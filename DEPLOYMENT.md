# Deploying 99warehousing

| Part | Where | Why |
|---|---|---|
| **Website** (17 static pages) | GoDaddy cPanel, `public_html` | the domain and SSL already live here |
| **API** (Express) | Vercel, serverless | **the web host cannot reach the database** — see below |
| **Text data** | MongoDB Atlas | already holds the data; no migration needed |
| **Images** | MongoDB today, AWS S3 when wanted | `MEDIA_DRIVER` switches it |

> Server paths, IPs and account identifiers are deliberately absent — this
> repository is public. They live in the hosting control panel.

---

## Why the API is not on the web host

The original plan ran Node on cPanel behind `mod_proxy`, and that part works:
Node installs under the home directory, `mod_proxy` forwards to it, and the
whole thing was proven end to end over HTTPS on the live domain.

It still cannot be used, for a reason that has nothing to do with Node:

**GoDaddy's shared hosting enforces an outbound port allowlist, and MongoDB's
port is not on it.** Measured from the server against a third-party port
responder, using port 80 as a passing control:

| Port | Result |
|---|---|
| 80, 443, 2096 | open |
| 8080, 27016, 27017 | blocked |

So a Node process on this host can never open a connection to Atlas. Vercel
can, so the API runs there.

**Diagnose this before blaming Atlas.** A non-allowlisted IP and a blocked
port produce *identical* symptoms — `MongoServerSelectionError: Server
selection timed out` — because both simply drop the packets. The only thing
that tells them apart is a third-party host that answers on any port.

Two other things were tried and do not work:

- **Apache proxying `/api` to Vercel.** `.htaccess` cannot enable
  `SSLProxyEngine`, so an HTTPS backend returns 500. Shared hosting will not
  allow the directive.
- **Serving the API from the `.vercel.app` host.** It works, but the refresh
  token is an httpOnly `sameSite=strict` cookie and `vercel.app` is on the
  Public Suffix List, so the browser treats it as an unrelated site and
  drops the cookie. Sessions then die silently when the access token
  expires. `sameSite=none` would "fix" this only until the browser's
  third-party cookie rules caught up with it.

The answer to both is a subdomain of the real domain — see **Remaining step**.

---

## How the pieces connect

```
browser ──▶ https://99warehousing.com/…        Apache, static files
        └─▶ https://<api host>/api/v1/…        Vercel function ──▶ Atlas
```

`assets/api.js` holds `PROD_API_HOST`, the single place that knows where the
API lives. `public/.htaccess` lists the same host in the `connect-src` and
`img-src` of its Content-Security-Policy; both the current Vercel host and
the `api.` subdomain are already listed, so moving between them is a one-line
change in `api.js` and nothing else.

Apache serves the pages rather than proxying them. With the API off-host that
is now automatic, but it was a deliberate choice before: nothing supervises a
process on shared hosting, so a backend that dies must not take the website
with it.

**Rollback** is setting `PROD_API_HOST` back to a same-origin `/api/v1`.

---

## Remaining step: the `api.` subdomain

Everything else is live. This one record is outstanding because it needs
account access:

**GoDaddy → Domains → 99warehousing.com → DNS → Add New Record**

| Field | Value |
|---|---|
| Type | `CNAME` |
| Name | `api` |
| Value | `ba7ce51ee2a7791c.vercel-dns-017.com.` |
| TTL | default (1 hour) |

`api.99warehousing.com` is already registered on the Vercel project, so it
goes valid and gets a certificate within minutes of the record resolving.

Then, in this order:

1. `assets/api.js` → `PROD_API_HOST = 'https://api.99warehousing.com'`.
2. Vercel → Settings → Environment Variables → `GOOGLE_CALLBACK_URL` →
   `https://api.99warehousing.com/api/v1/auth/google/callback`.
3. Google Cloud Console → the OAuth client → add that same URL to
   **Authorized redirect URIs**. Google rejects a callback that is not listed
   there, and the error it shows the user says nothing useful.
4. Redeploy, and copy `public/` to `public_html` again.

Until that is done the site works, but a signed-in session ends when the
15-minute access token expires instead of refreshing.

---

## Settings

The API refuses to start in production unless all of this holds, and the
message names whatever is wrong:

