export interface LatLng {
  lat: number;
  lng: number;
}

/** Great-circle distance in metres. Good enough for zone resolution and "nearby". */
export function haversineMetres(a: LatLng, b: LatLng): number {
  const R = 6_371_000;
  const toRad = (d: number) => (d * Math.PI) / 180;
  const dLat = toRad(b.lat - a.lat);
  const dLng = toRad(b.lng - a.lng);
  const s =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * Math.sin(dLng / 2) ** 2;
  return Math.round(2 * R * Math.asin(Math.sqrt(s)));
}

export interface ZoneLike extends LatLng {
  code: string;
  radiusMetres: number;
  active: boolean;
}

/**
 * Nearest active zone whose radius contains the point, or null.
 * PRD § 9a: if no zone claims the pin, the buyer picks one or is told delivery
 * is not yet available there.
 */
export function resolveZone<Z extends ZoneLike>(point: LatLng, zones: Z[]): Z | null {
  let best: { zone: Z; d: number } | null = null;
  for (const zone of zones) {
    if (!zone.active) continue;
    const d = haversineMetres(point, zone);
    if (d <= zone.radiusMetres && (!best || d < best.d)) best = { zone, d };
  }
  return best?.zone ?? null;
}
