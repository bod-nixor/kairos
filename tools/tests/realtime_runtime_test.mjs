import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/js/ws.js', import.meta.url), 'utf8');

function createSocket() {
  const handlers = new Map();
  let anyHandler = null;
  return {
    connected: false,
    io: { on() {} },
    on(name, handler) {
      handlers.set(name, handler);
    },
    onAny(handler) {
      anyHandler = handler;
    },
    close() {},
    disconnect() {
      handlers.get('disconnect')?.('io client disconnect');
    },
    trigger(name, payload) {
      handlers.get(name)?.(payload);
    },
    triggerAny(name, payload) {
      anyHandler?.(name, payload);
    },
  };
}

function createHarness({ withIo = true } = {}) {
  const sockets = [];
  const timers = new Map();
  const errors = [];
  let nextTimerId = 1;
  let ioCalls = 0;

  const context = {
    URL,
    URLSearchParams,
    Promise,
    Math,
    Date,
    navigator: { onLine: true },
    location: { protocol: 'https:', host: 'kairos.example.test' },
    fetch: async () => ({
      ok: true,
      json: async () => ({
        user_id: 42,
        ws: {
          user_id: 42,
          token: 'test-token',
          ws_url: 'wss://kairos.example.test',
          socket_path: '/websocket/socket.io',
        },
      }),
    }),
    console: {
      error(message) { errors.push(String(message)); },
      warn() {},
      info() {},
      debug() {},
    },
    setTimeout(handler) {
      const id = nextTimerId++;
      timers.set(id, handler);
      return id;
    },
    clearTimeout(id) {
      timers.delete(id);
    },
    addEventListener() {},
    dispatchEvent() {},
    CustomEvent: class CustomEvent {
      constructor(type, options) {
        this.type = type;
        this.detail = options?.detail;
      }
    },
    waitForAppConfig: async () => ({}),
  };
  context.window = context;
  if (withIo) {
    context.io = () => {
      ioCalls += 1;
      const socket = createSocket();
      sockets.push(socket);
      return socket;
    };
  }

  vm.createContext(context);
  vm.runInContext(source, context, { filename: 'public/js/ws.js' });

  return {
    context,
    sockets,
    timers,
    errors,
    ioCalls: () => ioCalls,
    async flush() {
      await new Promise((resolve) => setImmediate(resolve));
      await Promise.resolve();
    },
    async runNextTimer() {
      const next = timers.entries().next();
      assert.equal(next.done, false, 'expected a scheduled reconnect');
      const [id, handler] = next.value;
      timers.delete(id);
      handler();
      await this.flush();
    },
  };
}

{
  const harness = createHarness({ withIo: false });
  harness.context.SignoffWS.init({});
  await harness.flush();
  const state = harness.context.SignoffWS.getState();
  assert.equal(state.disabled, true);
  assert.equal(state.fatalReason, 'client_library_missing');
  assert.equal(harness.timers.size, 0, 'missing client library must not retry forever');
  assert.equal(harness.errors.length, 1, 'missing client library should produce one diagnostic');
}

{
  const harness = createHarness();
  const received = [];
  harness.context.SignoffWS.init({ onEvent: (payload) => received.push(payload) });
  harness.context.SignoffWS.init({ onEvent: (payload) => received.push(payload) });
  await harness.flush();
  assert.equal(harness.ioCalls(), 1, 'concurrent init calls must share one connection attempt');

  const socket = harness.sockets[0];
  socket.connected = true;
  socket.trigger('connect');
  socket.triggerAny('announcement.created', {
    event_name: 'announcement.created',
    event_id: 'event-1',
  });
  assert.equal(received.length, 1, 'named LMS events should reach the generic event handler');

  for (let attempt = 0; attempt <= 8; attempt += 1) {
    const current = harness.sockets[harness.sockets.length - 1];
    current.trigger('connect_error');
    if (attempt < 8) await harness.runNextTimer();
  }
  const state = harness.context.SignoffWS.getState();
  assert.equal(state.reconnectAttempts, 8);
  assert.equal(state.status, 'unavailable');
  assert.equal(harness.timers.size, 0, 'retry ceiling must stop further timers');
}

console.log('realtime runtime tests passed');
