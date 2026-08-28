/**
 * Seeds MongoDB with demo properties and an admin user.
 *
 *   npm run seed         → adds anything missing, leaves existing data alone
 *   npm run seed:fresh   → wipes properties/enquiries/audit first, then seeds
 *
 * Safe to re-run: matches on property name so it never duplicates rows.
 */
require('dotenv').config({ path: '.env.local' });

const mongoose = require('mongoose');

const PROPERTIES = [
  { name: 'Bhiwandi Logistics Hub — Block C', type: 'Warehouse', grade: 'Grade A', city: 'Mumbai', locality: 'Bhiwandi', rate: 28, area: 85000, status: 'approved', ownerName: 'Deepak Patel', specs: { clearHeight: 12, loadingDocks: 8, power: 500 },
    description: "Premium Grade A warehouse in MIDC Bhiwandi, India's largest inland logistics cluster. 12m clear height with column-free 24m spans, suitable for high-rack storage up to 12 pallet levels. NH-48 within 4km, JNPT under 45 minutes." },
  { name: 'Pune Cold Storage Facility — MIDC Chakan', type: 'Cold Storage', grade: 'Cold Chain', city: 'Pune', locality: 'Chakan', rate: 42, area: 32000, status: 'approved', ownerName: 'Sunita Rao', specs: { clearHeight: 9, loadingDocks: 4, power: 750 },
    description: 'Multi-temperature cold chain facility with zones from −25°C to +15°C. Blast freezing capability, insulated dock shelters, full redundancy on refrigeration. Suits pharma, dairy and quick-commerce.' },
  { name: 'GMR Aero Warehousing Complex — T3', type: 'Warehouse', grade: 'Grade A', city: 'Delhi', locality: 'Aerocity', rate: 35, area: 120000, status: 'approved', ownerName: 'GMR Estates', specs: { clearHeight: 15, loadingDocks: 6, power: 900 },
    description: 'LEED Gold certified airside warehousing beside Delhi International Airport T3. Bonded warehouse capability and on-site customs clearance. Best suited to air-freight forwarders and high-value electronics.' },
  { name: 'Gurgaon Industrial Estate — Shed 14B', type: 'Industrial Shed', grade: 'Grade B', city: 'Gurugram', locality: 'Sector 37', rate: 18, area: 22500, status: 'approved', ownerName: 'Harpreet Singh', specs: { clearHeight: 8, loadingDocks: 2, power: 250 },
    description: 'Independent industrial shed on a gated estate in Sector 37. Suits light manufacturing, assembly or regional distribution. Three-phase power, 250 kVA sanctioned load.' },
  { name: 'Hoskote Logistics Park — Phase II', type: 'Logistics Park', grade: 'Grade A', city: 'Bengaluru', locality: 'Hoskote', rate: 31, area: 64000, status: 'approved', ownerName: 'Embassy Industrial', specs: { clearHeight: 13, loadingDocks: 10, power: 600 },
    description: 'Institutional-grade logistics park on NH-75 with dedicated truck court and 10 dock-levelled bays. ESFR sprinklers throughout. Flexible demising from 20,000 sq ft.' },
  { name: 'Andheri Dark Store Hub — Q-Commerce Ready', type: 'Dark Store', grade: 'Grade A', city: 'Mumbai', locality: 'Andheri East', rate: 55, area: 4800, status: 'approved', ownerName: 'Quick Retail Pvt Ltd', specs: { clearHeight: 6, loadingDocks: 2, power: 200 },
    description: 'Purpose-fitted dark store 8 minutes from Andheri station. Chilled zone, two-wheeler loading bay for 40+ riders, fit-out already compliant with major q-commerce operator specs.' },
  { name: 'Sriperumbudur Auto Ancillary Shed', type: 'Industrial Shed', grade: 'Grade B', city: 'Chennai', locality: 'Sriperumbudur', rate: 19, area: 41000, status: 'approved', ownerName: 'TN Industrial Estates', specs: { clearHeight: 10, loadingDocks: 5, power: 400 },
    description: 'Auto-ancillary shed inside the Sriperumbudur belt, minutes from major OEM plants. EOT crane provision, heavy floor loading, dedicated trailer parking.' },
  { name: 'Nashik Cold Chain Facility', type: 'Cold Storage', grade: 'Cold Chain', city: 'Nashik', locality: 'Sinnar MIDC', rate: 38, area: 18000, status: 'approved', ownerName: 'Agro Cold Pvt Ltd', specs: { clearHeight: 9, loadingDocks: 3, power: 700 },
    description: 'Controlled-atmosphere storage built for horticulture — grapes, onion, pomegranate. On-site pre-cooling and grading line, direct access to the Nashik–Mumbai corridor.' },
  { name: 'Luhari Logistics Yard — Block A', type: 'Logistics Park', grade: 'Grade B', city: 'Gurugram', locality: 'Luhari', rate: 16, area: 96000, status: 'approved', ownerName: 'North Logistics LLP', specs: { clearHeight: 11, loadingDocks: 12, power: 550 },
    description: 'Large-format yard on the KMP Expressway with 12 docks and generous trailer circulation. Priced for bulk storage and cross-dock rather than premium fit-out.' },
  { name: 'Hyderabad Pharma Grade Warehouse', type: 'Warehouse', grade: 'Grade A', city: 'Hyderabad', locality: 'Medchal', rate: 26, area: 55000, status: 'approved', ownerName: 'Genome Estates', specs: { clearHeight: 12, loadingDocks: 7, power: 480 },
    description: 'GMP-compliant warehousing with full temperature mapping and validation documentation. Built for pharmaceutical distribution serving the Genome Valley cluster.' },

  /* Awaiting review — these populate the admin approval queue */
  { name: 'Panvel Industrial Park — Plot 22', type: 'Industrial Land', grade: 'Land', city: 'Navi Mumbai', locality: 'Panvel', rate: 22, area: 80000, status: 'pending', ownerName: 'Ramesh Kulkarni',
    description: 'Freehold industrial plot with MIDC approval and 60m road frontage. Suitable for built-to-suit development. Water and power connections sanctioned.' },
  { name: 'Ahmedabad Textile Storage — Sanand', type: 'Warehouse', grade: 'Grade B', city: 'Ahmedabad', locality: 'Sanand', rate: 15, area: 38000, status: 'pending', ownerName: 'Sanand Warehousing Co', specs: { clearHeight: 9, loadingDocks: 4, power: 300 },
    description: 'Textile and general storage near the Sanand industrial belt. Competitive rate for bulk requirements above 20,000 sq ft.' },
  { name: 'Kolkata Riverside Distribution Centre', type: 'Warehouse', grade: 'Grade B', city: 'Kolkata', locality: 'Dankuni', rate: 17, area: 47000, status: 'pending', ownerName: 'Bengal Logistics', specs: { clearHeight: 10, loadingDocks: 5, power: 350 },
    description: 'Distribution centre at Dankuni with direct access to NH-19 and the Kolkata port road. Suits FMCG and e-commerce regional distribution for East India.' },

  /* Previously rejected */
  { name: 'Wagholi Storage Shed', type: 'Industrial Shed', grade: 'Grade C', city: 'Pune', locality: 'Wagholi', rate: 12, area: 9000, status: 'rejected', ownerName: 'Anon Seller', specs: { clearHeight: 6, loadingDocks: 1, power: 100 },
    rejectionReason: 'Ownership documents missing; quoted rate inconsistent with locality benchmark.',
    description: 'Small storage shed on the Pune–Nagar road.' },
];

