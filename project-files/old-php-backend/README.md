# 99Warehousing — PHP backend

The same website, the same pages, the same API — with the Express + MongoDB
server replaced by PHP + MySQL.

`godaddy-upload/` (the Node build) is untouched and still works. This folder
is a parallel implementation, not a replacement, and the two can be run side
by side against the same front-end to compare them.

---

## What changed, and what did not

| | Node build | This folder |
|---|---|---|
| Front-end | `public/` | **byte-identical copy** |
| API surface | `/api/v1/*` | **identical, path for path** |
| Server | Express 4 | PHP 7.4+ (no framework) |
| Database | MongoDB / Mongoose | MySQL / MariaDB via PDO |
| Validation | zod | `app/Support/Rule.php` |
| Auth | jsonwebtoken | `app/Support/Jwt.php` (HS256, hand-rolled) |
| Passwords | bcryptjs | `password_hash` — **same hashes, interchangeable** |
| Images | sharp → S3 or MongoDB | GD → WebP files on disk |
| Rate limiting | express-rate-limit (in memory) | a `rate_limits` table |
| Dependencies | 17 npm packages | **none** |

The HTML, CSS and browser JavaScript are a straight copy. `assets/api.js` was
not edited: it already talks to `/api/v1`, and this server answers there with
the same response envelope, so it cannot tell the difference.

### Why no Composer

Shared cPanel hosting frequently has no SSH access, so `composer install` is
not something that can be relied on at deploy time. Everything here is either
in PHP's standard library or written out in `app/Support/`. Upload the folder
and it runs.

### Why MySQL rather than MongoDB

The PHP MongoDB driver is a PECL extension that most shared hosts do not
offer and cannot be installed without root. MySQL is on every cPanel account
by default.

Row ids are still 24-character hex strings in MongoDB's own format
(`app/Support/ObjectId.php`), for two reasons: the front-end is full of them
and was not to be edited, and an existing Mongo export imports into these
tables with its ids intact.

---

## Requirements

- PHP **7.4 or newer** with `pdo_mysql`, `gd`, `curl`, `mbstring`, `json`
- MySQL 5.7+ or MariaDB 10.3+
- Apache with `mod_rewrite` (or nginx — see the deployment guide)

**Verified on PHP 8.1.34 and 8.5.10** against MariaDB 12.3 — the full suite
passes on both. 7.4 and 8.0 are supported by construction, not by test: the
code uses no syntax newer than 7.4, and `str_contains` / `str_starts_with` /
`str_ends_with` are polyfilled in `app/Support/helpers.php`. If you are
deploying to 7.4, run `bash bin/api-test.sh` against it first.

Check a host before deploying:

```bash
php -r "printf('php %s | pdo_mysql %d | gd %d | webp %d | curl %d%s', PHP_VERSION, extension_loaded('pdo_mysql'), extension_loaded('gd'), function_exists('imagewebp'), extension_loaded('curl'), PHP_EOL);"
```

---

## Local setup

```bash
cp config/.env.example config/.env
```

Fill in `DB_*`, then generate the two JWT secrets — they must differ:

```bash
php -r "echo bin2hex(random_bytes(48)), PHP_EOL;"
```

Create the tables and, optionally, the sample catalogue:

```bash
php bin/install.php
php bin/seed.php
```

Run it. PHP's built-in server has no `mod_rewrite`, so `dev-router.php`
stands in for `public/.htaccess` — development only:

```bash
php -S 127.0.0.1:8080 -t public dev-router.php
```

Then open <http://127.0.0.1:8080>.

---

## Tests

