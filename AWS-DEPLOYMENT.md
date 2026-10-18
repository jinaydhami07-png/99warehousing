# 99warehousing on AWS — DynamoDB + App Runner

Order matters. Each phase is verifiable on its own, so if something breaks you
know which step caused it.

---

## Phase 0 — Decisions already made for you

| | | Why |
|---|---|---|
| Region | **ap-south-1** (Mumbai) | Your users and your Atlas cluster are in India. Keep everything in one region — cross-region traffic is slower and billed. |
| Compute | **App Runner** | Deploys from GitHub, HTTPS and certificates handled, custom domains built in, no servers or load balancers to run. |
| Tables | **One per entity** | Simple lookups over a few hundred rows. Single-table design buys nothing here and costs readability. |
| Billing | **PAY_PER_REQUEST** | No capacity planning, no idle cost, stays inside the free tier at your volume. |

Check App Runner is offered in ap-south-1 when you open the console. If it is
not, use **ap-southeast-1** (Singapore) — the next closest — and change
`AWS_REGION` everywhere below to match.

---

## Phase 1 — AWS account and CLI

1. Create an AWS account if you have none. A card is required even on free tier.
2. **Turn on MFA for the root user immediately** (IAM → Security credentials).
   The root user can delete everything in the account and cannot be restricted.
3. Create an admin IAM user for yourself — do not work as root:
   IAM → Users → Create user → attach `AdministratorAccess` → create an access key
   of type *Command Line Interface*.
4. Install and configure the CLI:

```bash
brew install awscli
aws configure
```

   Enter the access key, the secret, `ap-south-1`, and `json`. Verify:

```bash
aws sts get-caller-identity
```

   It prints your account id — note it, you need it in Phase 4.

---

## Phase 2 — Create the DynamoDB tables

From `deploy/server`:

```bash
AWS_REGION=ap-south-1 npm run dynamo:tables
```

Creates seven tables and their indexes. It is safe to re-run: existing tables
are reported and left untouched. Check with:

```bash
AWS_REGION=ap-south-1 npm run dynamo:verify
```

Expect `99w_users`, `99w_properties`, `99w_favorites`, `99w_enquiries`,
`99w_reviews`, `99w_auditlogs`, `99w_images`.

---

## Phase 3 — Move the images to S3 first

**Do this before migrating data.** A DynamoDB item caps at 400 KB; one photo
stored at four widths is larger than that. Image bytes cannot go to DynamoDB at
any size worth serving, so they move to S3 and DynamoDB keeps only the keys.

1. Create a bucket — globally unique name, e.g. `99warehousing-media`:

```bash
aws s3api create-bucket --bucket 99warehousing-media \
  --region ap-south-1 --create-bucket-configuration LocationConstraint=ap-south-1
```

2. Set these in `deploy/server/.env`, then run the migration that already
   exists in this project:

```
MEDIA_DRIVER=s3
AWS_S3_BUCKET=99warehousing-media
AWS_REGION=ap-south-1
```

```bash
npm run migrate:images:dry     # report only
npm run migrate:images         # move them
```

3. Confirm nothing is left behind:

```bash
aws s3 ls s3://99warehousing-media --recursive --summarize | tail -3
```

---

## Phase 4 — Migrate the data

Atlas must allow the IP you run this from (Atlas → Network Access). Dry run
first — it reads MongoDB and writes nothing:

```bash
AWS_REGION=ap-south-1 npm run dynamo:migrate:dry
```

It prints a row count per collection and warns if any image still holds bytes
in MongoDB — fix that in Phase 3 rather than continuing. Then:

```bash
AWS_REGION=ap-south-1 npm run dynamo:migrate
```

The job only reads from MongoDB, preserves every id, and is re-runnable —
rows are written by key, so a second run overwrites rather than duplicates.
**Leave MongoDB exactly as it is until the site has been live on DynamoDB for
a week.** It is your rollback.

Spot-check:

```bash
aws dynamodb scan --table-name 99w_properties --select COUNT --region ap-south-1
```

---

## Phase 5 — Deploy to App Runner

The repository to deploy is **99warehousing-node**, whose root is already the
application root.

1. App Runner → **Create service**
2. Source: **Source code repository** → connect GitHub → repo
   `jinaydhami07-png/99warehousing-node`, branch `main`, deployment **Automatic**
3. Build: **Use a configuration file** (it reads `apprunner.yaml`)
4. Service name `99warehousing`, 1 vCPU / 2 GB to start
5. **Environment variables** — set these in the console, never in the repo:

