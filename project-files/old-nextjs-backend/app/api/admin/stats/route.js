import { connectDB } from '@/lib/db';
import Property from '@/lib/models/Property';
import User from '@/lib/models/User';
import Enquiry from '@/lib/models/Enquiry';
import { ok, handler } from '@/lib/apiResponse';
import { requireAdmin } from '@/lib/auth';

export const dynamic = 'force-dynamic';

export const GET = handler(async (req) => {
  await connectDB();
  await requireAdmin(req);

  const [total, pending, approved, rejected, users, enquiries] = await Promise.all([
    Property.countDocuments(),
    Property.countDocuments({ status: 'pending' }),
    Property.countDocuments({ status: 'approved' }),
    Property.countDocuments({ status: 'rejected' }),
    User.countDocuments(),
    Enquiry.countDocuments(),
  ]);

  return ok({ total, pending, approved, rejected, users, enquiries });
});
