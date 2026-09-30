// Deck-editing page behaviours. main.js already handles data-confirm buttons,
// data-auto-submit forms and the popup menus; this file adds, on the card
// forms, the local image preview (the chosen file is shown before it is
// uploaded) and paste / drag-and-drop of an image straight into the form:
// Command-V with a picture on the clipboard fills the file input, so the
// normal Save sends it as the front image.

(function () {
  'use strict';

  function showPreview(input, file) {
    var target = document.getElementById(input.getAttribute('data-preview-target') || '');
    if (!target) return;
    if (!file || !/^image\//.test(file.type)) {
      target.hidden = true;
      target.removeAttribute('src');
      return;
    }
    var url = URL.createObjectURL(file);
    target.onload = function () { URL.revokeObjectURL(url); };
    target.src = url;
    target.hidden = false;
  }

  // Put a File into the file input (browsers allow assigning a FileList built
  // from a DataTransfer) and refresh the preview.
  function useImageFile(input, file) {
    var name = file.name && file.name !== 'image.png' ? file.name : 'pasted-image.' + ((file.type.split('/')[1] || 'png').replace('jpeg', 'jpg'));
    var named = new File([file], name, { type: file.type });
    var dt = new DataTransfer();
    dt.items.add(named);
    input.files = dt.files;
    showPreview(input, named);
    var note = document.getElementById(input.getAttribute('data-paste-note') || '');
    if (note) {
      note.textContent = 'Using pasted image (' + Math.round(named.size / 1024) + ' KB). Choose a file above to replace it.';
      note.hidden = false;
    }
  }

  function imageFileFromDataTransfer(data) {
    if (!data) return null;
    var items = data.items || [];
    for (var i = 0; i < items.length; i++) {
      if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
        return items[i].getAsFile();
      }
    }
    var files = data.files || [];
    for (var j = 0; j < files.length; j++) {
      if (/^image\//.test(files[j].type)) return files[j];
    }
    return null;
  }

  function setupImageInputs() {
    var inputs = document.querySelectorAll('input[type="file"][data-preview-target]');
    if (!inputs.length) return;
    var input = inputs[0];   // one image input per card form

    inputs.forEach(function (el) {
      el.addEventListener('change', function () {
        showPreview(el, el.files && el.files[0]);
        var note = document.getElementById(el.getAttribute('data-paste-note') || '');
        if (note) note.hidden = true;
      });
    });

    // Command-V / Ctrl-V anywhere on the page (except while a text field is
    // the target and the clipboard holds text, which pastes normally).
    document.addEventListener('paste', function (e) {
      var file = imageFileFromDataTransfer(e.clipboardData);
      if (!file) return;
      e.preventDefault();
      useImageFile(input, file);
    });

    // Dragging a picture onto the form works the same way.
    var form = input.form || document;
    form.addEventListener('dragover', function (e) {
      if (imageFileFromDataTransfer(e.dataTransfer) || (e.dataTransfer && e.dataTransfer.types && e.dataTransfer.types.indexOf('Files') !== -1)) {
        e.preventDefault();
        form.classList.add('drop-target');
      }
    });
    form.addEventListener('dragleave', function () { form.classList.remove('drop-target'); });
    form.addEventListener('drop', function (e) {
      form.classList.remove('drop-target');
      var file = imageFileFromDataTransfer(e.dataTransfer);
      if (!file) return;
      e.preventDefault();
      useImageFile(input, file);
    });
  }

  function init() {
    setupImageInputs();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
