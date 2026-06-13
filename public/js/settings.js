document.addEventListener('DOMContentLoaded', async () => {
  try {
    if (window.KairosLMS && typeof window.KairosLMS.boot === 'function') {
      await window.KairosLMS.boot();
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
});
