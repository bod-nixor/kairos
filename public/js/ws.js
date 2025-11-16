(function (global) {
  'use strict';

  const DEFAULT_CHANNELS = ['rooms', 'queue', 'progress', 'ta_accept'];
  const MAX_BACKOFF = 10000;
  const INITIAL_BACKOFF = 1000;
  const TOKEN_REFRESH_THRESHOLD_MS = 9 * 60 * 1000; // 9 minutes; server rejects tokens after ~10 minutes

  const state = {
    me: null,
    meLoaded: false,
    disabled: false,
    ws: null,
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

  function buildWsBaseUrl() {
    const wsInfo = state.me?.ws || {};
    const rawUrl = typeof wsInfo.ws_url === 'string' ? wsInfo.ws_url.trim() : '';
    const providedPort = (() => {
      const candidate = Number(wsInfo.port);
      if (Number.isFinite(candidate) && candidate > 0) {
        return String(Math.trunc(candidate));
      }
      return '';
    })();
    const fallbackPort = providedPort || '8090';

    if (rawUrl) {
      try {
        const base = new URL(rawUrl, window.location.origin);
        if (!base.port && providedPort) {
          base.port = providedPort;
        }
        const currentPath = (base.pathname || '').toLowerCase();
        if (!currentPath.endsWith('/ws')) {
          base.pathname = '/ws';
        }
        base.protocol = 'wss:';
        return base;
      } catch (err) {
        console.debug('WS invalid ws_url, falling back to default', err);
      }
    }

    const url = new URL(window.location.origin);
    url.protocol = 'wss:';
    const overrideHost = typeof wsInfo.host === 'string' ? wsInfo.host.trim() : '';
    if (overrideHost) {
      url.hostname = overrideHost;
    }
    url.port = fallbackPort;
    url.pathname = '/ws';
    url.search = '';
    url.hash = '';
    return url;
  }

  function computeEndpoint() {
    const wsInfo = state.me?.ws;
    if (!wsInfo?.token) {
      return null;
    }

    const baseUrl = buildWsBaseUrl();
    const params = new URLSearchParams(baseUrl.search ? baseUrl.search.replace(/^\?/, '') : '');
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

    const query = params.toString();
    baseUrl.search = query ? `?${query}` : '';
    baseUrl.protocol = 'wss:';
    if (!baseUrl.port) {
      baseUrl.port = '8090';
    }
    return baseUrl.toString();
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
    if (!state.ws) {
      return;
    }
    try {
      state.manualClose = !!manual;
      state.ws.close(code || 1000, reason || 'closing');
    } catch (err) {
      console.debug('WS close error', err);
    }
  }

  function handleMessage(event) {
    if (!event || typeof event.data !== 'string') {
      return;
    }
    if (event.data.length > 65536) {
      return;
    }
    let payload;
    try {
      payload = JSON.parse(event.data);
    } catch (err) {
      console.warn('WS invalid JSON payload', err);
      return;
    }
    if (!payload || payload.type !== 'event') {
      return;
    }
    const eventName = payload.event;
    const handler = state.handlers;

    if (eventName === 'queue' && typeof handler.onQueue === 'function') {
      try { handler.onQueue(payload); } catch (err) { console.error('WS onQueue handler error', err); }
      return;
    }
    if (eventName === 'rooms' && typeof handler.onRooms === 'function') {
      try { handler.onRooms(payload); } catch (err) { console.error('WS onRooms handler error', err); }
      return;
    }
    if (eventName === 'progress' && typeof handler.onProgress === 'function') {
      try { handler.onProgress(payload); } catch (err) { console.error('WS onProgress handler error', err); }
      return;
    }
    if (eventName === 'ta_accept' && typeof handler.onTaAccept === 'function') {
      const targetId = payload?.payload?.student_user_id ?? payload?.payload?.user_id;
      const selfId = getSelfUserId();
      if (selfId === null || targetId == null || normalizeId(targetId) === selfId) {
        try { handler.onTaAccept(payload); } catch (err) { console.error('WS onTaAccept handler error', err); }
      }
    }
  }

  function bindSocketEvents(ws) {
    ws.onopen = () => {
      resetBackoff();
      if (typeof state.handlers.onOpen === 'function') {
        try { state.handlers.onOpen(); } catch (err) { console.error('WS onOpen handler error', err); }
      }
    };
    ws.onmessage = handleMessage;
    ws.onerror = (err) => {
      console.debug('WS error event', err);
      state.forceRefresh = true;
    };
    ws.onclose = () => {
      const manual = state.manualClose;
      state.manualClose = false;
      state.ws = null;
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
    };
  }

  function connectSocket() {
    if (state.disabled || state.ws) {
      return;
    }
    const endpoint = computeEndpoint();
    if (!endpoint) {
      state.disabled = true;
      console.info('WS disabled: endpoint unavailable');
      return;
    }
    try {
      const ws = new WebSocket(endpoint);
      state.ws = ws;
      bindSocketEvents(ws);
    } catch (err) {
      console.warn('WS connection failed', err);
      state.ws = null;
      state.forceRefresh = true;
      scheduleReconnect();
    }
  }

  async function ensureConnection() {
    if (state.disabled || state.ws || state.connecting) {
      return;
    }
    try {
      await loadMe(state.forceRefresh);
    } catch (err) {
      scheduleReconnect();
      return;
    }
    if (state.disabled || state.ws) {
      return;
    }
    connectSocket();
  }

  function restartConnection() {
    resetBackoff();
    clearReconnectTimer();
    if (state.ws) {
      closeCurrent(1000, 'filters updated', true);
    } else {
      ensureConnection();
    }
  }

  function init(options) {
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
      // when invoked during init we don't want to immediately reconnect twice
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
      connected: !!state.ws,
      disabled: state.disabled,
      filters: getCurrentFilters(),
      channels: Array.from(state.channels),
      meLoaded: state.meLoaded,
    };
  }

  applyDefaultHandlers();

  global.SignoffWS = {
    init,
    updateFilters(filters) {
      updateFilters(filters, false);
    },
    setSelfUserId,
    getState,
  };
})(typeof window !== 'undefined' ? window : this);
