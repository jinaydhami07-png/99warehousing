# 99 Warehousing — Project Handoff

> **Purpose of this file.** Paste it into a new chat to bring a fresh assistant fully up to
> speed. It records what the project is, what has actually been built and verified, what is
> deliberately missing, and the traps that cost real time to find. Written 2026-08-01.

---

## 1. What this project is

An Indian industrial real-estate marketplace — warehouses, industrial sheds, land parcels —
priced and browsed **per square foot**. Buyers search listings; owners submit listings; an
internal admin moderates them.

**Working directory:** `C:\Users\HP\Documents\buypersquarefoot`
**Not a git repository.** There is no version control. Back up before large refactors.

---

## 2. How it got here (condensed history)

The project moved through several phases. Each one reset some earlier decisions, so read this
before assuming a file is current.

| Phase | What happened |
|---|---|
| Refactor | Started with 33 HTML files, roughly half duplicates (`bpsf-*.html` twins). Deleted 17 dead files, renamed the survivors to clean names. |
| Roadmap | Reconciled a vendor PDF (`Replica of 99 aces.pdf`) with `BPSF_Fullstack_Guide.docx`. Decisions: keep Node + MongoDB, adopt AWS for hosting, use the PDF's feature scope. |
| UI | Matte liquid-glass navbar, hero rebuilt to a supplied wireframe (slogan left, three property cards right), real photo background at `public/assets/img/hero.png`. |
| Brokers removed | Broker pages and the broker option in signup were deleted outright. Do not reintroduce them. |
| Admin portal | Dashboard is reachable **only** through the login page → "Staff access" → passkey. Never linked from the public nav. |
| Backend v1 (Next.js) | An `app/` + `lib/` Next.js backend was built, then abandoned. |
| Backend v2 (Express) | **Current.** Built to a detailed spec: layered architecture, pooled Mongo connection, security middleware, Mongoose validation, global error handler, `.env` template. |
| Live data | Real MongoDB Atlas cluster connected, 14 dummy properties seeded, PM2 supervision, auto-start on logon. |
| Wiring fixes | Frontend and backend were speaking different dialects. Fixed (see §6). |

### Folder split

Three folders at the top level, each with one job:

```
buypersquarefoot/
  deploy/            ← THE SOURCE. Edit here. Also what runs locally.
    index.html         redirect to public/, so static hosts have an entry point
    .nojekyll          stops GitHub Pages running Jekyll over the site
    public/            16 pages + assets
    server/            Express + Mongoose API   (.env = DEVELOPMENT settings)

  godaddy-upload/    ← BUILD OUTPUT. What goes on the GoDaddy cPanel server.
    app.js             Passenger startup file
    package.json       at the root, so cPanel's "Run NPM Install" finds it
    public/  server/   same layout, but server/.env holds PRODUCTION values
    DEPLOY-TO-GODADDY.md

  project-files/     ← everything else, not needed to run anything
    docs/              this file, the roadmap, the vendor spec
    old-nextjs-backend/  the abandoned prototype (41 dead files)
    local-tools/       Windows startup scripts, build-upload.js, old seeder
    rebrand-backup/    pre-rebrand copy, safe to delete
```

**`godaddy-upload/` is generated, not edited.** Change `deploy/`, then run:

```bash
node project-files/local-tools/build-upload.js
```

It mirrors `public/` and `server/src`, rewrites the root manifest, and deliberately **preserves
`godaddy-upload/server/.env`** — that file holds the production secrets and differs from the
development one on purpose. Editing the upload folder directly is how a fix ends up live-only or
local-only; it drifted once already.

`server/` sits beside `public/` rather than inside it, so the Express static handler has exactly
one directory to expose. Verified: `/server/.env` and path-traversal attempts at it both 404.

**The Next.js prototype is dead code.** Nothing serves or imports
`project-files/old-nextjs-backend/`. Deleting it is safe and recommended once you have confirmed
nothing valuable is left inside.

---

## 3. Architecture

### Frontend — plain static HTML/CSS/JS

No build step, no framework. Served by Express itself from `public/`.

```
public/
  index.html  warehouses.html  land-parks.html  property-detail.html
  calculator.html  submit-listing.html  contact.html  insights.html
  partners.html  about.html  terms.html  privacy.html
  login.html  register.html  forgot-password.html  dashboard.html
  assets/
    api.js          <- the ONLY place the browser talks to the server
    bpsf-store.js   <- favourites / compare, localStorage mirror
    demo-data.js    <- offline fallback backend
    img/hero.png
```

