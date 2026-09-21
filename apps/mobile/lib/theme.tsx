/**
 * Colours and spacing, from the plugin rather than from here.
 *
 * `useTheme()` returns whatever wp-admin last published, so changing the brand
 * orange is an admin edit. The tokens are POKBON's, shared with the
 * marketplace app, so the two never drift into two palettes.
 *
 * WHY THERE IS NO StyleSheet.create IN THIS APP. RN snapshots styles at
 * creation time, so a themeable app that builds its styles once can never
 * repaint when the theme changes — the marketplace app has that problem and
 * has to prompt for a reload. Here styles are built inside render from the
 * live tokens. It costs a little per frame and buys a theme that updates the
 * moment the config does.
 */
import React, { createContext, useContext, useMemo } from 'react';
import { useColorScheme } from 'react-native';
import { config, feature, ThemeTokens } from './config';

interface ThemeValue {
  c: ThemeTokens;
  radius: Record<string, number>;
  space: Record<string, number>;
  isDark: boolean;
}

const ThemeContext = createContext<ThemeValue | null>(null);

export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const scheme = useColorScheme();
  const cfg = config();

  const value = useMemo<ThemeValue>(() => {
    // Dark mode is a feature the owner can withdraw. If it is off, a phone set
    // to dark still gets the light palette rather than a half-styled screen.
    const isDark = scheme === 'dark' && feature('darkMode');
    return {
      c: isDark ? cfg.theme.dark : cfg.theme.light,
      radius: cfg.theme.radius,
      space: cfg.theme.spacing,
      isDark,
    };
  }, [scheme, cfg]);

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeValue {
  const value = useContext(ThemeContext);
  if (!value) {
    throw new Error('useTheme() outside ThemeProvider');
  }
  return value;
}
