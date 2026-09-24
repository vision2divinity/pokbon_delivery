/**
 * The Delivery API client.
 *
 * Tokens live in the device keystore, not AsyncStorage: AsyncStorage is plain
 * files that any process on a rooted phone can read, and this token grants
 * access to a rider's jobs, their earnings and their live position.
 *
 * Refresh is single-flight. Two screens polling at once would otherwise both
 * notice a 401, both refresh, and the second would present a token the server
 * has already rotated — which the API treats as theft and responds to by
 * revoking every session. That logs a rider out mid-delivery, which is the
 * worst possible moment.
 */
import Constants from 'expo-constants';
import * as SecureStore from 'expo-secure-store';

const ACCESS_KEY = 'pkbd_access';
const REFRESH_KEY = 'pkbd_refresh';
const BASE_KEY = 'pkbd_api_base';

const COMPILED_BASE =
  (Constants.expoConfig?.extra?.apiBaseUrl as string | undefined) ?? 'http://localhost:3001';

let apiBase = COMPILED_BASE;


/**
 * True for a build pointed at a dev host, which is what gates the in-app
 * address override. A production build ships https and never offers it.
 */
export const isDevelopmentBuild = !COMPILED_BASE.startsWith('https://');

export function apiBaseUrl(): string {
  return apiBase;
}

export async function loadApiBaseOverride(): Promise<void> {
  try {
    const stored = await SecureStore.getItemAsync(BASE_KEY);
    if (stored) apiBase = stored;
  } catch {
    // Keystore unavailable: the compiled default is still correct.
  }
}

export async function setApiBaseOverride(value: string | null): Promise<void> {
  apiBase = value ?? COMPILED_BASE;
  try {
    if (value) await SecureStore.setItemAsync(BASE_KEY, value);
    else await SecureStore.deleteItemAsync(BASE_KEY);
  } catch {
    // Not fatal: the override holds for this run.
  }
}

/**
 * fetch with a deadline.
 *
 * Not AbortSignal.timeout(): Hermes does not implement it, so every request
 * this app made threw "AbortSignal.timeout is not a function" before a single
 * byte left the phone — and the catch below reported that to the rider as
 * "No signal, or the delivery service cannot be reached". Somebody standing
 * in full signal was told to go and find a mast.
 */
export async function fetchWithTimeout(url: string, init: RequestInit, timeoutMs: number): Promise<Response> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, { ...init, signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

export class ApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
    readonly body?: unknown,
  ) {
    super(message);
  }
}

export class NotAuthenticated extends Error {}

export interface Tokens {
  accessToken: string;
  refreshToken: string;
  expiresInSeconds: number;
}

export async function saveTokens(t: Tokens): Promise<void> {
  await SecureStore.setItemAsync(ACCESS_KEY, t.accessToken);
  await SecureStore.setItemAsync(REFRESH_KEY, t.refreshToken);
}

export async function clearTokens(): Promise<void> {
  await SecureStore.deleteItemAsync(ACCESS_KEY).catch(() => undefined);
  await SecureStore.deleteItemAsync(REFRESH_KEY).catch(() => undefined);
}

export async function hasSession(): Promise<boolean> {
  return Boolean(await SecureStore.getItemAsync(REFRESH_KEY).catch(() => null));
}

let sessionEnded: (() => void) | null = null;
export function setSessionEndedHandler(h: (() => void) | null): void {
  sessionEnded = h;
}

/** Single-flight refresh. See the note at the top for why this matters. */
let refreshing: Promise<boolean> | null = null;

async function refreshOnce(): Promise<boolean> {
  if (refreshing) return refreshing;

  refreshing = (async () => {
    const refreshToken = await SecureStore.getItemAsync(REFRESH_KEY).catch(() => null);
    if (!refreshToken) return false;

    try {
      const res = await fetchWithTimeout(
        `${apiBase}/auth/refresh`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ refreshToken }),
        },
        15000,
      );
      /*
       * Only the server saying "no" ends a session.
       *
       * This used to throw the tokens away on any non-2xx, which includes a
       * 502 from a restarting API, a 503, a 429, and the 530 Cloudflare
       * returns when a tunnel drops. On 2026-09-22 exactly that happened: the
       * tunnel blipped, the refresh came back 530, and a signed-in rider was
       * silently logged out while the server's own refresh token remained
       * valid and unrevoked. A rider would have needed a fresh SMS code,
       * standing at a customer's door, because the connection had a bad
       * minute.
       *
       * 401 and 403 are the server's considered answer that this credential
       * is no longer good — a revoked session, a rotated token presented
       * twice, a rider removed. Everything else is a failure to ask the
       * question, and the tokens stay.
       */
      if (res.status === 401 || res.status === 403) {
        await clearTokens();
        sessionEnded?.();
        return false;
      }
      if (!res.ok) {
        // Kept, deliberately. The caller reports offline and the next attempt
        // tries again with the credentials the rider still has.
        return false;
      }
      await saveTokens((await res.json()) as Tokens);
      return true;
    } catch {
      // A network failure is not a dead session. Keep the tokens and let the
      // caller surface "no signal" instead of throwing the rider out.
      return false;
    } finally {
      refreshing = null;
    }
  })();

  return refreshing;
}

