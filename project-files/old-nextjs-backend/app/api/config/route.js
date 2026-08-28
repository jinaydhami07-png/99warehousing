import { ok, handler } from '@/lib/apiResponse';
import { googleConfigured } from '@/lib/google';
import { cloudinaryConfigured, storageDriver } from '@/lib/storage';

export const dynamic = 'force-dynamic';

/**
 * Non-secret settings the browser legitimately needs, plus which optional
 * integrations are configured — so the UI can explain what's unavailable
 * instead of failing silently.
 *
 * The Maps key is deliberately public (restricted by HTTP referrer in
 * Google Console). No private key is ever exposed here.
 */
export const GET = handler(async () => {
  return ok({
    googleMapsApiKey: process.env.NEXT_PUBLIC_GOOGLE_MAPS_API_KEY || null,
    features: {
      googleAuth: googleConfigured(),
      uploads: true,
      cloudinary: cloudinaryConfigured(),
      storage: storageDriver(),
      email: Boolean(process.env.RESEND_API_KEY?.trim()),
    },
  });
});
