import { z } from 'zod';
import { connectDB } from '@/lib/db';
import User from '@/lib/models/User';
import { ok, handler, ApiError } from '@/lib/apiResponse';
import { parse, strip } from '@/lib/validate';
import { issueSession } from '@/lib/auth';

const schema = z.object({
  name: z.string().trim().min(1, 'Name is required').max(120),
  email: z.string().trim().toLowerCase().email('Enter a valid email address'),
  password: z.string().min(8, 'Password must be at least 8 characters'),
  mobile: z.string().trim().optional(),
  company: z.string().trim().optional(),
  // `accountType`, never `role` — so a crafted body cannot grant itself admin.
  accountType: z.enum(['buyer', 'owner', 'agency']).optional(),
});

export const POST = handler(async (req) => {
  await connectDB();

  const body = strip(await req.json(), 'role', 'isVerified', 'isActive', 'googleId', 'tokenVersion');
  const data = parse(schema, body);

  if (await User.findOne({ email: data.email })) {
    throw ApiError.conflict('That email is already registered.');
  }

  const user = await User.create({
    name: data.name,
    email: data.email,
    password: data.password,
    mobile: data.mobile,
    company: data.company,
    role: data.accountType || 'buyer',
  });

  return ok(issueSession(user), 201);
});
