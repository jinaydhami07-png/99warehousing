<?php
/**
 * Database connection — one PDO instance per request.
 *
 * The counterpart of server/src/config/database.js, minus the pooling: PHP
 * has no long-lived process to pool in. Each request opens a connection,
 * uses it and drops it when the script ends. That is why every query in this
 * app is a prepared statement against an already-open handle rather than
 * anything that reconnects mid-request.
 */
declare(strict_types=1);

namespace App\Config;

use App\Http\ApiError;
use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = Env::get('db');

        /* A UNIX socket beats TCP where one is available — it skips the
           network stack entirely, and several cPanel hosts only allow the
           socket path. Falls back to host:port when unset. */
        $dsn = $cfg['socket']
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $cfg['socket'], $cfg['name'], $cfg['charset'])
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']);

        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
                /* Exceptions, not silent false returns. A failed write that
                   reports success is the worst outcome available here. */
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                /* Real prepared statements, not the driver emulating them by
                   interpolating strings. Emulation is what turns a bound
                   integer into a quoted string and, more importantly, is the
                   path where a badly-escaped multibyte value can break out
                   of its quotes. */
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $e) {
            /* The message carries the database host and sometimes the user,
               so it goes to the log, never to the client. */
            error_log('[db] connection failed: ' . $e->getMessage());
            throw new ApiError(503, 'The database is unavailable. Please try again shortly.', null, false);
        }

        /* STRICT_ALL_TABLES makes MySQL reject an over-long or wrong-typed
           value instead of silently truncating it — the database equivalent
           of the schema validation the Mongoose models did. Without it a
           161-character title is stored as 160 characters and nothing says
           so. Applied per-connection so the app behaves the same on a shared
           host whose global sql_mode we do not control. */
        self::$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        /* Every timestamp in this schema is UTC. Setting it per session means
           a server in Asia/Kolkata and one in UTC write identical rows. */
        self::$pdo->exec("SET SESSION time_zone = '+00:00'");

        return self::$pdo;
    }

    /** Readiness, for GET /api/v1/health. Never throws — that is the point. */
    public static function health(): array
    {
        try {
            self::connection()->query('SELECT 1');
            return ['status' => 'connected', 'ready' => true];
        } catch (\Throwable $e) {
            return ['status' => 'disconnected', 'ready' => false];
        }
    }

    /* ── Thin query helpers ──
       Not an ORM and not trying to be. They exist so no call site has to
       repeat prepare/execute/fetch, and so every query in the app goes
       through bound parameters — the one rule that matters. */

    /** @param array<string,mixed> $params */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @return array<string,mixed>|null */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** First column of the first row — for COUNT(*) and friends. */
    public static function scalar(string $sql, array $params = [])
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * INSERT built from a column => value map.
     *
     * Column names come from code, never from request data — the callers all
     * pass literal arrays — so they are safe to interpolate. Values are
     * always bound.
     *
     * @param array<string,mixed> $data
     */
    public static function insert(string $table, array $data): void
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn($c) => "`$c`", $columns)),
            implode(', ', array_map(static fn($c) => ":$c", $columns))
        );
        self::run($sql, $data);
    }

    /**
     * UPDATE ... WHERE id = :id from a column => value map.
     *
     * @param array<string,mixed> $data
     */
    public static function update(string $table, string $id, array $data): void
    {
        if (!$data) {
            return;
        }
        $sets = implode(', ', array_map(static fn($c) => "`$c` = :$c", array_keys($data)));
        $data['__id'] = $id;
        self::run("UPDATE `$table` SET $sets WHERE `id` = :__id", $data);
    }

    /**
     * A case-insensitive "any of these columns contains this text" clause.
     *
     * ── Why each column needs its own placeholder ─────────────────────
     * With PDO::ATTR_EMULATE_PREPARES off — which this connection sets, so
     * that statements are prepared by MySQL rather than assembled by string
     * interpolation — a named parameter may be bound exactly ONCE. Writing
     * `name LIKE :q OR city LIKE :q` fails the whole statement with
     * "Invalid parameter number", and it fails at runtime on the search
     * path rather than at boot, so it is the kind of thing that ships.
     * ─────────────────────────────────────────────────────────────────
     *
     * @param array<int,string>   $columns Literal column names, from code.
     * @param array<string,mixed> $params  Bound values, added to in place.
     */
    public static function likeClause(array $columns, string $prefix, string $value, array &$params): string
    {
        $parts = [];
        foreach (array_values($columns) as $i => $column) {
            $name = $prefix . $i;
            $parts[] = "`$column` LIKE :$name";
            $params[$name] = '%' . self::escapeLike($value) . '%';
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * Neutralise the LIKE metacharacters in user-supplied search text.
     *
     * Without this, searching for "100%" matches every row and "a_b"
     * matches "axb" — surprising rather than dangerous, but wrong.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    public static function transaction(callable $fn)
    {
        $pdo = self::connection();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
