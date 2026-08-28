import { NextResponse } from 'next/server';
import { connectDB } from '@/lib/db';
import User from '@/lib/models/User';
import { handler } from '@/lib/apiResponse';
import { googleConfigured, exchangeCode } from '@/lib/google';
import { signAccess, setRefreshCookie } from '@/lib/auth';

export const dynamic = 'force-dynamic';

/**
 * Step 2: Google redirects back here with a code. We swap it for the
 * user's profile, find-or-create the account, then hand our own token
 * to the page via the URL fragment (#token=…). A fragment is never sent
 * to the server, so it stays out of access logs; api.js reads it and
 * immediately strips it from the address bar.
 */
export const GET = handler(async (req) => {
  const base = process.env.NEXT_PUBLIC_APP_URL || '';
  const url = new URL(req.url);
  const code = url.searchParams.get('code');
  const error = url.searchParams.get('error');

  if (error || !code || !googleConfigured()) {
    return NextResponse.redirect(`${base}/login.html?error=google_failed`);
  }

  let profile;
  try { profile = await exchangeCode(code); }
  catch (e) {
    console.error('  Google token exchange failed:', e.message);
    return NextResponse.redirect(`${base}/login.html?error=google_failed`);
  }

  if (!profile?.email) {
    return NextResponse.redirect(`${base}/login.html?error=google_failed`);
  }

  await connectDB();
  const email = profile.email.toLowerCase();

  let user = await User.findOne({ googleId: profile.sub });

  // Same email already registered with a password → link, don't duplicate.
  if (!user) {
    user = await User.findOne({ email });
    if (user) {
      user.googleId = profile.sub;
      user.isVerified = true;
      if (!user.avatar && profile.picture) user.avatar = profile.picture;
      await user.save();
    }
  }

  if (!user) {
    user = await User.create({
      name: profile.name || email.split('@')[0],
      email,
      googleId: profile.sub,
      authProvider: 'google',
      isVerified: true,             // Google already verified the address
      avatar: profile.picture,
      role: 'buyer',
    });
  }

  if (!user.isActive) {
    return NextResponse.redirect(`${base}/login.html?error=account_disabled`);
  }

  user.lastLoginAt = new Date();
  await user.save();

  setRefreshCookie(user);
  return NextResponse.redirect(`${base}/login.html#token=${signAccess(user)}`);
});
