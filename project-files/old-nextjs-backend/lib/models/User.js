import mongoose from 'mongoose';
import bcrypt from 'bcryptjs';

export const ROLES = ['buyer', 'owner', 'agency', 'admin'];

const userSchema = new mongoose.Schema(
  {
    name: { type: String, required: [true, 'Name is required'], trim: true, maxlength: 120 },
    email: {
      type: String,
      required: [true, 'Email is required'],
      unique: true,
      lowercase: true,
      trim: true,
      index: true,
      match: [/^\S+@\S+\.\S+$/, 'Enter a valid email address'],
    },
    mobile: { type: String, trim: true },
    company: { type: String, trim: true },

    // Not required — Google accounts never set one.
    password: { type: String, minlength: 8, select: false },

    role: { type: String, enum: ROLES, default: 'buyer', index: true },
    avatar: String,

    // ── Google sign-in ──
    googleId: { type: String, index: true, sparse: true },
    authProvider: { type: String, enum: ['local', 'google'], default: 'local' },

    isVerified: { type: Boolean, default: false },
    isActive: { type: Boolean, default: true },

    // ── Brute-force protection ──
    failedLoginAttempts: { type: Number, default: 0, select: false },
    lockedUntil: { type: Date, select: false },
    lastLoginAt: Date,

    // Bumped on logout so old refresh tokens stop working.
    tokenVersion: { type: Number, default: 0 },
  },
  { timestamps: true }
);

userSchema.pre('save', async function (next) {
  if (!this.isModified('password') || !this.password) return next();
  this.password = await bcrypt.hash(this.password, 12);
  next();
});

userSchema.methods.comparePassword = function (plain) {
  if (!this.password) return Promise.resolve(false); // Google-only account
  return bcrypt.compare(plain, this.password);
};

userSchema.methods.isLocked = function () {
  return !!(this.lockedUntil && this.lockedUntil > new Date());
};

userSchema.methods.toPublic = function () {
  return {
    id: this._id.toString(),
    name: this.name,
    email: this.email,
    mobile: this.mobile,
    company: this.company,
    role: this.role,
    avatar: this.avatar,
    isVerified: this.isVerified,
    authProvider: this.authProvider,
    createdAt: this.createdAt,
  };
};

export default mongoose.models.User || mongoose.model('User', userSchema);
