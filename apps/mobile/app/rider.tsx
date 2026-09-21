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
   * Keep saying where you are, not just where you were when you started.
   *
   * Position used to be sent once, when the rider tapped "Go on duty", and
   * never again. Jobs are offered to the nearest rider and the API will not
   * offer to somebody whose last known position is hours old — quite rightly,
   * since it has no idea whether they are still in Accra. So a rider who had
   * been working all morning quietly stopped being offered anything, with
   * nothing on their screen to explain it. Found when a job went straight to
   * UNFULFILLED with a rider sitting on duty a kilometre away.
   *
   * A minute is slow enough not to matter to a battery and fresh enough that a
   * rider is never skipped for staleness.
   */
  useEffect(() => {
    if (!rider?.onDuty) return;

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
  }, [rider?.onDuty]);

  const toggleDuty = async () => {
    setBusy(true);
    setError('');
    try {
      let at: { lat: number; lng: number } | undefined;
      if (!rider?.onDuty) {
        const permission = await Location.requestForegroundPermissionsAsync();
        if (permission.status === 'granted') {
          const pos = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.Balanced });
          at = { lat: pos.coords.latitude, lng: pos.coords.longitude };
        }
      }
      await riderApi.setDuty(!rider?.onDuty, at);
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
          <Field label={copy('earnings', 'balance')} value={money(rider?.balance ?? 0)} />
          <P muted>
            {rider?.completedJobs ?? 0} delivered
            {rider?.pendingUplift ? ` · ${money(rider.pendingUplift)} uplift on your next delivery` : ''}
          </P>
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
