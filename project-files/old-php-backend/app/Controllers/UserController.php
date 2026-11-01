<?php
/**
 * Profile and admin user-management endpoints.
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiError;
use App\Http\Request;
use App\Http\Response;
use App\Models\User;
use App\Services\UserService;
use App\Support\ObjectId;
use App\Support\Pagination;
use App\Support\Validate;
use App\Validators\UserSchema;

final class UserController
{
    public static function getMe(Request $request): void
    {
        Response::success(['user' => User::toJson($request->user)], 'Profile retrieved');
    }

    public static function updateMe(Request $request): void
    {
        $body = Validate::against(UserSchema::updateMe(), $request->body());
        $user = UserService::updateUser((string) $request->user['id'], $body);

        Response::success(['user' => User::toJson($user)], 'Profile updated');
    }

    public static function listUsers(Request $request): void
    {
        $query = Validate::against(UserSchema::listQuery(), $request->query());
        $page = Pagination::from($query);

        $result = UserService::listUsers(array_merge($query, $page));

        Response::paginated($result['items'], $page['page'], $page['limit'], $result['total'], 'Users retrieved');
    }

    public static function getUser(Request $request): void
    {
        $user = UserService::getUserById(self::id($request));
        Response::success(['user' => User::toJson($user)], 'User retrieved');
    }

    public static function createUser(Request $request): void
    {
        $body = Validate::against(UserSchema::register(), $request->body());
        $user = UserService::createUser($body);

        Response::created(['user' => User::toJson($user)]);
    }

    public static function updateUser(Request $request): void
    {
        $body = Validate::against(UserSchema::adminUpdateUser(), $request->body());
        $user = UserService::updateUser(self::id($request), $body);

        Response::success(['user' => User::toJson($user)], 'User updated');
    }

    private static function id(Request $request): string
    {
        $id = (string) $request->param('id');
        if (!ObjectId::isValid($id)) {
            throw ApiError::badRequest('Invalid user id');
        }
        return $id;
    }
}
