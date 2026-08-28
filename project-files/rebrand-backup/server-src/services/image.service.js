/**
 * Image service — stores and retrieves property photos held in MongoDB.
 */
'use strict';

const Image = require('../models/image.model');
const Property = require('../models/property.model');
const ApiError = require('../utils/ApiError');
const logger = require('../config/logger');

/* ── Content sniffing ──────────────────────────────────────────
   `file.mimetype` comes from the Content-Type the CLIENT put in the
   multipart body. It is a claim, not a fact — anything can be uploaded as
   "image/jpeg". Since these bytes are later served back from our own
   origin, trusting that claim would let someone store HTML or SVG here and
   have it execute same-origin.

   So the format is determined from the leading bytes instead, and the
   sniffed type — never the claimed one — is what gets persisted.
   ───────────────────────────────────────────────────────────── */
function sniffImageType(buf) {
  if (!Buffer.isBuffer(buf) || buf.length < 12) return null;

  // JPEG: FF D8 FF
  if (buf[0] === 0xff && buf[1] === 0xd8 && buf[2] === 0xff) return 'image/jpeg';

  // PNG: 89 50 4E 47 0D 0A 1A 0A
  if (buf.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))) {
    return 'image/png';
  }

  // GIF: "GIF87a" / "GIF89a"
  if (buf.subarray(0, 6).toString('latin1').match(/^GIF8[79]a$/)) return 'image/gif';

  // WEBP: "RIFF" .... "WEBP"
  if (
    buf.subarray(0, 4).toString('latin1') === 'RIFF' &&
    buf.subarray(8, 12).toString('latin1') === 'WEBP'
  ) {
    return 'image/webp';
  }

  // AVIF (ISO-BMFF): bytes 4-8 are "ftyp", brand at 8-12 is "avif"/"avis"
  if (buf.subarray(4, 8).toString('latin1') === 'ftyp') {
    const brand = buf.subarray(8, 12).toString('latin1');
    if (brand === 'avif' || brand === 'avis') return 'image/avif';
  }

  return null;
}

/**
 * Persists uploaded files and returns descriptors ready to drop straight
 * into `Property.images[]`.
 *
 * The returned `url` points at this API rather than a CDN, which is what
 * keeps the whole feature free of third-party credentials.
 */
async function saveMany(files, user) {
  if (!files || !files.length) throw ApiError.badRequest('No files were uploaded');

  const saved = [];
  for (const file of files) {
    const contentType = sniffImageType(file.buffer);
    if (!contentType) {
      throw ApiError.unprocessable(
        `"${file.originalname}" is not a readable image (JPEG, PNG, WebP, GIF or AVIF only)`
      );
    }

    const doc = await Image.create({
      data: file.buffer,
      contentType,
      size: file.buffer.length,
      originalName: file.originalname,
      uploadedBy: user._id,
    });

    saved.push({
      url: `/api/v1/images/${doc._id}`,
      publicId: String(doc._id),
      contentType,
      size: doc.size,
      originalName: doc.originalName,
    });
  }

  logger.info({ count: saved.length, userId: user.id }, 'Images uploaded');
  return saved;
}

/**
 * The bytes, for the streaming route.
 * `.select('+data')` is required because the field is excluded by default.
 */
async function getBytes(id) {
  const image = await Image.findById(id).select('+data');
  if (!image || !image.data) throw ApiError.notFound('Image not found');
  return image;
}

/**
 * Marks images as belonging to a listing, so they are no longer orphans.
 * Best-effort by design: failing to tag an image must not fail the listing
 * it was attached to.
 */
async function attachToProperty(images, propertyId) {
  const ids = (images || []).map((i) => i.publicId).filter(Boolean);
  if (!ids.length) return;

  try {
    await Image.updateMany({ _id: { $in: ids } }, { $set: { property: propertyId } });
  } catch (err) {
    logger.warn({ err: err.message, propertyId }, 'Could not tag images with their property');
  }
}

/**
 * Deletes an image. Owner-or-admin only — checked here rather than in the
 * controller so the rule holds for every caller.
 */
async function remove(id, user) {
  const image = await Image.findById(id);
  if (!image) throw ApiError.notFound('Image not found');

  const isOwner = String(image.uploadedBy) === String(user._id);
  if (!isOwner && user.role !== 'admin') {
    throw ApiError.forbidden('You can only delete your own uploads');
  }

  /* Detach from any listing still referencing it, so the property is not
     left pointing at a URL that now 404s. */
  if (image.property) {
    await Property.updateOne(
      { _id: image.property },
      { $pull: { images: { publicId: String(image._id) } } }
    ).catch(() => {});
  }

  await image.deleteOne();
  return { id: String(id) };
}

module.exports = { saveMany, getBytes, attachToProperty, remove, sniffImageType };
