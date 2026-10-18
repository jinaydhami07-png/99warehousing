'use strict';
const User = require('../../models/user.model');
const { toPlain, toPlainList } = require('./shared');
const { USER_SECRETS_SELECT } = require('../sensitive');

const norm = (e) => String(e || '').toLowerCase().trim();

/**
 * The schema marks password, tokenVersion, failedLoginAttempts, lockedUntil
 * and passwordChangedAt `select: false`, so they are absent unless asked for.
 * `secrets: true` is what the authentication paths pass; everything else gets
 * the safe projection, and a password hash cannot reach an API response by
 * accident.
 */
const sel = (q, secrets) => (secrets ? q.select(USER_SECRETS_SELECT) : q);

module.exports = {
  getById: async (id, { secrets } = {}) => toPlain(await sel(User.findById(id), secrets).lean()),
  findByEmail: async (email, { secrets } = {}) =>
    toPlain(await sel(User.findOne({ email: norm(email) }), secrets).lean()),
  findByGoogleId: async (gid, { secrets } = {}) =>
    toPlain(await sel(User.findOne({ googleId: String(gid) }), secrets).lean()),
  findFirstAdmin: async ({ secrets } = {}) =>
    toPlain(await sel(User.findOne({ role: 'admin' }), secrets).lean()),

  create: async (doc) => toPlain(await User.create({ ...doc, email: norm(doc.email) })),

  update: async (id, changes = {}, { remove = [], secrets } = {}) => {
    const set = { ...changes };
    if (set.email) set.email = norm(set.email);
    const update = { $set: set };
    if (remove.length) update.$unset = Object.fromEntries(remove.map((f) => [f, 1]));
    return toPlain(await sel(User.findByIdAndUpdate(id, update, { new: true }), secrets).lean());
  },

  /* These three return the updated row, so they always select the hidden
     fields — a caller that asks to increment a counter and gets back a row
     without it has no way to tell whether the write landed. */
  bumpTokenVersion: async (id) =>
    toPlain(await User.findByIdAndUpdate(id, { $inc: { tokenVersion: 1 } }, { new: true })
      .select(USER_SECRETS_SELECT).lean()),
  recordFailedLogin: async (id, lockedUntil) =>
    toPlain(await User.findByIdAndUpdate(id,
      { $inc: { failedLoginAttempts: 1 }, $set: { lockedUntil } }, { new: true })
      .select(USER_SECRETS_SELECT).lean()),
  clearLoginFailures: async (id) =>
    toPlain(await User.findByIdAndUpdate(id,
      { $set: { failedLoginAttempts: 0, lastLoginAt: new Date() }, $unset: { lockedUntil: 1 } },
      { new: true }).select(USER_SECRETS_SELECT).lean()),

  listAll: async ({ role, limit } = {}) =>
    toPlainList(await User.find(role ? { role } : {}).sort({ createdAt: -1 }).limit(limit || 0).lean()),
  count: () => User.countDocuments(),
};
