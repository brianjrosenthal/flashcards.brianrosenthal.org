// Shared page behaviors: top-bar popup menus, auto-submit search forms,
// confirm buttons, and auth-page focus.

// Popup menus (Admin dropdown, profile menu) — toggle button + panel pairs.
function setupPopupMenus() {
  var pairs = [
    ['adminToggle', 'adminMenu'],
    ['profileToggle', 'profileMenu'],
  ];

  var menus = [];

  pairs.forEach(function (pair) {
    var btn = document.getElementById(pair[0]);
    var menu = document.getElementById(pair[1]);
    if (!btn || !menu) return;
    menus.push({ btn: btn, menu: menu });

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var willOpen = menu.classList.contains('hidden');
      closeAllMenus();
      if (willOpen) {
        menu.classList.remove('hidden');
        menu.setAttribute('aria-hidden', 'false');
        btn.setAttribute('aria-expanded', 'true');
      }
    });
  });

  function closeAllMenus() {
    menus.forEach(function (m) {
      m.menu.classList.add('hidden');
      m.menu.setAttribute('aria-hidden', 'true');
      m.btn.setAttribute('aria-expanded', 'false');
    });
  }

  document.addEventListener('click', function (e) {
    var inside = menus.some(function (m) {
      return m.btn.contains(e.target) || m.menu.contains(e.target);
    });
    if (!inside) closeAllMenus();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAllMenus();
  });
}

// Auto-submit forms with debouncing (opt in with data-auto-submit)
function setupAutoSubmit() {
  var forms = document.querySelectorAll('form[data-auto-submit]');

  forms.forEach(function (form) {
    var inputs = form.querySelectorAll('input, select');
    var timeout;

    inputs.forEach(function (input) {
      input.addEventListener('input', function () {
        clearTimeout(timeout);
        timeout = setTimeout(function () {
          if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
          } else {
            form.submit();
          }
        }, 600);
      });
    });
  });
}

// Confirm buttons/links (opt in with data-confirm="message")
function setupConfirmButtons() {
  var buttons = document.querySelectorAll('[data-confirm]');
  buttons.forEach(function (button) {
    button.addEventListener('click', function (e) {
      var message = this.getAttribute('data-confirm') || 'Are you sure?';
      if (!confirm(message)) {
        e.preventDefault();
      }
    });
  });
}

// Concept page video (opt in with data-autoplay): start playing on load.
// Browsers block audible autoplay until the visitor has interacted with the
// site, so when play() is refused we retry muted and offer an Unmute button.
function setupAutoplayVideo() {
  var video = document.querySelector('video[data-autoplay]');
  if (!video || typeof video.play !== 'function') return;

  function showUnmute() {
    var frame = video.parentNode;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'video-unmute';
    btn.textContent = '\uD83D\uDD07 Tap to unmute';
    btn.addEventListener('click', function () {
      video.muted = false;
      video.currentTime = 0;
      video.play().catch(function () {});
      btn.remove();
    });
    video.addEventListener('volumechange', function () {
      if (!video.muted) btn.remove();
    });
    frame.appendChild(btn);
  }

  var attempt = video.play();
  if (!attempt || typeof attempt.catch !== 'function') return;
  attempt.catch(function () {
    video.muted = true;
    var retry = video.play();
    if (retry && typeof retry.then === 'function') {
      retry.then(showUnmute).catch(function () { video.muted = false; });
    }
  });
}

document.addEventListener('DOMContentLoaded', function () {
  setupPopupMenus();
  setupAutoSubmit();
  setupConfirmButtons();
  setupAutoplayVideo();

  // Focus first input on auth pages
  if (document.body.classList.contains('auth')) {
    var firstInput = document.querySelector('input[type="email"], input[type="text"]');
    if (firstInput) {
      firstInput.focus();
    }
  }
});
