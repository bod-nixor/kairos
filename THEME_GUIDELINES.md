# Kairos Theme Guidelines

This document outlines the architectural approach to color theming within the Kairos platform. We use a full interface palette system, moving beyond simple accent-color swaps to create a cohesive, premium aesthetic resembling top-tier SaaS platforms like Vercel and Linear.

## Core Principles

1. **Semantic Variables**: Colors are defined functionally (e.g., `--bg`, `--panel`, `--text`, `--border`) rather than descriptively (e.g., `--white`, `--black`, `--gray-200`). This ensures seamless swapping between light and dark modes.
2. **Predictable Contrast**: Every layer stacked on the z-axis should have predictable contrast. Deep backgrounds (`--bg`) push backward, while content surfaces (`--panel`) step forward. 
3. **Subtle Elevation**: Use extremely subtle borders and faint shadows (`0 1px 3px rgba(0,0,0,0.02)`) over harsh dropshadows. In dark mode, rely more on border contrast than shadow.

## Variable Structure

### Backgrounds & Surfaces
- `--bg`: The absolute bottom layer of the page.
- `--panel`: Primary content containers (cards, forms, metrics).
- `--surface-subtle`: Secondary backgrounds (table headers, hovered elements, input fields).

### Text & Accents
- `--text`: The highest-contrast text color for primary legibility.
- `--muted`: De-emphasized text for metadata, timestamps, and secondary labels.
- `--primary`: The main action color used for primary buttons, active states, and focus rings. 
- `--primary-ghost`: A 10-15% opacity version of `--primary` used for active backgrounds or hover states without causing visual clutter.

### Status Indicators
Use the semantic status scale (`--ok`, `--warn`, `--danger`) consistently. Never hardcode green, yellow, or red into components.

## Implementation Rules
- Set base variables on the `:root` and `[data-theme="light"]` selectors.
- Override them cleanly in `[data-theme="dark"]`. No specific class overrides (e.g. `.card.dark-mode`) are permitted.
- Maintain the unified CSS structure where `style.css` defines the roots, and component files (`lms.css`, `manager.css`) purely consume them.
