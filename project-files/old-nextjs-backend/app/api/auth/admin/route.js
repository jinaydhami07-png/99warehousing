import { z } from 'zod';
import { connectDB } from '@/lib/db';
import User from '@/lib/models/User';
import { ok, handler, ApiError } from '@/lib/apiResponse';
import { parse } from '@/lib/validate';
import { issueSession } from '@/lib/auth';

const schema = z.object({ passkey: z.string().min(1, 'Passkey is required') });

/**
 * Admin passkey sign-in. Verified here on the server — the value in
 * ADMIN_PASSKEY is never sent to the browser, so it can't be read
 * from page source the way a client-side check could.
 */
export const POST = handler(async (req) => {
  const expected = process.env.ADMIN_PASSKEY;
  if (!expected?.trim()) {
    throw new Error('ADMIN_PASSKEY is not set. Add it to .env.local (section 3).');
  }

  const { passkey } = parse(schema, await req.json());
  if (passkey !== expected) throw ApiError.unauthorized('Incorrect admin passkey.');

  await connectDB();

  // Bootstrap one admin identity so audit entries have a real actor.
  let admin = await User.findOne({ role: 'admin' });
  if (!admin) {
    admin = await User.create({
      name: 'BPSF Admin',
      email: 'admin@buypersqft.local',
      role: 'admin',
      isVerified: true,
    });
  }

  return ok(issueSession(admin));
});
