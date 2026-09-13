# Deploying the PHP build to GoDaddy cPanel

Roughly 30 minutes, no SSH required. Everything here can be done through
cPanel's web interface.

Unlike the Node version, there is no "Setup Node.js App" step, no
`npm install`, and no Passenger process to restart — PHP runs when Apache
gets a request and stops when it answers.

---

## Before you start

In cPanel → **Select PHP Version**, confirm **PHP 7.4 or newer** and that
these extensions are ticked:

- `pdo_mysql` — the database
- `gd` — image resizing
- `curl` — Google sign-in only; skip if you are not using it
- `mbstring`, `json` — normally on by default

Without `gd`, uploads fail with a clear message and everything else works.
Without `pdo_mysql`, nothing works.

---

## 1. Create the database

cPanel → **MySQL Databases**:

1. Create a database, e.g. `99wh`. cPanel prefixes it with your account
   name — the real name will be something like `myacct_99wh`.
2. Create a user, e.g. `99whuser` → `myacct_99whuser`. Use the password
   generator and **copy the password now**.
3. Under *Add User To Database*, add the user to the database and grant
   **ALL PRIVILEGES**.

Write down all three values with their prefixes. They go in `config/.env`.

---

## 2. Get the package

A ready-to-upload build is at **`dist/99warehousing-php-cpanel.zip`** (and
unzipped at `dist/99warehousing-php/`). It is already split into the two
folders the server needs, so there is no rearranging to do by hand.

Rebuild it after any change with:

```bash
php bin/build-package.php
```

It contains `config/.env.example` and never your own `config/.env`, and no
images you uploaded locally.

If you would rather arrange it yourself, skip to the layouts below and work
from the source tree instead.

---

## 3. Decide where the files go

**Preferred — application above the web root:**

```
/home/myacct/
├── 99warehousing/        ← app, config, database, bin, storage
└── public_html/          ← the CONTENTS of public/
```

Nothing outside `public_html` is reachable over HTTP at all, so
`config/.env` cannot be served even if Apache is misconfigured.

**Also fine — everything inside public_html:**

```
/home/myacct/public_html/
├── .htaccess             ← denies everything
├── app/ config/ …        ← each denies itself as well
└── public/               ← re-enables itself
```

This works because of the `.htaccess` files already in the folder, but it
depends on Apache honouring them. The first layout does not depend on
anything.

> **If you use the second layout**, the site lives at `/public/`. To serve
> it at the domain root, set the domain's document root to
> `public_html/public` in cPanel → **Domains** → *Manage*.

The instructions below assume the **preferred** layout.

---

## 4. Upload

cPanel → **File Manager**, or FTP.

1. Upload `99warehousing-php-cpanel.zip` to your home directory and use
   *Extract*. Uploading ~100 files one at a time through File Manager is
   slow and it is easy to miss one.
2. Move the **contents** of `99warehousing-php/public_html/` into your real
   `public_html` — the HTML files, `assets/`, `uploads/`, `api.php` and the
   `.htaccess`. The contents, not the folder: ending up with
   `public_html/public_html` gives you a site that 404s.
3. Move `99warehousing-php/99warehousing-app/` to your home directory, so it
   sits *beside* `public_html` rather than inside it.
4. Delete the now-empty `99warehousing-php/` and the zip.

**Check that `.htaccess` came across.** File Manager hides dotfiles by
default: *Settings* → tick **Show Hidden Files (dotfiles)**. Without
`public_html/.htaccess` nothing under `/api/v1/` will route, and every page
will look like the API is down.

---

## 5. Configure

Copy `99warehousing-app/config/.env.example` to `config/.env`
(File Manager → *Copy*), then edit it.

`PUBLIC_DIR` is already set to `../public_html` in the packaged example and
is correct for the layout above — it is how the app knows where to write
uploaded photos. Change it only if you put the two folders somewhere else.
Get it wrong and uploads succeed but every photo 404s, because the files
land somewhere Apache never serves.

The rest:

```ini
APP_ENV=production
APP_URL=https://99warehousing.com

DB_HOST=localhost
DB_NAME=myacct_99wh
DB_USER=myacct_99whuser
DB_PASSWORD=the-password-you-copied

CORS_ORIGINS=https://99warehousing.com

JWT_ACCESS_SECRET=<96 hex characters>
JWT_REFRESH_SECRET=<96 DIFFERENT hex characters>

ADMIN_PASSKEY=<a long random staff passkey>

TRUST_PROXY=0
```

