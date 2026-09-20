// Signs requests the way the WordPress plugin will. Marketplace contract § 1.
import { createHmac, randomUUID } from 'node:crypto';

export function pluginHeaders(secret, rawBody = '', eventId = randomUUID()) {
  return {
    'content-type': 'application/json',
    'x-pokbon-delivery-signature': createHmac('sha256', secret).update(rawBody).digest('hex'),
    'x-pokbon-delivery-timestamp': String(Math.floor(Date.now() / 1000)),
    'x-pokbon-delivery-event-id': eventId,
  };
}

export async function pluginCall(base, secret, method, path, body, eventId) {
  const raw = body === undefined ? '' : JSON.stringify(body);
  const res = await fetch(base + path, {
    method,
    headers: pluginHeaders(secret, raw, eventId),
    body: body === undefined ? undefined : raw,
  });
  const text = await res.text();
  let json;
  try {
    json = JSON.parse(text);
  } catch {
    json = text;
  }
  if (!res.ok) {
    throw new Error(`${method} ${path} → ${res.status}: ${typeof json === 'string' ? json : JSON.stringify(json)}`);
  }
  return json;
}

export async function riderCall(base, token, method, path, body) {
  const res = await fetch(base + path, {
    method,
    headers: { 'content-type': 'application/json', ...(token ? { authorization: `Bearer ${token}` } : {}) },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const text = await res.text();
  let json;
  try {
    json = JSON.parse(text);
  } catch {
    json = text;
  }
  if (!res.ok) {
    throw new Error(`${method} ${path} → ${res.status}: ${typeof json === 'string' ? json : JSON.stringify(json)}`);
  }
  return json;
}

export function readEnv() {
  const base = process.env.API_BASE ?? `http://localhost:${process.env.PORT ?? 3001}`;
  const secret = process.env.PLUGIN_SHARED_SECRET;
  if (!secret) throw new Error('PLUGIN_SHARED_SECRET is not set. Run through `dotenv -e .env --` or export it.');
  return { base, secret };
}
