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

    const html = LMS.sanitizeForRender(lesson.html_content || '<p>No lesson content yet.</p>');
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

  function applyFormat(command, value) {
    $('lessonEditor')?.focus();
    document.execCommand(command, false, value || null);
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

    const type = kind === 'video' ? 'video' : 'pdf';
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
      const embedUrl = LMS.toYoutubeEmbedUrl(url);
      if (embedUrl) {
        const safeUrl = escAttr(embedUrl);
        const fallback = escAttr(url);
        const snippet = `<div class="k-embed-16x9"><iframe src="${safeUrl}" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen referrerpolicy="no-referrer"></iframe></div><p><a href="${fallback}" target="_blank" rel="noopener noreferrer">Open video in new tab ↗</a></p>`;
        document.execCommand('insertHTML', false, snippet);
      } else {
        const viewerHref = `./resource-viewer.html?course_id=${encodeURIComponent(courseId)}&resource_id=${encodeURIComponent(resourceId)}`;
        document.execCommand('insertHTML', false, `<p><a class="k-resource-link" href="${escAttr(viewerHref)}" target="_blank" rel="noopener noreferrer">${escAttr(title)}</a></p>`);
      }
    } else {
      const href = `./resource-viewer.html?course_id=${encodeURIComponent(courseId)}&resource_id=${encodeURIComponent(resourceId)}`;
      const snippet = `<p><a class="k-resource-link" href="${escAttr(href)}" target="_blank" rel="noopener noreferrer">${escAttr(title)}</a></p>`;
      document.execCommand('insertHTML', false, snippet);
    }

    modal?.close();
    await saveDraft();
  }


  function ensureLinkModal() {
    let modal = $('lessonLinkModal');
    if (modal) return modal;
    modal = document.createElement('dialog');
    modal.id = 'lessonLinkModal';
    modal.className = 'k-modal';
    modal.innerHTML = `
      <form method="dialog" class="k-modal__content" id="lessonLinkForm" style="max-width:520px">
        <h3 style="margin:0 0 12px">Insert link</h3>
        <label class="k-field" style="display:grid;gap:6px;margin-bottom:10px">
          <span>URL</span>
          <input id="lessonLinkUrl" type="url" required placeholder="https://example.com" />
        </label>
        <label class="k-field" style="display:grid;gap:6px;margin-bottom:12px">
          <span>Anchor text</span>
          <input id="lessonLinkText" type="text" placeholder="Link text" />
        </label>
        <div class="k-modal__footer" style="display:flex;justify-content:flex-end;gap:8px">
          <button class="btn btn-ghost" type="button" id="lessonLinkCancel">Cancel</button>
          <button class="btn btn-primary" type="submit">Insert link</button>
        </div>
      </form>`;
    document.body.appendChild(modal);
    $('lessonLinkCancel')?.addEventListener('click', () => modal.close());
    return modal;
  }

  function openLinkModal(initialText) {
    const modal = ensureLinkModal();
    const form = $('lessonLinkForm');
    const urlInput = $('lessonLinkUrl');
    const textInput = $('lessonLinkText');
    if (!form || !urlInput || !textInput) return Promise.resolve(null);
    urlInput.value = '';
    textInput.value = initialText || '';
    modal.showModal();
    return new Promise((resolve) => {
      const closeHandler = () => {
        form.removeEventListener('submit', submitHandler);
        modal.removeEventListener('close', closeHandler);
        resolve(null);
      };
      const submitHandler = (event) => {
        event.preventDefault();
        const url = urlInput.value.trim();
        const text = textInput.value.trim();
        if (!isSafeHttpUrl(url)) {
          LMS.toast('Please enter a valid http(s) URL.', 'warning');
          return;
        }
        form.removeEventListener('submit', submitHandler);
        modal.removeEventListener('close', closeHandler);
        modal.close();
        resolve({ url, text });
      };
      form.addEventListener('submit', submitHandler);
      modal.addEventListener('close', closeHandler);
      setTimeout(() => urlInput.focus(), 0);
    });
  }



  function markdownToHtml(markdown) {
    const lines = String(markdown || '').split(/\r?\n/);
    const htmlLines = lines.map((line) => {
      const trimmed = line.trim();
      if (!trimmed) return '<p><br></p>';
      if (trimmed.startsWith('### ')) return `<h3>${LMS.escHtml(trimmed.slice(4))}</h3>`;
      if (trimmed.startsWith('## ')) return `<h2>${LMS.escHtml(trimmed.slice(3))}</h2>`;
      if (trimmed.startsWith('# ')) return `<h1>${LMS.escHtml(trimmed.slice(2))}</h1>`;
      if (trimmed.startsWith('- ') || trimmed.startsWith('* ')) return `<li>${LMS.escHtml(trimmed.slice(2))}</li>`;
      if (/^\d+\.\s+/.test(trimmed)) return `<li data-ordered="1">${LMS.escHtml(trimmed.replace(/^\d+\.\s+/, ''))}</li>`;
      return `<p>${LMS.escHtml(trimmed)}</p>`;
    });

    let html = htmlLines.join('');
    html = html.replace(/(<li data-ordered="1">[\s\S]*?<\/li>)+/g, (chunk) => `<ol>${chunk.replace(/ data-ordered="1"/g, '')}</ol>`);
    html = html.replace(/(<li(?![^>]*data-ordered)[^>]*>[\s\S]*?<\/li>)+/g, (chunk) => `<ul>${chunk}</ul>`);
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
      if (tag === 'u') return `<u>${text}</u>`;
      if (tag === 'a') return `[${text}](${node.getAttribute('href') || ''})`;
      if (tag === 'li') return `- ${text}\n`;
      if (tag === 'ul' || tag === 'ol') return `${Array.from(node.children).map(mapNode).join('')}\n`;
      if (tag === 'br') return '\n';
      if (tag === 'p' || tag === 'div') return `${text}\n\n`;
      return text;
    };

    return Array.from(container.childNodes).map(mapNode).join('').replace(/\n{3,}/g, '\n\n').trim();
  }

  function wireToolbar() {
    document.querySelectorAll('[data-cmd]').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (!state.canEdit || !state.isEditMode) return;
        const command = btn.dataset.cmd;
        const value = btn.dataset.cmdValue || null;
        applyFormat(command, value);
      });
    });

    $('addLinkBtn')?.addEventListener('click', async () => {
      if (!state.canEdit || !state.isEditMode) return;
      const editor = $('lessonEditor');
      editor?.focus();
      const selection = document.getSelection();
      const hasSelection = !!selection && selection.rangeCount > 0 && !selection.getRangeAt(0).collapsed;
      const savedRange = hasSelection ? selection.getRangeAt(0).cloneRange() : null;
      const selectedText = hasSelection ? selection.toString() : '';
      const linkInput = await openLinkModal(selectedText);
      if (!linkInput) return;

      const restoreSelection = () => {
        if (!savedRange || !editor) return;
        const sel = document.getSelection();
        if (!sel) return;
        sel.removeAllRanges();
        sel.addRange(savedRange);
      };
      restoreSelection();

      if (savedRange) {
        document.execCommand('createLink', false, linkInput.url);
      } else {
        const text = linkInput.text || linkInput.url;
        document.execCommand('insertHTML', false, `<a href="${escAttr(linkInput.url)}">${escAttr(text)}</a>`);
      }

      editor?.querySelectorAll('a[href]').forEach((anchor) => {
        anchor.setAttribute('target', '_blank');
        anchor.setAttribute('rel', 'noopener noreferrer');
      });
    });



    $('copyMarkdownBtn')?.addEventListener('click', async () => {
      const editor = $('lessonEditor');
      if (!editor) return;
      const markdown = htmlToMarkdown(editor.innerHTML);
      try {
        await navigator.clipboard.writeText(markdown);
        LMS.toast('Markdown copied to clipboard.', 'success');
      } catch (_) {
        LMS.toast('Unable to copy markdown.', 'error');
      }
    });

    $('lessonEditor')?.addEventListener('paste', (event) => {
      if (!state.canEdit || !state.isEditMode) return;
      const text = event.clipboardData?.getData('text/plain') || '';
      if (!text || !/[#*\-\[\]]/.test(text)) return;
      event.preventDefault();
      const html = markdownToHtml(text);
      document.execCommand('insertHTML', false, html);
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
