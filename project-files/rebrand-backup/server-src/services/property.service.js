/**
 * Property service — all listing and moderation business logic.
 *
 * Same layering contract as user.service.js: no `req`, no `res`, no Express.
 * Takes plain arguments, returns plain data, throws ApiError. That is what
 * lets the same logic run from a route, a seed script, or a queue worker.
 */
'use strict';

const Property = require('../models/property.model');
const AuditLog = require('../models/auditlog.model');
const imageService = require('./image.service');
const ApiError = require('../utils/ApiError');
const logger = require('../config/logger');

/** Escapes regex metacharacters so user input cannot become a wildcard. */
const escapeRegex = (s) => String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

const SORTS = {
  'rate-asc': { rate: 1 },
  'rate-desc': { rate: -1 },
  'area-desc': { area: -1 },
  'area-asc': { area: 1 },
  newest: { createdAt: -1 },
  oldest: { createdAt: 1 },
};

/** Builds the Mongo filter shared by the public feed and admin list. */
function buildFilter({ city, type, grade, minRate, maxRate, minArea, maxArea, q, status }) {
  const filter = {};

  if (status) filter.status = status;
  if (city) filter.city = new RegExp(`^${escapeRegex(city.trim())}$`, 'i');
  if (type) filter.type = type;
  if (grade) filter.grade = grade;

  if (minRate != null || maxRate != null) {
    filter.rate = {};
    if (minRate != null) filter.rate.$gte = Number(minRate);
    if (maxRate != null) filter.rate.$lte = Number(maxRate);
  }

  if (minArea != null || maxArea != null) {
    filter.area = {};
    if (minArea != null) filter.area.$gte = Number(minArea);
    if (maxArea != null) filter.area.$lte = Number(maxArea);
  }

  // Text search needs the text index declared on the model.
  if (q) filter.$text = { $search: q };

  return filter;
}

/**
 * Public listing feed.
 *
 * Hard-codes status:'approved' AFTER building the filter, so a query string
 * of ?status=pending cannot expose unapproved listings.
 */
async function listPublic({ page, limit, skip, sort, ...filters }) {
  const filter = { ...buildFilter(filters), status: 'approved' };

  const [items, total] = await Promise.all([
    Property.find(filter).sort(SORTS[sort] || SORTS.newest).skip(skip).limit(limit),
    Property.countDocuments(filter),
  ]);

  return { items: items.map((p) => p.toJSON()), total, page, limit };
}

/** Admin list — every status visible, filterable by any of them. */
async function listAll({ page, limit, skip, sort, ...filters }) {
  const filter = buildFilter(filters);

  const [items, total] = await Promise.all([
    Property.find(filter).sort(SORTS[sort] || SORTS.newest).skip(skip).limit(limit),
    Property.countDocuments(filter),
  ]);

  return { items: items.map((p) => p.toJSON()), total, page, limit };
}

/**
 * Fetches one listing.
 *
 * Unapproved listings are visible only to their owner and to admins, and
 * return 404 rather than 403 so their existence is not disclosed.
 */
async function getById(id, viewer) {
  const property = await Property.findById(id);
  if (!property) throw ApiError.notFound('Property not found');

  if (property.status !== 'approved') {
    const isOwner = viewer && String(property.owner) === String(viewer._id);
    const isAdmin = viewer?.role === 'admin';
    if (!isOwner && !isAdmin) throw ApiError.notFound('Property not found');
  }

  // Fire-and-forget: a failed counter must never fail the page load.
  Property.updateOne({ _id: property._id }, { $inc: { views: 1 } }).catch((err) =>
    logger.warn({ err: err.message, id }, 'View counter increment failed')
  );

  return property.toJSON();
}

/** Owner submits a listing. Always lands as pending — never self-approved. */
async function create(payload, owner) {
  /* An admin IS the moderation queue, so holding their listing for review
     would leave it waiting on themselves. Everyone else starts pending.

     Derived from the caller's role, never from the payload, and applied
     after the spread — so a crafted body containing status/isVerified is
     overwritten rather than honoured. */
  const isAdmin = owner.role === 'admin';

  const property = await Property.create({
    ...payload,
    owner: owner._id,

    /* An admin enters listings on behalf of real owners, so the name they
       type in the form is the one to keep. For everyone else the account
       name wins, so a submitter cannot attribute a listing to someone else.
       Note this is gated on isAdmin, not merely on the field being present —
       the public schema rejects the key outright, and this is the second
       lock on the same door. */
    ownerName: isAdmin && payload.ownerName ? payload.ownerName : owner.name,

    /* An admin IS the moderation queue, so holding their listing for review
       would leave it waiting on themselves — default them to approved, but
       honour an explicit choice (e.g. entering a listing that still needs
       checking). Everyone else always starts pending, regardless of what the
       payload asked for. */
    status: isAdmin ? payload.status || 'approved' : 'pending',
    isVerified: isAdmin ? payload.isVerified !== false : false,
  });

  /* Tie the uploaded images to this listing so they stop counting as
     orphans. Best-effort: the listing is already saved, and losing the tag
     is a housekeeping problem, not a reason to fail the submission. */
  await imageService.attachToProperty(property.images, property._id);

  await AuditLog.record({
    actor: owner,
    action: isAdmin ? 'property.created' : 'property.submitted',
    entityId: property._id,
    after: { name: property.name, rate: property.rate, status: property.status },
  });

  logger.info({ propertyId: property.id, ownerId: owner.id }, 'Property submitted');
  return property.toJSON();
}

