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

const FAILURE_REASONS = [
  ['NOBODY_HOME', 'Nobody home'],
  ['UNREACHABLE', 'Cannot reach the customer'],
  ['WRONG_ADDRESS', 'Wrong address'],
  ['REFUSED', 'Customer refused it'],
  ['REFUSED_DAMAGED', 'Refused — damaged'],
  ['OTHER', 'Something else'],
] as const;

export default function JobScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { c, radius, space } = useTheme();

  const [job, setJob] = useState<RiderJob | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [code, setCode] = useState('');
  const [codeError, setCodeError] = useState('');
  const [showFail, setShowFail] = useState(false);
  const [linkPhone, setLinkPhone] = useState('');
  const [showLink, setShowLink] = useState(false);

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

  const run = async (fn: () => Promise<RiderJob>) => {
    setBusy(true);
    setError('');
    try {
      setJob(await fn());
    } catch (e) {
      setError(
        e instanceof ApiError && e.status === 0
          ? copy('errors', 'offline')
          : e instanceof Error
            ? e.message
            : copy('errors', 'generic'),
      );
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
      {/* Who and where. The phone number is here because calling is the
          fallback that actually works in Ghana. */}
      <Card>
        <Field label="Deliver to" value={job.dropoff.contactName || 'Customer'} />
        <Field label="Address" value={job.dropoff.address} />
        {job.dropoff.note ? <Field label="Landmark" value={job.dropoff.note} /> : null}
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
              onPress={() =>
                void Linking.openURL(`geo:${job.dropoff.lat},${job.dropoff.lng}?q=${job.dropoff.lat},${job.dropoff.lng}`)
              }
            />
          </View>
        </Row>
      </Card>

      {/* What the rider is carrying, and how to find the collection point.
          Both come from whoever booked it and are shown before anything
          else that needs a decision. */}
      <Card>
        <Field
          label="What you are carrying"
          value={job.parcel.description || `${job.parcel.itemCount} item(s), ${job.parcel.size.toLowerCase()}`}
        />
        <Field label="Collect from" value={job.pickup.address} />
        {job.pickup.note ? <Field label="Collection note" value={job.pickup.note} /> : null}
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
                  title="Send the payment link"
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
                title={label}
                kind="secondary"
                onPress={() => {
                  setShowFail(false);
                  void run(() => jobsApi.failed(job.id, value));
                }}
              />
            ))}
            <Button title="Never mind" kind="ghost" onPress={() => setShowFail(false)} />
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
