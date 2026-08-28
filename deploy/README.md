# 99 Warehousing

Industrial real-estate marketplace for India — warehouses, industrial sheds, land parcels and
logistics parks, priced per square foot.

**This folder is the deployable app.** Push it to GitHub, or upload it to a host, as-is.
Everything else (design notes, the abandoned Next.js prototype, machine-specific scripts) lives
in `../project-files/` and is deliberately not here.

---

## Layout

```
deploy/
  index.html        redirect to public/ — the entry point for static hosting
  .nojekyll         stops GitHub Pages running Jekyll over the site
  public/           the site: 16 pages, CSS, JS, images
  server/           Express + Mongoose API
```

`server/` is kept out of `public/` so the Express static handler has exactly one directory to
expose. Nothing under `server/` — including `.env` — is reachable over HTTP.

---

## Running it

Requires Node 18.18+ and a MongoDB connection string.

```bash
cd server
npm install
npm start
```

Then open **http://localhost:5000**. Express serves the API *and* the pages from one origin, so
there is no CORS to configure and the refresh-token cookie works normally.

For continuous running, the app is supervised by PM2:

```bash
npm run pm2:start     # start under supervision
npm run pm2:status    # is it alive?
npm run pm2:logs      # tail logs
npm run pm2:restart   # after a code change — file watching is off on purpose
```

Seed 14 demo listings:

```bash
npm run seed:fresh
```

---

## Configuration

Everything lives in **`server/.env`** (never committed — see `.gitignore`). Copy
`server/.env.example` to get started.

| Key | Purpose |
|---|---|
| `MONGODB_URI` | MongoDB Atlas connection string |
| `JWT_ACCESS_SECRET` / `JWT_REFRESH_SECRET` | Session signing keys |
| `ADMIN_PASSKEY` | Staff login passkey |
| `CORS_ORIGINS` | Allowed origins |
| `PORT` | Defaults to 5000 |

### Google sign-in

Credentials live in **`server/.env`** (lines 39–41):

```
GOOGLE_CLIENT_ID=…apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=…
GOOGLE_CALLBACK_URL=https://99warehousing.com/api/v1/auth/google/callback
```

That callback must **also** be listed under *Authorised redirect URIs* on the same OAuth client in
Google Cloud Console, matching exactly — scheme, host and path. Google compares the strings
literally, so a trailing slash or `http` instead of `https` is enough to fail with
`redirect_uri_mismatch`. If the site is reachable at both `99warehousing.com` and
`www.99warehousing.com`, register the one you actually redirect to; visitors arriving at the other
should be 301'd to it before they reach the sign-in button.

**Testing Google sign-in locally.** The callback is a fixed value, so with the live domain set,
consent on localhost sends the user to `https://99warehousing.com/...` — the production server, not
the machine you are testing on. To exercise it locally, register the localhost URI on the same
OAuth client as a second entry and temporarily point `GOOGLE_CALLBACK_URL` at it:

```
GOOGLE_CALLBACK_URL=http://localhost:5000/api/v1/auth/google/callback
```

Remember to change it back. Production refuses to start with a localhost or non-https callback, so
a forgotten switch fails loudly at deploy rather than silently sending users to the wrong host.

The URL is deliberately **not** derived from the request's `Host` header. That would make it "just
work" everywhere, but a forged Host would then redirect the authorization code to an attacker's
server — the value has to be pinned in config.

Leaving the two credentials blank simply disables the button — the server still starts, and the
login page explains that Google sign-in is not configured rather than dead-ending.

Signing in with Google against an email that already has a password account **links** the two
rather than creating a duplicate, so an owner who registered with a password can later use the
Google button and reach the same listings.

### Admin access

There is no admin link in the public navigation, by design. Go to `login.html`, expand
**"Staff access"** at the bottom, and enter the passkey from `ADMIN_PASSKEY`.

---

## Hosting on GitHub Pages

GitHub Pages serves static files only — **there is no Node process**, so the API cannot run
there. A bundled demo dataset can stand in for it, but it is **off by default**: with no
backend reachable, each page shows an honest "could not load" state with a retry instead.

That default is deliberate. The demo dataset is fourteen invented warehouses with invented
prices and invented owners, and serving those to a real visitor as though they were inventory
is worse than serving nothing — it also made the live site look like it had lost its listings.

To demonstrate the site without a backend, opt in per page load:

```html
<script>window.BPSF_DEMO = true;</script>   <!-- before assets/api.js -->
```

or append `?demo=1` to any URL. Writes are never faked even then: with no server reachable, a
save raises "this was NOT saved" rather than quietly writing to `localStorage`.

1. Push this folder as the repository root.
2. Settings → Pages → Source: `main`, folder `/ (root)`.
3. The root `index.html` redirects to `public/`.

`.nojekyll` is included so Pages publishes the files exactly as committed rather than running
them through Jekyll.

**For the real thing** — live database, working uploads, real accounts — deploy `server/` to a
host that runs Node (Render, Railway, Fly, an EC2 box) and point `MONGODB_URI` at your cluster.
Express serves `public/` itself, so one deployment covers both halves.

---

## Going live — checklist

The server **refuses to start** with `NODE_ENV=production` until these are right, and names
whichever one is wrong. That is deliberate: every item below is survivable in development and a
live vulnerability on the internet.

