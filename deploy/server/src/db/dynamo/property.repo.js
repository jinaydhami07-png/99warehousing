/**
 * Properties.
 *
 * ── How filtering works, and why ──────────────────────────────────────────
 * MongoDB could take an arbitrary filter and sort and resolve it server-side.
 * DynamoDB cannot: it sorts only by a sort key, and it has no case-insensitive
 * match and no free-text search.
 *
 * So listings are read one partition at a time — all rows of a given status
 * from `status-createdAt-index` — and then filtered, sorted and paged in
 * memory. That gives exact parity with the old behaviour, including an honest
 * total count, for one query per request.
 *
 * This is a deliberate choice for this catalogue's size, not an oversight.
 * The whole approved set is a few hundred rows and the front end already
 * downloads all of it to filter in the browser. Reading it server-side costs
 * well under DynamoDB's 1MB page.
 *
 * It stops being the right trade somewhere around 5,000 approved listings
 * (roughly 1MB of JSON). At that point: move the common filters onto their own
 * GSIs, page with LastEvaluatedKey instead of slicing, and put free-text search
 * in OpenSearch rather than scanning strings.
 */
'use strict';

const { makeRepo } = require('./base.repo');
const { TABLES } = require('./tables');

const repo = makeRepo(TABLES.properties);

/* Sort keys mirrored from property.service.js so behaviour is identical. */
const SORTS = {
  'rate-asc': (a, b) => (a.rate || 0) - (b.rate || 0),
  'rate-desc': (a, b) => (b.rate || 0) - (a.rate || 0),
  'area-asc': (a, b) => (a.area || 0) - (b.area || 0),
  'area-desc': (a, b) => (b.area || 0) - (a.area || 0),
  newest: (a, b) => String(b.createdAt || '').localeCompare(String(a.createdAt || '')),
  oldest: (a, b) => String(a.createdAt || '').localeCompare(String(b.createdAt || '')),
};

/**
 * Attributes derived on every write.
 *
 * `cityLower` exists because the old filter matched city case-insensitively
 * and DynamoDB has no such comparison. Normalising once on write is cheaper
 * and more predictable than lower-casing every row on every read.
 *
 * `searchText` is the same idea for free-text: the fields that used to be in
 * the Mongo text index, concatenated and lower-cased, so a search is one
 * substring test per row.
 */
function derived(doc) {
  const text = [doc.name, doc.description, doc.city, doc.locality, doc.type, doc.grade]
    .filter(Boolean).join(' ').toLowerCase();
  return {
    cityLower: doc.city ? String(doc.city).trim().toLowerCase() : undefined,
    searchText: text || undefined,
  };
}

function matches(row, f) {
  if (f.type && row.type !== f.type) return false;
  if (f.grade && row.grade !== f.grade) return false;
  if (f.city && row.cityLower !== String(f.city).trim().toLowerCase()) return false;
  if (f.minRate != null && !(Number(row.rate) >= Number(f.minRate))) return false;
  if (f.maxRate != null && !(Number(row.rate) <= Number(f.maxRate))) return false;
  if (f.minArea != null && !(Number(row.area) >= Number(f.minArea))) return false;
  if (f.maxArea != null && !(Number(row.area) <= Number(f.maxArea))) return false;
  if (f.q) {
    const needle = String(f.q).trim().toLowerCase();
    if (needle && !String(row.searchText || '').includes(needle)) return false;
  }
  return true;
}

module.exports = {
  raw: repo,

  async getById(id) {
    return repo.get(id);
  },

  async create(doc) {
    const now = repo.nowIso();
    const item = {
      ...doc,
      id: doc.id || repo.newId(),
      status: doc.status || 'pending',
      views: doc.views || 0,
      enquiryCount: doc.enquiryCount || 0,
      createdAt: doc.createdAt || now,
      updatedAt: now,
      ...derived(doc),
    };
    return repo.put(item);
  },

  /** Partial update. Derived attributes are recomputed when their source changes. */
  async update(id, changes) {
    const current = await repo.get(id);
    if (!current) return null;
    const merged = { ...current, ...changes };
    return repo.update(id, undefined, {
      set: { ...changes, ...derived(merged), updatedAt: repo.nowIso() },
    });
  },

  async remove(id) {
    return repo.delete(id);
  },

  /**
   * Atomic counter. DynamoDB's ADD is applied server-side, so two concurrent
   * page views cannot overwrite one another — which a read-then-write would
   * allow, and which is exactly what a view counter attracts.
   */
  async increment(id, field, by = 1) {
    return repo.update(id, undefined, { add: { [field]: by } });
  },

  /**
   * One status partition, filtered/sorted/paged in memory. See the note above.
   * Returns the same { items, total } shape the service already expects.
   */
  async listByStatus(status, { filters = {}, sort = 'newest', skip = 0, limit = 24 } = {}) {
    const rows = await repo.query(status, {
      index: 'status-createdAt-index',
      descending: true,
    });
    const filtered = rows.filter((r) => matches(r, filters));
    filtered.sort(SORTS[sort] || SORTS.newest);
    return {
      items: filtered.slice(skip, skip + limit),
      total: filtered.length,
    };
  },

  /** Admin view across every status. */
  async listAll({ filters = {}, sort = 'newest', skip = 0, limit = 24, status } = {}) {
    const statuses = status ? [status] : ['approved', 'pending', 'rejected', 'draft'];
    const batches = await Promise.all(statuses.map((s) =>
      repo.query(s, { index: 'status-createdAt-index', descending: true })));
    const rows = batches.flat().filter((r) => matches(r, filters));
    rows.sort(SORTS[sort] || SORTS.newest);
    return { items: rows.slice(skip, skip + limit), total: rows.length };
  },

  async listByOwner(ownerId, { limit } = {}) {
    return repo.query(ownerId, { index: 'owner-createdAt-index', descending: true, limit });
  },

  async countByStatus(status) {
    const rows = await repo.query(status, { index: 'status-createdAt-index' });
    return rows.length;
  },

  /**
   * Dashboard counters.
   * The old version was one aggregation pipeline; here it is one query per
   * status, run concurrently. Same answer, and no Scan.
   */
  async stats() {
    const statuses = ['approved', 'pending', 'rejected', 'draft'];
    const counts = await Promise.all(statuses.map((s) => this.countByStatus(s)));
    const out = Object.fromEntries(statuses.map((s, i) => [s, counts[i]]));
    out.total = counts.reduce((a, b) => a + b, 0);
    return out;
  },
};
