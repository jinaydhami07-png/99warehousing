<?php
/**
 * The route table — every endpoint the API answers, in one file.
 *
 * ── ROUTE ORDER MATTERS ──────────────────────────────────────────────
 * Routes match in declaration order, so every literal path must appear
 * BEFORE the pattern that could swallow it:
 *
 *     /properties/mine          before  /properties/{id}
 *     /properties/{id}/approve  before  /properties/{id}
 *     /enquiries/mine           before  any /enquiries pattern
 *
 * Get this wrong and /properties/mine is matched as id="mine", which fails
 * the id check with a confusing 400 instead of listing the user's
 * submissions. The Express version had the same constraint and the same
 * ordering.
 * ─────────────────────────────────────────────────────────────────────
 *
 * Mirrors /api/v1 from the Node build exactly, path for path, so the
 * unchanged front-end talks to this server without knowing the difference.
 */
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\EnquiryController;
use App\Controllers\FavoriteController;
use App\Controllers\ImageController;
use App\Controllers\PropertyController;
use App\Controllers\ReviewController;
use App\Controllers\SystemController;
use App\Controllers\UserController;
use App\Http\Router;
use App\Middleware\Auth;
use App\Middleware\RateLimit;

/** @var Router $router */

/* Shorthands for the guards, so a route reads as a sentence. */
$authed = [[Auth::class, 'required']];
$optional = [[Auth::class, 'optional']];
$admin = [[Auth::class, 'required'], Auth::role('admin')];

/* ── Health and config ────────────────────────────────────────
   Both public and both cheap. Health is exempt from the global rate
   limiter (see RateLimit::global) because a monitor polls it. */
$router->get('/health', [SystemController::class, 'health']);
$router->get('/health/media', [SystemController::class, 'healthMedia']);
$router->get('/config', [SystemController::class, 'config']);

/* ── Auth ─────────────────────────────────────────────────────
   The credential endpoints carry the auth limiter, which counts failed
   attempts. The admin passkey is behind it too: it is a single shared
   secret and therefore the most brute-forceable entry point here. */
$router->post('/auth/register', [AuthController::class, 'register'], [[RateLimit::class, 'auth']]);
$router->post('/auth/login', [AuthController::class, 'login'], [[RateLimit::class, 'auth']]);
$router->post('/auth/admin', [AuthController::class, 'adminLogin'], [[RateLimit::class, 'auth']]);

/* Full-page redirects — OAuth cannot run inside fetch().

   Not behind the auth limiter: it counts failed sign-ins, and one OAuth
   attempt is two GETs, so a user who mistypes their Google password twice
   would be locked out of the button itself. The global limiter applies. */
$router->get('/auth/google', [AuthController::class, 'googleStart']);
$router->get('/auth/google/callback', [AuthController::class, 'googleCallback']);

$router->post('/auth/refresh', [AuthController::class, 'refresh']);
$router->post('/auth/logout', [AuthController::class, 'logout'], $authed);
$router->get('/auth/me', [AuthController::class, 'me'], $authed);

/* ── Users ────────────────────────────────────────────────────
   /users/me before the admin routes, and the admin ones each carry their
   own guard rather than relying on declaration order for safety. */
$router->get('/users/me', [UserController::class, 'getMe'], $authed);
$router->patch('/users/me', [UserController::class, 'updateMe'], $authed);

$router->get('/users', [UserController::class, 'listUsers'], $admin);
$router->post('/users', [UserController::class, 'createUser'], $admin);
$router->get('/users/{id}', [UserController::class, 'getUser'], $admin);
$router->patch('/users/{id}', [UserController::class, 'updateUser'], $admin);

/* ── Properties ───────────────────────────────────────────────
   Literal paths first — see the note at the top of this file. */
$router->get('/properties/admin/all', [PropertyController::class, 'listAll'], $admin);
$router->get('/properties/admin/stats', [PropertyController::class, 'stats'], $admin);
$router->get('/properties/mine', [PropertyController::class, 'listMine'], $authed);

/* The public feed. */
$router->get('/properties', [PropertyController::class, 'list']);
$router->post('/properties', [PropertyController::class, 'create'], array_merge($authed, [[RateLimit::class, 'write']]));

/* Moderation, before '/properties/{id}'. */
$router->patch('/properties/{id}/approve', [PropertyController::class, 'approve'], $admin);
$router->patch('/properties/{id}/reject', [PropertyController::class, 'reject'], $admin);

