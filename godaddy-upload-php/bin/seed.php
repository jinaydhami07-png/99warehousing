<?php
/**
 * Seed the sample catalogue.
 *
 *     php bin/seed.php          add anything missing — safe to re-run
 *     php bin/seed.php --wipe   delete all listings first, then seed
 *
 * Matches on the listing name, so re-running never duplicates rows. Creates
 * a system "seed owner" account if one does not exist, since every listing
 * needs an owner.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Config\Database;
use App\Models\Property;
use App\Models\User;

$say = static function (string $line = ''): void {
    echo $line . PHP_EOL;
};

const SEED_OWNER_EMAIL = 'seed-owner@99warehousing.local';

$wipe = in_array('--wipe', $argv ?? [], true);

$say('99Warehousing — seed sample data');
$say(str_repeat('─', 40));

try {
    Database::connection();

    if ($wipe) {
        /* Images first: the rows carry the paths to the files on disk, and
           deleting the listings would cascade them away before anything
           could clean up. */
        $properties = Database::all('SELECT id FROM properties');
        foreach ($properties as $property) {
            \App\Services\ImageService::removeForProperty((string) $property['id']);
        }
        Database::run('DELETE FROM properties');
        $say(sprintf('Wiped %d existing listing(s).', count($properties)));
        $say('');
    }

    $owner = User::findByEmail(SEED_OWNER_EMAIL);
    if ($owner === null) {
        $owner = User::create([
            'name' => '99Warehousing Seed Data',
            'email' => SEED_OWNER_EMAIL,
            /* A random password nobody holds. This account exists to own
               sample rows, not to be signed into — a known password here
               would be a real credential on a live site. */
            'password' => bin2hex(random_bytes(24)),
            'role' => 'owner',
            'isEmailVerified' => true,
        ]);
        $say('Created the seed owner account.');
    }

    $listings = require APP_ROOT . '/database/seed-data.php';

    $added = 0;
    $skipped = 0;

    foreach ($listings as $listing) {
        $exists = Database::first('SELECT id FROM properties WHERE name = :name', ['name' => $listing['name']]);
        if ($exists !== null) {
            $skipped++;
            continue;
        }

        $status = $listing['status'];
        $row = Property::create($listing, (string) $owner['id']);

        /* Property::create() takes status from the payload, but the
           rejection reason and the verified flag are decided by the
           moderation path rather than by a create — set them here so the
           seeded rows look like rows that went through it. */
        Database::update('properties', (string) $row['id'], [
            'status' => $status,
            'is_verified' => $status === 'approved' ? 1 : 0,
            'rejection_reason' => $listing['rejectionReason'] ?? null,
            'owner_name' => $owner['name'],
        ]);

        $added++;
        $say(sprintf('  + %-46s %s', mb_strimwidth($listing['name'], 0, 46, '…'), $status));
    }

    $say('');
    $say(sprintf('Done — %d added, %d already present.', $added, $skipped));

    $counts = Database::all('SELECT status, COUNT(*) AS n FROM properties GROUP BY status ORDER BY status');
    $say('');
    $say('Catalogue now holds:');
    foreach ($counts as $count) {
        $say(sprintf('  %-10s %d', $count['status'], $count['n']));
    }
} catch (Throwable $e) {
    $say('');
    $say('✖ Seed failed: ' . $e->getMessage());
    exit(1);
}