| Setting | Value for production |
|---|---|
| `NODE_ENV` | `production` — turns on HSTS, hides stack traces from API responses |
| `JWT_ACCESS_SECRET` | a fresh 64-byte hex value (see below) |
| `JWT_REFRESH_SECRET` | a **different** fresh value — sharing one lets a stolen access token be replayed as a refresh token |
| `ADMIN_PASSKEY` | at least 12 characters. `2010J` is five |
| `CORS_ORIGINS` | your real domain, not localhost |
| `TRUST_PROXY` | `1` behind Render / Railway / nginx / an ALB |

Generate the secrets:

```bash
node -e "console.log(require('crypto').randomBytes(48).toString('hex'))"
```

`TRUST_PROXY` matters more than it looks: behind a proxy, every request appears to come from the
proxy's address unless it is set, so all visitors share one rate-limit bucket and the limits stop
protecting anything.

**Never put real values in `.env.example`** — that file is committed. Only `.env` is gitignored.

### What requires an account

| Action | Signed out |
|---|---|
| Browse listings, search, calculator | allowed |
| Contact form, agency application | allowed (lead capture) |
| **See an owner's phone or email** | **401** |
| **List a property** | **401** |
| Upload photos, favourites | 401 |
| Anything under `/admin` | 401, then 403 unless the account is staff |

Contact details are served by `GET /properties/:id/contact` rather than being part of the listing
payload, so they are absent from the public feed and from the page source. Reveals are capped at
30 per hour per account — an account is cheap to create, so authentication alone would not stop a
scraper — and each one is written to the audit log.

---

## API

Base path `/api/v1`. Every response uses the same envelope:

```json
{ "success": true, "message": "…", "data": { … }, "meta": { … } }
```

| Mount | Purpose |
|---|---|
| `/health` | Liveness and database status |
| `/auth` | Register, login, staff passkey, refresh, logout |
| `/users` | Profile and admin user management |
| `/properties` | Public feed, detail, create, update, delete |
| `/favorites` | Saved listings |
| `/enquiries` | Contact form submissions |
| `/upload`, `/images` | Photo upload and delivery |
| `/admin` | Dashboard: stats, moderation, audit log, enquiry inbox |

### Photos and floor plans

**Currently `MEDIA_DRIVER=mongo` — everything is stored in MongoDB.** The S3 code is in
place and tested but deliberately switched off until there is an AWS account.

Which store is used changes *where the bytes land and nothing else*. The processing,
the sizes, the `srcset` and the placeholders are identical either way.

Upload with `POST /api/v1/upload` (multipart, field `files`, max 12 × 10 MB; add
`?kind=floorplan` for a floor plan). On the way in, each file is:

1. **sniffed** — the format comes from the file's magic bytes, never the `Content-Type` the
   client claims. These bytes are served back under our own domain, so an HTML or SVG file
   renamed `.jpg` would otherwise be a stored-XSS hole. SVG is not an accepted type for the
   same reason.
2. **rotated and stripped** — EXIF orientation is baked into the pixels, then all metadata is
   discarded. Phone photos carry GPS coordinates, and an owner uploading from site should not
   publish their exact location in a downloadable file.
3. **re-encoded** to WebP at 320 / 640 / 1280 / 1920 px (2560 for floor plans), never larger
   than the original, plus a ~20 px inline placeholder for the first paint.

The response is a descriptor — `{ url, publicId, variants, blur, width, height }` — which is
what gets saved on the listing. The browser is handed the whole variant set through `srcset`,
so a phone loading a card grid fetches the 320 px file rather than a 4 MB original.

**In MongoDB mode** every rendition is stored on the image document and served from
`GET /api/v1/images/:id?w=320`, so the `srcset` is real — asking for 320 returns a 320 px file,
not the full-size one scaled down in the browser. Without `?w=` the route means what it always
meant, the full-size image, so URLs saved by older versions still resolve.

**In S3 mode** the renditions go to the bucket and the listing stores their URLs directly.

`MEDIA_DRIVER` picks: `mongo` (force MongoDB, AWS settings ignored), `auto` (S3 when a bucket
and region are set), `s3` (require S3, refuse to start without it). The server names the mode
it chose at startup, and `GET /api/v1/health` reports it as `media: "s3" | "mongo"`.

Switching to S3 later is: fill in the bucket, set `MEDIA_DRIVER=auto`, then migrate. Nothing
breaks in the meantime — `/api/v1/images/:id` streams whatever is still in MongoDB and
301-redirects anything that has moved.

```bash
npm run migrate:images:dry           # report what would move, change nothing
npm run migrate:images               # move it all, repointing every listing
npm run migrate:images -- --only=ID  # one image, to try it first
```

---

## Architecture

The API is strictly layered:

```
routes/       HTTP paths and guards
controllers/  request/response shaping only
services/     business logic — never touches req or res
models/       Mongoose schemas
validators/   zod schemas
middleware/   auth, validation, security, error handling
```

The rule worth keeping: **services never see `req` or `res`.**

On the frontend, `public/assets/api.js` is the only place the browser talks to the server.
If the client and server ever disagree about a response shape, fix it there once rather than
in each page.
