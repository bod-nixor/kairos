(function (global) {
  'use strict';

  const DEFAULT_CHANNELS = ['rooms', 'queue', 'progress', 'ta_accept', 'projector'];
  const ALLOWED_CHANNEL = /^[a-z][a-z0-9_:-]{0,63}$/;
  const INITIAL_BACKOFF = 1000;
  const MAX_BACKOFF = 10000;
  const MAX_RECONNECT_ATTEMPTS = 8;
  const RESTART_DEBOUNCE_MS = 180;
  const TOKEN_REFRESH_THRESHOLD_MS = 9 * 60 * 1000;
  const DEFAULT_WS_PATH = '/websocket/socket.io';
  const BASE_EVENTS = new Set([
    'queue',
    'rooms',
    'progress',
    'ta_accept',
    'projector_serve',
    'projector_call_again',
  ]);

  let wsBaseUrl = '';
  let wsPath = DEFAULT_WS_PATH;
  let configReady = false;
  let pendingEnsure = false;

  const state = {
    me: null,
    meLoaded: false,
    meFetchedAt: 0,
    disabled: false,
    fatalReason: null,
    socket: null,
    manualSockets: new WeakSet(),
    reconnectDelay: INITIAL_BACKOFF,
    reconnectAttempts: 0,
    reconnectTimer: null,
    restartTimer: null,
    connectionPromise: null,
    channels: new Set(DEFAULT_CHANNELS),
    handlers: {
      onQueue: null,
      onRooms: null,
      onProgress: null,
      onTaAccept: null,
      onProjector: null,
      onEvent: null,
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
    forceRefresh: false,
    status: 'idle',
    loggedMessages: new Set(),
  };

  function normalizeId(value) {
    if (value === undefined || value === null || value === '') return null;
    const number = Number(value);
    return Number.isFinite(number) ? Math.trunc(number) : null;
  }

  function logOnce(key, level, message) {
    if (state.loggedMessages.has(key)) return;
    state.loggedMessages.add(key);
    const logger = console[level] || console.warn;
    logger.call(console, message);
  }

  function ensureStatusElement() {
    if (typeof document === 'undefined' || !document.body) return null;
    let element = document.getElementById('kRealtimeStatus');
    if (element) return element;

    element = document.createElement('div');
    element.id = 'kRealtimeStatus';
    element.className = 'k-realtime-status';
    element.setAttribute('role', 'status');
    element.setAttribute('aria-live', 'polite');
    element.setAttribute('aria-atomic', 'true');
    element.hidden = true;
    element.innerHTML =
      '<span class="k-realtime-status__dot" aria-hidden="true"></span>' +
      '<span class="k-realtime-status__text"></span>';
    document.body.appendChild(element);
    return element;
  }

  function setStatus(status, text) {
    state.status = status;
    const element = ensureStatusElement();
    if (element) {
      const visible = !['idle', 'connected', 'disabled'].includes(status);
      element.hidden = !visible;
      element.dataset.status = status;
      const label = element.querySelector('.k-realtime-status__text');
      if (label) label.textContent = text || '';
    }
    if (typeof global.CustomEvent === 'function' && typeof global.dispatchEvent === 'function') {
      global.dispatchEvent(new global.CustomEvent('kairos:realtime-status', {
        detail: { status, message: text || '', attempts: state.reconnectAttempts },
      }));
    }
  }

  function applyConfig(config) {
    const cfg = config || {};
    wsBaseUrl = typeof cfg.wsBaseUrl === 'string' ? cfg.wsBaseUrl.trim() : '';
    wsPath = typeof cfg.wsSocketPath === 'string' && cfg.wsSocketPath.trim() !== ''
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
    .catch(() => applyConfig(global.SignoffConfig || global.SIGNOFF_CONFIG || {}));

  function applyDefaultHandlers() {
    if (!state.handlers.onQueue) {
      state.handlers.onQueue = (data) => {
        if (typeof global.reloadQueues === 'function') global.reloadQueues(data);
      };
    }
    if (!state.handlers.onRooms) {
      state.handlers.onRooms = (data) => {
        if (typeof global.reloadRooms === 'function') global.reloadRooms(data);
      };
    }
    if (!state.handlers.onProgress) {
      state.handlers.onProgress = (data) => {
        if (typeof global.reloadProgress === 'function') global.reloadProgress(data);
      };
    }
    if (!state.handlers.onTaAccept) {
      state.handlers.onTaAccept = (data) => {
        if (typeof global.handleTaAcceptEvent === 'function') {
          global.handleTaAcceptEvent(data);
        } else if (typeof global.handleTaAcceptPayload === 'function') {
          global.handleTaAcceptPayload(data ? data.payload || {} : {});
        }
      };
    }
  }

  function assignHandlers(options) {
    [
      'onQueue',
      'onRooms',
      'onProgress',
      'onTaAccept',
      'onProjector',
      'onEvent',
      'onOpen',
      'onClose',
    ].forEach((key) => {
      if (typeof options[key] === 'function') state.handlers[key] = options[key];
    });
    applyDefaultHandlers();
  }

  function setChannels(channels) {
    if (!Array.isArray(channels) || channels.length === 0) {
      state.channels = new Set(DEFAULT_CHANNELS);
      return;
    }
    const cleaned = channels
      .map((channel) => (typeof channel === 'string' ? channel.trim() : ''))
      .filter((channel) => ALLOWED_CHANNEL.test(channel));
    state.channels = new Set(cleaned.length ? cleaned : DEFAULT_CHANNELS);
  }

  function shouldRefreshToken() {
    return !state.meLoaded
      || !state.me?.ws?.token
      || !state.meFetchedAt
      || Date.now() - state.meFetchedAt >= TOKEN_REFRESH_THRESHOLD_MS;
  }

  async function loadMe(forceRefresh) {
    if (!forceRefresh && !shouldRefreshToken()) return state.me;

    const response = await fetch('./api/me.php', {
      credentials: 'same-origin',
      headers: { 'Cache-Control': 'no-cache', Accept: 'application/json' },
    });
    if (!response.ok) throw new Error(`me.php returned ${response.status}`);

    const data = await response.json();
    state.me = data || {};
    state.meLoaded = true;
    state.meFetchedAt = Date.now();
    state.forceRefresh = false;

    if (state.selfUserId === null && data?.user_id != null) {
      state.selfUserId = normalizeId(data.user_id);
    }
    if (!data?.ws?.token) {
      state.disabled = true;
      setStatus('disabled', '');
    }
    return state.me;
  }

  function getCurrentFilters() {
    let courseId;
    let roomId;
    if (typeof state.getFilters === 'function') {
      try {
        const result = state.getFilters() || {};
        courseId = result.courseId;
        roomId = result.roomId;
      } catch (_) {
        logOnce('filters', 'warn', 'Realtime filters could not be read.');
      }
    }
    if (state.staticFilters.courseId !== undefined) courseId = state.staticFilters.courseId;
    if (state.staticFilters.roomId !== undefined) roomId = state.staticFilters.roomId;
    return { courseId: normalizeId(courseId), roomId: normalizeId(roomId) };
  }

  function getSelfUserId() {
    if (state.selfUserId !== null) return state.selfUserId;
    if (typeof state.getSelfUserId === 'function') {
      try {
        const value = normalizeId(state.getSelfUserId());
        if (value !== null) return value;
      } catch (_) {
        logOnce('self-user', 'warn', 'Realtime user context could not be read.');
      }
    }
    return normalizeId(state.me?.ws?.user_id ?? state.me?.user_id);
  }

  function computeEndpoint() {
    const wsInfo = state.me?.ws;
    if (!wsInfo?.token) return null;

    let baseUrlRaw = wsInfo.ws_url || wsBaseUrl;
    if (!baseUrlRaw && global.location?.host) {
      baseUrlRaw = `${global.location.protocol === 'http:' ? 'ws:' : 'wss:'}//${global.location.host}`;
    }
    if (!baseUrlRaw) return null;

    const baseUrl = new URL(baseUrlRaw);
    const query = new URLSearchParams();
    const channels = Array.from(state.channels);
    if (channels.length) query.set('channels', channels.join(','));

    const filters = getCurrentFilters();
    if (filters.courseId !== null) query.set('course_id', String(filters.courseId));
    if (filters.roomId !== null) query.set('room_id', String(filters.roomId));
    query.set('token', wsInfo.token);

    const socketPath = wsInfo.socket_path || wsPath || DEFAULT_WS_PATH;
    return {
      origin: `${baseUrl.protocol}//${baseUrl.host}`,
      path: `/${String(socketPath).replace(/^\/+/, '')}`,
      query: Object.fromEntries(query),
    };
  }

  function clearTimer(name) {
    if (state[name]) {
      clearTimeout(state[name]);
      state[name] = null;
    }
  }

  function resetBackoff() {
    state.reconnectDelay = INITIAL_BACKOFF;
    state.reconnectAttempts = 0;
  }

  function scheduleReconnect() {
    if (state.disabled || state.fatalReason || state.reconnectTimer) return;
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
      setStatus('offline', 'Offline. Realtime updates are paused.');
      return;
    }
    if (state.reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
      setStatus('unavailable', 'Realtime updates are unavailable. Changes still save normally.');
      logOnce('retry-limit', 'warn', 'Realtime updates paused after repeated connection failures.');
      return;
    }

    state.reconnectAttempts += 1;
    const jitter = Math.random() * 0.25 * state.reconnectDelay;
    const delay = state.reconnectDelay + jitter;
    setStatus('reconnecting', `Reconnecting live updates (${state.reconnectAttempts}/${MAX_RECONNECT_ATTEMPTS})...`);
    state.reconnectTimer = setTimeout(() => {
      state.reconnectTimer = null;
      ensureConnection();
    }, delay);
    state.reconnectDelay = Math.min(state.reconnectDelay * 2, MAX_BACKOFF);
  }

  function closeCurrent(manual) {
    const socket = state.socket;
    if (!socket) return;
    if (manual) state.manualSockets.add(socket);
    state.socket = null;
    try {
      socket.disconnect();
    } catch (_) {
      // The socket is already unusable; clearing the reference is sufficient.
    }
  }

  function reportHandlerError(eventName) {
    logOnce(`handler:${eventName}`, 'error', `A realtime handler failed for "${eventName}".`);
  }

  function extractTaAcceptUserId(payload) {
    if (!payload || typeof payload !== 'object') return null;
    return normalizeId(
      payload.student_user_id
      ?? payload.user_id
      ?? payload.payload?.student_user_id
      ?? payload.payload?.user_id,
    );
  }

  function handleIncomingMessage(data, explicitEventName) {
    let parsed = data;
    if (typeof data === 'string') {
      try {
        parsed = JSON.parse(data);
      } catch (_) {
        return;
      }
    }
    if (!parsed || typeof parsed !== 'object') return;

    const eventName = explicitEventName || parsed.event || parsed.event_name || parsed.type || parsed.channel;
    if (!eventName) return;
    const payload = parsed.payload !== undefined && !parsed.event_name ? parsed.payload : parsed;

    const handlerMap = {
      queue: 'onQueue',
      rooms: 'onRooms',
      progress: 'onProgress',
      ta_accept: 'onTaAccept',
      projector_serve: 'onProjector',
      projector_call_again: 'onProjector',
    };
    const handlerName = handlerMap[eventName];

    if (eventName === 'ta_accept') {
      const targetId = extractTaAcceptUserId(payload);
      const selfId = getSelfUserId();
      if (targetId !== null && selfId !== null && targetId !== selfId) return;
    }

    if (handlerName && typeof state.handlers[handlerName] === 'function') {
      try {
        state.handlers[handlerName](payload);
      } catch (_) {
        reportHandlerError(eventName);
      }
      return;
    }

    if (typeof state.handlers.onEvent === 'function') {
      try {
        state.handlers.onEvent(parsed.event_name ? parsed : { ...parsed, event_name: eventName });
      } catch (_) {
        reportHandlerError(eventName);
      }
    }
  }

  function bindSocketEvents(socket) {
    socket.on('connect', () => {
      resetBackoff();
      state.loggedMessages.delete('retry-limit');
      setStatus('connected', '');
      if (typeof state.handlers.onOpen === 'function') {
        try { state.handlers.onOpen(); } catch (_) { reportHandlerError('open'); }
      }
    });

    socket.on('connect_error', () => {
      if (state.socket === socket) state.socket = null;
      state.forceRefresh = true;
      try { socket.close(); } catch (_) { /* no-op */ }
      scheduleReconnect();
    });

    socket.on('disconnect', () => {
      const manual = state.manualSockets.has(socket);
      state.manualSockets.delete(socket);
      if (state.socket === socket) state.socket = null;
      if (typeof state.handlers.onClose === 'function') {
        try { state.handlers.onClose(); } catch (_) { reportHandlerError('close'); }
      }
      if (!manual) {
        state.forceRefresh = true;
        scheduleReconnect();
      }
    });

    BASE_EVENTS.forEach((eventName) => {
      socket.on(eventName, (payload) => handleIncomingMessage(payload, eventName));
    });

    if (typeof socket.onAny === 'function') {
      socket.onAny((eventName, payload) => {
        if (!BASE_EVENTS.has(eventName)) handleIncomingMessage(payload, eventName);
      });
    }
  }

  function connectSocket() {
    if (state.disabled || state.fatalReason || state.socket) return;
    if (typeof global.io !== 'function') {
      state.disabled = true;
      state.fatalReason = 'client_library_missing';
      setStatus('unavailable', 'Live updates could not start. Refresh after deployment completes.');
      logOnce('missing-client', 'error', 'Realtime unavailable: the Socket.IO client library did not load.');
      return;
    }

    const endpoint = computeEndpoint();
    if (!endpoint) {
      state.disabled = true;
      state.fatalReason = 'endpoint_unavailable';
      setStatus('unavailable', 'Live updates are not configured.');
      logOnce('missing-endpoint', 'error', 'Realtime unavailable: connection configuration is incomplete.');
      return;
    }

    setStatus(state.reconnectAttempts ? 'reconnecting' : 'connecting', 'Connecting live updates...');
    try {
      const socket = global.io(endpoint.origin, {
        path: endpoint.path,
        query: endpoint.query,
        transports: ['websocket'],
        upgrade: true,
        forceNew: true,
        withCredentials: true,
        reconnection: false,
      });
      state.socket = socket;
      bindSocketEvents(socket);
    } catch (_) {
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
    if (state.connectionPromise) return state.connectionPromise;
    if (state.disabled || state.fatalReason || state.socket) return;

    state.connectionPromise = (async () => {
      try {
        await loadMe(state.forceRefresh || force);
        if (!state.disabled && !state.socket) connectSocket();
      } catch (_) {
        scheduleReconnect();
      } finally {
        state.connectionPromise = null;
      }
    })();
    return state.connectionPromise;
  }

  function restartConnection() {
    clearTimer('restartTimer');
    state.restartTimer = setTimeout(() => {
      state.restartTimer = null;
      clearTimer('reconnectTimer');
      resetBackoff();
      closeCurrent(true);
      ensureConnection(true);
    }, RESTART_DEBOUNCE_MS);
  }

  function updateFilters(filters, silent) {
    const next = filters || {};
    let changed = false;
    ['courseId', 'roomId'].forEach((key) => {
      if (!Object.prototype.hasOwnProperty.call(next, key)) return;
      const value = next[key] === undefined ? undefined : normalizeId(next[key]);
      if (state.staticFilters[key] !== value) {
        state.staticFilters[key] = value;
        changed = true;
      }
    });
    if (!changed) return;
    if (silent) {
      resetBackoff();
      ensureConnection();
    } else {
      restartConnection();
    }
  }

  function setSelfUserId(value) {
    state.selfUserId = value === undefined ? null : normalizeId(value);
  }

  function getState() {
    return {
      connected: Boolean(state.socket?.connected),
      disabled: state.disabled,
      status: state.status,
      fatalReason: state.fatalReason,
      reconnectAttempts: state.reconnectAttempts,
      filters: getCurrentFilters(),
      channels: Array.from(state.channels),
      meLoaded: state.meLoaded,
    };
  }

  function handleOnline() {
    if (state.disabled || state.fatalReason || state.socket?.connected) return;
    clearTimer('reconnectTimer');
    resetBackoff();
    state.forceRefresh = true;
    ensureConnection(true);
  }

  function handleOffline() {
    clearTimer('reconnectTimer');
    closeCurrent(true);
    setStatus('offline', 'Offline. Realtime updates are paused.');
  }

  applyDefaultHandlers();
  if (typeof global.addEventListener === 'function') {
    global.addEventListener('online', handleOnline);
    global.addEventListener('offline', handleOffline);
  }

  global.SignoffWS = {
    init(options) {
      const opts = options || {};
      if (opts.channels) setChannels(opts.channels);
      if (typeof opts.getFilters === 'function') state.getFilters = opts.getFilters;
      if (typeof opts.getSelfUserId === 'function') state.getSelfUserId = opts.getSelfUserId;
      if (Object.prototype.hasOwnProperty.call(opts, 'selfUserId')) setSelfUserId(opts.selfUserId);
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
    retry() {
      if (state.fatalReason) return;
      state.disabled = false;
      clearTimer('reconnectTimer');
      resetBackoff();
      state.forceRefresh = true;
      ensureConnection(true);
    },
    destroy() {
      state.disabled = true;
      clearTimer('reconnectTimer');
      clearTimer('restartTimer');
      closeCurrent(true);
      setStatus('disabled', '');
    },
  };
})(typeof window !== 'undefined' ? window : this);
