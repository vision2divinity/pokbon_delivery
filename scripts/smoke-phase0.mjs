// Phase 0 end to end, against a running API in PLUGIN_MODE=console.
//
// A pay-on-delivery marketplace job is created by "the plugin", assigned by
// hand to a rider who signed in by OTP, and taken through pickup, arrival, the
// code, the payment prompt, the plugin's "paid" callback and hand-over. The
// code is read from the dev outbox, which is exactly where the buyer's SMS
// would have gone — the rider endpoints never return it, and the test asserts
// that they do not.
import assert from 'node:assert/strict';
import { pluginCall, readEnv, riderCall } from './lib/plugin-sign.mjs';

const { base, secret } = readEnv();
const log = (...a) => console.log('•', ...a);

// 1. Settings must be there (seed-dev.mjs). Quote Madina → Circle.
const quote = await pluginCall(base, secret, 'GET', '/quote?pickup.zoneCode=MADINA&dropoff.zoneCode=CIRCLE');
assert.equal(quote.served, true, 'Madina→Circle must be priced; run scripts/seed-dev.mjs first');
log(`Quote Madina→Circle: buyer GH₵${quote.buyerPrice}, rider GH₵${quote.riderFee}`);

// 2. Rider signs in by OTP. Console mode echoes the code to a local caller.
const riderPhone = '0244000111';
const otp = await riderCall(base, null, 'POST', '/auth/otp/request', { phone: riderPhone });
assert.ok(otp.devCode, 'console mode should echo the OTP dev code to a local caller');
const tokens = await riderCall(base, null, 'POST', '/auth/otp/verify', { phone: riderPhone, code: otp.devCode });
const rider = tokens.accessToken;
let me = await riderCall(base, rider, 'GET', '/rider/me');
log(`Rider ${me.id} signed in, status ${me.status}`);

// 3. Complete the application and get approved by "the plugin".
if (me.status === 'DRAFT' || me.status === 'REJECTED') {
  await riderCall(base, rider, 'PATCH', '/rider/me', {
    fullName: 'Kofi Test Rider',
    vehicleClass: 'MOTORBIKE',
    vehicleRegistration: 'GR 1234-26',
    baseZoneCode: 'MADINA',
    idType: 'GHANA_CARD',
    idNumber: 'GHA-000000000-0',
    licenceNumber: 'DL-TEST-1',
    momoNumber: '0244000111',
    photoUrl: 'https://example.invalid/kofi.jpg',
    idPhotoUrl: 'https://example.invalid/kofi-id.jpg',
    licencePhotoUrl: 'https://example.invalid/kofi-licence.jpg',
  });
  await riderCall(base, rider, 'POST', '/rider/me/agreement', { version: me.agreement.current });
  me = await riderCall(base, rider, 'POST', '/rider/me/apply');
  log(`Application submitted, status ${me.status}`);
}
if (me.status === 'APPLIED' || me.status === 'SUSPENDED') {
  await pluginCall(base, secret, 'POST', `/plugin/riders/${me.id}/decision`, { decision: 'approve', actor: 'smoke', idVerificationLevel: 'PHOTO' });
  me = await riderCall(base, rider, 'GET', '/rider/me');
  log(`Approved, status ${me.status}`);
}
assert.equal(me.status, 'APPROVED');
await riderCall(base, rider, 'PUT', '/rider/me/duty', { onDuty: true, lat: 5.6689, lng: -0.1651 });

// 3b. Release anything this rider is still holding from an earlier run.
//
// The concurrency cap is real and correct — a rider at their limit cannot be
// assigned another job — so a smoke test that leaves a job half-finished will
// refuse to run a second time. Clearing up first makes the test repeatable and
// exercises the failure and return path on the way through.
{
  const { jobs: held } = await riderCall(base, rider, 'GET', '/rider/jobs/active');
  for (const stale of held) {
    if (stale.status === 'ASSIGNED') {
      await pluginCall(base, secret, 'POST', `/jobs/${stale.id}/cancel`, { reason: 'smoke test cleanup' });
    } else {
      await riderCall(base, rider, 'POST', `/rider/jobs/${stale.id}/failed`, { reason: 'OTHER', detail: 'smoke test cleanup' });
      await riderCall(base, rider, 'POST', `/rider/jobs/${stale.id}/returned`, {});
    }
  }
  if (held.length) log(`Cleared ${held.length} job(s) left over from an earlier run`);
}

