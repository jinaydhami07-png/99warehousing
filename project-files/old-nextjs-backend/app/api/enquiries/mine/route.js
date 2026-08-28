import { connectDB } from '@/lib/db';
import Enquiry from '@/lib/models/Enquiry';
import Property from '@/lib/models/Property';
import { ok, handler } from '@/lib/apiResponse';
import { requireUser } from '@/lib/auth';

export const dynamic = 'force-dynamic';

/** Enquiries received on the signed-in user's own listings. */
export const GET = handler(async (req) => {
  await connectDB();
  const user = await requireUser(req);

  const myIds = await Property.find({ owner: user._id }).distinct('_id');
  const items = await Enquiry.find({ property: { $in: myIds } })
    .populate('property', 'name city rate')
    .sort({ createdAt: -1 });

  return ok({ items });
});
