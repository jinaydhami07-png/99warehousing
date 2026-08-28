import mongoose from 'mongoose';

export const PROPERTY_TYPES = [
  'Warehouse',
  'Cold Storage',
  'Industrial Shed',
  'Logistics Park',
  'Dark Store',
  'Industrial Land',
];

export const GRADES = ['Grade A', 'Grade B', 'Grade C', 'Cold Chain', 'Land'];
export const STATUSES = ['draft', 'pending', 'approved', 'rejected'];
export const LISTING_TYPES = ['rent', 'sale', 'bts'];

/**
 * One uploaded file. `storage` records where the bytes actually live so the
 * delete path knows whether to call Cloudinary or unlink a local file.
 */
const mediaSchema = new mongoose.Schema(
  {
    url: { type: String, required: true },
    publicId: String,                 // Cloudinary id, used to delete later
    storage: { type: String, enum: ['cloudinary', 'local'], default: 'local' },
    width: Number,
    height: Number,
    bytes: Number,
    format: String,
    caption: { type: String, trim: true, maxlength: 200 },
    isPrimary: { type: Boolean, default: false },
    uploadedAt: { type: Date, default: Date.now },
  },
  { _id: true }
);

const propertySchema = new mongoose.Schema(
  {
    /* ── Text content ─────────────────────────────────────── */
    name: { type: String, required: [true, 'Property title is required'], trim: true, maxlength: 160 },
    slug: { type: String, unique: true, sparse: true, index: true },
    description: { type: String, trim: true, maxlength: 5000 },

    type: { type: String, enum: PROPERTY_TYPES, default: 'Warehouse', index: true },
    grade: { type: String, enum: GRADES, default: 'Grade B' },
    listingType: { type: String, enum: LISTING_TYPES, default: 'rent' },

    /* ── Location ─────────────────────────────────────────── */
    city: { type: String, trim: true, index: true },
    locality: { type: String, trim: true },
    state: { type: String, trim: true },
    pincode: { type: String, trim: true, match: [/^\d{6}$/, 'PIN code must be 6 digits'] },
    address: { type: String, trim: true, maxlength: 500 },
    coordinates: {
      lat: { type: Number, min: -90, max: 90 },
      lng: { type: Number, min: -180, max: 180 },
    },

    /* ── Commercials ──────────────────────────────────────── */
    rate: { type: Number, required: [true, 'Rate is required'], min: [0, 'Rate cannot be negative'], index: true },
    area: { type: Number, required: [true, 'Area is required'], min: [0, 'Area cannot be negative'] },
    depositMonths: { type: Number, default: 3, min: 0, max: 24 },
    negotiable: { type: Boolean, default: true },
    availableFrom: Date,

    /* ── Specifications ───────────────────────────────────── */
    specs: {
      clearHeight: Number,    // metres
      loadingDocks: Number,
      floorStrength: Number,  // tonnes/m²
      power: Number,          // kVA
      fireSafety: String,
      features: [{ type: String, trim: true }],
      certifications: [{ type: String, trim: true }],
    },

    /* ── Media ────────────────────────────────────────────── */
    images: [mediaSchema],
    floorPlan: mediaSchema,
    documents: [
      {
        name: { type: String, trim: true },
        url: String,
        publicId: String,
        storage: { type: String, enum: ['cloudinary', 'local'], default: 'local' },
        mimeType: String,
        bytes: Number,
        uploadedAt: { type: Date, default: Date.now },
      },
    ],

    /* ── Ownership & moderation ───────────────────────────── */
    owner: { type: mongoose.Schema.Types.ObjectId, ref: 'User', index: true },
    ownerName: { type: String, trim: true },   // denormalised for admin tables
    ownerEmail: { type: String, trim: true, lowercase: true },

    status: { type: String, enum: STATUSES, default: 'pending', index: true },
    isVerified: { type: Boolean, default: false },
    approvedBy: { type: mongoose.Schema.Types.ObjectId, ref: 'User' },
    approvedAt: Date,
    rejectionReason: { type: String, trim: true, maxlength: 500 },

    /* ── Engagement ───────────────────────────────────────── */
    views: { type: Number, default: 0 },
    enquiryCount: { type: Number, default: 0 },
  },
  { timestamps: true, toJSON: { virtuals: true }, toObject: { virtuals: true } }
);

/* Indexes matching the queries the app actually runs. */
propertySchema.index({ status: 1, city: 1, rate: 1 });   // public listing feed
propertySchema.index({ status: 1, type: 1 });
propertySchema.index({ status: 1, createdAt: -1 });      // admin queue ordering
propertySchema.index({ name: 'text', city: 'text', locality: 'text', description: 'text' });

/** "Bhiwandi, Mumbai" — assembled rather than stored twice. */
propertySchema.virtual('location').get(function () {
  return [this.locality, this.city].filter(Boolean).join(', ');
});

/** The image marked primary, else the first one. */
propertySchema.virtual('primaryImage').get(function () {
  if (!this.images || !this.images.length) return null;
  return this.images.find((i) => i.isPrimary) || this.images[0];
});

propertySchema.pre('save', function (next) {
  if (!this.slug && this.name) {
    const base = this.name
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '')
      .slice(0, 60);
    this.slug = `${base}-${String(this._id).slice(-6)}`;
  }
  // Exactly one primary image, always.
  if (this.images?.length && !this.images.some((i) => i.isPrimary)) {
    this.images[0].isPrimary = true;
  }
  next();
});

/** Shape sent to the browser. Never leaks owner email or internal ids. */
propertySchema.methods.toPublic = function () {
  return {
    id: this._id.toString(),
    slug: this.slug,
    name: this.name,
    description: this.description,
    type: this.type,
    grade: this.grade,
    listingType: this.listingType,
    city: this.city,
    locality: this.locality,
    location: this.location,
    address: this.address,
    coordinates: this.coordinates,
    rate: this.rate,
    area: this.area,
    depositMonths: this.depositMonths,
    negotiable: this.negotiable,
    availableFrom: this.availableFrom,
    specs: this.specs,
    images: (this.images || []).map((i) => ({
      id: i._id?.toString(),
      url: i.url,
      caption: i.caption,
      isPrimary: i.isPrimary,
      width: i.width,
      height: i.height,
    })),
    primaryImage: this.primaryImage ? this.primaryImage.url : null,
    floorPlan: this.floorPlan ? { url: this.floorPlan.url } : null,
    isVerified: this.isVerified,
    status: this.status,
    views: this.views,
    enquiryCount: this.enquiryCount,
    createdAt: this.createdAt,
  };
};

/** Adds the moderation fields an admin needs on top of the public shape. */
propertySchema.methods.toAdmin = function () {
  return {
    ...this.toPublic(),
    ownerName: this.ownerName,
    ownerEmail: this.ownerEmail,
    rejectionReason: this.rejectionReason,
    approvedAt: this.approvedAt,
    submitted: this.createdAt,
    documents: this.documents,
  };
};

export default mongoose.models.Property || mongoose.model('Property', propertySchema);
