import mongoose from 'mongoose';

export const STAGES = ['new', 'contacted', 'site-visit', 'negotiating', 'closed', 'lost'];

const enquirySchema = new mongoose.Schema(
  {
    property: { type: mongoose.Schema.Types.ObjectId, ref: 'Property', index: true },
    propertyName: String,          // kept so the record survives listing deletion
    from: { type: mongoose.Schema.Types.ObjectId, ref: 'User', index: true },

    // Captured from the form directly so guests can enquire too.
    name: { type: String, required: true, trim: true },
    email: { type: String, required: true, lowercase: true, trim: true },
    mobile: { type: String, trim: true },
    company: { type: String, trim: true },
    subject: { type: String, trim: true },
    message: { type: String, trim: true, maxlength: 2000 },

    stage: { type: String, enum: STAGES, default: 'new', index: true },
    assignedTo: { type: mongoose.Schema.Types.ObjectId, ref: 'User' },
  },
  { timestamps: true }
);

enquirySchema.index({ property: 1, createdAt: -1 });

export default mongoose.models.Enquiry || mongoose.model('Enquiry', enquirySchema);
