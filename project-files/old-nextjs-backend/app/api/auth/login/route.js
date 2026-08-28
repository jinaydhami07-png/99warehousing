import { z } from 'zod';
import { connectDB } from '@/lib/db';
import User from '@/lib/models/User';
import { ok, handler, ApiError } from '@/lib/apiResponse';
import { parse } from '@/lib/validate';
import { issueSession } from '@/lib/auth';

const MAX_ATTEMPTS = 5;
const LOCK_MINUTES = 15;

const schema = z.object({
  email: z.string().trim().toLowerCase().email('Enter a valid email address'),
  password: z.string().min(1, 'Password is required'),
});

export const POST = handler(async (req) => {
  await connectDB();
  const { email, password } = parse(schema, await req.json());

  const user = await User.findOne({ email }).select('+password +failedLoginAttempts +lockedUntil');

  // Identical message whether or not the account exists — no enumeration.
  if (!user) throw ApiError.unauthorized('Incorrect email or password.');

  if (user.isLocked()) {
    const mins = Math.ceil((user.lockedUntil - Date.now()) / 60000);
    throw ApiError.tooMany(`Too many failed attempts. Try again in ${mins} minute(s).`);
  }

  if (!(await user.comparePassword(password))) {
    user.failedLoginAttempts = (user.failedLoginAttempts || 0) + 1;
    if (user.failedLoginAttempts >= MAX_ATTEMPTS) {
      user.lockedUntil = new Date(Date.now() + LOCK_MINUTES * 60 * 1000);
      user.failedLoginAttempts = 0;
    }
    await user.save();
    throw ApiError.unauthorized('Incorrect email or password.');
  }

  if (!user.isActive) throw ApiError.forbidden('This account has been disabled.');

  user.failedLoginAttempts = 0;
  user.lockedUntil = undefined;
  user.lastLoginAt = new Date();
  await user.save();

  return ok(issueSession(user));
});
