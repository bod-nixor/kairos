(function () {
  'use strict';

  const $ = id => document.getElementById(id);
  const LMS = window.KairosLMS;
  const params = new URLSearchParams(location.search);
  const courseId = params.get('course_id') || '';
  const lessonId = params.get('lesson_id') || '';
  let lesson = null;
  let canEdit = false;
  let editor = null;

  function isSafeHttpUrl(value) {
    try {
      const parsed = new URL(String(value || ''));
      return parsed.protocol === 'http:' || parsed.protocol === 'https:';
    } catch (_) {
      return false;
    }
  }

  function sanitizeForRender(html) {
    const template = document.createElement('template');
    template.innerHTML = html || '';
    template.content.querySelectorAll('iframe,script,style,object,embed').forEach(node => node.remove());
    template.content.querySelectorAll('*').forEach((node) => {
      [...node.attributes].forEach((attr) => {
        const n = attr.name.toLowerCase();
        if (n.startsWith('on')) node.removeAttribute(attr.name);
      });
    });
    return template.innerHTML;
  }

  function htmlToEditorBlocks(html) {
    const tmp = document.createElement('div');
    tmp.innerHTML = html || '';
    const blocks = [];
    tmp.childNodes.forEach((node) => {
      if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
        blocks.push({ type: 'paragraph', data: { text: node.textContent.trim() } });
      } else if (node.nodeType === Node.ELEMENT_NODE) {
        const tag = node.tagName.toLowerCase();
        if (/^h[1-6]$/.test(tag)) {
          blocks.push({ type: 'header', data: { text: node.innerHTML, level: Number(tag[1]) } });
        } else if (tag === 'ul' || tag === 'ol') {
          const items = [...node.querySelectorAll('li')].map(li => li.innerHTML);
          blocks.push({ type: 'list', data: { style: tag === 'ol' ? 'ordered' : 'unordered', items } });
        } else if (tag === 'iframe') {
          blocks.push({ type: 'embed', data: { service: 'custom', source: node.getAttribute('src') || '', embed: node.getAttribute('src') || '', width: node.getAttribute('width') || 640, height: node.getAttribute('height') || 360, caption: '' } });
        } else {
          blocks.push({ type: 'paragraph', data: { text: node.innerHTML } });
        }
      }
    });
    return blocks.length ? blocks : [{ type: 'paragraph', data: { text: '' } }];
  }

  function blocksToHtml(data) {
    const blocks = Array.isArray(data?.blocks) ? data.blocks : [];
    return blocks.map((block) => {
      if (block.type === 'header') {
        const level = Math.min(6, Math.max(1, Number(block.data?.level || 2)));
        return `<h${level}>${block.data?.text || ''}</h${level}>`;
      }
      if (block.type === 'list') {
        const style = block.data?.style === 'ordered' ? 'ol' : 'ul';
        const items = Array.isArray(block.data?.items) ? block.data.items : [];
        return `<${style}>${items.map(item => `<li>${item}</li>`).join('')}</${style}>`;
      }
      if (block.type === 'embed') {
        const src = block.data?.embed || block.data?.source || '';
        if (!isSafeHttpUrl(src)) return '';
        return `<iframe src="${src}" width="640" height="360" allow="autoplay; encrypted-media" allowfullscreen></iframe>`;
      }
      return `<p>${block.data?.text || ''}</p>`;
    }).join('');
  }

  async function initEditor() {
    if (!canEdit) return;
    editor = new EditorJS({
      holder: 'lessonEditor',
      autofocus: false,
      inlineToolbar: true,
      tools: {
        header: Header,
        list: List,
        paragraph: Paragraph,
        embed: Embed,
      },
      data: { blocks: htmlToEditorBlocks(lesson?.html_content || '') },
    });
  }

  async function loadLesson() {
    const res = await LMS.api('GET', `./api/lms/lessons.php?lesson_id=${encodeURIComponent(lessonId)}`);
    if (!res.ok) { LMS.toast('Failed to load lesson', 'error'); return; }
    lesson = res.data?.data || res.data;
    $('lessonTitle').textContent = lesson.title || 'Lesson';
    $('lessonSubtitle').textContent = lesson.summary || '';
    $('lessonContent').innerHTML = sanitizeForRender(lesson.html_content || '<p>No lesson content yet.</p>');
    $('backToModules').href = `./modules.html?course_id=${encodeURIComponent(courseId || lesson.course_id)}`;

    if (canEdit) {
      $('editorWrap').classList.remove('hidden');
      await initEditor();
    }
  }

  async function saveLesson() {
    if (!editor) return;
    const data = await editor.save();
    const payload = {
      lesson_id: parseInt(lessonId, 10),
      title: lesson.title,
      summary: lesson.summary || '',
      html_content: blocksToHtml(data),
      position: lesson.position || 0,
      requires_previous: lesson.requires_previous || 0,
    };
    const res = await LMS.api('POST', './api/lms/lessons/update.php', payload);
    if (!res.ok) { LMS.toast(res.data?.error?.message || 'Failed to save lesson', 'error'); return; }
    LMS.toast('Lesson saved', 'success');
    if (editor) {
      await editor.destroy();
      editor = null;
    }
    await loadLesson();
  }

  async function insertSafeLink(label) {
    if (!editor) return;
    const u = prompt(`Enter ${label} URL`);
    if (!u || !isSafeHttpUrl(u)) {
      LMS.toast('Please enter a valid http(s) URL.', 'warning');
      return;
    }
    await editor.blocks.insert('paragraph', { text: `<a href="${u}" target="_blank" rel="noopener noreferrer">${label}</a>` });
  }

  async function insertSafeEmbed() {
    if (!editor) return;
    const u = prompt('Enter video embed URL');
    if (!u || !isSafeHttpUrl(u)) {
      LMS.toast('Please enter a valid http(s) URL.', 'warning');
      return;
    }
    await editor.blocks.insert('embed', { service: 'custom', source: u, embed: u, width: 640, height: 360, caption: '' });
  }

  document.addEventListener('DOMContentLoaded', async () => {
    if (!courseId || !lessonId) {
      LMS.toast('Missing course or lesson id.', 'error');
      setTimeout(() => { window.location.href = courseId ? `./modules.html?course_id=${encodeURIComponent(courseId)}` : '/'; }, 800);
      return;
    }

    const session = await LMS.boot();
    if (!session) return;
    const roles = session.caps?.roles || {};
    canEdit = !!(roles.admin || roles.manager);

    document.querySelectorAll('[data-cmd]').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!editor) return;
        const command = btn.dataset.cmd;
        if (command === 'insertUnorderedList') {
          await editor.blocks.insert('list', { style: 'unordered', items: [''] });
          return;
        }
        if (command === 'insertOrderedList') {
          await editor.blocks.insert('list', { style: 'ordered', items: [''] });
          return;
        }
        await editor.blocks.insert('paragraph', { text: `<${command === 'bold' ? 'strong' : command === 'italic' ? 'em' : 'u'}>Text</${command === 'bold' ? 'strong' : command === 'italic' ? 'em' : 'u'}>` });
      });
    });

    $('addLinkBtn').addEventListener('click', () => insertSafeLink('Open Link'));
    $('addPdfBtn').addEventListener('click', () => insertSafeLink('Open PDF'));
    $('addVideoBtn').addEventListener('click', insertSafeEmbed);
    $('saveLessonBtn').addEventListener('click', saveLesson);

    await loadLesson();
  });
})();
