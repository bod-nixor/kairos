(function (global) {
  'use strict';

  const COURSE_ROUTE = /^\/signoff\/(?:course|modules|lesson|resource-viewer|quizzes|quiz|assignments|assignment|grading|analytics)(?:\.html)?$/;
  let progress = null;

  function eligibleLink(event) {
    if (event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return null;
    }
    const link = event.target.closest('a[href]');
    if (!link || link.hasAttribute('download') || link.target === '_blank') return null;

    let target;
    try {
      target = new URL(link.href, global.location.href);
    } catch (_) {
      return null;
    }
    if (target.origin !== global.location.origin || !COURSE_ROUTE.test(target.pathname)) return null;
    if (target.pathname === global.location.pathname && target.search === global.location.search && target.hash) return null;
    return link;
  }

  function showProgress() {
    if (progress || !document.body) return;
    progress = document.createElement('div');
    progress.className = 'k-navigation-progress';
    progress.setAttribute('role', 'progressbar');
    progress.setAttribute('aria-label', 'Loading page');
    document.body.appendChild(progress);
  }

  function clearProgress() {
    progress?.remove();
    progress = null;
  }

  document.addEventListener('click', (event) => {
    if (!eligibleLink(event)) return;
    showProgress();
  }, true);

  global.addEventListener('pageshow', clearProgress);
  global.addEventListener('pagehide', () => {
    if (global.KairosLMS && typeof global.KairosLMS.invalidateAccessCache === 'function') {
      global.KairosLMS.invalidateAccessCache();
    }
  });
})(typeof window !== 'undefined' ? window : this);
