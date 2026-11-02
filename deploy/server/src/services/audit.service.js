/**
 * Audit service — read side of the audit trail.
 *
 * Writes happen through `db.auditlogs.record()` at the point of the action
 * being audited (see property.service.js). This module only reads, which is
 * why it exposes no create/update/delete: an audit trail you can edit is not
 * an audit trail.
 */
'use strict';

const db = require('../db');

/* A dashboard feed, not a data export. Capped so a UI bug asking for
   limit=100000 can't pull the whole collection into memory. */
const MAX_LIMIT = 200;

/**
 * Most recent entries first.
 *
 * The repository returns plain rows keyed by `id` with ISO timestamps on
 * either driver, so the shaping below is about what the dashboard needs, not
 * about which database answered.
 */
async function list({ limit = 50, entityId, action } = {}) {
  const capped = Math.min(Number(limit) || 50, MAX_LIMIT);

  const items = await db.auditlogs.list({ limit: capped, entityId, action });

  return items.map((entry) => ({
    id: entry.id,
    action: entry.action,
    entity: entry.entity,
    entityId: entry.entityId ? String(entry.entityId) : null,
    actorEmail: entry.actorEmail || null,
    actorRole: entry.actorRole || null,
    before: entry.before ?? null,
    after: entry.after ?? null,
    createdAt: entry.createdAt,
  }));
}

module.exports = { list };
