/**
 * MongoDB connection for Next.js.
 *
 * Next.js hot-reloads modules in development, which would otherwise open a
 * new connection on every file save until Mongo refuses them. The connection
 * is cached on `globalThis` so it survives reloads — this is the standard
 * Next.js + Mongoose pattern, not a workaround.
 */
import mongoose from 'mongoose';

const MONGODB_URI = process.env.MONGODB_URI;

let cached = globalThis._bpsfMongoose;
if (!cached) cached = globalThis._bpsfMongoose = { conn: null, promise: null };

export async function connectDB() {
  if (cached.conn) return cached.conn;

  if (!MONGODB_URI || !MONGODB_URI.trim()) {
    throw new Error(
      'MONGODB_URI is not set. Open .env.local and paste your MongoDB connection ' +
        'string on line 5. Get one free at https://cloud.mongodb.com'
    );
  }

  if (!cached.promise) {
    mongoose.set('strictQuery', true);
    cached.promise = mongoose
      .connect(MONGODB_URI, {
        // Fail fast with a clear message rather than hanging the request.
        serverSelectionTimeoutMS: 10000,
        maxPoolSize: 10,
      })
      .then((m) => {
        console.log(`  MongoDB connected — db "${m.connection.name}"`);
        return m;
      })
      .catch((err) => {
        // Reset so the next request retries instead of reusing a dead promise.
        cached.promise = null;
        throw new Error(
          `Could not connect to MongoDB: ${err.message}\n` +
            '  • Check MONGODB_URI in .env.local\n' +
            '  • On Atlas, confirm your IP is allowed under Network Access'
        );
      });
  }

  cached.conn = await cached.promise;

  /* First run on an empty database: load the demo listings so the site has
     content without anyone running a seed command. No-ops if data exists. */
  const { autoSeed } = await import('./autoseed');
  await autoSeed();

  return cached.conn;
}

export default connectDB;
