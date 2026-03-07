# Kairos UI/UX Design Guidelines

This document outlines the core design systems and aesthetic principles for the Kairos Learning Management System.

## Visual Aesthetics

### 1. Colors & Theming
Kairos uses a modern variable-driven approach mapped to both light and dark themes. We rely on a robust semantic palette to ensure contrast and readability natively on every viewport.

- **Backgrounds:** `--bg`, `--panel`, `--surface-subtle`. Used to layer depth with panels popping visually off the softer foundation background.
- **Accents:** `--primary` (blue by default) drives interactive feedback. Subdued ghost buttons use `--primary-ghost` (12-20% opacity).
- **Text Hierarchy:** 
  - `--text` (Main headline and body color)
  - `--muted` (Subtitles, labels, disabled states)
- **Status:** We embrace semantic pill badges (e.g. `--ok` green, `--warn` yellow, `--danger` red / `--status-pending-bg` etc.) to indicate course or assignment state immediately. 

*Dark mode* reverses contrast metrics intelligently instead of a mere inversion—preserving legibility without eye strain by using slate (`#11151f`, `#1b202c`) instead of absolute black.

### 2. Spacing and Grids
A standardized spacing scale provides rhythmic consistency. Avoid arbitrary padding variables.
- `--space-1` (4px) to `--space-8` (32px), with `--space-12` (48px) for prominent section separation.
- Content relies on grid arrays with `gap: var(--space-4)` or `16px/24px` combinations. 
- Maximum grid responsiveness ensures mobile reflow using CSS `grid-template-columns: repeat(auto-fill, minmax(280px, 1fr))`.

### 3. Typography
- **Font Stack:**  `ui-sans-serif, system-ui, Segoe UI, Roboto, Arial`.
- Emphasis on hierarchy via font size and weight (e.g. `12px` `.small` mutated to `14px` base up to `--font-2xl`). 

## Components & Elements

### 1. The Layout Shell (`.k-layout`, `.k-sidebar`, `.k-topbar`)
Every page utilizes the `.k-layout` component to guarantee the sidebar and topbar structure remain structurally identical without causing jarring jumps between modules.
- **`.k-sidebar`:** Contains branding and contextual navigation. Fixed width, scales down to a sliding mobile drawer (`.is-open`) on small viewports via a hamburger toggle.
- **`.k-topbar`:** Constrains breadcrumbs, notifications, profile actions, and the unifying theme toggle mechanism. 

Shell authority rules:
- Shared shell styling belongs in `public/css/kairos-ui.css`.
- Shared drawer/open-close behavior belongs in `public/js/theme.js`.
- Do not add page-local hamburger scripts, page-local overlays, or page-local `resize` handlers that compete with the shared shell.
- Course surfaces must all use the same shell hierarchy: `.k-sidebar`, `.k-layout`, `.k-topbar`, `.k-main`, `.k-page`.

### 1.1 Course Navigation
- Keep `Home`, `Modules`, `Quizzes`, and `Assignments` available across course surfaces so navigation does not "disappear" when moving between pages.
- `Grading` and `Analytics` remain role-gated, but their placement in the nav should stay fixed.
- Breadcrumbs should always reflect the same hierarchy:
  - `All Courses > Course > Section > Current page`
- Lessons and resources should still live inside the course shell; they are not standalone documents.

### 2. Cards (`.k-card`)
A standard `.k-card` provides a white/slate container for discrete chunks of data (like quizzes, forms, settings).
- **Mobile handling:** All child elements in `.k-card` should stack appropriately if using `.grid-two`.

### 3. Interactive UX
- **Hover/Focus states:** All interactive elements (`.k-nav-item`, `.btn`) use a slight background translation or vertical `transform: translateY(-2px)` on hover to feel alive.
- **Skeletons:** Data loaders should use `.k-skeleton` to project layout before API responses arrive, staving off layout shift.

## Mobile and Responsive Strategy

- **Breakpoints:** 
  - `<= 1024px:` Sidebar condenses or shifts to allow content breathing room. Hamburger toggles enabled.
  - `<= 640px:` Sidebar becomes an absolute modal slide-in `transform: translateX(0)`. Tap targets (`min-height: 48px`) are enforced so fingers don't double-click links. Modal dialogs snap to `100%` width with minimal `12px` margins.

Practical layout rules:
- At `<= 1180px`, two-column content regions should collapse to one column unless there is a strong reason not to.
- At `<= 760px`, page-header actions, search rows, grading controls, and editor toolbars should wrap to full-width rows.
- Avoid fixed widths on form controls inside page headers; prefer utility classes (`.k-control-sm`, `.k-control-md`) that collapse cleanly.
- Tables must live inside `.k-table-wrap` and should degrade gracefully before causing page-level horizontal overflow.
- Sticky cards should disable themselves on narrower viewports where they compete with content height.

### 4. Shared Primitives
- Prefer shared utility classes from `public/css/kairos-ui.css` for page composition instead of inline layout styles.
- Reuse these primitives before creating page-specific CSS:
  - `.k-grid-auto`, `.k-grid-sidebar`, `.k-grid-split`
  - `.k-toolbar`, `.k-search-row`, `.k-progress-row`
  - `.k-card-grid`, `.k-state-card`, `.k-notice-banner`
  - `.k-editor-toolbar`, `.k-editor-surface`
  - `.k-resource-viewer`, `.k-workspace-empty`, `.k-workspace-active`
- Inline styles should be limited to stateful values that are genuinely data-driven, such as progress percentages or skeleton heights.

## Accessibility (A11y)
- **ARIA Labeling:** Interactive toggles (like the hamburger or `.theme-toggle`) mandate `aria-label`. Use `aria-hidden="true"` on non-semantic icons.
- **Focus Rings:** Avoid outlining inputs inconsistently. Rely on `:focus-visible` to give keyboard nav explicit indicators.
- **Contrast Check:** Text elements over backgrounds should meet a minimum of WCAG AA compliance (contrast ratio of 4.5:1).
