/**
 * The shared pieces, all built from the live theme.
 *
 * Deliberately plain. A rider uses this in sunlight, one-handed, wearing a
 * helmet, often in a hurry: large targets, high contrast, no decoration that
 * costs legibility. Text sizes are generous for the same reason.
 */
import React from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleProp,
  Text,
  TextStyle,
  View,
  ViewStyle,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useTheme } from '../lib/theme';

export function Screen({
  children,
  scroll = true,
  refreshing,
}: {
  children: React.ReactNode;
  scroll?: boolean;
  refreshing?: boolean;
}) {
  const { c, space } = useTheme();
  const body = (
    <View style={{ padding: space.md, gap: space.md, flexGrow: 1 }}>
      {refreshing ? <ActivityIndicator color={c.primary} /> : null}
      {children}
    </View>
  );

  /*
   * Two things a rider actually hits, both reported from a doorstep.
   *
   * The keyboard covered the very field it opened to fill — the delivery code
   * sits low on the screen, so a rider was typing six digits blind while a
   * customer read them out. Android resizes the window (adjustResize in the
   * manifest), but with nothing below the last control there was nowhere to
   * scroll to, so the input stayed hidden.
   *
   * And the final button sat flush against the gesture bar on phones that
   * show one, which on this device put "Could not deliver" under the system
   * navigation. The safe-area inset was already honoured; what was missing
   * was any breathing room inside it.
   *
   * Hence the generous bottom padding rather than a clever measurement: it
   * gives the scroll somewhere to go when the keyboard appears and keeps the
   * last control clear of the bar when it has not.
   */
  const scrollable = (
    <ScrollView
      contentContainerStyle={{ flexGrow: 1, paddingBottom: space.xl * 2 }}
      keyboardShouldPersistTaps="handled"
      keyboardDismissMode="on-drag"
      // iOS insets the scroll view for the keyboard itself; Android does it
      // by resizing the window, so this is deliberately one-sided.
      automaticallyAdjustKeyboardInsets={Platform.OS === 'ios'}
    >
      {body}
    </ScrollView>
  );

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: c.background }} edges={['top', 'bottom']}>
      <KeyboardAvoidingView
        style={{ flex: 1 }}
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      >
        {scroll ? scrollable : body}
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

export function H1({ children }: { children: React.ReactNode }) {
  const { c } = useTheme();
  return (
    <Text style={{ fontSize: 26, fontWeight: '700', color: c.textPrimary }}>{children}</Text>
  );
}

export function H2({ children }: { children: React.ReactNode }) {
  const { c } = useTheme();
  return (
    <Text style={{ fontSize: 19, fontWeight: '700', color: c.textPrimary }}>{children}</Text>
  );
}

export function P({
  children,
  muted,
  style,
}: {
  children: React.ReactNode;
  muted?: boolean;
  style?: StyleProp<TextStyle>;
}) {
  const { c } = useTheme();
  return (
    <Text style={[{ fontSize: 16, lineHeight: 23, color: muted ? c.textSecondary : c.textPrimary }, style]}>
      {children}
    </Text>
  );
}

export function Card({ children, style }: { children: React.ReactNode; style?: StyleProp<ViewStyle> }) {
  const { c, radius, space } = useTheme();
  return (
    <View
      style={[
        {
          backgroundColor: c.backgroundSecondary,
          borderRadius: radius.lg,
          borderWidth: 1,
          borderColor: c.border,
          padding: space.md,
          gap: space.sm,
        },
        style,
      ]}
    >
      {children}
    </View>
  );
}

export type Tone = 'info' | 'success' | 'warning' | 'error';

export function Notice({ tone = 'info', children }: { tone?: Tone; children: React.ReactNode }) {
  const { c, radius, space } = useTheme();
  const map = {
    info: { bg: c.backgroundTertiary, fg: c.textPrimary, edge: c.info },
    success: { bg: c.successSurface, fg: c.successText, edge: c.success },
    warning: { bg: c.warningSurface, fg: c.warningText, edge: c.warning },
    error: { bg: c.errorSurface, fg: c.errorText, edge: c.error },
  }[tone];

  return (
    <View
      style={{
        backgroundColor: map.bg,
        borderLeftWidth: 4,
        borderLeftColor: map.edge,
        borderRadius: radius.sm,
        padding: space.md,
      }}
    >
      <Text style={{ color: map.fg, fontSize: 16, lineHeight: 23 }}>{children}</Text>
    </View>
  );
}

export function Button({
  title,
  onPress,
  kind = 'primary',
  disabled,
  busy,
}: {
  title: string;
  onPress: () => void;
  kind?: 'primary' | 'secondary' | 'danger' | 'ghost';
  disabled?: boolean;
  busy?: boolean;
}) {
  const { c, radius } = useTheme();

  const bg = {
    primary: c.primary,
    secondary: c.backgroundTertiary,
    danger: c.error,
    ghost: 'transparent',
  }[kind];

  const fg = {
    primary: c.textWhite,
    secondary: c.textPrimary,
    danger: c.textWhite,
    ghost: c.primaryText,
  }[kind];

  const off = disabled || busy;

  return (
    <Pressable
      onPress={onPress}
      disabled={off}
      accessibilityRole="button"
      accessibilityState={{ disabled: Boolean(off), busy: Boolean(busy) }}
      style={({ pressed }) => ({
        backgroundColor: bg,
        opacity: off ? 0.45 : pressed ? 0.85 : 1,
        borderRadius: radius.md,
        // 56 tall: this is pressed with a thumb, sometimes gloved.
        minHeight: 56,
        alignItems: 'center',
        justifyContent: 'center',
        paddingHorizontal: 20,
        borderWidth: kind === 'ghost' ? 1 : 0,
        borderColor: c.border,
      })}
    >
      {busy ? (
        <ActivityIndicator color={fg} />
      ) : (
        <Text style={{ color: fg, fontSize: 17, fontWeight: '700', textAlign: 'center' }}>{title}</Text>
      )}
    </Pressable>
  );
}

/**
 * The PAID banner. Its whole job is to be unmistakable at arm's length in
 * sunlight, because it is the one thing standing between a rider and handing
 * over goods that have not been paid for.
 */
export function PaidBanner({ text }: { text: string }) {
  const { c, radius, space } = useTheme();
  return (
    <View
      style={{
        backgroundColor: c.success,
        borderRadius: radius.lg,
        padding: space.lg,
        alignItems: 'center',
      }}
    >
      <Text style={{ color: '#FFFFFF', fontSize: 30, fontWeight: '900', textAlign: 'center' }}>
        {text}
      </Text>
    </View>
  );
}

export function Row({ children, gap }: { children: React.ReactNode; gap?: number }) {
  const { space } = useTheme();
  return <View style={{ flexDirection: 'row', gap: gap ?? space.sm }}>{children}</View>;
}

export function Field({ label, value }: { label: string; value: string }) {
  const { c } = useTheme();
  return (
    <View style={{ gap: 2 }}>
      <Text style={{ fontSize: 13, color: c.textLight, textTransform: 'uppercase', letterSpacing: 0.5 }}>
        {label}
      </Text>
      <Text style={{ fontSize: 16, color: c.textPrimary }}>{value}</Text>
    </View>
  );
}
