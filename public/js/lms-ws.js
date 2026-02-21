/**
 * lms-ws.js — Kairos LMS WebSocket Channel Adapter
 * Extends the existing SignoffWS pattern with LMS-specific event channels.
 * Must be loaded AFTER ws.js and lms-core.js.
 */
(function (global) {
    'use strict';

    const LMS_CHANNELS = [
        'lms_content',
        'lms_quiz',
        'lms_assignment',
        'lms_grade',
        'lms_analytics',
        'lms_announcement',
    ];

    // All existing channels from ws.js
    const BASE_CHANNELS = ['rooms', 'queue', 'progress', 'ta_accept', 'projector'];

    // Event name → handler set map
    const _handlers = new Map();
    let _initialized = false;
    let _courseId = null;

    function getOrCreate(eventName) {
        if (!_handlers.has(eventName)) {
            _handlers.set(eventName, new Set());
        }
        return _handlers.get(eventName);
    }

    function on(eventName, handler) {
        if (typeof handler !== 'function') return () => { };
        getOrCreate(eventName).add(handler);
        return () => off(eventName, handler);
    }

    function off(eventName, handler) {
        const set = _handlers.get(eventName);
        if (set) set.delete(handler);
    }

    /* Dispatch incoming LMS WS events to registered handlers */
    function dispatchEvent(payload) {
        if (!payload || typeof payload !== 'object') return;

        // AGENTS.md: deduplicate by event_id
        const eventId = payload.event_id;
        if (eventId && global.KairosLMS && typeof global.KairosLMS.markEventSeen === 'function') {
            if (!global.KairosLMS.markEventSeen(eventId)) {
                return; // already seen — drop
            }
        }

        const eventName = payload.event_name;
        if (!eventName) return;

        // Dispatch to specific event handlers
        const set = _handlers.get(eventName);
        if (set && set.size > 0) {
            set.forEach(fn => {
                try { fn(payload); } catch (e) { console.error('LmsWS handler error', eventName, e); }
            });
        }

        // Dispatch to wildcard handlers
        const wildcards = _handlers.get('*');
        if (wildcards && wildcards.size > 0) {
            wildcards.forEach(fn => {
                try { fn(payload); } catch (e) { console.error('LmsWS wildcard handler error', e); }
            });
        }
    }

    function setCourseContext(courseId) {
        _courseId = courseId ? Number(courseId) : null;
        if (global.SignoffWS) {
            global.SignoffWS.updateFilters({ courseId: _courseId });
        }
    }

    function init(options) {
        if (_initialized) return;
        _initialized = true;

        const opts = options || {};
        const allChannels = opts.includeBaseChannels
            ? [...BASE_CHANNELS, ...LMS_CHANNELS]
            : LMS_CHANNELS;

        if (global.SignoffWS) {
            global.SignoffWS.init({
                channels: allChannels,
                courseId: opts.courseId || _courseId || undefined,
                // Route LMS events through our dispatcher
                onQueue: opts.onQueue || null,
                onRooms: opts.onRooms || null,
                onProgress: opts.onProgress || null,
            });
        }

        // Listen for lms_* events via Socket.IO
        // The backend emits them as named Socket.IO events.
        // We hook into the raw socket if available, falling back to
        // polling dispatcher registration when socket unavailable.
        _hookIntoSocket();
    }

    function _hookIntoSocket() {
        // Poll for socket availability (SignoffWS manages connection)
        const MAX_POLLS = 30;
        let polls = 0;
        const interval = setInterval(() => {
            polls++;
            const ws = global.SignoffWS;
            if (!ws) {
                if (polls >= MAX_POLLS) clearInterval(interval);
                return;
            }
            const state = ws.getState ? ws.getState() : null;
            // Access raw socket if exposed, else wait for connected
            if (!state || !state.connected) {
                if (polls >= MAX_POLLS) clearInterval(interval);
                return;
            }
            clearInterval(interval);

            // Register LMS event names on the underlying socket.
            // Since SignoffWS wraps the socket internally, we use a custom
            // global hook that ws.js will call if defined:
            //   global.lmsEventHandler(payload)
            global.lmsEventHandler = dispatchEvent;

        }, 500);
    }

    /*
     * Emit helper — for testing only. Fires handlers as if WS event arrived.
     * Also useful for manual testing in browser console:
     *   LmsWS.emit({ event_name: 'announcement.created', ... })
     */
    function emit(payload) {
        dispatchEvent(payload);
    }

    global.LmsWS = { on, off, emit, setCourseContext, init, channels: LMS_CHANNELS };

})(typeof window !== 'undefined' ? window : this);
