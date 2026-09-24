/**
 * One delivery, from collection to hand-over.
 *
 * THE TWO RULES THIS SCREEN EXISTS TO ENFORCE, both also enforced by the
 * server so a tampered app cannot get round them:
 *
 *   1. The rider never sees the delivery code. It goes to the customer by
 *      SMS; the rider types back what the customer reads out, and the server
 *      answers only matched or not matched.
 *   2. Goods are not handed over until the money is in. On a pay-on-delivery
 *      job the hand-over control does not exist until the server says PAID.
 *      Not disabled — absent. A greyed-out button invites a rider to keep
 *      pressing it and to wonder whether it is broken.
 *
 * Every word here comes from the plugin, so the wording at the door can be
 * corrected from wp-admin the same hour a rider reports it is confusing.
 */
import { useLocalSearchParams, router } from 'expo-router';
import * as Location from 'expo-location';
import React, { useCallback, useEffect, useState } from 'react';
import { Linking, TextInput, View } from 'react-native';
import { Button, Card, Field, H2, Notice, P, PaidBanner, Row, Screen } from '../../components/ui';
import { ApiError, jobs as jobsApi, RiderJob } from '../../lib/api';
import { config, copy, feature, money } from '../../lib/config';
import { useTheme } from '../../lib/theme';
import { capturePhoto, PhotoPayload } from '../../lib/photo';

const FAILURE_REASONS = [
  ['NOBODY_HOME', 'Nobody home'],
  ['UNREACHABLE', 'Cannot reach the customer'],
  ['WRONG_ADDRESS', 'Wrong address'],
  ['REFUSED', 'Customer refused it'],
  ['REFUSED_DAMAGED', 'Refused — damaged'],
  ['OTHER', 'Something else'],
] as const;

type FailureReasonValue = (typeof FAILURE_REASONS)[number][0];

/*
 * The server has always required a photo here and returns 400 without one, so
 * this is not a new rule — it is the same rule, said before the rider submits
 * instead of after. Damage is a claim against a vendor or a customer, and the
 * only moment it can be evidenced is while the rider is still holding the box.
 */
const PHOTO_REQUIRED: readonly FailureReasonValue[] = ['REFUSED_DAMAGED'];

/* "Something else" is only useful if the rider can say what else. */
const NOTE_REQUIRED: readonly FailureReasonValue[] = ['OTHER'];

/**
 * Where "Navigate" should actually take the rider.
 *
 * It used to open the coordinates, always. But an order with no map pin — which
 * is every website order — is created with the CENTRE OF THE CHOSEN ZONE as its
 * coordinates, because the job still has to be priced and routed against
 * something. So tapping Navigate drove riders confidently to the middle of
 * Madina and left them there. Worse than no button: a wrong answer delivered
 * with the same certainty as a right one.
 *
 * With a real pin, navigate to the point — nothing beats it. Without one,
 * search for the address instead, which is what a person would type, and put
 * the GhanaPostGPS code first when there is one because it is the most precise
 * thing on a Ghanaian order.
 */
type Place = {
  lat: number;
  lng: number;
  address: string;
  ghanaPost?: string | null;
  landmark?: string | null;
  pinned?: boolean;
  contactName?: string | null;
};

/**
 * @param useName Search for the contact's NAME as well as the address.
 *
 * True only for a collection point, where the name is a business with a
 * signboard and often the only findable thing about it. Never for a drop-off,
 * where the name is a PERSON — and a person's name in a maps query is not
 * ignored, it is matched. "POKBON Marketplace, Planet Close 44, Sowutuom"
 * found the business called POKBON Marketplace and drove the rider there
 * instead of to the customer, who happened to be the same person that day and
 * will not be next time.
 *
 * I added the name for the pickup and let it leak into the drop-off. The
 * lesson is the one this file already carries twice: a wrong answer delivered
 * with the same confidence as a right one is worse than no button at all.
 */
