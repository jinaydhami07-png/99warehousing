/**
 * Rebuilds godaddy-upload/ from deploy/.
 *
 * The upload folder is a BUILD OUTPUT, not a second copy to edit. Editing it
 * directly, or editing deploy/ and forgetting to rebuild, is how a fix ends up
 * working locally and missing on the live site — it has already happened once
 * with a stylesheet.
 *
 * Always edit deploy/, then run:
 *     node project-files/local-tools/build-upload.js
 *
 * server/.env is deliberately PRESERVED, never overwritten: it holds the
 * production secrets and differs from the development one on purpose.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..');
const SRC = path.join(ROOT, 'deploy');
const OUT = path.join(ROOT, 'godaddy-upload');

/* Never shipped: installed on the host, or local-only noise. */
const SKIP = new Set(['node_modules', 'logs', '.git', 'ecosystem.config.js', '.DS_Store']);

function copyDir(from, to) {
  fs.mkdirSync(to, { recursive: true });
  for (const entry of fs.readdirSync(from, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue;
    const s = path.join(from, entry.name);
    const d = path.join(to, entry.name);
    if (entry.isDirectory()) copyDir(s, d);
    else fs.copyFileSync(s, d);
  }
}

function rmDir(p) {
  if (fs.existsSync(p)) fs.rmSync(p, { recursive: true, force: true });
}

/* 1. Static site — a straight mirror. */
rmDir(path.join(OUT, 'public'));
copyDir(path.join(SRC, 'public'), path.join(OUT, 'public'));

/* 1b. The cPanel root landing page. It sits beside the site rather than
       inside it — public_html/index.html, with everything else under
       public_html/99warehousing/ — so it is not part of public/ and has to be
       copied on its own. Left out of earlier builds, which is how the upload
       folder ended up without one. */
fs.copyFileSync(path.join(SRC, 'index.html'), path.join(OUT, 'index.html'));

/* 2. API source. The production .env lives beside it and must survive. */
rmDir(path.join(OUT, 'server', 'src'));
copyDir(path.join(SRC, 'server', 'src'), path.join(OUT, 'server', 'src'));
fs.copyFileSync(
  path.join(SRC, 'server', '.env.example'),
  path.join(OUT, 'server', '.env.example')
);

/* Apache deny rule for the API directory. Only matters when the app root
   ends up inside public_html — where every file here, settings included,
   would otherwise be downloadable. Harmless anywhere else, since Node reads
   these files from disk rather than over HTTP. */
fs.copyFileSync(
  path.join(SRC, 'server', '.htaccess'),
  path.join(OUT, 'server', '.htaccess')
);

/* 3. Root manifest: dependencies only. devDependencies are nodemon,
      pino-pretty and pm2 — none used in production, and cPanel supervises
      the process itself. */
const pkg = JSON.parse(fs.readFileSync(path.join(SRC, 'server', 'package.json'), 'utf8'));
fs.writeFileSync(
  path.join(OUT, 'package.json'),
  JSON.stringify(
    {
      name: '99warehousing',
      version: pkg.version || '1.0.0',
      private: true,
      description: '99warehousing.com — Express + MongoDB API and static site',
      main: 'app.js',
      engines: pkg.engines,
      scripts: { start: 'node app.js' },
      dependencies: pkg.dependencies,
    },
    null,
    2
  ) + '\n',
  'utf8'
);
fs.copyFileSync(
  path.join(SRC, 'server', 'package-lock.json'),
  path.join(OUT, 'package-lock.json')
);

const envPath = path.join(OUT, 'server', '.env');
console.log('Rebuilt godaddy-upload/');
console.log('  pages   :', fs.readdirSync(path.join(OUT, 'public')).filter((f) => f.endsWith('.html')).length);
console.log('  api src :', (function count(d) {
  return fs.readdirSync(d, { withFileTypes: true })
    .reduce((n, e) => n + (e.isDirectory() ? count(path.join(d, e.name)) : 1), 0);
})(path.join(OUT, 'server', 'src')), 'files');
console.log('  .env    :', fs.existsSync(envPath) ? 'preserved' : 'MISSING — the app will not start');
