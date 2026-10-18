/** Users: sign-in by email, Google OAuth by subject id, and the staff lookup. */
'use strict';
const { makeRepo } = require('./base.repo');
const { TABLES } = require('./tables');
const { stripSecrets } = require('../sensitive');
const repo = makeRepo(TABLES.users);

/**
 * DynamoDB returns an item whole — it has no equivalent of Mongo's
 * `select: false`. Without stripping here, a password hash and the session
 * counters would travel wherever a user object goes, including API
 * responses. `secrets: true` is what the authentication paths pass.
 */
const view = (row, secrets) => (secrets ? row : stripSecrets(row));

const norm = (e) => String(e || '').toLowerCase().trim();

module.exports = {
  raw: repo,

  getById: async (id, { secrets } = {}) => view(await repo.get(id), secrets),

  /**
   * Sign-in lookup.
   * Email is stored already normalised so the index is matched exactly —
   * DynamoDB has no case-insensitive comparison, and normalising on read
   * would mean scanning the table on every login attempt.
   */
  async findByEmail(email, { secrets } = {}) {
    const rows = await repo.query(norm(email), { index: 'email-index', limit: 1 });
    return view(rows[0] || null, secrets);
  },

  async findByGoogleId(googleId, { secrets } = {}) {
    const rows = await repo.query(String(googleId), { index: 'googleId-index', limit: 1 });
    return view(rows[0] || null, secrets);
  },

  async findFirstAdmin({ secrets } = {}) {
    const rows = await repo.query('admin', { index: 'role-createdAt-index', limit: 1 });
    return view(rows[0] || null, secrets);
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

  async update(id, changes = {}, { remove = [], secrets } = {}) {
    const set = { ...changes, updatedAt: repo.nowIso() };
    if (set.email) set.email = norm(set.email);
    return view(await repo.update(id, undefined, { set, remove }), secrets);
  },

  /**
   * Invalidates every issued refresh token for this account by bumping the
   * version the tokens are signed against. ADD is atomic, so two sign-outs
   * racing each other still both take effect.
   */
  bumpTokenVersion: (id) => repo.update(id, undefined, { add: { tokenVersion: 1 } }),   // returns secrets by design

  recordFailedLogin: (id, lockedUntil) =>
    repo.update(id, undefined, { add: { failedLoginAttempts: 1 }, set: { lockedUntil } }),

  clearLoginFailures: (id) =>
    repo.update(id, undefined, { set: { failedLoginAttempts: 0, lastLoginAt: repo.nowIso() }, remove: ['lockedUntil'] }),

  /** Admin user list. A Scan is honest here: there is no partition that means
      "every user", and the table is small. */
  async listAll({ role, limit } = {}) {
    const rows = role
      ? await repo.query(role, { index: 'role-createdAt-index', descending: true, limit })
      : await repo.scan({ limit });
    return rows.map((r) => stripSecrets(r));
  },

  count: () => repo.count(),
};
