import { connectDB } from '@/lib/db';
import Favorite from '@/lib/models/Favorite';
import Property from '@/lib/models/Property';
import { ok, handler, ApiError } from '@/lib/apiResponse';
import { requireUser } from '@/lib/auth';

export const dynamic = 'force-dynamic';

/* Upsert: clicking twice can never create a duplicate, and the unique
   index makes this safe under concurrent requests. */
export const POST = handler(async (req, { params }) => {
  await connectDB();
  const user = await requireUser(req);

  if (!(await Property.exists({ _id: params.id }))) {
    throw ApiError.notFound('Property not found.');
  }

  await Favorite.updateOne(
    { user: user._id, property: params.id },
    { $setOnInsert: { user: user._id, property: params.id } },
    { upsert: true }
  );

  const count = await Favorite.countDocuments({ user: user._id });
  return ok({ ok: true, favorited: true, count }, 201);
});

export const DELETE = handler(async (req, { params }) => {
  await connectDB();
  const user = await requireUser(req);
  await Favorite.deleteOne({ user: user._id, property: params.id });
  const count = await Favorite.countDocuments({ user: user._id });
  return ok({ ok: true, favorited: false, count });
});
