import { connectDB } from '@/lib/db';
import Property from '@/lib/models/Property';
import AuditLog from '@/lib/models/AuditLog';
import { ok, handler, ApiError } from '@/lib/apiResponse';
import { requireAdmin } from '@/lib/auth';

export const dynamic = 'force-dynamic';

export const PATCH = handler(async (req, { params }) => {
  await connectDB();
  const admin = await requireAdmin(req);

  const property = await Property.findById(params.id);
  if (!property) throw ApiError.notFound('Property not found.');

  const before = { status: property.status };
  property.status = 'approved';
  property.isVerified = true;
  property.rejectionReason = undefined;
  property.approvedBy = admin._id;
  property.approvedAt = new Date();
  await property.save();

  await AuditLog.create({
    actor: admin._id, actorEmail: admin.email,
    action: 'property.approved', entityId: property._id,
    before, after: { status: 'approved' },
  });

  return ok({ item: property.toAdmin() });
});
