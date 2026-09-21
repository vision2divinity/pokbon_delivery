/**
 * What this app is, fetched from the plugin.
 *
 * THE WHOLE POINT. Colours, every word a rider reads, which features exist and
 * what the home screen shows are decided in wp-admin, not compiled in here.
 * Francis changes a warning or a fee rule and riders see it on their next
 * launch — no release, no store review, no waiting for people to update.
 *
 * Three layers, in order of authority:
 *   1. the live config, fetched from the plugin on launch;
 *   2. the last config we successfully fetched, kept on the device;
 *   3. FALLBACK below, compiled in.
 *
 * Layer 3 exists so a rider whose phone has no signal at 6am still gets a
 * usable app rather than a blank screen. It is deliberately a copy of the
 * plugin's own defaults — when those change, this drifts, and that is fine:
 * it is only ever the third choice.
 */
import Constants from 'expo-constants';
import { fetchWithTimeout } from './api';
import * as SecureStore from 'expo-secure-store';

const CACHE_KEY = 'pkbd_app_config';

/** Where the plugin lives. The API is a different address; see lib/api.ts. */
const PLUGIN_BASE =
  (Constants.expoConfig?.extra?.pluginBaseUrl as string | undefined) ??
  'https://pokbongroup.com/wp-json/pokbon/v1';

export interface ThemeTokens {
  primary: string;
  primaryDark: string;
  primaryLight: string;
  primaryText: string;
  primarySurface: string;
  secondary: string;
  secondaryLight: string;
  background: string;
  backgroundSecondary: string;
  backgroundTertiary: string;
  textPrimary: string;
  textSecondary: string;
  textLight: string;
  textWhite: string;
  success: string;
  successSurface: string;
  successText: string;
  warning: string;
  warningSurface: string;
  warningText: string;
  error: string;
  errorSurface: string;
  errorText: string;
  info: string;
  border: string;
  borderLight: string;
  overlay: string;
  shadow: string;
}

export interface AppConfig {
  version: number;
  brand: Record<string, string>;
  theme: {
    light: ThemeTokens;
    dark: ThemeTokens;
    radius: Record<string, number>;
    spacing: Record<string, number>;
  };
  copy: Record<string, Record<string, string>>;
  features: Record<string, boolean>;
  rules: {
    codeLength: number;
    codeMaxSends: number;
    paymentMaxPrompts: number;
    paymentWaitMinutes: number;
    offerTimeoutSeconds: number;
    activeVehicleClasses: string[];
    agreementVersion: string;
    operatingHours: { open: string; close: string; days: number[] };
    currency: string;
    currencySymbol: string;
  };
  riderHome: Array<Record<string, unknown>>;
}

/**
 * The third choice. Only reached on a first launch with no network.
 *
 * The palette is POKBON's, shared with the marketplace app. `primaryText` is
 * darker than `primary` on purpose: the fill orange measures 2.84:1 as text on
 * white and fails accessibility, which the marketplace's own audit found.
 */
