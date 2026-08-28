import { connectDB } from '@/lib/db';
import { ok, handler } from '@/lib/apiResponse';

export const dynamic = 'force-dynamic';

/** Quick check that the app is up and the database is reachable. */
export const GET = handler(async () => {
  let database = 'disconnected';
  try { await connectDB(); database = 'connected'; }
  catch (e) { database = `error: ${e.message.split('\n')[0]}`; }

  return ok({ status: 'ok', database, time: new Date().toISOString() });
});
