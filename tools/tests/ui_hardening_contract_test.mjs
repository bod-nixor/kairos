import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const style = fs.readFileSync(path.join(root, 'public/css/style.css'), 'utf8');
const ui = fs.readFileSync(path.join(root, 'public/css/kairos-ui.css'), 'utf8');
const lms = fs.readFileSync(path.join(root, 'public/css/lms.css'), 'utf8');

const failures = [];

function findTokenBlock(css, selectorPattern, requiredToken) {
  const blocks = [...css.matchAll(new RegExp(`${selectorPattern}\\s*\\{([\\s\\S]*?)\\}`, 'gm'))];
  return blocks.map(match => match[1]).find(block => block.includes(requiredToken)) || '';
}

const lightBlock = findTokenBlock(
  style,
  String.raw`(?:^|\n)\s*(?::root\s*,\s*\[data-theme=["']light["']\]|\[data-theme=["']light["']\]|:root)`,
  '--text-secondary',
);
const semanticBlock = findTokenBlock(style, String.raw`(?:^|\n)\s*:root`, '--status-success-bg');

if (!lightBlock) failures.push('could not locate the light-theme token block');
if (!semanticBlock) failures.push('could not locate the semantic status token block');

function token(block, name) {
  const value = block.match(new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{6})\\s*;`))?.[1];
  if (!value) failures.push(`missing --${name}`);
  return value || '#000000';
}

function luminance(hex) {
  const channels = [1, 3, 5].map(index => Number.parseInt(hex.slice(index, index + 2), 16) / 255);
  const linear = channels.map(value => value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4);
  return (0.2126 * linear[0]) + (0.7152 * linear[1]) + (0.0722 * linear[2]);
}

function contrast(foreground, background) {
  const a = luminance(foreground);
  const b = luminance(background);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

function requireContrast(label, foreground, background, minimum) {
  const actual = contrast(foreground, background);
  if (actual < minimum) {
    failures.push(`${label} contrast ${actual.toFixed(2)} is below ${minimum}`);
  }
}

const panel = token(lightBlock, 'panel');
const input = token(lightBlock, 'input-bg');
requireContrast('primary text', token(lightBlock, 'text'), panel, 7);
requireContrast('secondary text', token(lightBlock, 'text-secondary'), panel, 4.5);
requireContrast('muted text', token(lightBlock, 'muted'), panel, 4.5);
requireContrast('disabled text', token(lightBlock, 'text-disabled'), panel, 4.5);
requireContrast('placeholder text', token(lightBlock, 'placeholder'), input, 4.5);
requireContrast('link text', token(lightBlock, 'link'), panel, 4.5);
requireContrast('focus ring', token(lightBlock, 'focus-ring'), panel, 3);
requireContrast('control border', token(lightBlock, 'control-border'), input, 3);

for (const status of ['success', 'warning', 'danger', 'info', 'neutral']) {
  requireContrast(
    `${status} status`,
    token(semanticBlock, `status-${status}-text`),
    token(semanticBlock, `status-${status}-bg`),
    4.5,
  );
}

for (const needle of ['::placeholder', ':disabled', 'var(--control-border)', 'var(--focus-ring)', 'var(--link)']) {
  if (!`${style}\n${ui}\n${lms}`.includes(needle)) {
    failures.push(`shared UI CSS missing ${needle}`);
  }
}
if (!/\.k-item-reorder-controls\s*\{[\s\S]*?display:\s*inline-flex/.test(lms)) {
  failures.push('item keyboard reorder controls must remain visible at desktop breakpoints');
}
if (!lms.includes('.k-form-field input:focus-visible')) {
  failures.push('form fields must use focus-visible for the custom focus ring');
}

if (failures.length) {
  console.error(failures.join('\n'));
  process.exit(1);
}

console.log('UI hardening contrast and token tests passed');