**`deploy/public/assets/api.js` is the seam.** Every page goes through it. If the frontend and backend
ever disagree about a response shape, fix it there once rather than in each page.

### Backend — Express, strictly layered

```
deploy/server/src/
  server.js              entry point, graceful shutdown
  app.js                 middleware order, mounts /api/v1
  config/
    database.js          Mongo connection + pooling + DNS fallback  <- see §7
    env.js               env parsing and validation
    logger.js            pino
  routes/       (9)      HTTP paths only
  controllers/  (6)      req/res handling only
  services/     (6)      business logic — never touches req/res
  models/       (5)      Mongoose schemas
  middleware/   (5)      auth, validate, security, error handler
  validators/   (3)      zod schemas
  jobs/seed.js           dummy data seeder
```

The rule that matters: **services never see `req` or `res`.** Controllers pull validated data
off the request, call a service, and shape the response. Keep it that way.

### API surface (`/api/v1`)

| Mount | Purpose |
|---|---|
| `/health` | liveness + DB status |
| `/config` | public runtime config |
| `/auth` | register, login, admin passkey, refresh, logout, me, google (stub) |
| `/users` | self-service + admin user management |
| `/properties` | public feed, detail, create, update, delete, mine |
| `/favorites` | saved listings (per account) |
| `/enquiries` | contact form + "my enquiries" |
| `/admin` | dashboard surface — stats, moderation, audit, enquiry inbox, users |

**Response envelope — every endpoint:**

```json
{ "success": true, "message": "…", "data": { … }, "meta": { … } }
```

Errors:

```json
{ "success": false, "message": "Validation failed",
  "errors": [ { "field": "grade", "message": "Invalid enum value…" } ] }
```

`api.js` unwraps both shapes so pages see flat objects. Do not make pages reach through `.data`.

### Auth model

Dual token, deliberately:

- **Access token** — 15 min, returned in the body, held in memory + `sessionStorage`, sent as
  `Authorization: Bearer …`.
- **Refresh token** — 7 days, `httpOnly` cookie. JavaScript cannot read it. That is the point.
- `tokenVersion` on the user allows "log out everywhere" by invalidating all refresh tokens.
- Admin passkey compared with `crypto.timingSafeEqual`, not `===`.

---

## 4. Credentials — exact locations

There are **two** populated `.env` files, and confusing them is the easiest mistake here:

| File | Used by | Notable values |
|---|---|---|
| `deploy/server/.env` | local development | `NODE_ENV=development`, CORS on localhost, admin passkey `2010J`, Google callback on the live domain |
| `godaddy-upload/server/.env` | the GoDaddy server | `NODE_ENV=production`, CORS on `https://99warehousing.com`, `TRUST_PROXY=1`, **freshly generated** JWT secrets, a 20-character admin passkey |

`deploy/server/.env.example` is the committed template — placeholders only, never real values.

The two deliberately carry **different JWT secrets**, so a leaked development key cannot mint a
session on the live site. They share the same MongoDB cluster, so local work and production see
the same listings — split that if it ever matters.

### Admin access

Login page → **"Staff access ▾"** at the bottom → passkey.

- **Locally:** `2010J`
- **In production:** the 20-character value in `godaddy-upload/server/.env`. Production refuses to
  start with a passkey under 12 characters, which is why they differ.

There is no admin link in the public navigation, by design. A normal user listing a property never
sees the dashboard.

### Google sign-in — configured and built

`GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` are set in both `.env` files, and the flow is
implemented (`googleAuthUrl` / `googleCallback` in `auth.service.js`) — authorization-code
exchange with a CSRF `state` cookie, done directly against Google rather than through passport.

The callback is pinned to `https://99warehousing.com/api/v1/auth/google/callback` and **must be
registered** under *Authorised redirect URIs* on the OAuth client. It is intentionally not derived
from the request `Host` header: a forged Host would otherwise redirect the authorization code to
an attacker's server.

Consequence worth knowing: with the callback set to the live domain, pressing the Google button on
localhost sends the user to the production server. To test locally, register the localhost URI as a
second entry and point `GOOGLE_CALLBACK_URL` at it temporarily.

### Image hosting — no third-party account needed

Cloudinary was never used. Photos are stored **as binary in MongoDB** and served by the API itself
(`/upload`, `/images/:id`). See §5.

---

## 5. What works, and what does not

### Verified working (exercised against the live database, not just assumed)

