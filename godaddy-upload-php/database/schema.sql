-- ═══════════════════════════════════════════════════════════════
--  99Warehousing — MySQL / MariaDB schema
--  The PHP port of the Mongoose models in godaddy-upload/server.
--  Apply with:  php bin/install.php
-- ═══════════════════════════════════════════════════════════════
--
-- ── Two decisions worth explaining ────────────────────────────
--
-- 1. IDs are CHAR(24), holding MongoDB-shaped hex ids rather than
--    AUTO_INCREMENT integers.
--
--    The front-end is carried over unchanged from the Node build and is
--    full of 24-character ids — in URLs, in image `publicId` values, in the
--    id check inside the demo store. Integer keys would have meant editing
--    pages this port is not meant to touch. They are also a worse public
--    identifier: a sequential id tells anyone how many listings exist and
--    lets them walk the whole catalogue by counting up.
--
--    Because the layout matches MongoDB's, rows exported from the existing
--    Mongo database import here with their ids intact.
--
-- 2. `specs`, `images`, `floor_plan` and `distances` are JSON columns
--    rather than child tables.
--
--    They are read and written whole, never queried by their inner fields,
--    and their shape is the API's response shape. Four join tables would
--    add four round trips per listing and a reassembly step, to model
--    something nothing ever queries into. Anything the app filters or sorts
--    by — city, rate, area, status — is a real column with a real index.
-- ─────────────────────────────────────────────────────────────

SET NAMES utf8mb4;
SET time_zone = '+00:00';


-- ── Users ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id`                    CHAR(24)      NOT NULL,
  `name`                  VARCHAR(120)  NOT NULL,
  `email`                 VARCHAR(254)  NOT NULL,
  -- NULL for Google accounts, which never set one. Bcrypt, cost 12 —
  -- the same format bcryptjs wrote, so migrated rows sign in unchanged.
  `password`              VARCHAR(255)      NULL,
  `mobile`                VARCHAR(20)       NULL,
  `company`               VARCHAR(160)      NULL,
  -- Google's own CDN URL. Stored as a link rather than downloaded: it
  -- changes when the user changes their photo, and copying it here would
  -- mean holding a stale image plus someone else's likeness.
  `avatar`                VARCHAR(500)      NULL,
  `role`                  ENUM('buyer','owner','agency','admin') NOT NULL DEFAULT 'buyer',
  `auth_provider`         ENUM('local','google')                 NOT NULL DEFAULT 'local',
  `google_id`             VARCHAR(64)       NULL,
  `is_email_verified`     TINYINT(1)    NOT NULL DEFAULT 0,
  `is_active`             TINYINT(1)    NOT NULL DEFAULT 1,

  -- Brute-force state.
  `failed_login_attempts` INT           NOT NULL DEFAULT 0,
  `locked_until`          DATETIME          NULL,
  `last_login_at`         DATETIME          NULL,

  -- Bumped on logout. Every refresh token issued before the bump stops
  -- validating, which is how "log out everywhere" works without keeping a
  -- blacklist of individual tokens.
  `token_version`         INT           NOT NULL DEFAULT 0,
  `password_changed_at`   DATETIME          NULL,

  `created_at`            DATETIME      NOT NULL,
  `updated_at`            DATETIME      NOT NULL,

  PRIMARY KEY (`id`),
  -- The hot path: every sign-in is a lookup by email. Unique so two
  -- accounts cannot claim one address, which is also what makes the
  -- "already registered" check race-proof.
  UNIQUE KEY `uniq_users_email` (`email`),
  UNIQUE KEY `uniq_users_google` (`google_id`),
  -- The admin user list: filter by role, sort by newest. Equality column
  -- first, then the sort column — the order is what makes it usable.
  KEY `idx_users_role_created` (`role`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Properties ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `properties` (
  `id`               CHAR(24)      NOT NULL,
  `name`             VARCHAR(160)  NOT NULL,
  `slug`             VARCHAR(80)       NULL,
  `description`      TEXT              NULL,
  `type`             VARCHAR(40)   NOT NULL DEFAULT 'Warehouse',
  `grade`            VARCHAR(20)   NOT NULL DEFAULT 'Grade B',

  `city`             VARCHAR(80)   NOT NULL,
  `locality`         VARCHAR(120)      NULL,
  -- Full address, pincode and map link are collected on the listing form
  -- and shown only to a signed-in enquirer — never on the public card.
  `address`          VARCHAR(300)      NULL,
  `pincode`          VARCHAR(10)       NULL,
  `maps_url`         VARCHAR(500)      NULL,

  `rate`             DECIMAL(12,2) NOT NULL,
  `area`             DECIMAL(14,2) NOT NULL,
  `deposit_months`   INT           NOT NULL DEFAULT 3,

  -- JSON. See the note at the top of this file.
  `specs`            LONGTEXT          NULL,
  `images`           LONGTEXT          NULL,
  `floor_plan`       LONGTEXT          NULL,
  `distances`        LONGTEXT          NULL,

  `available_from`   DATE              NULL,

  `owner_id`         CHAR(24)      NOT NULL,
  -- Denormalised: a listing outlives its owner's profile edits, and the
  -- public feed should not join to users on every row.
  `owner_name`       VARCHAR(160)      NULL,

  `status`           ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `is_verified`      TINYINT(1)    NOT NULL DEFAULT 0,
  `rejection_reason` VARCHAR(500)      NULL,

  `views`            INT           NOT NULL DEFAULT 0,
  `enquiry_count`    INT           NOT NULL DEFAULT 0,

  `created_at`       DATETIME      NOT NULL,
  `updated_at`       DATETIME      NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_properties_slug` (`slug`),
  -- The public feed's default filter-and-sort path.
  KEY `idx_properties_status_city_rate` (`status`, `city`, `rate`),
  -- The admin approval queue, newest first.
  KEY `idx_properties_status_created` (`status`, `created_at`),
  KEY `idx_properties_owner` (`owner_id`, `created_at`),
  CONSTRAINT `fk_properties_owner` FOREIGN KEY (`owner_id`)
      REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Images ────────────────────────────────────────────────────
