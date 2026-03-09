(function () {
  const STORAGE_KEY = 'kairos-theme';
  const SETTINGS_KEY = 'kairos-ui-settings';
  const LAST_DARK_KEY = 'kairos-last-dark-theme';
  const HOME_PATH = '/signoff/';
  const LEGACY_GRADIENT_VALUE = 'theme';
  const root = document.documentElement;
  const prefersDarkQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  const shellDrawerQuery = window.matchMedia ? window.matchMedia('(max-width: 1024px)') : null;
  const SETTINGS_PAGE_PATH = './settings.html';
  const THEMES = [
    {
      value: 'dark',
      label: 'Default Dark',
      preview: { sidebar: '#0d131f', main: '#11151f', card: '#1b202c', border: '#2c3242' },
    },
    {
      value: 'light',
      label: 'Light Mode',
      preview: { sidebar: '#0b1225', main: '#f7f8fb', card: '#ffffff', border: '#e6e8ef' },
    },
    {
      value: 'midnight',
      label: 'Midnight',
      preview: { sidebar: '#020617', main: '#0f172a', card: '#1e293b', border: '#334155' },
    },
    {
      value: 'graphite',
      label: 'Graphite',
      preview: { sidebar: '#171717', main: '#262626', card: '#404040', border: '#525252' },
    },
    {
      value: 'indigo',
      label: 'Indigo',
      preview: { sidebar: '#1e1b4b', main: '#312e81', card: '#4338ca', border: '#4f46e5' },
    },
    {
      value: 'emerald',
      label: 'Emerald',
      preview: { sidebar: '#064e3b', main: '#065f46', card: '#047857', border: '#059669' },
    },
  ];

  let serverSaveTimer = null;
  let settingsButton = null;
  let settingsPanel = null;
  let shellOverlay = null;
  let settingsObserver = null;
  let serverSettingsLoaded = false;
  let serverSettingsPromise = null;
  let storageOwner = null;
  let storageOwnerResolved = false;
  let storageOwnerPromise = null;

  const isProjectorView = () => window.location.pathname.toLowerCase().includes('projector');
  if (isProjectorView()) {
    return;
  }

  const isValidTheme = (value) => THEMES.some((theme) => theme.value === value);

  const isPreAuthView = () => !!document.body && document.body.classList.contains('k-pre-auth');

  const hasScopedOwner = () => storageOwnerResolved && !!storageOwner;

  const storageKeyFor = (baseKey, allowGuest = true) => {
    if (hasScopedOwner()) {
      return `${baseKey}:${storageOwner}`;
    }
    return allowGuest ? baseKey : null;
  };

  const readStorageValue = (baseKey, allowGuest = true) => {
    const key = storageKeyFor(baseKey, allowGuest);
    if (!key) {
      return null;
    }
    try {
      return localStorage.getItem(key);
    } catch (_) {
      return null;
    }
  };

  const writeStorageValue = (baseKey, value, { allowGuest = true, mirrorGlobal = false } = {}) => {
    const key = storageKeyFor(baseKey, allowGuest);
    if (!key) {
      return;
    }
    try {
      localStorage.setItem(key, value);
      if (mirrorGlobal && key !== baseKey) {
        localStorage.setItem(baseKey, value);
      }
    } catch (_) {
      // ignore
    }
  };

  const removeStorageValue = (baseKey, { allowGuest = true, removeGlobal = false } = {}) => {
    const key = storageKeyFor(baseKey, allowGuest);
    if (!key) {
      return;
    }
    try {
      localStorage.removeItem(key);
      if (removeGlobal && key !== baseKey) {
        localStorage.removeItem(baseKey);
      }
    } catch (_) {
      // ignore
    }
  };

  const ownerFromUser = (me) => {
    const userId = me && (me.user_id ?? me.id);
    if (userId !== undefined && userId !== null && String(userId).trim()) {
      return `user:${String(userId).trim()}`;
    }
    const email = me && typeof me.email === 'string' ? me.email.trim().toLowerCase() : '';
    return email ? `email:${email}` : null;
  };

  const resolveStorageOwner = async () => {
    if (storageOwnerResolved) {
      return storageOwner;
    }
    if (storageOwnerPromise) {
      return storageOwnerPromise;
    }

    storageOwnerPromise = (async () => {
      if (isPreAuthView()) {
        storageOwner = null;
        storageOwnerResolved = true;
        return null;
      }

      let me = null;
      try {
        if (window.KairosLMS && typeof window.KairosLMS.loadMe === 'function') {
          me = await window.KairosLMS.loadMe();
        } else if (window.KairosLMS && typeof window.KairosLMS.api === 'function') {
          const res = await window.KairosLMS.api('GET', './api/me.php');
          me = res.ok ? (res.data || null) : null;
        } else {
          const resp = await fetch('./api/me.php', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
          });
          if (resp.ok) {
            me = await resp.json();
          }
        }
      } catch (_) {
        me = null;
      }

      storageOwner = ownerFromUser(me);
      storageOwnerResolved = true;
      return storageOwner;
    })();

    try {
      return await storageOwnerPromise;
    } finally {
      storageOwnerPromise = null;
    }
  };

  const resetStorageOwner = (resolved = false) => {
    storageOwner = null;
    storageOwnerResolved = resolved;
    storageOwnerPromise = null;
  };

  const mirrorBootstrapTheme = (theme) => {
    if (!isValidTheme(theme)) {
      return;
    }
    try {
      localStorage.setItem(STORAGE_KEY, theme);
    } catch (_) {
      // ignore
    }
  };

  const readStoredTheme = (allowGuest = true) => {
    const value = readStorageValue(STORAGE_KEY, allowGuest);
    return isValidTheme(value) ? value : null;
  };

  const readStoredSettings = (allowGuest = true) => {
    try {
      const raw = readStorageValue(SETTINGS_KEY, allowGuest);
      if (!raw) {
        return null;
      }
      const parsed = JSON.parse(raw);
      return {
        gradient: LEGACY_GRADIENT_VALUE,
        compactMode: parsed.compactMode === true,
        reduceMotion: parsed.reduceMotion === true,
      };
    } catch (_) {
      return null;
    }
  };

  const readSettings = () => readStoredSettings()
    || { gradient: LEGACY_GRADIENT_VALUE, compactMode: false, reduceMotion: false };

  const canUseLmsApi = () => !!(window.KairosLMS && typeof window.KairosLMS.api === 'function');

  const canSyncSettingsServer = () => canUseLmsApi() && !isPreAuthView();

  const emitUiState = () => {
    document.dispatchEvent(new CustomEvent('kairos:ui-settings', {
      detail: {
        theme: isValidTheme(root.dataset.theme) ? root.dataset.theme : resolvePreferredTheme(),
        settings: readSettings(),
        authenticated: canSyncSettingsServer(),
      },
    }));
  };

  const syncToggle = (theme) => {
    const isDark = theme !== 'light';
    document.querySelectorAll('[data-theme-toggle]').forEach((toggle) => {
      toggle.classList.toggle('is-dark', isDark);
      toggle.setAttribute('aria-pressed', String(isDark));
      const label = toggle.querySelector('[data-theme-label]');
      if (label) {
        label.textContent = isDark ? 'Dark' : 'Light';
      }
    });
  };

  const syncSettingsInputs = (settings) => {
    const next = settings || readSettings();
    const compactInputs = document.querySelectorAll('#kCompactMode, #kInputCompact, [data-settings-input="compact"]');
    const motionInputs = document.querySelectorAll('#kReduceMotion, #kInputMotion, [data-settings-input="motion"]');

    compactInputs.forEach((input) => {
      if (input.type === 'checkbox') {
        input.checked = !!next.compactMode;
      }
      input.setAttribute('aria-checked', String(!!next.compactMode));
    });

    motionInputs.forEach((input) => {
      if (input.type === 'checkbox') {
        input.checked = !!next.reduceMotion;
      }
      input.setAttribute('aria-checked', String(!!next.reduceMotion));
    });
  };

  const syncThemeChoiceState = () => {
    const currentTheme = isValidTheme(root.dataset.theme) ? root.dataset.theme : resolvePreferredTheme();
    document.querySelectorAll('[data-theme-choice]').forEach((choice) => {
      const isActive = choice.dataset.themeChoice === currentTheme;
      choice.classList.toggle('is-active', isActive);
      choice.setAttribute('aria-pressed', String(isActive));
      choice.setAttribute('tabindex', isActive ? '0' : '-1');
    });
  };

  const syncSettingsPanelState = () => {
    if (!settingsPanel) return;
    const fullSettingsLink = settingsPanel.querySelector('[data-settings-link="full"]');
    const authHint = settingsPanel.querySelector('[data-settings-copy="auth"]');

    syncThemeChoiceState();
    syncSettingsInputs(readSettings());

    if (settingsButton) {
      settingsButton.setAttribute('data-theme-current', isValidTheme(root.dataset.theme) ? root.dataset.theme : resolvePreferredTheme());
    }

    if (fullSettingsLink) {
      fullSettingsLink.classList.toggle('hidden', isPreAuthView());
    }
    if (authHint) {
      authHint.classList.toggle('hidden', !isPreAuthView());
    }
  };

  const persistSettingsServer = (settings, themeOverride) => {
    if (!canSyncSettingsServer()) return;
    clearTimeout(serverSaveTimer);
    serverSaveTimer = window.setTimeout(() => {
      window.KairosLMS.api('POST', './api/lms/user_settings/set.php', {
        theme: isValidTheme(themeOverride) ? themeOverride : (isValidTheme(root.dataset.theme) ? root.dataset.theme : null),
        gradient: settings.gradient,
        compact_mode: settings.compactMode ? 1 : 0,
        reduce_motion: settings.reduceMotion ? 1 : 0,
      });
    }, 250);
  };

  const saveSettings = (patch) => {
    const next = { ...readSettings(), ...(patch || {}) };
    writeStorageValue(SETTINGS_KEY, JSON.stringify(next));
    applyUiSettings(next, false);
    persistSettingsServer(next);
    emitUiState();
    return next;
  };

  const loadSettingsServer = async () => {
    if (!canSyncSettingsServer()) return null;
    const res = await window.KairosLMS.api('GET', './api/lms/user_settings/get.php');
    if (!res.ok) return null;
    const data = res.data?.data || res.data || {};
    return {
      theme: isValidTheme(data.theme) ? data.theme : null,
      gradient: LEGACY_GRADIENT_VALUE,
      compactMode: Number(data.compact_mode || 0) === 1,
      reduceMotion: Number(data.reduce_motion || 0) === 1,
    };
  };

  const applyUiSettings = (settings, emit = true) => {
    const next = settings || readSettings();
    root.dataset.gradientTheme = LEGACY_GRADIENT_VALUE;
    root.classList.toggle('ui-compact', !!next.compactMode);
    root.classList.toggle('ui-reduce-motion', !!next.reduceMotion);
    syncSettingsInputs(next);
    if (emit) {
      emitUiState();
    }
    return next;
  };

  const applyTheme = (theme, persist = true, emit = true) => {
    const next = isValidTheme(theme) ? theme : 'light';
    root.dataset.theme = next;
    root.classList.toggle('theme-dark', next !== 'light');
    root.classList.toggle('theme-light', next === 'light');
    if (next !== 'light') {
      writeStorageValue(LAST_DARK_KEY, next);
    }
    if (persist) {
      writeStorageValue(STORAGE_KEY, next);
      mirrorBootstrapTheme(next);
      persistSettingsServer(readSettings(), next);
    }
    syncToggle(next);
    syncThemeChoiceState();
    if (emit) {
      emitUiState();
    }
    return next;
  };

  const resolvePreferredTheme = () => {
    const stored = readStoredTheme();
    if (stored) return stored;
    if (isValidTheme(root.dataset.theme)) return root.dataset.theme;
    return prefersDarkQuery && prefersDarkQuery.matches ? 'dark' : 'light';
  };

  const syncThemeState = () => {
    applyTheme(resolvePreferredTheme(), false, false);
    syncToggle(isValidTheme(root.dataset.theme) ? root.dataset.theme : resolvePreferredTheme());
    syncThemeChoiceState();
  };

  const homeUrl = () => `${window.location.origin}${HOME_PATH}`;

  const normalizeHrefToHome = (href) => {
    const raw = String(href || '').trim();
    if (!raw || raw === '#' || raw.startsWith('javascript:')) return null;
    try {
      const parsed = new URL(raw, window.location.origin);
      if (parsed.origin !== window.location.origin) return null;
      const path = parsed.pathname.replace(/\/+$/, '') || '/';
      if (
        path === '/' ||
        path === '/index.html' ||
        path === '/signoff' ||
        path === '/signoff/index.html'
      ) {
        return homeUrl();
      }
      return null;
    } catch (_) {
      return null;
    }
  };

  const normalizeHomeLinks = () => {
    document.querySelectorAll('a[href]').forEach((anchor) => {
      const href = (anchor.getAttribute('href') || '').trim();
      const normalized = normalizeHrefToHome(href);
      if (normalized) {
        anchor.setAttribute('href', normalized);
      }
      if (anchor.dataset.homeLink === 'true') {
        anchor.setAttribute('href', homeUrl());
      }
    });
  };

  const syncTopbarOffset = () => {
    const topbar = document.querySelector('.k-topbar');
    const fallback = getComputedStyle(root).getPropertyValue('--k-topbar-height').trim() || '64px';
    const height = topbar ? Math.ceil(topbar.getBoundingClientRect().height) : 0;
    root.style.setProperty('--k-topbar-offset', height > 0 ? `${height}px` : fallback);
  };

  const readBranding = () => {
    const fallback = {
      productName: 'Kairos',
      homeLabel: 'Kairos home',
      logoUrl: './images/logo.png',
      logoAlt: 'Kairos',
    };
    try {
      if (window.KairosLMS && typeof window.KairosLMS.getBranding === 'function') {
        return window.KairosLMS.getBranding();
      }
      const cfg = typeof window.getAppConfig === 'function'
        ? window.getAppConfig()
        : (window.SignoffConfig || window.SIGNOFF_CONFIG || {});
      const branding = cfg && typeof cfg.branding === 'object' ? cfg.branding : {};
      const productName = typeof branding.productName === 'string' && branding.productName.trim()
        ? branding.productName.trim()
        : fallback.productName;
      return {
        productName,
        homeLabel: typeof branding.homeLabel === 'string' && branding.homeLabel.trim()
          ? branding.homeLabel.trim()
          : `${productName} home`,
        logoUrl: typeof branding.logoUrl === 'string' && branding.logoUrl.trim()
          ? branding.logoUrl.trim()
          : fallback.logoUrl,
        logoAlt: typeof branding.logoAlt === 'string' && branding.logoAlt.trim()
          ? branding.logoAlt.trim()
          : productName,
      };
    } catch (_) {
      return fallback;
    }
  };

  const hydrateBranding = () => {
    const branding = readBranding();
    document.querySelectorAll('.k-sidebar__brand').forEach((link) => {
      link.setAttribute('aria-label', branding.homeLabel);
    });
    document.querySelectorAll('.k-brand-badge').forEach((img) => {
      img.setAttribute('src', branding.logoUrl);
      img.setAttribute('alt', branding.logoAlt);
    });
    document.querySelectorAll('.k-sidebar__wordmark').forEach((wordmark) => {
      wordmark.textContent = branding.productName;
    });
  };

  const ensureShellOverlay = () => {
    if (shellOverlay && document.body.contains(shellOverlay)) {
      return shellOverlay;
    }
    shellOverlay = document.querySelector('.k-shell-overlay');
    if (!shellOverlay) {
      shellOverlay = document.createElement('div');
      shellOverlay.className = 'k-shell-overlay hidden';
      shellOverlay.setAttribute('aria-hidden', 'true');
      document.body.appendChild(shellOverlay);
    }
    return shellOverlay;
  };

  const closeShellDrawer = () => {
    const sidebar = document.getElementById('kSidebar');
    const menuButton = document.getElementById('kMobileMenuBtn');
    const overlay = ensureShellOverlay();
    if (sidebar) sidebar.classList.remove('is-open');
    if (menuButton) menuButton.setAttribute('aria-expanded', 'false');
    if (overlay) overlay.classList.add('hidden');
    document.body.classList.remove('k-shell-drawer-open');
  };

  const setShellDrawerOpen = (open) => {
    const sidebar = document.getElementById('kSidebar');
    const menuButton = document.getElementById('kMobileMenuBtn');
    if (!sidebar || !menuButton) return;
    const shouldOpen = !!open && !!(shellDrawerQuery && shellDrawerQuery.matches);
    const overlay = ensureShellOverlay();
    sidebar.classList.toggle('is-open', shouldOpen);
    menuButton.setAttribute('aria-expanded', String(shouldOpen));
    document.body.classList.toggle('k-shell-drawer-open', shouldOpen);
    if (overlay) overlay.classList.toggle('hidden', !shouldOpen);
  };

  const bindShell = () => {
    const sidebar = document.getElementById('kSidebar');
    const menuButton = document.getElementById('kMobileMenuBtn');
    if (!sidebar || !menuButton) {
      closeShellDrawer();
      return;
    }
    const overlay = ensureShellOverlay();

    if (menuButton.dataset.shellBound !== 'true') {
      menuButton.dataset.shellBound = 'true';
      menuButton.type = 'button';
      menuButton.addEventListener('click', () => {
        setShellDrawerOpen(!sidebar.classList.contains('is-open'));
      });
    }

    if (overlay && overlay.dataset.shellBound !== 'true') {
      overlay.dataset.shellBound = 'true';
      overlay.addEventListener('click', closeShellDrawer);
    }

    if (sidebar.dataset.shellBound !== 'true') {
      sidebar.dataset.shellBound = 'true';
      sidebar.addEventListener('click', (event) => {
        if (!(shellDrawerQuery && shellDrawerQuery.matches)) return;
        if (!event.target.closest('a, button')) return;
        window.requestAnimationFrame(closeShellDrawer);
      });
    }

    if (!(shellDrawerQuery && shellDrawerQuery.matches)) {
      closeShellDrawer();
    }
  };

  const themeChoiceMarkup = () => THEMES.map((theme) => `
    <button
      type="button"
      class="k-settings-theme-choice"
      data-theme-choice="${theme.value}"
      aria-pressed="false"
      aria-label="Use ${theme.label} theme"
    >
      <span class="k-settings-theme-preview" aria-hidden="true">
        <span class="k-settings-theme-preview__sidebar" style="background:${theme.preview.sidebar}"></span>
        <span class="k-settings-theme-preview__main" style="background:${theme.preview.main}">
          <span class="k-settings-theme-preview__card" style="background:${theme.preview.card}; border-color:${theme.preview.border}"></span>
        </span>
      </span>
      <span class="k-settings-theme-choice__label">${theme.label}</span>
    </button>
  `).join('');

  const ensureSettingsLauncher = () => {
    settingsButton = document.getElementById('kSettingsFab');

    if (settingsButton && settingsButton.tagName !== 'BUTTON') {
      const replacement = document.createElement('button');
      replacement.id = 'kSettingsFab';
      replacement.className = settingsButton.className || 'k-settings-fab';
      replacement.setAttribute('type', 'button');
      replacement.setAttribute('aria-label', 'Open appearance settings');
      replacement.setAttribute('aria-expanded', 'false');
      replacement.innerHTML = settingsButton.innerHTML || '&#9881;';
      settingsButton.replaceWith(replacement);
      settingsButton = replacement;
    }

    if (!settingsButton) {
      settingsButton = document.createElement('button');
      settingsButton.id = 'kSettingsFab';
      settingsButton.type = 'button';
      settingsButton.className = 'k-settings-fab flex align-center justify-center';
      settingsButton.setAttribute('aria-label', 'Open appearance settings');
      settingsButton.setAttribute('aria-expanded', 'false');
      settingsButton.innerHTML = '&#9881;';
      document.body.appendChild(settingsButton);
    }

    return settingsButton;
  };

  const ensureSettingsPanel = () => {
    settingsPanel = document.getElementById('kSettingsPanel');
    if (settingsPanel) {
      return settingsPanel;
    }

    settingsPanel = document.createElement('section');
    settingsPanel.id = 'kSettingsPanel';
    settingsPanel.className = 'k-settings-panel hidden';
    settingsPanel.setAttribute('aria-hidden', 'true');
    settingsPanel.innerHTML = `
      <div class="k-settings-panel__header">
        <div>
          <strong>Appearance</strong>
          <div class="muted small">Theme changes apply instantly.</div>
        </div>
        <button type="button" class="k-settings-panel__close" data-settings-close aria-label="Close appearance settings">&times;</button>
      </div>
      <div class="k-settings-theme-grid" role="list" aria-label="Theme variants">
        ${themeChoiceMarkup()}
      </div>
      <label class="k-settings-check k-settings-check--panel">
        <input type="checkbox" data-settings-input="compact">
        <span>Compact density</span>
      </label>
      <label class="k-settings-check k-settings-check--panel">
        <input type="checkbox" data-settings-input="motion">
        <span>Reduce motion</span>
      </label>
      <a class="btn btn-ghost btn-sm k-settings-panel__link" data-settings-link="full" href="${SETTINGS_PAGE_PATH}">Open full settings</a>
      <div class="muted small hidden" data-settings-copy="auth">Sign in to access the full preferences page.</div>
    `;
    document.body.appendChild(settingsPanel);
    return settingsPanel;
  };

  const setSettingsPanelOpen = (open) => {
    const panel = ensureSettingsPanel();
    const launcher = ensureSettingsLauncher();
    const shouldOpen = !!open;
    panel.classList.toggle('hidden', !shouldOpen);
    panel.setAttribute('aria-hidden', String(!shouldOpen));
    launcher.setAttribute('aria-expanded', String(shouldOpen));
    syncSettingsPanelState();
  };

  const closeSettingsPanel = () => {
    if (!settingsPanel) return;
    setSettingsPanelOpen(false);
  };

  const bindSettingsUi = () => {
    const launcher = ensureSettingsLauncher();
    const panel = ensureSettingsPanel();

    if (launcher.dataset.settingsBound !== 'true') {
      launcher.dataset.settingsBound = 'true';
      launcher.addEventListener('click', (event) => {
        event.preventDefault();
        const isOpen = panel && !panel.classList.contains('hidden');
        setSettingsPanelOpen(!isOpen);
      });
    }

    if (panel.dataset.settingsBound !== 'true') {
      panel.dataset.settingsBound = 'true';

      panel.addEventListener('click', (event) => {
        const closeTrigger = event.target.closest('[data-settings-close]');
        if (closeTrigger) {
          closeSettingsPanel();
          return;
        }

        const themeChoice = event.target.closest('[data-theme-choice]');
        if (themeChoice) {
          applyTheme(themeChoice.dataset.themeChoice);
          syncSettingsPanelState();
          return;
        }
      });

      panel.addEventListener('change', (event) => {
        const input = event.target.closest('[data-settings-input]');
        if (!input) return;
        if (input.dataset.settingsInput === 'compact') {
          saveSettings({ compactMode: !!input.checked });
          return;
        }
        if (input.dataset.settingsInput === 'motion') {
          saveSettings({ reduceMotion: !!input.checked });
        }
      });
    }
  };

  const bindThemeToggleButtons = () => {
    document.querySelectorAll('[data-theme-toggle]').forEach((toggle) => {
      if (toggle.dataset.themeBound === 'true') return;
      toggle.dataset.themeBound = 'true';
      toggle.addEventListener('click', () => {
        const current = isValidTheme(root.dataset.theme) ? root.dataset.theme : resolvePreferredTheme();
        if (current === 'light') {
          let target = 'dark';
          try {
            const lastDark = readStorageValue(LAST_DARK_KEY);
            if (lastDark && isValidTheme(lastDark) && lastDark !== 'light') {
              target = lastDark;
            }
          } catch (_) {
            // ignore
          }
          applyTheme(target);
        } else {
          applyTheme('light');
        }
      });
    });
  };

  const maybeLoadServerSettings = async () => {
    if (!canSyncSettingsServer()) {
      syncSettingsPanelState();
      return null;
    }
    if (serverSettingsLoaded) {
      syncSettingsPanelState();
      return readSettings();
    }
    if (serverSettingsPromise) {
      return serverSettingsPromise;
    }

    serverSettingsPromise = (async () => {
      const serverSettings = await loadSettingsServer();
      serverSettingsPromise = null;
      if (!serverSettings) {
        return null;
      }

      serverSettingsLoaded = true;
      const localTheme = hasScopedOwner() ? readStoredTheme(false) : null;
      const localSettings = hasScopedOwner() ? readStoredSettings(false) : null;
      const nextTheme = localTheme || serverSettings.theme || resolvePreferredTheme();
      const nextSettings = localSettings || {
        gradient: serverSettings.gradient,
        compactMode: serverSettings.compactMode,
        reduceMotion: serverSettings.reduceMotion,
      };

      try {
        if (!localSettings) {
          writeStorageValue(SETTINGS_KEY, JSON.stringify(nextSettings), { allowGuest: false });
        }
        if (!localTheme && serverSettings.theme) {
          writeStorageValue(STORAGE_KEY, serverSettings.theme, { allowGuest: false });
          mirrorBootstrapTheme(serverSettings.theme);
        }
        if (!localTheme && !serverSettings.theme) {
          removeStorageValue(STORAGE_KEY, { allowGuest: false });
        }
      } catch (_) {
        // ignore
      }

      if (
        hasScopedOwner()
        && (localTheme || localSettings)
        && (
          serverSettings.theme !== nextTheme
          || serverSettings.compactMode !== nextSettings.compactMode
          || serverSettings.reduceMotion !== nextSettings.reduceMotion
        )
      ) {
        persistSettingsServer(nextSettings, nextTheme);
      }

      applyTheme(nextTheme, false, false);
      mirrorBootstrapTheme(nextTheme);
      applyUiSettings(nextSettings, false);

      syncSettingsPanelState();
      emitUiState();
      return {
        theme: nextTheme,
        gradient: nextSettings.gradient,
        compactMode: nextSettings.compactMode,
        reduceMotion: nextSettings.reduceMotion,
      };
    })();

    return serverSettingsPromise;
  };

  const bindAuthObserver = () => {
    if (!document.body || settingsObserver) return;
    settingsObserver = new MutationObserver(() => {
      if (isPreAuthView()) {
        serverSettingsLoaded = false;
        serverSettingsPromise = null;
        resetStorageOwner(true);
        syncThemeState();
        applyUiSettings(readSettings(), false);
        syncSettingsPanelState();
        return;
      }

      if (!hasScopedOwner()) {
        resetStorageOwner(false);
        resolveStorageOwner().then(() => {
          syncThemeState();
          applyUiSettings(readSettings(), false);
          syncSettingsPanelState();
          maybeLoadServerSettings();
          emitUiState();
        });
        return;
      }

      syncSettingsPanelState();
      maybeLoadServerSettings();
    });
    settingsObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
  };

  document.addEventListener('DOMContentLoaded', async () => {
    syncThemeState();
    if (typeof window.waitForAppConfig === 'function') {
      try {
        await window.waitForAppConfig();
      } catch (_) {
        // ignore
      }
    }
    await resolveStorageOwner();
    syncThemeState();
    applyUiSettings(readSettings(), false);
    hydrateBranding();
    normalizeHomeLinks();
    ensureSettingsLauncher();
    ensureSettingsPanel();
    bindShell();
    bindSettingsUi();
    bindThemeToggleButtons();
    bindAuthObserver();
    syncSettingsPanelState();
    syncTopbarOffset();
    maybeLoadServerSettings();
    emitUiState();
  });

  window.addEventListener('pageshow', () => {
    (async () => {
      await resolveStorageOwner();
      syncThemeState();
      applyUiSettings(readSettings(), false);
      hydrateBranding();
      normalizeHomeLinks();
      ensureSettingsLauncher();
      ensureSettingsPanel();
      bindShell();
      bindSettingsUi();
      bindThemeToggleButtons();
      bindAuthObserver();
      syncSettingsPanelState();
      syncTopbarOffset();
      closeShellDrawer();
      maybeLoadServerSettings();
      emitUiState();
    })();
  });

  document.addEventListener('click', (event) => {
    if (!settingsPanel || settingsPanel.classList.contains('hidden')) return;
    const target = event.target;
    if (settingsPanel.contains(target) || (settingsButton && settingsButton.contains(target))) return;
    closeSettingsPanel();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    closeShellDrawer();
    closeSettingsPanel();
  });

  if (prefersDarkQuery && typeof prefersDarkQuery.addEventListener === 'function') {
    prefersDarkQuery.addEventListener('change', (event) => {
      if (readStoredTheme()) return;
      applyTheme(event.matches ? 'dark' : 'light', false);
    });
  }

  if (shellDrawerQuery && typeof shellDrawerQuery.addEventListener === 'function') {
    shellDrawerQuery.addEventListener('change', () => {
      bindShell();
      syncTopbarOffset();
      if (!shellDrawerQuery.matches) {
        closeShellDrawer();
      }
    });
  }

  window.addEventListener('resize', syncTopbarOffset);

  window.KairosTheme = {
    applyTheme,
    saveSettings,
    readSettings,
    applyUiSettings,
    resolvePreferredTheme,
    getThemes: () => THEMES.map((theme) => ({ ...theme, preview: { ...theme.preview } })),
    syncFromServer: maybeLoadServerSettings,
    openPanel: () => setSettingsPanelOpen(true),
    closePanel: closeSettingsPanel,
    homeUrl,
  };
})();
