<?php
/**
 * Request schemas for accounts and sessions.
 */
declare(strict_types=1);

namespace App\Validators;

use App\Models\User;
use App\Support\ObjectRule;
use App\Support\Rule;
use App\Support\Validate;

final class UserSchema
{
    private const PHONE = '/^[+]?[\d\s()-]{7,20}$/';

    public static function email(): Rule
    {
        return Rule::string()->trim()->lower()->email()->max(254);
    }

    /**
     * ── On password rules ─────────────────────────────────────────────
     * Length only, 8 to 128. Forced symbol-and-digit mixes push people
     * toward predictable substitutions (Password1!) that add far less
     * entropy than one more word does, and the upper bound is there because
     * bcrypt silently ignores everything past 72 bytes — a user with a
     * 200-character passphrase would find that only its first 72 characters
     * ever mattered.
     * ─────────────────────────────────────────────────────────────────
     */
    public static function password(): Rule
    {
        return Rule::string()
            ->min(8, 'Password must be at least 8 characters')
            ->max(128, 'Password cannot exceed 128 characters');
    }

    public static function register(): ObjectRule
    {
        return Validate::object([
            'name' => Rule::string()->trim()->min(2, 'Name must be at least 2 characters')->max(120),
            'email' => self::email(),
            'password' => self::password(),
            'mobile' => Rule::string()->trim()->pattern(self::PHONE, 'Invalid phone number')->optional(),
            'company' => Rule::string()->trim()->max(160)->optional(),
            /* No 'role' key. The sign-up form sends accountType, which the
               service filters against a list that has no 'admin' in it —
               and a body asking for role is rejected outright here. */
            'accountType' => Rule::enum(['buyer', 'owner', 'agency'])->optional(),
        ])->requires('email', 'Email is required')
          ->requires('password', 'Password is required');
    }

    public static function login(): ObjectRule
    {
        return Validate::object([
            'email' => self::email(),
            /* Deliberately not the full password rule. Checking length here
               would reject an old short password before it is compared, and
               tell an attacker the format instead of "incorrect". */
            'password' => Rule::string()->min(1, 'Password is required'),
        ]);
    }

    public static function adminLogin(): ObjectRule
    {
        return Validate::object([
            'passkey' => Rule::string()->min(1, 'Passkey is required')->max(200),
        ])->requires('passkey', 'Passkey is required');
    }

    /** What a user may change about their own profile. */
    public static function updateMe(): ObjectRule
    {
        return Validate::object([
            'name' => Rule::string()->trim()->min(2)->max(120)->optional(),
            'mobile' => Rule::string()->trim()->pattern(self::PHONE, 'Invalid phone number')->optional(),
            'company' => Rule::string()->trim()->max(160)->optional(),
            /* No role, no isActive, no email, no avatar. A user cannot
               promote themselves, and avatar is only ever set from Google. */
        ])->nonEmpty();
    }

    /** What an admin may change about anyone. */
    public static function adminUpdateUser(): ObjectRule
    {
        return Validate::object([
            'name' => Rule::string()->trim()->min(2)->max(120)->optional(),
            'mobile' => Rule::string()->trim()->max(20)->optional(),
            'company' => Rule::string()->trim()->max(160)->optional(),
            'role' => Rule::enum(User::ROLES)->optional(),
            'isActive' => Rule::boolean()->optional(),
        ])->nonEmpty();
    }

    public static function listQuery(): ObjectRule
    {
        return Validate::object([
            'page' => Rule::number()->int()->positive()->optional(),
            'limit' => Rule::number()->int()->positive()->max(100)->optional(),
            'role' => Rule::enum(User::ROLES)->optional(),
            'search' => Rule::string()->trim()->max(120)->optional(),
        ])->loose();
    }
}
