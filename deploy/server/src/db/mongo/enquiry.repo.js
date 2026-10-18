'use strict';
const Enquiry = require('../../models/enquiry.model');
const { toPlain, toPlainList } = require('./shared');

module.exports = {
  getById: async (id) => toPlain(await Enquiry.findById(id).lean()),
  create: async (doc) => toPlain(await Enquiry.create(doc)),
  update: async (id, changes) =>
    toPlain(await Enquiry.findByIdAndUpdate(id, { $set: changes }, { new: true }).lean()),
  listForUser: async (userId) =>
    toPlainList(await Enquiry.find({ user: userId }).sort({ createdAt: -1 }).lean()),
  listForProperty: async (propertyId) =>
    toPlainList(await Enquiry.find({ property: propertyId }).sort({ createdAt: -1 }).lean()),
  listAll: async ({ limit } = {}) =>
    toPlainList(await Enquiry.find({}).sort({ createdAt: -1 }).limit(limit || 0).lean()),
  count: () => Enquiry.countDocuments(),
};