- Staff login via real button clicks → dashboard loads with live counts.
- Dashboard: 14 properties, stats tiles, moderation queue, audit log.
- Approve / reject — persist to MongoDB and move the counters.
- Public feed shows **approved listings only**; pending and rejected never leak.
- Property detail page loads the correct listing from `?id=`.
- Register → login → wrong password rejected (401) → buyer blocked from admin (403).
- Submit a listing → lands as `pending` → invisible publicly → admin approves → goes live.
- Contact form files a real enquiry; admin inbox lists it; status can be updated.
- Favourites: add / remove / list, idempotent, 401 when signed out.
- Validation errors now surface readable field-level messages.
- Server survives crashes (PM2) and starts on logon.
- **Photo upload** — pick or drag photos on the submit form, they upload immediately, thumbnails
  render, and the listing carries them through moderation to the public card and detail gallery.

### Image storage — how photos work

Photos are stored **as binary in MongoDB**, not on a CDN. That was chosen so the feature needs
no Cloudinary or S3 account and no keys to configure.

- `POST /api/v1/upload` — authenticated, multipart, field name `files`, up to 12 per request,
  5 MB each. Returns `[{ url, publicId, contentType, size }]`.
- `GET /api/v1/images/:id` — public (a plain `<img src>` cannot send an auth header), served with
  `Cache-Control: immutable` and an ETag, so repeat views come back `304` with no body.
- `DELETE /api/v1/images/:id` — uploader or admin only; also pulls the reference out of any
  listing still pointing at it, so no listing is left with a dead image URL.

Two things worth keeping if this is ever refactored:

- **The format is decided by sniffing magic bytes, not by the `Content-Type` the client sends.**
  That header is a claim, and these bytes are served back from our own origin. An HTML file
  renamed `.jpg` is rejected on the content, not the extension.
- **SVG is deliberately not an allowed type.** It is an executable document and would be a
  stored-XSS hole when served same-origin.

The binary field is `select: false`, so ordinary property queries never drag image bytes along.
Migration path if traffic ever demands a CDN: upload to object storage and rewrite
`Property.images[].url`. Nothing else changes, because the rest of the app only ever sees a URL.

### Seeded data

14 properties: **10 approved, 3 pending, 1 rejected.** Test artifacts created during
verification were deleted afterwards; the database is back to exactly this state.

A test buyer account was left in place deliberately, so the non-admin path can be exercised
without registering each time:

```
buyer.test@example.com  /  TestPass!234
```

Delete it before going anywhere near production.

### Not built

- **Google OAuth callback.** Stub only, as above.
- **Password reset.** `forgot-password.html` exists; no backend behind it.
- **Payments, subscriptions, notifications, email.** Never started.
- **Tests.** `npm test` is wired to `node --test tests/` but there are no test files.

---

## 6. The wiring bugs that were fixed (do not reintroduce)

These were all frontend/backend mismatches. Every one produced a *silent* failure — no error,
just an empty page or a dead button.

1. **CSP killed every button on the site.** Helmet's default is `script-src-attr 'none'`, which
   blocks inline `onclick=` attributes *separately* from inline `<script>` blocks. The pages use
   ~310 inline handlers. Result: functions were defined, attributes were present in the DOM, but
   `element.onclick === null` and **nothing was logged to the console**. Fixed in
   `deploy/server/src/middleware/security.js` by adding `scriptSrcAttr: ["'unsafe-inline'"]`.
   This grants nothing beyond the `'unsafe-inline'` already allowed on `scriptSrc`. The real
   hardening is to move handlers to `addEventListener` and then drop `'unsafe-inline'` from
   **both** directives.
2. **Response envelope mismatch.** API returned `{ data: { accessToken, user } }`; `api.js` read
   `d.accessToken` → `undefined` → login appeared to succeed then bounced back. Fixed with
   `unwrap()` in `api.js`.
3. **Error envelope mismatch.** API sends `{ message, errors[] }`; `api.js` read
   `{ error, details }`, so every form showed "Request failed (422)" instead of the actual
   problem. Fixed with `errorMessage()` in `api.js`.
4. **Dashboard called routes that did not exist** (`/admin/*`). Added `deploy/server/src/routes/admin.routes.js`
   which delegates to the existing controllers behind one `authenticate + authorize('admin')` guard.
5. **`property` vs `item` key mismatch** between controller and detail page. Standardised on `item`.
6. **The whole site broke on static hosting.** On GitHub Pages there is no Node process, so every
   `/api/v1/…` request is answered by the host's own 404 page. `api.js` only fell back to the
   bundled demo backend on a *network* failure, and a 404 is a perfectly successful HTTP
   response — so the fallback never fired. Sign-in, staff login, favourites and the contact form
   all died with a bare "Request failed (404)" while `BPSFDemo` sat loaded and unused.
   Fixed in `deploy/public/assets/api.js`: a 404/405 whose body is **not JSON** means "there is no
   API at this origin" → switch to demo mode. A genuine 404 from a live backend arrives as our
   JSON envelope and still surfaces to the caller, so nothing is masked.

