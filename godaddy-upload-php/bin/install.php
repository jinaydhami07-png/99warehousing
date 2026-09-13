<?php
/**
 * Create the database tables.
 *
 *     php bin/install.php
 *
 * Safe to run more than once: every statement in database/schema.sql is
 * CREATE TABLE IF NOT EXISTS, so this creates what is missing and leaves
 * what is there alone. It never drops anything.
 *
 * On a cPanel account without SSH, run it once through the browser at
 * /install.php after copying it into public_html — then DELETE it. Leaving
 * a script that can touch the schema reachable from the internet is not
 * something to do twice.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Config\Database;
use App\Config\Env;

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$say = static function (string $line = ''): void {
    echo $line . PHP_EOL;
};

$say('99Warehousing — database install');
$say(str_repeat('─', 40));

try {
    $db = Env::get('db');
    $say(sprintf('Database : %s@%s', $db['name'], $db['socket'] ?: $db['host'] . ':' . $db['port']));

    $pdo = Database::connection();
    $say('Connected.');
    $say('');

    $sql = file_get_contents(APP_ROOT . '/database/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('database/schema.sql is missing');
    }

    /* Split on semicolons at the end of a line. The schema has no stored
       procedures or triggers, so there are no embedded semicolons to worry
       about, and a real SQL parser for a file this app ships would be
       ceremony.

       The leading comment block has to be stripped from each statement
       rather than used to skip it: every CREATE TABLE here is preceded by
       one, so a "does this chunk start with --?" test would discard the
       whole schema and report success. */
    $statements = [];
    foreach (preg_split('/;\s*$/m', $sql) ?: [] as $chunk) {
        $lines = array_filter(
            array_map('rtrim', explode("\n", $chunk)),
            static fn(string $line) => trim($line) !== '' && !str_starts_with(trim($line), '--')
        );
        $statement = trim(implode("\n", $lines));
        if ($statement !== '') {
            $statements[] = $statement;
        }
    }

    /* Which tables exist now, so the report can say what this run actually
       did rather than guessing from a warning count. */
    $before = self_tables($pdo, $db['name']);

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    $after = self_tables($pdo, $db['name']);
    $created = 0;

    foreach ($after as $table) {
        $isNew = !in_array($table, $before, true);
        $created += $isNew ? 1 : 0;
        $say(sprintf('  %-14s %s', $table, $isNew ? 'created' : 'already present'));
    }

    $say('');
    $say($created > 0 ? "Done — $created table(s) created." : 'Done — schema already up to date.');
    $say('');
    $say('Next:  php bin/seed.php     (optional: a demo admin and sample listings)');
} catch (Throwable $e) {
    $say('');
    $say('✖ Install failed: ' . $e->getMessage());
    $say('');
    $say('Check DB_HOST, DB_NAME, DB_USER and DB_PASSWORD in config/.env,');
    $say('and that the database user has been granted privileges on the database.');
    exit(1);
}

/**
 * The tables currently in the schema.
 *
 * @return array<int,string>
 */
function self_tables(PDO $pdo, string $database): array
{
    $stmt = $pdo->prepare(
        'SELECT table_name FROM information_schema.tables
          WHERE table_schema = :db ORDER BY table_name'
    );
    $stmt->execute(['db' => $database]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
