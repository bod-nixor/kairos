(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  const LMS = window.KairosLMS;
  const params = new URLSearchParams(location.search);
  const courseId = params.get('course_id') || '';
  const lessonId = params.get('lesson_id') || '';
  let lesson = null;
  let canEdit = false;

  function cmd(command, value) { document.execCommand(command, false, value || null); }

  async function loadLesson() {
    const res = await LMS.api('GET', `./api/lms/lessons.php?lesson_id=${encodeURIComponent(lessonId)}`);
    if (!res.ok) { LMS.toast('Failed to load lesson', 'error'); return; }
    lesson = res.data?.data || res.data;
    $('lessonTitle').textContent = lesson.title || 'Lesson';
    $('lessonSubtitle').textContent = lesson.summary || '';
    $('lessonContent').innerHTML = lesson.html_content || '<p>No lesson content yet.</p>';
    $('backToModules').href = `./modules.html?course_id=${encodeURIComponent(courseId || lesson.course_id)}`;

    if (canEdit) {
      $('editorWrap').classList.remove('hidden');
      $('lessonEditor').innerHTML = lesson.html_content || '';
    }
  }

  async function saveLesson() {
    const payload = {
      lesson_id: parseInt(lessonId, 10),
      title: lesson.title,
      summary: lesson.summary || '',
      html_content: $('lessonEditor').innerHTML,
      position: lesson.position || 0,
      requires_previous: lesson.requires_previous || 0,
    };
    const res = await LMS.api('POST', './api/lms/lessons/update.php', payload);
    if (!res.ok) { LMS.toast(res.data?.error?.message || 'Failed to save lesson', 'error'); return; }
    LMS.toast('Lesson saved', 'success');
    await loadLesson();
  }

  document.addEventListener('DOMContentLoaded', async () => {
    const session = await LMS.boot();
    if (!session) return;
    const roles = session.caps?.roles || {};
    canEdit = !!(roles.admin || roles.manager);

    document.querySelectorAll('[data-cmd]').forEach(btn => btn.addEventListener('click', () => cmd(btn.dataset.cmd)));
    $('addLinkBtn').addEventListener('click', () => {
      const u = prompt('Enter URL');
      if (u) cmd('createLink', u);
    });
    $('addPdfBtn').addEventListener('click', () => {
      const u = prompt('Enter PDF URL');
      if (u) cmd('insertHTML', `<p><a href="${u}" target="_blank" rel="noopener noreferrer">Open PDF</a></p>`);
    });
    $('addVideoBtn').addEventListener('click', () => {
      const u = prompt('Enter video embed URL');
      if (u) cmd('insertHTML', `<iframe src="${u}" width="640" height="360" allowfullscreen></iframe>`);
    });
    $('saveLessonBtn').addEventListener('click', saveLesson);

    await loadLesson();
  });
})();
