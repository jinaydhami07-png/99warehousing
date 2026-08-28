import { z } from 'zod';
import { connectDB } from '@/lib/db';
import Enquiry from '@/lib/models/Enquiry';
import Property from '@/lib/models/Property';
import { ok, handler } from '@/lib/apiResponse';
import { parse } from '@/lib/validate';
import { getUser } from '@/lib/auth';

export const dynamic = 'force-dynamic';

const schema = z.object({
  propertyId: z.string().optional(),
  name: z.string().trim().min(1, 'Your name is required'),
  email: z.string().trim().toLowerCase().email('Enter a valid email address'),
  mobile: z.string().trim().optional(),
  company: z.string().trim().optional(),
  subject: z.string().trim().optional(),
  message: z.string().trim().max(2000).optional(),
});

/* Public: guests can enquire without an account. */
export const POST = handler(async (req) => {
  await connectDB();
  const data = parse(schema, await req.json());
  const user = await getUser(req);

  let property = null;
  if (data.propertyId) {
    property = await Property.findById(data.propertyId).catch(() => null);
  }

  const enquiry = await Enquiry.create({
    property: property?._id,
    propertyName: property?.name || data.subject || 'General enquiry',
    from: user?._id,
    name: data.name,
    email: data.email,
    mobile: data.mobile,
    company: data.company,
    subject: data.subject,
    message: data.message,
  });

  if (property) {
    await Property.updateOne({ _id: property._id }, { $inc: { enquiryCount: 1 } });
  }

  return ok({ ok: true, id: enquiry._id.toString() }, 201);
});
