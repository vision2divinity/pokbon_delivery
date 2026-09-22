/**
 * The shell. Two providers and a stack, nothing else.
 *
 * SessionProvider loads the config before anything renders, so no screen ever
 * paints in the wrong colours and then snaps to the right ones.
 */
import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import React from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { SessionProvider } from '../lib/session';
/*
 * Imported for its side effect, not for anything it exports.
 *
 * Android can restart the process and replay a background location task
 * without any screen ever mounting. The task has to be registered by then or
 * the fix is dropped, so it is defined at module scope and imported from the
 * one file that always loads.
 */
import '../lib/duty-location';
import { ThemeProvider, useTheme } from '../lib/theme';

function Navigator() {
  const { c, isDark } = useTheme();
  return (
    <>
      <StatusBar style={isDark ? 'light' : 'dark'} />
      <Stack
        screenOptions={{
          headerStyle: { backgroundColor: c.background },
          headerTintColor: c.textPrimary,
          headerTitleStyle: { color: c.textPrimary },
          contentStyle: { backgroundColor: c.background },
        }}
      >
        <Stack.Screen name="index" options={{ headerShown: false }} />
        <Stack.Screen name="sign-in" options={{ title: 'Sign in' }} />
        <Stack.Screen name="apply" options={{ title: 'Become a rider' }} />
        <Stack.Screen name="rider" options={{ headerShown: false }} />
        <Stack.Screen name="job/[id]" options={{ title: 'Delivery' }} />
      </Stack>
    </>
  );
}

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <SessionProvider>
        <ThemeProvider>
          <Navigator />
        </ThemeProvider>
      </SessionProvider>
    </SafeAreaProvider>
  );
}
