(function (global) {
  'use strict';

  const DEFAULT_CHANNELS = ['rooms', 'queue', 'progress', 'ta_accept'];
  const MAX_BACKOFF = 10000;
  const INITIAL_BACKOFF = 1000;
  const TOKEN_REFRESH_THRESHOLD_MS = 9 * 60 * 1000;
  const DEFAULT_WS_BASE_URL = 'wss://regatta.nixorcorporate.com';
  const DEFAULT_WS_PATH = '/websocket/socket.io';
  let WS_BASE_URL = DEFAULT_WS_BASE_URL;
  let WS_PATH = DEFAULT_WS_PATH;
  let configReady = false;
  let pendingEnsure = false;

  function applyConfig(config) {
    const cfg = config || {};
    WS_BASE_URL = typeof cfg.wsBaseUrl === 'string' && cfg.wsBaseUrl.trim() !== ''
      ? cfg.wsBaseUrl.trim()
      : DEFAULT_WS_BASE_URL;
    WS_PATH = typeof cfg.wsSocketPath === 'string' && cfg.wsSocketPath.trim() !== ''
      ? `/${cfg.wsSocketPath.replace(/^\/+/, '')}`
      : DEFAULT_WS_PATH;
    configReady = true;
    if (pendingEnsure) {
      pendingEnsure = false;
      ensureConnection(true);
    }
  }

  const configPromise = typeof global.waitForAppConfig === 'function'
    ? global.waitForAppConfig()
    : Promise.resolve(global.SignoffConfig || global.SIGNOFF_CONFIG || {});

  configPromise
    .then(applyConfig)
    .catch(() => { applyConfig(global.SignoffConfig || global.SIGNOFF_CONFIG || {}); });

  const state = {
    me: null,
    meLoaded: false,
    disabled: false,
    socket: null,
    manualClose: false,
    reconnectDelay: INITIAL_BACKOFF,
    reconnectTimer: null,
    connecting: false,
    channels: new Set(DEFAULT_CHANNELS),
    handlers: {
      onQueue: null,
      onRooms: null,
      onProgress: null,
      onTaAccept: null,
      onOpen: null,
      onClose: null,
    },
    getFilters: null,
    getSelfUserId: null,
    staticFilters: {
      courseId: undefined,
      roomId: undefined,
    },
    selfUserId: null,
    meFetchedAt: 0,
    forceRefresh: false,
  };

  function normalizeId(value) {
    if (value === undefined || value === null || value === '') {
      return null;
    }
    const num = Number(value);
    if (!Number.isFinite(num)) {
      return null;
    }
    return Math.trunc(num);
  }

  function applyDefaultHandlers() {
    if (!state.handlers.onQueue) {
      state.handlers.onQueue = function (data) {
        if (typeof global.reloadQueues === 'function') {
          try { global.reloadQueues(data); } catch (err) { console.error('WS queue handler error', err); }
        }
      };
    }
    if (!state.handlers.onRooms) {
      state.handlers.onRooms = function (data) {
        if (typeof global.reloadRooms === 'function') {
          try { global.reloadRooms(data); } catch (err) { console.error('WS rooms handler error', err); }
        }
      };
    }
    if (!state.handlers.onProgress) {
      state.handlers.onProgress = function (data) {
        if (typeof global.reloadProgress === 'function') {
          try { global.reloadProgress(data); } catch (err) { console.error('WS progress handler error', err); }
        }
      };
    }
    if (!state.handlers.onTaAccept) {
      state.handlers.onTaAccept = function (data) {
        if (typeof global.handleTaAcceptEvent === 'function') {
          try { global.handleTaAcceptEvent(data); } catch (err) { console.error('WS ta_accept handler error', err); }
        } else if (typeof global.handleTaAcceptPayload === 'function') {
          try { global.handleTaAcceptPayload(data ? data.payload || {} : {}); } catch (err) { console.error('WS ta_accept payload handler error', err); }
        }
      };
    }
  }

  function assignHandlers(options) {
    ['onQueue', 'onRooms', 'onProgress', 'onTaAccept', 'onOpen', 'onClose'].forEach((key) => {
      if (typeof options[key] === 'function') {
        state.handlers[key] = options[key];
      }
    });
    applyDefaultHandlers();
  }

  function setChannels(channels) {
    if (!Array.isArray(channels) || !channels.length) {
      state.channels = new Set(DEFAULT_CHANNELS);
      return;
    }
    const cleaned = channels
      .map((c) => (typeof c === 'string' ? c.trim() : ''))
      .filter((c) => c && DEFAULT_CHANNELS.includes(c));
    state.channels = new Set(cleaned.length ? cleaned : DEFAULT_CHANNELS);
  }

  function shouldRefreshToken() {
    if (!state.meLoaded) {
      return true;
    }
    const wsInfo = state.me?.ws;
    if (!wsInfo?.token) {
      return true;
    }
    if (!state.meFetchedAt) {
      return true;
    }
    if (Date.now() - state.meFetchedAt >= TOKEN_REFRESH_THRESHOLD_MS) {
      return true;
    }
    return false;
  }

  async function loadMe(forceRefresh) {
    if (!forceRefresh && !shouldRefreshToken()) {
      return state.me;
    }
    state.connecting = true;
    try {
      const resp = await fetch('./api/me.php', {
        credentials: 'same-origin',
        headers: {
          'Cache-Control': 'no-cache',
          Accept: 'application/json',
        },
      });
      if (!resp.ok) {
        throw new Error('me.php ' + resp.status);
      }
      const data = await resp.json();
      state.me = data || {};
      state.meLoaded = true;
      state.meFetchedAt = Date.now();
      state.forceRefresh = false;
      const maybeUser = data && typeof data === 'object' ? data : {};
      if (state.selfUserId === null && maybeUser.user_id != null) {
        const normalized = normalizeId(maybeUser.user_id);
        if (normalized !== null) {
          state.selfUserId = normalized;
        }
      }
      if (!data?.ws?.token) {
        state.disabled = true;
        console.info('WS disabled: missing token');
      }
      return state.me;
    } catch (err) {
      console.warn('WS me.php fetch failed', err);
      throw err;
    } finally {
      state.connecting = false;
    }
  }

  function getCurrentFilters() {
    let courseId;
    let roomId;

    if (typeof state.getFilters === 'function') {
      try {
        const result = state.getFilters() || {};
        if (result && typeof result === 'object') {
          courseId = result.courseId;
          roomId = result.roomId;
        }
      } catch (err) {
        console.debug('WS getFilters threw', err);
      }
    }

    if (state.staticFilters.courseId !== undefined) {
      courseId = state.staticFilters.courseId;
    }
    if (state.staticFilters.roomId !== undefined) {
      roomId = state.staticFilters.roomId;
    }

    return {
      courseId: normalizeId(courseId),
      roomId: normalizeId(roomId),
    };
  }

  function getSelfUserId() {
    if (state.selfUserId !== null && state.selfUserId !== undefined) {
      return state.selfUserId;
    }
    if (typeof state.getSelfUserId === 'function') {
      try {
        const value = state.getSelfUserId();
        const normalized = normalizeId(value);
        if (normalized !== null) {
          return normalized;
        }
      } catch (err) {
        console.debug('WS getSelfUserId threw', err);
      }
    }
    if (state.me?.ws?.user_id != null) {
      const normalized = normalizeId(state.me.ws.user_id);
      if (normalized !== null) {
        return normalized;
      }
    }
    if (state.me?.user_id != null) {
      const normalized = normalizeId(state.me.user_id);
      if (normalized !== null) {
        return normalized;
      }
    }
    return null;
  }

  function computeEndpoint() {
    const wsInfo = state.me?.ws;
    if (!wsInfo?.token) return null;

    const baseUrl = new URL(WS_BASE_URL);

    const params = new URLSearchParams();
    const channels = Array.from(state.channels);
    if (channels.length) {
      params.set('channels', channels.join(','));
    }

    const filters = getCurrentFilters();
    if (filters.courseId !== null) {
      params.set('course_id', String(filters.courseId));
    }
    if (filters.roomId !== null) {
      params.set('room_id', String(filters.roomId));
    }

    params.set('token', wsInfo.token);

    baseUrl.pathname = WS_PATH;
    baseUrl.search = '';
    baseUrl.hash = '';

    return {
      origin: `${baseUrl.protocol}//${baseUrl.host}`,
      path: baseUrl.pathname,
      query: Object.fromEntries(params),
    };
  }

  function clearReconnectTimer() {
    if (state.reconnectTimer) {
      clearTimeout(state.reconnectTimer);
      state.reconnectTimer = null;
    }
  }

  function scheduleReconnect() {
    if (state.disabled) {
      return;
    }
    if (state.reconnectTimer) {
      return;
    }
    const delay = state.reconnectDelay;
    state.reconnectTimer = setTimeout(() => {
      state.reconnectTimer = null;
      ensureConnection();
    }, delay);
    state.reconnectDelay = Math.min(state.reconnectDelay * 2, MAX_BACKOFF);
  }

  function resetBackoff() {
    state.reconnectDelay = INITIAL_BACKOFF;
  }

  function closeCurrent(code, reason, manual) {
    if (!state.socket) {
      return;
    }
    try {
      state.manualClose = !!manual;
      state.socket.disconnect();
    } catch (err) {
      console.debug('WebSocket disconnect error', err);
    } finally {
      state.socket = null;
    }
  }

  function bindSocketEvents(socket) {
    socket.on('connect', () => {
      resetBackoff();
      if (typeof state.handlers.onOpen === 'function') {
        try { state.handlers.onOpen(); } catch (err) { console.error('WS onOpen handler error', err); }
      }
    });

    socket.on('connect_error', (err) => {
      console.debug('WebSocket error', err);
      state.forceRefresh = true;
    });

    socket.on('disconnect', () => {
      const manual = state.manualClose;
      state.manualClose = false;
      state.socket = null;
      if (typeof state.handlers.onClose === 'function') {
        try { state.handlers.onClose(); } catch (err) { console.error('WS onClose handler error', err); }
      }
      if (manual) {
        resetBackoff();
        ensureConnection();
      } else {
        state.forceRefresh = true;
        scheduleReconnect();
      }
    });

    const eventMap = {
      queue: 'onQueue',
      rooms: 'onRooms',
      progress: 'onProgress',
      ta_accept: 'onTaAccept',
    };

    Object.keys(eventMap).forEach((eventName) => {
      socket.on(eventName, (payload) => {
        handleIncomingMessage({ event: eventName, payload });
      });
    });
  }

  function handleIncomingMessage(data) {
    if (!data) {
      return;
    }

    let parsed;
    try {
      parsed = typeof data === 'string' ? JSON.parse(data) : data;
    } catch (err) {
      console.debug('WS message parse error', err, data);
      return;
    }

    if (!parsed || typeof parsed !== 'object') {
      return;
    }

    const eventName = parsed.event || parsed.type || parsed.channel;
    if (!eventName) {
      return;
    }

    const handlerMap = {
      queue: 'onQueue',
      rooms: 'onRooms',
      progress: 'onProgress',
      ta_accept: 'onTaAccept',
    };

    const handlerName = handlerMap[eventName];
    if (!handlerName) {
      return;
    }

    const payload = parsed.payload !== undefined ? parsed.payload : parsed;

    if (eventName === 'ta_accept') {
      const targetId = extractTaAcceptUserId(payload);
      const selfId = getSelfUserId();
      if (targetId !== null && selfId !== null && targetId !== selfId) {
        return;
      }
    }

    const handler = state.handlers[handlerName];
    if (typeof handler === 'function') {
      try {
        handler(payload);
      } catch (err) {
        console.error('WS handler error for', eventName, err);
      }
    }
  }

  function extractTaAcceptUserId(payload) {
    if (!payload || typeof payload !== 'object') {
      return null;
    }

    const direct = normalizeId(payload.student_user_id ?? payload.user_id);
    if (direct !== null) {
      return direct;
    }

    if (payload.payload && typeof payload.payload === 'object') {
      const nested = normalizeId(
        payload.payload.student_user_id ?? payload.payload.user_id,
      );
      if (nested !== null) {
        return nested;
      }
    }

    return null;
  }

  function connectSocket() {
    if (state.disabled || state.socket) {
      return;
    }

    const endpoint = computeEndpoint();
    if (!endpoint) {
      state.disabled = true;
      console.info('WS disabled: endpoint unavailable');
      return;
    }

    try {
      const socket = io(endpoint.origin, {
        path: endpoint.path,
        query: endpoint.query,
        transports: ['websocket', 'polling'],
        upgrade: true,
      });
      state.socket = socket;
      bindSocketEvents(socket);
    } catch (err) {
      console.warn('WebSocket connection failed', err);
      state.socket = null;
      state.forceRefresh = true;
      scheduleReconnect();
    }
  }

  async function ensureConnection(force = false) {
    if (!configReady) {
      pendingEnsure = true;
      return;
    }
    if (!force && (state.disabled || state.socket || state.connecting)) {
      return;
    }
    try {
      await loadMe(state.forceRefresh);
    } catch (err) {
      scheduleReconnect();
      return;
    }
    if (state.disabled || state.socket) {
      return;
    }
    connectSocket();
  }

  function restartConnection() {
    resetBackoff();
    clearReconnectTimer();
    if (state.socket) {
      closeCurrent(1000, 'filters updated', true);
    } else {
      ensureConnection();
    }
  }

  function updateFilters(filters, silent) {
    const next = filters || {};
    let changed = false;
    if (Object.prototype.hasOwnProperty.call(next, 'courseId')) {
      const override = next.courseId === undefined ? undefined : normalizeId(next.courseId);
      if (state.staticFilters.courseId !== override) {
        state.staticFilters.courseId = override;
        changed = true;
      }
    }
    if (Object.prototype.hasOwnProperty.call(next, 'roomId')) {
      const override = next.roomId === undefined ? undefined : normalizeId(next.roomId);
      if (state.staticFilters.roomId !== override) {
        state.staticFilters.roomId = override;
        changed = true;
      }
    }
    if (changed && !silent) {
      restartConnection();
    }
    if (changed && silent === true) {
      resetBackoff();
      ensureConnection();
    }
  }

  function setSelfUserId(value) {
    if (value === undefined) {
      state.selfUserId = null;
      return;
    }
    const normalized = normalizeId(value);
    state.selfUserId = normalized;
  }

  function getState() {
    return {
      connected: !!state.socket,
      disabled: state.disabled,
      filters: getCurrentFilters(),
      channels: Array.from(state.channels),
      meLoaded: state.meLoaded,
    };
  }

  applyDefaultHandlers();

  global.SignoffWS = {
    init(options) {
      const opts = options || {};
      if (opts.channels) {
        setChannels(opts.channels);
      }
      if (typeof opts.getFilters === 'function') {
        state.getFilters = opts.getFilters;
      }
      if (typeof opts.getSelfUserId === 'function') {
        state.getSelfUserId = opts.getSelfUserId;
      }
      if (Object.prototype.hasOwnProperty.call(opts, 'selfUserId')) {
        setSelfUserId(opts.selfUserId);
      }
      assignHandlers(opts);
      updateFilters({
        courseId: Object.prototype.hasOwnProperty.call(opts, 'courseId') ? opts.courseId : undefined,
        roomId: Object.prototype.hasOwnProperty.call(opts, 'roomId') ? opts.roomId : undefined,
      }, true);
      ensureConnection();
    },
    updateFilters(filters) {
      updateFilters(filters, false);
    },
    setSelfUserId,
    getState,
  };
})(typeof window !== 'undefined' ? window : this);
