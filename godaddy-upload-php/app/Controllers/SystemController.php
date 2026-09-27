<?php
/**
 * Health, configuration and the audit log.
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Config\Env;
use App\Http\Request;
use App\Http\Response;
use App\Services\AuditService;

final class SystemController
{
    /**
     * Liveness and readiness in one endpoint.
     *
     * 503 when the database is unreachable, so a load balancer or uptime
     * monitor stops sending traffic here instead of watching users get
     * errors. api.js also probes this to decide whether a real backend
     * exists at this origin, which is why it must always answer in JSON.
     */
    public static function health(Request $request): void
    {
        $db = Database::health();

        Response::json([
            'success' => $db['ready'],
            'message' => $db['ready'] ? 'OK' : 'Degraded — database unavailable',
            'data' => [
                'timestamp' => gmdate('c'),
                'database' => $db['status'],
                'media' => 'local',
                'php' => PHP_VERSION,
                'memoryMB' => (int) round(memory_get_usage(true) / 1024 / 1024),
            ],
        ], $db['ready'] ? 200 : 503);
    }

    /**
     * GET /api/v1/health/media — can uploads actually be written?
     *
     * Its own endpoint because it touches the filesystem, which the main
     * health check is polled far too often to afford.
     *
     * In the Node build this checked that the S3 bucket was reachable. The
     * store is local disk here, so the equivalent question is whether the
     * upload directory exists and is writable — the same failure, which is
     * otherwise only discovered by a user whose photo upload fails.
     *
     * Deliberately NOT part of the ready/not-ready decision: a full disk
     * degrades uploads, but every text page on the site still works, and
     * taking the instance out of the load balancer over it would be the
     * larger outage.
     */
    public static function healthMedia(Request $request): void
    {
        $dir = Env::get('publicDir') . '/' . trim((string) Env::get('media.dir', 'uploads'), '/');

        $exists = is_dir($dir);
        /* is_writable() can disagree with reality under some ACL and
           open_basedir setups, so this actually writes a file and removes
           it — the only answer that means anything. */
        $writable = false;
        if ($exists) {
            $probe = $dir . '/.write-probe-' . bin2hex(random_bytes(4));
            $writable = @file_put_contents($probe, 'ok') !== false;
            @unlink($probe);
        }

        $free = $exists ? @disk_free_space($dir) : false;

        $ok = $exists && $writable;

        Response::success([
            'driver' => 'local',
            'ok' => $ok,
            /* The configured name only — never the absolute path. This
               endpoint is public, and the server's filesystem layout is not
               something to hand out. "uploads is not writable" is the whole
               of what an operator needs; where it lives they already know. */
            'directory' => trim((string) Env::get('media.dir', 'uploads'), '/'),
            'exists' => $exists,
            'writable' => $writable,
            'freeMB' => $free === false ? null : (int) round($free / 1024 / 1024),
            'webp' => function_exists('imagewebp'),
            'gd' => extension_loaded('gd'),
            'reason' => $ok ? null : (!$exists ? 'Upload directory does not exist' : 'Upload directory is not writable'),
        ], $ok ? 'Media storage is writable' : 'Media storage is not writable');
    }

    /**
     * The non-secret settings the browser legitimately needs, plus which
     * optional integrations are configured — so the UI can explain what is
     * unavailable instead of failing with a confusing 404.
     *
     * Only booleans. No secret, ever.
     */
    public static function config(Request $request): void
    {
        Response::success([
            'features' => [
                'googleAuth' => (bool) Env::get('google.enabled'),
                'adminAccess' => (bool) Env::get('adminPasskey'),
                'uploads' => true,
            ],
        ], 'Config retrieved');
    }

    public static function audit(Request $request): void
    {
        $query = $request->query();

        Response::success([
            'items' => AuditService::list([
                'limit' => $query['limit'] ?? 50,
                'entityId' => $query['entityId'] ?? null,
                'action' => $query['action'] ?? null,
            ]),
        ], 'Audit log retrieved');
    }
}
