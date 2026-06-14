document.addEventListener('DOMContentLoaded', async () => {
  let session = null;
  try {
    if (window.KairosLMS && typeof window.KairosLMS.boot === 'function') {
      session = await window.KairosLMS.boot();
    }
  } catch (err) {
    console.error('[Settings] boot failed:', err);
    return;
  }

  const renderThemeCards = () => {
    const grid = document.getElementById('themeGrid');
    const themes = window.KairosTheme && typeof window.KairosTheme.getThemes === 'function'
      ? window.KairosTheme.getThemes()
      : [];
    const escapeHtml = window.KairosTheme && typeof window.KairosTheme.escapeHtml === 'function'
      ? window.KairosTheme.escapeHtml
      : null;
    const sanitizePreviewColor = window.KairosTheme && typeof window.KairosTheme.sanitizePreviewColor === 'function'
      ? window.KairosTheme.sanitizePreviewColor
      : null;
    if (!grid || !themes.length || !escapeHtml || !sanitizePreviewColor) return [];

    grid.innerHTML = themes.map((theme, index) => `
      <div class="k-theme-card" data-theme-value="${escapeHtml(theme.value)}" role="radio" aria-checked="false"
        tabindex="${index === 0 ? '0' : '-1'}">
        <div class="k-theme-preview">
          <div class="k-theme-preview-sidebar" style="background:${sanitizePreviewColor(theme.preview && theme.preview.sidebar, '#111827')}"></div>
          <div class="k-theme-preview-main" style="background:${sanitizePreviewColor(theme.preview && theme.preview.main, '#1f2937')}">
            <div class="k-theme-preview-card" style="background:${sanitizePreviewColor(theme.preview && theme.preview.card, '#374151')}; border-color:${sanitizePreviewColor(theme.preview && theme.preview.border, '#4b5563')}"></div>
            <div class="k-theme-preview-card" style="width: 60%; background:${sanitizePreviewColor(theme.preview && theme.preview.card, '#374151')}; border-color:${sanitizePreviewColor(theme.preview && theme.preview.border, '#4b5563')}"></div>
          </div>
        </div>
        <div class="k-theme-card-title">
          <span>${escapeHtml(theme.label)}</span>
          <span class="k-theme-check"></span>
        </div>
      </div>
    `).join('');
    grid.querySelectorAll('.k-theme-check').forEach((node) => {
      node.innerHTML = '&#10003;';
    });
    return Array.from(grid.querySelectorAll('.k-theme-card'));
  };

  const cards = renderThemeCards();

  const updateActiveCard = () => {
    const currentTheme = document.documentElement.dataset.theme || 'light';
    cards.forEach((card) => {
      const isActive = card.dataset.themeValue === currentTheme;
      card.classList.toggle('is-active', isActive);
      card.setAttribute('aria-checked', String(isActive));
      card.setAttribute('tabindex', isActive ? '0' : '-1');
    });
    if (!cards.some((card) => card.getAttribute('tabindex') === '0') && cards.length) {
      cards[0].setAttribute('tabindex', '0');
    }
  };

  const focusCard = (card) => {
    cards.forEach((node) => node.setAttribute('tabindex', '-1'));
    card.setAttribute('tabindex', '0');
    card.focus();
  };

  const syncControlsFromTheme = () => {
    updateActiveCard();
    if (!window.KairosTheme) return;
    const settings = window.KairosTheme.readSettings();
    const compactNode = document.getElementById('kInputCompact');
    const motionNode = document.getElementById('kInputMotion');
    if (compactNode) compactNode.checked = !!settings.compactMode;
    if (motionNode) motionNode.checked = !!settings.reduceMotion;
  };

  cards.forEach((card) => {
    card.addEventListener('click', () => {
      const themeValue = card.dataset.themeValue;
      if (window.KairosTheme) {
        window.KairosTheme.applyTheme(themeValue, true);
        syncControlsFromTheme();
      }
    });

    card.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        card.click();
        return;
      }
      const arrows = ['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp'];
      if (!arrows.includes(event.key)) return;
      event.preventDefault();
      const index = cards.indexOf(card);
      const next = event.key === 'ArrowRight' || event.key === 'ArrowDown'
        ? (index + 1) % cards.length
        : (index - 1 + cards.length) % cards.length;
      focusCard(cards[next]);
    });
  });

  if (window.KairosTheme) {
    const compactNode = document.getElementById('kInputCompact');
    const motionNode = document.getElementById('kInputMotion');

    compactNode?.addEventListener('change', (event) => {
      window.KairosTheme.saveSettings({ compactMode: event.target.checked });
    });
    motionNode?.addEventListener('change', (event) => {
      window.KairosTheme.saveSettings({ reduceMotion: event.target.checked });
    });
  }

  syncControlsFromTheme();
  document.addEventListener('kairos:ui-settings', syncControlsFromTheme);

  const appConfig = typeof window.waitForAppConfig === 'function'
    ? await window.waitForAppConfig().catch(() => window.SignoffConfig || {})
    : (window.SignoffConfig || {});
  const me = session?.me || window.KairosIdentity?.me || {};
  const changeSection = document.getElementById('changePasswordSection');
  const changeForm = document.getElementById('changePasswordForm');
  const changeStatus = document.getElementById('changePasswordStatus');
  const newPassword = document.getElementById('newPassword');
  const passwordGuidance = document.getElementById('settingsPasswordGuidance');
  const googleSection = document.getElementById('googleLinkSection');
  const googleDescription = document.getElementById('googleLinkDescription');
  const startGoogleLinkBtn = document.getElementById('startGoogleLinkBtn');
  const googleButton = document.getElementById('googleLinkButton');
  const googleStatus = document.getElementById('googleLinkStatus');

  const localEnabled = appConfig.localAuthEnabled === true;
  changeSection?.classList.toggle('hidden', !(localEnabled && me.has_password));
  googleSection?.classList.toggle('hidden', !localEnabled);

  newPassword?.addEventListener('input', () => {
    const result = window.KairosAuth.passwordGuidance(newPassword.value, appConfig.passwordPolicy || {});
    if (passwordGuidance) {
      passwordGuidance.textContent = result.message;
      passwordGuidance.dataset.tone = result.tone;
    }
  });

  changeForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = changeForm.querySelector('button[type="submit"]');
    const currentPassword = changeForm.elements.current_password.value;
    const nextPassword = changeForm.elements.new_password.value;
    const confirmation = changeForm.elements.confirm_password.value;
    if (!currentPassword || !nextPassword || nextPassword !== confirmation) {
      window.KairosAuth.setStatus(changeStatus, 'Enter the current password and matching new passwords.', 'danger');
      return;
    }
    submit.disabled = true;
    try {
      const result = await window.KairosAuth.post('./api/auth/change_password.php', {
        current_password: currentPassword,
        new_password: nextPassword,
      });
      changeForm.reset();
      window.KairosAuth.setStatus(changeStatus, result.message, 'success');
    } catch (error) {
      window.KairosAuth.setStatus(changeStatus, error.message, 'danger');
    } finally {
      submit.disabled = false;
    }
  });

  if (me.google_linked) {
    if (googleDescription) {
      googleDescription.textContent = 'A Nixor Google account is linked and can be used to sign in. For account security, it cannot currently be removed.';
    }
    if (startGoogleLinkBtn) {
      startGoogleLinkBtn.textContent = 'Google account linked';
      startGoogleLinkBtn.disabled = true;
    }
  } else {
    startGoogleLinkBtn?.addEventListener('click', async () => {
      startGoogleLinkBtn.disabled = true;
      try {
        const linkSession = await window.KairosAuth.post('./api/auth/google_link_start.php', {});
        if (!window.google?.accounts?.id || !appConfig.googleClientId) {
          throw new Error('Google linking is temporarily unavailable.');
        }
        window.KairosAuth.setStatus(googleStatus, 'Choose the Nixor Google account to link.', 'neutral');
        googleButton.classList.remove('hidden');
        googleButton.innerHTML = '';
        google.accounts.id.initialize({
          client_id: appConfig.googleClientId,
          ux_mode: 'popup',
          auto_select: false,
          itp_support: true,
          callback: async (response) => {
            try {
              const result = await window.KairosAuth.post('./api/auth/google_link_complete.php', {
                credential: response.credential,
                state: linkSession.state,
              });
              googleButton.classList.add('hidden');
              startGoogleLinkBtn.textContent = 'Google account linked';
              startGoogleLinkBtn.disabled = true;
              window.KairosAuth.setStatus(googleStatus, result.message, 'success');
            } catch (error) {
              startGoogleLinkBtn.disabled = false;
              window.KairosAuth.setStatus(googleStatus, error.message, 'danger');
            }
          },
        });
        google.accounts.id.renderButton(googleButton, {
          theme: 'outline',
          size: 'large',
          shape: 'rectangular',
          text: 'continue_with',
          logo_alignment: 'left',
        });
      } catch (error) {
        startGoogleLinkBtn.disabled = false;
        window.KairosAuth.setStatus(googleStatus, error.message, 'danger');
      }
    });
  }
});
