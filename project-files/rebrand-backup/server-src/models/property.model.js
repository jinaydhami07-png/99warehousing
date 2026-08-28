/**
 * Property model — warehouses, land, logistics parks, cold storage, etc.
 *
 * Follows the same conventions as user.model.js: schema-level validation,
 * timestamps, indexes matched to real queries, and a toJSON transform so
 * internal fields never leak through res.json().
 */
'use strict';

const mongoose = require('mongoose');

const PROPERTY_TYPES = [
  'Warehouse',
  'Cold Storage',
  'Industrial Shed',
  'Logistics Park',
  'Dark Store',
  'Industrial Land',
];

const GRADES = ['Grade A', 'Grade B', 'Grade C', 'Cold Chain', 'Land'];
const STATUSES = ['draft', 'pending', 'approved', 'rejected'];

const mediaSchema = new mongoose.Schema(
  {
    url: { type: String, required: true },
    publicId: String,
    caption: { type: String, trim: true, maxlength: 200 },
    isPrimary: { type: Boolean, default: false },
  },
  { _id: true }
);

const propertySchema = new mongoose.Schema(
  {
    name: {
      type: String,
      required: [true, 'Property title is required'],
      trim: true,
      maxlength: [160, 'Title cannot exceed 160 characters'],
    },
    slug: { type: String, unique: true, sparse: true },
    description: { type: String, trim: true, maxlength: 5000 },

    type: {
      type: String,
      enum: { values: PROPERTY_TYPES, message: '{VALUE} is not a supported property type' },
      default: 'Warehouse',
    },
    grade: { type: String, enum: GRADES, default: 'Grade B' },

    city: { type: String, trim: true, required: [true, 'City is required'] },
    locality: { type: String, trim: true },

    rate: {
      type: Number,
      required: [true, 'Rate is required'],
      min: [1, 'Rate must be greater than zero'],
    },
    area: {
      type: Number,
      required: [true, 'Area is required'],
      min: [1, 'Area must be greater than zero'],
    },
    depositMonths: { type: Number, default: 3, min: 0, max: 24 },

    specs: {
      clearHeight: Number,
      loadingDocks: Number,
      power: Number,
      features: [{ type: String, trim: true }],
    },

    images: [mediaSchema],

    owner: { type: mongoose.Schema.Types.ObjectId, ref: 'User', required: true },
    ownerName: { type: String, trim: true },

    status: { type: String, enum: STATUSES, default: 'pending' },
    isVerified: { type: Boolean, default: false },
    rejectionReason: { type: String, trim: true, maxlength: 500 },

    views: { type: Number, default: 0 },
    enquiryCount: { type: Number, default: 0 },
  },
  {
    timestamps: true,
    toJSON: {
      virtuals: true,
      transform(doc, ret) {
        ret.id = ret._id;
        delete ret._id;
        delete ret.__v;
        return ret;
      },
    },
    toObject: { virtuals: true },
  }
);

/* ── Indexes, matched to how the app actually queries ──
   status+city+rate → the public listing feed's default filter+sort path.
   status+createdAt → the admin approval queue, newest-first. */
propertySchema.index({ status: 1, city: 1, rate: 1 });
propertySchema.index({ status: 1, createdAt: -1 });
propertySchema.index({ name: 'text', city: 'text', locality: 'text', description: 'text' });

propertySchema.virtual('location').get(function () {
  return [this.locality, this.city].filter(Boolean).join(', ');
});

propertySchema.pre('save', function (next) {
  if (!this.slug && this.name) {
    const base = this.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60);
    this.slug = `${base}-${new mongoose.Types.ObjectId().toString().slice(-6)}`;
  }
  next();
});

module.exports = mongoose.model('Property', propertySchema);
module.exports.PROPERTY_TYPES = PROPERTY_TYPES;
module.exports.GRADES = GRADES;
module.exports.STATUSES = STATUSES;
