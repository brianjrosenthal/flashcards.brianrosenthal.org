// Deck-editing page behaviours. main.js already handles data-confirm buttons,
// data-auto-submit forms and the popup menus; this file adds the local image
// preview on the card forms (the chosen file is shown before it is uploaded).

(function () {
  'use strict';

  function setupImagePreview() {
    document.querySelectorAll('input[type="file"][data-preview-target]').forEach(function (input) {
      var target = document.getElementById(input.getAttribute('data-preview-target'));
      if (!target) return;
      input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file || !/^image\//.test(file.type)) {
          target.hidden = true;
          target.removeAttribute('src');
          return;
        }
        var url = URL.createObjectURL(file);
        target.onload = function () { URL.revokeObjectURL(url); };
        target.src = url;
        target.hidden = false;
      });
    });
  }

  function init() {
    setupImagePreview();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
