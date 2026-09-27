<?php
/**
 * Structured logging to a file, mirroring what pino did in the Node app.
 *
 * One JSON object per line, so the log is greppable and machine-readable —
 * on a cPanel box the alternative is an unparseable mix of PHP warnings and
 * application messages in the same error_log.
 */
declare(strict_types=1);

namespace App\Config;

final class Logger
{
    private const LEVELS = ['trace' => 10, 'debug' => 20, 'info' => 30, 'warn' => 40, 'error' => 50, 'fatal' => 60];

    private static ?string $requestId = null;

    public static function setRequestId(string $id): void
    {
        self::$requestId = $id;
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::write('warn', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $configured = self::LEVELS[(string) Env::get('logLevel', 'info')] ?? 30;
        if ((self::LEVELS[$level] ?? 30) < $configured) {
            return;
        }

        $line = json_encode(array_filter([
            'time' => gmdate('c'),
            'level' => $level,
            'msg' => $message,
            'reqId' => self::$requestId,
            'ctx' => $context ?: null,
        ], static fn($v) => $v !== null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $file = (string) Env::get('logFile');
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        /* FILE_APPEND + LOCK_EX so two concurrent PHP processes cannot
           interleave half-lines into the same file. */
        if (@file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            // Read-only filesystem or a full disk. Losing a log line must
            // never fail the request that produced it.
            error_log($line);
        }
    }
}
