/**
 * Enquiries.
 *
 * The admin inbox needs every enquiry in date order, which has no natural
 * partition. Each row carries the literal string "ENQUIRY" in `entity` so one
 * Query returns them all, already sorted, instead of a Scan that reads the
 * table and sorts in memory.
 *
 * The cost is that all writes land on one partition. DynamoDB allows 1,000
 * writes/second to a single partition, which is far beyond this inbox. If it
 * ever approaches that, shard the value — "ENQUIRY#2026-10" — and query the
 * months you need.
 */
'use strict';
const { makeRepo } = require('./base.repo');
const { TABLES, PARTITION } = require('./tables');
const repo = makeRepo(TABLES.enquiries);

module.exports = {
  raw: repo,
  getById: (id) => repo.get(id),

  async create(doc) {
    const now = repo.nowIso();
    return repo.put({
      ...doc,
      id: doc.id || repo.newId(),
      entity: PARTITION.ENQUIRY,
      status: doc.status || 'new',
      createdAt: doc.createdAt || now,
      updatedAt: now,
    });
  },

  update: (id, changes) => repo.update(id, undefined, { set: { ...changes, updatedAt: repo.nowIso() } }),
  listForUser: (userId) => repo.query(String(userId), { index: 'user-createdAt-index', descending: true }),
  listForProperty: (propertyId) => repo.query(String(propertyId), { index: 'property-createdAt-index', descending: true }),
  listAll: ({ limit } = {}) => repo.query(PARTITION.ENQUIRY, { index: 'entity-createdAt-index', descending: true, limit }),
  async count() { return (await repo.query(PARTITION.ENQUIRY, { index: 'entity-createdAt-index' })).length; },
};
