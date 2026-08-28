'use strict';

const { z } = require('zod');
const { PROPERTY_TYPES, GRADES, STATUSES } = require('../models/property.model');

const objectId = z.string().regex(/^[0-9a-fA-F]{24}$/, 'Invalid property id');

const specs = z
  .object({
    clearHeight: z.coerce.number().nonnegative().optional(),
    loadingDocks: z.coerce.number().int().nonnegative().optional(),
    power: z.coerce.number().nonnegative().optional(),
    features: z.array(z.string().trim().max(80)).max(30).optional(),
  })
  .partial()
  .optional();

const image = z.object({
  url: z.string().trim().min(1, 'Image url is required'),
  publicId: z.string().optional(),
  caption: z.string().trim().max(200).optional(),
  isPrimary: z.boolean().optional(),
});

/* Fields anyone may set when submitting a listing. Deliberately excludes
   ownerName, status and isVerified: a submitter must not be able to name a
   different owner or approve their own listing. */
const createFields = {
  name: z.string().trim().min(3, 'Title must be at least 3 characters').max(160),
  description: z.string().trim().max(5000).optional(),
  type: z.enum(PROPERTY_TYPES).optional(),
  grade: z.enum(GRADES).optional(),
  city: z.string().trim().min(1, 'City is required').max(80),
  locality: z.string().trim().max(120).optional(),
  rate: z.coerce.number().positive('Rate must be greater than zero'),
  area: z.coerce.number().positive('Area must be greater than zero'),
  depositMonths: z.coerce.number().min(0).max(24).optional(),
  specs,
  images: z.array(image).max(12).optional(),
};

/* .strict() so an unknown key is rejected with a message naming it, rather
   than silently stripped. Silent stripping is how the admin form lost the
   Owner and Status fields for so long: the request succeeded, and the values
   simply never arrived. */
const create = z.object({
  body: z.object(createFields).strict(),
});

/* Admins additionally set who owns the listing and what state it goes into —
   they are entering listings on behalf of real owners, and moderating. */
const adminCreate = z.object({
  body: z
    .object({
      ...createFields,
      ownerName: z.string().trim().max(160).optional(),
      status: z.enum(STATUSES).optional(),
      isVerified: z.boolean().optional(),
    })
    .strict(),
});

/* Owners may not set status/isVerified — those are moderation decisions.
   `.strict()` rejects them outright rather than silently dropping them, so a
   client attempting it gets a clear 422 instead of a surprising no-op. */
const update = z.object({
  params: z.object({ id: objectId }),
  body: z
    .object({
      name: z.string().trim().min(3).max(160).optional(),
      description: z.string().trim().max(5000).optional(),
      type: z.enum(PROPERTY_TYPES).optional(),
      grade: z.enum(GRADES).optional(),
      city: z.string().trim().min(1).max(80).optional(),
      locality: z.string().trim().max(120).optional(),
      rate: z.coerce.number().positive().optional(),
      area: z.coerce.number().positive().optional(),
      depositMonths: z.coerce.number().min(0).max(24).optional(),
      specs,
      images: z.array(image).max(12).optional(),
    })
    .strict()
    .refine((v) => Object.keys(v).length > 0, 'Provide at least one field to update'),
});

/* Admins may additionally change status directly. */
const adminUpdate = z.object({
  params: z.object({ id: objectId }),
  body: z
    .object({
      name: z.string().trim().min(3).max(160).optional(),
      description: z.string().trim().max(5000).optional(),
      type: z.enum(PROPERTY_TYPES).optional(),
      grade: z.enum(GRADES).optional(),
      city: z.string().trim().min(1).max(80).optional(),
      locality: z.string().trim().max(120).optional(),
      rate: z.coerce.number().positive().optional(),
      area: z.coerce.number().positive().optional(),
      depositMonths: z.coerce.number().min(0).max(24).optional(),
      status: z.enum(STATUSES).optional(),
      isVerified: z.boolean().optional(),
      /* The admin edit form has an Owner field, so the schema has to accept
         it — otherwise .strict() rejects the whole request and no edit saves
         at all. */
      ownerName: z.string().trim().max(160).optional(),
      specs,
      images: z.array(image).max(12).optional(),
    })
    .strict(),
});

const byId = z.object({ params: z.object({ id: objectId }) });

const reject = z.object({
  params: z.object({ id: objectId }),
  body: z.object({
    reason: z.string().trim().min(1, 'A rejection reason is required').max(500),
  }),
});

const listQuery = z.object({
  query: z.object({
    page: z.coerce.number().int().positive().optional(),
    limit: z.coerce.number().int().positive().max(100).optional(),
    city: z.string().trim().max(80).optional(),
    type: z.enum(PROPERTY_TYPES).optional(),
    grade: z.enum(GRADES).optional(),
    status: z.enum(STATUSES).optional(),
    minRate: z.coerce.number().nonnegative().optional(),
    maxRate: z.coerce.number().nonnegative().optional(),
    minArea: z.coerce.number().nonnegative().optional(),
    maxArea: z.coerce.number().nonnegative().optional(),
    q: z.string().trim().max(120).optional(),
    sort: z.enum(['rate-asc', 'rate-desc', 'area-asc', 'area-desc', 'newest', 'oldest']).optional(),
  }),
});

module.exports = { create, adminCreate, update, adminUpdate, byId, reject, listQuery, objectId };
