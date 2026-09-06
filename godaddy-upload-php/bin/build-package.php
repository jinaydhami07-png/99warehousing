<?php
/**
 * Build the upload-ready deployment package.
 *
 *     php bin/build-package.php
 *
 * Produces, at the repository's dist/:
 *
 *     99warehousing-php/
 *     ├── public_html/        → upload INTO public_html
 *     └── 99warehousing-app/  → upload one level ABOVE public_html
 *     99warehousing-php-cpanel.zip
 *
 * ── Why the split ────────────────────────────────────────────────────
 * The source tree keeps public/ inside the project because that is
 * convenient to develop in. On the server it must be the other way round:
 * the web root holds only the site, and the application — config/.env above
 * all — sits outside it, where Apache cannot serve it whatever happens to
 * the .htaccess files.
 *
 * Doing that split by hand at deploy time is a step that gets forgotten,
 * and forgetting it publishes the database password. So it is done here,
 * once, by a script.
 * ────────────────────────────────────────────────────────────────────
 *
 * This is a BUILD OUTPUT. Never edit dist/ — edit the source and rebuild.
 */
declare(strict_types=1);

$root = dirname(__DIR__);                    // godaddy-upload-php/
$dist = dirname($root) . '/dist';            // the repo's dist/
$out = $dist . '/99warehousing-php';
$web = $out . '/public_html';
$app = $out . '/99warehousing-app';

/* Never shipped: local-only noise, build output, and — the important one —
   the real config/.env and anything already uploaded. A package containing
   either would carry live secrets or another site's photos. */
$SKIP = ['.DS_Store', '.git', 'node_modules', '.env', 'app.log'];

function say(string $line = ''): void
{
    echo $line . PHP_EOL;
}

function rmTree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            rmTree($path . '/' . $entry);
        }
    }
    rmdir($path);
}

/**
 * @param array<int,string> $skip
 * @param callable|null $filter Return false to omit a path.
 */
function copyTree(string $from, string $to, array $skip, ?callable $filter = null): int
{
    if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) {
        throw new RuntimeException("Cannot create $to");
    }

    $copied = 0;
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || in_array($entry, $skip, true)) {
            continue;
        }
        $src = "$from/$entry";
        $dst = "$to/$entry";

        if ($filter !== null && !$filter($src, $entry)) {
            continue;
        }

        if (is_dir($src)) {
            $copied += copyTree($src, $dst, $skip, $filter);
        } else {
            copy($src, $dst);
            $copied++;
        }
    }
    return $copied;
}

say('99Warehousing — build deployment package');
say(str_repeat('─', 46));

if (!is_dir($dist) && !mkdir($dist, 0755, true) && !is_dir($dist)) {
    say("✖ Cannot create $dist");
    exit(1);
}

rmTree($out);

/* ── public_html: the contents of public/, not the folder itself ──
   Uploading public/ whole would put the site at /public/ and every link
   would 404. */
$files = copyTree($root . '/public', $web, $SKIP, static function (string $src, string $entry): bool {
    /* Images already uploaded on the developer's machine are not part of
       the build. The directory itself is kept — with its .htaccess, which
       is what stops anything in it executing. */
    return !preg_match('#/uploads/(photos|floorplans)$#', $src);
});
say(sprintf('  public_html/            %d files', $files));

/* ── The application, outside the web root ── */
$appFiles = 0;
foreach (['app', 'bin', 'config', 'database'] as $dir) {
    $appFiles += copyTree($root . "/$dir", "$app/$dir", $SKIP);
}
foreach (['README.md', 'DEPLOY-TO-GODADDY.md', '.htaccess'] as $file) {
    if (is_file($root . '/' . $file)) {
        copy($root . '/' . $file, "$app/$file");
        $appFiles++;
    }
}

/* storage/logs must exist and be writable; git cannot carry an empty
   directory, so it is created here rather than left to the first request
   to discover it is missing. */
mkdir("$app/storage/logs", 0775, true);
copy($root . '/storage/.htaccess', "$app/storage/.htaccess");
file_put_contents("$app/storage/logs/.gitkeep", '');
$appFiles += 2;

say(sprintf('  99warehousing-app/      %d files', $appFiles));

/* ── The config the split layout needs ──
   PUBLIC_DIR is the one setting that differs from the development default,
   and getting it wrong means uploads land somewhere Apache never serves.
   Ship it already set rather than as a step in a document. */
$example = (string) file_get_contents($root . '/config/.env.example');
$example = str_replace(
    "MEDIA_DIR=uploads",
    "# The web root, relative to this application folder. Correct for the\n"
        . "# layout this package ships in: public_html beside 99warehousing-app.\n"
        . "# Change it only if you put the two somewhere else.\n"
        . "PUBLIC_DIR=../public_html\n\nMEDIA_DIR=uploads",
    $example
);
$example = str_replace('APP_ENV=development', 'APP_ENV=production', $example);
$example = str_replace(
    'APP_URL=http://localhost:8080',
    'APP_URL=https://99warehousing.com',
    $example
);
$example = str_replace(
    'CORS_ORIGINS=http://localhost:8080',
    'CORS_ORIGINS=https://99warehousing.com',
    $example
);
file_put_contents("$app/config/.env.example", $example);

/* ── The installation card, at the top level ──
   Same instructions as the .txt below, laid out to be read. Both ship: the
   HTML is what you open after unzipping, the .txt is what you can still
   read over SSH or FTP with no browser. */
copy($root . '/package/index.html', $out . '/index.html');
say('  index.html              installation card');

/* ── A note at the top level, for whoever opens the zip ── */
file_put_contents($out . '/READ-ME-FIRST.txt', <<<TXT
99Warehousing — PHP build
=========================

Open index.html (beside this file) for the same instructions,
laid out to be read. This copy is here for when you only have
FTP or a terminal.

Two folders. They go in DIFFERENT places.


1.  public_html/
    Upload the CONTENTS of this folder into your public_html.
    (The contents — not the folder itself. If you end up with
    public_html/public_html, the site will 404.)

2.  99warehousing-app/
    Upload this folder ONE LEVEL ABOVE public_html, so you have:

        /home/youraccount/
        ├── public_html/          ← from step 1
        └── 99warehousing-app/    ← from step 2

    Keeping it outside public_html is what stops anyone fetching
    config/.env — which holds your database password — over the web.


3.  Copy 99warehousing-app/config/.env.example to
    99warehousing-app/config/.env and fill it in. It will not run
    until you do.

4.  Create the database tables. See DEPLOY-TO-GODADDY.md, step 5.


FULL INSTRUCTIONS
    99warehousing-app/DEPLOY-TO-GODADDY.md

BEFORE YOU START
    PHP 7.4 or newer, with pdo_mysql and gd enabled.
    cPanel → Select PHP Version.

TXT
);

/* ── Zip it, if the host has the extension ── */
$zipPath = $dist . '/99warehousing-php-cpanel.zip';
@unlink($zipPath);

if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($out) + 1);
            if ($item->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($item->getPathname(), $relative);
            }
        }
        $zip->close();
        say(sprintf('  %s   %s', basename($zipPath), human((int) filesize($zipPath))));
    }
} else {
    say('  (ZipArchive unavailable — folder built, zip skipped)');
}

say('');
say('Done.');
say('  folder : dist/99warehousing-php/');
say('  zip    : dist/99warehousing-php-cpanel.zip');
say('');
say('The package contains .env.example, never your own config/.env.');

function human(int $bytes): string
{
    return $bytes > 1048576
        ? round($bytes / 1048576, 1) . ' MB'
        : round($bytes / 1024) . ' KB';
}
