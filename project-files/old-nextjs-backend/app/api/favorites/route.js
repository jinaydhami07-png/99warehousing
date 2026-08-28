import { connectDB } from '@/lib/db';
import Favorite from '@/lib/models/Favorite';
import { ok, handler } from '@/lib/apiResponse';
import { requireUser } from '@/lib/auth';

export const dynamic = 'force-dynamic';

export const GET = handler(async (req) => {
  await connectDB();
  const user = await requireUser(req);
  const favs = await Favorite.find({ user: user._id }).populate('property');
  return ok({
    items: favs
      .filter((f) => f.property)          // property may have been deleted
      .map((f) => ({ ...f.property.toPublic(), favoritedAt: f.createdAt })),
  });
});
