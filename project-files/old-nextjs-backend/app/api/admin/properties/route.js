import { z } from 'zod';
import { connectDB } from '@/lib/db';
import Property from '@/lib/models/Property';
import AuditLog from '@/lib/models/AuditLog';
import { ok, handler } from '@/lib/apiResponse';
import { parse, strip } from '@/lib/validate';
import { requireAdmin } from '@/lib/auth';

export const dynamic = 'force-dynamic';

/* Unlike the public feed, this returns every status. */
export const GET = handler(async (req) => {
  await connectDB();
  await requireAdmin(req);

  const q = new URL(req.url).searchParams;
  const filter = {};
  if (q.get('status') && q.get('status') !== 'all') filter.status = q.get('status');
  if (q.get('q')) filter.$text = { $search: q.get('q') };

  const items = await Property.find(filter).sort({ createdAt: -1 }).limit(500);
  return ok({ items: items.map((p) => p.toAdmin()) });
});

const createSchema = z.object({
  name: z.string().trim().min(1, 'Property title is required'),
  rate: z.coerce.number().positive('Rate must be greater than zero'),
  area: z.coerce.number().positive('Area must be greater than zero'),
  description: z.string().trim().max(5000).optional(),
  type: z.string().optional(),
  grade: z.string().optional(),
  city: z.string().trim().optional(),
  locality: z.string().trim().optional(),
  ownerName: z.string().trim().optional(),
  status: z.enum(['draft', 'pending', 'approved', 'rejected']).optional(),
  images: z.array(z.any()).optional(),
});

/* Admin creates a listing directly — publishes immediately by default. */
export const POST = handler(async (req) => {
  await connectDB();
  const admin = await requireAdmin(req);

  const data = parse(createSchema, strip(await req.json(), 'owner'));
  const status = data.status || 'approved';

  const property = await Property.create({
    ...data,
    owner: admin._id,
    ownerName: data.ownerName || 'Added by admin',
    ownerEmail: admin.email,
    status,
    isVerified: status === 'approved',
    approvedBy: status === 'approved' ? admin._id : undefined,
    approvedAt: status === 'approved' ? new Date() : undefined,
  });

  await AuditLog.create({
    actor: admin._id, actorEmail: admin.email,
    action: 'property.created', entityId: property._id,
    after: { name: property.name },
  });

  return ok({ item: property.toAdmin() }, 201);
});
