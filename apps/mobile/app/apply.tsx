/**
 * Becoming a rider, and waiting to hear back.
 *
 * One screen for the whole pre-approval life: filling the form, waiting for
 * review, being told no, being suspended. A rider who has applied and heard
 * nothing is the most likely person to open this app, so the waiting state is
 * a first-class screen rather than an empty one.
 *
 * What is required comes from the server (`missingForApplication`), not from
 * a list compiled in here. Change what POKBON asks for and the app follows.
 */
import { router } from 'expo-router';
import React, { useState } from 'react';
import { TextInput, View } from 'react-native';
import { Button, Card, H1, H2, Notice, P, Screen } from '../components/ui';
import { rider as riderApi } from '../lib/api';
import { config, copy } from '../lib/config';
import { useSession } from '../lib/session';
import { useTheme } from '../lib/theme';

/** Label and keyboard for each field the server may ask for. */
const FIELDS: Record<string, { label: string; hint?: string; keyboard?: 'default' | 'phone-pad' }> = {
  fullName: { label: 'Full name', hint: 'Exactly as it appears on your ID' },
  vehicleClass: { label: 'Vehicle', hint: 'MOTORBIKE' },
  vehicleRegistration: { label: 'Registration number' },
  baseZoneCode: { label: 'Where you are based' },
  idType: { label: 'ID type', hint: 'GHANA_CARD or VOTER_ID' },
  idNumber: { label: 'ID number' },
  licenceNumber: { label: 'Rider licence number' },
  momoNumber: { label: 'Mobile money number', hint: 'Where you want to be paid', keyboard: 'phone-pad' },
  nextOfKinName: { label: 'Next of kin' },
  nextOfKinPhone: { label: 'Next of kin phone', keyboard: 'phone-pad' },
  photoUrl: { label: 'Your photo' },
  idPhotoUrl: { label: 'Photo of your ID' },
  licencePhotoUrl: { label: 'Photo of your licence' },
};

export default function Apply() {
  const { c, radius, space } = useTheme();
  const { rider, refresh, signOut } = useSession();

  const [draft, setDraft] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  if (!rider) {
    return (
      <Screen>
        <P muted>Loading…</P>
      </Screen>
    );
  }

  const input = {
    backgroundColor: c.backgroundSecondary,
    borderColor: c.border,
    borderWidth: 1,
    borderRadius: radius.md,
    color: c.textPrimary,
    minHeight: 52,
    paddingHorizontal: 14,
    fontSize: 16,
  };

  // ── waiting, refused, suspended ──────────────────────────────────

  if (rider.status === 'APPLIED') {
    return (
      <Screen>
        <H1>Thank you</H1>
        <Notice tone="info">
          Your application is with POKBON. Someone will look at it and call you if anything is unclear.
        </Notice>
        <Card>
          <H2>While you wait</H2>
          <P muted>{copy('onboarding', 'contractorNote')}</P>
          <P muted>{copy('onboarding', 'commissionZero')}</P>
        </Card>
        <Button title="Check again" onPress={() => void refresh()} />
        <Button title="Sign out" kind="ghost" onPress={() => void signOut()} />
      </Screen>
    );
  }

  if (rider.status === 'SUSPENDED' || rider.status === 'REJECTED' || rider.status === 'LEFT') {
    const text = {
      SUSPENDED: 'Your account is suspended. Call POKBON to sort it out.',
      REJECTED: 'This application was not accepted. Call POKBON if you think that is wrong.',
      LEFT: 'You have left the platform. Call POKBON if you want to come back.',
    }[rider.status];

    return (
      <Screen>
        <H1>{rider.status === 'SUSPENDED' ? 'Suspended' : 'Not active'}</H1>
        <Notice tone="warning">{text}</Notice>
        <Button
          title={`Call ${config().brand.supportPhone}`}
          onPress={() => void import('react-native').then((rn) => rn.Linking.openURL(`tel:${config().brand.supportPhone}`))}
        />
        <Button title="Sign out" kind="ghost" onPress={() => void signOut()} />
      </Screen>
    );
  }

  // ── filling it in ────────────────────────────────────────────────

  const missing = rider.missingForApplication ?? [];
  const needsAgreement = !rider.agreement.upToDate;
  // The agreement is tracked separately from the other fields: accepting it is
  // an action with a date, not a value typed into a box.
  const stillMissing = missing.filter((k) => k !== 'agreementVersion' && !draft[k]);

  const save = async () => {
    setBusy(true);
    setError('');
    try {
      if (Object.keys(draft).length) await riderApi.update(draft);
      if (needsAgreement) await riderApi.acceptAgreement(rider.agreement.current);
      await riderApi.apply();
      await refresh();
      router.replace('/');
    } catch (e) {
      setError(e instanceof Error ? e.message : copy('errors', 'generic'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <H1>Become a POKBON rider</H1>

      <Card>
        <P>{copy('onboarding', 'contractorNote')}</P>
        <P muted>{copy('onboarding', 'commissionZero')}</P>
        <P muted>{copy('onboarding', 'licenceNote')}</P>
        <P muted>{copy('onboarding', 'idNote')}</P>
      </Card>

      {missing
        .filter((key) => key !== 'agreementVersion')
        .map((key) => {
          const field = FIELDS[key] ?? { label: key };
          return (
            <View key={key} style={{ gap: 4 }}>
              <P>{field.label}</P>
              {field.hint ? <P muted style={{ fontSize: 13 }}>{field.hint}</P> : null}
              <TextInput
                style={input}
                value={draft[key] ?? ''}
                onChangeText={(v) => setDraft((d) => ({ ...d, [key]: v }))}
                keyboardType={field.keyboard ?? 'default'}
                autoCapitalize={key === 'idType' || key === 'vehicleClass' ? 'characters' : 'words'}
                placeholderTextColor={c.textLight}
                editable={!busy}
              />
            </View>
          );
        })}

      {needsAgreement ? (
        <Notice tone="info">
          Submitting accepts the POKBON rider agreement, version {rider.agreement.current}. It says you are an
          independent contractor, that you can stop at any time, and that you never collect cash.
        </Notice>
      ) : null}

      {error ? <Notice tone="error">{error}</Notice> : null}

      <Button
        title="Submit my application"
        onPress={save}
        busy={busy}
        disabled={stillMissing.length > 0}
      />
      {stillMissing.length ? (
        <P muted>Still needed: {stillMissing.map((k) => (FIELDS[k]?.label ?? k)).join(', ')}</P>
      ) : null}

      <View style={{ height: space.lg }} />
      <Button title="Sign out" kind="ghost" onPress={() => void signOut()} />
    </Screen>
  );
}
