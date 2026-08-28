import { NextResponse } from 'next/server';

/** An error carrying an HTTP status. Anything else becomes a 500. */
export class ApiError extends Error {
  constructor(status, message, details) {
    super(message);
    this.status = status;
    this.details = details;
  }
  static badRequest(m, d) { return new ApiError(400, m || 'Bad request', d); }
  static unauthorized(m) { return new ApiError(401, m || 'Sign in to continue.'); }
  static forbidden(m) { return new ApiError(403, m || 'You do not have access to this.'); }
  static notFound(m) { return new ApiError(404, m || 'Not found.'); }
  static conflict(m) { return new ApiError(409, m || 'Already exists.'); }
  static tooMany(m) { return new ApiError(429, m || 'Too many requests.'); }
}

export const ok = (data, status = 200) => NextResponse.json(data, { status });

/**
 * Turns any thrown value into a clean JSON response.
 * Mongoose validation and duplicate-key errors get readable messages;
 * genuine bugs are logged server-side and hidden from the client.
 */
export function fail(err) {
  if (err instanceof ApiError) {
    return NextResponse.json(
      { error: err.message, ...(err.details ? { details: err.details } : {}) },
      { status: err.status }
    );
  }

  if (err?.name === 'ValidationError') {
    return NextResponse.json(
      {
        error: 'Please correct the highlighted fields.',
        details: Object.values(err.errors).map((e) => ({ field: e.path, message: e.message })),
      },
      { status: 400 }
    );
  }

  if (err?.name === 'CastError') {
    return NextResponse.json({ error: `Invalid ${err.path}.` }, { status: 400 });
  }

  if (err?.code === 11000) {
    const field = Object.keys(err.keyValue || {})[0] || 'value';
    return NextResponse.json({ error: `That ${field} is already registered.` }, { status: 409 });
  }

  console.error('  Unhandled API error:', err);
  const isProd = process.env.NODE_ENV === 'production';
  return NextResponse.json(
    { error: isProd ? 'Something went wrong on our end.' : String(err?.message || err) },
    { status: 500 }
  );
}

/** Wraps a route handler so every throw lands in `fail()`. */
export const handler = (fn) => async (req, ctx) => {
  try { return await fn(req, ctx); }
  catch (err) { return fail(err); }
};
