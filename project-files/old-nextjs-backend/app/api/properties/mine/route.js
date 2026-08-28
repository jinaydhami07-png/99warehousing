import { connectDB } from '@/lib/db';
import Property from '@/lib/models/Property';
import { ok, handler } from '@/lib/apiResponse';
import { requireUser } from '@/lib/auth';

export const dynamic = 'force-dynamic';

/** The signed-in user's own submissions, including their review status. */
export const GET = handler(async (req) => {
  await connectDB();
  const user = await requireUser(req);
  const items = await Property.find({ owner: user._id }).sort({ createdAt: -1 });
  return ok({
    items: items.map((p) => ({
      ...p.toPublic(),
      status: p.status,
      rejectionReason: p.rejectionReason,
    })),
  });
});
