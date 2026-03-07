(function (global) {
  'use strict';

  const DEFAULT_CONFIG = Object.freeze({
    googleClientId: null,
    allowedDomain: '',
    wsBaseUrl: '',
    wsSocketPath: '/websocket/socket.io',
    branding: Object.freeze({
      productName: 'Kairos',
      homeLabel: 'Kairos home',
      logoUrl: './images/logo.png',
      logoAlt: 'Kairos',
    }),
  });

  function normalizeConfig(raw) {
    const base = { ...DEFAULT_CONFIG };
    const cfg = { ...base, ...(raw || {}) };
    const rawBranding = raw && typeof raw.branding === 'object' ? raw.branding : {};

    cfg.allowedDomain = typeof cfg.allowedDomain === 'string'
      ? cfg.allowedDomain.replace(/^@+/, '')
      : '';

    cfg.wsBaseUrl = typeof cfg.wsBaseUrl === 'string'
      ? cfg.wsBaseUrl.replace(/\/+$/, '')
      : '';

    cfg.wsSocketPath = typeof cfg.wsSocketPath === 'string' && cfg.wsSocketPath !== ''
      ? '/' + cfg.wsSocketPath.replace(/^\/+/, '')
      : DEFAULT_CONFIG.wsSocketPath;

    if (!cfg.wsBaseUrl && typeof window !== 'undefined' && window.location?.host) {
      const scheme = window.location.protocol === 'http:' ? 'ws:' : 'wss:';
      cfg.wsBaseUrl = `${scheme}//${window.location.host}`;
    }

    const productName = typeof rawBranding.productName === 'string' && rawBranding.productName.trim()
      ? rawBranding.productName.trim()
      : DEFAULT_CONFIG.branding.productName;
    const homeLabel = typeof rawBranding.homeLabel === 'string' && rawBranding.homeLabel.trim()
      ? rawBranding.homeLabel.trim()
      : `${productName} home`;
    const logoUrl = typeof rawBranding.logoUrl === 'string' && rawBranding.logoUrl.trim()
      ? rawBranding.logoUrl.trim()
      : DEFAULT_CONFIG.branding.logoUrl;
    const logoAlt = typeof rawBranding.logoAlt === 'string' && rawBranding.logoAlt.trim()
      ? rawBranding.logoAlt.trim()
      : productName;

    cfg.branding = Object.freeze({
      productName,
      homeLabel,
      logoUrl,
      logoAlt,
    });

    return Object.freeze(cfg);
  }

  const configPromise = (async () => {
    try {
      const response = await fetch('./api/config.php', { credentials: 'same-origin' });
      if (!response.ok) {
        throw new Error(`Config request failed with status ${response.status}`);
      }
      const data = await response.json();
      const cfg = normalizeConfig(data);
      global.SignoffConfig = cfg;
      global.SIGNOFF_CONFIG = cfg;
      return cfg;
    } catch (err) {
      console.error('Failed to load app config', err);
      global.SignoffConfig = DEFAULT_CONFIG;
      global.SIGNOFF_CONFIG = DEFAULT_CONFIG;
      return DEFAULT_CONFIG;
    }
  })();

  global.waitForAppConfig = function waitForAppConfig() {
    return configPromise;
  };

  global.getAppConfig = function getAppConfig() {
    return global.SignoffConfig || DEFAULT_CONFIG;
  };
})(typeof window !== 'undefined' ? window : this);
