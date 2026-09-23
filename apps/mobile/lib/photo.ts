/**
 * Evidence, from the rider's camera.
 *
 * The API has accepted photos on pickup, delivery and failure since the
 * lifecycle was written, and enforces one for REFUSED_DAMAGED — it returns 400
 * without it. The app never had a camera at all. So a rider who opened "Could
 * not deliver" and honestly chose "Refused — damaged" was told by the server
 * that a photo was required, with nothing on the screen able to take one: the
 * delivery could not be completed and could not be failed either.
 *
 * That is the worst shape a rule can take — a demand for something the person
 * has no way to give. The rule is right; the missing half was here.
 *
 * Deliberately small and free of UI, so the same capture serves pickup and
 * hand-over when those are wired up, and so the size and format rules live in
 * one place rather than being re-decided at each call site.
 */
import * as ImagePicker from 'expo-image-picker';

/** Exactly what packages/shared's photo schema accepts. */
export interface PhotoPayload {
  kind: 'PICKUP' | 'DELIVERY' | 'FAILURE';
  contentType: 'image/jpeg';
  base64: string;
}

export type PhotoResult =
  | { ok: true; photo: PhotoPayload }
  | { ok: false; reason: 'cancelled' | 'permission' | 'too_large' | 'failed'; message: string };

/*
 * The server rejects anything over 6 MB, and a modern phone camera clears that
 * on a single frame at full quality. Compressing here rather than finding out
 * from a 400 matters more than it looks: this photo is taken at somebody's
 * door, often on a bad connection, and a rider who has to retake it because
 * the upload bounced may well have walked away by then.
 *
 * 0.5 at the picker's default resize lands comfortably under a megabyte and is
 * still clear enough to see a damaged box, which is the whole point of it.
 */
const QUALITY = 0.5;

/** Roughly the byte length of a base64 string, without allocating a buffer. */
const approximateBytes = (base64: string): number => Math.floor((base64.length * 3) / 4);

const MAX_BYTES = 6 * 1024 * 1024;

/**
 * Take a photo. Never throws.
 *
 * Every failure is a returned reason the caller can put on the screen, because
 * the alternative — an exception crossing a submit handler — loses the rider's
 * chosen reason and their typed note along with it.
 */
export async function capturePhoto(kind: PhotoPayload['kind']): Promise<PhotoResult> {
  try {
    const permission = await ImagePicker.requestCameraPermissionsAsync();
    if (!permission.granted) {
      return {
        ok: false,
        reason: 'permission',
        message: 'POKBON Delivery needs the camera to record this. Allow it in Settings and try again.',
      };
    }

    const shot = await ImagePicker.launchCameraAsync({
      mediaTypes: ['images'],
      quality: QUALITY,
      base64: true,
      exif: false,
      allowsEditing: false,
    });

    if (shot.canceled) return { ok: false, reason: 'cancelled', message: '' };

    const asset = shot.assets?.[0];
    if (!asset?.base64) {
      return { ok: false, reason: 'failed', message: 'The camera returned no image. Try again.' };
    }

    if (approximateBytes(asset.base64) > MAX_BYTES) {
      return {
        ok: false,
        reason: 'too_large',
        message: 'That photo is too big to send. Try again in better light, or move closer.',
      };
    }

    return { ok: true, photo: { kind, contentType: 'image/jpeg', base64: asset.base64 } };
  } catch {
    // A missing native module, a camera another app is holding, a cancelled
    // permission dialog. None of them should cost the rider the form they have
    // already filled in.
    return { ok: false, reason: 'failed', message: 'The camera could not be opened. Try again.' };
  }
}
