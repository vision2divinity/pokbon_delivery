/**
 * Who is signed in, and what the app is.
 *
 * Both are loaded once at launch: the config (what this app looks like and
 * says) and the rider (who they are and what they may do). Screens read from
 * here rather than fetching for themselves, so a rider on a bad connection
 * sees one loading state on launch instead of six.
 */
import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import {
  clearTokens,
  hasSession,
  loadApiBaseOverride,
  NotAuthenticated,
  rider as riderApi,
  RiderMe,
  setSessionEndedHandler,
} from './api';
import { router } from 'expo-router';
import { ConfigSource, loadConfig } from './config';
import { stopDutyLocation } from './duty-location';

type Phase = 'starting' | 'signedOut' | 'signedIn';

interface SessionValue {
  phase: Phase;
  rider: RiderMe | null;
  configSource: ConfigSource;
  /** Set when the last refresh failed for want of signal, not authorisation. */
  offline: boolean;
  refresh: () => Promise<void>;
  signedIn: () => Promise<void>;
  signOut: () => Promise<void>;
}

const SessionContext = createContext<SessionValue | null>(null);

export function SessionProvider({ children }: { children: React.ReactNode }) {
  const [phase, setPhase] = useState<Phase>('starting');
  const [rider, setRider] = useState<RiderMe | null>(null);
  const [source, setSource] = useState<ConfigSource>('fallback');
  const [offline, setOffline] = useState(false);

  const refresh = useCallback(async () => {
    try {
      const me = await riderApi.me();
      setRider(me);
      setOffline(false);
      setPhase('signedIn');
    } catch (error) {
      if (error instanceof NotAuthenticated) {
        setRider(null);
        setPhase('signedOut');
        return;
      }
      // Any other failure is the network, not the session. A rider mid-job
      // must not be thrown back to the sign-in screen because a request
      // timed out in a stairwell.
      setOffline(true);
      setPhase((p) => (p === 'starting' ? 'signedOut' : p));
    }
  }, []);

  const signedIn = useCallback(async () => {
    setPhase('starting');
    await refresh();
  }, [refresh]);

  /**
   * End the session properly, however it ended.
   *
   * Three things have to happen and only one of them used to.
   *
   * Clearing the tokens was never the problem. The problem was that nothing
   * moved: index.tsx is what routes on `phase`, and a rider signing out is by
   * definition standing on /rider, where index is not mounted. So the screen
   * sat there looking signed in, "Go on duty" was still tappable, and tapping
   * it produced "no access token" — the one error message that tells a rider
   * nothing they can act on. The only way out was killing the app from recents.
   *
   * And tracking kept running. duty-location.ts says a contractor who has
   * finished for the day is not to be followed home, and signing out is a
   * stronger way of saying that than the duty switch. Worse, every ping after
   * this point is a 401: a foreground service draining the battery, showing a
   * notification that claims they are on duty, reporting to nobody.
   */
  const endSession = useCallback(async () => {
    await clearTokens();
    // Best effort, and deliberately not awaited into a failure path: a
    // stubborn task must not be able to trap somebody in a signed-out app.
    await stopDutyLocation().catch(() => undefined);
    setRider(null);
    setPhase('signedOut');
    // Back to index, which decides where a signed-out rider belongs. Routing
    // stays in one place rather than each screen knowing about sign-in.
    //
    // Guarded because this also runs when a refresh token is rejected, which
    // can happen during the very first load — before the navigator is mounted,
    // where an imperative replace has nothing to act on. index.tsx already
    // routes correctly on `phase` in that case, so failing here costs nothing.
    try {
      router.replace('/');
    } catch {
      /* Not mounted yet; phase alone gets them to the right screen. */
    }
  }, []);

  const signOut = endSession;

  useEffect(() => {
    let cancelled = false;

    (async () => {
      // The config decides what every later screen says, so it loads first.
      await loadApiBaseOverride();
      const src = await loadConfig();
      if (cancelled) return;
      setSource(src);

      if (await hasSession()) {
        await refresh();
      } else {
        setPhase('signedOut');
      }
    })();

    // The API tells us when a refresh token was rejected outright, which is
    // the one case where dropping the rider to sign-in is correct.
    // A refresh token rejected outright ends the session exactly as tapping
    // Sign out does — including the navigation, which this used to lack too,
    // leaving a rider whose session expired stranded on a screen that no
    // longer worked.
    setSessionEndedHandler(() => {
      void endSession();
    });

    return () => {
      cancelled = true;
      setSessionEndedHandler(null);
    };
  }, [refresh, endSession]);

  const value = useMemo<SessionValue>(
    () => ({ phase, rider, configSource: source, offline, refresh, signedIn, signOut }),
    [phase, rider, source, offline, refresh, signedIn, signOut],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionValue {
  const value = useContext(SessionContext);
  if (!value) throw new Error('useSession() outside SessionProvider');
  return value;
}
