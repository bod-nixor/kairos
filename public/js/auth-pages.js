(function () {
  'use strict';

  const page = document.body?.dataset.authPage || '';
  const tokenMatch = /^#token=([A-Za-z0-9_-]{40,64})$/.exec(window.location.hash || '');
  const token = tokenMatch ? tokenMatch[1] : '';
  if (window.location.hash) {
    history.replaceState(null, document.title, window.location.pathname + window.location.search);
  }

  document.addEventListener('DOMContentLoaded', async () => {
    const form = document.getElementById('authActionForm');
    const status = document.getElementById('authActionStatus');
    const submit = form?.querySelector('button[type="submit"]');
    const password = document.getElementById('authPassword');
    const confirm = document.getElementById('authPasswordConfirm');
    const guidance = document.getElementById('authPasswordGuidance');
    const config = typeof window.waitForAppConfig === 'function'
      ? await window.waitForAppConfig().catch(() => window.SignoffConfig || {})
      : (window.SignoffConfig || {});

    if (config.localAuthEnabled !== true) {
      if (submit) submit.disabled = true;
      window.KairosAuth?.setStatus(status, 'Password account services are temporarily unavailable.', 'warning');
      return;
    }

    if ((page === 'activation' || page === 'password_reset') && !token) {
      if (submit) submit.disabled = true;
      window.KairosAuth?.setStatus(status, 'This link is invalid or incomplete. Request a new link.', 'danger');
      return;
    }

    if (token) {
      try {
        if (submit) submit.disabled = true;
        window.KairosAuth?.setStatus(status, 'Checking this secure link...', 'neutral');
        await window.KairosAuth.post('./api/auth/validate_token.php', { purpose: page, token });
        window.KairosAuth?.setStatus(status, 'Link verified. Set your new password.', 'success');
        if (submit) submit.disabled = false;
      } catch (error) {
        if (submit) submit.disabled = true;
        window.KairosAuth?.setStatus(status, error.message || 'This link is invalid or expired.', 'danger');
        return;
      }
    }

    password?.addEventListener('input', () => {
      const result = window.KairosAuth.passwordGuidance(password.value, config.passwordPolicy || {});
      if (guidance) {
        guidance.textContent = result.message;
        guidance.dataset.tone = result.tone;
      }
    });

    form?.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (submit) submit.disabled = true;
      try {
        if (page === 'forgot_password') {
          const identifier = form.elements.identifier.value.trim();
          if (!identifier) throw new Error('Enter your username or email.');
          const result = await window.KairosAuth.post('./api/auth/request_password_reset.php', { identifier });
          form.reset();
          window.KairosAuth.setStatus(status, result.message, 'success');
          return;
        }

        if (!password?.value || password.value !== confirm?.value) {
          throw new Error('Passwords must match.');
        }
        const path = page === 'activation'
          ? './api/auth/activate.php'
          : './api/auth/reset_password.php';
        const result = await window.KairosAuth.post(path, { token, password: password.value });
        form.reset();
        window.KairosAuth.setStatus(status, result.message, 'success');
        const link = document.createElement('a');
        link.href = '/signoff/';
        link.className = 'btn btn-primary k-auth-submit';
        link.textContent = 'Continue to login';
        form.appendChild(link);
        submit?.remove();
      } catch (error) {
        window.KairosAuth?.setStatus(status, error.message || 'Unable to complete this request.', 'danger');
      } finally {
        if (submit && submit.isConnected) submit.disabled = false;
      }
    });
  });
})();
