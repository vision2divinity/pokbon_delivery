/**
 * The rider's home screen, laid out by the server.
 *
 * The cards and their order come from `config().riderHome`, so Francis can
 * reorder them, drop one, or add a notice from wp-admin without a release.
 * An unrecognised card type is skipped rather than crashing, which is what
 * makes it safe to add a new one while older phones are still out there.
 */
import { router, useFocusEffect } from 'expo-router';
import * as Location from 'expo-location';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import { RefreshControl, ScrollView, Vibration, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Button, Card, Field, H1, H2, Notice, P, Row, Tone } from '../components/ui';
import { ApiError, jobs as jobsApi, rider as riderApi, RiderJob, RiderOffer } from '../lib/api';
import { config, copy, feature, money } from '../lib/config';
import { startDutyLocation, stopDutyLocation } from '../lib/duty-location';
import { useSession } from '../lib/session';
import { useTheme } from '../lib/theme';

export default function RiderHome() {
  const { c, space } = useTheme();
  const { rider, refresh, signOut, offline } = useSession();

  const [active, setActive] = useState<RiderJob[]>([]);
  const [offers, setOffers] = useState<RiderOffer[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  /*
   * Announce a new offer out loud, not just on screen.
   *
   * A rider is not watching this phone. It is in a pocket or on a mount and
   * they are riding, so an offer that only appears silently is an offer that
   * expires. The offer window is seconds long, which makes the alert part of
   * whether this works at all rather than a nicety.
   *
   * Vibration is the whole of it for now, because a tone needs a native audio
   * module and therefore a new build. The pattern is deliberately unlike an
   * ordinary notification buzz: two pulses, so it is recognisable through a
   * jacket without looking.
   */
  const announced = useRef<Set<string>>(new Set());

  // Whether position is being reported in the background. False means the
  // rider declined that permission and only gets offers while the app is open.
  const [backgroundLocation, setBackgroundLocation] = useState(false);
  /*
   * What the rider is owed, and whether they can ask for it.
   *
   * The balance alone was never enough. It was lifetime gross earnings — money
   * already paid was still counted in it — and a rider's only way to raise a
   * payout was to telephone. An unexplained number a contractor cannot act on
   * is the most common reason they stop turning up, and they tell other riders
   * why.
   */
  const [payout, setPayout] = useState<Awaited<ReturnType<typeof riderApi.payoutStatus>> | null>(null);
  const [asking, setAsking] = useState(false);

  /*
   * Put reporting back after a restart, and never claim it is running when it
   * is not.
   *
   * startDutyLocation() used to be reachable from one place only — the duty
   * toggle. So a rider whose app was killed (a crash, a reboot, or ColorOS
   * reclaiming memory, which on the test device is routine) came back to the
   * server saying onDuty: true, a card reading "On duty", and nothing
   * reporting their position at all. They looked available, they were not,
   * and no screen said so. Observed 2026-09-22: a rider on duty with a
   * position 52 minutes old, silently skipped for every offer.
   *
   * isTaskRegisteredAsync() is not enough on its own — a registration
   * survives the process that owned it — so this re-arms whenever the rider
   * is on duty or holding a job, and reports honestly when it cannot.
   */
  const armed = useRef(false);
  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const shouldReport = Boolean(rider?.onDuty) || active.length > 0;

      // Arm once per launch, not once per render. startDutyLocation() stops a
      // running task before starting it, which is right when options have
      // changed and wrong on every tick of a job count — it would drop a fix
      // each time the list moved, which is exactly the gap this exists to close.
      if (shouldReport && !armed.current) {
        armed.current = true;
        const running = await startDutyLocation({
          title: copy('duty', 'trackingTitle'),
          body: copy('duty', 'trackingBody'),
        });
        if (!cancelled) setBackgroundLocation(running);
        if (!running) armed.current = false; // Permission refused; try again later.
        return;
      }

      // The last job closed while off duty: nothing is in hand any more.
      if (!shouldReport && armed.current) {
        armed.current = false;
        await stopDutyLocation();
        if (!cancelled) setBackgroundLocation(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [rider?.onDuty, active.length]);

  const announce = useCallback((incoming: RiderOffer[]) => {
    const fresh = incoming.filter((o) => !announced.current.has(o.offerId));
    // Remember every id currently in play, and forget the ones that have gone,
    // so a re-offer of the same job after a lapse announces itself again.
    announced.current = new Set(incoming.map((o) => o.offerId));
    if (fresh.length === 0) return;

    if (config().features.offerVibrate !== false) {
      Vibration.vibrate([0, 400, 200, 400]);
    }
  }, []);

  const load = useCallback(async () => {
    try {
      const [a, o] = await Promise.all([jobsApi.active(), jobsApi.offers()]);
      // Separately and forgivingly: a payout panel that fails to load must not
      // take the job list with it.
      void riderApi.payoutStatus().then(setPayout).catch(() => undefined);
      setActive(a.jobs);
      setOffers(o.offers);
      announce(o.offers);
      setError('');
    } catch (e) {
      setError(e instanceof ApiError && e.status === 0 ? copy('errors', 'offline') : '');
    }
  }, [announce]);

  useFocusEffect(
    useCallback(() => {
      void load();
      void refresh();
    }, [load, refresh]),
  );

  // Poll while on duty. A short interval costs a rider's data and battery,
  // which they pay for, so it stops the moment they go off duty.
  useEffect(() => {
    if (!rider?.onDuty) return;
    const t = setInterval(() => void load(), 15000);
    return () => clearInterval(t);
  }, [rider?.onDuty, load]);

  /*
   * Keep saying where you are, even with the phone in a pocket.
   *
   * This was a setInterval on this screen, which Android suspends the moment
   * the app leaves the foreground. A rider on duty a kilometre from a pickup
   * went 52 minutes without reporting, the API refused to offer them work for
   * a stale position — correctly — and neither the rider nor the dispatcher
   * was told why. Reporting now runs in a background task with a foreground
   * service, started when they go on duty and stopped when they come off.
   *
   * The effect here is kept for the case where background permission was
   * declined: the app still reports while it is open, which is worse but not
   * nothing, and the duty card says so.
   */
  useEffect(() => {
    if (!rider?.onDuty || backgroundLocation) return;

    const report = async () => {
      try {
        const permission = await Location.getForegroundPermissionsAsync();
        if (permission.status !== 'granted') return;
        const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
        await riderApi.ping(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy ?? undefined);
      } catch {
        // A missed fix is not worth telling the rider about; the next one is
        // sixty seconds away and the screen has real work on it.
      }
    };

    void report();
    const t = setInterval(() => void report(), 60000);
    return () => clearInterval(t);
  }, [rider?.onDuty, backgroundLocation]);

  const toggleDuty = async () => {
    setBusy(true);
    setError('');
    try {
      let at: { lat: number; lng: number } | undefined;
      const goingOn = !rider?.onDuty;

      if (goingOn) {
        const permission = await Location.requestForegroundPermissionsAsync();
        if (permission.status === 'granted') {
          const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
          at = { lat: pos.coords.latitude, lng: pos.coords.longitude };
        }
      }
      await riderApi.setDuty(goingOn, at);

      /*
       * Reporting follows the PARCEL, not the switch.
       *
       * Stopping matters as much as starting: a contractor who has finished
       * for the day is not followed home, and the notification saying they
       * are being tracked disappears with the tracking.
       *
       * But a rider holding somebody's goods has not finished. Going off duty
       * used to call stopDutyLocation() unconditionally, so a rider carrying a
       * parcel who went off duty — which is the ordinary way to say "no more
       * work today, let me just finish this one" — went dark mid-journey, and
       * the customer watching the delivery approach simply stopped seeing it
       * move. Off duty means "send me no new offers". It has never meant
       * "abandon the delivery in your hand".
       *
       * So the tracking ends when the last job does, not when the switch
       * flips. Whoever finishes second turns it off: this branch when there is
       * nothing in hand, and the delivery screen when the final job closes.
       */
      if (goingOn) {
        const running = await startDutyLocation({
          title: copy('duty', 'trackingTitle'),
          body: copy('duty', 'trackingBody'),
        });
        setBackgroundLocation(running);
      } else if (active.length === 0) {
        await stopDutyLocation();
        setBackgroundLocation(false);
      }
      await refresh();
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : copy('errors', 'generic'));
    } finally {
      setBusy(false);
    }
  };

  const accept = async (offer: RiderOffer) => {
    setBusy(true);
    try {
      const job = await jobsApi.accept(offer.offerId);
      await load();
      router.push(`/job/${job.id}`);
    } catch (e) {
      setError(e instanceof Error ? e.message : copy('errors', 'generic'));
    } finally {
      setBusy(false);
    }
  };

  const decline = async (offer: RiderOffer) => {
    setBusy(true);
    try {
      await jobsApi.decline(offer.offerId);
      await load();
    } catch {
      /* the offer expires by itself; nothing useful to say */
    } finally {
      setBusy(false);
    }
  };

  const cards: Record<string, () => React.ReactNode> = {
    dutyToggle: () => (
      <Card key="duty">
        <H2>{rider?.onDuty ? copy('duty', 'goOffline') : copy('duty', 'goOnline')}</H2>
        <P muted>{rider?.onDuty ? copy('duty', 'onlineNote') : copy('duty', 'offlineNote')}</P>
        {/*
          Say it when cover is partial.

          A rider who declined background location still gets offers, but only
          while this screen is open — and the whole reason this work exists is
          that silently receiving nothing is indistinguishable from a quiet
          day. If they are half-covered, they are told.
        */}
        {rider?.onDuty && !backgroundLocation ? (
          <Notice tone="warning">{copy('duty', 'foregroundOnly')}</Notice>
        ) : null}
        <Button
          title={rider?.onDuty ? copy('duty', 'goOffline') : copy('duty', 'goOnline')}
          kind={rider?.onDuty ? 'secondary' : 'primary'}
          onPress={toggleDuty}
          busy={busy}
        />
      </Card>
    ),

    activeJobs: () =>
      active.length ? (
        <View key="active" style={{ gap: space.sm }}>
          <H2>On now</H2>
          {active.map((job) => (
            <Card key={job.id}>
              <Field label="To" value={`${job.dropoff.zoneCode ?? ''} · ${job.dropoff.address}`} />
              <Row>
                <P>{money(job.earnings.total)}</P>
                <P muted>· {job.status.replace(/_/g, ' ').toLowerCase()}</P>
              </Row>
              <Button title="Open" onPress={() => router.push(`/job/${job.id}`)} />
            </Card>
          ))}
        </View>
      ) : null,

    offers: () =>
      offers.length ? (
        <View key="offers" style={{ gap: space.sm }}>
          <H2>{copy('offer', 'title')}</H2>
          {offers.map((offer) => (
            <Card key={offer.offerId}>
              {/* What it is comes first: a courier who cannot see the item
                  before accepting will decline, or accept and then find they
                  cannot carry it. */}
              {offer.parcel.description ? (
                <Field label="What it is" value={offer.parcel.description} />
              ) : null}
              <Field label="Pick up" value={`${offer.pickup.zoneCode ?? ''} · ${offer.pickup.address}`} />
              <Field label="Deliver to" value={`${offer.dropoff.zoneCode ?? ''} · ${offer.dropoff.address}`} />
              {/*
                * What lands in their pocket, not the gross fee. The breakdown
                * below only appears when there is something to explain.
                */}
              <Field label={copy('offer', 'feeLabel')} value={money(offer.earnings.total)} />
              {offer.earnings.commission > 0 ? (
                <Field
                  label={copy('offer', 'commissionLabel')}
                  value={`${money(offer.earnings.riderFee + offer.earnings.uplift)} − ${money(offer.earnings.commission)}`}
                />
              ) : null}
              {offer.earnings.uplift > 0 ? (
                <Notice tone="success">{copy('earnings', 'upliftNote')}</Notice>
              ) : null}
              {active.length > 0 ? <Notice tone="warning">{copy('offer', 'secondJobWarning')}</Notice> : null}
              <Row>
                <View style={{ flex: 2 }}>
                  <Button title={copy('offer', 'accept')} onPress={() => accept(offer)} busy={busy} />
                </View>
                <View style={{ flex: 1 }}>
                  <Button title={copy('offer', 'decline')} kind="secondary" onPress={() => decline(offer)} />
                </View>
              </Row>
            </Card>
          ))}
        </View>
      ) : null,

    earningsSummary: () =>
      feature('earningsScreen') ? (
        <Card key="earnings">
          <H2>{copy('earnings', 'title')}</H2>
          <Field label={copy('earnings', 'balance')} value={money(payout?.balance ?? rider?.balance ?? 0)} />
          <P muted>
            {rider?.completedJobs ?? 0} delivered
            {rider?.pendingUplift ? ` · ${money(rider.pendingUplift)} uplift on your next delivery` : ''}
          </P>

          {/*
            * Being paid has to show on their screen.
            *
            * Without this the request simply disappears when it is settled,
            * and a rider cannot tell "they paid me" from "the app forgot" —
            * which is the single thing most likely to make somebody stop
            * riding for you, and the hardest to find out about afterwards.
            */}
          {!payout?.openRequest && payout?.lastSettled ? (
            <Notice tone={payout.lastSettled.status === 'PAID' ? 'success' : 'warning'}>
              {payout.lastSettled.status === 'PAID'
                ? `Paid. You asked for ${money(payout.lastSettled.requested)}${
                    payout.lastSettled.settledAt
                      ? ` on ${new Date(payout.lastSettled.settledAt).toLocaleDateString()}`
                      : ''
                  }.`
                : `Your last request was declined${
                    payout.lastSettled.note ? `: ${payout.lastSettled.note}` : '.'
                  }`}
            </Notice>
          ) : null}

          {payout?.openRequest ? (
            <Notice tone="info">
              You asked for {money(payout.openRequest.amount)}. POKBON has been told and will pay you.
            </Notice>
          ) : payout?.canRequest ? (
            <Button
              title="Ask to be paid"
              busy={asking}
              onPress={async () => {
                setAsking(true);
                try {
                  setPayout(await riderApi.requestPayout());
                } catch (e) {
                  // The server's refusal is already written for the rider.
                  setError(e instanceof Error ? e.message : copy('errors', 'generic'));
                } finally {
                  setAsking(false);
                }
              }}
            />
          ) : payout ? (
            <P muted>
              {payout.reason}
              {payout.nextEligibleAt
                ? ` You can ask again on ${new Date(payout.nextEligibleAt).toLocaleDateString()}.`
                : ''}
            </P>
          ) : null}
        </Card>
      ) : null,

    notice: () => null, // handled below, where the card's own props are in scope
  };

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: c.background }} edges={['top']}>
      <ScrollView
        contentContainerStyle={{ padding: space.md, gap: space.md }}
        refreshControl={<RefreshControl refreshing={false} onRefresh={() => void load()} tintColor={c.primary} />}
      >
        <H1>{rider?.fullName || config().brand.name}</H1>

        {offline ? <Notice tone="warning">{copy('errors', 'offline')}</Notice> : null}
        {error ? <Notice tone="error">{error}</Notice> : null}

        {config().riderHome.map((card, i) => {
          const type = String(card.type ?? '');

          // A server-authored notice carries its own text and tone.
          if (type === 'notice') {
            return (
              <Notice key={String(card.id ?? i)} tone={(card.tone as Tone) ?? 'info'}>
                {String(card.text ?? '')}
              </Notice>
            );
          }

          // Unknown card types are skipped, never crashed on: this is what
          // lets a new card be published to phones that predate it.
          const render = cards[type];
          return render ? <React.Fragment key={type}>{render()}</React.Fragment> : null;
        })}

        <View style={{ height: space.xl }} />
        <Button title="Sign out" kind="ghost" onPress={() => void signOut()} />
      </ScrollView>
    </SafeAreaView>
  );
}