Generate each secret separately — they must not match:

```bash
php -r "echo bin2hex(random_bytes(48)), PHP_EOL;"
```

No shell? Any password generator set to 96 characters will do, or paste the
one-liner into `public_html/gen.php` inside `<?php ?>`, load it once, copy
the output, **and delete the file**.

### Settings people get wrong

| Setting | Wrong | Why it matters |
|---|---|---|
| `APP_ENV` | left as `development` | leaks file paths and stack traces to visitors |
| `JWT_*` | both the same | a stolen access token can be replayed as a refresh token |
| `CORS_ORIGINS` | `*` | any site on the internet could act as your signed-in users |
| `TRUST_PROXY` | `1` with no proxy | anyone can spoof their IP and bypass rate limiting |

`APP_ENV=production` **refuses to start** on most of these rather than
running insecurely. If the site returns a 500 straight after deploying, read
`storage/logs/app.log` — the message names the setting.

Behind Cloudflare, set `TRUST_PROXY=1`. Direct to GoDaddy, leave it `0`.

---

## 6. Create the tables

With SSH:

```bash
cd ~/99warehousing && php bin/install.php
```

Without SSH — cPanel → **Cron Jobs**, "Once per five minutes", command:

```
/usr/local/bin/php /home/myacct/99warehousing/bin/install.php
```

Wait for it to run, check your email for the output, then **delete the cron
job**. Leaving a scheduled schema script is not something to do twice.

Confirm in **phpMyAdmin**: the database should hold eight tables — `users`,
`properties`, `images`, `favorites`, `reviews`, `enquiries`, `audit_logs`,
`rate_limits`.

Sample listings are optional and use the same method with `bin/seed.php`.
Skip it for a real launch: it inserts fourteen invented warehouses with
invented prices.

---

## 7. Permissions

`public_html/uploads` must be writable — `755` is normally right on cPanel,
where PHP runs as your own account. Use `775` only if uploads fail with a
permission error.

`storage/logs` needs the same. If it cannot be written, logging falls back
to the account's PHP error log rather than failing the request.

---

## 8. Check it

Load `https://99warehousing.com/api/v1/health`. You want:

```json
{"success":true,"message":"OK","data":{"database":"connected", ...}}
```

Then:

- the home page loads
- **Browse Warehouses** shows listings (or "0 warehouses found" on an empty
  database — that is the API answering, not failing)
- registering an account works, and the account appears in phpMyAdmin
- *Staff access* on the login page accepts your `ADMIN_PASSKEY`
- submitting a listing with a photo works, and the photo appears

The first `/auth/admin` sign-in creates the admin account automatically.

### If something is wrong

| Symptom | Cause |
|---|---|
| Pages load, every API call 404s | `public_html/.htaccess` missing, or `mod_rewrite` off |
| `{"success":false,...database unavailable}` | `DB_*` wrong, or the user has no privileges on the database |
| Signed in, then immediately signed out | Apache is stripping `Authorization`; the rewrite in `.htaccess` restores it — confirm the file is intact |
| Blank white page | PHP fatal error. Read `storage/logs/app.log` and the cPanel error log |
| Uploads work but photos 404 | `PUBLIC_DIR` points somewhere Apache does not serve. Check `/api/v1/health/media` |
| Uploads fail over ~2 MB | `upload_max_filesize` / `post_max_size` in *Select PHP Version* → *Options*. Set both ≥ `MEDIA_MAX_MB` |
| 500 immediately after deploying | A production configuration check refused to start. The log names the setting |

---

## 9. After it works

- Delete anything you used to generate secrets.
- Remove the install cron job if you have not already.
- cPanel → **SSL/TLS Status** → run *AutoSSL*, then force HTTPS. The refresh
  cookie is marked `secure` in production, so **sign-in will not work over
  plain http**.
- Once HTTPS is confirmed, uncomment the `Strict-Transport-Security` line in
  `public_html/.htaccess`. Do it in that order — sending it before HTTPS
  works locks visitors out for a year.
- Set up backups: cPanel → *Backup*, or a scheduled `mysqldump`. The
  database holds the listings; `public_html/uploads` holds the photos. You
  need both.

---

## Running both backends at once

They are independent. The Node app listens on its own port under Passenger;
this one is served by Apache. Point them at different databases and they
will not interact.

To compare them, host the PHP build on a subdomain
(`php.99warehousing.com` → its own document root) and leave the Node app on
the main domain. Both serve the same pages, so any difference you see is a
difference in the backend.