export interface ApiOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE';
  body?: unknown;
  /** Skip the bearer token, for sign-in routes. */
  anonymous?: boolean;
  timeoutMs?: number;
}

export async function api<T>(path: string, options: ApiOptions = {}): Promise<T> {
  const { method = 'GET', body, anonymous = false, timeoutMs = 20000 } = options;

  const send = async (): Promise<Response> => {
    const headers: Record<string, string> = { 'Content-Type': 'application/json' };
    if (!anonymous) {
      const token = await SecureStore.getItemAsync(ACCESS_KEY).catch(() => null);
      if (!token) throw new NotAuthenticated('No access token');
      headers.Authorization = `Bearer ${token}`;
    }
    return fetchWithTimeout(
      `${apiBase}${path}`,
      { method, headers, body: body === undefined ? undefined : JSON.stringify(body) },
      timeoutMs,
    );
  };

  let response: Response;
  try {
    response = await send();
  } catch (error) {
    if (error instanceof NotAuthenticated) throw error;
    /*
     * Only a real network failure is "offline".
     *
     * React Native throws TypeError for both a dead network and a mistake in
     * this file, and treating every exception as offline is how a missing
     * function sat here telling riders their signal was bad. Anything that is
     * not recognisably the network is re-thrown so it reaches the screen with
     * its own message.
     */
    const failure = error as Error;
    const offline =
      failure?.name === 'AbortError' ||
      (failure instanceof TypeError && /network request failed/i.test(failure.message ?? ''));
    if (!offline) throw error;
    throw new ApiError(0, 'offline');
  }

  if (response.status === 401 && !anonymous) {
    if (await refreshOnce()) {
      try {
        response = await send();
      } catch {
        throw new ApiError(0, 'offline');
      }
    } else {
      throw new NotAuthenticated('Session ended');
    }
  }

  const text = await response.text();
  let parsed: unknown = null;
  try {
    parsed = text ? JSON.parse(text) : null;
  } catch {
    parsed = text;
  }

  if (!response.ok) {
    const message =
      parsed && typeof parsed === 'object' && 'message' in parsed
        ? String((parsed as { message: unknown }).message)
        : `Request failed (${response.status})`;
    throw new ApiError(response.status, message, parsed);
  }

  return parsed as T;
}

// ─── the calls the screens make ──────────────────────────────────────────────

export const auth = {
  requestOtp: (phone: string) =>
    api<{ sent: boolean; devCode?: string }>('/auth/otp/request', {
      method: 'POST',
      body: { phone },
      anonymous: true,
    }),
  verifyOtp: (phone: string, code: string) =>
    api<Tokens>('/auth/otp/verify', { method: 'POST', body: { phone, code }, anonymous: true }),
};

export interface RiderMe {
  id: string;
  phone: string;
  fullName: string | null;
  status: string;
  vehicleClass: string | null;
  baseZoneCode: string | null;
  onDuty: boolean;
  completedJobs: number;
  activeJobs: number;
  balance: number;
  pendingUplift: number;
  missingForApplication: string[];
  agreement: { accepted: string | null; current: string; upToDate: boolean };
  commissionSchedule: Array<{ effectiveFrom: string; rateBps?: number }>;
}

export interface RiderJob {
  id: string;
  status: string;
  paymentMethod: 'PREPAID' | 'PAY_ON_DELIVERY';
  parcel: { size: string; itemCount: number; description: string | null };
  pickup: {
    lat: number;
    lng: number;
    address: string;
    zoneCode: string | null;
    note: string | null;
    contactName: string;
    contactPhone: string;
  };
  dropoff: {
    lat: number;
    lng: number;
    address: string;
    zoneCode: string | null;
    ghanaPost: string | null;
    note: string | null;
    contactName: string;
    contactPhone: string;
  };
  /** The rider's own money. There is no buyer price in this shape, by design. */
  earnings: { riderFee: number; uplift: number; commission: number; total: number; currency: string };
  payment: { status: string | null; promptCount: number; pendingSince: string | null };
  code: { sends: number; bypassed: boolean };
  failure: { reason: string; detail: string | null } | null;
}

export interface RiderOffer {
  offerId: string;
  jobId: string;
  expiresAt: string;
  distanceMetres: number;
  pickup: { zoneCode: string | null; address: string };
  dropoff: { zoneCode: string | null; address: string };
  parcel: { size: string; itemCount: number; description: string | null };
  paymentMethod: string;
  earnings: { riderFee: number; uplift: number; commission: number; total: number; currency: string };
}

