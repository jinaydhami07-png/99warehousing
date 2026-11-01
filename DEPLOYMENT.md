# Deploying 99warehousing

The plan, as settled on 2026-11-01:

| Part | Where | Why |
|---|---|---|
| **Website + API** | GoDaddy cPanel, Node behind `mod_proxy` | the domain and SSL already live here |
| **Text data** | MongoDB Atlas | already holds the data; no migration needed |
| **Images** | AWS S3 | photos are too large for the database to carry well |

> Server paths, IPs and account identifiers are deliberately absent — this
> repository is public. They live in the hosting control panel.

---

## How Node runs here, and why it is unusual

GoDaddy's plan for this account has **no "Setup Node.js App"**. It does have
*Setup Python App* and *Setup Ruby App*, which is the opposite of the usual
assumption that those three appear together — so do not reason from how cPanel
normally works; check the panel.

That absence means **Passenger is unavailable**: its `.htaccess` directives
return 500. What does work is `mod_proxy`. Node runs as an ordinary process on
a local port, and a rewrite in `public_html/.htaccess` forwards requests to it.
This was tested end to end and serves correctly over HTTPS on the live domain.

The cost of having no Passenger is that **nothing supervises the process**.
Shared hosting reaps stray processes, so a cron watchdog is not optional here —
it is the only thing keeping the site up.

---

## Host quirks — each of these cost an hour to find

- **`.tar.xz` cannot be extracted.** `xz` is not executable on this host.
  Use the `.tar.gz` build of anything you download.
- **`NODE_ENV` must be `production`.** Development mode loads `pino-pretty`,
  which `npm ci --omit=dev` does not install, and the app exits at startup.
- **An empty directory with indexes off returns 403**, which is
  indistinguishable from a proxy denial. Put a real `index.html` in before
  concluding that proxying is blocked.
- **Atlas free clusters auto-pause** after prolonged inactivity, and a paused
  cluster refuses every connection regardless of the IP access list. Check this
  first when connections fail — it presents exactly like an allowlist problem.

---

## Current state

Already done on the server:

- Node 20.20.2 installed under the home directory and added to `~/.bashrc`
- The app cloned to `~/nodeapp`, production dependencies installed, `sharp` loads
- `~/nodeapp/keepalive.sh` written and verified
- Apache → Node routing proven through `mod_proxy`
- Atlas: the cPanel server's IP added to the access list, and the cluster resumed

Not done:

- The cron entry that runs the watchdog — cPanel only lets the account owner
  create one
- Uploading the settings file. It is generated and verified at
  `deploy/server/env.production`; the server still has a throwaway pointing at
  a dead database
- The cutover: the domain still serves the old `public_html` files, which
  return 403/404

The API itself is verified end to end against Atlas on this machine, in
production mode and behind Apache: 18 listings served with correct pagination,
the JWT round trip (register, authenticate, tamper-reject, refresh by
httpOnly cookie, sign in, reject a wrong password) all behaving, stack traces
suppressed, and the security headers present. What is left is hosting, not
code.

---

## Finishing the deployment

### 1. Settings file

A filled-in file is generated locally at `deploy/server/env.production`:
real credentials carried over from the development `.env`, and fresh JWT
secrets and admin passkey generated for it. Upload it through File Manager as
`~/nodeapp/server/env` — **it is not in this repository and must not be**, so
it has to be copied across by hand.

It is named `env`, not `.env`, because cPanel File Manager hides dotfiles and
routinely drops the dot. `server/src/config/env.js` accepts either.

The app refuses to start in production until all of this holds, and the
message names whatever is wrong:

| Setting | Why it is refused otherwise |
|---|---|
| `NODE_ENV=production` | development mode loads `pino-pretty`, absent under `--omit=dev` |
| `TRUST_PROXY=1` | every request arrives from 127.0.0.1, so rate limits would apply to all visitors at once |
| `PORT=5050` | must match the `.htaccess` rewrite |
| `JWT_ACCESS_SECRET` / `JWT_REFRESH_SECRET` | 32+ chars, and **different** — one shared key lets a stolen access token be replayed as a refresh token |
| `ADMIN_PASSKEY` | 12+ chars; it is the only thing in front of the moderation dashboard |
| `CORS_ORIGINS` | no `localhost`, never `*` — credentials ride along with requests |
| `GOOGLE_CALLBACK_URL` | https and the live domain, or sign-in returns the code to the wrong host |