```
MONGODB_URI            (keep during the transition)
JWT_ACCESS_SECRET
JWT_REFRESH_SECRET
JWT_ACCESS_EXPIRES     15m
JWT_REFRESH_EXPIRES    7d
BCRYPT_ROUNDS          12
ADMIN_PASSKEY
CORS_ORIGINS           https://99warehousing.com,https://www.99warehousing.com
GOOGLE_CLIENT_ID
GOOGLE_CLIENT_SECRET
GOOGLE_CALLBACK_URL    https://99warehousing.com/api/v1/auth/google/callback
MEDIA_DRIVER           s3
AWS_S3_BUCKET          99warehousing-media
RATE_LIMIT_WINDOW_MIN  15
RATE_LIMIT_MAX         300
AUTH_RATE_LIMIT_MAX    10
```

   Do **not** set `PORT` or `NODE_ENV` — `apprunner.yaml` handles both.

6. **Security → Instance role.** This is how the app reaches DynamoDB and S3
   with no AWS keys anywhere. Create a role for service `tasks.apprunner`, and
   attach two inline policies from this repo, replacing the placeholders:

   - `deploy/server/aws-dynamodb-policy.json` — swap `REGION` and `ACCOUNT_ID`
   - `deploy/server/aws-s3-policy.json` — swap `BUCKET`

   Both are scoped to this app's resources only. Neither can create or delete
   a table or bucket.

7. Create, wait for **Running**, then check the URL App Runner gives you:

```
https://<id>.ap-south-1.awsapprunner.com/api/v1/health
```

   Expect `"database":"connected"`.

---

## Phase 6 — Point 99warehousing.com at it

### The apex-domain problem, and the fix

App Runner gives you a hostname, so the domain must point at a *name*, not an
IP. DNS forbids a CNAME at the apex of a zone (`99warehousing.com` with no
subdomain), and **GoDaddy does not support ALIAS/ANAME records**, which is how
other providers work around it.

So you have two options:

**Option A — move DNS hosting to Route 53 (recommended).**
Your domain stays registered with GoDaddy. Only the nameservers change.

1. Route 53 → Hosted zones → **Create hosted zone** for `99warehousing.com`
2. Recreate your existing records there (mail records especially — **if you
   use email on this domain, copy the MX and TXT/SPF records first or mail
   stops**)
3. Route 53 gives you four nameservers. In GoDaddy:
   **My Products → Domain → Nameservers → Change → I'll use my own** and enter
   all four.
4. In App Runner → your service → **Custom domains** → add `99warehousing.com`
   and tick *www*. It returns validation records.
5. Add those records in Route 53, plus an **A record, Alias**, pointing the
   apex at the App Runner service.
6. Propagation takes 10 minutes to a few hours.

**Option B — stay on GoDaddy DNS, serve www.**
Simpler, but the apex can only redirect rather than serve.

1. App Runner → Custom domains → add `www.99warehousing.com`
2. In GoDaddy **DNS → Records**, add the CNAMEs App Runner gives you:
   the certificate-validation records, and `www` → the App Runner hostname
3. For the apex, GoDaddy **Domain → Forwarding** → forward `99warehousing.com`
   to `https://www.99warehousing.com`, **301 permanent**

Verify:

```bash
dig +short www.99warehousing.com
curl -I https://99warehousing.com
```

Then update `GOOGLE_CALLBACK_URL` in Google Cloud Console and in App Runner to
whichever hostname you settled on.

---

## Phase 7 — Switch the database over

The DynamoDB layer is built and tested but **not yet wired into the services** —
the app still reads MongoDB. That wiring is the remaining work, and it should
land behind `DB_DRIVER` so a bad deploy is one environment variable away from
being undone:

```
DB_DRIVER=mongo     → current behaviour
DB_DRIVER=dynamo    → DynamoDB
```

Once switched and stable for a week, remove the Mongoose models, drop
`MONGODB_URI`, and close the Atlas cluster.

---

## What this costs

| | Monthly |
|---|---|
| App Runner, 1 vCPU / 2 GB, always on | ~$25–45 |
| DynamoDB at this volume | $0 (free tier: 25 GB, 25 RCU/WCU) |
| S3 for images, a few GB | ~$0.50 |
| Route 53 hosted zone | $0.50 |
| Data transfer out | a few dollars |

App Runner is the only real cost, and it bills per second the instance exists.
If the site is quiet, set **minimum size 1** and let it scale up, or consider
`PAUSE` on the service when idle — a paused service bills nothing for compute
and resumes in seconds.
