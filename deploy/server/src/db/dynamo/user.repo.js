/** Users: sign-in by email, Google OAuth by subject id, and the staff lookup. */
'use strict';
const { makeRepo } = require('./base.repo');
const { TABLES } = require('./tables');
const repo = makeRepo(TABLES.users);

const norm = (e) => String(e || '').toLowerCase().trim();

module.exports = {
  raw: repo,

  getById: (id) => repo.get(id),

  /**
   * Sign-in lookup.
   * Email is stored already normalised so the index is matched exactly —
   * DynamoDB has no case-insensitive comparison, and normalising on read
   * would mean scanning the table on every login attempt.
   */
  async findByEmail(email) {
    const rows = await repo.query(norm(email), { index: 'email-index', limit: 1 });
    return rows[0] || null;
  },

  async findByGoogleId(googleId) {
    const rows = await repo.query(String(googleId), { index: 'googleId-index', limit: 1 });
    return rows[0] || null;
  },

  async findFirstAdmin() {
    const rows = await repo.query('admin', { index: 'role-createdAt-index', limit: 1 });
    return rows[0] || null;
  },

  async create(doc) {
    const now = repo.nowIso();
    return repo.put({
      ...doc,
      id: doc.id || repo.newId(),
      email: norm(doc.email),
      role: doc.role || 'buyer',
      tokenVersion: doc.tokenVersion || 0,
      failedLoginAttempts: doc.failedLoginAttempts || 0,
      createdAt: doc.createdAt || now,
      updatedAt: now,
    });
  },

  async update(id, changes = {}, { remove = [] } = {}) {
    const set = { ...changes, updatedAt: repo.nowIso() };
    if (set.email) set.email = norm(set.email);
    return repo.update(id, undefined, { set, remove });
  },

  /**
   * Invalidates every issued refresh token for this account by bumping the
   * version the tokens are signed against. ADD is atomic, so two sign-outs
   * racing each other still both take effect.
   */
  bumpTokenVersion: (id) => repo.update(id, undefined, { add: { tokenVersion: 1 } }),

  recordFailedLogin: (id, lockedUntil) =>
    repo.update(id, undefined, { add: { failedLoginAttempts: 1 }, set: { lockedUntil } }),

  clearLoginFailures: (id) =>
    repo.update(id, undefined, { set: { failedLoginAttempts: 0, lastLoginAt: repo.nowIso() }, remove: ['lockedUntil'] }),

  /** Admin user list. A Scan is honest here: there is no partition that means
      "every user", and the table is small. */
  async listAll({ role, limit } = {}) {
    if (role) return repo.query(role, { index: 'role-createdAt-index', descending: true, limit });
    return repo.scan({ limit });
  },

  count: () => repo.count(),
};
