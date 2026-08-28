import { z } from 'zod';
import { connectDB } from '@/lib/db';
import Property from '@/lib/models/Property';
import AuditLog from '@/lib/models/AuditLog';
import { ok, handler, ApiError } from '@/lib/apiResponse';
import { parse } from '@/lib/validate';
import { requireAdmin } from '@/lib/auth';

export const dynamic = 'force-dynamic';

const schema = z.object({
  reason: z.string().trim().min(1, 'A rejection reason is required').max(500),
});

export const PATCH = handler(async (req, { params }) => {
  await connectDB();
  const admin = await requireAdmin(req);

  const { reason } = parse(schema, await req.json());

  const property = await Property.findById(params.id);
  if (!property) throw ApiError.notFound('Property not found.');

  const before = { status: property.status };
  property.status = 'rejected';
  property.isVerified = false;
  property.rejectionReason = reason;
  await property.save();

  await AuditLog.create({
    actor: admin._id, actorEmail: admin.email,
    action: 'property.rejected', entityId: property._id,
    before, after: { status: 'rejected', reason },
  });

  return ok({ item: property.toAdmin() });
});
