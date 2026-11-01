<?php
/**
 * Account creation, credential checking, and the admin user list.
 */
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Config\Logger;
use App\Http\ApiError;
use App\Models\User;

final class UserService
{
    /** @param array<string,mixed> $payload */
    public static function createUser(array $payload): array
    {
        $email = mb_strtolower(trim((string) $payload['email']));

        if (User::findByEmail($email) !== null) {
            throw ApiError::conflict('An account with that email already exists');
        }

        /* The role comes from `accountType` on the sign-up form and is
           filtered against a whitelist that has no 'admin' in it. A payload
           asking for admin lands on 'buyer' rather than being honoured. */
        $accountType = $payload['accountType'] ?? 'buyer';
        $role = in_array($accountType, ['buyer', 'owner', 'agency'], true) ? $accountType : 'buyer';

        try {
            $user = User::create([
                'name' => $payload['name'],
                'email' => $email,
                'password' => $payload['password'] ?? null,
                'mobile' => $payload['mobile'] ?? null,
                'company' => $payload['company'] ?? null,
                'role' => $role,
            ]);
        } catch (\PDOException $e) {
            /* Two simultaneous sign-ups with the same address: the check
               above passed for both and the unique index caught the loser.
               Report it as the conflict it is, not a 500. */
            if ($e->getCode() === '23000') {
                throw ApiError::conflict('An account with that email already exists');
            }
            throw $e;
        }

        Logger::info('User created', ['userId' => $user['id'], 'role' => $role]);
        return $user;
    }

    /**
     * Sign-in check, with lockout.
     *
     * "Incorrect email or password" is used for both a missing account and a
     * wrong password on purpose: distinguishing them turns this endpoint
     * into an oracle for which email addresses are registered.
     */
    public static function verifyCredentials(string $email, string $password): array
    {
        $user = User::findByEmail($email);
        if ($user === null) {
            throw ApiError::unauthorized('Incorrect email or password');
        }

        if (User::isLocked($user)) {
            $minutes = User::lockMinutesRemaining($user);
            throw ApiError::tooMany("Account temporarily locked. Try again in $minutes minute(s).");
        }

        if (!User::verifyPassword($user, $password)) {
            User::registerFailedAttempt($user);
            throw ApiError::unauthorized('Incorrect email or password');
        }

        if (!$user['is_active']) {
            throw ApiError::forbidden('This account has been deactivated');
        }

        User::registerSuccessfulLogin($user['id']);
        return User::findById($user['id']) ?? $user;
    }

    public static function getUserById(string $id): array
    {
        $user = User::findById($id);
        if ($user === null) {
            throw ApiError::notFound('User not found');
        }
        return $user;
    }

    /**
     * The admin user list.
     *
     * @param array<string,mixed> $options
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public static function listUsers(array $options): array
    {
        $where = [];
        $params = [];

        if (!empty($options['role'])) {
            $where[] = 'role = :role';
            $params['role'] = $options['role'];
        }
        if (!empty($options['search'])) {
            $where[] = Database::likeClause(['name', 'email'], 'search', (string) $options['search'], $params);
        }

        $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) Database::scalar("SELECT COUNT(*) FROM users$sql", $params);

        /* LIMIT and OFFSET are cast to int rather than bound. MySQL will not
           accept a placeholder there when prepares are not emulated, and an
           int cast is not injectable. */
        $limit = (int) $options['limit'];
        $offset = (int) $options['skip'];

        $rows = Database::all(
            "SELECT * FROM users$sql ORDER BY created_at DESC LIMIT $limit OFFSET $offset",
            $params
        );

        return [
            'items' => array_map([User::class, 'toJson'], $rows),
            'total' => $total,
        ];
    }

    /**
     * Update a profile.
     *
     * The caller decides which keys are allowed — updateMe passes three,
     * the admin route passes five. This maps and writes; it does not
     * authorise.
     *
     * @param array<string,mixed> $patch
     */
    public static function updateUser(string $id, array $patch): array
    {
        if (User::findById($id) === null) {
            throw ApiError::notFound('User not found');
        }

        $map = [
            'name' => 'name',
            'mobile' => 'mobile',
            'company' => 'company',
            'role' => 'role',
            'isActive' => 'is_active',
        ];

        $updates = [];
        foreach ($map as $field => $column) {
            if (array_key_exists($field, $patch)) {
                $updates[$column] = $field === 'isActive' ? (int) (bool) $patch[$field] : $patch[$field];
            }
        }

        $updated = User::update($id, $updates);
        if ($updated === null) {
            throw ApiError::notFound('User not found');
        }
        return $updated;
    }
}
