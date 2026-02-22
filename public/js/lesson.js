(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const LMS = window.KairosLMS;
  const params = new URLSearchParams(window.location.search);
  const courseId = params.get('course_id') || '';
  const lessonId = params.get('lesson_id') || '';
  const debugMode = params.get('debug') === '1';

  const state = {
    lesson: null,
    canEdit: false,
    isEditMode: false,
    lastGetResponse: null,
  };

  function show(id) { $(id)?.classList.remove('hidden'); }
  function hide(id) { $(id)?.classList.add('hidden'); }

  function isSafeHttpUrl(value) {
    try {
      const parsed = new URL(String(value || ''));
      return parsed.protocol === 'http:' || parsed.protocol === 'https:';
    } catch (_) {
      return false;
    }
  }

  function escAttr(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function sanitizeForRender(html) {
    const template = document.createElement('template');
    template.innerHTML = html || '';
    template.content.querySelectorAll('script,style,object,embed').forEach((node) => node.remove());
    template.content.querySelectorAll('*').forEach((node) => {
      [...node.attributes].forEach((attr) => {
        const name = attr.name.toLowerCase();
        if (name.startsWith('on')) {
          node.removeAttribute(attr.name);
        }
      });
    });
    return template.innerHTML;
  }

  function renderDebug() {
    if (!debugMode) return;
    const debug = $('lessonDebug');
    if (!debug) return;
    show('lessonDebug');
    const payload = {
      course_id: courseId,
      lesson_id: lessonId,
      can_edit: state.canEdit,
      mode: state.isEditMode ? 'edit' : 'view',
      endpoint: `./api/lms/lessons/get.php?course_id=${encodeURIComponent(courseId)}&lesson_id=${encodeURIComponent(lessonId)}`,
      response_status: state.lastGetResponse?.status,
      response_body: state.lastGetResponse?.data || null,
    };
    debug.textContent = JSON.stringify(payload, null, 2);
  }

  function setStatusPill(isPublished) {
    const pill = $('lessonStatusPill');
    if (!pill) return;
    pill.textContent = isPublished ? 'Published' : 'Draft';
  }

  function applyMode(isEditMode) {
    state.isEditMode = !!isEditMode;
    const editor = $('lessonEditor');
    if (!editor) return;

    const editable = state.canEdit && state.isEditMode;
    editor.setAttribute('contenteditable', editable ? 'true' : 'false');
    editor.style.pointerEvents = editable ? 'auto' : 'none';
    editor.style.opacity = editable ? '1' : '0.75';

    if (editable) {
      editor.focus();
      hide('editModeBtn');
      show('viewModeBtn');
      show('saveDraftBtn');
    } else {
      show('editModeBtn');
      hide('viewModeBtn');
      hide('saveDraftBtn');
    }

    renderDebug();
  }

  function renderLesson() {
    const lesson = state.lesson || {};
    $('lessonTitle').textContent = lesson.title || 'Lesson';
    $('lessonSubtitle').textContent = lesson.summary || '';
    $('backToModules').href = `./modules.html?course_id=${encodeURIComponent(courseId || lesson.course_id || '')}`;

    const html = sanitizeForRender(lesson.html_content || '<p>No lesson content yet.</p>');
    $('lessonContent').innerHTML = html;
    $('lessonEditor').innerHTML = html;

    const isPublished = Number(lesson.published_flag || 0) === 1;
    setStatusPill(isPublished);

    if (state.canEdit) {
      show('editorWrap');
      show('editModeBtn');
      if (isPublished) {
        hide('publishBtn');
        show('unpublishBtn');
      } else {
        show('publishBtn');
        hide('unpublishBtn');
      }
      applyMode(false);
    } else {
      hide('editorWrap');
      hide('editModeBtn');
      hide('viewModeBtn');
      hide('saveDraftBtn');
      hide('publishBtn');
      hide('unpublishBtn');
    }

    renderDebug();
  }

  async function loadLesson() {
    const endpoint = `./api/lms/lessons/get.php?course_id=${encodeURIComponent(courseId)}&lesson_id=${encodeURIComponent(lessonId)}`;
    const res = await LMS.api('GET', endpoint);
    state.lastGetResponse = res;
    if (!res.ok) {
      LMS.toast(res.data?.error?.message || 'Failed to load lesson.', 'error');
      renderDebug();
      return;
    }
    state.lesson = res.data?.data || res.data;
    renderLesson();
  }

  async function saveDraft() {
    if (!state.canEdit) return;
    const editor = $('lessonEditor');
    const payload = {
      course_id: Number(courseId),
      lesson_id: Number(lessonId),
      title: state.lesson?.title || 'Untitled lesson',
      summary: state.lesson?.summary || '',
      html_content: editor?.innerHTML || '',
    };

    const res = await LMS.api('POST', './api/lms/lessons/save.php', payload);
    if (!res.ok) {
      LMS.toast(res.data?.error?.message || 'Failed to save draft.', 'error');
      return;
    }

    LMS.toast('Draft saved.', 'success');
    await loadLesson();
    applyMode(true);
  }

  async function setPublished(published) {
    if (!state.canEdit) return;
    const payload = {
      lesson_id: Number(lessonId),
      published: published ? 1 : 0,
    };
    const res = await LMS.api('POST', './api/lms/lessons/publish.php', payload);
    if (!res.ok) {
      LMS.toast(res.data?.error?.message || 'Failed to update publish status.', 'error');
      return;
    }
    LMS.toast(published ? 'Lesson published.' : 'Lesson moved to draft.', 'success');
    await loadLesson();
  }

  function applyFormat(command) {
    $('lessonEditor')?.focus();
    document.execCommand(command, false);
  }

  function openResourceModal(kind) {
    const modal = $('resourceModal');
    const title = $('resourceModalTitle');
    const resourceTitle = $('resourceTitle');
    const resourceUrl = $('resourceUrl');
    if (!modal || !resourceTitle || !resourceUrl || !title) return;

    title.textContent = kind === 'video' ? 'Embed Video' : 'Insert PDF';
    resourceTitle.value = kind === 'video' ? 'Embedded Video' : 'PDF Resource';
    resourceUrl.value = '';
    modal.dataset.kind = kind;
    modal.showModal();
  }

  async function createResourceAndInsert(event) {
    event.preventDefault();
    if (!state.canEdit) return;

    const modal = $('resourceModal');
    const kind = modal?.dataset.kind || 'file';
    const title = $('resourceTitle')?.value?.trim() || '';
    const url = $('resourceUrl')?.value?.trim() || '';

    if (!title || !isSafeHttpUrl(url)) {
      LMS.toast('Enter a title and valid http(s) URL.', 'warning');
      return;
    }

    const type = kind === 'video' ? 'video' : 'file';
    const res = await LMS.api('POST', './api/lms/resources/create.php', {
      course_id: Number(courseId),
      title,
      type,
      url,
    });

    if (!res.ok) {
      LMS.toast(res.data?.error?.message || 'Resource creation failed.', 'error');
      return;
    }

    const resource = res.data?.data || res.data;
    const resourceId = Number(resource.resource_id || 0);
    if (resourceId <= 0) {
      LMS.toast('Resource was created without a valid id.', 'error');
      return;
    }

    const editor = $('lessonEditor');
    if (!editor) return;

    if (type === 'video') {
      const safeUrl = escAttr(url);
      const snippet = `<p><iframe src="${safeUrl}" width="640" height="360" allow="autoplay; encrypted-media" allowfullscreen></iframe></p>`;
      document.execCommand('insertHTML', false, snippet);
    } else {
      const href = `./resource-viewer.html?course_id=${encodeURIComponent(courseId)}&resource_id=${encodeURIComponent(resourceId)}`;
      const snippet = `<p><a href="${escAttr(href)}" target="_blank" rel="noopener noreferrer">${escAttr(title)}</a></p>`;
      document.execCommand('insertHTML', false, snippet);
    }

    modal?.close();
    await saveDraft();
  }

  function wireToolbar() {
    document.querySelectorAll('[data-cmd]').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (!state.canEdit || !state.isEditMode) return;
        const command = btn.dataset.cmd;
        applyFormat(command);
      });
    });

    $('addLinkBtn')?.addEventListener('click', () => {
      if (!state.canEdit || !state.isEditMode) return;
      const url = window.prompt('Enter link URL');
      if (!isSafeHttpUrl(url)) {
        LMS.toast('Please enter a valid http(s) URL.', 'warning');
        return;
      }
      const safe = escAttr(url);
      document.execCommand('insertHTML', false, `<a href="${safe}" target="_blank" rel="noopener noreferrer">${safe}</a>`);
    });

    $('addPdfBtn')?.addEventListener('click', () => {
      if (!state.canEdit || !state.isEditMode) return;
      openResourceModal('file');
    });

    $('addVideoBtn')?.addEventListener('click', () => {
      if (!state.canEdit || !state.isEditMode) return;
      openResourceModal('video');
    });
  }

  document.addEventListener('DOMContentLoaded', async () => {
    if (!courseId || !lessonId) {
      LMS.toast('Missing course_id or lesson_id.', 'error');
      return;
    }

    const session = await LMS.boot();
    if (!session) return;
    LMS.nav.updateUserBar(session.me);

    const roles = session.caps?.roles || {};
    state.canEdit = !!(roles.admin || roles.manager);

    $('editModeBtn')?.addEventListener('click', () => applyMode(true));
    $('viewModeBtn')?.addEventListener('click', () => applyMode(false));
    $('saveDraftBtn')?.addEventListener('click', saveDraft);
    $('publishBtn')?.addEventListener('click', () => setPublished(true));
    $('unpublishBtn')?.addEventListener('click', () => setPublished(false));
    $('resourceModalCancel')?.addEventListener('click', () => $('resourceModal')?.close());
    $('resourceModalForm')?.addEventListener('submit', createResourceAndInsert);

    wireToolbar();
    await loadLesson();
  });
})();