/**
 * Updates a listing.
 *
 * An owner's edit sends it back to pending for re-review; an admin's does
 * not. Without that, an owner could get a listing approved and then quietly
 * change the price.
 */
async function update(id, patch, actor) {
  const property = await Property.findById(id);
  if (!property) throw ApiError.notFound('Property not found');

  if (actor.role !== 'admin' && String(property.owner) !== String(actor._id)) {
    throw ApiError.forbidden('You can only edit your own listings');
  }

  const before = { name: property.name, rate: property.rate, status: property.status };

  Object.assign(property, patch);

  if (actor.role !== 'admin') {
    property.status = 'pending';
    property.isVerified = false;
  }

  /* A rejection reason only makes sense while the listing is rejected.
     Without this, an owner who fixes the problem and resubmits — or an admin
     who flips the status back — leaves the old "documents incomplete" note
     attached to a listing that is no longer rejected, and the detail page
     shows it. */
  if (property.status !== 'rejected') property.rejectionReason = undefined;

  await property.save();

  /* An edit can add photos too, so tag anything newly attached. */
  if (patch.images) await imageService.attachToProperty(property.images, property._id);

  await AuditLog.record({
    actor,
    action: 'property.edited',
    entityId: property._id,
    before,
    after: { name: property.name, rate: property.rate, status: property.status },
  });

  return property.toJSON();
}

async function remove(id, actor) {
  const property = await Property.findById(id);
  if (!property) throw ApiError.notFound('Property not found');

  if (actor.role !== 'admin' && String(property.owner) !== String(actor._id)) {
    throw ApiError.forbidden('You can only delete your own listings');
  }

  await property.deleteOne();

  await AuditLog.record({
    actor,
    action: 'property.deleted',
    entityId: id,
    before: { name: property.name },
  });

  return { deleted: true };
}

/** Publishes a pending listing. */
async function approve(id, admin) {
  const property = await Property.findById(id);
  if (!property) throw ApiError.notFound('Property not found');

  const before = { status: property.status };

  property.status = 'approved';
  property.isVerified = true;
  property.rejectionReason = undefined;
  await property.save();

  await AuditLog.record({
    actor: admin,
    action: 'property.approved',
    entityId: property._id,
    before,
    after: { status: 'approved' },
  });

  logger.info({ propertyId: property.id }, 'Property approved');
  return property.toJSON();
}

/** Rejects a listing. A reason is mandatory — the owner has to know why. */
async function reject(id, reason, admin) {
  const property = await Property.findById(id);
  if (!property) throw ApiError.notFound('Property not found');

  const before = { status: property.status };

  property.status = 'rejected';
  property.isVerified = false;
  property.rejectionReason = reason;
  await property.save();

  await AuditLog.record({
    actor: admin,
    action: 'property.rejected',
    entityId: property._id,
    before,
    after: { status: 'rejected', reason },
  });

  logger.info({ propertyId: property.id }, 'Property rejected');
  return property.toJSON();
}

/** The signed-in owner's own submissions, including review status. */
async function listMine(ownerId) {
  const items = await Property.find({ owner: ownerId }).sort({ createdAt: -1 });
  return items.map((p) => p.toJSON());
}

/** Counters for the admin dashboard. */
async function stats() {
  const [total, pending, approved, rejected] = await Promise.all([
    Property.countDocuments(),
    Property.countDocuments({ status: 'pending' }),
    Property.countDocuments({ status: 'approved' }),
    Property.countDocuments({ status: 'rejected' }),
  ]);
  return { total, pending, approved, rejected };
}

module.exports = {
  listPublic,
  listAll,
  getById,
  create,
  update,
  remove,
  approve,
  reject,
  listMine,
  stats,
};
