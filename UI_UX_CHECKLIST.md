# Kairos UI/UX Checklist

Before submitting PRs or deploying changes, run through this comprehensive checklist to prevent visual, interactive, or accessibility regressions.

## 1. Visual Consistency 
- [ ] **Component Consistency:** Buttons, dropdowns, inputs, and cards use the standard `.k-` definitions from `style.css` / `lms.css`. No inline overrides unless strictly context-specific.
- [ ] **Color Contrast:** Foreground text passes contrast thresholds over the background (in both light and dark themes). Gray text is readable (`--muted`).
- [ ] **Typography Hierarchy:** Title usage follows `h1 > h2 > h3`. Base fonts aren't overridden.
- [ ] **Unified Shell:** Does the view inhabit a `.k-main` wrapped by a topbar and sidebar correctly? Does the breadcrumb accurately reflect state?
- [ ] **Shell Authority:** Is the page using the shared shell in `public/css/kairos-ui.css` and `public/js/theme.js` without page-local drawer scripts or duplicate overlay logic?
- [ ] **No Duplicate Branding:** Logos, product marks, and course identity blocks appear once per shell region. Avoid duplicated marks between sidebar, topbar, and page headers.
- [ ] **Course Nav Consistency:** On course pages, do `Home`, `Modules`, `Quizzes`, and `Assignments` remain visible in the same place? Are `Grading` / `Analytics` only gated by role, not by ad-hoc page markup?

## 2. Interaction & State Patterns
- [ ] **Hover/Focus Styles:** Buttons provide immediate feedback (translation or background opacity shift) when hovered or focused.
- [ ] **Disable States:** Forms mid-submission correctly disable controls, averting double posts. They provide visual indicator (`opacity: 0.5`, `cursor: not-allowed`).
- [ ] **Loaders/Skeletons:** Heavy API fetches employ `.k-skeleton` structures to retain viewport dimensions and prevent elements skipping down.
- [ ] **Empty States:** When a list is null (no modules, no queues), a clear graphic/icon alongside a helpful directive appears (e.g. `No announcements available. Check back tomorrow.`).
- [ ] **Visible Status Feedback:** Save/publish/submit actions surface success, pending, and failure states without relying on the console or silent button text changes.
- [ ] **Modal Safety:** Modals lock background interaction, fit inside the viewport, and keep close actions reachable on short screens.

## 3. Responsive & Mobile Functionality
- [ ] **Desktop (1180px+):** Sidebar, topbar, sticky side panels, and content widths feel balanced rather than stretched or crowded.
- [ ] **Max Width 1024px:** The hamburger menu correctly orchestrates `.k-sidebar.is-open`.
- [ ] **Max Width 1024px:** Body scroll locking and overlay dismissal work; pressing `Escape` closes the drawer and any stacked transient UI safely.
- [ ] **Tablet Range (761px-1024px):** Two-column layouts collapse before cards or forms become cramped. Sticky side panels release appropriately.
- [ ] **Narrow Tablet / Large Phone (641px-760px):** Breadcrumbs, topbar actions, and editor toolbars wrap without clipping labels or icons.
- [ ] **Max Width 640px (Mobile Check):**
  - Forms stack properly (no horizontal scrolling to discover input text).
  - Tappable areas are sufficient (nav items, buttons >= 44px bounds).
  - The Slide-in drawer closes cleanly.
  - Modals conform to 100% width padded adequately (`12px`) natively blocking horizontal overflow.
  - Tables wrap gracefully (`.table-wrap`) so the UI doesn't blow out horizontally.
- [ ] **Page Shell Coverage:** Lesson, resource viewer, quiz, assignment, grading, and analytics pages still sit inside the shared course shell at all target breakpoints.
- [ ] **Scaling Check:** Does UI handle basic browser zoom logically? (Check at 150%).

## 4. Accessibility & Navigation (a11y)
- [ ] **Keyboard Nav:** A user can `Tab` through input flow in standard sequential order.
- [ ] **ARIA Tags:** Toggle icons, modal close icons, and icon-only buttons declare semantic `aria-label` definitions. E.g `<button aria-label="Toggle Section Menu">`.
- [ ] **Focus Rings:** Ensure `:focus-visible` isn't entirely stripped without providing a visual contour for input elements or links.
- [ ] **Theme Persistence:** Dark and light modes remember settings from `localStorage` seamlessly.
- [ ] **Non-Color Signals:** Errors, warnings, and status badges are understandable without depending only on hue.

## 5. Typical Regressions to Verify
- [ ] Is the sidebar overlapping text content at specific medium breakpoints?
- [ ] Did the global `themeToggle` button lose its event listener when a specific view loads?
- [ ] Did a page reintroduce inline shell/layout styling that competes with shared utilities?
- [ ] Did a lesson, resource, quiz, or assignment page fall out of the shared course shell?
- [ ] Did a course page accidentally hide `Quizzes` or `Assignments` again because of page-specific nav markup?
- [ ] Are dynamic content injections overwriting base layout classes?
- [ ] Have popups/toasts stacked cleanly, or do they push screen geometries unnaturally?
