/**
 * Audit log — append-only, and only the recent entries are ever read.
 *
 * Rows carry `expiresAt`, a unix timestamp DynamoDB uses to delete them for
 * free once they age out. Without it an audit table grows forever and is
 * eventually the largest thing in the account.
 */
'use strict';
const { makeRepo } = require('./base.repo');
const { TABLES, PARTITION } = require('./tables');
const repo = makeRepo(TABLES.auditlogs);

const RETENTION_DAYS = Number(process.env.AUDIT_RETENTION_DAYS || 365);

module.exports = {
  raw: repo,
  async record(doc) {
    const now = repo.nowIso();
    return repo.put({
      ...doc,
      id: doc.id || repo.newId(),
      entity: PARTITION.AUDIT,
      createdAt: doc.createdAt || now,
      expiresAt: Math.floor(Date.now() / 1000) + RETENTION_DAYS * 86400,
    });
  },
  list: ({ limit = 100 } = {}) =>
    repo.query(PARTITION.AUDIT, { index: 'entity-createdAt-index', descending: true, limit }),
};
