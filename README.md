# 99Warehousing

Industrial real-estate marketplace for India — warehouses, industrial sheds, land parcels and
logistics parks, priced per square foot.

Three folders, each with one job:

| Folder | What it is | Do you edit it? |
|---|---|---|
| **`deploy/`** | The source. The app as it runs locally on `http://localhost:5050`. | **Yes — this is the only place to make changes.** |
| **`godaddy-upload/`** | Build output: exactly the files that go on the GoDaddy cPanel server, with production settings. | No — regenerate it. |
| **`project-files/`** | Notes, the abandoned prototype, machine-specific scripts. Nothing here runs. | Rarely. |

---

## Working on the site

```bash
cd deploy/server
npm run pm2:status     # is it running?
npm run pm2:restart    # after changing server code — file watching is off on purpose
```

Then open **http://localhost:5050**. Express serves the API *and* the pages from one origin, so
there is no CORS to configure and the refresh-token cookie behaves normally.

Front-end files are static — edit and reload, no restart and no build step.

---

## Publishing to the live site

```bash
node project-files/local-tools/build-upload.js
```

That rebuilds `godaddy-upload/` from `deploy/`. Then follow
[`godaddy-upload/DEPLOY-TO-GODADDY.md`](godaddy-upload/DEPLOY-TO-GODADDY.md).

**Never edit `godaddy-upload/` by hand.** It is regenerated, so changes made there are lost on the
next build — and changes made only in `deploy/` never reach the server unless you rebuild. That
drift has already happened once, with a stylesheet.

The one exception is `godaddy-upload/server/.env`: it holds the production secrets, differs from
the development copy deliberately, and the build script leaves it alone.

---

## Where things are

| Looking for | Path |
|---|---|
| The pages | `deploy/public/*.html` |
| The single place the browser calls the API | `deploy/public/assets/api.js` |
| Shared behaviour (navbar, logo, mobile menu) | `deploy/public/assets/site.js` |
| API routes / controllers / services | `deploy/server/src/` |
| Local settings and secrets | `deploy/server/.env` |
| Production settings and secrets | `godaddy-upload/server/.env` |
| Full context for a new session | `project-files/docs/PROJECT_HANDOFF.md` |
| Deployment steps | `godaddy-upload/DEPLOY-TO-GODADDY.md` |

---

## Machine notes (macOS)

**Node** is installed via Homebrew — `/opt/homebrew/bin/node`, already on PATH. Start the API with:

```bash
npm --prefix deploy/server start
```

**Port 5000 is taken by macOS ControlCenter** (AirPlay Receiver), so `deploy/server/.env` sets
`PORT=5050`. Either keep 5050 or turn off AirPlay Receiver in System Settings → General →
AirDrop & Handoff.

**MongoDB Atlas connects normally.** The `mongodb+srv://` SRV-lookup failure described in the old
handoff was specific to the previous Windows machine's network; the 8.8.8.8 / 1.1.1.1 fallback in
`src/config/database.js` is not exercised here.

---

## Hosting reality

The GoDaddy plan in use has no *Setup Node.js App*, so the Express API cannot run there. The site
is deployed as static files and runs on the bundled client-side demo backend
(`deploy/public/assets/demo-data.js`), enabled per page by `window.BPSF_DEMO = true` and labelled
by `assets/demo-banner.js`. Browsing, filters, favourites, compare and the admin screens all work;
nothing persists to a server.

For real shared listings the API needs a host that runs Node — a cPanel plan with the Node app
feature, or Render / Railway with the domain pointed at it by CNAME.

`.cpanel.yml` deploys `deploy/public/` to `public_html` when cPanel pulls from GitHub. Set the
`CPANELUSER` placeholder in it before first deploy.

---

## Settings files

`.gitignore` excludes every settings file — `.env`, a dot-less `env`, and `api.env` have all
existed in this project. Only `.env.example` is tracked. Never commit a real one.