async function main() {
  const uri = process.env.MONGODB_URI;
  if (!uri || !uri.trim()) {
    console.error('\n  MONGODB_URI is not set.');
    console.error('  Open .env.local (section 1) and paste your MongoDB connection string.\n');
    process.exit(1);
  }

  await mongoose.connect(uri, { serverSelectionTimeoutMS: 10000 });
  console.log(`  Connected to "${mongoose.connection.name}"`);

  // Models are ESM; define minimal equivalents here so this CommonJS
  // script can run standalone without a build step.
  const Property = mongoose.models.Property || mongoose.model('Property', new mongoose.Schema({}, { strict: false, timestamps: true, collection: 'properties' }));
  const User = mongoose.models.User || mongoose.model('User', new mongoose.Schema({}, { strict: false, timestamps: true, collection: 'users' }));
  const AuditLog = mongoose.models.AuditLog || mongoose.model('AuditLog', new mongoose.Schema({}, { strict: false, timestamps: true, collection: 'auditlogs' }));

  if (process.argv.includes('--wipe')) {
    console.log('  Wiping properties and audit log…');
    await Promise.all([Property.deleteMany({}), AuditLog.deleteMany({})]);
  }

  let admin = await User.findOne({ role: 'admin' });
  if (!admin) {
    admin = await User.create({
      name: 'BPSF Admin',
      email: 'admin@buypersqft.local',
      role: 'admin',
      isVerified: true,
      isActive: true,
      authProvider: 'local',
      tokenVersion: 0,
    });
    console.log('  Created admin user (sign in with ADMIN_PASSKEY, not a password)');
  }

  let created = 0;
  for (const p of PROPERTIES) {
    if (await Property.findOne({ name: p.name })) continue;   // idempotent
    const slugBase = p.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60);
    await Property.create({
      ...p,
      slug: `${slugBase}-${Math.random().toString(36).slice(2, 8)}`,
      listingType: 'rent',
      depositMonths: 3,
      images: [],
      owner: admin._id,
      ownerEmail: admin.email,
      isVerified: p.status === 'approved',
      approvedBy: p.status === 'approved' ? admin._id : undefined,
      approvedAt: p.status === 'approved' ? new Date() : undefined,
      views: 0,
      enquiryCount: 0,
    });
    created += 1;
  }

  const counts = {
    total: await Property.countDocuments(),
    pending: await Property.countDocuments({ status: 'pending' }),
    approved: await Property.countDocuments({ status: 'approved' }),
    rejected: await Property.countDocuments({ status: 'rejected' }),
  };

  console.log(`\n  Seed complete — ${created} new propert${created === 1 ? 'y' : 'ies'} inserted.`);
  console.log('  Database now holds:', counts, '\n');

  await mongoose.disconnect();
  process.exit(0);
}

main().catch((err) => {
  console.error('  Seed failed:', err.message);
  process.exit(1);
});
