/**
 * Contract test: identical assertions, run against whichever driver
 * DB_DRIVER selects. If both pass, the drivers are interchangeable and the
 * services can be switched with a configuration change.
 */
const path = require('path');
const SRV = '/Users/jinaydhami/Documents/99Warehousing/deploy/server';
const driver = process.env.DB_DRIVER || 'mongo';

(async () => {
  if (driver === 'mongo') {
    const mongoose = require(path.join(SRV, 'node_modules/mongoose'));
    await mongoose.connect('mongodb://127.0.0.1:27018/bpsf');
    await mongoose.connection.dropDatabase();
  }
  if (driver === 'dynamo') {
    /* Symmetry with dropDatabase() above: a contract test that starts with
       leftovers from the previous run measures the leftovers. */
    const { doc } = require(path.join(SRV, 'src/db/dynamo/client'));
    const { TABLES } = require(path.join(SRV, 'src/db/dynamo/tables'));
    const { ScanCommand, DeleteCommand } = require(path.join(SRV, 'node_modules/@aws-sdk/lib-dynamodb'));
    for (const t of Object.values(TABLES)) {
      const res = await doc.send(new ScanCommand({ TableName: t.name }));
      for (const item of res.Items || []) {
        const Key = t.range ? { [t.hash]: item[t.hash], [t.range]: item[t.range] } : { [t.hash]: item[t.hash] };
        await doc.send(new DeleteCommand({ TableName: t.name, Key }));
      }
    }
  }

  const r = require(path.join(SRV, 'src/db'));

  let pass = 0, fail = 0;
  const ok = (c, label, extra = '') => {
    c ? (pass++, console.log('  ✓ ' + label)) : (fail++, console.log('  ✗ ' + label + '   ' + extra));
  };

  // users
  const u = await r.users.create({ name: 'Asha', email: ' Asha@Example.COM ', password: 'x'.repeat(12), role: 'owner' });
  ok(u.id && !u._id, 'create returns `id`, never `_id`');
  ok(u.email === 'asha@example.com', 'email normalised');
  ok((await r.users.findByEmail('ASHA@example.com'))?.id === u.id, 'findByEmail case-insensitive');
  await r.users.bumpTokenVersion(u.id); await r.users.bumpTokenVersion(u.id);
  ok((await r.users.getById(u.id, { secrets: true })).tokenVersion === 2, 'tokenVersion increments');
  await r.users.recordFailedLogin(u.id, new Date(Date.now() + 9e5).toISOString());
  ok((await r.users.getById(u.id, { secrets: true })).failedLoginAttempts === 1, 'failed login counter');
  await r.users.clearLoginFailures(u.id);
  const c2 = await r.users.getById(u.id, { secrets: true });
  ok(c2.failedLoginAttempts === 0 && !c2.lockedUntil, 'lock cleared');

  // The rule that keeps password hashes out of API responses.
  const safe = await r.users.getById(u.id);
  ok(safe.password === undefined && safe.tokenVersion === undefined, 'hidden fields withheld by default');
  ok((await r.users.getById(u.id, { secrets: true })).password !== undefined, 'hidden fields returned on request');
  ok((await r.users.findByEmail('asha@example.com')).password === undefined, 'findByEmail withholds password');
  ok((await r.users.listAll())[0].password === undefined, 'listAll withholds password');

  // properties
  const mk = (n, o) => r.properties.create({ name: n, owner: u.id, ...o });
  await mk('Bhiwandi Grade A Warehouse', { city: 'Mumbai', type: 'Warehouse', grade: 'Grade A', rate: 28, area: 45000, status: 'approved', description: 'Dock levellers' });
  await mk('Chakan Cold Storage', { city: 'Pune', type: 'Cold Storage', grade: 'Cold Chain', rate: 45, area: 18000, status: 'approved' });
  const p3 = await mk('Panvel Logistics Park', { city: 'Mumbai', type: 'Logistics Park', grade: 'Grade A', rate: 34, area: 80000, status: 'approved' });
  await mk('Pending Shed', { city: 'Hosur', type: 'Industrial Shed', grade: 'Grade B', rate: 22, area: 26000, status: 'pending' });

  ok((await r.properties.listByStatus('approved')).total === 3, 'listByStatus counts only approved');
  ok((await r.properties.listByStatus('approved', { filters: { city: 'MUMBAI' } })).total === 2, 'city filter case-insensitive');
  ok((await r.properties.listByStatus('approved', { filters: { type: 'Cold Storage' } })).total === 1, 'type filter');
  ok((await r.properties.listByStatus('approved', { filters: { minRate: 30 } })).total === 2, 'minRate filter');
  ok((await r.properties.listByStatus('approved', { filters: { minArea: 40000, maxArea: 90000 } })).total === 2, 'area range filter');
  ok((await r.properties.listByStatus('approved', { filters: { q: 'dock levellers' } })).total === 1, 'free-text search');
  ok((await r.properties.listByStatus('approved', { filters: { q: 'zzz' } })).total === 0, 'free-text miss');

  const asc = await r.properties.listByStatus('approved', { sort: 'rate-asc' });
  ok(asc.items[0].rate === 28 && asc.items[2].rate === 45, 'sort rate-asc');
  ok((await r.properties.listByStatus('approved', { sort: 'rate-desc' })).items[0].rate === 45, 'sort rate-desc');
  const pg = await r.properties.listByStatus('approved', { limit: 2, skip: 2 });
  ok(pg.items.length === 1 && pg.total === 3, 'pagination keeps total honest');

  await r.properties.increment(p3.id, 'views', 5);
  ok((await r.properties.getById(p3.id)).views === 5, 'views increment');
  ok((await r.properties.listByOwner(u.id)).length === 4, 'listByOwner');
  const st = await r.properties.stats();
  ok(st.approved === 3 && st.pending === 1 && st.total === 4, 'stats', JSON.stringify(st));

  // favourites
  await r.favorites.add(u.id, p3.id); await r.favorites.add(u.id, p3.id);
  ok((await r.favorites.listForUser(u.id)).length === 1, 'duplicate favourite prevented');
  ok(await r.favorites.has(u.id, p3.id), 'favourite has()');
  await r.favorites.remove(u.id, p3.id);
  ok((await r.favorites.listForUser(u.id)).length === 0, 'favourite removed');

  // enquiries
  const e = await r.enquiries.create({ user: u.id, property: p3.id, name: 'Buyer', email: 'b@x.com', message: 'Available for a three year lease?' });
  ok((await r.enquiries.listForUser(u.id)).length === 1, 'enquiries for user');
  ok((await r.enquiries.listForProperty(p3.id)).length === 1, 'enquiries for property');
  ok((await r.enquiries.listAll()).length === 1, 'enquiry admin list');
  await r.enquiries.update(e.id, { status: 'handled' });
  ok((await r.enquiries.getById(e.id)).status === 'handled', 'enquiry update');

  // reviews
  await r.reviews.upsert({ property: p3.id, author: u.id, authorName: 'Asha', rating: 4, comment: 'Good location and clear height.', status: 'published' });
  await r.reviews.upsert({ property: p3.id, author: u.id, authorName: 'Asha', rating: 5, comment: 'Revised after a second visit.', status: 'published' });
  const revs = await r.reviews.listForProperty(p3.id);
  ok(revs.length === 1 && revs[0].rating === 5, 'one review per author, upsert overwrites');
  const rid = revs[0].id;
  ok((await r.reviews.getById(rid))?.id === rid, 'review getById');
  const rating = await r.reviews.ratingFor(p3.id);
  ok(rating.average === 5 && rating.count === 1, 'average rating', JSON.stringify(rating));
  await r.reviews.moderate(rid, 'rejected');
  ok((await r.reviews.getById(rid)).status === 'rejected', 'moderate');
  ok(await r.reviews.removeById(rid), 'review removeById');

  // audit
  await r.auditlogs.record({ action: 'property.approve', actor: u.id });
  ok((await r.auditlogs.list({ limit: 5 })).length === 1, 'audit record + list');

  // images
  const img = await r.images.create({ property: p3.id, url: 's3://b/k.webp', w: 1280, contentType: 'image/webp', uploadedBy: u.id, size: 48123, storage: 's3' });
  ok((await r.images.listForProperty(p3.id)).length === 1, 'image metadata by property');
  await r.images.remove(img.id);
  ok((await r.images.listForProperty(p3.id)).length === 0, 'image delete');

  console.log(`\n  [${driver}] ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('  CRASH:', e.message); console.error(e.stack.split('\n').slice(1, 4).join('\n')); process.exit(1); });
