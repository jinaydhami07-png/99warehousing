import { connectDB } from '@/lib/db';
import Property from '@/lib/models/Property';
import AuditLog from '@/lib/models/AuditLog';
import { ok, handler, ApiError } from '@/lib/apiResponse';
import { strip } from '@/lib/validate';
import { requireAdmin } from '@/lib/auth';
import { deleteFile } from '@/lib/storage';

export const dynamic = 'force-dynamic';

export const PATCH = handler(async (req, { params }) => {
  await connectDB();
  const admin = await requireAdmin(req);

  const property = await Property.findById(params.id);
  if (!property) throw ApiError.notFound('Property not found.');

  const patch = strip(await req.json(), 'owner');
  const before = { name: property.name, rate: property.rate, status: property.status };

  Object.assign(property, patch);
  if (patch.status) property.isVerified = patch.status === 'approved';
  await property.save();

  await AuditLog.create({
    actor: admin._id, actorEmail: admin.email,
    action: 'property.edited', entityId: property._id,
    before, after: { name: property.name, rate: property.rate, status: property.status },
  });

  return ok({ item: property.toAdmin() });
});

export const DELETE = handler(async (req, { params }) => {
  await connectDB();
  const admin = await requireAdmin(req);

  const property = await Property.findById(params.id);
  if (!property) throw ApiError.notFound('Property not found.');

  await Promise.all([
    ...(property.images || []).map(deleteFile),
    property.floorPlan ? deleteFile(property.floorPlan) : null,
  ].filter(Boolean));

  await property.deleteOne();

  await AuditLog.create({
    actor: admin._id, actorEmail: admin.email,
    action: 'property.deleted', entityId: params.id,
    before: { name: property.name },
  });

  return ok({ ok: true });
});
