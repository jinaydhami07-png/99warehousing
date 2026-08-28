# 99 Warehousing — session handoff

Paste this whole file into a new Claude Code session as the first message, with:
**"Read this, then continue. Ask me nothing you can verify yourself."**

---

## What this is

**99 Warehousing** (`99warehousing.com`) — an Indian industrial real-estate marketplace.
Browse/list warehouses, cold storage, logistics parks and industrial land. Formerly
"Buy Per Square Foot" (BPSF — the prefix survives in some identifiers, that's expected).

Working directory on the old machine: `C:\Users\HP\Documents\buypersquarefoot`
**Not a git repository.** No version control — be careful with destructive edits.

## Stack

- **Backend:** Express 4 + Mongoose 8 + MongoDB Atlas
- **Frontend:** 17 static HTML pages, vanilla JS, no build step, no framework
- **Auth:** JWT dual-token — access (15m, Bearer) + refresh (7d, httpOnly cookie),
  `tokenVersion` for log-out-everywhere. Google OAuth authorization-code flow with a
  CSRF `state` cookie.
- **Images:** **MongoDB** (`MEDIA_DRIVER=mongo`). Uploads are sniffed by magic bytes
  (never the client's `Content-Type`), EXIF-rotated, stripped of metadata, and
  re-encoded to WebP at 320/640/1280/1920 px plus a ~20px inline blur placeholder.
  Every rendition is stored on the image document and served from
  `/api/v1/images/:id?w=320`, so `srcset` is real — asking for 320 gets a 320px
  file. Bare `/api/v1/images/:id` still means full size, so pre-existing URLs work.
  **The S3 path is fully built and tested but deliberately switched off** until
  there is an AWS account; `MEDIA_DRIVER` is the switch (`mongo` / `auto` / `s3`)
  and the AWS settings are ignored entirely while it is `mongo`. To turn it on:
  fill in the bucket, set `MEDIA_DRIVER=auto`, run `npm run migrate:images`.
  Startup log and `/api/v1/health` (`media: "s3" | "mongo"`) name the active mode.
- **Layering:** routes → controllers → services → models. **Services never touch
  `req`/`res`.** Validation is Zod, and schemas are `.strict()` on purpose.

## Folder layout

```
buypersquarefoot/
  deploy/                  ← THE SOURCE. Edit here. Runs locally.
    index.html             ← cPanel root landing page (self-contained, inline CSS)
    public/                ← 17 pages + assets/
    server/                ← Express API (.env = development)
  godaddy-upload/          ← BUILD OUTPUT — never edit directly
  project-files/
    local-tools/build-upload.js   ← regenerates godaddy-upload/ from deploy/
    docs/  old-nextjs-backend/  rebrand-backup/
```

Rebuild the upload folder with:

```bash
node project-files/local-tools/build-upload.js
```

It preserves `godaddy-upload/server/.env` (production values). Last build: 17 pages,
52 API source files.

## Machine gotchas (cost hours if unknown)

1. **Node is not on PATH.** It's at `C:\Program Files\nodejs\node.exe`. In Git Bash:
   `export PATH="/c/Program Files/nodejs:$PATH"`
2. **DNS SRV lookups are refused on this network.** `mongodb+srv://` fails with
   `querySrv ECONNREFUSED`. `server/src/config/database.js` retries via 8.8.8.8 /
   1.1.1.1 — seeing that fallback in the logs is **normal**.
   **Any standalone script calling `mongoose.connect()` directly will fail.** Require
   the project's `./src/config/database` and call `connect()`. The script must also
   live *inside* `deploy/server/` or Node won't resolve its dependencies.
3. **`pkill` from Git Bash reports success but does not kill Windows processes.**
   Use PowerShell `Stop-Process -Force -Confirm:$false`.
4. Duplicate API instances silently hold port 5000 — check
   `Get-NetTCPConnection -LocalPort 5000` before assuming a restart took effect.

## Running it

```bash
cd deploy/server && npm start
```

Serves both the API and the static site at `http://localhost:5000`.
Health check: `http://localhost:5000/api/v1/health`.

Seed data: `npm run seed` (or `npm run seed:fresh`). Currently 12 approved properties
across 11 cities. **None have images** — that's data, not a bug.

## Verification discipline that has paid off here

- **Never trust exit codes or logs — click the real button.** Logs have lied.
- Measure computed CSS and rects in the browser, not intent.
- **Style recalc freezes when the Browser pane is hidden.** Load-time measurements are
  valid; DOM mutations made from `javascript_tool` will *not* restyle. To test a state,
  bake it into a temp copy of the file and load that, then delete the copy.
- Cross-check PID *and* uptime when confirming a restart.

## Work completed (most recent first)

**Demo banner removed, demo data made opt-in** — the bar pinned along the bottom of every page
is gone (`showBanner` deleted from `demo-data.js`, its CSS out of `site.css`, both call sites
removed). Removing only the warning would have been worse than leaving it, since it was the one
marker saying the listings on screen were fabricated — so the fallback that produced them is now
**off unless explicitly requested** (`window.BPSF_DEMO = true` before api.js, or `?demo=1`).
With it off, an unreachable API shows an honest "could not load" state with a Try again button
on `warehouses.html` and `land-parks.html` instead of substituting a fake catalogue. Watch for:
`var engaged` lived inside the deleted banner block and is still read by the dashboard's toast —
it was re-declared, and `demo-data.js` is `'use strict'`, so losing it would have been a runtime
ReferenceError, not a silent no-op.

**Saves can no longer be silently lost** — `api.js` had an offline fallback that answered
WRITES from the bundled demo backend, which persists to `localStorage`. It was gated on "the
real API has not answered yet this session", which is exactly the state during a first-load
submit: the listing was written to one browser, reported as saved, and never reached MongoDB —
indistinguishable from the site resetting itself. Writes now **always** raise `notSaved` when
the server is unreachable; only reads still fall back. Two things fed the same bug: the 3.5s
read timeout was applied to every request, so any upload over a slow link was aborted mid-flight
and treated as "no server here" (writes now get 30s, uploads 180s, refresh 10s); and "is there
an API here?" was guessed from the status code, so a static host's 501 counted as *reached the
API* — it now keys on the response being JSON, which is what actually distinguishes this API
from a host's error page. Reads also no longer swap in demo listings once the API has answered,
since replacing real listings with invented ones on screen is the other half of what looks
like a reset.

**Images moved to AWS S3** — MongoDB now stores text only. `storage.service.js` is the single
place that talks to S3 (lazily loaded, so a host without the SDK still boots);
`image.service.js` sniffs, rotates, strips metadata and renders WebP variants with `sharp`.
`Property.images[]` and `floorPlan` gained `variants`/`blur`/`width`/`height`, the validators
accept them, and `API.media.*` in `api.js` turns them into `srcset` markup — one
`API.media.descriptor()` so the submit form, the admin form and the server cannot drift on
which fields get saved (the admin edit form *was* silently dropping them). The public feed now
sends only the cover photo per listing plus an `imageCount`, since cards never draw the rest.
Deleting a listing now deletes its objects. `npm run migrate:images` moves pre-S3 images across
and repoints every listing; `--only=<id>`, `--limit=`, `--dry-run` and `--keep-bytes` are there
to do it in careful batches. **S3 is deliberately off** — `MEDIA_DRIVER=mongo` in
`server/.env`, so every rendition is stored on the image document and served from
`/api/v1/images/:id?w=320`. The MongoDB path is the full pipeline, not a degraded one: same
sniffing, same resizing, same srcset, same placeholders. Only the destination differs.

**Fake content purge + real reviews** — the detail page had 9 spec cards with only 2 fed
from the database; every listing claimed a 3 MW supply, 3,200 sq ft office and VESDA fire
system. Description was a fixed Bhiwandi paragraph. 12 amenities, 5 distances, a
block-diagram floor plan, and 2 testimonials signed by named people at named logistics
companies — all invented. The listing form *already asked* for the specs but the inputs
had no `id`, so everything typed was discarded on submit.
Now: model carries the fields, both forms collect them, sections render from data or hide
themselves. Added a real review system (model/validator/service/controller/routes +
dashboard moderation queue). 11/11 service checks passed against the live DB, cleaned up
after. `land-parks.html` had **zero** API calls — 6 parcels hardcoded — now data-driven,
which is why uploaded photos never appeared there.

**Warehouses filters** — sidebar checkboxes had no handlers at all; several filtered on
fields that don't exist (Built-to-Suit, SEZ, LEED, availability windows). Options are now
built from the returned data so no filter can offer a dead choice. Removed invented counts
`(842)`/`(340)`, "2,400+ Listings", "48 Cities", and pagination hardcoded to page 48.
Sort options had no `value` so everything fell back to newest.

**Mobile pass** — icons sized down, arrangement fixed across 8 pages; logo replaces the
wordmark below 560px; auth pages showed no brand at all on mobile (`.auth-left` was
`display:none`) — now a 62px brand strip. WhatsApp emoji → vector glyph.

**Brand** — WhatsApp number is **9819216600** (`wa.me/919819216600`). "99" in the wordmark
is 1.18em and pulled tight (`margin-right: -0.10em`).

**Earlier** — admin dashboard wired to real data, image upload in MongoDB, contact gating
behind sign-in, Google OAuth, production env guards, full rebrand to 99 Warehousing.

## Known-good gotchas in this codebase

- Helmet's **`script-src-attr` is separate from `script-src`** — it was `'none'` and
  silently killed all 310 inline `onclick=` handlers with no console error.
  `security.js` now sets `scriptSrcAttr: ["'unsafe-inline'"]`.
- **CSS specificity beats source order** — `.brand-mark` (0,1,0) lost to `.nav-logo span`
  (0,1,1). Qualify selectors; a page's inline `<style>` beats linked `site.css` only at
  *equal* specificity.
- `loading="lazy"` inside `display:none` never loads.
- `api.js` `unwrap()` turns `{success, data, meta}` into `{items, page, pages, total}`.
- `api.js` **never fakes a save**: a non-GET that can't reach the server throws with
  `notSaved = true` rather than falling back to demo mode.
- Admin form sends every detail field on every save (`''`/`null` = clear), so the
  validators use `.nullish()` not `.optional()`.

## Outstanding / not built

- **Verification document upload** — the API stores images only (PDFs fail the magic-byte
  check). The fake upload zone and its two hardcoded "already uploaded" files were removed
  and replaced with an honest note. Real document storage is unbuilt work.
- **Password-reset email delivery** — currently files a support enquiry instead.
- **Office phone on `contact.html` is still a placeholder** — `+91 22 4890 7000`.
- `project-files/old-nextjs-backend/` — 41 dead files, safe to delete.
- In-browser filtering holds the catalogue in memory, capped at 600 listings. Past that
  it shows a `+` rather than hiding results, but real server-side filtering is needed.

## Deploy to GoDaddy (cPanel)

Layout is `public_html/index.html` **beside** `public_html/99warehousing/` (everything
else). So from `godaddy-upload/`:

- `index.html` → `public_html/index.html`
- everything else → `public_html/99warehousing/`

Then **restart the Node app in cPanel** or new routes 404. Hard-refresh (Ctrl+F5) or
cached `site.css` / `site.js` will make it look unchanged.

Standing manual steps:

- **`godaddy-upload/server/.env` is preserved by the build and has no AWS block yet.**
  Copy the `AWS_*` / `MEDIA_*` section from `.env.example` into it and fill in the bucket,
  or production keeps storing image bytes in MongoDB. Check the startup log — it names the
  driver — or `GET /api/v1/health` (`media: "s3"`).
- cPanel needs `npm install` after this deploy: `@aws-sdk/client-s3` and `sharp` are new.
  `sharp` ships a native binary; if the host cannot install it the server still starts and
  logs that resizing is off (images are then stored at full size).
- Google Cloud Console → Authorised JavaScript origins: `https://99warehousing.com`,
  `https://www.99warehousing.com`. Redirect URIs: those + `/api/v1/auth/google/callback`.
  Must match `.env` byte-for-byte — exact-string check, no trailing slash.
- MongoDB Atlas → Network Access → allow the GoDaddy server IP.
- HTTPS must be on (the session cookie is `Secure`).
- cPanel needs "Setup Node.js App" (Phusion Passenger).

## First things to do on the new machine

1. Copy the whole `buypersquarefoot` folder across — **`deploy/server/.env` and
   `godaddy-upload/server/.env` are not in any repo and hold the Atlas URI, JWT secrets,
   admin passkey and Google client secret. Without them nothing boots.**
2. `cd deploy/server && npm install`
3. `npm start`, then confirm `http://localhost:5000/api/v1/health` returns 200
4. Whitelist the new machine's IP in MongoDB Atlas Network Access

---

**Preferences to carry over:** do the setup and running yourself, report outcomes rather
than recipes. Verify by the real path. Don't use workflows or subagents unless asked.
