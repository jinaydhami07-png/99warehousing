# Session summary — DynamoDB layer, AWS attempt, and Node on GoDaddy cPanel

Written 2026-11-01. Covers the work from the DynamoDB data layer through to
getting Node running on the GoDaddy server.

---

## Where the site actually stands

**99warehousing.com is down, and was before this session started.** It returns
403 on `/` and 404 on the pages; only `/assets/site.css` resolves. `public_html`
holds leftovers from earlier upload attempts — `99warehousing-app/`, a stray
`.zip`, a nested `public_html/`, `READ-ME-FIRST.txt` — and no `index.html` at
the root.

Nothing in this session changed what the domain serves. The Node work lives in
`~/nodeapp`, outside `public_html`, and the cutover has not been done.

---

## Done

### Data layer — branch `dynamodb`, pushed

Both storage backends now expose the same seven repositories with the same 48
method signatures, and `src/db/index.js` chooses between them from `DB_DRIVER`
(default `mongo`, so a mistyped variable cannot silently point the site at an
empty database).

`tests/db-contract.test.js` runs 38 assertions against whichever driver is
selected. **Both pass.**

```bash
npm run test:contract:mongo
npm run test:contract:dynamo
```

Making the two agree exposed three real defects:

| Defect | Detail |
|---|---|
| Credential leak | `User` marks `password`, `tokenVersion`, `failedLoginAttempts`, `lockedUntil`, `passwordChangedAt` as `select: false`. DynamoDB returns items whole, so hashes would have reached API responses. Both drivers now withhold them unless `{ secrets: true }` — see `src/db/sensitive.js`. |
| Id type mismatch | Several models do `ret.id = ret._id` in `toJSON`, assigning the ObjectId, so `create()` returned an ObjectId id while queries returned a string and they compared unequal. |
| Search divergence | Mongo `$text` matches stemmed whole words; the Dynamo side matches substrings. The Mongo repo now uses a regex over the same fields so both answer a query identically. |

Three MongoDB behaviours have no DynamoDB equivalent and were replaced:
`$text` → a `searchText` attribute derived on write; case-insensitive city
match → a `cityLower` attribute; `$group`/`$avg` rating → one Query and a
reduce.

Favourites became `user+property` as a composite key and reviews
`property+author`, so duplicates are impossible by construction rather than by
an upsert the application has to remember.

**Image bytes cannot go in DynamoDB** — a 400KB item cap against photos stored
at four widths. `image.repo.js` throws rather than writing a row with no file
behind it. `storage.service.js` already implements S3, so that path is
configuration plus the existing `migrate:images` job.

Jobs added: `dynamo-create-tables.js` (idempotent, never drops a table) and
`migrate-mongo-to-dynamo.js` (reads only, preserves ids, re-runnable).
**The migration has never run against real data** — Atlas was unreachable.

### Node on GoDaddy cPanel

| | |
|---|---|
| Node 20.20.2 | installed at `~/nodejs`, added to `~/.bashrc` |
| App | cloned to `~/nodeapp`, 158 packages, `sharp` loads |
| Port binding | works — app answered `/api/v1/health` locally |
| Apache → Node | **works via `mod_proxy`** |
| Passenger | not available — its directives 500 |
| Watchdog script | `~/nodeapp/keepalive.sh`, written and test-run |

### MongoDB Atlas

- Added `the server's shared IP` to the IP access list ("GoDaddy cPanel server")
- **Resumed the cluster** — it had been auto-paused for inactivity. This is
  very likely why Vercel never connected either, not just the IP rules.

---

## Outstanding — needs the account owner

1. **Cron entry.** cPanel → Tools → Cron Jobs, `*/2 * * * *`:
   `$HOME/nodeapp/keepalive.sh >/dev/null 2>&1`
   Without it nothing supervises the app; shared hosting reaps it and it stays
   dead. My tooling blocks creating persistent scheduled jobs.
2. **Real settings file.** Replace `$HOME/nodeapp/server/env` —
   it currently holds an 8-line throwaway pointing at a dead database. Must
   include `NODE_ENV=production` and `TRUST_PROXY=1`. My tooling blocked
   reading the production `.env` as credential handling, correctly.
3. **Cutover.** Once 1 and 2 are done: point the domain root at Node and clear
   the `public_html` leftovers.

> Server paths, IP addresses and account identifiers are deliberately
> omitted — this repository is public. They are in the hosting control panel.

---

## Gotchas worth keeping

- **`.tar.xz` will not extract on this host** — `xz` cannot execute. Use `.tar.gz`.
- **`NODE_ENV` must be `production`** — development mode needs `pino-pretty`,
  which `--omit=dev` does not install, and the app crashes at startup without it.
- **An empty directory with indexes off returns 403**, which briefly looked
  like a proxy denial. Test with a real index file before concluding.
- **Deep links into region-specific AWS console URLs** bounce to the session
  selector and hang. Open the console home and navigate by UI.
- `sanitize-html` is pinned to **2.17.1**; 2.17.2+ pulls an ESM-only
  `htmlparser2` that a CommonJS `require()` cannot load on Vercel.

---

## AWS — attempted, parked

The plan was DynamoDB plus App Runner. It stopped at the account, not the code:

- Account and project renamed as requested, KYC now **Verified**
- A **$20 monthly spend limit** already exists on the project
- **App Runner is not available on the current plan** — the console shows
  "Available with advanced features". App Runner alone runs ~$25–45/month,
  which also conflicts with the $20 cap.
- `aws login` was never completed, so no CLI credentials; no tables, bucket or
  migration were created.
- The project sits in **ap-southeast-2 (Sydney)**, not Mumbai.

`AWS-DEPLOYMENT.md`, `apprunner.yaml` and the two IAM policies are written and
ready if that path is revived.

---

## Correction on the record

Earlier in this project I said Python was the same cPanel feature as Node, so
if one was missing the other would be too. **That was wrong for this plan** —
Setup Python App and Setup Ruby App are both present; only Node is missing. A
Python port of the API is therefore genuinely possible on the current hosting,
which is worth weighing against moving the API to Render or Railway.