| Setting | Why it is refused otherwise |
|---|---|
| `NODE_ENV=production` | development mode loads `pino-pretty`, absent under `--omit=dev` |
| `TRUST_PROXY=1` | every request arrives from the proxy, so rate limits would apply to all visitors at once |
| `JWT_ACCESS_SECRET` / `JWT_REFRESH_SECRET` | 32+ chars, and **different** — one shared key lets a stolen access token be replayed as a refresh token |
| `ADMIN_PASSKEY` | 12+ chars; it is the only thing in front of the moderation dashboard |
| `CORS_ORIGINS` | no `localhost`, never `*` — credentials ride along with requests |
| `GOOGLE_CALLBACK_URL` | https and a live host, or sign-in returns the code to the wrong place |

This guard is worth knowing about before a cutover rather than during one:
the file that works in development is rejected in production on five counts
at once.

On Vercel these are project environment variables. The cPanel copy at
`~/nodeapp/server/env` is left in place but unused; it is not in this
repository and must never be.

**Atlas Network Access must contain `0.0.0.0/0`.** Vercel functions have no
fixed egress IP, so there is nothing narrower to allow. The database password
is the remaining gate — rotate it if it was ever weak.

---

## Host quirks — each of these cost an hour to find

- **No "Setup Node.js App"** on this plan, though *Setup Python App* and
  *Setup Ruby App* are both there. Do not reason from how cPanel normally
  works; check the panel.
- **Passenger is unavailable** — its `.htaccess` directives return 500.
- **`.tar.xz` cannot be extracted.** `xz` is not executable on this host.
  Use the `.tar.gz` build of anything you download.
- **An empty directory with indexes off returns 403**, which is
  indistinguishable from a proxy denial. Put a real `index.html` in before
  concluding that proxying is blocked.
- **Atlas free clusters auto-pause** after prolonged inactivity, and a paused
  cluster refuses every connection regardless of the IP access list. Check
  this first when connections fail — it presents exactly like an allowlist
  problem.
- **`git pull` in `~/nodeapp` fails the first time**: a hand-written
  `keepalive.sh` is sitting there untracked and Git will not overwrite it.
  Move it aside rather than deleting it.

---

## Deploying a change

The site:

```bash
cd ~/nodeapp && git pull origin main && cp -R public/. ~/public_html/
```

`public_html/.htaccess` ships with the site, so it updates with everything
else. Keep `~/nodeapp` outside `public_html` — Apache serves anything inside
it as a plain file, and that directory holds the database password. That has
already happened once on this project.

The API deploys from the flattened repository `99warehousing-node`, which is
what Vercel builds. A fix made in `deploy/` here must be mirrored there, and
a fix made there must be carried back, or the next build regenerates the bug.
Check with `diff -rq deploy/public <clone>/public`.

---

## Images on S3

The code already supports this; it is configuration, not new work.
`storage.service.js` implements S3 behind `MEDIA_DRIVER`, and
`npm run migrate:images` moves what is currently stored in MongoDB.

1. Create a bucket in the AWS console.
2. Create an IAM user limited to that bucket — `aws-s3-policy.json` in
   `deploy/server/` is the policy; replace `BUCKET` with the real name.
   Serverless functions cannot use an instance role, so this needs a key.
3. Add `MEDIA_DRIVER=s3`, `AWS_S3_BUCKET`, `AWS_REGION`,
   `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY` to the Vercel
   environment.
4. `npm run migrate:images:dry`, then `npm run migrate:images`.

S3 is reachable from the cPanel host too (`s3.<region>.amazonaws.com:443`),
if images are ever served from there.

---

## What happened to the AWS plan

Running the whole backend on AWS with DynamoDB was scoped and partly built,
then parked:

- **App Runner is not available on the account's current plan**, and at
  roughly $25–45/month it also conflicts with the $20 monthly spend limit set
  on the project.
- The project sits in ap-southeast-2 (Sydney), not Mumbai.

The work still stands and is reusable: `apprunner.yaml`, the two IAM
policies, and a complete DynamoDB data layer on the `dynamodb` branch, with
contract assertions passing against both MongoDB and DynamoDB. It matters
more than it looks: DynamoDB speaks HTTPS on 443, which **is** reachable from
the cPanel host, so it is the one data store that would let the API run
there. Switching is a `DB_DRIVER` change plus wiring the services to the
repository layer.
