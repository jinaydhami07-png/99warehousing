import { z } from 'zod';
import { connectDB } from '@/lib/db';
import User from '@/lib/models/User';
import AuditLog from '@/lib/models/AuditLog';
import { ok, handler, ApiError } from '@/lib/apiResponse';
import { parse } from '@/lib/validate';
import { requireAdmin } from '@/lib/auth';

export const dynamic = 'force-dynamic';

const schema = z.object({
  role: z.enum(['buyer', 'owner', 'agency', 'admin']).optional(),
  isActive: z.boolean().optional(),
});

export const PATCH = handler(async (req, { params }) => {
  await connectDB();
  const admin = await requireAdmin(req);

  const patch = parse(schema, await req.json());

  const user = await User.findById(params.id);
  if (!user) throw ApiError.notFound('User not found.');

  // Stop an admin locking themselves out of their own console.
  if (user._id.toString() === admin._id.toString()) {
    if (patch.role && patch.role !== 'admin') {
      throw ApiError.badRequest('You cannot remove your own admin role.');
    }
    if (patch.isActive === false) {
      throw ApiError.badRequest('You cannot deactivate your own account.');
    }
  }

  const before = { role: user.role, isActive: user.isActive };
  if (patch.role !== undefined) user.role = patch.role;
  if (patch.isActive !== undefined) user.isActive = patch.isActive;
  await user.save();

  await AuditLog.create({
    actor: admin._id, actorEmail: admin.email,
    action: 'user.updated', entity: 'user', entityId: user._id,
    before, after: { role: user.role, isActive: user.isActive },
  });

  return ok({ item: user.toPublic() });
});
