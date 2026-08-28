# Project files — not part of the deployment

Everything here is reference material, superseded code, or specific to this machine.
**None of it is needed to run or host the app** — that lives in `../deploy/` (source) and
`../godaddy-upload/` (what goes on the server).

Keeping it separate means the folder you upload contains only what actually runs.

```
project-files/
  docs/                 design notes, roadmap, handoff
  old-nextjs-backend/   the abandoned Next.js prototype
  local-tools/          Windows startup scripts, the upload build script, old seeder
  rebrand-backup/       pre-rebrand copy of the pages — safe to delete
```

---

## `docs/`

| File | What it is |
|---|---|
| `PROJECT_HANDOFF.md` | **Start here.** Full context: architecture, what works, what doesn't, credentials, and the environment traps specific to this machine. Written to be pasted into a fresh chat. |
| `ROADMAP.md` | Delivery plan — features, hosting, security. |
| `BPSF_Fullstack_Guide.docx` | Original vendor specification. |

## `old-nextjs-backend/`

A Next.js (App Router) backend built during an earlier phase and then abandoned in favour of
the Express server now in `deploy/server/`. **Nothing serves this code and nothing imports it.**

It is kept only until someone confirms nothing valuable is left in it — then delete the whole
folder. Two backends in one project is a standing source of confusion about which one is real.

Its `.env.local` is here too, holding credentials for that dead prototype. The live secrets are
in `deploy/server/.env`.

## `local-tools/`

Machine-specific helpers, not deployment artifacts.

| File | What it does |
|---|---|
| `build-upload.js` | Rebuilds `../godaddy-upload/` from `../deploy/`. Run it after **every** change you intend to publish. Preserves the production `.env`. |
| `start-api-silent.vbs` | Starts the API at logon with no console window. A **copy** of this lives in the Windows Startup folder. |
| `start-api.bat` | Runs `pm2 resurrect` against `deploy/server`. |
| `scripts/seed.js` | Standalone seeder from the Next.js era. The current one is `deploy/server/src/jobs/seed.js` (`npm run seed:fresh`). |

⚠ **The paths in these scripts are absolute.** If the project folder is ever moved or renamed,
update `start-api.bat`, `start-api-silent.vbs`, **and** the copy in:

```
%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\99warehousing-API.vbs
```

Node is not on this machine's PATH, so these scripts call `node.exe` by full path. In Git Bash
you will need:

```bash
export PATH="/c/Program Files/nodejs:$PATH"
```
