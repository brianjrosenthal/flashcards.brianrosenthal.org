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

  // ---- Picture import: review page count + progress page batch loop -------

  function setupImportReview() {
    var form = document.getElementById('import-review-form');
    if (!form) return;
    var box = form.querySelector('input[name="import_existing"]');
    var count = document.getElementById('import-count');
    var btn = document.getElementById('import-start-btn');
    if (!box || !count || !btn) return;
    var base = parseInt(count.textContent, 10) || 0;
    var adds = parseInt(box.getAttribute('data-adds'), 10) || 0;
    box.addEventListener('change', function () {
      var n = base + (box.checked ? adds : 0);
      count.textContent = String(n);
      btn.disabled = n === 0;
    });
  }

  function setupImportProgress() {
    var box = document.getElementById('import-progress');
    if (!box || box.getAttribute('data-finished') === '1') return;
    var token = box.getAttribute('data-token');
    var csrf = box.getAttribute('data-csrf');
    var fill = document.getElementById('import-progress-fill');
    var text = document.getElementById('import-progress-text');
    var errorEl = document.getElementById('import-progress-error');
    var summary = document.getElementById('import-summary');
    var createdEl = document.getElementById('import-created');
    var failedEl = document.getElementById('import-failed');
    var failedWrap = document.getElementById('import-failed-wrap');
    var actions = document.getElementById('import-done-actions');
    var failures = document.getElementById('import-failures');
    var failureList = document.getElementById('import-failure-list');
    var retries = 0;

    function render(r) {
      var pct = r.total > 0 ? Math.round(100 * r.processed / r.total) : 100;
      fill.style.width = pct + '%';
      text.textContent = r.done ? 'Done.' : ('Imported ' + r.processed + ' of ' + r.total + '\u2026 keep this page open.');
      createdEl.textContent = String(r.created);
      failedEl.textContent = String(r.failed);
      failedWrap.hidden = r.failed === 0;
      if (r.failures && r.failures.length) {
        failureList.innerHTML = '';
        r.failures.forEach(function (f) {
          var li = document.createElement('li');
          var b = document.createElement('strong');
          b.textContent = f.source;
          li.appendChild(b);
          li.appendChild(document.createTextNode(' \u2014 ' + f.reason));
          failureList.appendChild(li);
        });
        failures.hidden = false;
      }
      if (r.done) {
        summary.hidden = false;
        actions.hidden = false;
        var heading = document.querySelector('.page-head h2');
        if (heading) heading.textContent = 'Import finished';
      }
    }

    function step() {
      var body = new URLSearchParams();
      body.set('token', token);
      body.set('csrf', csrf);
      fetch('/manage/card_import_batch_eval.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      }).then(function (res) {
        return res.json().then(function (json) {
          if (!res.ok || !json.ok) throw new Error(json.error || ('Import failed (' + res.status + ')'));
          return json;
        });
      }).then(function (r) {
        retries = 0;
        render(r);
        if (!r.done) setTimeout(step, 50);
      }).catch(function (e) {
        // A transient network hiccup: try again a few times; progress is
        // saved server-side after every picture, so nothing is lost.
        if (retries < 3) {
          retries++;
          setTimeout(step, 1500 * retries);
          return;
        }
        errorEl.textContent = e.message + ' Reload this page to continue where it left off.';
        errorEl.hidden = false;
      });
    }

    step();
  }

  function init() {
    setupImageInputs();
    setupImportReview();
    setupImportProgress();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
