/**
 * lms-core.js — Kairos LMS Shared Core
 * API wrapper, reactive state store, toast system, modal, nav, and utilities.
 * Exposed on window.KairosLMS — loaded AFTER config.js on every LMS page.
 */
(function (global) {
  'use strict';

  /* ── Escape HTML ─────────────────────────────────────────── */
  function escHtml(str) {
    if (!str && str !== 0) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  /* ── Date formatting ─────────────────────────────────────── */
  function fmtDate(iso) {
    if (!iso) return '';
    try {
      const d = new Date(iso);
      return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    } catch { return iso; }
  }

  function fmtDateTime(iso) {
    if (!iso) return '';
    try {
      const d = new Date(iso);
      return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch { return iso; }
  }

  function timeAgo(iso) {
    if (!iso) return '';
    try {
      const diff = Date.now() - new Date(iso).getTime();
      const mins = Math.floor(diff / 60000);
      if (mins < 1) return 'just now';
      if (mins < 60) return `${mins}m ago`;
      const hrs = Math.floor(mins / 60);
      if (hrs < 24) return `${hrs}h ago`;
      const days = Math.floor(hrs / 24);
      return `${days}d ago`;
    } catch { return ''; }
  }

  /* ── Course accent color (deterministic) ─────────────────── */
  function courseAccent(courseId) {
    const id = parseInt(courseId, 10) || 1;
    return ((id - 1) % 8) + 1;
  }


  function normalizePathname(pathname) {
    const raw = String(pathname || '').replace(/\/+$/, '');
    return raw || '/';
  }

  const APP_BASE = '/signoff/';
  const REDIRECT_SENTINEL_KEY = 'kairos:lastRedirect';
  const REDIRECT_SENTINEL_WINDOW_MS = 2000;

  function isSignoffHomePath(pathname) {
    const normalized = normalizePathname(pathname);
    return normalized === '/signoff' || normalized === '/signoff/index.html';
  }

  function redirectToSignoffHome() {
    if (!global.location) return;
    const currentPath = normalizePathname(global.location.pathname);
    const targetPath = normalizePathname(APP_BASE);
    if (currentPath === targetPath) return;

    let canRedirect = true;
    try {
      const now = Date.now();
      const raw = global.sessionStorage ? global.sessionStorage.getItem(REDIRECT_SENTINEL_KEY) : null;
      if (raw) {
        const parsed = JSON.parse(raw);
        if (parsed && parsed.path === targetPath && Number.isFinite(parsed.at) && (now - parsed.at) < REDIRECT_SENTINEL_WINDOW_MS) {
          canRedirect = false;
        }
      }
      if (canRedirect && global.sessionStorage) {
        global.sessionStorage.setItem(REDIRECT_SENTINEL_KEY, JSON.stringify({ path: targetPath, at: now }));
      }
    } catch (_) {
      canRedirect = true;
    }

    if (canRedirect) {
      global.location.replace(APP_BASE);
    }
  }

  /* ── API wrapper ─────────────────────────────────────────── */
  async function api(method, path, body) {
    const opts = {
      method: method.toUpperCase(),
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    };
    if (body !== undefined) {
      if (body instanceof FormData) {
        opts.body = body;
      } else {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
      }
    }
    try {
      const resp = await fetch(path, opts);
      if (resp.status === 401) {
        redirectToSignoffHome();
        return { ok: false, status: 401, error: 'Unauthorized', data: null };
      }
      let data = null;
      const ctype = resp.headers.get('content-type') || '';
      if (ctype.includes('application/json')) {
        try { data = await resp.json(); } catch { data = null; }
      }
      if (!resp.ok) {
        const errMsg = (data && data.error) ? data.error : `HTTP ${resp.status}`;
        return { ok: false, status: resp.status, error: errMsg, data };
      }
      return { ok: true, status: resp.status, error: null, data };
    } catch (err) {
      return { ok: false, status: 0, error: err.message || 'Network error', data: null };
    }
  }

  /* ── Reactive State Store ────────────────────────────────── */
  function createStore(initial) {
    let state = Object.assign({}, initial);
    const subs = new Set();
    return {
      get() { return state; },
      getProp(key) { return state[key]; },
      set(patch) {
        state = Object.assign({}, state, patch);
        subs.forEach(fn => { try { fn(state); } catch (e) { console.error('Store subscriber error', e); } });
      },
      subscribe(fn) {
        subs.add(fn);
        return () => subs.delete(fn);
      },
    };
  }

  /* ── Toast System ────────────────────────────────────────── */
  let _toastStack = null;

  function getToastStack() {
    if (!_toastStack) {
      _toastStack = document.createElement('div');
      _toastStack.className = 'k-toast-stack';
      _toastStack.setAttribute('aria-live', 'polite');
      _toastStack.setAttribute('aria-atomic', 'false');
      document.body.appendChild(_toastStack);
    }
    return _toastStack;
  }

  const TOAST_ICONS = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };

  function toast(message, type, duration) {
    type = type || 'info';
    duration = typeof duration === 'number' ? duration : 4000;
    const stack = getToastStack();
    const el = document.createElement('div');
    el.className = `k-toast k-toast--${type}`;
    el.setAttribute('role', 'status');
    el.innerHTML = `
      <span class="k-toast__icon" aria-hidden="true">${TOAST_ICONS[type] || 'ℹ️'}</span>
      <div class="k-toast__body">
        <p class="k-toast__title">${escHtml(message)}</p>
      </div>
      <button class="k-toast__close" aria-label="Dismiss">&times;</button>
    `;
    el.querySelector('.k-toast__close').addEventListener('click', () => dismissToast(el));
    stack.appendChild(el);
    requestAnimationFrame(() => { el.classList.add('is-visible'); });
    const t = setTimeout(() => dismissToast(el), duration);
    el._timer = t;
    return el;
  }

  function dismissToast(el) {
    if (!el || !el.parentNode) return;
    clearTimeout(el._timer);
    el.classList.add('is-hiding');
    el.addEventListener('transitionend', () => el.remove(), { once: true });
    setTimeout(() => { if (el.parentNode) el.remove(); }, 400);
  }

  /* ── Modal System ────────────────────────────────────────── */
  let _activeModal = null;

  function openModal({ title, body, actions, wide, narrow, onClose } = {}) {
    closeModal();
    const el = document.createElement('div');
    el.className = 'k-modal';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-label', title || 'Dialog');
    const sizeClass = wide ? 'k-modal__box--wide' : narrow ? 'k-modal__box--narrow' : '';
    const actionsHtml = (actions || []).map(a =>
      `<button class="btn ${escHtml(a.class || 'btn-ghost')}" data-modal-action="${escHtml(a.id || '')}">${escHtml(a.label)}</button>`
    ).join('');
    el.innerHTML = `
      <div class="k-modal__backdrop"></div>
      <div class="k-modal__box ${sizeClass}" role="document">
        <div class="k-modal__head">
          <h2 class="k-modal__title">${escHtml(title || '')}</h2>
          <button class="k-modal__close" aria-label="Close">&times;</button>
        </div>
        <div class="k-modal__body"></div>
        ${actionsHtml ? `<div class="k-modal__foot">${actionsHtml}</div>` : ''}
      </div>
    `;
    const bodyEl = el.querySelector('.k-modal__body');
    if (typeof body === 'string') {
      bodyEl.innerHTML = body;
    } else if (body instanceof HTMLElement) {
      bodyEl.appendChild(body);
    }
    document.body.appendChild(el);
    requestAnimationFrame(() => el.classList.add('is-open'));
    _activeModal = el;

    const close = () => { if (onClose) onClose(); closeModal(); };
    el.querySelector('.k-modal__close').addEventListener('click', close);
    el.querySelector('.k-modal__backdrop').addEventListener('click', close);
    el.querySelectorAll('[data-modal-action]').forEach(btn => {
      const act = (actions || []).find(a => a.id === btn.dataset.modalAction);
      if (act && typeof act.onClick === 'function') {
        btn.addEventListener('click', () => act.onClick(btn, el));
      }
    });

    const firstFocusable = el.querySelector('button, [tabindex="0"]');
    if (firstFocusable) firstFocusable.focus();

    const trapFocus = e => {
      if (e.key !== 'Tab' || !el.isConnected) return;
      const focusable = Array.from(el.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])')).filter(f => !f.disabled);
      if (!focusable.length) return;
      const first = focusable[0], last = focusable[focusable.length - 1];
      if (e.shiftKey && document.activeElement === first) { last.focus(); e.preventDefault(); }
      else if (!e.shiftKey && document.activeElement === last) { first.focus(); e.preventDefault(); }
    };
    el._trapFocus = trapFocus;
    document.addEventListener('keydown', trapFocus);

    const escHandler = e => { if (e.key === 'Escape') close(); };
    el._escHandler = escHandler;
    document.addEventListener('keydown', escHandler);
    return el;
  }

  function confirm(title, message, onOk, { okLabel, okClass } = {}) {
    openModal({
      title,
      body: `<p>${escHtml(message)}</p>`,
      narrow: true,
      actions: [
        { id: 'cancel', label: 'Cancel', class: 'btn-ghost', onClick: () => closeModal() },
        { id: 'ok', label: okLabel || 'Confirm', class: okClass || 'btn-primary', onClick: () => { closeModal(); if (onOk) onOk(); } },
      ],
    });
  }

  function closeModal() {
    if (!_activeModal) return;
    const el = _activeModal;
    _activeModal = null;
    if (el._trapFocus) document.removeEventListener('keydown', el._trapFocus);
    if (el._escHandler) document.removeEventListener('keydown', el._escHandler);
    el.classList.remove('is-open');
    el.addEventListener('transitionend', () => el.remove(), { once: true });
    setTimeout(() => { if (el.parentNode) el.remove(); }, 300);
  }

  /* ── Skeleton helpers ────────────────────────────────────── */
  function skeletonCards(n) {
    return Array.from({ length: n }, () =>
      `<div class="k-skeleton k-skeleton--card" style="height:180px"></div>`
    ).join('');
  }

  function skeletonLines(n) {
    return Array.from({ length: n }, (_, i) =>
      `<div class="k-skeleton k-skeleton--text" style="width:${70 - i * 8}%;margin-bottom:8px"></div>`
    ).join('');
  }

  /* ── WS idempotency seen-set ────────────────────────────── */
  const _seenEvents = new Set();
  const MAX_SEEN = 500;

  function markEventSeen(eventId) {
    if (!eventId) return false;
    if (_seenEvents.has(eventId)) return false;
    if (_seenEvents.size >= MAX_SEEN) {
      const first = _seenEvents.values().next().value;
      _seenEvents.delete(first);
    }
    _seenEvents.add(eventId);
    return true;
  }

  /* ── Session / User Role ─────────────────────────────────── */
  let _me = null;
  let _caps = null;

  async function loadMe() {
    if (_me) return _me;
    const r = await api('GET', './api/me.php');
    _me = r.ok ? r.data : null;
    return _me;
  }

  async function loadCaps() {
    if (_caps) return _caps;
    const r = await api('GET', './api/session_capabilities.php');
    if (r.ok && r.data && r.data.ok === true && r.data.data && r.data.data.user) {
      const role = String(r.data.data.user.role || 'student').toLowerCase();
      _caps = {
        is_logged_in: true,
        roles: {
          student: true,
          ta: role === 'ta' || role === 'manager' || role === 'admin',
          manager: role === 'manager' || role === 'admin',
          admin: role === 'admin',
        },
      };
    } else if (r.ok && r.data && r.data.roles) {
      // Old format backwards compat
      _caps = r.data;
    } else {
      _caps = { is_logged_in: false, roles: { student: false, ta: false, manager: false, admin: false } };
    }
    return _caps;
  }

  function getRole() {
    if (!_caps) return { student: false, ta: false, manager: false, admin: false };
    return _caps.roles || {};
  }

  /* ── Access denied page rendering ───────────────────────── */
  function renderAccessDenied(container, message, backHref) {
    if (!container) return;
    container.innerHTML = `
      <div class="k-access-denied">
        <div class="k-access-denied__icon">🔒</div>
        <p class="k-access-denied__code">403</p>
        <h1 class="k-access-denied__title">Access Denied</h1>
        <p class="k-access-denied__desc">${escHtml(message || 'You do not have permission to view this page.')}</p>
        ${backHref ? `<a href="${escHtml(backHref)}" class="btn btn-primary">← Go Back</a>` : ''}
      </div>
    `;
  }

  /* ── Empty state rendering ───────────────────────────────── */
  function renderEmpty(container, { icon, title, desc, action }) {
    if (!container) return;
    container.innerHTML = `
      <div class="k-empty">
        <div class="k-empty__icon" aria-hidden="true">${icon || '📭'}</div>
        <h2 class="k-empty__title">${escHtml(title || 'Nothing here yet')}</h2>
        ${desc ? `<p class="k-empty__desc">${escHtml(desc)}</p>` : ''}
        ${action ? `<a href="${escHtml(action.href || '#')}" class="btn btn-primary">${escHtml(action.label)}</a>` : ''}
      </div>
    `;
  }

  /* ── Nav / Sidebar ───────────────────────────────────────── */
  const KairosNav = {
    _courseId: null,
    _courseName: null,

    setGlobalContext() {
      this._courseId = null;
      this._courseName = null;
      const global = document.getElementById('kNavGlobal');
      const course = document.getElementById('kNavCourse');
      const header = document.getElementById('kSidebarCourseHeader');
      if (global) global.removeAttribute('hidden');
      if (course) course.setAttribute('hidden', '');
      if (header) header.setAttribute('hidden', '');
    },

    setCourseContext(courseId, courseName) {
      this._courseId = courseId;
      this._courseName = courseName;
      const global = document.getElementById('kNavGlobal');
      const course = document.getElementById('kNavCourse');
      const header = document.getElementById('kSidebarCourseHeader');
      const nameEl = document.getElementById('kSidebarCourseName');
      if (global) global.setAttribute('hidden', '');
      if (course) course.removeAttribute('hidden');
      if (header) header.removeAttribute('hidden');
      if (nameEl && courseName) nameEl.textContent = courseName;
      // Patch all course nav hrefs with course_id
      if (courseId && course) {
        course.querySelectorAll('[data-course-href]').forEach(el => {
          const base = el.dataset.courseHref;
          el.href = `${base}?course_id=${encodeURIComponent(courseId)}`;
        });
      }
    },

    setActive(pageKey) {
      document.querySelectorAll('.k-nav-item[data-nav-key]').forEach(el => {
        const current = el.dataset.navKey === pageKey;
        el.setAttribute('aria-current', current ? 'page' : 'false');
        el.classList.toggle('is-active', current);
      });
    },

    setBreadcrumb(items) {
      const el = document.getElementById('kBreadcrumb');
      if (!el || !Array.isArray(items)) return;
      el.innerHTML = items.map((item, i) => {
        const isLast = i === items.length - 1;
        const safeName = escHtml(item.name || '');
        if (isLast) return `<span class="k-breadcrumb__item is-current">${safeName}</span>`;
        const href = escHtml(item.href || '#');
        return `<a class="k-breadcrumb__item" href="${href}">${safeName}</a><span class="k-breadcrumb__sep" aria-hidden="true">›</span>`;
      }).join('');
    },

    updateUserBar(me) {
      if (!me) return;
      const avatar = document.getElementById('kSidebarAvatar');
      const name = document.getElementById('kSidebarName');
      const roleEl = document.getElementById('kSidebarRole');
      if (avatar) avatar.src = me.picture_url || '';
      if (name) name.textContent = me.name || me.email || '';
      // Derive display role from session capabilities
      const r = _caps && _caps.roles ? _caps.roles : {};
      let roleLabel = 'Student';
      if (r.admin) roleLabel = 'Admin';
      else if (r.manager) roleLabel = 'Manager';
      else if (r.ta) roleLabel = 'TA';
      if (roleEl) roleEl.textContent = roleLabel;
    },
  };

  /* ── Feature flags ───────────────────────────────────────── */
  const _featureCache = new Map();

  async function featureEnabled(flag, courseId) {
    const key = `${courseId}:${flag}`;
    if (_featureCache.has(key)) return _featureCache.get(key);
    const r = await api('GET', `./api/lms/features.php?course_id=${encodeURIComponent(courseId)}&flag=${encodeURIComponent(flag)}`);
    let val = false;
    if (r.ok && r.data) {
      // r.data is {ok:true, data:{items:[...]}} from lms_ok()
      const payload = r.data.data || r.data;
      const items = Array.isArray(payload.items) ? payload.items : (Array.isArray(payload) ? payload : []);
      val = items.some(item => item.flag_key === flag && (item.enabled === 1 || item.enabled === true));
    }
    _featureCache.set(key, val);
    return val;
  }


  /* ── URL + Debug utilities ─────────────────────────────── */
  const _debugStores = new Map();


  function markdownToHtml(markdown) {
    const lines = String(markdown || '').split(/\r?\n/);
    const htmlLines = lines.map((line) => {
      const trimmed = line.trim();
      if (!trimmed) return '<p><br></p>';
      if (trimmed.startsWith('### ')) return `<h3>${escHtml(trimmed.slice(4))}</h3>`;
      if (trimmed.startsWith('## ')) return `<h2>${escHtml(trimmed.slice(3))}</h2>`;
      if (trimmed.startsWith('# ')) return `<h1>${escHtml(trimmed.slice(2))}</h1>`;
      if (trimmed.startsWith('- ') || trimmed.startsWith('* ')) return `<li>${escHtml(trimmed.slice(2))}</li>`;
      if (/^\d+\.\s+/.test(trimmed)) return `<li data-ordered="1">${escHtml(trimmed.replace(/^\d+\.\s+/, ''))}</li>`;
      return `<p>${escHtml(trimmed)}</p>`;
    });

    let html = htmlLines.join('');
    html = html.replace(/(<li(?![^>]*data-ordered)[^>]*>[\s\S]*?<\/li>)+/g, (chunk) => `<ul>${chunk}</ul>`);
    html = html.replace(/(<li data-ordered="1">[\s\S]*?<\/li>)+/g, (chunk) => `<ol>${chunk.replace(/ data-ordered="1"/g, '')}</ol>`);
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
    html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
    return html;
  }

  function htmlToMarkdown(html) {
    const container = document.createElement('div');
    container.innerHTML = html || '';

    const mapNode = (node) => {
      if (node.nodeType === Node.TEXT_NODE) return node.textContent || '';
      if (node.nodeType !== Node.ELEMENT_NODE) return '';
      const tag = node.tagName.toLowerCase();
      const text = Array.from(node.childNodes).map(mapNode).join('');
      if (tag === 'h1') return `# ${text}\n\n`;
      if (tag === 'h2') return `## ${text}\n\n`;
      if (tag === 'h3') return `### ${text}\n\n`;
      if (tag === 'strong' || tag === 'b') return `**${text}**`;
      if (tag === 'em' || tag === 'i') return `*${text}*`;
      if (tag === 'a') return `[${text}](${node.getAttribute('href') || ''})`;
      if (tag === 'li') return `- ${text}\n`;
      if (tag === 'ul' || tag === 'ol') return `${Array.from(node.children).map(mapNode).join('')}\n`;
      if (tag === 'br') return '\n';
      if (tag === 'p' || tag === 'div') return `${text}\n\n`;
      return text;
    };

    return Array.from(container.childNodes).map(mapNode).join('').replace(/\n{3,}/g, '\n\n').trim();
  }

  function parseStartSeconds(value) {
    const raw = String(value || '').trim();
    if (!raw) return 0;
    if (/^\d+$/.test(raw)) return Number(raw);
    const m = raw.match(/(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?/i);
    if (!m) return 0;
    return (Number(m[1] || 0) * 3600) + (Number(m[2] || 0) * 60) + Number(m[3] || 0);
  }

  function toYoutubeEmbedUrl(inputUrl) {
    if (!inputUrl) return null;
    try {
      const parsed = new URL(inputUrl);
      const host = parsed.hostname.replace(/^www\./i, '').toLowerCase();
      let videoId = '';
      if (host === 'youtube.com' || host === 'm.youtube.com') {
        if (parsed.pathname === '/watch') videoId = parsed.searchParams.get('v') || '';
        else if (parsed.pathname.startsWith('/embed/')) videoId = parsed.pathname.split('/')[2] || '';
        else if (parsed.pathname.startsWith('/shorts/')) videoId = parsed.pathname.split('/')[2] || '';
      } else if (host === 'youtu.be') {
        videoId = parsed.pathname.replace(/^\//, '').split('/')[0] || '';
      }
      if (!videoId) return null;
      const start = parseStartSeconds(parsed.searchParams.get('t') || parsed.searchParams.get('start') || '');
      const embed = new URL(`https://www.youtube.com/embed/${videoId}`);
      if (start > 0) embed.searchParams.set('start', String(start));
      return embed.toString();
    } catch (_) {
      return null;
    }
  }
  
function sanitizeForRender(html) {
    if (!html || typeof html !== 'string') return '';

    const div = document.createElement('div');
    div.innerHTML = html;

    // Remove dangerous tags
    div.querySelectorAll('script, style, object, embed').forEach(el => el.remove());

    // Remove inline event handlers (onclick, onerror, etc.)
    div.querySelectorAll('*').forEach(el => {
        [...el.attributes].forEach(attr => {
            if (attr.name.startsWith('on')) {
                el.removeAttribute(attr.name);
            }
            // Block javascript: URLs
            if (['href', 'src'].includes(attr.name)) {
                const value = attr.value.trim().toLowerCase();
                if (value.startsWith('javascript:')) {
                    el.removeAttribute(attr.name);
                }
            }
        });
    });

    return div.innerHTML;
}

  function debug(entry, options = {}) {
    const isDebugMode = new URLSearchParams(global.location.search).get('debug') === '1';
    if (!isDebugMode) return;
    const paneId = options.paneId || 'quizDebug';
    const pane = _debugStores.get(paneId) || [];
    pane.push(entry);
    _debugStores.set(paneId, pane);

    let debugEl = document.getElementById(paneId);
    if (!debugEl) {
      debugEl = document.createElement('pre');
      debugEl.id = paneId;
      debugEl.className = 'k-card';
      debugEl.style.cssText = 'padding:12px;white-space:pre-wrap;margin-top:12px;';
      document.querySelector('.k-page')?.appendChild(debugEl);
    }
    try {
      debugEl.textContent = JSON.stringify(pane, null, 2);
    } catch (_) {
      debugEl.textContent = String(pane);
    }
  }

  /* ── Boot helper (call on DOMContentLoaded in each page) ── */
  async function boot() {
    // Apply theme immediately
    if (global.themeInit) global.themeInit();
    // Load session
    const [me, caps] = await Promise.all([loadMe(), loadCaps()]);
    if (!me || !me.email) {
      redirectToSignoffHome();
      return;
    }
    // Logout button
    const logoutBtn = document.getElementById('kLogoutBtn');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', async () => {
        await api('POST', './api/logout.php', {});
        redirectToSignoffHome();
      });
    }
    return { me, caps };
  }

  /* ── Progress ring helper ────────────────────────────────── */
  function setProgressRing(svgEl, pct) {
    if (!svgEl) return;
    const fill = svgEl.querySelector('.k-score-ring__fill');
    if (!fill) return;
    const dashoffset = 345 * (1 - Math.min(1, Math.max(0, pct / 100)));
    fill.style.strokeDashoffset = dashoffset;
  }

  /* ── Drive URL helpers ─────────────────────────────────── */

  /**
   * Extract a Google Drive file ID from a variety of URL formats:
   *   /file/d/<id>/view, /file/d/<id>/preview, /file/d/<id>/edit
   *   open?id=<id>, uc?id=<id>, shared link variants, /d/<id>
   * Returns the file ID string or '' if not detected.
   */
  function extractDriveFileId(url) {
    if (!url) return '';
    try {
      const parsed = new URL(String(url));
      const host = parsed.hostname.replace(/^www\./i, '').toLowerCase();
      if (host !== 'drive.google.com' && host !== 'docs.google.com' && host !== 'slides.google.com') return '';
      // /file/d/<id>/...
      const fileMatch = parsed.pathname.match(/\/(?:file\/)?d\/([A-Za-z0-9_-]{10,})/);
      if (fileMatch) return fileMatch[1];
      // open?id=<id> or uc?id=<id>
      const qid = parsed.searchParams.get('id');
      if (qid && /^[A-Za-z0-9_-]{10,}$/.test(qid)) return qid;
      // /presentation/d/<id>, /document/d/<id>, /spreadsheets/d/<id>
      const docMatch = parsed.pathname.match(/\/(?:presentation|document|spreadsheets)\/d\/([A-Za-z0-9_-]{10,})/);
      if (docMatch) return docMatch[1];
      return '';
    } catch (_) {
      return '';
    }
  }

  /**
   * Convert any Drive/Docs/Slides URL to the best embeddable preview URL.
   *
   * Google Drive files: https://drive.google.com/file/d/<id>/preview
   * Google Slides:      https://docs.google.com/presentation/d/<id>/embed?start=false&loop=false
   * Google Docs:        https://docs.google.com/document/d/<id>/preview
   * Google Sheets:      https://docs.google.com/spreadsheets/d/<id>/preview
   *
   * For non-Drive URLs, returns the original URL unchanged.
   */
  function toDrivePreviewUrl(inputUrl) {
    if (!inputUrl) return '';
    try {
      const parsed = new URL(String(inputUrl));
      const host = parsed.hostname.replace(/^www\./i, '').toLowerCase();

      // Google Slides
      if (host === 'docs.google.com' && parsed.pathname.includes('/presentation/')) {
        const m = parsed.pathname.match(/\/presentation\/d\/([A-Za-z0-9_-]+)/);
        if (m) return `https://docs.google.com/presentation/d/${m[1]}/embed?start=false&loop=false`;
      }
      // Google Docs
      if (host === 'docs.google.com' && parsed.pathname.includes('/document/')) {
        const m = parsed.pathname.match(/\/document\/d\/([A-Za-z0-9_-]+)/);
        if (m) return `https://docs.google.com/document/d/${m[1]}/preview`;
      }
      // Google Sheets
      if (host === 'docs.google.com' && parsed.pathname.includes('/spreadsheets/')) {
        const m = parsed.pathname.match(/\/spreadsheets\/d\/([A-Za-z0-9_-]+)/);
        if (m) return `https://docs.google.com/spreadsheets/d/${m[1]}/preview`;
      }

      // Drive file URLs
      if (host === 'drive.google.com') {
        const fileId = extractDriveFileId(inputUrl);
        if (fileId) return `https://drive.google.com/file/d/${fileId}/preview`;
      }

      return inputUrl;
    } catch (_) {
      return inputUrl;
    }
  }

  /**
   * Check whether a URL can be determined to be a ppt/pptx (Office file),
   * and generate an Office Online embed URL for it.
   * Returns '' if not applicable.
   */
  function toOfficeViewerUrl(rawUrl) {
    if (!rawUrl) return '';
    try {
      const parsed = new URL(String(rawUrl));
      if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return '';
      const pathname = String(parsed.pathname || '').toLowerCase();
      if (!/\.pptx?$/.test(pathname)) return '';
      return `https://view.officeapps.live.com/op/embed.aspx?src=${encodeURIComponent(rawUrl)}`;
    } catch (_) {
      return '';
    }
  }

  /* ── Export ──────────────────────────────────────────────── */
  global.KairosLMS = {
    api,
    createStore,
    toast,
    dismissToast,
    openModal,
    closeModal,
    confirm,
    skeletonCards,
    skeletonLines,
    markEventSeen,
    renderAccessDenied,
    renderEmpty,
    escHtml,
    fmtDate,
    fmtDateTime,
    timeAgo,
    courseAccent,
    featureEnabled,
    loadMe,
    loadCaps,
    getRole,
    boot,
    setProgressRing,
    parseStartSeconds,
    toYoutubeEmbedUrl,
    extractDriveFileId,
    toDrivePreviewUrl,
    toOfficeViewerUrl,
    markdownToHtml,
    htmlToMarkdown,
    sanitizeForRender,
    debug,
    nav: KairosNav,
  };

})(typeof window !== 'undefined' ? window : this);
