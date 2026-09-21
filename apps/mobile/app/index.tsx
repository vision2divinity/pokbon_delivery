/**
 * Where a rider lands, decided by what the server says about them.
 *
 * Deliberately server-driven: the app does not decide who may go on duty or
 * whose application is complete. It asks, and renders the answer. That keeps
 * the rule in one place and lets it change without a release.
 */
import { Redirect } from 'expo-router';
import React from 'react';
import { ActivityIndicator, View } from 'react-native';
import { Notice, P, Screen } from '../components/ui';
import { useSession } from '../lib/session';
import { useTheme } from '../lib/theme';

export default function Index() {
  const { phase, rider, offline } = useSession();
  const { c } = useTheme();

  if (phase === 'starting') {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: c.background }}>
        <ActivityIndicator size="large" color={c.primary} />
      </View>
    );
  }

  if (phase === 'signedOut') {
    return <Redirect href="/sign-in" />;
  }

  if (!rider) {
    return (
      <Screen>
        <Notice tone="warning">
          {offline
            ? 'No signal, so we could not load your account. Pull down to try again when you have a bar or two.'
            : 'We could not load your account.'}
        </Notice>
      </Screen>
    );
  }

  // An approved rider goes to work. Everyone else goes to the application,
  // which explains where they are in the process rather than dead-ending.
  if (rider.status === 'APPROVED') {
    return <Redirect href="/rider" />;
  }

  return <Redirect href="/apply" />;
}
