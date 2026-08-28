/**
 * Lifts the 99warehouses logo off the paper mockup and writes transparent PNGs.
 *
 * A single luminance threshold does not work here: the photograph is lit
 * unevenly, so the paper ranges from ~155 in one corner to ~222 in another —
 * darker paper on one side is the same brightness as lighter ink on the other.
 *
 * Instead the paper is estimated LOCALLY. A coarse grid holds a high
 * percentile of luminance per block (the paper, ignoring the odd specular
 * highlight), bilinearly interpolated back to full resolution. Each pixel is
 * then judged on how much darker it is than the paper *beside it*, which is
 * invariant to the lighting gradient.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { PNG } = require('pngjs');

const SRC = 'C:/Users/HP/Documents/buypersquarefoot/deploy/public/assets/img/logo-original.png';
const OUT_DIR = 'C:/Users/HP/Documents/buypersquarefoot/deploy/public/assets/img';

const png = PNG.sync.read(fs.readFileSync(SRC));
const { width: W, height: H, data } = png;
const lumAt = (x, y) => {
  const i = (y * W + x) * 4;
  return 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
};

/* ── 1. Local paper estimate ── */
const BLOCK = 40;
const gw = Math.ceil(W / BLOCK);
const gh = Math.ceil(H / BLOCK);
const grid = new Float64Array(gw * gh);

for (let gy = 0; gy < gh; gy++) {
  for (let gx = 0; gx < gw; gx++) {
    const vals = [];
    for (let y = gy * BLOCK; y < Math.min((gy + 1) * BLOCK, H); y += 2) {
      for (let x = gx * BLOCK; x < Math.min((gx + 1) * BLOCK, W); x += 2) {
        vals.push(lumAt(x, y));
      }
    }
    vals.sort((a, b) => a - b);
    /* 85th percentile, not the max: the emboss throws bright specular edges
       that would otherwise inflate the estimate and eat thin strokes. */
    grid[gy * gw + gx] = vals[Math.floor(vals.length * 0.85)] || 200;
  }
}

/* A block sitting entirely on thick ink would report ink as "paper". Repair by
   pulling each cell up toward the brightest of its neighbours. */
const smoothed = Float64Array.from(grid);
for (let gy = 0; gy < gh; gy++) {
  for (let gx = 0; gx < gw; gx++) {
    let best = grid[gy * gw + gx];
    for (let dy = -2; dy <= 2; dy++) {
      for (let dx = -2; dx <= 2; dx++) {
        const nx = gx + dx, ny = gy + dy;
        if (nx < 0 || ny < 0 || nx >= gw || ny >= gh) continue;
        best = Math.max(best, grid[ny * gw + nx] * 0.97);
      }
    }
    smoothed[gy * gw + gx] = best;
  }
}

function paperAt(x, y) {
  const fx = Math.min(gw - 1.001, Math.max(0, x / BLOCK - 0.5));
  const fy = Math.min(gh - 1.001, Math.max(0, y / BLOCK - 0.5));
  const x0 = Math.floor(fx), y0 = Math.floor(fy);
  const tx = fx - x0, ty = fy - y0;
  const g = (gx, gy) => smoothed[Math.min(gh - 1, gy) * gw + Math.min(gw - 1, gx)];
  return (
    g(x0, y0) * (1 - tx) * (1 - ty) + g(x0 + 1, y0) * tx * (1 - ty) +
    g(x0, y0 + 1) * (1 - tx) * ty + g(x0 + 1, y0 + 1) * tx * ty
  );
}

/* ── 2. Alpha from local contrast ── */
const LOW = 26;    // below this the pixel is paper
const HIGH = 72;   // at or above, solid ink
const alpha = new Float64Array(W * H);

for (let y = 0; y < H; y++) {
  for (let x = 0; x < W; x++) {
    const delta = paperAt(x, y) - lumAt(x, y);
    let a = (delta - LOW) / (HIGH - LOW);
    a = a < 0 ? 0 : a > 1 ? 1 : a;
    alpha[y * W + x] = a * a * (3 - 2 * a);   // smoothstep — softens the edge
  }
}

