import { NextResponse } from 'next/server';
import { handler } from '@/lib/apiResponse';
import { googleConfigured, googleAuthUrl } from '@/lib/google';

export const dynamic = 'force-dynamic';

/**
 * Step 1 of Google sign-in: bounce the browser to Google's consent screen.
 * OAuth requires a full-page redirect — it cannot run inside fetch().
 */
export const GET = handler(async () => {
  if (!googleConfigured()) {
    const base = process.env.NEXT_PUBLIC_APP_URL || '';
    return NextResponse.redirect(`${base}/login.html?error=google_not_configured`);
  }
  return NextResponse.redirect(googleAuthUrl());
});
