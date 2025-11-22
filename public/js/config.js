(function (global) {
  'use strict';

  const DEFAULT_CONFIG = Object.freeze({
    googleClientId: null,
    allowedDomain: '',
    wsBaseUrl: 'wss://regatta.nixorcorporate.com',
    wsSocketPath: '/websocket/socket.io',
  });

  function normalizeConfig(raw) {
    const base = { ...DEFAULT_CONFIG };
    const cfg = { ...base, ...(raw || {}) };

    cfg.allowedDomain = typeof cfg.allowedDomain === 'string'
      ? cfg.allowedDomain.replace(/^@+/, '')
      : '';

    cfg.wsBaseUrl = typeof cfg.wsBaseUrl === 'string'
      ? cfg.wsBaseUrl.replace(/\/+$/, '')
      : DEFAULT_CONFIG.wsBaseUrl;

    cfg.wsSocketPath = typeof cfg.wsSocketPath === 'string' && cfg.wsSocketPath !== ''
      ? '/' + cfg.wsSocketPath.replace(/^\/+/, '')
      : DEFAULT_CONFIG.wsSocketPath;

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
