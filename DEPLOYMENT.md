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

- The cron entry that runs the watchdog
- The real settings file (a throwaway is in place, pointing at a dead database)
- The cutover: the domain still serves the old `public_html` files, which
  return 403/404

---

## Finishing the deployment

### 1. Settings file

Replace `~/nodeapp/server/env` with the production one, through File Manager.
It must include:

```
NODE_ENV=production
TRUST_PROXY=1
PORT=<the port the .htaccess rewrite targets>
MONGODB_URI=...
JWT_ACCESS_SECRET=...        # 32+ chars, different from the refresh secret
JWT_REFRESH_SECRET=...
ADMIN_PASSKEY=...            # 12+ chars
CORS_ORIGINS=https://99warehousing.com,https://www.99warehousing.com
```

`TRUST_PROXY=1` matters: behind the rewrite every request appears to come from
the server itself, so without it rate limits apply to all visitors at once.

The file is named `env`, not `.env`, because cPanel File Manager hides dotfiles
and routinely drops the dot. `server/src/config/env.js` accepts either.

### 2. Watchdog

cPanel → **Tools → Cron Jobs**, every two minutes:

```
*/2 * * * *   $HOME/nodeapp/keepalive.sh >/dev/null 2>&1
```

Confirm it is working by killing the process and waiting: it should come back
within two minutes, and `~/nodeapp/keepalive.log` should record the restart.

### 3. Cutover

Only after 1 and 2 are verified.

1. Clear the leftovers from `public_html` — the stray `.zip`, the nested
   `public_html/`, `READ-ME-FIRST.txt`, the old app folder.
2. Add the proxy rewrite to `public_html/.htaccess`:

```apache
RewriteEngine On
RewriteRule ^(.*)$ http://127.0.0.1:<PORT>/$1 [P,L]
```

3. Check `https://99warehousing.com/api/v1/health` reports
   `"database":"connected"`, then load the homepage and the warehouses page.

**Rollback** is removing that `.htaccess` rewrite — Apache goes straight back
to serving files from `public_html`.

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
