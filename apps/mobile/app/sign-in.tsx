/**
 * Sign in by phone.
 *
 * A rider types a number, gets a code by SMS, types it back. No password:
 * riders lose passwords, share them, and write them inside helmets, and a
 * phone number is the thing POKBON needs to be able to reach anyway.
 *
 * The API echoes a development code only to a caller on the same machine, so
 * this screen can show it while testing and cannot leak one in production.
 */
import { router } from 'expo-router';
import React, { useState } from 'react';
import { TextInput, View } from 'react-native';
import { Button, H1, Notice, P, Row, Screen } from '../components/ui';
import { ApiError, apiBaseUrl, auth, isDevelopmentBuild, saveTokens, setApiBaseOverride } from '../lib/api';
import { config } from '../lib/config';
import { useSession } from '../lib/session';
import { useTheme } from '../lib/theme';

export default function SignIn() {
  const { c, radius, space } = useTheme();
  const { signedIn } = useSession();

  const [phone, setPhone] = useState('');
  const [code, setCode] = useState('');
  const [stage, setStage] = useState<'phone' | 'code'>('phone');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [devCode, setDevCode] = useState('');
  const [showAddress, setShowAddress] = useState(false);
  const [address, setAddress] = useState(apiBaseUrl());

  const input = {
    backgroundColor: c.backgroundSecondary,
    borderColor: c.border,
    borderWidth: 1,
    borderRadius: radius.md,
    color: c.textPrimary,
    fontSize: 20,
    minHeight: 56,
    paddingHorizontal: 16,
  };

  const send = async () => {
    setBusy(true);
    setError('');
    try {
      const result = await auth.requestOtp(phone.trim());
      setDevCode(result.devCode ?? '');
      setStage('code');
    } catch (e) {
      setError(
        e instanceof ApiError && e.status === 0
          ? 'No signal, or the delivery service cannot be reached.'
          : e instanceof Error
            ? e.message
            : 'Could not send the code.',
      );
    } finally {
      setBusy(false);
    }
  };

  const verify = async () => {
    setBusy(true);
    setError('');
    try {
      await saveTokens(await auth.verifyOtp(phone.trim(), code.trim()));
      await signedIn();
      router.replace('/');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'That code was not accepted.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <H1>{config().brand.name}</H1>

      {stage === 'phone' ? (
        <>
          <P muted>Enter your phone number. We will text you a code.</P>
          <TextInput
            style={input}
            value={phone}
            onChangeText={setPhone}
            placeholder="024 000 0000"
            placeholderTextColor={c.textLight}
            keyboardType="phone-pad"
            autoComplete="tel"
            editable={!busy}
          />
          <Button title="Send me a code" onPress={send} busy={busy} disabled={phone.trim().length < 9} />
        </>
      ) : (
        <>
          <P muted>We texted a code to {phone}. Enter it below.</P>
          <TextInput
            style={[input, { letterSpacing: 8, textAlign: 'center', fontSize: 28 }]}
            value={code}
            onChangeText={setCode}
            placeholder="000000"
            placeholderTextColor={c.textLight}
            keyboardType="number-pad"
            maxLength={config().rules.codeLength}
            autoComplete="sms-otp"
            editable={!busy}
          />
          {devCode ? <Notice tone="info">Development code: {devCode}</Notice> : null}
          <Button
            title="Sign in"
            onPress={verify}
            busy={busy}
            disabled={code.trim().length < config().rules.codeLength}
          />
          <Button title="Use a different number" kind="ghost" onPress={() => setStage('phone')} />
        </>
      )}

      {error ? <Notice tone="error">{error}</Notice> : null}

      {/*
        The address override exists because a test build has to point at a
        laptop, and a laptop's address changes with the network. Without this
        every change costs a rebuild, and the symptom on the phone looks like
        a broken app rather than a misconfigured one. Production ships https
        and never shows it.
      */}
      {isDevelopmentBuild ? (
        <View style={{ marginTop: space.xl, gap: space.sm }}>
          <Button
            title={showAddress ? 'Hide server address' : 'Server address'}
            kind="ghost"
            onPress={() => setShowAddress((v) => !v)}
          />
          {showAddress ? (
            <>
              <TextInput
                style={[input, { fontSize: 15 }]}
                value={address}
                onChangeText={setAddress}
                autoCapitalize="none"
                autoCorrect={false}
              />
              <Row>
                <View style={{ flex: 1 }}>
                  <Button title="Use this" kind="secondary" onPress={() => void setApiBaseOverride(address.trim())} />
                </View>
                <View style={{ flex: 1 }}>
                  <Button
                    title="Reset"
                    kind="secondary"
                    onPress={() => {
                      void setApiBaseOverride(null);
                      setAddress(apiBaseUrl());
                    }}
                  />
                </View>
              </Row>
            </>
          ) : null}
        </View>
      ) : null}
    </Screen>
  );
}
