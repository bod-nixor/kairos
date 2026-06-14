(function (global) {
  'use strict';

  let csrfPromise = null;

  async function csrf() {
    if (!csrfPromise) {
      csrfPromise = fetch('./api/auth/csrf.php', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      }).then(async (response) => {
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload?.ok !== true || !payload?.data?.csrf_token) {
          throw new Error('Unable to verify this request. Refresh and try again.');
        }
        return payload.data;
      }).catch((error) => {
        csrfPromise = null;
        throw error;
      });
    }
    return csrfPromise;
  }

  async function post(path, body = {}) {
    const csrfData = await csrf();
    const response = await fetch(path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfData.csrf_token,
      },
      body: JSON.stringify(body),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload?.ok === false || payload?.success === false) {
      const error = new Error(
        payload?.error?.message || payload?.error || payload?.message || 'Request failed.'
      );
      error.code = payload?.error?.code || payload?.code || '';
      error.status = response.status;
      error.data = payload?.data || null;
      throw error;
    }
    return payload?.data ?? payload;
  }

  function passwordGuidance(password, policy = {}) {
    const minLength = Number(policy.minLength || policy.min_length || 12);
    const length = Array.from(String(password || '')).length;
    if (!length) return { tone: 'neutral', message: `Use at least ${minLength} characters.` };
    if (length < minLength) {
      return { tone: 'danger', message: `${minLength - length} more characters needed.` };
    }
    if (length < 16) {
      return { tone: 'warning', message: 'Good. A longer unique passphrase is even stronger.' };
    }
    return { tone: 'success', message: 'Strong length. Make sure it is unique to Kairos.' };
  }

  function setStatus(node, message, tone = 'neutral') {
    if (!node) return;
    node.textContent = message || '';
    node.className = `k-auth-status k-auth-status--${tone}`;
    node.hidden = !message;
  }

  global.KairosAuth = {
    csrf,
    post,
    passwordGuidance,
    setStatus,
    resetCsrf() { csrfPromise = null; },
  };
})(window);
