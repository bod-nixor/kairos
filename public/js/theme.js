(function() {
  const STORAGE_KEY = 'kairos-theme';
  const root = document.documentElement;
  const prefersDarkQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  const isProjectorView = () => window.location.pathname.toLowerCase().includes('projector');
  if (isProjectorView()) {
    return;
  }

  const isValidTheme = (value) => value === 'light' || value === 'dark';

  const readStoredTheme = () => {
    try {
      const value = localStorage.getItem(STORAGE_KEY);
      return isValidTheme(value) ? value : null;
    } catch (err) {
      return null;
    }
  };

  const syncToggle = (theme) => {
    const toggle = document.querySelector('[data-theme-toggle]');
    if (!toggle) return;
    const isDark = theme === 'dark';
    toggle.classList.toggle('is-dark', isDark);
    toggle.setAttribute('aria-pressed', String(isDark));
    const label = toggle.querySelector('[data-theme-label]');
    if (label) {
      label.textContent = isDark ? 'Dark' : 'Light';
    }
  };

  const applyTheme = (theme, persist = true) => {
    const next = isValidTheme(theme) ? theme : 'light';
    root.dataset.theme = next;
    root.classList.toggle('theme-dark', next === 'dark');
    root.classList.toggle('theme-light', next !== 'dark');
    if (persist) {
      try { localStorage.setItem(STORAGE_KEY, next); } catch (err) { /* ignore */ }
    }
    syncToggle(next);
  };

  const resolvePreferredTheme = () => {
    const stored = readStoredTheme();
    if (stored) return stored;
    if (isValidTheme(root.dataset.theme)) return root.dataset.theme;
    return prefersDarkQuery && prefersDarkQuery.matches ? 'dark' : 'light';
  };

  document.addEventListener('DOMContentLoaded', () => {
    const preferred = resolvePreferredTheme();
    applyTheme(preferred, false);
    const toggle = document.querySelector('[data-theme-toggle]');
    if (toggle) {
      toggle.addEventListener('click', () => {
        const current = isValidTheme(root.dataset.theme) ? root.dataset.theme : resolvePreferredTheme();
        applyTheme(current === 'dark' ? 'light' : 'dark');
      });
    }
  });

  if (prefersDarkQuery && typeof prefersDarkQuery.addEventListener === 'function') {
    prefersDarkQuery.addEventListener('change', (event) => {
      if (readStoredTheme()) return;
      applyTheme(event.matches ? 'dark' : 'light', false);
    });
  }
})();
