document.addEventListener('DOMContentLoaded', () => {
  window.showKairosView = (view) => {
    if (view === 'dashboard' || view === 'courses') {
      if (typeof renderCourseCards === 'function') renderCourseCards();
      return;
    }
    if (view === 'rooms') {
      if (typeof showView === 'function') showView('viewRooms');
      return;
    }
    const mapped = `view${view.charAt(0).toUpperCase()}${view.slice(1)}`;
    if (typeof showView === 'function') showView(mapped);
  };

  const navButtons = document.querySelectorAll('.k-nav-item[data-view]');
  navButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const view = button.dataset.view;
      navButtons.forEach((node) => {
        node.classList.remove('is-active');
        node.removeAttribute('aria-current');
      });
      button.classList.add('is-active');
      button.setAttribute('aria-current', 'page');
      window.showKairosView(view);
    });
  });

  const hour = new Date().getHours();
  const greeting = document.getElementById('dashGreeting');
  if (greeting) {
    greeting.textContent = hour < 12 ? 'Good morning!' : hour < 17 ? 'Good afternoon!' : 'Good evening!';
  }
});
