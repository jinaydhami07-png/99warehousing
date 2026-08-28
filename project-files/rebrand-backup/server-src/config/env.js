/**
 * Environment loading and validation.
 *
 * Every environment variable is read HERE and nowhere else, so the rest of
 * the codebase imports a typed, validated config object rather than reaching
 * into `process.env` at arbitrary call sites. That keeps secrets in one
 * auditable place and makes a missing variable a startup failure with a
 * clear message, instead of an undefined-shaped bug at 3am.
 */
'use strict';

const path = require('path');
const { z } = require('zod');

require('dotenv').config({ path: path.join(__dirname, '..', '..', '.env') });

/** Treats blanks and leftover `<< PASTE … >>` placeholders as unset. */
const optional = (schema) =>
  z.preprocess((v) => {
    if (typeof v !== 'string') return undefined;
    const t = v.trim();
    if (!t || t.includes('<<') || t.includes('>>')) return undefined;
    return t;
  }, schema.optional());

const required = (name) =>
  z.preprocess(
    (v) => (typeof v === 'string' ? v.trim() : v),
    z.string({ required_error: `${name} is required` }).min(1, `${name} must not be empty`)
  );

const csv = (v) =>
  String(v || '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean);

const schema = z.object({
  NODE_ENV: z.enum(['development', 'test', 'production']).default('development'),
  PORT: z.coerce.number().int().positive().default(5000),

  /* Never hardcoded, never committed — see .env.example */
  MONGODB_URI: required('MONGODB_URI'),
  MONGO_POOL_SIZE: z.coerce.number().int().positive().max(100).default(10),
  MONGO_MIN_POOL_SIZE: z.coerce.number().int().nonnegative().default(2),

  /* Comma-separated list. CORS is an allow-list, never `*` with credentials. */
  CORS_ORIGINS: z.string().default('http://localhost:5000'),

  JWT_ACCESS_SECRET: required('JWT_ACCESS_SECRET'),
  JWT_REFRESH_SECRET: required('JWT_REFRESH_SECRET'),
  JWT_ACCESS_EXPIRES: z.string().default('15m'),
  JWT_REFRESH_EXPIRES: z.string().default('7d'),

  RATE_LIMIT_WINDOW_MIN: z.coerce.number().int().positive().default(15),
  RATE_LIMIT_MAX: z.coerce.number().int().positive().default(300),
  AUTH_RATE_LIMIT_MAX: z.coerce.number().int().positive().default(10),

  BCRYPT_ROUNDS: z.coerce.number().int().min(10).max(15).default(12),
  LOG_LEVEL: z.enum(['fatal', 'error', 'warn', 'info', 'debug', 'trace']).default('info'),
  TRUST_PROXY: z.coerce.number().int().nonnegative().default(0),
  BODY_LIMIT: z.string().default('100kb'),

  ADMIN_PASSKEY: optional(z.string()),
});

const parsed = schema.safeParse(process.env);

if (!parsed.success) {
  console.error('\n  ✖ Invalid environment configuration:\n');
  for (const issue of parsed.error.issues) {
    console.error(`     • ${issue.path.join('.')}: ${issue.message}`);
  }
  console.error('\n  Copy server/.env.example to server/.env and fill in the values.\n');
  process.exit(1);
}

const raw = parsed.data;

/* Fail fast on footguns that are legal strings but wrong in practice. */
if (raw.JWT_ACCESS_SECRET === raw.JWT_REFRESH_SECRET) {
  console.error('\n  ✖ JWT_ACCESS_SECRET and JWT_REFRESH_SECRET must differ.\n');
  process.exit(1);
}

if (raw.NODE_ENV === 'production') {
  const weak = [raw.JWT_ACCESS_SECRET, raw.JWT_REFRESH_SECRET].filter((s) => s.length < 32);
  if (weak.length) {
    console.error('\n  ✖ JWT secrets must be at least 32 characters in production.\n');
    process.exit(1);
  }
  if (csv(raw.CORS_ORIGINS).some((o) => o === '*')) {
    console.error('\n  ✖ CORS_ORIGINS cannot be "*" in production.\n');
    process.exit(1);
  }
}

const env = Object.freeze({
  nodeEnv: raw.NODE_ENV,
  isProd: raw.NODE_ENV === 'production',
  isTest: raw.NODE_ENV === 'test',
  port: raw.PORT,
  bodyLimit: raw.BODY_LIMIT,
  trustProxy: raw.TRUST_PROXY,
  logLevel: raw.LOG_LEVEL,

  mongo: {
    uri: raw.MONGODB_URI,
    maxPoolSize: raw.MONGO_POOL_SIZE,
    minPoolSize: raw.MONGO_MIN_POOL_SIZE,
  },

  corsOrigins: csv(raw.CORS_ORIGINS),

  jwt: {
    accessSecret: raw.JWT_ACCESS_SECRET,
    refreshSecret: raw.JWT_REFRESH_SECRET,
    accessExpires: raw.JWT_ACCESS_EXPIRES,
    refreshExpires: raw.JWT_REFRESH_EXPIRES,
  },

  rateLimit: {
    windowMs: raw.RATE_LIMIT_WINDOW_MIN * 60 * 1000,
    max: raw.RATE_LIMIT_MAX,
    authMax: raw.AUTH_RATE_LIMIT_MAX,
  },

  bcryptRounds: raw.BCRYPT_ROUNDS,
  adminPasskey: raw.ADMIN_PASSKEY,
});

module.exports = env;