/* ── 3. Ink bounds, and the gap between mark and wordmark ── */
const rowInk = new Float64Array(H);
const colInk = new Float64Array(W);
for (let y = 0; y < H; y++) {
  for (let x = 0; x < W; x++) {
    const a = alpha[y * W + x];
    rowInk[y] += a;
    colInk[x] += a;
  }
}
/* Thresholds relative to the strongest row/column. An absolute cutoff of a
   couple of pixels was too generous: the photograph's vignetted edges leave a
   residue around 10, which is enough to drag the crop out to the full frame
   and to fill in the blank band between the graphic and the wordmark. */
const rowCut = Math.max(12, 0.03 * Math.max(...rowInk));
/* Columns need a firmer cut than rows. The mockup's left edge carries a faint
   vignette that survives at ~1% opacity — invisible, but it widens the canvas
   by ~100px of near-empty space, which throws the logo off centre wherever it
   is placed. */
const colCut = Math.max(22, 0.055 * Math.max(...colInk));

const firstRow = rowInk.findIndex((v) => v > rowCut);
let lastRow = H - 1; while (lastRow > 0 && rowInk[lastRow] <= rowCut) lastRow--;
const firstCol = colInk.findIndex((v) => v > colCut);
let lastCol = W - 1; while (lastCol > 0 && colInk[lastCol] <= colCut) lastCol--;

/* The blank band separating the graphic from the "99warehouses" wordmark. */
let gapStart = -1, gapEnd = -1, run = 0;
for (let y = firstRow; y <= lastRow; y++) {
  if (rowInk[y] <= rowCut) {
    if (run === 0) gapStart = y;
    run++;
    gapEnd = y;
  } else {
    if (run >= 12) break;      // found a real gap
    run = 0; gapStart = -1;
  }
}
const markBottom = run >= 12 ? gapStart : lastRow;

console.log('ink bounds   :', `x ${firstCol}-${lastCol}, y ${firstRow}-${lastRow}`);
console.log('mark ends at : y', markBottom, run >= 12 ? `(gap ${gapStart}-${gapEnd})` : '(no gap found)');

/* ── 4. Write ── */
const PAD = 6;
function write(name, x0, y0, x1, y1, rgb) {
  x0 = Math.max(0, x0 - PAD); y0 = Math.max(0, y0 - PAD);
  x1 = Math.min(W - 1, x1 + PAD); y1 = Math.min(H - 1, y1 + PAD);
  const w = x1 - x0 + 1, h = y1 - y0 + 1;
  const out = new PNG({ width: w, height: h });
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      const a = alpha[(y + y0) * W + (x + x0)];
      const o = (y * w + x) * 4;
      /* Flat brand colour rather than the photographed ink: the emboss makes
         the same stroke lighter on one side than the other, which reads as a
         printing fault once the paper it sat on is gone. */
      out.data[o] = rgb[0]; out.data[o + 1] = rgb[1]; out.data[o + 2] = rgb[2];
      out.data[o + 3] = Math.round(a * 255);
    }
  }
  const file = path.join(OUT_DIR, name);
  fs.writeFileSync(file, PNG.sync.write(out));
  console.log(`  ${name.padEnd(26)} ${w}x${h}  ${Math.round(fs.statSync(file).size / 1024)} KB`);
}

const BROWN = [74, 55, 40];    // --deep-mahogany
const CREAM = [245, 240, 232]; // --off-white, for the dark sidebar

console.log('written:');
write('logo-photo.png', firstCol, firstRow, lastCol, markBottom, BROWN);
write('logo-photo-light.png', firstCol, firstRow, lastCol, markBottom, CREAM);
write('logo-photo-full.png', firstCol, firstRow, lastCol, lastRow, BROWN);
