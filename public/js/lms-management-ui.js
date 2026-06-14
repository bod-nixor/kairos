/**
 * Shared, accessible management dialogs for LMS assignments and quizzes.
 */
(function (global) {
  'use strict';

  const LMS = global.KairosLMS;
  const FALLBACK_PRESETS = {
    documents: { label: 'Documents', extensions: ['pdf', 'doc', 'docx', 'txt', 'rtf', 'odt'] },
    images: { label: 'Images', extensions: ['jpg', 'jpeg', 'png', 'gif', 'webp'] },
    video: { label: 'Video', extensions: ['mp4', 'mov', 'webm', 'm4v'] },
    audio: { label: 'Audio', extensions: ['mp3', 'wav', 'm4a', 'ogg'] },
    archives: { label: 'Archives', extensions: ['zip', 'rar', '7z', 'tar', 'gz'] },
    code: { label: 'Code', extensions: ['json', 'py', 'java', 'c', 'cpp', 'h', 'sql', 'md'] },
    pdf: { label: 'PDF only', extensions: ['pdf'] },
    spreadsheets: { label: 'Spreadsheets', extensions: ['xls', 'xlsx', 'csv', 'ods'] },
    presentations: { label: 'Presentations', extensions: ['ppt', 'pptx', 'odp'] },
    custom: { label: 'Custom', extensions: [] },
  };
  const FALLBACK_DANGEROUS = new Set([
    'bat', 'cmd', 'com', 'csh', 'dll', 'exe', 'htm', 'html', 'jar', 'js', 'jsx', 'mjs',
    'phtml', 'phar', 'php', 'ps1', 'scr', 'sh', 'svg', 'ts', 'tsx', 'xhtml', 'xml',
  ]);

  function normalizeExtension(value) {
    return String(value || '').trim().toLowerCase().replace(/^\.+/, '');
  }

  function normalizeExtensions(value, policy = {}) {
    const supported = new Set(policy.supported_extensions || []);
    const dangerous = new Set(policy.dangerous_extensions || FALLBACK_DANGEROUS);
    const raw = Array.isArray(value) ? value : String(value || '').split(/[\s,;]+/);
    const extensions = [];
    const errors = [];

    raw.forEach((entry) => {
      const ext = normalizeExtension(entry);
      if (!ext) return;
      if (!/^[a-z0-9]{1,10}$/.test(ext)) {
        errors.push(`.${ext} is not a valid extension.`);
        return;
      }
      if (dangerous.has(ext)) {
        errors.push(`.${ext} is blocked because it can contain active or executable content.`);
        return;
      }
      if (supported.size && !supported.has(ext)) {
        errors.push(`.${ext} is not supported by Kairos storage.`);
        return;
      }
      if (!extensions.includes(ext)) extensions.push(ext);
    });

    return { extensions, errors };
  }

  function normalizePolicy(policy) {
    const presets = policy?.presets && typeof policy.presets === 'object'
      ? policy.presets
      : FALLBACK_PRESETS;
    const normalizedPresets = {};
    Object.entries(presets).forEach(([key, preset]) => {
      const extensions = Array.isArray(preset)
        ? preset
        : (Array.isArray(preset?.extensions) ? preset.extensions : []);
      normalizedPresets[key] = {
        label: preset?.label || key.replace(/(^|_)([a-z])/g, (_, __, letter) => ` ${letter.toUpperCase()}`).trim(),
        extensions: normalizeExtensions(extensions, {
          supported_extensions: policy?.supported_extensions || [],
          dangerous_extensions: policy?.dangerous_extensions || [],
        }).extensions,
      };
    });
    return {
      presets: normalizedPresets,
      supported_extensions: policy?.supported_extensions || Object.values(normalizedPresets).flatMap((item) => item.extensions),
      dangerous_extensions: policy?.dangerous_extensions || Array.from(FALLBACK_DANGEROUS),
    };
  }

  function resolveExtensions(selectedGroups, customExtensions, policy) {
    const normalizedPolicy = normalizePolicy(policy);
    const combined = [];
    selectedGroups.forEach((key) => {
      (normalizedPolicy.presets[key]?.extensions || []).forEach((ext) => combined.push(ext));
    });
    customExtensions.forEach((ext) => combined.push(ext));
    return normalizeExtensions(combined, normalizedPolicy);
  }

  function classifyExtensions(value, policy) {
    const normalizedPolicy = normalizePolicy(policy);
    const normalized = normalizeExtensions(value, normalizedPolicy).extensions;
    const remaining = new Set(normalized);
    const groups = [];
    const entries = Object.entries(normalizedPolicy.presets)
      .sort((a, b) => b[1].extensions.length - a[1].extensions.length);

    entries.forEach(([key, preset]) => {
      if (preset.extensions.length && preset.extensions.every((ext) => remaining.has(ext))) {
        groups.push(key);
        preset.extensions.forEach((ext) => remaining.delete(ext));
      }
    });
    return { groups, custom: Array.from(remaining) };
  }

  function extensionsToAccept(value, policy) {
    return normalizeExtensions(value, normalizePolicy(policy)).extensions.map((ext) => `.${ext}`).join(',');
  }

  function formatExtensions(value, policy) {
    const extensions = normalizeExtensions(value, normalizePolicy(policy)).extensions;
    return extensions.length ? extensions.map((ext) => `.${ext}`).join(', ') : 'Any supported file type';
  }

  function toDateTimeLocal(value) {
    if (!value) return '';
    return String(value).replace(' ', 'T').slice(0, 16);
  }

  function fromDateTimeLocal(value) {
    return value ? String(value).replace('T', ' ') + (String(value).length === 16 ? ':00' : '') : '';
  }

  function createDialog(id, title, subtitle, bodyHtml, saveLabel) {
    document.getElementById(id)?.remove();
    const dialog = document.createElement('dialog');
    dialog.id = id;
    dialog.className = 'k-modal k-management-dialog';
    dialog.setAttribute('aria-labelledby', `${id}Title`);
    dialog.innerHTML = `
      <form class="k-modal__content k-modal__content--lg k-management-form" novalidate>
        <header class="k-management-form__header">
          <div>
            <h2 class="k-modal__title" id="${id}Title">${LMS.escHtml(title)}</h2>
            <p class="k-management-form__subtitle">${LMS.escHtml(subtitle || '')}</p>
          </div>
          <button class="k-modal__close" type="button" data-dialog-cancel aria-label="Close">&times;</button>
        </header>
        <div class="k-management-form__body">${bodyHtml}</div>
        <div class="k-form-error hidden" data-form-error role="alert"></div>
        <footer class="k-modal__footer">
          <button class="btn btn-ghost" type="button" data-dialog-cancel>Cancel</button>
          <button class="btn btn-primary" type="submit" data-dialog-save>${LMS.escHtml(saveLabel)}</button>
        </footer>
      </form>`;
    document.body.appendChild(dialog);
    return dialog;
  }

  function setFieldError(dialog, name, message) {
    const error = dialog.querySelector(`[data-field-error="${name}"]`);
    const field = dialog.querySelector(`[data-field="${name}"]`);
    if (error) {
      error.textContent = message || '';
      error.classList.toggle('hidden', !message);
    }
    if (field) field.setAttribute('aria-invalid', message ? 'true' : 'false');
  }

  function clearErrors(dialog) {
    dialog.querySelectorAll('[data-field-error]').forEach((el) => {
      el.textContent = '';
      el.classList.add('hidden');
    });
    dialog.querySelectorAll('[aria-invalid="true"]').forEach((el) => el.removeAttribute('aria-invalid'));
    const formError = dialog.querySelector('[data-form-error]');
    if (formError) {
      formError.textContent = '';
      formError.classList.add('hidden');
    }
  }

  function showFormError(dialog, message) {
    const formError = dialog.querySelector('[data-form-error]');
    if (!formError) return;
    formError.textContent = message || 'Unable to save. Please review the form and try again.';
    formError.classList.remove('hidden');
  }

  function setSaving(dialog, saving, label) {
    const save = dialog.querySelector('[data-dialog-save]');
    dialog.querySelectorAll('button, input, select, textarea, [contenteditable]').forEach((control) => {
      if ('disabled' in control) control.disabled = !!saving;
      if (control.hasAttribute('contenteditable')) {
        control.setAttribute('contenteditable', saving ? 'false' : 'true');
      }
    });
    if (save) {
      save.disabled = !!saving;
      save.textContent = saving ? 'Saving…' : label;
    }
    dialog.setAttribute('aria-busy', saving ? 'true' : 'false');
  }

  function wireDialog(dialog, onSubmit, saveLabel, focusSelector) {
    const form = dialog.querySelector('form');
    const returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    let settled = false;
    let resolvePromise;
    const promise = new Promise((resolve) => { resolvePromise = resolve; });
    const close = (value = null) => {
      if (settled) return;
      settled = true;
      dialog.close();
      resolvePromise(value);
    };
    dialog.querySelectorAll('[data-dialog-cancel]').forEach((button) => button.addEventListener('click', () => close(null)));
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      close(null);
    });
    dialog.addEventListener('close', () => {
      dialog.remove();
      if (returnFocus?.isConnected) returnFocus.focus();
      if (!settled) {
        settled = true;
        resolvePromise(null);
      }
    }, { once: true });
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearErrors(dialog);
      try {
        const result = await onSubmit(dialog, close);
        if (result?.error) showFormError(dialog, result.error);
      } catch (error) {
        showFormError(dialog, error?.message || 'Unable to save. Please try again.');
      }
    });
    dialog.showModal();
    setTimeout(() => dialog.querySelector(focusSelector || 'input, textarea, button')?.focus(), 0);
    return promise;
  }

  function wireRichTextToolbar(dialog, editorSelector) {
    dialog.querySelectorAll('[data-editor-command]').forEach((button) => {
      button.addEventListener('click', () => {
        const editor = dialog.querySelector(editorSelector);
        if (!editor) return;
        editor.focus();
        document.execCommand(button.dataset.editorCommand, false, button.dataset.editorValue || null);
      });
    });
  }

  function openAssignmentEditor(initial = {}, options = {}) {
    const policy = normalizePolicy(options.policy || initial.upload_policy || {});
    const restored = classifyExtensions(initial.allowed_file_extensions || '', policy);
    const selectedGroups = new Set(restored.groups);
    const customExtensions = new Set(restored.custom);
    const isCreate = options.mode === 'create';
    const dialog = createDialog(
      'lmsAssignmentEditor',
      isCreate ? 'Create assignment' : 'Edit assignment',
      'Set clear instructions, grading details, and upload rules.',
      `
        <section class="k-form-section">
          <div class="k-form-section__heading"><span class="k-form-section__eyebrow">Basics</span><h3>Assignment details</h3></div>
          <label class="k-field-stack"><span class="k-label">Title</span><input class="k-input" data-field="title" value="${LMS.escHtml(initial.title || '')}" maxlength="255" required /><span class="k-field-error hidden" data-field-error="title"></span></label>
          <div class="k-field-stack">
            <span class="k-label">Instructions</span>
            <div class="k-editor-toolbar k-editor-toolbar--surface" role="toolbar" aria-label="Description formatting">
              <button type="button" class="btn btn-ghost btn-sm" data-editor-command="formatBlock" data-editor-value="h2">Heading</button>
              <button type="button" class="btn btn-ghost btn-sm" data-editor-command="bold">Bold</button>
              <button type="button" class="btn btn-ghost btn-sm" data-editor-command="italic">Italic</button>
              <button type="button" class="btn btn-ghost btn-sm" data-editor-command="insertUnorderedList">Bullets</button>
              <button type="button" class="btn btn-ghost btn-sm" data-editor-command="insertOrderedList">Numbered</button>
            </div>
            <div class="k-editor-surface k-editor-surface--compact k-management-editor" data-field="instructions" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Assignment instructions"></div>
            <span class="k-field-hint">Formatting is sanitized before it is displayed to students.</span>
          </div>
          <div class="k-form-grid">
            <label class="k-field-stack"><span class="k-label">Due date and time</span><input class="k-input" data-field="due_at" type="datetime-local" value="${LMS.escHtml(toDateTimeLocal(initial.due_at || ''))}" /></label>
            <label class="k-field-stack"><span class="k-label">Maximum points</span><input class="k-input" data-field="max_points" type="number" min="0.01" step="0.01" value="${LMS.escHtml(String(initial.max_points || 100))}" required /><span class="k-field-error hidden" data-field-error="max_points"></span></label>
          </div>
        </section>
        <section class="k-form-section">
          <div class="k-form-section__heading"><span class="k-form-section__eyebrow">Submissions</span><h3>Allowed file types</h3><p>Select one or more presets, then add supported custom extensions if needed.</p></div>
          <div class="k-preset-grid" data-preset-grid></div>
          <div class="k-custom-extension-row">
            <label class="k-field-stack"><span class="k-label">Custom extensions</span><input class="k-input" data-field="custom_extensions" placeholder="e.g. md, json" autocomplete="off" /><span class="k-field-hint">Leading dots are optional. Active web, script, SVG, and executable formats are blocked.</span></label>
            <button class="btn btn-secondary" type="button" data-add-custom>Add</button>
          </div>
          <span class="k-field-error hidden" data-field-error="custom_extensions"></span>
          <div class="k-resolved-types" aria-live="polite"><span>Resolved upload policy</span><div data-resolved-types></div></div>
          <label class="k-field-stack k-control-sm"><span class="k-label">Maximum file size (MB)</span><input class="k-input" data-field="max_file_mb" type="number" min="1" max="1024" step="1" value="${LMS.escHtml(String(initial.max_file_mb || 50))}" required /><span class="k-field-error hidden" data-field-error="max_file_mb"></span></label>
        </section>
        <section class="k-form-section">
          <div class="k-form-section__heading"><span class="k-form-section__eyebrow">Rubric</span><h3>Grading Rubric</h3><p>Define criteria for grading this assignment. If empty, grading will use raw score input.</p></div>
          <div id="editorRubricList" class="k-panel-gap"></div>
          <button class="btn btn-secondary mt-8" type="button" id="editorAddRubricBtn">+ Add Criterion</button>
        </section>`,
      isCreate ? 'Create assignment' : 'Save changes',
    );
    const editor = dialog.querySelector('[data-field="instructions"]');
    editor.innerHTML = LMS.sanitizeForRender(initial.instructions || initial.description || '');
    wireRichTextToolbar(dialog, '[data-field="instructions"]');

    const presetGrid = dialog.querySelector('[data-preset-grid]');
    const resolved = dialog.querySelector('[data-resolved-types]');
    const customInput = dialog.querySelector('[data-field="custom_extensions"]');
    const rubricContainer = dialog.querySelector('#editorRubricList');
    const addRubricBtn = dialog.querySelector('#editorAddRubricBtn');
    let rubricData = Array.isArray(initial.rubric) ? [...initial.rubric] : [];

    const renderRubricItems = () => {
      if (!rubricContainer) return;
      rubricContainer.innerHTML = rubricData.map((item, index) => `
        <div class="k-form-grid mt-8" data-rubric-index="${index}" style="grid-template-columns: 1fr 100px auto; align-items: end; gap: 8px;">
          <label class="k-field-stack" style="margin: 0;">
            <span class="k-label small">Criterion Name</span>
            <input class="k-input" type="text" data-rubric-field="criterion" value="${LMS.escHtml(item.criterion || '')}" placeholder="e.g. Correctness" required />
          </label>
          <label class="k-field-stack" style="margin: 0;">
            <span class="k-label small">Max Points</span>
            <input class="k-input" type="number" data-rubric-field="max_pts" min="0.1" step="0.1" value="${LMS.escHtml(String(item.max_pts || item.max_points || 10))}" required />
          </label>
          <button class="btn btn-ghost btn-sm btn-danger" type="button" data-remove-rubric-index="${index}" style="margin-bottom: 4px; border: 1px solid var(--k-border-color);">✕</button>
        </div>
      `).join('');
    };

    if (rubricContainer && addRubricBtn) {
      renderRubricItems();
      addRubricBtn.addEventListener('click', () => {
        rubricData.push({ criterion: '', max_pts: 10 });
        renderRubricItems();
      });
      rubricContainer.addEventListener('click', (event) => {
        const removeBtn = event.target.closest('[data-remove-rubric-index]');
        if (removeBtn) {
          const index = Number(removeBtn.dataset.removeRubricIndex);
          rubricData.splice(index, 1);
          renderRubricItems();
        }
      });
      rubricContainer.addEventListener('input', (event) => {
        const input = event.target.closest('[data-rubric-field]');
        if (input) {
          const grid = input.closest('[data-rubric-index]');
          const index = Number(grid.dataset.rubricIndex);
          const field = input.dataset.rubricField;
          if (field === 'criterion') {
            rubricData[index].criterion = input.value;
          } else if (field === 'max_pts') {
            rubricData[index].max_pts = Number(input.value) || 0;
            rubricData[index].max_points = Number(input.value) || 0;
          }
        }
      });
    }

    const renderPolicy = () => {
      presetGrid.innerHTML = Object.entries(policy.presets).map(([key, preset]) => {
        const isCustom = key === 'custom';
        const isSelected = isCustom ? customExtensions.size > 0 : selectedGroups.has(key);
        return `
        <button class="k-preset-chip${isSelected ? ' is-selected' : ''}" type="button" ${isCustom ? 'data-custom-preset' : `data-preset="${LMS.escHtml(key)}"`} aria-pressed="${isSelected ? 'true' : 'false'}">
          <strong>${LMS.escHtml(preset.label)}</strong><span>${LMS.escHtml(preset.extensions.length ? preset.extensions.map((ext) => `.${ext}`).join(' ') : 'Add supported extensions')}</span>
        </button>`;
      }).join('');
      presetGrid.querySelectorAll('[data-preset]').forEach((button) => {
        button.addEventListener('click', () => {
          const key = button.dataset.preset;
          if (selectedGroups.has(key)) selectedGroups.delete(key);
          else selectedGroups.add(key);
          renderPolicy();
        });
      });
      presetGrid.querySelector('[data-custom-preset]')?.addEventListener('click', () => customInput.focus());
      const current = resolveExtensions(selectedGroups, customExtensions, policy).extensions;
      resolved.innerHTML = current.length
        ? current.map((ext) => `<span class="k-extension-token">.${LMS.escHtml(ext)}${customExtensions.has(ext) ? `<button type="button" data-remove-custom="${LMS.escHtml(ext)}" aria-label="Remove custom extension .${LMS.escHtml(ext)}">&times;</button>` : ''}</span>`).join('')
        : '<span class="k-text-muted">Any supported file type</span>';
      resolved.querySelectorAll('[data-remove-custom]').forEach((button) => button.addEventListener('click', () => {
        customExtensions.delete(button.dataset.removeCustom);
        renderPolicy();
      }));
    };
    const addCustom = () => {
      const result = normalizeExtensions(customInput.value, policy);
      if (result.errors.length) {
        setFieldError(dialog, 'custom_extensions', result.errors.join(' '));
        return;
      }
      result.extensions.forEach((ext) => customExtensions.add(ext));
      customInput.value = '';
      setFieldError(dialog, 'custom_extensions', '');
      renderPolicy();
    };
    dialog.querySelector('[data-add-custom]').addEventListener('click', addCustom);
    customInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        addCustom();
      }
    });
    renderPolicy();

    return wireDialog(dialog, async (currentDialog, close) => {
      if (customInput.value.trim()) addCustom();
      const title = currentDialog.querySelector('[data-field="title"]').value.trim();
      const points = Number(currentDialog.querySelector('[data-field="max_points"]').value);
      const maxFileMb = Number(currentDialog.querySelector('[data-field="max_file_mb"]').value);
      const resolvedPolicy = resolveExtensions(selectedGroups, customExtensions, policy);
      let invalid = false;
      if (!title) { setFieldError(currentDialog, 'title', 'Enter an assignment title.'); invalid = true; }
      if (!Number.isFinite(points) || points <= 0) { setFieldError(currentDialog, 'max_points', 'Points must be greater than zero.'); invalid = true; }
      if (!Number.isInteger(maxFileMb) || maxFileMb < 1 || maxFileMb > 1024) { setFieldError(currentDialog, 'max_file_mb', 'Use a whole number from 1 to 1024.'); invalid = true; }
      if (resolvedPolicy.errors.length) { setFieldError(currentDialog, 'custom_extensions', resolvedPolicy.errors.join(' ')); invalid = true; }
      if (invalid) return { error: 'Review the highlighted fields.' };

      let validRubric = [];
      if (rubricContainer) {
        rubricContainer.querySelectorAll('[data-rubric-index]').forEach((grid) => {
          const crit = grid.querySelector('[data-rubric-field="criterion"]').value.trim();
          const pts = Number(grid.querySelector('[data-rubric-field="max_pts"]').value);
          if (crit && Number.isFinite(pts) && pts > 0) {
            validRubric.push({ criterion: crit, max_pts: pts, max_points: pts });
          }
        });
      }

      const payload = {
        title,
        instructions: LMS.sanitizeForRender(editor.innerHTML || '').trim(),
        due_at: fromDateTimeLocal(currentDialog.querySelector('[data-field="due_at"]').value),
        max_points: points,
        allowed_file_extensions: resolvedPolicy.extensions.join(','),
        max_file_mb: maxFileMb,
        rubric: validRubric,
      };
      setSaving(currentDialog, true, isCreate ? 'Create assignment' : 'Save changes');
      try {
        const result = options.onSubmit ? await options.onSubmit(payload) : { ok: true };
        if (result === false || result?.ok === false) {
          return { error: result?.error || 'The assignment could not be saved.' };
        }
        close(payload);
        return null;
      } finally {
        if (currentDialog.isConnected) setSaving(currentDialog, false, isCreate ? 'Create assignment' : 'Save changes');
      }
    }, isCreate ? 'Create assignment' : 'Save changes', '[data-field="title"]');
  }

  function openQuizEditor(initial = {}, options = {}) {
    const isCreate = options.mode === 'create';
    const dialog = createDialog(
      'lmsQuizEditor',
      isCreate ? 'Create quiz' : 'Edit quiz',
      'Define the quiz window, attempt rules, and student-facing instructions.',
      `
        <section class="k-form-section">
          <div class="k-form-section__heading"><span class="k-form-section__eyebrow">Basics</span><h3>Quiz details</h3></div>
          <label class="k-field-stack"><span class="k-label">Title</span><input class="k-input" data-field="title" maxlength="255" value="${LMS.escHtml(initial.title || '')}" required /><span class="k-field-error hidden" data-field-error="title"></span></label>
          <label class="k-field-stack"><span class="k-label">Instructions</span><textarea class="k-textarea" data-field="instructions" rows="5" placeholder="Tell students what to expect.">${LMS.escHtml(LMS.richTextToPlainText(initial.instructions || initial.description || ''))}</textarea></label>
          <div class="k-form-grid">
            <label class="k-field-stack"><span class="k-label">Available from</span><input class="k-input" data-field="available_from" type="datetime-local" value="${LMS.escHtml(toDateTimeLocal(initial.available_from || ''))}" /></label>
            <label class="k-field-stack"><span class="k-label">Due date</span><input class="k-input" data-field="due_at" type="datetime-local" value="${LMS.escHtml(toDateTimeLocal(initial.due_at || ''))}" /></label>
            <label class="k-field-stack"><span class="k-label">Time limit (minutes)</span><input class="k-input" data-field="time_limit_minutes" type="number" min="0" max="1440" value="${LMS.escHtml(String(initial.time_limit_minutes || initial.time_limit_min || ''))}" placeholder="No limit" /><span class="k-field-error hidden" data-field-error="time_limit_minutes"></span></label>
            <label class="k-field-stack"><span class="k-label">Maximum attempts</span><input class="k-input" data-field="max_attempts" type="number" min="1" max="100" value="${LMS.escHtml(String(initial.max_attempts || 1))}" required /><span class="k-field-error hidden" data-field-error="max_attempts"></span></label>
          </div>
        </section>`,
      isCreate ? 'Create quiz' : 'Save changes',
    );
    return wireDialog(dialog, async (currentDialog, close) => {
      const title = currentDialog.querySelector('[data-field="title"]').value.trim();
      const timeRaw = currentDialog.querySelector('[data-field="time_limit_minutes"]').value.trim();
      const attempts = Number(currentDialog.querySelector('[data-field="max_attempts"]').value);
      const timeLimit = timeRaw === '' ? null : Number(timeRaw);
      let invalid = false;
      if (!title) { setFieldError(currentDialog, 'title', 'Enter a quiz title.'); invalid = true; }
      if (timeLimit !== null && (!Number.isInteger(timeLimit) || timeLimit < 1 || timeLimit > 1440)) { setFieldError(currentDialog, 'time_limit_minutes', 'Use 1 to 1440 minutes, or leave blank.'); invalid = true; }
      if (!Number.isInteger(attempts) || attempts < 1 || attempts > 100) { setFieldError(currentDialog, 'max_attempts', 'Use a whole number from 1 to 100.'); invalid = true; }
      if (invalid) return { error: 'Review the highlighted fields.' };
      const payload = {
        title,
        instructions: currentDialog.querySelector('[data-field="instructions"]').value.trim(),
        available_from: fromDateTimeLocal(currentDialog.querySelector('[data-field="available_from"]').value),
        due_at: fromDateTimeLocal(currentDialog.querySelector('[data-field="due_at"]').value),
        time_limit_minutes: timeLimit,
        max_attempts: attempts,
        status: initial.status || 'draft',
      };
      setSaving(currentDialog, true, isCreate ? 'Create quiz' : 'Save changes');
      try {
        const result = options.onSubmit ? await options.onSubmit(payload) : { ok: true };
        if (result === false || result?.ok === false) return { error: result?.error || 'The quiz could not be saved.' };
        close(payload);
        return null;
      } finally {
        if (currentDialog.isConnected) setSaving(currentDialog, false, isCreate ? 'Create quiz' : 'Save changes');
      }
    }, isCreate ? 'Create quiz' : 'Save changes', '[data-field="title"]');
  }

  function answerSeedToInput(value) {
    const values = Array.isArray(value) ? value : [value];
    return values.filter(Boolean).map((entry) => {
      const match = String(entry).match(/^opt_(\d+)$/);
      return match ? match[1] : String(entry);
    }).join(', ');
  }

  function openQuestionEditor(initial = {}, options = {}) {
    const editing = !!initial.question_id || options.mode === 'edit';
    const optionLines = Array.isArray(initial.options)
      ? initial.options.map((option) => option.text || option.label || option.value || '').filter(Boolean).join('\n')
      : (initial.options_raw || '');
    const dialog = createDialog(
      'lmsQuestionEditor',
      editing ? 'Edit question' : 'Add question',
      'Keep the prompt focused and make scoring rules explicit.',
      `
        <section class="k-form-section">
          <label class="k-field-stack"><span class="k-label">Prompt</span><textarea class="k-textarea" data-field="prompt" rows="4" required>${LMS.escHtml(initial.prompt || '')}</textarea><span class="k-field-error hidden" data-field-error="prompt"></span></label>
          <div class="k-form-grid">
            <label class="k-field-stack"><span class="k-label">Question type</span><select class="k-select" data-field="question_type">
              <option value="mcq">Multiple choice</option><option value="multiple_select">Select all that apply</option><option value="true_false">True / False</option><option value="short_answer">Short answer</option><option value="long_answer">Long answer</option>
            </select></label>
            <label class="k-field-stack"><span class="k-label">Points</span><input class="k-input" data-field="points" type="number" min="0.01" step="0.01" value="${LMS.escHtml(String(initial.points || 1))}" /><span class="k-field-error hidden" data-field-error="points"></span></label>
          </div>
          <div data-choice-fields>
            <label class="k-field-stack"><span class="k-label">Answer options</span><textarea class="k-textarea" data-field="options" rows="5" placeholder="One option per line">${LMS.escHtml(optionLines)}</textarea><span class="k-field-error hidden" data-field-error="options"></span></label>
            <label class="k-field-stack"><span class="k-label">Correct option number</span><input class="k-input" data-field="answer" value="${LMS.escHtml(answerSeedToInput(initial.answer_key ?? initial.answer_raw ?? ''))}" placeholder="e.g. 2 or 1, 3" /><span class="k-field-hint" data-answer-hint>Use the option number shown by its line order.</span><span class="k-field-error hidden" data-field-error="answer"></span></label>
          </div>
          <label class="k-inline-checkbox k-question-required"><input data-field="is_required" type="checkbox" ${initial.is_required ? 'checked' : ''} /><span><strong>Required question</strong><small>Students must answer before submitting.</small></span></label>
        </section>`,
      editing ? 'Save question' : 'Add question',
    );
    const typeField = dialog.querySelector('[data-field="question_type"]');
    typeField.value = initial.question_type === 'multi_select' ? 'multiple_select' : (initial.question_type || 'mcq');
    const syncType = () => {
      const choiceFields = dialog.querySelector('[data-choice-fields]');
      const answer = dialog.querySelector('[data-field="answer"]');
      const hint = dialog.querySelector('[data-answer-hint]');
      const type = typeField.value;
      const needsOptions = ['mcq', 'multiple_select'].includes(type);
      choiceFields.classList.toggle('is-text-question', !needsOptions && type !== 'true_false');
      dialog.querySelector('[data-field="options"]').closest('label').classList.toggle('hidden', !needsOptions);
      answer.closest('label').classList.toggle('hidden', ['short_answer', 'long_answer'].includes(type));
      if (type === 'true_false') {
        answer.placeholder = 'true or false';
        hint.textContent = 'Enter true or false.';
      } else {
        answer.placeholder = type === 'multiple_select' ? 'e.g. 1, 3' : 'e.g. 2';
        hint.textContent = 'Use the option number shown by its line order.';
      }
    };
    typeField.addEventListener('change', syncType);
    syncType();

    return wireDialog(dialog, async (currentDialog, close) => {
      const prompt = currentDialog.querySelector('[data-field="prompt"]').value.trim();
      const type = typeField.value;
      const points = Number(currentDialog.querySelector('[data-field="points"]').value);
      const optionTexts = currentDialog.querySelector('[data-field="options"]').value.split(/\r?\n/).map((value) => value.trim()).filter(Boolean);
      const answerRaw = currentDialog.querySelector('[data-field="answer"]').value.trim().toLowerCase();
      let invalid = false;
      if (!prompt) { setFieldError(currentDialog, 'prompt', 'Enter the question prompt.'); invalid = true; }
      if (!Number.isFinite(points) || points <= 0) { setFieldError(currentDialog, 'points', 'Points must be greater than zero.'); invalid = true; }
      if (['mcq', 'multiple_select'].includes(type) && optionTexts.length < 2) { setFieldError(currentDialog, 'options', 'Add at least two answer options.'); invalid = true; }

      let answerKey = null;
      if (type === 'true_false') {
        if (!['true', 'false'].includes(answerRaw)) { setFieldError(currentDialog, 'answer', 'Enter true or false.'); invalid = true; }
        else answerKey = answerRaw;
      } else if (['mcq', 'multiple_select'].includes(type)) {
        const indexes = answerRaw.split(',').map((value) => Number(value.trim())).filter((value) => Number.isInteger(value));
        if (!indexes.length || indexes.some((value) => value < 1 || value > optionTexts.length)) {
          setFieldError(currentDialog, 'answer', 'Enter valid option numbers.');
          invalid = true;
        } else {
          const values = indexes.map((value) => `opt_${value}`);
          answerKey = type === 'multiple_select' ? Array.from(new Set(values)) : values[0];
        }
      }
      if (invalid) return { error: 'Review the highlighted fields.' };

      const payload = {
        prompt,
        question_type: type,
        points,
        options: optionTexts.map((text, index) => ({ value: `opt_${index + 1}`, text })),
        answer_key: answerKey,
        is_required: currentDialog.querySelector('[data-field="is_required"]').checked ? 1 : 0,
      };
      setSaving(currentDialog, true, editing ? 'Save question' : 'Add question');
      try {
        const result = options.onSubmit ? await options.onSubmit(payload) : { ok: true };
        if (result === false || result?.ok === false) return { error: result?.error || 'The question could not be saved.' };
        close(payload);
        return null;
      } finally {
        if (currentDialog.isConnected) setSaving(currentDialog, false, editing ? 'Save question' : 'Add question');
      }
    }, editing ? 'Save question' : 'Add question', '[data-field="prompt"]');
  }

  global.KairosLMSManagement = {
    FALLBACK_PRESETS,
    normalizeExtension,
    normalizeExtensions,
    normalizePolicy,
    resolveExtensions,
    classifyExtensions,
    extensionsToAccept,
    formatExtensions,
    openAssignmentEditor,
    openQuizEditor,
    openQuestionEditor,
  };
})(typeof window !== 'undefined' ? window : this);
