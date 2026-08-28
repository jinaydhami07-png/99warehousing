# 99 Warehousing

Industrial real-estate marketplace for India — warehouses, industrial sheds, land parcels and
logistics parks, priced per square foot.

Three folders, each with one job:

| Folder | What it is | Do you edit it? |
|---|---|---|
| **`deploy/`** | The source. The app as it runs locally on `http://localhost:5000`. | **Yes — this is the only place to make changes.** |
| **`godaddy-upload/`** | Build output: exactly the files that go on the GoDaddy cPanel server, with production settings. | No — regenerate it. |
| **`project-files/`** | Notes, the abandoned prototype, machine-specific scripts. Nothing here runs. | Rarely. |

---

## Working on the site

```bash
cd deploy/server
npm run pm2:status     # is it running?
npm run pm2:restart    # after changing server code — file watching is off on purpose
```

Then open **http://localhost:5000**. Express serves the API *and* the pages from one origin, so
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

## Two things that bite on this machine

**Node is not on PATH.** In Git Bash, prefix with:

```bash
export PATH="/c/Program Files/nodejs:$PATH"
```

**DNS SRV lookups are refused on this network.** `mongodb+srv://` fails through Node's resolver
while Windows' own resolver succeeds. `deploy/server/src/config/database.js` retries via
8.8.8.8 / 1.1.1.1 — seeing that fallback in the logs is normal, not an error. Any standalone script
that calls `mongoose.connect()` directly will fail; require the project's own
`src/config/database` and call `connect()` instead.

**There is no version control.** No git repository, so a bad refactor is unrecoverable. `git init`
remains the single most valuable thing to do to this project.
