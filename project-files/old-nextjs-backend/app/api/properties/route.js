import { z } from 'zod';
import { connectDB } from '@/lib/db';
import Property from '@/lib/models/Property';
import AuditLog from '@/lib/models/AuditLog';
import { ok, handler } from '@/lib/apiResponse';
import { parse, strip } from '@/lib/validate';
import { requireUser, getUser } from '@/lib/auth';

export const dynamic = 'force-dynamic';

/* ── GET /api/properties ──────────────────────────────────────
   Public feed. Only ever returns approved listings — a pending or
   rejected property is invisible here regardless of query params. */
export const GET = handler(async (req) => {
  await connectDB();
  const q = new URL(req.url).searchParams;

  const page = Math.max(1, parseInt(q.get('page') || '1', 10));
  const limit = Math.min(60, Math.max(1, parseInt(q.get('limit') || '12', 10)));

  const filter = { status: 'approved' };

  const city = q.get('city');
  if (city) filter.city = new RegExp(city.trim().replace(/[.*+?^${}()|[\]\]/g, '\$&'), 'i');
  if (q.get('type')) filter.type = q.get('type');
  if (q.get('grade')) filter.grade = q.get('grade');
  if (q.get('minArea')) filter.area = { $gte: Number(q.get('minArea')) };

  const minRate = q.get('minRate');
  const maxRate = q.get('maxRate');
  if (minRate || maxRate) {
    filter.rate = {};
    if (minRate) filter.rate.$gte = Number(minRate);
    if (maxRate) filter.rate.$lte = Number(maxRate);
  }
  if (q.get('q')) filter.$text = { $search: q.get('q') };

  const sorts = {
    'rate-asc': { rate: 1 },
    'rate-desc': { rate: -1 },
    'area-desc': { area: -1 },
    newest: { createdAt: -1 },
  };

  const [items, total] = await Promise.all([
    Property.find(filter)
      .sort(sorts[q.get('sort')] || sorts.newest)
      .skip((page - 1) * limit)
      .limit(limit),
    Property.countDocuments(filter),
  ]);

  return ok({
    items: items.map((p) => p.toPublic()),
    total,
    page,
    limit,
    pages: Math.max(1, Math.ceil(total / limit)),
  });
});

/* ── POST /api/properties ─────────────────────────────────────
   Owner submits a listing. Always lands as "pending": status is
   stripped from the body so it cannot be self-approved. */
const createSchema = z.object({
  name: z.string().trim().min(1, 'Property title is required').max(160),
  description: z.string().trim().max(5000).optional(),
  type: z.string().optional(),
  grade: z.string().optional(),
  listingType: z.enum(['rent', 'sale', 'bts']).optional(),
  city: z.string().trim().optional(),
  locality: z.string().trim().optional(),
  state: z.string().trim().optional(),
  pincode: z.string().trim().regex(/^\d{6}$/, 'PIN code must be 6 digits').optional().or(z.literal('')),
  address: z.string().trim().max(500).optional(),
  rate: z.coerce.number().positive('Rate must be greater than zero'),
  area: z.coerce.number().positive('Area must be greater than zero'),
  depositMonths: z.coerce.number().min(0).max(24).optional(),
  specs: z.object({
    clearHeight: z.coerce.number().optional(),
    loadingDocks: z.coerce.number().optional(),
    power: z.coerce.number().optional(),
    features: z.array(z.string()).optional(),
  }).partial().optional(),
  // Media uploaded first via POST /api/upload, then attached here.
  images: z.array(z.object({
    url: z.string(),
    publicId: z.string().optional(),
    storage: z.string().optional(),
    width: z.number().optional(),
    height: z.number().optional(),
    bytes: z.number().optional(),
    format: z.string().optional(),
    caption: z.string().optional(),
    isPrimary: z.boolean().optional(),
  })).optional(),
});

export const POST = handler(async (req) => {
  await connectDB();
  const user = await requireUser(req);

  const body = strip(
    await req.json(),
    'status', 'isVerified', 'approvedBy', 'approvedAt', 'owner', 'views', 'enquiryCount'
  );
  const data = parse(createSchema, body);

  const property = await Property.create({
    ...data,
    pincode: data.pincode || undefined,
    owner: user._id,
    ownerName: user.name,
    ownerEmail: user.email,
    status: 'pending',
    isVerified: false,
  });

  await AuditLog.create({
    actor: user._id,
    actorEmail: user.email,
    action: 'property.submitted',
    entityId: property._id,
    after: { name: property.name, rate: property.rate },
    ip: req.headers.get('x-forwarded-for') || undefined,
  });

  return ok({ item: property.toPublic() }, 201);
});
