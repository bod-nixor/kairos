(function() {
  const STORAGE_KEY = 'kairos-theme';
  const SETTINGS_KEY = 'kairos-ui-settings';
  const HOME_PATH = '/signoff/';
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

  const readSettings = () => {
    try {
      const parsed = JSON.parse(localStorage.getItem(SETTINGS_KEY) || '{}');
      return {
        gradient: typeof parsed.gradient === 'string' ? parsed.gradient : 'ocean',
        compactMode: parsed.compactMode === true,
        reduceMotion: parsed.reduceMotion === true,
      };
    } catch (_) {
      return { gradient: 'ocean', compactMode: false, reduceMotion: false };
    }
  };

  const saveSettings = (patch) => {
    const next = { ...readSettings(), ...(patch || {}) };
    try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(next)); } catch (_) { /* ignore */ }
    return next;
  };

  const applyUiSettings = (settings) => {
    const next = settings || readSettings();
    root.dataset.gradientTheme = next.gradient || 'ocean';
    root.classList.toggle('ui-compact', !!next.compactMode);
    root.classList.toggle('ui-reduce-motion', !!next.reduceMotion);
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

  const homeUrl = () => `${window.location.origin}${HOME_PATH}`;

  const normalizeHomeLinks = () => {
    document.querySelectorAll('a[href]').forEach((anchor) => {
      const href = (anchor.getAttribute('href') || '').trim();
      if (href === '/' || href === './' || href === '/index.html') {
        anchor.setAttribute('href', homeUrl());
      }
      if (anchor.dataset.homeLink === 'true') {
        anchor.setAttribute('href', homeUrl());
      }
    });
  };

  const ensureSettingsLauncher = () => {
    if (document.getElementById('kSettingsFab')) return;
    const btn = document.createElement('button');
    btn.id = 'kSettingsFab';
    btn.className = 'k-settings-fab';
    btn.type = 'button';
    btn.setAttribute('aria-label', 'Open settings');
    btn.innerHTML = '⚙️';
    document.body.appendChild(btn);

    const panel = document.createElement('section');
    panel.id = 'kSettingsPanel';
    panel.className = 'k-settings-panel hidden';
    panel.innerHTML = `
      <div class="k-settings-panel__header">
        <h3>Settings</h3>
        <button type="button" id="kSettingsClose" aria-label="Close settings">✕</button>
      </div>
      <label class="k-settings-row">Theme Mode
        <button class="theme-toggle" data-theme-toggle aria-label="Toggle dark mode">
          <span class="theme-toggle__icon theme-toggle__icon--sun" aria-hidden="true">☀️</span>
          <span class="theme-toggle__icon theme-toggle__icon--moon" aria-hidden="true">🌙</span>
          <span class="theme-toggle__thumb"></span>
        </button>
      </label>
      <label class="k-settings-row">Gradient
        <select id="kGradientTheme">
          <option value="ocean">Ocean</option>
          <option value="sunset">Sunset</option>
          <option value="forest">Forest</option>
          <option value="violet">Violet</option>
        </select>
      </label>
      <label class="k-settings-check"><input type="checkbox" id="kCompactMode"> Compact mode</label>
      <label class="k-settings-check"><input type="checkbox" id="kReduceMotion"> Reduce motion</label>
    `;
    document.body.appendChild(panel);

    btn.addEventListener('click', () => panel.classList.toggle('hidden'));
    panel.querySelector('#kSettingsClose')?.addEventListener('click', () => panel.classList.add('hidden'));

    const settings = readSettings();
    panel.querySelector('#kGradientTheme').value = settings.gradient;
    panel.querySelector('#kCompactMode').checked = settings.compactMode;
    panel.querySelector('#kReduceMotion').checked = settings.reduceMotion;

    panel.querySelector('#kGradientTheme')?.addEventListener('change', (e) => applyUiSettings(saveSettings({ gradient: e.target.value })));
    panel.querySelector('#kCompactMode')?.addEventListener('change', (e) => applyUiSettings(saveSettings({ compactMode: e.target.checked })));
    panel.querySelector('#kReduceMotion')?.addEventListener('change', (e) => applyUiSettings(saveSettings({ reduceMotion: e.target.checked })));

    document.querySelectorAll('[data-theme-toggle]').forEach((el) => {
      if (el.closest('#kSettingsPanel')) return;
      el.remove();
    });
    syncToggle(resolvePreferredTheme());
    panel.classList.add('hidden');
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
    applyUiSettings(readSettings());
    normalizeHomeLinks();
    ensureSettingsLauncher();
    document.querySelectorAll('[data-theme-toggle]').forEach((toggle) => {
      toggle.addEventListener('click', () => {
        const current = isValidTheme(root.dataset.theme) ? root.dataset.theme : resolvePreferredTheme();
        applyTheme(current === 'dark' ? 'light' : 'dark');
      });
    });
  });

  window.addEventListener('resize', () => {
    applyTheme(resolvePreferredTheme(), false);
    applyUiSettings(readSettings());
  });

  if (prefersDarkQuery && typeof prefersDarkQuery.addEventListener === 'function') {
    prefersDarkQuery.addEventListener('change', (event) => {
      if (readStoredTheme()) return;
      applyTheme(event.matches ? 'dark' : 'light', false);
    });
  }
})();