// 4. The plugin creates a pay-on-delivery job for order 90001.
const orderId = Number(process.env.ORDER_ID ?? Date.now() % 1_000_000);
const created = await pluginCall(base, secret, 'POST', '/jobs', {
  source: 'marketplace',
  orderId,
  vendorId: 59,
  pickup: { lat: 5.669, lng: -0.166, address: 'Vendor shop, Madina market', contactName: 'Ama Vendor', contactPhone: '0205550001' },
  dropoff: { lat: 5.5725, lng: -0.2109, address: 'Blue gate opposite the pharmacy, Circle', note: 'Call when you reach', contactName: 'Yaw Buyer', contactPhone: '0244000222' },
  parcel: { sizeClass: 'small', declaredValue: 120, itemCount: 2 },
  payment: { method: 'cod', codAmount: 160, currency: 'GHS' },
  buyerUserId: 987,
});
assert.equal(created.created, true);
assert.equal(created.quotedFee, 40);
assert.equal(created.riderFee, 30);
const jobId = created.jobId;
log(`Job ${jobId} created for order ${orderId} (buyer GH₵${created.quotedFee}, rider GH₵${created.riderFee})`);

// Replay with the same order must not create a second job.
const replay = await pluginCall(base, secret, 'POST', '/jobs', {
  source: 'marketplace', orderId, vendorId: 59,
  pickup: { lat: 5.669, lng: -0.166, address: 'x', contactPhone: '0205550001' },
  dropoff: { lat: 5.5725, lng: -0.2109, address: 'y', contactPhone: '0244000222' },
  parcel: { sizeClass: 'small' }, payment: { method: 'cod', codAmount: 160 },
});
assert.equal(replay.jobId, jobId);
assert.equal(replay.created, false);
log('Replayed creation returned the same job');

// 5. Dispatcher assigns by hand.
await pluginCall(base, secret, 'POST', `/jobs/${jobId}/assign`, { riderId: me.id, actor: 'francis' });

// 6. The rider works the job. Every view is checked for leaks.
const noLeak = (view) => {
  const s = JSON.stringify(view);
  assert.ok(!s.includes('buyerPrice'), 'rider view leaked buyerPrice');
  assert.ok(!s.includes('amountDue'), 'rider view leaked amountDue');
  assert.ok(!s.includes('"40'), 'rider view leaked the buyer price value');
  assert.ok(!s.includes('160'), 'rider view leaked the order amount');
};
let active = await riderCall(base, rider, 'GET', '/rider/jobs/active');
assert.equal(active.jobs.length >= 1, true);
noLeak(active);
const view = active.jobs.find((j) => j.id === jobId);
assert.equal(view.earnings.riderFee, 30);
log(`Rider sees fee GH₵${view.earnings.riderFee}; no buyer price in the payload`);

noLeak(await riderCall(base, rider, 'POST', `/rider/jobs/${jobId}/at-pickup`));
noLeak(await riderCall(base, rider, 'POST', `/rider/jobs/${jobId}/picked-up`, {}));
noLeak(await riderCall(base, rider, 'POST', `/rider/jobs/${jobId}/en-route`));
await riderCall(base, rider, 'POST', '/rider/me/location', { lat: 5.6, lng: -0.2 });
noLeak(await riderCall(base, rider, 'POST', `/rider/jobs/${jobId}/arrived`, { lat: 5.5725, lng: -0.2109 }));

// Handing over before payment must be refused.
await assert.rejects(riderCall(base, rider, 'POST', `/rider/jobs/${jobId}/delivered`, {}), /409/);
log('Hand-over before the code is refused');

const sent = await riderCall(base, rider, 'POST', `/rider/jobs/${jobId}/send-code`);
noLeak(sent);
assert.equal(sent.status, 'CODE_SENT');
assert.ok(!/\b\d{6}\b/.test(JSON.stringify(sent)), 'send-code response leaked a six-digit code');