export const FALLBACK: AppConfig = {
  version: 0,
  brand: {
    name: 'POKBON Delivery',
    shortName: 'POKBON',
    supportPhone: '+233556780200',
    supportWhatsApp: '+233574482260',
    supportEmail: 'info@pokbongroup.com',
  },
  theme: {
    light: {
      primary: '#FF6B35',
      primaryDark: '#E55A2B',
      primaryLight: '#FF8C5F',
      primaryText: '#C2410C',
      primarySurface: '#FFE5D9',
      secondary: '#2D3436',
      secondaryLight: '#636E72',
      background: '#FFFFFF',
      backgroundSecondary: '#F8F9FA',
      backgroundTertiary: '#F1F2F6',
      textPrimary: '#2D3436',
      textSecondary: '#636E72',
      textLight: '#64737A',
      textWhite: '#FFFFFF',
      success: '#00B894',
      successSurface: '#E6F4EE',
      successText: '#065F46',
      warning: '#FDCB6E',
      warningSurface: '#FFF7ED',
      warningText: '#92400E',
      error: '#E74C3C',
      errorSurface: '#FFF5F5',
      errorText: '#B91C1C',
      info: '#74B9FF',
      border: '#DFE6E9',
      borderLight: '#F1F2F6',
      overlay: 'rgba(0, 0, 0, 0.5)',
      shadow: 'rgba(0, 0, 0, 0.1)',
    },
    dark: {
      primary: '#FF8C5F',
      primaryDark: '#FF6B35',
      primaryLight: '#FFA77D',
      primaryText: '#FF8C5F',
      primarySurface: '#3A2118',
      secondary: '#E5E7EB',
      secondaryLight: '#9CA3AF',
      background: '#0F1419',
      backgroundSecondary: '#1A2028',
      backgroundTertiary: '#252C36',
      textPrimary: '#F3F4F6',
      textSecondary: '#9CA3AF',
      textLight: '#808A96',
      textWhite: '#FFFFFF',
      success: '#34D399',
      successSurface: '#0F2A22',
      successText: '#6EE7B7',
      warning: '#FCD34D',
      warningSurface: '#2A2113',
      warningText: '#FCD34D',
      error: '#F87171',
      errorSurface: '#2A1616',
      errorText: '#FCA5A5',
      info: '#93C5FD',
      border: '#374151',
      borderLight: '#252C36',
      overlay: 'rgba(0, 0, 0, 0.7)',
      shadow: 'rgba(0, 0, 0, 0.4)',
    },
    radius: { sm: 8, md: 12, lg: 16, pill: 999 },
    spacing: { xs: 4, sm: 8, md: 16, lg: 24, xl: 32 },
  },
  copy: {
    duty: {
      goOnline: 'Go on duty',
      goOffline: 'Go off duty',
      onlineNote: 'You will be offered jobs near you while you are on duty.',
      offlineNote: 'You are off duty. No jobs will be offered.',
    },
    offer: {
      title: 'New delivery',
      accept: 'Accept',
      decline: 'Decline',
      feeLabel: 'You earn',
      commissionLabel: 'Fee, less POKBON commission',
      expiresIn: 'Respond within %d seconds',
      secondJobWarning:
        'You are already on a delivery. Taking this one means both are late if either goes wrong.',
    },
    pickup: {
      atPickup: 'I have arrived at pickup',
      collected: 'I have collected the parcel',
      photoHint: 'Photograph the parcel before you leave.',
    },
    delivery: {
      arrived: 'I have arrived',
      sendCode: 'Send code to customer',
      codeSentNote:
        'We sent a code to the customer by SMS. Ask them to read it to you. You will not see it.',
      codePrompt: 'Type the code the customer reads to you',
      codeWrong: 'That is not the code. Ask them to read it again.',
      codeLocked: 'Too many wrong tries. Call the dispatcher.',
      resendCode: 'Send the code again',
    },
    payment: {
      waiting: 'Waiting for the customer to approve on their phone',
      promptAgain: 'Send the prompt again',
      sendLink: 'Send the payment link',
      payByLink: 'Let someone else pay',
      paidBanner: 'PAID — hand over the item',
      notPaidYet: 'Not paid yet. Do not hand over the item.',
      failedNote:
        'The payment did not go through. Try the prompt again, send a payment link, or mark the delivery failed.',
      noCashNote: 'Never accept cash. If the customer insists, call the dispatcher.',
    },
    complete: {
      handOver: 'I have handed over the item',
      failed: 'Could not deliver',
      returned: 'Returned to sender',
      photoHint: 'Photograph the hand-over.',
    },
    earnings: {
      title: 'Earnings',
      balance: 'Your balance',
      upliftNote: 'This job pays extra to make up for a delivery that failed through no fault of yours.',
      payoutNote: 'Paid to your mobile money on the %s cycle.',
    },
    onboarding: {
      contractorNote:
        'You are an independent contractor. You choose when to work and you can stop at any time.',
      commissionZero: 'You keep the whole delivery fee. POKBON takes no commission from riders at the moment.',
      commissionNote: 'POKBON takes %s%% of the delivery fee from %s.',
      licenceNote: 'A valid rider licence is required.',
      idNote: 'Your Ghana Card is required. It is stored securely and only reviewed by POKBON staff.',
    },
    errors: {
      offline: 'No signal. Your last action is saved and will send when you are back online.',
      codeOffline:
        'The code has to be checked by POKBON, so you need signal for this step. Move to where you have a bar or two and try again.',
      generic: 'Something went wrong. Try again, or call the dispatcher.',
    },
  },
  features: {
    requesterMode: false,
    riderSelfSignup: true,
    earningsScreen: true,
    payByLink: true,
    photoAtPickup: true,
    photoAtDelivery: true,
    sosButton: false,
    darkMode: true,
    multiJob: true,
    // Switched from the plugin like everything else here: a rider with the
    // phone on a mount needs to be told out loud, an office does not.
    offerVibrate: true,
  },
  rules: {
    codeLength: 6,
    codeMaxSends: 3,
    paymentMaxPrompts: 3,
    paymentWaitMinutes: 10,
    offerTimeoutSeconds: 45,
    activeVehicleClasses: ['MOTORBIKE'],
    agreementVersion: '2026-09-20',
    operatingHours: { open: '06:00', close: '22:00', days: [0, 1, 2, 3, 4, 5, 6] },
    currency: 'GHS',
    currencySymbol: 'GH₵',
  },
  riderHome: [
    { type: 'dutyToggle' },
    { type: 'activeJobs' },
    { type: 'offers' },
    { type: 'earningsSummary' },
  ],
};

