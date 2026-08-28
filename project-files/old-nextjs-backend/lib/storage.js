/**
 * Image and document storage.
 *
 * Two drivers, chosen automatically:
 *   • Cloudinary — when CLOUDINARY_* are set in .env.local (section 5)
 *   • Local disk — otherwise, writes to public/uploads/
 *
 * Local storage is fine for development. On a cloud host the filesystem is
 * ephemeral, so anything written there disappears on the next deploy —
 * configure Cloudinary before going live.
 */
import { writeFile, mkdir, unlink } from 'fs/promises';
import path from 'path';
import crypto from 'crypto';
import { ApiError } from './apiResponse';

const MAX_BYTES = 8 * 1024 * 1024; // 8 MB per file

const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];
const DOC_TYPES = ['application/pdf'];

const has = (v) => typeof v === 'string' && v.trim().length > 0;

export const cloudinaryConfigured = () =>
  has(process.env.CLOUDINARY_CLOUD_NAME) &&
  has(process.env.CLOUDINARY_API_KEY) &&
  has(process.env.CLOUDINARY_API_SECRET);

export const storageDriver = () => (cloudinaryConfigured() ? 'cloudinary' : 'local');

/** Rejects anything that isn't an allowed type or is over the size cap. */
export function assertUploadable(file, kind = 'image') {
  if (!file || typeof file.arrayBuffer !== 'function') {
    throw ApiError.badRequest('No file was received.');
  }
  const allowed = kind === 'document' ? [...IMAGE_TYPES, ...DOC_TYPES] : IMAGE_TYPES;
  if (!allowed.includes(file.type)) {
    throw ApiError.badRequest(
      `Unsupported file type "${file.type || 'unknown'}". Allowed: ${allowed.join(', ')}`
    );
  }
  if (file.size > MAX_BYTES) {
    throw ApiError.badRequest(
      `"${file.name}" is ${(file.size / 1024 / 1024).toFixed(1)} MB. The limit is ${MAX_BYTES / 1024 / 1024} MB.`
    );
  }
}

async function uploadToCloudinary(buffer, { folder, filename }) {
  const { v2: cloudinary } = await import('cloudinary');
  cloudinary.config({
    cloud_name: process.env.CLOUDINARY_CLOUD_NAME,
    api_key: process.env.CLOUDINARY_API_KEY,
    api_secret: process.env.CLOUDINARY_API_SECRET,
    secure: true,
  });

  const result = await new Promise((resolve, reject) => {
    cloudinary.uploader
      .upload_stream(
        { folder, public_id: filename, resource_type: 'auto', overwrite: false },
        (err, res) => (err ? reject(err) : resolve(res))
      )
      .end(buffer);
  });

  return {
    url: result.secure_url,
    publicId: result.public_id,
    storage: 'cloudinary',
    width: result.width,
    height: result.height,
    bytes: result.bytes,
    format: result.format,
  };
}

async function uploadToLocal(buffer, { folder, filename, ext }) {
  const dir = path.join(process.cwd(), 'public', 'uploads', folder);
  await mkdir(dir, { recursive: true });

  const safe = `${filename}${ext}`;
  await writeFile(path.join(dir, safe), buffer);

  return {
    url: `/uploads/${folder}/${safe}`,   // served straight out of /public
    publicId: `${folder}/${safe}`,
    storage: 'local',
    bytes: buffer.length,
    format: ext.replace('.', ''),
  };
}

/**
 * Stores one File (from request.formData()) and returns a media object
 * shaped exactly like the Property model's media sub-schema.
 */
export async function saveFile(file, { folder = 'properties', kind = 'image' } = {}) {
  assertUploadable(file, kind);

  const buffer = Buffer.from(await file.arrayBuffer());

  // Never trust the client's filename — derive our own.
  const ext = path.extname(file.name || '').toLowerCase() || '.jpg';
  const filename = `${Date.now()}-${crypto.randomBytes(6).toString('hex')}`;

  return cloudinaryConfigured()
    ? uploadToCloudinary(buffer, { folder, filename })
    : uploadToLocal(buffer, { folder, filename, ext });
}

/** Best-effort cleanup. A failure here must never break the main request. */
export async function deleteFile(media) {
  if (!media?.publicId) return;
  try {
    if (media.storage === 'cloudinary') {
      const { v2: cloudinary } = await import('cloudinary');
      cloudinary.config({
        cloud_name: process.env.CLOUDINARY_CLOUD_NAME,
        api_key: process.env.CLOUDINARY_API_KEY,
        api_secret: process.env.CLOUDINARY_API_SECRET,
      });
      await cloudinary.uploader.destroy(media.publicId);
    } else {
      await unlink(path.join(process.cwd(), 'public', 'uploads', media.publicId));
    }
  } catch (err) {
    console.warn('  Could not delete stored file:', media.publicId, err.message);
  }
}
