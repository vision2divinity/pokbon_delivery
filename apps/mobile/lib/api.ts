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
      const res = await fetch(`${apiBase}/auth/refresh`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refreshToken }),
        signal: AbortSignal.timeout(15000),
      });
      if (!res.ok) {
        await clearTokens();
        sessionEnded?.();
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
    return fetch(`${apiBase}${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: AbortSignal.timeout(timeoutMs),
    });
  };

  let response: Response;
  try {
    response = await send();
  } catch (error) {
    if (error instanceof NotAuthenticated) throw error;
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
  parcel: { size: string; itemCount: number };
  pickup: { lat: number; lng: number; address: string; zoneCode: string | null; contactName: string; contactPhone: string };
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
  parcel: { size: string; itemCount: number };
  paymentMethod: string;
  earnings: { riderFee: number; uplift: number; currency: string };
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
