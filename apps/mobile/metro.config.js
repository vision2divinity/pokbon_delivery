// Metro, told how to live in a monorepo.
//
// Without this file the app bundles but dies on first render with "Invalid
// hook call ... you might have more than one copy of React in the same app",
// and that is exactly what was happening: react-native sits in the workspace
// root and resolves `react` by walking up from its own directory, finding the
// root's React, while the app's own components resolve the copy in
// apps/mobile/node_modules. Two Reacts, one renderer, no hooks.
//
// The versions differ on purpose. React Native 0.81 is built against React
// 19.1, which is what apps/mobile pins; the root carries 19.3 hoisted from
// something else entirely. So the app's copy is the correct one and must win.
const { getDefaultConfig } = require('expo/metro-config');
const path = require('node:path');

const projectRoot = __dirname;
const workspaceRoot = path.resolve(projectRoot, '../..');

const config = getDefaultConfig(projectRoot);

// packages/shared is source, not a published package, so Metro has to watch it.
config.watchFolders = [workspaceRoot];

// Order matters: the app's own node_modules is asked first.
config.resolver.nodeModulesPaths = [
  path.resolve(projectRoot, 'node_modules'),
  path.resolve(workspaceRoot, 'node_modules'),
];

// The line that actually fixes it. Without it Metro still walks up from each
// file's own directory, so react-native keeps finding the root's React no
// matter what order the paths above are in.
config.resolver.disableHierarchicalLookup = true;

module.exports = config;
