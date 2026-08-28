import { ok, handler, ApiError } from '@/lib/apiResponse';
import { requireUser } from '@/lib/auth';
import { saveFile, storageDriver } from '@/lib/storage';

export const dynamic = 'force-dynamic';
// Uploads must run on Node, not Edge — the local-disk driver needs fs.
export const runtime = 'nodejs';

const MAX_FILES = 12;

/**
 * POST /api/upload
 *
 * Accepts multipart/form-data and returns stored media objects ready to be
 * attached to a property. Upload happens first, then the returned objects
 * go in the `images` array when creating or updating the listing — so a
 * half-finished form never leaves a property row behind.
 *
 * Fields:
 *   files  — one or more File objects (repeatable field)
 *   kind   — "image" (default) or "document"
 *   folder — subfolder, defaults to "properties"
 *
 * Example:
 *   const fd = new FormData();
 *   fd.append('files', fileInput.files[0]);
 *   const { files } = await API.upload(fd);
 *   await API.properties.create({ name, rate, area, images: files });
 */
export const POST = handler(async (req) => {
  await requireUser(req);   // never allow anonymous uploads

  const form = await req.formData().catch(() => {
    throw ApiError.badRequest('Expected multipart/form-data.');
  });

  const files = form.getAll('files').filter((f) => typeof f?.arrayBuffer === 'function');
  if (!files.length) throw ApiError.badRequest('No files were attached.');
  if (files.length > MAX_FILES) {
    throw ApiError.badRequest(`Too many files — the limit is ${MAX_FILES} per upload.`);
  }

  const kind = form.get('kind') === 'document' ? 'document' : 'image';

  // Only allow a simple folder name; no path traversal.
  const rawFolder = String(form.get('folder') || 'properties');
  const folder = /^[a-z0-9-]{1,40}$/i.test(rawFolder) ? rawFolder : 'properties';

  // Sequential rather than parallel: keeps memory flat and gives a clear
  // error naming the file that failed.
  const saved = [];
  for (const file of files) {
    saved.push(await saveFile(file, { folder, kind }));
  }

  if (saved.length) saved[0].isPrimary = true;

  return ok({ files: saved, storage: storageDriver() }, 201);
});
