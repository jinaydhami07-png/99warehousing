<?php
/**
 * The sample catalogue.
 *
 * Identical to the dataset in the Node build's src/jobs/seed.js, so the two
 * backends can be compared side by side against the same listings.
 *
 * Statuses are spread on purpose: ten approved listings fill the public
 * feed, three pending ones populate the admin approval queue, and one
 * rejected listing exercises that state in the admin table and the
 * rejection banner on the detail page.
 */
declare(strict_types=1);

return [
    /* ── Approved: the public feed ── */
    [
        'name' => 'Bhiwandi Logistics Hub — Block C',
        'type' => 'Warehouse', 'grade' => 'Grade A', 'city' => 'Mumbai', 'locality' => 'Bhiwandi',
        'rate' => 28, 'area' => 85000, 'status' => 'approved',
        'specs' => ['clearHeight' => 12, 'loadingDocks' => 8, 'power' => 500, 'features' => ['24×7 Security', 'CCTV Surveillance', 'Dock Levellers']],
        'description' => "Premium Grade A warehouse in MIDC Bhiwandi, India's largest inland logistics cluster. 12m clear height with column-free 24m spans. NH-48 within 4km, JNPT under 45 minutes.",
    ],
    [
        'name' => 'Pune Cold Storage Facility — MIDC Chakan',
        'type' => 'Cold Storage', 'grade' => 'Cold Chain', 'city' => 'Pune', 'locality' => 'Chakan',
        'rate' => 42, 'area' => 32000, 'status' => 'approved',
        'specs' => ['clearHeight' => 9, 'loadingDocks' => 4, 'power' => 750, 'features' => ['Multi-temperature Zones', 'Blast Freezing', '100% Power Backup']],
        'description' => 'Multi-temperature cold chain facility with zones from −25°C to +15°C. Blast freezing capability and full refrigeration redundancy. Suits pharma, dairy and quick-commerce.',
    ],
    [
        'name' => 'GMR Aero Warehousing Complex — T3',
        'type' => 'Warehouse', 'grade' => 'Grade A', 'city' => 'Delhi', 'locality' => 'Aerocity',
        'rate' => 35, 'area' => 120000, 'status' => 'approved',
        'specs' => ['clearHeight' => 15, 'loadingDocks' => 6, 'power' => 900, 'features' => ['LEED Gold Certified', 'Airside Access', 'Fire Suppression']],
        'description' => 'LEED Gold certified airside warehousing beside Delhi International Airport T3. Bonded warehouse capability and on-site customs clearance.',
    ],
    [
        'name' => 'Gurgaon Industrial Estate — Shed 14B',
        'type' => 'Industrial Shed', 'grade' => 'Grade B', 'city' => 'Gurugram', 'locality' => 'Sector 37',
        'rate' => 18, 'area' => 22500, 'status' => 'approved',
        'specs' => ['clearHeight' => 8, 'loadingDocks' => 2, 'power' => 250, 'features' => ['Gated Estate', 'Three-phase Power']],
        'description' => 'Independent industrial shed on a gated estate in Sector 37. Suits light manufacturing, assembly or regional distribution.',
    ],
    [
        'name' => 'Hoskote Logistics Park — Phase II',
        'type' => 'Logistics Park', 'grade' => 'Grade A', 'city' => 'Bengaluru', 'locality' => 'Hoskote',
        'rate' => 31, 'area' => 64000, 'status' => 'approved',
        'specs' => ['clearHeight' => 13, 'loadingDocks' => 10, 'power' => 600, 'features' => ['Truck Parking Court', 'ESFR Sprinklers', 'Dock Levellers']],
        'description' => 'Institutional-grade logistics park on NH-75 with a dedicated truck court and 10 dock-levelled bays. Flexible demising from 20,000 sq ft.',
    ],
    [
        'name' => 'Andheri Dark Store Hub — Q-Commerce Ready',
        'type' => 'Dark Store', 'grade' => 'Grade A', 'city' => 'Mumbai', 'locality' => 'Andheri East',
        'rate' => 55, 'area' => 4800, 'status' => 'approved',
        'specs' => ['clearHeight' => 6, 'loadingDocks' => 2, 'power' => 200, 'features' => ['Last-mile Ready', 'Cold Zone', 'Two-wheeler Bay']],
        'description' => 'Purpose-fitted dark store 8 minutes from Andheri station. Chilled zone and two-wheeler loading bay for 40+ riders.',
    ],
    [
        'name' => 'Sriperumbudur Auto Ancillary Shed',
        'type' => 'Industrial Shed', 'grade' => 'Grade B', 'city' => 'Chennai', 'locality' => 'Sriperumbudur',
        'rate' => 19, 'area' => 41000, 'status' => 'approved',
        'specs' => ['clearHeight' => 10, 'loadingDocks' => 5, 'power' => 400, 'features' => ['EOT Crane Provision', 'Trailer Parking']],
        'description' => 'Auto-ancillary shed inside the Sriperumbudur belt, minutes from major OEM plants. EOT crane provision and heavy floor loading.',
    ],
    [
        'name' => 'Nashik Cold Chain Facility',
        'type' => 'Cold Storage', 'grade' => 'Cold Chain', 'city' => 'Nashik', 'locality' => 'Sinnar MIDC',
        'rate' => 38, 'area' => 18000, 'status' => 'approved',
        'specs' => ['clearHeight' => 9, 'loadingDocks' => 3, 'power' => 700, 'features' => ['Controlled Atmosphere', 'Pre-cooling Chamber']],
        'description' => 'Controlled-atmosphere storage built for horticulture — grapes, onion, pomegranate. On-site pre-cooling and grading line.',
    ],
    [
        'name' => 'Luhari Logistics Yard — Block A',
        'type' => 'Logistics Park', 'grade' => 'Grade B', 'city' => 'Gurugram', 'locality' => 'Luhari',
        'rate' => 16, 'area' => 96000, 'status' => 'approved',
        'specs' => ['clearHeight' => 11, 'loadingDocks' => 12, 'power' => 550, 'features' => ['Cross-dock Ready', 'Trailer Circulation']],
        'description' => 'Large-format yard on the KMP Expressway with 12 docks and generous trailer circulation. Priced for bulk storage and cross-dock.',
    ],
    [
        'name' => 'Hyderabad Pharma Grade Warehouse',
        'type' => 'Warehouse', 'grade' => 'Grade A', 'city' => 'Hyderabad', 'locality' => 'Medchal',
        'rate' => 26, 'area' => 55000, 'status' => 'approved',
        'specs' => ['clearHeight' => 12, 'loadingDocks' => 7, 'power' => 480, 'features' => ['GMP Compliant', 'Temperature Mapped', 'Validated Storage']],
        'description' => 'GMP-compliant warehousing with full temperature mapping and validation documentation for pharmaceutical distribution.',
    ],

    /* ── Awaiting review: populates the admin approval queue ── */
    [
        'name' => 'Panvel Industrial Park — Plot 22',
        'type' => 'Industrial Land', 'grade' => 'Land', 'city' => 'Navi Mumbai', 'locality' => 'Panvel',
        'rate' => 22, 'area' => 80000, 'status' => 'pending',
        'specs' => ['features' => ['MIDC Approved', 'Road Frontage']],
        'description' => 'Freehold industrial plot with MIDC approval and 60m road frontage. Suitable for built-to-suit development.',
    ],
    [
        'name' => 'Ahmedabad Textile Storage — Sanand',
        'type' => 'Warehouse', 'grade' => 'Grade B', 'city' => 'Ahmedabad', 'locality' => 'Sanand',
        'rate' => 15, 'area' => 38000, 'status' => 'pending',
        'specs' => ['clearHeight' => 9, 'loadingDocks' => 4, 'power' => 300],
        'description' => 'Textile and general storage near the Sanand industrial belt. Competitive rate for bulk requirements above 20,000 sq ft.',
    ],
    [
        'name' => 'Kolkata Riverside Distribution Centre',
        'type' => 'Warehouse', 'grade' => 'Grade B', 'city' => 'Kolkata', 'locality' => 'Dankuni',
        'rate' => 17, 'area' => 47000, 'status' => 'pending',
        'specs' => ['clearHeight' => 10, 'loadingDocks' => 5, 'power' => 350],
        'description' => 'Distribution centre at Dankuni with direct access to NH-19 and the Kolkata port road.',
    ],

    /* ── Previously rejected: exercises that state in the admin table ── */
    [
        'name' => 'Wagholi Storage Shed',
        'type' => 'Industrial Shed', 'grade' => 'Grade C', 'city' => 'Pune', 'locality' => 'Wagholi',
        'rate' => 12, 'area' => 9000, 'status' => 'rejected',
        'specs' => ['clearHeight' => 6, 'loadingDocks' => 1, 'power' => 100],
        'rejectionReason' => 'Ownership documents missing; quoted rate inconsistent with locality benchmark.',
        'description' => 'Small storage shed on the Pune–Nagar road.',
    ],
];
