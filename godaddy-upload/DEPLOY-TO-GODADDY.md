# Deploying 99warehousing.com to GoDaddy cPanel

Everything in this folder is what goes on the server. Nothing else from the project is needed.

---

## 0. First, check your hosting plan can run Node

**This is the one thing that decides whether the site works.**

Log into cPanel and look for **Setup Node.js App** (usually under *Software*).

| What you see | What it means |
|---|---|
| "Setup Node.js App" is there | Everything below works. Continue. |
| It is missing | Your plan is **static-only PHP hosting**. The API, database, logins, uploads and admin dashboard cannot run. See *If Node is not available* at the bottom. |

GoDaddy's *Deluxe / Ultimate / Business* Linux cPanel plans generally include it. The cheapest
*Economy* plan and all Windows plans do not.

---

## 1. Upload

Zip **the contents of this folder** (not the folder itself), then in cPanel:

1. **File Manager** → go to your home directory (`/home/<youruser>`), *not* `public_html`.
2. Create a folder named `99warehousing`.
3. Upload the zip into it and **Extract**.

You should end up with:

```
/home/<youruser>/99warehousing/
    app.js
    package.json
    package-lock.json
    public/          ← the website
    server/          ← the API
        .env         ← your live settings and secrets
        src/
```

The app root is deliberately **outside `public_html`**. Anything inside `public_html` is served
as a raw file by Apache, and `server/.env` holds your database password and session keys.

> **`.env` may not have uploaded.** Many File Managers hide dotfiles. In File Manager choose
> *Settings → Show Hidden Files (dotfiles)* and confirm `server/.env` is there. If it is missing,
> the app will not start.

---

## 2. Create the Node application

cPanel → **Setup Node.js App** → **Create Application**:

| Field | Value |
|---|---|
| Node.js version | **18** or higher (20 is ideal) |
| Application mode | **Production** |
| Application root | `99warehousing` |
| Application URL | `99warehousing.com` — the domain root, no subfolder |
| Application startup file | `app.js` |

Click **Create**.

---

## 3. Install dependencies

On that same screen, click **Run NPM Install**. It reads `package.json` and builds `node_modules`
on the server.

`node_modules` is deliberately **not** in this folder — it is ~50 MB, slow to upload, and the
packages should be fetched for the server's own Node version.

If the button errors, use cPanel's **Terminal** (if your plan has it):

```
cd ~/99warehousing
npm install --omit=dev
```

---

## 4. Point the domain at the app and turn on HTTPS

1. cPanel → **Domains** — make sure `99warehousing.com` resolves to this hosting account.
   If the domain is registered at GoDaddy but hosted elsewhere, update the nameservers first and
   allow for DNS propagation.
2. cPanel → **SSL/TLS Status** → select the domain → **Run AutoSSL**.

**HTTPS is not optional here.** In production the session cookie is issued with the `Secure` flag,
so browsers will refuse to store it over plain `http` and nobody will be able to stay signed in.
Once the certificate is live, add a redirect so visitors always land on `https://`.

---

## 5. Allow the server to reach MongoDB

In [MongoDB Atlas](https://cloud.mongodb.com) → **Network Access** → **Add IP Address**, add your
hosting server's IP (cPanel shows it as *Shared IP Address* on the main page).

Skip this and every request fails with a connection timeout — the app starts fine, then nothing
loads. It is the most common cause of "it worked locally".

---

## 6. Start it

Back on **Setup Node.js App**, click **Restart**. Then check:

- `https://99warehousing.com/api/v1/health` → `{"success":true, ... "database":"connected"}`
- `https://99warehousing.com/` → the site

If health reports `"database":"disconnected"`, revisit step 5.

---

## 7. Finish Google sign-in

In [Google Cloud Console](https://console.cloud.google.com) → *APIs & Services → Credentials* →
your OAuth client → **Authorised redirect URIs**, add exactly:

```
https://99warehousing.com/api/v1/auth/google/callback
```

Google matches the string literally — a trailing slash or `http` instead of `https` fails with
`redirect_uri_mismatch`.

---

## Your new staff passkey

The old passkey (`2010J`) is five characters, and the server **refuses to start in production**
with anything under twelve — it is the only thing between the internet and the moderation
dashboard. A new one has been generated and is in `server/.env`:

```
ADMIN_PASSKEY=HiAPhS5wQ9Vawa3kJxYW
```

Sign in at `https://99warehousing.com/login.html` → *Staff access* → paste that value. Change it to
anything you prefer, twelve characters or more, then Restart the app.

---

## After any change

Editing files on the server does **not** take effect until you press **Restart** in *Setup Node.js
App*. Node holds the code in memory.

---

## If something goes wrong

The app refuses to start rather than run misconfigured, and says exactly which setting is wrong.
Read the log: *Setup Node.js App* → your app → **stderr.log**, or `~/99warehousing/stderr.log`.

| Symptom | Cause |
|---|---|
| 503 / "cannot start" | `npm install` not run, or `server/.env` missing |
| Health shows `database: disconnected` | Atlas IP allowlist (step 5) |
| Site loads but sign-in never sticks | HTTPS not active — the session cookie is `Secure` |
| Google button → `redirect_uri_mismatch` | Callback URI not registered, or does not match exactly |
| "Refusing to start in production" | The message names the setting. Fix it in `server/.env`, Restart. |

---

## If Node is not available on your plan

The site still works as a **demo**: it detects that no backend is reachable and falls back to a
built-in sample dataset, showing a banner that says so. Every page, the navigation, browsing and
the admin screens are usable — but nothing saves, because there is no server.

To do that, upload **only the contents of `public/`** into `public_html`.

For the real thing — live listings, accounts, photo uploads — you need somewhere that runs Node.
Either upgrade the cPanel plan to one with *Setup Node.js App*, or host the app on
[Render](https://render.com) / [Railway](https://railway.app) (both have free tiers) and point the
GoDaddy domain at it with a CNAME. The domain stays with GoDaddy either way.