/** Where the current config came from, so a screen can say so honestly. */
export type ConfigSource = 'live' | 'cached' | 'fallback';

let current: AppConfig = FALLBACK;
let source: ConfigSource = 'fallback';

export function config(): AppConfig {
  return current;
}

export function configSource(): ConfigSource {
  return source;
}

/**
 * Fetch the config, falling back through the layers.
 *
 * Never throws and never blocks the app: a rider with no signal gets the
 * cached copy, and a rider on a brand-new phone with no signal gets the
 * compiled one. Both are usable.
 */
export async function loadConfig(): Promise<ConfigSource> {
  try {
    // Short deadline: this runs on launch and a rider should never watch a
    // spinner because WordPress is slow. The cached copy is right there.
    const response = await fetchWithTimeout(
      `${PLUGIN_BASE}/delivery/app-config`,
      { headers: { Accept: 'application/json' } },
      6000,
    );
    if (response.ok) {
      const live = (await response.json()) as AppConfig;
      if (live && live.theme && live.copy) {
        current = merge(FALLBACK, live);
        source = 'live';
        // Best effort: a cache write failing must not fail the launch.
        void SecureStore.setItemAsync(CACHE_KEY, JSON.stringify(live)).catch(() => undefined);
        return source;
      }
    }
  } catch {
    // Fall through to the cache. Offline is normal, not an error.
  }

  try {
    const cached = await SecureStore.getItemAsync(CACHE_KEY);
    if (cached) {
      current = merge(FALLBACK, JSON.parse(cached) as AppConfig);
      source = 'cached';
      return source;
    }
  } catch {
    // Fall through to the compiled copy.
  }

  current = FALLBACK;
  source = 'fallback';
  return source;
}

/**
 * Deep merge, with the fallback underneath.
 *
 * This is what lets the plugin send only part of the shape. It also means a
 * key the server has never heard of still resolves, so an older phone running
 * against a newer plugin — or the reverse — never renders `undefined` at a
 * rider standing on a doorstep.
 */
function merge<T>(base: T, over: Partial<T>): T {
  if (over === null || over === undefined) return base;
  if (typeof base !== 'object' || base === null || Array.isArray(base)) {
    return (over as T) ?? base;
  }

  const out = { ...(base as Record<string, unknown>) };
  for (const [key, value] of Object.entries(over as Record<string, unknown>)) {
    const existing = out[key];
    if (
      value !== null &&
      typeof value === 'object' &&
      !Array.isArray(value) &&
      typeof existing === 'object' &&
      existing !== null &&
      !Array.isArray(existing)
    ) {
      out[key] = merge(existing, value as Record<string, unknown>);
    } else if (value !== undefined) {
      out[key] = value;
    }
  }
  return out as T;
}

/** `copy('delivery', 'sendCode')`, always a string, never undefined on screen. */
export function copy(group: string, key: string, ...args: Array<string | number>): string {
  const raw = current.copy?.[group]?.[key] ?? FALLBACK.copy?.[group]?.[key] ?? '';
  let i = 0;
  return raw.replace(/%[sd]/g, () => String(args[i++] ?? ''));
}

export function feature(name: string): boolean {
  return Boolean(current.features?.[name] ?? FALLBACK.features?.[name]);
}

export function money(amount: number): string {
  return `${current.rules?.currencySymbol ?? 'GH₵'}${amount.toFixed(2)}`;
}
