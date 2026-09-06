<?php
/**
 * MongoDB-shaped identifiers, generated in PHP.
 *
 * ── Why not AUTO_INCREMENT ────────────────────────────────────────────
 * The front-end is unchanged from the Node build, and it is full of
 * 24-hex-character ids: /properties/:id in URLs, `publicId` on every image
 * descriptor, the id regex in the demo store. An integer key would have
 * meant editing pages this port is explicitly not allowed to touch.
 *
 * A sequential integer is also a worse public identifier: it leaks how many
 * listings exist and lets anyone walk the whole catalogue by counting up.
 *
 * The layout is MongoDB's, so ids from an existing Mongo database import
 * into these tables unchanged:
 *     4 bytes  seconds since the epoch  (roughly sortable by creation)
 *     5 bytes  per-process random value
 *     3 bytes  counter, starting at a random point
 * ─────────────────────────────────────────────────────────────────────
 */
declare(strict_types=1);

namespace App\Support;

final class ObjectId
{
    /** Fixed for the life of the process, exactly as the Mongo drivers do. */
    private static ?string $machine = null;
    private static ?int $counter = null;

    public static function generate(): string
    {
        if (self::$machine === null) {
            self::$machine = random_bytes(5);
            self::$counter = random_int(0, 0xFFFFFF);
        }

        self::$counter = (self::$counter + 1) & 0xFFFFFF;

        return bin2hex(pack('N', time()) . self::$machine)
            . substr(sprintf('%06x', self::$counter), -6);
    }

    /** The shape every :id route parameter is checked against. */
    public static function isValid($value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-fA-F]{24}$/', $value) === 1;
    }

    /** Creation time, recovered from the leading four bytes. */
    public static function timestamp(string $id): ?int
    {
        if (!self::isValid($id)) {
            return null;
        }
        return (int) hexdec(substr($id, 0, 8));
    }
}
