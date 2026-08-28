import { connectDB } from '@/lib/db';
import Property from '@/lib/models/Property';
import AuditLog from '@/lib/models/AuditLog';
import { ok, handler, ApiError } from '@/lib/apiResponse';
import { strip } from '@/lib/validate';
import { getUser, requireUser, assertOwnership } from '@/lib/auth';
import { deleteFile } from '@/lib/storage';

export const dynamic = 'force-dynamic';

async function load(id) {
  const property = await Property.findById(id);
  if (!property) throw ApiError.notFound('Property not found.');
  return property;
}

/* ── GET /api/properties/:id ── */
export const GET = handler(async (req, { params }) => {
  await connectDB();
  const property = await load(params.id);
  const user = await getUser(req);

  const isOwner = user && property.owner?.toString() === user._id.toString();
  const isAdmin = user?.role === 'admin';

  // Unapproved listings are invisible to everyone but their owner and admins,
  // and we 404 rather than 403 so their existence isn't revealed.
  if (property.status !== 'approved' && !isOwner && !isAdmin) {
    throw ApiError.notFound('Property not found.');
  }

  // Fire-and-forget: a failed counter must never fail the page load.
  Property.updateOne({ _id: property._id }, { $inc: { views: 1 } }).catch(() => {});

  return ok({ item: isAdmin ? property.toAdmin() : property.toPublic() });
});

/* ── PATCH /api/properties/:id ──
   An owner's edit sends the listing back to pending for re-review;
   an admin's edit does not. */
export const PATCH = handler(async (req, { params }) => {
  await connectDB();
  const user = await requireUser(req);
  const property = await load(params.id);
  assertOwnership(user, property);

  const patch = strip(await req.json(), 'owner', 'approvedBy', 'approvedAt', 'views', 'enquiryCount');
  const before = { name: property.name, rate: property.rate, status: property.status };

  Object.assign(property, patch);

  if (user.role !== 'admin') {
    property.status = 'pending';
    property.isVerified = false;
  }

  await property.save();

  await AuditLog.create({
    actor: user._id,
    actorEmail: user.email,
    action: 'property.edited',
    entityId: property._id,
    before,
    after: { name: property.name, rate: property.rate, status: property.status },
  });

  return ok({ item: property.toPublic() });
});

/* ── DELETE /api/properties/:id ── */
export const DELETE = handler(async (req, { params }) => {
  await connectDB();
  const user = await requireUser(req);
  const property = await load(params.id);
  assertOwnership(user, property);

  // Remove stored media so deleted listings don't leave orphaned files.
  await Promise.all([
    ...(property.images || []).map(deleteFile),
    property.floorPlan ? deleteFile(property.floorPlan) : null,
    ...(property.documents || []).map(deleteFile),
  ].filter(Boolean));

  await property.deleteOne();

  await AuditLog.create({
    actor: user._id,
    actorEmail: user.email,
    action: 'property.deleted',
    entityId: params.id,
    before: { name: property.name },
  });

  return ok({ ok: true });
});