---

## 7. Gotchas specific to this machine

These cost hours. Do not rediscover them.

- **Node is not on PATH.** It is installed at `C:\Program Files\nodejs\node.exe` but the PATH
  registry entries are empty. In Git Bash, prefix commands with:
  ```bash
  export PATH="/c/Program Files/nodejs:$PATH"
  ```
- **DNS SRV lookups are refused on this network.** `mongodb+srv://` fails with
  `querySrv ECONNREFUSED` through Node's default resolver, while Windows' own
  `Resolve-DnsName` succeeds. `deploy/server/src/config/database.js` handles this: on SRV failure only,
  it switches to `8.8.8.8` / `1.1.1.1` and retries once. You will see the fallback in the logs —
  that is expected, not an error. **Any standalone script that connects directly with
  `mongoose.connect()` will fail.** Require `./src/config/database` and call `connect()` instead.
- **`pkill` from Git Bash does not kill Windows processes.** It reports success and the process
  keeps running. Use PowerShell `Stop-Process` instead.
- **Watch for PM2 false positives.** A stray manual `node` once held port 5000 while PM2
  crash-looped 9 times behind it — health checks passed the whole time. Cross-check the PID and
  the uptime counter, not just "online".
- **Do not trust an Atlas SQL / Data Federation endpoint as the connection string.** One was used
  by mistake early on: it connects at socket level (so `/health` passes) but every real write
  fails with `auth required`.
- **Check for `<>` around the password.** A connection string was once pasted with the literal
  placeholder brackets still in it (`<2010j>`).
- **Defer/parse ordering.** This bit twice. Inline `<script>` blocks run during parsing while
  `defer`-ed libraries have not executed yet → `ReferenceError` → blank page, nothing in console.
  Page boot code must be wrapped in `DOMContentLoaded`, and data libraries loaded non-deferred.

---

## 8. Running it

The server runs under **PM2** and restarts automatically on crash and on logon.

```bash
cd deploy/server
npm run pm2:status     # is it alive?
npm run pm2:logs       # tail logs
npm run pm2:restart    # after a code change — required, watch is off
npm run pm2:stop
```

Site: **http://localhost:5000** — Express serves both the API and the static pages, so there is
no CORS and the refresh cookie works normally.

Reseed the dummy data:

```bash
npm run seed:fresh
```

PM2 deliberately runs **one** process (`instances: 1`, `exec_mode: 'fork'`). Clustering would
multiply the Mongo connection pool by the worker count and can exhaust the Atlas shared-tier
connection limit. Revisit only under measured CPU load.

---

## 9. Suggested next steps

In the order that unblocks the most:

1. **`git init`.** There is no version control at all right now. This is the single riskiest
   fact about the project.
2. **Delete the dead Next.js backend** (`project-files/old-nextjs-backend/`) so there is one obvious place for
   server code.
3. **Add photos to the seeded listings.** All 14 currently fall back to the type emoji, so the
   upload feature is invisible until someone submits a listing with photos. Consider extending
   `deploy/server/src/jobs/seed.js` to attach a few generated images.
4. Finish Google OAuth (callback + account linking).
5. Password reset flow behind `forgot-password.html`.
6. Move the ~310 inline handlers to `addEventListener`, then drop `'unsafe-inline'` from both
   CSP script directives.
7. Write tests — `npm test` is wired up and `tests/` is empty.
8. Deploy: AWS was the chosen target. Set `NODE_ENV=production` (this turns on HSTS and hides
   stack traces), put real secrets in the host's secret manager rather than `.env`, and set
   `CORS_ORIGINS` to the real domain.

---

## 10. Conventions worth preserving

- Comments explain **why**, not what. Several in this codebase document a decision that looks
  wrong until you know the reason (single PM2 instance, `record()` swallowing its own errors,
  route ordering). Keep that habit.
- **Route order is load-bearing.** Literal paths must be declared before `/:id`, or
  `/properties/mine` gets captured as `id="mine"` and fails ObjectId validation with a
  confusing 400.
- **Never trust an exit code or a log line as proof.** Everything in §5 was confirmed by
  exercising the real path — real writes, real DNS queries, real button clicks. A function
  called directly from the console is *not* evidence that its button works; that is exactly how
  the CSP bug hid for so long.
