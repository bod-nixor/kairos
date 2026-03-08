(function () {
  const STORAGE_KEY = 'kairos-theme';
  const SETTINGS_KEY = 'kairos-ui-settings';
  const HOME_PATH = '/signoff/';
  const root = document.documentElement;
  const prefersDarkQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  const shellDrawerQuery = window.matchMedia ? window.matchMedia('(max-width: 1024px)') : null;

  let serverSaveTimer = null;
  let settingsButton = null;
  let settingsPanel = null;
  let shellOverlay = null;

  const isProjectorView = () => window.location.pathname.toLowerCase().includes('projector');
  if (isProjectorView()) {
    return;
  }

  const isValidTheme = (value) => value === 'light' || value === 'dark';

  const readStoredTheme = () => {
    try {
      const value = localStorage.getItem(STORAGE_KEY);
      return isValidTheme(value) ? value : null;
    } catch (_) {
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

  const canUseLmsApi = () => !!(window.KairosLMS && typeof window.KairosLMS.api === 'function');

  const persistSettingsServer = (settings, themeOverride) => {
    if (!canUseLmsApi()) return;
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
    try {
      localStorage.setItem(SETTINGS_KEY, JSON.stringify(next));
    } catch (_) {
      // ignore
    }
    persistSettingsServer(next);
    return next;
  };

  const loadSettingsServer = async () => {
    if (!canUseLmsApi()) return null;
    const res = await window.KairosLMS.api('GET', './api/lms/user_settings/get.php');
    if (!res.ok) return null;
    const data = res.data?.data || res.data || {};
    return {
      theme: isValidTheme(data.theme) ? data.theme : null,
      gradient: typeof data.gradient === 'string' ? data.gradient : 'ocean',
      compactMode: Number(data.compact_mode || 0) === 1,
      reduceMotion: Number(data.reduce_motion || 0) === 1,
    };
  };

  const applyUiSettings = (settings) => {
    const next = settings || readSettings();
    root.dataset.gradientTheme = next.gradient || 'ocean';
    root.classList.toggle('ui-compact', !!next.compactMode);
    root.classList.toggle('ui-reduce-motion', !!next.reduceMotion);
  };

  const syncToggle = (theme) => {
    const isDark = theme === 'dark';
    document.querySelectorAll('[data-theme-toggle]').forEach((toggle) => {
      toggle.classList.toggle('is-dark', isDark);
      toggle.setAttribute('aria-pressed', String(isDark));
      const label = toggle.querySelector('[data-theme-label]');
      if (label) {
        label.textContent = isDark ? 'Dark' : 'Light';
      }
    });
  };

  const applyTheme = (theme, persist = true) => {
    const next = isValidTheme(theme) ? theme : 'light';
    root.dataset.theme = next;
    root.classList.toggle('theme-dark', next === 'dark');
    root.classList.toggle('theme-light', next !== 'dark');
    if (persist) {
      try {
        localStorage.setItem(STORAGE_KEY, next);
      } catch (_) {
        // ignore
      }
      persistSettingsServer(readSettings(), next);
    }
    syncToggle(next);
  };

  const resolvePreferredTheme = () => {
    const stored = readStoredTheme();
    if (stored) return stored;
    if (isValidTheme(root.dataset.theme)) return root.dataset.theme;
    return prefersDarkQuery && prefersDarkQuery.matches ? 'dark' : 'light';
  };

  const syncThemeState = () => {
    applyTheme(resolvePreferredTheme(), false);
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

  const ensureSettingsLauncher = () => {
    settingsButton = document.getElementById('kSettingsFab');

    if (!settingsButton) {
      settingsButton = document.createElement('a');
      settingsButton.id = 'kSettingsFab';
      settingsButton.className = 'k-settings-fab flex align-center justify-center';
      settingsButton.style.textDecoration = 'none';
      settingsButton.href = './settings.html';
      settingsButton.setAttribute('aria-label', 'Open settings');
      settingsButton.innerHTML = '&#9881;';
      document.body.appendChild(settingsButton);
    }
  };

  document.addEventListener('DOMContentLoaded', async () => {
    syncThemeState();
    applyUiSettings(readSettings());
    if (typeof window.waitForAppConfig === 'function') {
      try {
        await window.waitForAppConfig();
      } catch (_) {
        // ignore
      }
    }
    hydrateBranding();
    normalizeHomeLinks();
    ensureSettingsLauncher();
    bindShell();
    syncTopbarOffset();

    const serverSettings = await loadSettingsServer();
    if (serverSettings) {
      try {
        localStorage.setItem(SETTINGS_KEY, JSON.stringify({
          gradient: serverSettings.gradient,
          compactMode: serverSettings.compactMode,
          reduceMotion: serverSettings.reduceMotion,
        }));
        if (serverSettings.theme) {
          localStorage.setItem(STORAGE_KEY, serverSettings.theme);
        } else {
          localStorage.removeItem(STORAGE_KEY);
        }
      } catch (_) {
        // ignore
      }

      if (serverSettings.theme) {
        applyTheme(serverSettings.theme, false);
      } else {
        applyTheme(resolvePreferredTheme(), false);
      }

      applyUiSettings({
        gradient: serverSettings.gradient,
        compactMode: serverSettings.compactMode,
        reduceMotion: serverSettings.reduceMotion,
      });

      const gradientInput = document.getElementById('kGradientTheme');
      const compactInput = document.getElementById('kCompactMode');
      const reduceMotionInput = document.getElementById('kReduceMotion');
      if (gradientInput) gradientInput.value = serverSettings.gradient;
      if (compactInput) {
        compactInput.checked = !!serverSettings.compactMode;
        compactInput.setAttribute('aria-checked', String(!!serverSettings.compactMode));
      }
      if (reduceMotionInput) {
        reduceMotionInput.checked = !!serverSettings.reduceMotion;
        reduceMotionInput.setAttribute('aria-checked', String(!!serverSettings.reduceMotion));
      }
    }

    document.querySelectorAll('[data-theme-toggle]').forEach((toggle) => {
      if (toggle.dataset.themeBound === 'true') return;
      toggle.dataset.themeBound = 'true';
      toggle.addEventListener('click', () => {
        const current = isValidTheme(root.dataset.theme) ? root.dataset.theme : resolvePreferredTheme();
        applyTheme(current === 'dark' ? 'light' : 'dark');
      });
    });
  });

  window.addEventListener('pageshow', () => {
    syncThemeState();
    applyUiSettings(readSettings());
    hydrateBranding();
    normalizeHomeLinks();
    ensureSettingsLauncher();
    bindShell();
    syncTopbarOffset();
    closeShellDrawer();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    closeShellDrawer();
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
    homeUrl
  };
})();
