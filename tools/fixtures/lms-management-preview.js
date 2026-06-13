(function () {
  'use strict';

  const LMS = window.KairosLMS;
  const Management = window.KairosLMSManagement;
  const previewParams = new URLSearchParams(window.location.search);
  const previewRole = previewParams.get('role') || 'manager';
  const previewAction = previewParams.get('action') || '';
  const previewSubmit = async () => {
    if (previewAction === 'save') {
      await new Promise(resolve => setTimeout(resolve, 5000));
    }
    return { ok: true };
  };
  const richDescription = `
    <h2>Build and explain your solution</h2>
    <p>Complete the practical exercise and submit one supported file.</p>
    <ul><li>Use clear names.</li><li>Include a short reflection.</li></ul>
    <script>window.previewUnsafe = true;</script>
    <iframe src="https://example.com/unsafe"></iframe>`;
  const policy = {
    presets: Management.FALLBACK_PRESETS,
    supported_extensions: Object.values(Management.FALLBACK_PRESETS).flatMap(item => item.extensions),
    dangerous_extensions: ['svg', 'html', 'js', 'php', 'exe'],
  };

  const capabilities = {
    view_course: true,
    manage_course: previewRole === 'manager' || previewRole === 'admin',
    grade_course: previewRole === 'ta' || previewRole === 'manager' || previewRole === 'admin',
  };
  LMS.nav.setCourseContext(101, 'Computer Science', { capabilities });
  LMS.nav.setActive('assignments');
  document.documentElement.dataset.theme = previewParams.get('theme') || 'light';
  document.getElementById('previewExcerpt').textContent = LMS.richTextExcerpt(richDescription, 150);
  document.getElementById('previewDescription').innerHTML = LMS.sanitizeForRender(richDescription);

  document.querySelectorAll('[data-preview-theme]').forEach(button => {
    button.addEventListener('click', () => {
      document.documentElement.dataset.theme = button.dataset.previewTheme;
    });
  });

  document.getElementById('previewAssignmentBtn').addEventListener('click', () => {
    Management.openAssignmentEditor({
      title: 'Practical Exercise 1.4',
      instructions: richDescription,
      due_at: '2026-06-18 15:30:00',
      max_points: 100,
      allowed_file_extensions: 'pdf,doc,docx,txt,rtf,odt,json',
      max_file_mb: 25,
      upload_policy: policy,
    }, { mode: 'edit', policy, onSubmit: previewSubmit });
  });

  document.getElementById('previewQuizBtn').addEventListener('click', () => {
    Management.openQuizEditor({
      title: 'Data Structures Checkpoint',
      instructions: 'Answer each question before the deadline.',
      available_from: '2026-06-15 09:00:00',
      due_at: '2026-06-20 15:30:00',
      time_limit_minutes: 30,
      max_attempts: 2,
      status: 'draft',
    }, { mode: 'edit', onSubmit: previewSubmit });
  });

  const requestedDialog = previewParams.get('dialog');
  let dialogTrigger = null;
  if (requestedDialog === 'assignment') {
    dialogTrigger = document.getElementById('previewAssignmentBtn');
  } else if (requestedDialog === 'quiz') {
    dialogTrigger = document.getElementById('previewQuizBtn');
  }
  if (dialogTrigger) {
    dialogTrigger.focus();
    dialogTrigger.click();
  }

  requestAnimationFrame(() => {
    const openDialog = document.querySelector('dialog[open]');
    document.body.dataset.horizontalOverflow = String(
      document.documentElement.scrollWidth > document.documentElement.clientWidth
    );
    document.body.dataset.modalHorizontalOverflow = String(
      !!openDialog && openDialog.scrollWidth > openDialog.clientWidth
    );
    document.body.dataset.unsafeDescriptionNodes = String(
      document.querySelectorAll('#previewDescription script, #previewDescription iframe, #previewDescription [onclick]').length
    );
    document.body.dataset.excerptContainsMarkup = String(
      /[<>]/.test(document.getElementById('previewExcerpt').textContent || '')
    );
    document.body.dataset.navItems = Array.from(document.querySelectorAll('#kNavCourse [data-nav-key]'))
      .map(item => item.dataset.navKey)
      .join(',');
    const dialog = document.querySelector('dialog[open]');
    document.body.dataset.focusInsideDialog = String(!!dialog && dialog.contains(document.activeElement));
    document.body.dataset.selectedPresets = Array.from(document.querySelectorAll('.k-preset-chip.is-selected strong'))
      .map(item => item.textContent)
      .join(',');
  });

  if (previewParams.get('focus') === 'uploads') {
    setTimeout(() => {
      const modalBody = document.querySelector('.k-management-form__body');
      const sections = document.querySelectorAll('.k-management-form__body .k-form-section');
      if (sections.length > 1) sections[0].classList.add('hidden');
      if (modalBody) modalBody.scrollTop = 0;
    }, 50);
  }

  if (previewAction === 'save') {
    setTimeout(() => {
      document.querySelector('dialog[open] form')?.requestSubmit();
      setTimeout(() => {
        const saveButton = document.querySelector('[data-dialog-save]');
        document.body.dataset.saveDisabled = String(!!saveButton?.disabled);
        document.body.dataset.dialogBusy = document.querySelector('dialog[open]')?.getAttribute('aria-busy') || '';
      }, 20);
    }, 20);
  } else if (previewAction === 'escape') {
    setTimeout(() => {
      const dialog = document.querySelector('dialog[open]');
      dialog?.dispatchEvent(new Event('cancel', { cancelable: true }));
      setTimeout(() => {
        document.body.dataset.dialogOpenAfterEscape = String(!!document.querySelector('dialog[open]'));
        document.body.dataset.restoredFocus = document.activeElement?.id || '';
      }, 20);
    }, 20);
  }
})();
