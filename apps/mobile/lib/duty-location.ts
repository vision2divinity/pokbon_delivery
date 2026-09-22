/**
 * Where the rider is, reported even when the app is in a pocket.
 *
 * Position used to be sent from a JavaScript timer on the duty screen, which
 * Android suspends the moment the app leaves the foreground. On 2026-09-22 a
 * rider was on duty, a kilometre from a pickup, with a position 52 minutes
 * old — and the API rightly refuses to offer work to somebody whose last known
 * position is that stale, because it has no idea whether they are still in the
 * city. The dispatcher saw "nobody eligible" and the rider saw nothing at all.
 *
 * That is the worst failure mode this system has: a rider who is working, and
 * available, and silently receiving nothing.
 *
 * So reporting moves to a background task with a foreground service, which is
 * how delivery apps stay alive on Android. The rider sees a persistent
 * notification while on duty — deliberately, since an app tracking somebody's
 * location should say so on their screen rather than only in a permission
 * dialog they tapped once.
 *
 * It runs only while on duty. Going off duty stops the task, removes the
 * notification, and ends the tracking, because a contractor who has finished
 * for the day is not to be followed home.
 */
import * as Location from 'expo-location';
import * as TaskManager from 'expo-task-manager';
import { rider as riderApi } from './api';

export const DUTY_LOCATION_TASK = 'pokbon-duty-location';

/*
 * Defined at module scope, not inside a component.
 *
 * Android can restart the process and replay the task without the UI ever
 * mounting, and a task that is not registered by then is dropped. This module
 * is imported from the root layout for that reason.
 */
TaskManager.defineTask(DUTY_LOCATION_TASK, async ({ data, error }) => {
  if (error) {
    // Nothing useful to do: the next fix is a minute away, and there is no
    // screen to tell. Swallowing it keeps the task registered.
    return;
  }

  const locations = (data as { locations?: Location.LocationObject[] } | undefined)?.locations;
  const latest = locations?.[locations.length - 1];
  if (!latest) return;

  try {
    await riderApi.ping(latest.coords.latitude, latest.coords.longitude, latest.coords.accuracy ?? undefined);
  } catch {
    // Offline, or the session ended. Either way the next fix retries, and a
    // failed ping must never crash a background task — Android stops
    // restarting tasks that throw.
  }
});

/**
 * Begin reporting. Safe to call when already running.
 *
 * Returns false when the rider declined background permission, so the caller
 * can say what that costs them rather than failing silently.
 */
export async function startDutyLocation(copy: { title: string; body: string }): Promise<boolean> {
  const foreground = await Location.requestForegroundPermissionsAsync();
  if (foreground.status !== 'granted') return false;

  /*
   * Background permission is requested separately and second, which is what
   * Android requires — asking for both at once is rejected outright. A rider
   * who grants only foreground still gets offers while the app is open, so
   * this returns false rather than refusing to start.
   */
  const background = await Location.requestBackgroundPermissionsAsync();
  if (background.status !== 'granted') return false;

  const already = await TaskManager.isTaskRegisteredAsync(DUTY_LOCATION_TASK);
  if (already) return true;

  await Location.startLocationUpdatesAsync(DUTY_LOCATION_TASK, {
    accuracy: Location.Accuracy.Balanced,
    // A minute, and 100 metres. The API treats a position older than five
    // minutes as stale, so this leaves room for a missed fix in a building
    // without draining a battery the rider pays to charge.
    timeInterval: 60_000,
    distanceInterval: 100,
    pausesUpdatesAutomatically: false,
    foregroundService: {
      notificationTitle: copy.title,
      notificationBody: copy.body,
      notificationColor: '#FF6B35',
    },
  });

  return true;
}

/** Stop reporting. Safe to call when not running. */
export async function stopDutyLocation(): Promise<void> {
  try {
    const running = await TaskManager.isTaskRegisteredAsync(DUTY_LOCATION_TASK);
    if (running) await Location.stopLocationUpdatesAsync(DUTY_LOCATION_TASK);
  } catch {
    // Already stopped, or the task was never registered on this launch.
  }
}

/** Whether the background task is currently reporting. */
export async function dutyLocationRunning(): Promise<boolean> {
  try {
    return await TaskManager.isTaskRegisteredAsync(DUTY_LOCATION_TASK);
  } catch {
    return false;
  }
}
