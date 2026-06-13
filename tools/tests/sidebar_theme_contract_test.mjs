import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = relativePath => fs.readFileSync(path.join(root, relativePath), 'utf8');
const style = read('public/css/style.css');
const ui = read('public/css/kairos-ui.css');
const themes = ['light', 'dark', 'midnight', 'graphite', 'indigo', 'emerald'];

function cssBlock(pattern, label) {
  const match = style.match(pattern);
  assert.ok(match, `missing ${label} token block`);
  return match[1];
}

function declarations(block) {
  return Object.fromEntries(
    [...block.matchAll(/--([a-z0-9-]+)\s*:\s*([^;]+);/gi)]
      .map(match => [match[1], match[2].trim()]),
  );
}

const themeVars = Object.fromEntries(themes.map(theme => [
  theme,
  declarations(cssBlock(
    new RegExp(`\\[data-theme="${theme}"\\]\\s*\\{([\\s\\S]*?)\\n\\}`, 'm'),
    theme,
  )),
]));
const darkSemanticVars = declarations(cssBlock(
  /\[data-theme="dark"\],\s*\[data-theme="midnight"\],\s*\[data-theme="graphite"\],\s*\[data-theme="indigo"\],\s*\[data-theme="emerald"\]\s*\{([\s\S]*?)\n\}/m,
  'shared dark sidebar semantic',
));

for (const theme of themes.slice(1)) {
  Object.assign(themeVars[theme], darkSemanticVars);
}

function resolveValue(theme, name, seen = new Set()) {
  assert.ok(!seen.has(name), `${theme} has a circular token reference at --${name}`);
  const value = themeVars[theme][name];
  assert.ok(value, `${theme} is missing --${name}`);
  const reference = value.match(/^var\(--([a-z0-9-]+)\)$/i);
  if (!reference) return value;
  seen.add(name);
  return resolveValue(theme, reference[1], seen);
}

function parseColor(value) {
  const hex = value.match(/^#([0-9a-f]{6})$/i);
  if (hex) {
    return {
      r: Number.parseInt(hex[1].slice(0, 2), 16),
      g: Number.parseInt(hex[1].slice(2, 4), 16),
      b: Number.parseInt(hex[1].slice(4, 6), 16),
      a: 1,
    };
  }
  const rgba = value.match(/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*([\d.]+))?\s*\)$/i);
  assert.ok(rgba, `unsupported color value ${value}`);
  return {
    r: Number(rgba[1]),
    g: Number(rgba[2]),
    b: Number(rgba[3]),
    a: rgba[4] === undefined ? 1 : Number(rgba[4]),
  };
}

function composite(foreground, background) {
  return {
    r: Math.round((foreground.r * foreground.a) + (background.r * (1 - foreground.a))),
    g: Math.round((foreground.g * foreground.a) + (background.g * (1 - foreground.a))),
    b: Math.round((foreground.b * foreground.a) + (background.b * (1 - foreground.a))),
    a: 1,
  };
}

function channel(value) {
  const normalized = value / 255;
  return normalized <= 0.04045
    ? normalized / 12.92
    : ((normalized + 0.055) / 1.055) ** 2.4;
}

function luminance(color) {
  return (0.2126 * channel(color.r)) + (0.7152 * channel(color.g)) + (0.0722 * channel(color.b));
}

