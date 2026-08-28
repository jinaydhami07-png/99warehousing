/**
 * Image — property photos stored directly in MongoDB.
 *
 * Kept in its own collection rather than embedded in the Property document
 * for two reasons: MongoDB's 16 MB per-document ceiling would be reachable
 * with a handful of phone photos, and every property read would otherwise
 * drag the full binary payload along with it.
 *
 * `data` is `select: false` so ordinary queries return only the metadata.
 * The bytes are fetched deliberately, by the one route that streams them.
 *
 * This trades CDN performance for zero external dependencies — no Cloudinary
 * or S3 account, no keys to configure. If image traffic ever becomes the
 * bottleneck, the migration path is to upload to object storage and rewrite
 * `Property.images[].url`; nothing else in the codebase needs to change,
 * because the rest of the app only ever sees a URL string.
 */
'use strict';

const mongoose = require('mongoose');

/* Raster formats a browser can display. SVG is deliberately excluded: it is
   an executable document (it can carry <script>), and serving user-supplied
   SVG from our own origin would be a stored-XSS hole. */
const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];

const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

const imageSchema = new mongoose.Schema(
  {
    data: {
      type: Buffer,
      required: true,
      select: false, // never travels with a metadata query
    },
    contentType: {
      type: String,
      required: true,
      enum: { values: ALLOWED_MIME, message: '{VALUE} is not a supported image type' },
    },
    size: { type: Number, required: true, max: MAX_BYTES },
    originalName: { type: String, trim: true, maxlength: 260 },

    /* Who uploaded it — so an owner cannot delete someone else's image. */
    uploadedBy: { type: mongoose.Schema.Types.ObjectId, ref: 'User', required: true },

    /* Set once the image is attached to a listing. Images that are never
       attached are orphans and can be swept up later. */
    property: { type: mongoose.Schema.Types.ObjectId, ref: 'Property' },
  },
  { timestamps: true }
);

/* Finding a user's uploads, and finding orphans to clean up. */
imageSchema.index({ uploadedBy: 1, createdAt: -1 });
imageSchema.index({ property: 1 });

module.exports = mongoose.model('Image', imageSchema);
module.exports.ALLOWED_MIME = ALLOWED_MIME;
module.exports.MAX_BYTES = MAX_BYTES;
