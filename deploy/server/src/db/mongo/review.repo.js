'use strict';
const Review = require('../../models/review.model');
const { toPlain, toPlainList } = require('./shared');

module.exports = {
  get: async (propertyId, authorId) =>
    toPlain(await Review.findOne({ property: propertyId, author: authorId }).lean()),
  getById: async (id) => toPlain(await Review.findById(id).lean()),

  async upsert(doc) {
    const existing = await Review.findOne({ property: doc.property, author: doc.author });
    if (existing) {
      return toPlain(await Review.findByIdAndUpdate(existing._id, { $set: doc }, { new: true }).lean());
    }
    return toPlain(await Review.create(doc));
  },

  listForProperty: async (propertyId, { status } = {}) =>
    toPlainList(await Review.find({ property: propertyId, ...(status ? { status } : {}) }).lean()),
  listByStatus: async (status, { limit } = {}) =>
    toPlainList(await Review.find({ status }).sort({ createdAt: -1 }).limit(limit || 0).lean()),
  moderate: async (id, status) =>
    toPlain(await Review.findByIdAndUpdate(id, { $set: { status } }, { new: true }).lean()),
  removeById: async (id) => Boolean(await Review.findByIdAndDelete(id)),

  async ratingFor(propertyId) {
    const rows = await Review.find({ property: propertyId, status: 'published' }).lean();
    if (!rows.length) return { average: null, count: 0 };
    const sum = rows.reduce((a, r) => a + Number(r.rating || 0), 0);
    return { average: Math.round((sum / rows.length) * 10) / 10, count: rows.length };
  },
};