function contrast(foreground, background) {
  const a = luminance(foreground);
  const b = luminance(background);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

function color(theme, token, background = null) {
  const value = resolveValue(theme, token);
  const parsed = parseColor(value);
  return background && parsed.a < 1 ? composite(parsed, background) : parsed;
}

function ratio(theme, foregroundToken, backgroundToken) {
  const base = color(theme, 'sidebar-bg');
  const background = color(theme, backgroundToken, base);
  return contrast(color(theme, foregroundToken, background), background);
}

const textPairs = [
  ['brand', 'sidebar-brand-text', 'sidebar-bg'],
  ['section', 'sidebar-section-text', 'sidebar-bg'],
  ['navigation', 'sidebar-nav-text', 'sidebar-bg'],
  ['icon', 'sidebar-nav-icon', 'sidebar-bg'],
  ['profile name', 'sidebar-profile-name', 'sidebar-bg'],
  ['profile role', 'sidebar-profile-role', 'sidebar-bg'],
  ['control', 'sidebar-control-text', 'sidebar-bg'],
  ['disabled', 'sidebar-disabled-text', 'sidebar-bg'],
  ['hover text', 'sidebar-nav-hover-text', 'sidebar-nav-hover-bg'],
  ['hover icon', 'sidebar-nav-hover-icon', 'sidebar-nav-hover-bg'],
  ['active text', 'sidebar-nav-active-text', 'sidebar-nav-active-bg'],
  ['active icon', 'sidebar-nav-active-icon', 'sidebar-nav-active-bg'],
];

const results = {};
for (const theme of themes) {
  results[theme] = {};
  for (const [label, foreground, background] of textPairs) {
    const actual = ratio(theme, foreground, background);
    results[theme][label] = actual;
    assert.ok(actual >= 4.5, `${theme} ${label} contrast ${actual.toFixed(2)} is below 4.5`);
  }

  const sidebarBackground = color(theme, 'sidebar-bg');
  const focusRatio = contrast(color(theme, 'sidebar-focus-ring'), sidebarBackground);
  const markerRatio = contrast(color(theme, 'sidebar-nav-active-marker'), sidebarBackground);
  assert.ok(focusRatio >= 3, `${theme} focus ring contrast ${focusRatio.toFixed(2)} is below 3`);
  assert.ok(markerRatio >= 3, `${theme} active marker contrast ${markerRatio.toFixed(2)} is below 3`);
  results[theme].focus = focusRatio;
  results[theme].marker = markerRatio;

  assert.notEqual(
    resolveValue(theme, 'sidebar-nav-hover-bg'),
    resolveValue(theme, 'sidebar-bg'),
    `${theme} hover state must differ from the sidebar background`,
  );
  assert.notEqual(
    resolveValue(theme, 'sidebar-nav-active-bg'),
    resolveValue(theme, 'sidebar-bg'),
    `${theme} active state must differ from the sidebar background`,
  );
}

assert.ok(
  contrast(color('light', 'sidebar-border'), color('light', 'sidebar-bg')) >= 3,
  'light sidebar boundary contrast must be at least 3',
);

const semanticTokens = [
  'sidebar-bg',
  'sidebar-border',
  'sidebar-brand-text',
  'sidebar-section-text',
  'sidebar-nav-text',
  'sidebar-nav-icon',
  'sidebar-nav-hover-bg',
  'sidebar-nav-hover-text',
  'sidebar-nav-active-bg',
  'sidebar-nav-active-text',
  'sidebar-nav-active-icon',
  'sidebar-profile-name',
  'sidebar-profile-role',
  'sidebar-control-text',
  'sidebar-focus-ring',
];
for (const token of semanticTokens) {
  assert.ok(themeVars.light[token], `light theme is missing --${token}`);
}

const selectorContracts = [
  [/\.k-sidebar__wordmark\s*\{[\s\S]*?color:\s*var\(--sidebar-brand-text\)/, 'brand text'],
  [/\.k-nav-group__label\s*\{[\s\S]*?color:\s*var\(--sidebar-section-text\)/, 'section label'],
  [/\.k-nav-item\s*\{[\s\S]*?color:\s*var\(--sidebar-nav-text\)/, 'navigation text'],
  [/\.k-nav-item__icon\s*\{[\s\S]*?color:\s*var\(--sidebar-nav-icon\)/, 'navigation icon'],
  [/\.k-sidebar__user-name\s*\{[\s\S]*?color:\s*var\(--sidebar-profile-name\)/, 'profile name'],
  [/\.k-sidebar__user-role\s*\{[\s\S]*?color:\s*var\(--sidebar-profile-role\)/, 'profile role'],
  [/\.k-sidebar__logout\s*\{[\s\S]*?color:\s*var\(--sidebar-control-text\)/, 'sidebar control'],
  [/\.k-nav-item:disabled,[\s\S]*?opacity:\s*1/, 'disabled navigation'],
];
for (const [pattern, label] of selectorContracts) {
  assert.match(style, pattern, `${label} does not consume its semantic sidebar token`);
}

assert.match(ui, /--focus-ring-context:\s*var\(--sidebar-focus-ring\)/);
assert.match(ui, /linear-gradient\(180deg,\s*var\(--sidebar-sheen\)/);
assert.match(ui, /@media \(max-width: 1024px\)[\s\S]*?\.k-sidebar\s*\{[\s\S]*?var\(--k-drawer-width\)/);
assert.match(ui, /@media \(max-width: 640px\)[\s\S]*?\.k-sidebar\s*\{[\s\S]*?var\(--sidebar-border\)/);
assert.match(style, /body\.k-page-body\s*\{[\s\S]*?overflow-x:\s*hidden/);

const requiredPages = [
  'index.html',
  'course.html',
  'modules.html',
  'assignments.html',
  'grading.html',
  'analytics.html',
  'manager.html',
  'admin.html',
  'settings.html',
  'room.html',
  'ta.html',
];
for (const page of requiredPages) {
  const html = read(`templates/pages/${page}`);
  for (const className of ['k-sidebar', 'k-nav-item', 'k-nav-item__icon']) {
    assert.match(html, new RegExp(`class=["'][^"']*\\b${className}\\b`), `${page} is missing .${className}`);
  }
}

const index = read('templates/pages/index.html');
for (const roleLabel of ['Student', 'TA Dashboard', 'Manager', 'Admin']) {
  assert.ok(index.includes(roleLabel), `dashboard sidebar is missing the ${roleLabel} role surface`);
}
for (const [page, roleLabel] of [['ta.html', 'TA'], ['manager.html', 'Manager'], ['admin.html', 'Admin']]) {
  assert.ok(read(`templates/pages/${page}`).includes(roleLabel), `${page} is missing its ${roleLabel} identity`);
}

const lightSummary = {
  background: resolveValue('light', 'sidebar-bg'),
  brand: `${resolveValue('light', 'sidebar-brand-text')} (${results.light.brand.toFixed(2)}:1)`,
  section: `${resolveValue('light', 'sidebar-section-text')} (${results.light.section.toFixed(2)}:1)`,
  navigation: `${resolveValue('light', 'sidebar-nav-text')} (${results.light.navigation.toFixed(2)}:1)`,
  icon: `${resolveValue('light', 'sidebar-nav-icon')} (${results.light.icon.toFixed(2)}:1)`,
  profileName: `${resolveValue('light', 'sidebar-profile-name')} (${results.light['profile name'].toFixed(2)}:1)`,
  profileRole: `${resolveValue('light', 'sidebar-profile-role')} (${results.light['profile role'].toFixed(2)}:1)`,
  hover: `${resolveValue('light', 'sidebar-nav-hover-text')} on ${resolveValue('light', 'sidebar-nav-hover-bg')} (${results.light['hover text'].toFixed(2)}:1)`,
  active: `${resolveValue('light', 'sidebar-nav-active-text')} on ${resolveValue('light', 'sidebar-nav-active-bg')} (${results.light['active text'].toFixed(2)}:1)`,
  focus: `${resolveValue('light', 'sidebar-focus-ring')} (${results.light.focus.toFixed(2)}:1)`,
};

console.log(`Light sidebar contrast: ${JSON.stringify(lightSummary)}`);
console.log(`Sidebar theme contract tests passed for: ${themes.join(', ')}`);