function navigationUrl(place: Place, useName = false): string {
  const pinned = place.pinned !== false;
  if (pinned) {
    return `geo:${place.lat},${place.lng}?q=${place.lat},${place.lng}`;
  }

  /*
   * Order matters. GhanaPostGPS is exact when it is there; a landmark is the
   * next most findable thing and usually beats the address outright, because
   * "opposite Melcom, Sowutuom" is a place a maps app knows and "Planet Close
   * 44" frequently is not.
   */
  const query = [place.ghanaPost, place.landmark, useName ? place.contactName : '', place.address]
    .map((p) => (p ?? '').trim())
    .filter(Boolean)
    .join(', ');

  // Fall back to the zone centre only when there is no address at all to
  // search for — at that point an approximate area genuinely is the best
  // information anyone has.
  if (query === '') {
    return `geo:${place.lat},${place.lng}?q=${place.lat},${place.lng}`;
  }

  // geo:0,0?q=<text> is the documented way to ask the maps app to search
  // rather than drop a pin, and every Android maps app honours it.
  return `geo:0,0?q=${encodeURIComponent(query)}`;
}

export default function JobScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { c, radius, space } = useTheme();

  const [job, setJob] = useState<RiderJob | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [code, setCode] = useState('');
  const [codeError, setCodeError] = useState('');
  const [showFail, setShowFail] = useState(false);
  /*
   * The failure form is a form, not a menu.
   *
   * Tapping a reason used to submit immediately, which is why "Refused —
   * damaged" was unreportable: it went straight to a server that demands a
   * photo, and came back 400 with nothing on screen able to take one. The
   * rider could neither deliver nor fail, which is the one state the lifecycle
   * has no way out of.
   */
  const [failReason, setFailReason] = useState<FailureReasonValue | null>(null);
  const [failNote, setFailNote] = useState('');
  const [failPhoto, setFailPhoto] = useState<PhotoPayload | null>(null);
  const [failError, setFailError] = useState('');
  /*
   * Starts as the number on the order, and can be changed.
   *
   * An empty box made a rider retype a number they could already see, which
   * is how digits get transposed at a doorstep. It is editable because the
   * number on an order is often wrong or switched off and the person actually
   * standing there has a different phone — but the change is recorded against
   * the job, and it only ever moves the payment link. The delivery code still
   * goes to the number on the order and nowhere else: that code is the only
   * thing proving the goods reached the buyer rather than the rider.
   */
  const [linkPhone, setLinkPhone] = useState('');
  const [showLink, setShowLink] = useState(false);

  useEffect(() => {
    if (job?.dropoff.contactPhone && linkPhone === '') setLinkPhone(job.dropoff.contactPhone);
  }, [job?.dropoff.contactPhone]);

  const load = useCallback(async () => {
    if (!id) return;
    try {
      setJob(await jobsApi.one(id));
      setError('');
    } catch (e) {
      setError(e instanceof ApiError && e.status === 0 ? copy('errors', 'offline') : copy('errors', 'generic'));
    }
  }, [id]);

  useEffect(() => {
    void load();
  }, [load]);

  /**
   * Poll only while waiting on the customer's phone.
   *
   * This is the one moment the screen cannot know when to change by itself:
   * the payment lands at Paystack, reaches WordPress, then the API. Polling
   * anywhere else would just cost the rider data.
   */
  useEffect(() => {
    if (job?.status !== 'PAYMENT_PENDING') return;
    const t = setInterval(() => void load(), 5000);
    return () => clearInterval(t);
  }, [job?.status, load]);

  /**
   * Returns whether it worked.
   *
   * It swallows the error on purpose — the message belongs on the screen, not
   * in a crash — but a caller that then tidies up needs to know. The failure
   * form clears itself on success, and a silent `true` there would have thrown
   * away the photo a rider had just taken and the note they had just typed,
   * at a doorstep, on the bad connection that caused the failure. Callers that
   * do not care can carry on ignoring this.
   */
  const run = async (fn: () => Promise<RiderJob>): Promise<boolean> => {
    setBusy(true);
    setError('');
    try {
      setJob(await fn());
      return true;
    } catch (e) {
      setError(
        e instanceof ApiError && e.status === 0
          ? copy('errors', 'offline')
          : e instanceof Error
            ? e.message
            : copy('errors', 'generic'),
      );
      return false;
    } finally {
      setBusy(false);
    }
  };

  const submitCode = async () => {
    if (!id) return;
    setBusy(true);
    setCodeError('');
    setError('');
    try {
      const result = await jobsApi.verifyCode(id, code.trim());
      setJob(result.job);
      if (result.matched) {
        setCode('');
      } else {
        setCodeError(result.outcome === 'LOCKED' ? copy('delivery', 'codeLocked') : copy('delivery', 'codeWrong'));
        setCode('');
      }
    } catch (e) {
      // A code cannot be checked offline: it lives on the server, which is
      // the whole reason a rider cannot fake one.
      setCodeError(
        e instanceof ApiError && e.status === 0 ? copy('errors', 'codeOffline') : copy('errors', 'generic'),
      );
    } finally {
      setBusy(false);
    }
  };

  const arrive = async () => {
    if (!id) return;
    let at: { lat: number; lng: number } | undefined;
    try {
      const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
      at = { lat: pos.coords.latitude, lng: pos.coords.longitude };
    } catch {
      // Arriving without a fix is fine; the code is what proves the delivery.
    }
    await run(() => jobsApi.arrived(id, at));
  };

  if (!job) {
    return (
      <Screen>
        {error ? <Notice tone="error">{error}</Notice> : <P muted>Loading…</P>}
      </Screen>
    );
  }

  const payOnDelivery = job.paymentMethod === 'PAY_ON_DELIVERY';
  const done = ['DELIVERED', 'RETURNED', 'CANCELLED'].includes(job.status);

  /*
   * What the customer actually said, with the system's own sentence taken out.
   *
   * Stripped here rather than trusted to be absent, because the plugin that
   * appends it is installed separately and an older one is still out there —
   * and a rider reading "[No map pin on this order]" under "Note from the
   * customer" would reasonably conclude the customer is talking nonsense.
   */
  const landmark = (job.dropoff.landmark ?? '').trim();
  const showLandmark =
    landmark !== '' && !job.dropoff.address.toLowerCase().includes(landmark.toLowerCase());

  const customerNote = (job.dropoff.note ?? '')
    .replace(/\[No map pin on this order[^\]]*\]/gi, '')
    .trim();

  const input = {
    backgroundColor: c.backgroundSecondary,
    borderColor: c.border,
    borderWidth: 1,
    borderRadius: radius.md,
    color: c.textPrimary,
    minHeight: 56,
    paddingHorizontal: 16,
    fontSize: 18,
  };

  return (
    <Screen>
      {/*
        THE COLLECTION POINT COMES FIRST, because that is where the rider goes
        first. The screen used to open with the customer's address, which is
        the second half of the job — so a rider glancing at their phone at a
        junction read the destination and had to scroll to find where they were
        actually heading. The order of the cards is the order of the work.
      */}
      <Card>
        <Field
          label="What you are carrying"
          value={job.parcel.description || `${job.parcel.itemCount} item(s), ${job.parcel.size.toLowerCase()}`}
        />
        <Field label="Collect from" value={job.pickup.address} />
        {job.pickup.note ? <Field label="Collection note" value={job.pickup.note} /> : null}
        {/*
          The collection point had an address and no way to navigate to it.
          Every job starts by getting to a shop the rider has usually never
          been to, and the only help on this screen was a line of text to
          retype into another app while sitting on a bike.
        */}
        <Row>
          <View style={{ flex: 1 }}>
            <Button
              title="Call the shop"
              kind="secondary"
              onPress={() => void Linking.openURL(`tel:${job.pickup.contactPhone}`)}
            />
          </View>
          <View style={{ flex: 1 }}>
            <Button
              title="Navigate"
              kind="secondary"
              onPress={() => void Linking.openURL(navigationUrl(job.pickup, true))}
            />
          </View>
        </Row>
      </Card>
      {/* Where it goes after that. The phone number is here because calling
          is the fallback that actually works in Ghana. */}
      <Card>
        <Field label="Deliver to" value={job.dropoff.contactName || 'Customer'} />
        <Field label="Address" value={job.dropoff.address} />
        {/*
          Two different things were being shown under one label.
          `note` carries whatever the customer typed, and the plugin also
          appended "[No map pin on this order — go by the address and call the
          customer.]" to it. So on every order without a pin — which is most of
          them — the rider read a sentence the system had written, under a
          heading saying LANDMARK, as though the customer had written it. And
          on the orders where the customer DID leave a landmark it was buried
          in the same line as the warning.

          The warning is a state of the job, and the job already reports that
          state as `pinned`. So it is shown as a warning, and the customer's
          own words are shown as theirs.
        */}
        {/*
          Not repeated when it is already sitting in the address line. Orders
          placed before the landmark had its own question fall back to address
          line 2, which this marketplace has always labelled "Apartment, suite,
          landmark" — and that line is also part of the address. Showing the
          same words twice under two headings reads as a broken screen.
        */}
        {showLandmark ? <Field label="Landmark" value={job.dropoff.landmark as string} /> : null}
        {customerNote ? <Field label="Note from the customer" value={customerNote} /> : null}
        {job.dropoff.pinned === false ? (
          <Notice tone="warning">
            No map pin on this order. Go by the address, and call the customer when you are close.
          </Notice>
        ) : null}
        {job.dropoff.ghanaPost ? <Field label="GhanaPost GPS" value={job.dropoff.ghanaPost} /> : null}
        <Row>
          <View style={{ flex: 1 }}>
            <Button
              title="Call"
              kind="secondary"
              onPress={() => void Linking.openURL(`tel:${job.dropoff.contactPhone}`)}
            />
          </View>
          <View style={{ flex: 1 }}>
            <Button
              title="Navigate"
              kind="secondary"
              onPress={() => void Linking.openURL(navigationUrl(job.dropoff))}
            />
          </View>
        </Row>
      </Card>


      <Card>
        <Field label="You earn" value={money(job.earnings.total)} />
        {job.earnings.uplift > 0 ? <Notice tone="success">{copy('earnings', 'upliftNote')}</Notice> : null}
        {payOnDelivery ? <Notice tone="warning">{copy('payment', 'noCashNote')}</Notice> : null}
      </Card>

      {error ? <Notice tone="error">{error}</Notice> : null}

      {/* ── collection ─────────────────────────────────────────────── */}

      {job.status === 'ASSIGNED' ? (
        <Button title={copy('pickup', 'atPickup')} onPress={() => run(() => jobsApi.atPickup(job.id))} busy={busy} />
      ) : null}

      {job.status === 'AT_PICKUP' ? (
        <>
          {feature('photoAtPickup') ? <P muted>{copy('pickup', 'photoHint')}</P> : null}
          <Button title={copy('pickup', 'collected')} onPress={() => run(() => jobsApi.pickedUp(job.id))} busy={busy} />
        </>
      ) : null}

      {job.status === 'PICKED_UP' ? (
        <Button title="On my way" onPress={() => run(() => jobsApi.enRoute(job.id))} busy={busy} />
      ) : null}

      {job.status === 'EN_ROUTE' ? (
        <Button title={copy('delivery', 'arrived')} onPress={arrive} busy={busy} />
      ) : null}

      {/* ── the code ───────────────────────────────────────────────── */}

      {job.status === 'ARRIVED' ? (
        <Button title={copy('delivery', 'sendCode')} onPress={() => run(() => jobsApi.sendCode(job.id))} busy={busy} />
      ) : null}

      {job.status === 'CODE_SENT' ? (
        <Card>
          <H2>{copy('delivery', 'codePrompt')}</H2>
          <P muted>{copy('delivery', 'codeSentNote')}</P>
          <TextInput
            style={[input, { letterSpacing: 10, textAlign: 'center', fontSize: 30 }]}
            value={code}
            onChangeText={(v) => {
              setCode(v);
              setCodeError('');
            }}
            placeholder={'0'.repeat(config().rules.codeLength)}
            placeholderTextColor={c.textLight}
            keyboardType="number-pad"
            maxLength={config().rules.codeLength}
            editable={!busy}
          />
          {codeError ? <Notice tone="error">{codeError}</Notice> : null}
          <Button
            title="Check the code"
            onPress={submitCode}
            busy={busy}
            disabled={code.trim().length < config().rules.codeLength}
          />
          {job.code.sends < config().rules.codeMaxSends ? (
            <Button
              title={copy('delivery', 'resendCode')}
              kind="ghost"
              onPress={() => run(() => jobsApi.sendCode(job.id))}
              busy={busy}
            />
          ) : null}
        </Card>
      ) : null}

      {/* ── the money ──────────────────────────────────────────────── */}

      {job.status === 'CODE_VERIFIED' && payOnDelivery ? (
        <Card>
          <P>{copy('payment', 'waiting')}</P>
          <Button
            title={copy('payment', 'promptAgain')}
            onPress={() => run(() => jobsApi.promptAgain(job.id))}
            busy={busy}
          />
        </Card>
      ) : null}

      {job.status === 'PAYMENT_PENDING' ? (
        <Card>
          <H2>{copy('payment', 'waiting')}</H2>
          <P muted>{copy('payment', 'notPaidYet')}</P>
          {job.payment.promptCount < config().rules.paymentMaxPrompts ? (
            <Button
              title={copy('payment', 'promptAgain')}
              onPress={() => run(() => jobsApi.promptAgain(job.id))}
              busy={busy}
            />
          ) : null}
          {feature('payByLink') ? (
            showLink ? (
              <>
                <TextInput
                  style={input}
                  value={linkPhone}
                  onChangeText={setLinkPhone}
                  placeholder="024 000 0000"
                  placeholderTextColor={c.textLight}
                  keyboardType="phone-pad"
                />
                <Button
                  title={copy('payment', 'sendLink')}
                  kind="secondary"
                  onPress={() => run(() => jobsApi.payByLink(job.id, linkPhone.trim()))}
                  busy={busy}
                  disabled={linkPhone.trim().length < 9}
                />
              </>
            ) : (
              <Button title={copy('payment', 'payByLink')} kind="ghost" onPress={() => setShowLink(true)} />
            )
          ) : null}
        </Card>
      ) : null}

      {job.status === 'PAYMENT_FAILED' ? (
        <>
          <Notice tone="error">{copy('payment', 'failedNote')}</Notice>
          <Button
            title={copy('payment', 'promptAgain')}
            onPress={() => run(() => jobsApi.promptAgain(job.id))}
            busy={busy}
          />
          {/*
            The link belongs here most of all. This is the screen a rider is
            looking at when the request has already failed once, and it used
            to offer them only the thing that just did not work.
          */}
          {feature('payByLink') ? (
            showLink ? (
              <>
                <TextInput
                  style={input}
                  value={linkPhone}
                  onChangeText={setLinkPhone}
                  placeholder="024 000 0000"
                  placeholderTextColor={c.textLight}
                  keyboardType="phone-pad"
                />
                <Button
                  title={copy('payment', 'sendLink')}
                  kind="secondary"
                  onPress={() => run(() => jobsApi.payByLink(job.id, linkPhone.trim()))}
                  busy={busy}
                  disabled={linkPhone.trim().length < 9}
                />
              </>
            ) : (
              <Button title={copy('payment', 'payByLink')} kind="ghost" onPress={() => setShowLink(true)} />
            )
          ) : null}
        </>
      ) : null}

      {/*
        Hand-over. Present only when the money is in, or when the job was
        prepaid and the code matched. On every other status this control does
        not render at all.
      */}
      {job.status === 'PAID' || (job.status === 'CODE_VERIFIED' && !payOnDelivery) ? (
        <>
          {job.status === 'PAID' ? <PaidBanner text={copy('payment', 'paidBanner')} /> : null}
          {feature('photoAtDelivery') ? <P muted>{copy('complete', 'photoHint')}</P> : null}
          <Button title={copy('complete', 'handOver')} onPress={() => run(() => jobsApi.delivered(job.id))} busy={busy} />
        </>
      ) : null}

      {/* ── it went wrong ──────────────────────────────────────────── */}

      {job.status === 'FAILED' ? (
        <>
          <Notice tone="warning">
            {job.failure?.reason?.replace(/_/g, ' ').toLowerCase() ?? 'Delivery failed'}. Take the parcel back.
          </Notice>
          <Button title={copy('complete', 'returned')} onPress={() => run(() => jobsApi.returned(job.id))} busy={busy} />
        </>
      ) : null}

      {!done && job.status !== 'FAILED' ? (
        showFail ? (
          <Card>
            <H2>{copy('complete', 'failed')}</H2>

            {FAILURE_REASONS.map(([value, label]) => (
              <Button
                key={value}
                title={failReason === value ? `✓  ${label}` : label}
                kind={failReason === value ? 'primary' : 'secondary'}
                onPress={() => {
                  setFailReason(value);
                  setFailError('');
                  // A photo taken for "damaged" is not evidence for "nobody
                  // home". Changing the reason clears what was gathered for
                  // the old one rather than quietly attaching it to the new.
                  if (!PHOTO_REQUIRED.includes(value)) setFailPhoto(null);
                }}
              />
            ))}

            {failReason ? (
              <View style={{ gap: space.sm, marginTop: space.sm }}>
                <P>
                  {NOTE_REQUIRED.includes(failReason)
                    ? 'What happened? This goes to the dispatcher.'
                    : 'Anything to add? (optional)'}
                </P>
                <TextInput
                  style={[input, { minHeight: 80, textAlignVertical: 'top' }]}
                  value={failNote}
                  onChangeText={(v) => {
                    setFailNote(v);
                    setFailError('');
                  }}
                  placeholder="In your own words"
                  placeholderTextColor={c.textLight}
                  multiline
                  maxLength={500}
                  editable={!busy}
                />

                {PHOTO_REQUIRED.includes(failReason) ? (
                  <Notice tone="warning">
                    A photo is required when goods come back damaged. It is the only record of what
                    the box looked like while you still had it.
                  </Notice>
                ) : null}

                <Button
                  title={failPhoto ? 'Photo attached — retake' : 'Take a photo'}
                  kind="secondary"
                  disabled={busy}
                  onPress={async () => {
                    const shot = await capturePhoto('FAILURE');
                    if (shot.ok) {
                      setFailPhoto(shot.photo);
                      setFailError('');
                    } else if (shot.reason !== 'cancelled') {
                      setFailError(shot.message);
                    }
                  }}
                />

                {failError ? <Notice tone="error">{failError}</Notice> : null}

                <Button
                  title="Report it"
                  busy={busy}
                  onPress={() => {
                    // Checked here so the rider is told before they submit,
                    // rather than by a 400 that loses what they typed.
                    if (PHOTO_REQUIRED.includes(failReason) && !failPhoto) {
                      setFailError('Take a photo of the damage before reporting this.');
                      return;
                    }
                    if (NOTE_REQUIRED.includes(failReason) && failNote.trim() === '') {
                      setFailError('Say what happened, so the dispatcher can act on it.');
                      return;
                    }
                    void run(() =>
                      jobsApi.failed(
                        job.id,
                        failReason,
                        failNote.trim() === '' ? undefined : failNote.trim(),
                        failPhoto ?? undefined,
                      ),
                    ).then((ok) => {
                      // Only on success. A failed submit keeps the photo and
                      // the note exactly where they were, so the rider retries
                      // rather than re-gathers.
                      if (!ok) return;
                      setShowFail(false);
                      setFailReason(null);
                      setFailNote('');
                      setFailPhoto(null);
                    });
                  }}
                />
              </View>
            ) : null}

            <Button
              title="Never mind"
              kind="ghost"
              onPress={() => {
                setShowFail(false);
                setFailReason(null);
                setFailNote('');
                setFailPhoto(null);
                setFailError('');
              }}
            />
          </Card>
        ) : (
          <Button title={copy('complete', 'failed')} kind="ghost" onPress={() => setShowFail(true)} />
        )
      ) : null}

      {done ? (
        <>
          <Notice tone="success">
            {job.status === 'DELIVERED' ? 'Delivered. Your fee has been added to your balance.' : 'This job is closed.'}
          </Notice>
          <Button title="Back to jobs" onPress={() => router.replace('/rider')} />
        </>
      ) : null}

      <View style={{ height: space.xl }} />
    </Screen>
  );
}