`bin/api-test.sh` exercises **101 assertions over HTTP** against a running
server — auth, ownership, moderation, uploads, rate limiting, and the
security boundaries (a PHP shell renamed `.jpg`, a submitter trying to set
their own `status`, one user editing another's listing).

```bash
bash bin/api-test.sh
```

It **resets the database first**, because it asserts exact counts. Point it
at a development database, never a live one.

---

## Layout

```
config/.env            secrets — never committed, never web-reachable
app/
  bootstrap.php        autoloader + error handling
  routes.php           every endpoint, in one file
  Config/              Env, Database, Logger
  Http/                Request, Response, Router, ApiError
  Middleware/          Auth, Security (CORS/CSP), RateLimit
  Support/             Jwt, ObjectId, Rule/ObjectRule/ArrayRule, Pagination
  Models/              table queries + row → JSON mapping
  Services/            business logic — the layer that decides things
  Controllers/         validate → call a service → shape a response
  Validators/          request schemas
database/
  schema.sql           the tables, with the reasoning
  seed-data.php        the sample catalogue
bin/
  install.php          create tables (safe to re-run)
  seed.php             sample data (--wipe to start over)
  api-test.sh          the test suite
public/                ← upload the CONTENTS of this to public_html
  api.php              the API front controller
  *.html, assets/      the unchanged front-end
  uploads/             generated images (writable)
```

Controllers stay thin. Anything that *decides* something — who may edit a
listing, whether a submission starts pending, whether an unapproved listing
is visible to this viewer — lives in a service, so the public route and the
admin route cannot drift apart.

---

## Building the deploy package

```bash
php bin/build-package.php
```

Writes an upload-ready build to the repository's `dist/`:

```
dist/99warehousing-php/
├── public_html/            → upload the CONTENTS into public_html
└── 99warehousing-app/      → upload one level ABOVE public_html
dist/99warehousing-php-cpanel.zip
```

The split is the point. In the source tree `public/` sits inside the
project because that is convenient to develop in; on the server it has to
be the other way round, with the application — `config/.env` above all —
outside the web root. Doing that split by hand at deploy time is a step
that gets forgotten, and forgetting it publishes the database password.

The package ships `.env.example` with `PUBLIC_DIR=../public_html` already
set, and never your own `config/.env` or anything under `uploads/`.

`api.php` finds the application in either layout, so the same file works
in development and on the server.

## Deployment

See **[DEPLOY-TO-GODADDY.md](DEPLOY-TO-GODADDY.md)**.

The short version: `public/` becomes `public_html`, and everything else goes
one level above it. If the host will not allow that, upload the whole folder
into `public_html` — the `.htaccess` files in `app/`, `config/`, `database/`,
`bin/` and `storage/` deny web access to each, and the one at the root denies
everything but `public/`.

---

## What was verified

Beyond the 101 automated assertions, the following was exercised by hand
through the real pages in a browser, against this backend:

- registering an account through `register.html` → the row appears in MySQL
  with the right role, mobile and company
- signing in with the staff passkey → the admin console loads real counts
  (16 total / 4 pending / 10 live / 2 rejected)
- submitting a listing through `submit-listing.html`, photo and all → stored
  with `status=pending`, `owner_name` taken from the account rather than the
  form, `specs` and `distances` as JSON, and the image row tagged to the
  listing
- approving it from the dashboard → it appears in the public feed
- the uploaded photo served as WebP at three widths, each decoding to
  exactly the expected dimensions, with a real `srcset` on the card
- owner contact details behind a session, and written to the audit log
- no console errors on any page visited

The **deployed layout was tested as a layout**, not just assumed: the built
package was unpacked into a simulated server home with `public_html` beside
the application, configured from the shipped `.env.example`, and the full
101-assertion suite run against it — all passing. Uploads landed in
`public_html/uploads` where Apache serves them, and nothing was written
inside the application folder.

`/api/v1` is **route-for-route identical** to the Node build: all 54
endpoints, verified by diffing the two route tables.

## Known gaps

- **Google sign-in is implemented but untested against live Google.** The
  flow is complete (state cookie, code exchange, account linking); it has
  only been exercised with the feature switched off, which correctly
  redirects to `/login.html?error=google_not_configured`.
- **No email is sent.** The Node build did not send any either — the
  "confirmation has been sent to your email" text on the submit page is
  copy, not behaviour, in both versions.
- **`forgot-password.html` has no backend** in either version.
- **AVIF uploads depend on the host's GD build.** JPEG, PNG, GIF and WebP
  always work; an AVIF upload is refused with a clear message where GD
  lacks `imagecreatefromavif`.