/** What a rider is owed, and whether they may ask for it yet. */
export interface PayoutStatus {
  balance: number;
  currency: string;
  canRequest: boolean;
  /** A sentence written for the rider, not an error code. */
  reason: string | null;
  cycle: string;
  nextEligibleAt: string | null;
  openRequest: { id: string; amount: number; requestedAt: string } | null;
  /** What happened to the last request, so being paid is visible. */
  lastSettled: {
    status: 'PAID' | 'DECLINED';
    requested: number;
    settledAt: string | null;
    note: string | null;
  } | null;
}

export const rider = {
  me: () => api<RiderMe>('/rider/me'),
  update: (data: Record<string, unknown>) => api<RiderMe>('/rider/me', { method: 'PATCH', body: data }),
  acceptAgreement: (version: string) =>
    api<RiderMe>('/rider/me/agreement', { method: 'POST', body: { version } }),
  apply: () => api<RiderMe>('/rider/me/apply', { method: 'POST' }),
  setDuty: (onDuty: boolean, at?: { lat: number; lng: number }) =>
    api<RiderMe>('/rider/me/duty', { method: 'PUT', body: { onDuty, ...at } }),
  ping: (lat: number, lng: number, accuracy?: number) =>
    api<{ accepted: boolean }>('/rider/me/location', { method: 'POST', body: { lat, lng, accuracy } }),
  /**
   * What the rider is owed, and whether they may ask for it.
   *
   * The reason is a sentence written for the rider, not a code — a refusal a
   * contractor cannot understand is how you lose one.
   */
  payoutStatus: () => api<PayoutStatus>('/rider/me/payout'),

  // Answers with the same shape, so the screen re-renders from one source.
  requestPayout: (note?: string) =>
    api<PayoutStatus>('/rider/me/payout', { method: 'POST', body: { note } }),

  earnings: () =>
    api<{ balance: number; currency: string; payoutCycle: string; entries: Array<Record<string, unknown>> }>(
      '/rider/me/earnings',
    ),
  leave: () => api<{ left: boolean }>('/rider/me/leave', { method: 'POST' }),
};

export const jobs = {
  offers: () => api<{ offers: RiderOffer[] }>('/rider/jobs/offers'),
  accept: (offerId: string) => api<RiderJob>(`/rider/jobs/offers/${offerId}/accept`, { method: 'POST' }),
  decline: (offerId: string, reason?: string) =>
    api<void>(`/rider/jobs/offers/${offerId}/decline`, { method: 'POST', body: { reason } }),
  active: () => api<{ jobs: RiderJob[] }>('/rider/jobs/active'),
  history: () => api<{ jobs: RiderJob[] }>('/rider/jobs/history'),
  one: (id: string) => api<RiderJob>(`/rider/jobs/${id}`),

  atPickup: (id: string) => api<RiderJob>(`/rider/jobs/${id}/at-pickup`, { method: 'POST' }),
  pickedUp: (id: string, photo?: unknown) =>
    api<RiderJob>(`/rider/jobs/${id}/picked-up`, { method: 'POST', body: { photo } }),
  enRoute: (id: string) => api<RiderJob>(`/rider/jobs/${id}/en-route`, { method: 'POST' }),
  arrived: (id: string, at?: { lat: number; lng: number }) =>
    api<RiderJob>(`/rider/jobs/${id}/arrived`, { method: 'POST', body: at ?? {} }),

  /** Sends the code to the customer. The response never contains it. */
  sendCode: (id: string) => api<RiderJob>(`/rider/jobs/${id}/send-code`, { method: 'POST' }),
  /** Answers matched or not. The rider never learns the code itself. */
  verifyCode: (id: string, code: string) =>
    api<{ matched: boolean; outcome: string; job: RiderJob }>(`/rider/jobs/${id}/verify-code`, {
      method: 'POST',
      body: { code },
    }),

  promptAgain: (id: string) => api<RiderJob>(`/rider/jobs/${id}/prompt-again`, { method: 'POST' }),
  payByLink: (id: string, phone: string) =>
    api<RiderJob>(`/rider/jobs/${id}/pay-by-link`, { method: 'POST', body: { phone } }),

  delivered: (id: string, photo?: unknown) =>
    api<RiderJob>(`/rider/jobs/${id}/delivered`, { method: 'POST', body: { photo } }),
  failed: (id: string, reason: string, detail?: string, photo?: unknown) =>
    api<RiderJob>(`/rider/jobs/${id}/failed`, { method: 'POST', body: { reason, detail, photo } }),
  returned: (id: string, detail?: string) =>
    api<RiderJob>(`/rider/jobs/${id}/returned`, { method: 'POST', body: { detail } }),
};
