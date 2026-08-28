# Buy Per Square Foot

Industrial real-estate marketplace for India — warehouses, industrial sheds, land parcels and
logistics parks, priced per square foot.

**This folder is the deployable app.** Push it to GitHub, or upload it to a host, as-is.
Everything else (design notes, the abandoned Next.js prototype, machine-specific scripts) lives
in `../project-files/` and is deliberately not here.

---

## Layout

```
deploy/
  index.html        redirect to public/ — the entry point for static hosting
  .nojekyll         stops GitHub Pages running Jekyll over the site
  public/           the site: 16 pages, CSS, JS, images
  server/           Express + Mongoose API
```

`server/` is kept out of `public/` so the Express static handler has exactly one directory to
expose. Nothing under `server/` — including `.env` — is reachable over HTTP.

---

## Running it

Requires Node 18.18+ and a MongoDB connection string.

```bash
cd server
npm install
npm start
```

Then open **http://localhost:5000**. Express serves the API *and* the pages from one origin, so
there is no CORS to configure and the refresh-token cookie works normally.

For continuous running, the app is supervised by PM2:

```bash
npm run pm2:start     # start under supervision
npm run pm2:status    # is it alive?
npm run pm2:logs      # tail logs
npm run pm2:restart   # after a code change — file watching is off on purpose
```

Seed 14 demo listings:

```bash
npm run seed:fresh
```

---

## Configuration

Everything lives in **`server/.env`** (never committed — see `.gitignore`). Copy
`server/.env.example` to get started.

| Key | Purpose |
|---|---|
| `MONGODB_URI` | MongoDB Atlas connection string |
| `JWT_ACCESS_SECRET` / `JWT_REFRESH_SECRET` | Session signing keys |
| `ADMIN_PASSKEY` | Staff login passkey |
| `CORS_ORIGINS` | Allowed origins |
| `PORT` | Defaults to 5000 |

### Admin access

There is no admin link in the public navigation, by design. Go to `login.html`, expand
**"Staff access"** at the bottom, and enter the passkey from `ADMIN_PASSKEY`.

---

## Hosting on GitHub Pages

GitHub Pages serves static files only — **there is no Node process**, so the API cannot run
there. The site handles this: when it detects no backend, it falls back to a bundled demo
dataset and shows a banner saying so. Every page, the navbar, sign-in and the property browser
all work; the data is just not live.

1. Push this folder as the repository root.
2. Settings → Pages → Source: `main`, folder `/ (root)`.
3. The root `index.html` redirects to `public/`.

`.nojekyll` is included so Pages publishes the files exactly as committed rather than running
them through Jekyll.

**For the real thing** — live database, working uploads, real accounts — deploy `server/` to a
host that runs Node (Render, Railway, Fly, an EC2 box) and point `MONGODB_URI` at your cluster.
Express serves `public/` itself, so one deployment covers both halves.

---

## API

Base path `/api/v1`. Every response uses the same envelope:

```json
{ "success": true, "message": "…", "data": { … }, "meta": { … } }
```

| Mount | Purpose |
|---|---|
| `/health` | Liveness and database status |
| `/auth` | Register, login, staff passkey, refresh, logout |
| `/users` | Profile and admin user management |
| `/properties` | Public feed, detail, create, update, delete |
| `/favorites` | Saved listings |
| `/enquiries` | Contact form submissions |
| `/upload`, `/images` | Photo upload and delivery |
| `/admin` | Dashboard: stats, moderation, audit log, enquiry inbox |

### Photos

Images are stored **as binary in MongoDB**, so the app needs no Cloudinary or S3 account.
Upload with `POST /api/v1/upload` (multipart, field `files`, max 12 × 5 MB); they are served
back from `GET /api/v1/images/:id` with immutable caching and an ETag.

The image format is determined by **sniffing the file's magic bytes**, not the `Content-Type`
the client claims — these bytes are served back from our own origin, so an HTML or SVG file
renamed `.jpg` would otherwise be a stored-XSS hole. SVG is not an accepted type for the same
reason.

---

## Architecture

The API is strictly layered:

```
routes/       HTTP paths and guards
controllers/  request/response shaping only
services/     business logic — never touches req or res
models/       Mongoose schemas
validators/   zod schemas
middleware/   auth, validation, security, error handling
```

The rule worth keeping: **services never see `req` or `res`.**

On the frontend, `public/assets/api.js` is the only place the browser talks to the server.
If the client and server ever disagree about a response shape, fix it there once rather than
in each page.
