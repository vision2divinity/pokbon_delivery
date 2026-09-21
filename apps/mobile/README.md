# POKBON Delivery — rider app

Expo / React Native. Android first.

**It ships as a shell and asks the plugin what to be.** Colours, every word a rider reads, which features
exist and what the home screen shows are fetched from
`https://pokbongroup.com/wp-json/pokbon/v1/delivery/app-config` on launch. Change a warning in wp-admin and
riders see it on their next open — no release, no store review, no waiting for people to update.

## Run it

```bash
npm install
npm run android --workspace=@pokbon-delivery/mobile
```

The app needs two addresses, both in `app.json` under `extra` and both overridable at runtime:

| Setting | What it is | Default |
|---|---|---|
| `pluginBaseUrl` | Where it asks what to be | `https://pokbongroup.com/wp-json/pokbon/v1` |
| `apiBaseUrl` | Riders, jobs, the delivery code | `http://localhost:3001` |

On a dev build the sign-in screen has a **Server address** panel for the API, because a test build has to point
at a laptop and a laptop's address changes with the network. Without it every change costs a rebuild, and the
symptom on the phone looks like a broken app rather than a misconfigured one. A production build ships https
and never shows the panel.

## The two rules the job screen exists to enforce

Both are enforced by the server as well, so a tampered app cannot get round them.

1. **The rider never sees the delivery code.** It goes to the customer by SMS. The rider types back what the
   customer reads out and the server answers only matched or not matched.
2. **Goods are not handed over until the money is in.** On a pay-on-delivery job the hand-over control does
   not exist until the server says `PAID`. Not disabled — absent. A greyed-out button invites a rider to keep
   pressing it and to wonder whether it is broken.

## Config, in three layers

1. the live config from the plugin;
2. the last one that fetched successfully, in the device keystore;
3. `FALLBACK` in `lib/config.ts`, compiled in.

Layer 3 exists so a rider whose phone has no signal at 6am gets a usable app rather than a blank screen. It is
a copy of the plugin's defaults and will drift from them; that is fine, because it is only ever the third
choice. Everything is deep-merged, so the plugin can send a fragment and an older phone running against a newer
plugin still renders.

## Layout

```
app/
  _layout.tsx      providers and the stack
  index.tsx        routes by what the server says about this rider
  sign-in.tsx      phone, then the SMS code
  apply.tsx        the application, and waiting/refused/suspended
  rider.tsx        home — cards and their order come from the server
  job/[id].tsx     one delivery, collection through hand-over
lib/
  config.ts        what this app is, fetched from the plugin
  theme.tsx        colours from the config, rebuilt per render so they can change live
  api.ts           the Delivery API client, single-flight token refresh
  session.tsx      who is signed in
components/ui.tsx  buttons, cards, notices — all theme-driven
```

## Things done deliberately

- **No `StyleSheet.create`.** It snapshots values at creation, so a themeable app that builds styles once can
  never repaint when the theme changes. Styles are built in render from live tokens.
- **Tokens in the keystore, not AsyncStorage.** AsyncStorage is plain files any process on a rooted phone can
  read, and this token grants access to a rider's jobs, earnings and live position.
- **Single-flight refresh.** Two screens polling at once would both see a 401, both refresh, and the second
  would present a rotated token — which the API treats as theft and answers by revoking every session. That
  logs a rider out mid-delivery.
- **Polling only where it earns its place.** Offers while on duty, payment status while waiting at a door.
  Riders pay for their own data and battery.
- **Unknown home cards are skipped, not crashed on**, which is what makes it safe to publish a new card to
  phones that predate it.

## Not built yet

- Photos. The capture flow is stubbed: the API accepts base64 at pickup, delivery and failure, and the
  buttons are wired without the picker.
- Push for offers. The home screen polls every 15 seconds while on duty instead.
- Requester mode. Off by the `requesterMode` feature flag; standalone courier jobs are phase 2.
- Never run on a physical device. It typechecks and the screens are complete, but no rider has held it.