-- The bytes live on disk under public/uploads, served by Apache without a
-- PHP process in the way. This table holds only the text: where the file
-- is, how big it is, who uploaded it and which listing it belongs to.
--
-- That split is the point. Storing image bytes in the database — as the
-- MongoDB build did when no S3 bucket was configured — makes every image
-- read a query, and grows the thing you have to back up by a gigabyte a
-- month.
CREATE TABLE IF NOT EXISTS `images` (
  `id`            CHAR(24)      NOT NULL,
  -- 'local' — a file under public/uploads. Room for 's3' later without a
  -- migration; the serve route already branches on this.
  `storage`       VARCHAR(10)   NOT NULL DEFAULT 'local',
  `path`          VARCHAR(300)      NULL,
  `url`           VARCHAR(500)      NULL,
  -- One entry per rendered width, so the pages get a real srcset and a
  -- phone downloads the 320px file rather than the 1920px one.
  `variants`      LONGTEXT          NULL,
  -- A ~20px WebP as a data URI: what the browser paints in the moment
  -- before the real photo arrives, instead of an empty grey box.
  `blur`          TEXT              NULL,
  `content_type`  VARCHAR(40)   NOT NULL,
  `size`          INT           NOT NULL,
  `width`         INT               NULL,
  `height`        INT               NULL,
  `original_name` VARCHAR(260)      NULL,
  `kind`          ENUM('photo','floorplan') NOT NULL DEFAULT 'photo',
  `uploaded_by`   CHAR(24)      NOT NULL,
  -- Set once the image is attached to a listing. Unattached rows are
  -- orphans from an abandoned form and can be swept up later.
  `property_id`   CHAR(24)          NULL,
  `created_at`    DATETIME      NOT NULL,
  `updated_at`    DATETIME      NOT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_images_uploader` (`uploaded_by`, `created_at`),
  KEY `idx_images_property` (`property_id`),
  CONSTRAINT `fk_images_uploader` FOREIGN KEY (`uploaded_by`)
      REFERENCES `users` (`id`) ON DELETE CASCADE,
  -- SET NULL, not CASCADE: the property service deletes the files from
  -- disk itself and needs the rows to still be there to find them.
  CONSTRAINT `fk_images_property` FOREIGN KEY (`property_id`)
      REFERENCES `properties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Favourites ────────────────────────────────────────────────
-- One row per (user, property). A separate table rather than a column on
-- users because a keen buyer's list is unbounded, and "who saved this
-- listing?" is answerable this way and not the other.
CREATE TABLE IF NOT EXISTS `favorites` (
  `id`          CHAR(24) NOT NULL,
  `user_id`     CHAR(24) NOT NULL,
  `property_id` CHAR(24) NOT NULL,
  `created_at`  DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  -- Unique so a double-tap on the heart, or a retried request, cannot
  -- create a second row. The service relies on this: it upserts and treats
  -- the duplicate as success rather than checking first, which would race.
  UNIQUE KEY `uniq_favorites_pair` (`user_id`, `property_id`),
  KEY `idx_favorites_user` (`user_id`, `created_at`),
  CONSTRAINT `fk_favorites_user` FOREIGN KEY (`user_id`)
      REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_favorites_property` FOREIGN KEY (`property_id`)
      REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Reviews ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `reviews` (
  `id`               CHAR(24)      NOT NULL,
  `property_id`      CHAR(24)      NOT NULL,
  `author_id`        CHAR(24)      NOT NULL,
  -- Denormalised at write time: a review outlives the reviewer's profile
  -- edits, and a public feed should not join to users per row.
  `author_name`      VARCHAR(120)  NOT NULL,
  `author_role`      VARCHAR(120)      NULL,
  `rating`           TINYINT       NOT NULL,
  `comment`          VARCHAR(1500) NOT NULL,
  -- The same moderation path a listing takes: nothing a stranger typed
  -- appears on a public page until an admin has looked at it.
  `status`           ENUM('pending','published','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` VARCHAR(500)      NULL,
  `created_at`       DATETIME      NOT NULL,
  `updated_at`       DATETIME      NOT NULL,

  PRIMARY KEY (`id`),
  -- One review per person per property. Editing yours replaces it rather
  -- than stacking a second opinion under the same name.
  UNIQUE KEY `uniq_reviews_author` (`property_id`, `author_id`),
  KEY `idx_reviews_feed` (`property_id`, `status`, `created_at`),
  KEY `idx_reviews_moderation` (`status`, `created_at`),
  CONSTRAINT `fk_reviews_property` FOREIGN KEY (`property_id`)
      REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reviews_author` FOREIGN KEY (`author_id`)
      REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Enquiries ─────────────────────────────────────────────────
-- Stores the contact details typed into the form rather than only linking
-- to a user: most enquiries come from signed-out visitors, and even a
-- signed-in one may want a reply at a different address.
CREATE TABLE IF NOT EXISTS `enquiries` (
  `id`            CHAR(24)      NOT NULL,
  `name`          VARCHAR(120)  NOT NULL,
  `email`         VARCHAR(254)  NOT NULL,
  `mobile`        VARCHAR(20)       NULL,
  `company`       VARCHAR(160)      NULL,
  `subject`       VARCHAR(160)  NOT NULL DEFAULT 'General enquiry',
  `message`       TEXT          NOT NULL,

  -- Set when the enquiry came from a listing page; absent for the general
  -- contact form. The name is denormalised so the admin inbox still reads
  -- correctly if the listing is later deleted.
  `property_id`   CHAR(24)          NULL,
  `property_name` VARCHAR(160)      NULL,
  -- Set only if the sender happened to be signed in. Never required.
  `user_id`       CHAR(24)          NULL,

  `status`        ENUM('new','contacted','closed') NOT NULL DEFAULT 'new',
  `admin_note`    VARCHAR(2000)     NULL,

  -- Captured for abuse triage. Never returned by the API.
  `ip`            VARCHAR(45)       NULL,
  `user_agent`    VARCHAR(500)      NULL,

  `created_at`    DATETIME      NOT NULL,
  `updated_at`    DATETIME      NOT NULL,

  PRIMARY KEY (`id`),
  -- The admin inbox: unhandled first, newest first within that.
  KEY `idx_enquiries_status_created` (`status`, `created_at`),
  KEY `idx_enquiries_user` (`user_id`, `created_at`),
  KEY `idx_enquiries_property` (`property_id`, `created_at`),
  -- SET NULL so deleting a listing does not delete the enquiries it
  -- generated; property_name keeps them readable.
  CONSTRAINT `fk_enquiries_property` FOREIGN KEY (`property_id`)
      REFERENCES `properties` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_enquiries_user` FOREIGN KEY (`user_id`)
      REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Audit log ─────────────────────────────────────────────────
-- Append-only. Exists so moderation decisions are reviewable after the
-- fact: which admin approved a listing, the values before and after, when.
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          CHAR(24)     NOT NULL,
  -- SET NULL rather than CASCADE: the record of what an admin did must
  -- survive that admin's account being deleted, which is exactly the case
  -- an audit trail exists for. actor_email keeps it readable.
  `actor_id`    CHAR(24)         NULL,
  `actor_email` VARCHAR(254)     NULL,
  `actor_role`  VARCHAR(20)      NULL,
  `action`      VARCHAR(60)  NOT NULL,
  `entity`      VARCHAR(30)  NOT NULL DEFAULT 'property',
  `entity_id`   CHAR(24)         NULL,
  `before`      LONGTEXT         NULL,
  `after`       LONGTEXT         NULL,
  `ip`          VARCHAR(45)      NULL,
  `user_agent`  VARCHAR(500)     NULL,
  -- No updated_at: an audit record is never updated, by definition.
  `created_at`  DATETIME     NOT NULL,

  PRIMARY KEY (`id`),
  -- The dashboard reads this newest-first.
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_entity` (`entity_id`),
  KEY `idx_audit_action` (`action`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_id`)
      REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── Rate limiting ─────────────────────────────────────────────
-- PHP has no long-lived process to hold counters in, so they live here.
-- One primary-key upsert per request, which is about as cheap as a query
-- gets. See app/Middleware/RateLimit.php.
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `bucket`   VARCHAR(190) NOT NULL,
  `hits`     INT          NOT NULL DEFAULT 0,
  `reset_at` DATETIME     NOT NULL,

  PRIMARY KEY (`bucket`),
  KEY `idx_rate_reset` (`reset_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
