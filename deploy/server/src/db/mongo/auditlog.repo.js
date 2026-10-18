'use strict';
const AuditLog = require('../../models/auditlog.model');
const { toPlain, toPlainList } = require('./shared');

module.exports = {
  record: async (doc) => toPlain(await AuditLog.create(doc)),
  list: async ({ limit = 100 } = {}) =>
    toPlainList(await AuditLog.find({}).sort({ createdAt: -1 }).limit(limit).lean()),
};