This guard is worth knowing about before the cutover rather than during it:
the file that works in development is rejected in production on five counts
at once.

### 2. Update the checkout

The `.htaccess` and the watchdog are both in the deployment repository now,
so the server picks them up with a pull. In cPanel → **Terminal**:

```bash
cd ~/nodeapp && git pull origin main
```

**This will fail the first time.** A hand-written `keepalive.sh` is already
sitting there untracked, and Git refuses to overwrite an untracked file:
`error: untracked working tree file 'keepalive.sh' would be overwritten`.
Move it aside and pull again — keeping it rather than deleting it, so the
version that was verified to work on the host is still there to compare
against:

```bash
mv ~/nodeapp/keepalive.sh ~/nodeapp/keepalive.sh.original
cd ~/nodeapp && git pull origin main && chmod +x keepalive.sh
```

### 3. Watchdog

cPanel → **Tools → Cron Jobs**, every two minutes:

```
*/2 * * * *   $HOME/nodeapp/keepalive.sh >/dev/null 2>&1
```

Only the account owner can create this, which is why it is still outstanding.

Confirm it works by killing the process and waiting: it should return within
two minutes and `~/nodeapp/keepalive.log` should record the restart. The
script checks `/health` over HTTP rather than looking for a running process,
because the failure this hosting produces is a process that is alive and
wedged, which a PID check calls healthy.

### 4. Cutover

Only after 1 and 2 are verified.

1. Clear the leftovers from `public_html` — the stray `.zip`, the nested
   `public_html/`, `READ-ME-FIRST.txt`, the old app folder.
2. Upload the site: the contents of `deploy/public/` into `public_html/`,
   including `.htaccess` (File Manager must be set to show dotfiles).
3. Check `https://99warehousing.com/api/v1/health` reports
   `"database":"connected"`, then load the homepage and the warehouses page.

`deploy/public/.htaccess` ships with the site and carries the rewrite:

```apache
RewriteRule ^api/(.*)$ http://127.0.0.1:5050/api/$1 [P,QSA,L]
```

**Only `/api/*` is proxied; Apache serves the pages itself.** An earlier
draft of this guide proxied everything to Node, which is the obvious reading
of "put Node behind Apache" and the wrong one here: nothing supervises the
process on this hosting, so a Node crash would take the entire website down
rather than just the API. Verified against Apache 2.4 with this exact file —
with Node killed, pages and assets still return 200 and only `/api` degrades
to 503, which the pages absorb by falling back to their bundled sample data.

The port appears in two places, here and `PORT` in `server/env`. If they ever
disagree, every API call returns 502 while the site looks perfectly healthy —
check that first when the pages load but no data appears.

**Rollback** is removing the `API → Node` block from that `.htaccess`. Apache
then serves the site exactly as it did before Node existed.

---

## Images on S3

The code already supports this; it is configuration, not new work.
`storage.service.js` implements S3 behind `MEDIA_DRIVER`, and
`npm run migrate:images` moves what is currently stored in MongoDB.

1. Create a bucket in the AWS console.
2. Create an IAM user limited to that bucket — `aws-s3-policy.json` in
   `deploy/server/` is the policy; replace `BUCKET` with the real name.
   cPanel cannot use an instance role, so this needs an access key.
3. Add to `server/env`:

```
MEDIA_DRIVER=s3
AWS_S3_BUCKET=...
AWS_REGION=...
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
```

4. `npm run migrate:images:dry`, then `npm run migrate:images`.

**Keep `~/nodeapp` outside `public_html`.** Apache serves anything inside it as
a plain file, and this directory now holds an AWS key as well as the database
password. That has already happened once on this project.

---

## What happened to the AWS plan

Running the whole backend on AWS with DynamoDB was scoped and partly built,
then parked — not for technical reasons:

- **App Runner is not available on the account's current plan** ("available
  with advanced features"), and at roughly $25–45/month it also conflicts with
  the $20 monthly spend limit set on the project.
- The project sits in ap-southeast-2 (Sydney), not Mumbai.

The work already done still stands and is reusable: `apprunner.yaml`, the two
IAM policies, and a complete DynamoDB data layer on the `dynamodb` branch, with
38 contract assertions passing against both MongoDB and DynamoDB. Switching
storage is a `DB_DRIVER` change, so that path can be revived whenever the
hosting question is settled.
