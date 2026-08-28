/**
 * Image controller — HTTP layer only.
 */
'use strict';

const catchAsync = require('../utils/catchAsync');
const { success, created } = require('../utils/ApiResponse');
const imageService = require('../services/image.service');

/** POST /api/v1/upload — multipart, field name "files" */
const upload = catchAsync(async (req, res) => {
  const files = await imageService.saveMany(req.files, req.user);
  created(res, { message: 'Upload complete', data: { files } });
});

/**
 * GET /api/v1/images/:id — streams the bytes.
 *
 * Public: these are photos on public listings, and requiring a token would
 * break plain <img src> tags, which cannot send an Authorization header.
 */
const serve = catchAsync(async (req, res) => {
  const image = await imageService.getBytes(req.params.id);

  /* Content is immutable — an image document is never rewritten, only
     replaced by a new upload with a new id. So it can be cached hard, and
     a matching ETag can be answered with 304 and no body at all. */
  const etag = `"img-${image._id}-${image.size}"`;
  if (req.headers['if-none-match'] === etag) return res.status(304).end();

  res.set({
    'Content-Type': image.contentType,
    'Content-Length': image.size,
    'Cache-Control': 'public, max-age=31536000, immutable',
    ETag: etag,
    /* Belt and braces alongside the magic-byte sniffing on the way in:
       stop a browser content-sniffing this into something executable. */
    'X-Content-Type-Options': 'nosniff',
    'Content-Disposition': 'inline',
  });
  res.send(image.data);
});

/** DELETE /api/v1/images/:id */
const remove = catchAsync(async (req, res) => {
  const data = await imageService.remove(req.params.id, req.user);
  success(res, { message: 'Image deleted', data });
});

module.exports = { upload, serve, remove };
