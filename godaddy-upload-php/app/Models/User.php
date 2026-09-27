<?php
/**
 * User model.
 *
 * A model here is a table's queries plus its row → API shape mapping. There
 * is no ORM: the Mongoose models this replaces did schema validation,
 * hashing hooks and a toJSON transform, and each of those has a home —
 * validation in app/Validators, hashing in this file's create/verify pair,
 * and serialisation in toJson() below.
 */
declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use App\Config\Env;
use App\Support\ObjectId;

final class User
{
    public const ROLES = ['buyer', 'owner', 'agency', 'admin'];

    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_SECONDS = 900; // 15 minutes

    public static function findById(?string $id): ?array
    {
        if ($id === null || !ObjectId::isValid($id)) {
            return null;
        }
        return Database::first('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::first(
            'SELECT * FROM users WHERE email = :email',
            ['email' => mb_strtolower(trim($email))]
        );
    }

    public static function findByGoogleId(string $googleId): ?array
    {
        return Database::first('SELECT * FROM users WHERE google_id = :gid', ['gid' => $googleId]);
    }

    public static function firstAdmin(): ?array
    {
        return Database::first("SELECT * FROM users WHERE role = 'admin' ORDER BY created_at ASC LIMIT 1");
    }

    /**
     * Insert a user, hashing the password on the way in.
     *
     * Hashing happens here and nowhere else, so no code path can store a
     * plaintext password by forgetting a step — the same guarantee the
     * Mongoose pre('save') hook gave.
     *
     * @param array<string,mixed> $data
     */
    public static function create(array $data): array
    {
        $id = ObjectId::generate();
        $now = now_utc();

        $password = $data['password'] ?? null;

        Database::insert('users', [
            'id' => $id,
            'name' => $data['name'],
            'email' => mb_strtolower(trim((string) $data['email'])),
            'password' => $password === null ? null : self::hash((string) $password),
            'mobile' => $data['mobile'] ?? null,
            'company' => $data['company'] ?? null,
            'avatar' => $data['avatar'] ?? null,
            'role' => in_array($data['role'] ?? 'buyer', self::ROLES, true) ? $data['role'] : 'buyer',
            'auth_provider' => $data['authProvider'] ?? 'local',
            'google_id' => $data['googleId'] ?? null,
            'is_email_verified' => (int) (bool) ($data['isEmailVerified'] ?? false),
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $created = self::findById($id);
        if ($created === null) {
            // Only reachable if the row vanished between INSERT and SELECT.
            throw \App\Http\ApiError::internal('User could not be created');
        }
        return $created;
    }

    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => (int) Env::get('bcryptCost', 12)]);
    }

    /**
     * Password check.
     *
     * password_verify handles $2a$, $2b$ and $2y$ alike, so a hash written
     * by bcryptjs in the Node app verifies here without asking the user to
     * reset anything.
     */
    public static function verifyPassword(array $user, string $candidate): bool
    {
        $hash = $user['password'] ?? null;
        if (!is_string($hash) || $hash === '') {
            /* An OAuth-only account. Still burn the time a real comparison
               would take, so "this address has no password" is not
               detectable by how fast the answer comes back. */
            password_verify($candidate, '$2y$12$' . str_repeat('.', 53));
            return false;
        }
        return password_verify($candidate, $hash);
    }

    public static function isLocked(array $user): bool
    {
        $until = $user['locked_until'] ?? null;
        return $until !== null && strtotime($until . ' UTC') > time();
    }

    public static function lockMinutesRemaining(array $user): int
    {
        $until = strtotime(((string) $user['locked_until']) . ' UTC');
        return max(1, (int) ceil(($until - time()) / 60));
    }

    /**
     * Count a failed sign-in and lock the account once the run is long
     * enough. The counter resets on the next successful sign-in.
     */
    public static function registerFailedAttempt(array $user): void
    {
        $attempts = (int) $user['failed_login_attempts'] + 1;

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            Database::update('users', $user['id'], [
                'failed_login_attempts' => 0,
                'locked_until' => utc_offset(self::LOCK_SECONDS),
                'updated_at' => now_utc(),
            ]);
            \App\Config\Logger::warn('Account locked after repeated failed logins', ['userId' => $user['id']]);
            return;
        }

        Database::update('users', $user['id'], [
            'failed_login_attempts' => $attempts,
            'updated_at' => now_utc(),
        ]);
    }

    public static function registerSuccessfulLogin(string $id): void
    {
        Database::update('users', $id, [
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now_utc(),
            'updated_at' => now_utc(),
        ]);
    }

    /** True if the password changed after this token was issued. */
    public static function passwordChangedAfter(array $user, int $tokenIssuedAt): bool
    {
        $changed = $user['password_changed_at'] ?? null;
        if ($changed === null) {
            return false;
        }
        return strtotime($changed . ' UTC') > $tokenIssuedAt;
    }

    /** "Log out everywhere": invalidates every refresh token in one write. */
    public static function revokeSessions(string $id): void
    {
        Database::run(
            'UPDATE users SET token_version = token_version + 1, updated_at = :now WHERE id = :id',
            ['now' => now_utc(), 'id' => $id]
        );
    }

    /** @param array<string,mixed> $updates Already-mapped column names. */
    public static function update(string $id, array $updates): ?array
    {
        if ($updates) {
            $updates['updated_at'] = now_utc();
            Database::update('users', $id, $updates);
        }
        return self::findById($id);
    }

    /**
     * Row → the JSON the API returns.
     *
     * The single place a user is serialised, so the password hash, the
     * lockout counters and the token version cannot leak by someone
     * returning a raw row from a new endpoint.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function toJson(array $row): array
    {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'mobile' => $row['mobile'],
            'company' => $row['company'],
            'avatar' => $row['avatar'],
            'role' => $row['role'],
            'authProvider' => $row['auth_provider'],
            'isEmailVerified' => (bool) $row['is_email_verified'],
            'isActive' => (bool) $row['is_active'],
            'lastLoginAt' => iso8601($row['last_login_at'] ?? null),
            'createdAt' => iso8601($row['created_at'] ?? null),
            'updatedAt' => iso8601($row['updated_at'] ?? null),
        ];
    }
}
