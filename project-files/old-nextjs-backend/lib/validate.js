import { ApiError } from './apiResponse';

/**
 * Runs a Zod schema against a value and converts failures into a 400
 * with per-field messages the forms can display inline.
 */
export function parse(schema, value) {
  const result = schema.safeParse(value);
  if (result.success) return result.data;
  throw ApiError.badRequest(
    'Please correct the highlighted fields.',
    result.error.issues.map((i) => ({ field: i.path.join('.') || 'form', message: i.message }))
  );
}

/**
 * Removes keys a client must never set. Without this a crafted POST body
 * could smuggle role:'admin' or status:'approved' straight into the model.
 */
export function strip(obj, ...fields) {
  const out = { ...obj };
  fields.forEach((f) => delete out[f]);
  return out;
}