/* Owner contact details.

   Authenticated, not optional: contact details are the one thing a
   signed-out visitor must never obtain, so this rejects rather than
   degrading. The contact limiter then caps how many an account can pull in
   an hour, which is what stops a scraper that signed up. */
$router->get(
    '/properties/{id}/contact',
    [PropertyController::class, 'getContact'],
    array_merge($authed, [[RateLimit::class, 'contact']])
);

/* Reviews, nested under the listing they belong to.

   Reading is public — a rating nobody can see is worth nothing to a buyer.
   Writing needs a session, which is what ties a review to a real account
   and keeps the page from filling with anonymous claims. */
$router->get('/properties/{id}/reviews', [ReviewController::class, 'listForProperty']);
$router->get('/properties/{id}/reviews/mine', [ReviewController::class, 'mine'], $authed);
$router->post(
    '/properties/{id}/reviews',
    [ReviewController::class, 'create'],
    array_merge($authed, [[RateLimit::class, 'write']])
);

/* The single listing. optionalAuth so the service can decide whether an
   unapproved listing is visible to this particular viewer. */
$router->get('/properties/{id}', [PropertyController::class, 'getOne'], $optional);
$router->patch('/properties/{id}', [PropertyController::class, 'update'], $authed);
$router->delete('/properties/{id}', [PropertyController::class, 'remove'], $authed);

/* ── Favourites ───────────────────────────────────────────────
   Every route is per-account. Signed-out visitors keep theirs in
   localStorage and push them up on sign-in. */
$router->get('/favorites', [FavoriteController::class, 'list'], $authed);
$router->post('/favorites/{id}', [FavoriteController::class, 'add'], $authed);
$router->delete('/favorites/{id}', [FavoriteController::class, 'remove'], $authed);

/* ── Enquiries ────────────────────────────────────────────────
   POST is public on purpose: the contact form must work for signed-out
   visitors. optionalAuth links it to an account when there happens to be a
   session; the write limiter is what stops the open endpoint being used as
   a spam relay. */
$router->get('/enquiries/mine', [EnquiryController::class, 'listMine'], $authed);
$router->post(
    '/enquiries',
    [EnquiryController::class, 'create'],
    array_merge($optional, [[RateLimit::class, 'write']])
);

/* ── Images ───────────────────────────────────────────────────
   Split because the halves have opposite access rules: writing is
   authenticated and rate-limited, reading must stay public for a plain
   <img> tag to work. */
$router->post('/upload', [ImageController::class, 'upload'], array_merge($authed, [[RateLimit::class, 'write']]));
$router->get('/images/{id}', [ImageController::class, 'serve']);
$router->delete('/images/{id}', [ImageController::class, 'remove'], $authed);

/* ── Admin ────────────────────────────────────────────────────
   These delegate to the same controllers as the public routes rather than
   duplicating logic; the difference is the guard in front of them, not the
   behaviour behind them. */
$router->get('/admin/stats', [PropertyController::class, 'stats'], $admin);
$router->get('/admin/audit', [SystemController::class, 'audit'], $admin);

$router->get('/admin/properties', [PropertyController::class, 'listAll'], $admin);
/* An admin's listing is created live — they are the moderators, so there
   is nobody left to review their submission. The service applies that
   from the caller's role. */
$router->post('/admin/properties', [PropertyController::class, 'create'], $admin);
$router->patch('/admin/properties/{id}/approve', [PropertyController::class, 'approve'], $admin);
$router->patch('/admin/properties/{id}/reject', [PropertyController::class, 'reject'], $admin);
$router->patch('/admin/properties/{id}', [PropertyController::class, 'update'], $admin);
$router->delete('/admin/properties/{id}', [PropertyController::class, 'remove'], $admin);

$router->get('/admin/enquiries', [EnquiryController::class, 'listAll'], $admin);
$router->patch('/admin/enquiries/{id}', [EnquiryController::class, 'update'], $admin);

/* Reviews are written by strangers and shown on a public page, so they go
   through the same pending → published path a listing does. */
$router->get('/admin/reviews', [ReviewController::class, 'listPending'], $admin);
$router->patch('/admin/reviews/{id}', [ReviewController::class, 'moderate'], $admin);
$router->delete('/admin/reviews/{id}', [ReviewController::class, 'remove'], $admin);

$router->get('/admin/users', [UserController::class, 'listUsers'], $admin);
$router->patch('/admin/users/{id}', [UserController::class, 'updateUser'], $admin);