// Wait for the outbox to hand the SMS to the (console) plugin, then read it.
let code = null;
for (let i = 0; i < 15 && !code; i++) {
  await new Promise((r) => setTimeout(r, 1000));
  const outbox = await riderCall(base, null, 'GET', '/dev/outbox');
  const sms = outbox.entries.find((e) => e.path === '/delivery/messages/sms' && e.body?.jobId === jobId && e.body?.purpose === 'delivery_code');
  const m = sms && /code is (\d{6})/.exec(sms.body.message);
  if (m) code = m[1];
}
assert.ok(code, 'the delivery code SMS should be in the dev outbox');
log('Buyer received the code by SMS (read from the dev outbox)');

// The in-app copy must NOT carry the code.
//
// The marketplace app's inbox is filled by push notifications, so anything in
// that body is readable on a locked screen by whoever is holding the phone —
// which is the one thing the code exists to prevent. This asserts the property
// rather than trusting the wording to stay right.
{
  const outbox = await riderCall(base, null, 'GET', '/dev/outbox');
  const inbox = outbox.entries.find((e) => e.path === '/delivery/messages/inbox' && e.body?.jobId === jobId);
  assert.ok(inbox, 'an in-app message should have been queued for a marketplace order');
  assert.ok(!inbox.body.body.includes(code), 'the in-app message must not contain the delivery code');
  assert.ok(!/\d{6}/.test(inbox.body.body), 'the in-app message must not contain any six-digit code');
  log('In-app copy carries no code, only "check your SMS"');
}

// A wrong code is refused and reported only as "not matched".
const wrong = await riderCall(base, rider, 'POST', `/rider/jobs/${jobId}/verify-code`, { code: code === '000000' ? '111111' : '000000' });
assert.equal(wrong.matched, false);
noLeak(wrong);

// The right code moves straight to PAYMENT_PENDING (console plugin returns a fake intent).
const ok = await riderCall(base, rider, 'POST', `/rider/jobs/${jobId}/verify-code`, { code });
assert.equal(ok.matched, true);
assert.equal(ok.job.status, 'PAYMENT_PENDING');
assert.equal(ok.job.payment.promptCount, 1);
noLeak(ok);
log('Code matched; MoMo prompt requested from the plugin');

// Still cannot hand over: the buyer has not paid.
await assert.rejects(riderCall(base, rider, 'POST', `/rider/jobs/${jobId}/delivered`, {}), /not paid yet/);
log('Hand-over before payment is refused');

// Rider re-sends the prompt; same intent.
const again = await riderCall(base, rider, 'POST', `/rider/jobs/${jobId}/prompt-again`);
assert.equal(again.payment.promptCount, 2);

// 7. The plugin reports the Paystack webhook: paid.
const paid = await pluginCall(base, secret, 'POST', `/jobs/${jobId}/payment`, {
  intentId: `dev_intent_${jobId}`, status: 'paid', reference: 'PSK_TEST_REF_1', paidAt: new Date().toISOString(),
});
assert.equal(paid.status, 'PAID');
log('Plugin reported PAID');

// 8. Hand over.
const done = await riderCall(base, rider, 'POST', `/rider/jobs/${jobId}/delivered`, {});
noLeak(done);
assert.equal(done.status, 'DELIVERED');
const earnings = await riderCall(base, rider, 'GET', '/rider/me/earnings');
assert.ok(earnings.entries.some((e) => e.jobId === jobId && e.type === 'FEE' && e.amount === 30));
log(`Delivered. Rider balance GH₵${earnings.balance}`);

// 9. Admin view has everything; callbacks were queued for the plugin.
const admin = await pluginCall(base, secret, 'GET', `/jobs/${jobId}`);
assert.equal(admin.money.buyerPrice, 40);
assert.equal(admin.money.margin, 10);
assert.ok(admin.events.some((e) => e.type === 'status.delivered'));
await new Promise((r) => setTimeout(r, 3500));
const outbox = await riderCall(base, null, 'GET', '/dev/outbox');
const callbacks = outbox.entries.filter((e) => e.path === '/delivery/callback' && e.body?.jobId === jobId).map((e) => e.body.status);
for (const s of ['assigned', 'picked_up', 'arrived', 'code_sent', 'code_verified', 'payment_pending', 'paid', 'delivered']) {
  assert.ok(callbacks.includes(s), `plugin should have received a "${s}" callback (got ${callbacks.join(', ')})`);
}
log(`Plugin received callbacks: ${callbacks.join(' → ')}`);

console.log('\nPHASE 0 SMOKE PASSED — one pay-on-delivery job went end to end with no cash and no code shown to the rider.');
