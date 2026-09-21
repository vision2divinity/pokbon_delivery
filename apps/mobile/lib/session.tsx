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
import { ConfigSource, loadConfig } from './config';

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

  const signOut = useCallback(async () => {
    await clearTokens();
    setRider(null);
    setPhase('signedOut');
  }, []);

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
    setSessionEndedHandler(() => {
      setRider(null);
      setPhase('signedOut');
    });

    return () => {
      cancelled = true;
      setSessionEndedHandler(null);
    };
  }, [refresh]);

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
