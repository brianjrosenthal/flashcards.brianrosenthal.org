// Flashcard deck engine. The page embeds DECK (the deck's cards in the
// viewer's order), START_AT (resume index), PERSIST_POSITION, DECK_TYPE /
// DECK_ID (which deck a saved position belongs to), CSRF and CAN_SAVE. Reads
// never hit the server; marks, flag toggles and position saves POST to the
// dedicated eval endpoints and the UI advances optimistically. When CAN_SAVE
// is false (an anonymous visitor on a public deck) nothing is ever posted:
// marks just advance the deck and count towards the session tally.
(function () {
  'use strict';

  if (typeof DECK === 'undefined' || !DECK.length) return;

  var idx = Math.min(START_AT, DECK.length);
  var sessionGot = 0;
  var sessionMiss = 0;

  var stage = document.getElementById('flashcard-stage');
  var card = document.getElementById('flashcard');
  var imageEl = document.getElementById('card-image');
  var frontTextEl = document.getElementById('card-front-text');
  var backEl = document.getElementById('card-back');
  var sourceEl = document.getElementById('card-source');   // only when studying a whole category
  var flagBtn = document.getElementById('flag-btn');        // absent when CAN_SAVE is false
  var btnGot = document.getElementById('btn-got');
  var btnMiss = document.getElementById('btn-miss');
  var btnPrev = document.getElementById('btn-prev');
  var btnNext = document.getElementById('btn-next');
  var btnDoneBack = document.getElementById('btn-done-back');
  var progressFill = document.getElementById('progress-fill');
  var progressText = document.getElementById('progress-text');
  var donePanel = document.getElementById('deck-done');
  var doneTally = document.getElementById('deck-done-tally');
  var toast = document.getElementById('toast');
  var toastTimer;
  var savePositionTimer;

  function showToast(message) {
    toast.textContent = message;
    toast.classList.remove('hidden');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.classList.add('hidden'); }, 4000);
  }

  function postForm(url, fields) {
    var body = new FormData();
    body.append('csrf', CSRF);
    Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  // Positions are saved per deck: DECK_TYPE + DECK_ID name the deck being
  // studied. Marks piggyback their own position save; browsing with the
  // arrows saves it separately (debounced so rapid flipping produces one write).
  function positionFields(fields) {
    fields.position = idx;
    fields.deck_type = DECK_TYPE;
    fields.deck_id = DECK_ID;
    return fields;
  }

  function schedulePositionSave() {
    if (!CAN_SAVE || !PERSIST_POSITION) return;
    clearTimeout(savePositionTimer);
    savePositionTimer = setTimeout(function () {
      postForm('/review/save_position_eval.php', positionFields({}))
        .then(function (res) {
          if (!res.ok) showToast('Could not save your place: ' + (res.error || 'unknown error'));
        })
        .catch(function () { showToast('Could not save your place — check your connection.'); });
    }, 500);
  }

  function updateScoreChip(score) {
    if (!score) return;
    var mastered = document.getElementById('score-mastered');
    var total = document.getElementById('score-total');
    var today = document.getElementById('score-today');
    if (mastered) mastered.textContent = score.mastered.toLocaleString();
    if (total) total.textContent = score.total_cards.toLocaleString();
    if (today) today.textContent = score.reviewed_today.toLocaleString();
  }

  function renderProgress() {
    var done = Math.min(idx, DECK.length);
    progressFill.style.width = (DECK.length ? (done / DECK.length) * 100 : 0) + '%';
    progressText.textContent = done < DECK.length
      ? 'Card ' + (done + 1) + ' of ' + DECK.length
      : DECK.length + ' of ' + DECK.length;
  }

  // The front face: an optional image (sized from the stored dimensions so
  // the card doesn't jump while it loads) and/or optional text.
  function renderImage(entry) {
    imageEl.removeAttribute('src');   // never show the previous card's picture while the next loads
    imageEl.removeAttribute('width');
    imageEl.removeAttribute('height');
    if (!entry.image_url) {
      imageEl.hidden = true;
      return;
    }
    if (entry.image_width && entry.image_height) {
      imageEl.width = entry.image_width;
      imageEl.height = entry.image_height;
    }
    imageEl.src = entry.image_url;
    imageEl.hidden = false;
  }

  function preloadNextImage() {
    var next = DECK[idx + 1];
    if (next && next.image_url) {
      var img = new Image();
      img.src = next.image_url;
    }
  }

  function renderFlag(entry) {
    if (!flagBtn) return;
    flagBtn.classList.toggle('flagged', entry.flagged);
    flagBtn.setAttribute('aria-pressed', entry.flagged ? 'true' : 'false');
  }

  function renderCard() {
    renderProgress();

    if (idx >= DECK.length) {
      stage.classList.add('hidden');
      donePanel.classList.remove('hidden');
      doneTally.textContent = sessionGot + sessionMiss > 0
        ? 'This round: ' + sessionGot + ' got it, ' + sessionMiss + ' to review again.'
        : 'You’re all the way through!';
      return;
    }

    stage.classList.remove('hidden');
    donePanel.classList.add('hidden');

    var entry = DECK[idx];
    card.classList.remove('flipped');
    renderImage(entry);
    frontTextEl.textContent = entry.front_text || '';
    frontTextEl.classList.toggle('hidden', !entry.front_text);
    backEl.textContent = entry.back_text;
    if (sourceEl) sourceEl.textContent = entry.subcategory_name || '';
    renderFlag(entry);
    btnPrev.disabled = idx === 0;
    // Forward only appears once this card has been marked — the way ahead is
    // earned card by card. (concealed, not hidden, so the card doesn't shift.)
    btnNext.classList.toggle('concealed', !entry.marked);
    renderEditLink(entry);
    preloadNextImage();
  }

  // "Edit this card" follows the card on screen (owner/admin only) and the
  // editor returns here, to this very card, after saving.
  function editUrlFor(entry) {
    return '/manage/card_edit.php?id=' + entry.id + '&next=' + encodeURIComponent(THIS_URL + '&card=' + entry.id);
  }
  function renderEditLink(entry) {
    if (!editLink) return;
    editLink.href = editUrlFor(entry);
  }
  function openEditor() {
    if (!editLink || idx >= DECK.length) return;
    window.location.href = editUrlFor(DECK[idx]);
  }

  function flipCard() {
    if (idx >= DECK.length) return;
    card.classList.toggle('flipped');
  }

  // Browse to another card without marking (the < and > buttons / arrow keys).
  function goTo(newIdx) {
    newIdx = Math.max(0, Math.min(newIdx, DECK.length));
    if (newIdx === idx) return;
    idx = newIdx;
    renderCard();
    schedulePositionSave();
  }

  function goBack() { goTo(idx - 1); }
  function goForward() {
    if (idx < DECK.length && !DECK[idx].marked) return;   // mark it to move on
    goTo(idx + 1);
  }

  function markCurrent(mark) {
    if (idx >= DECK.length) return;
    var entry = DECK[idx];
    entry.marked = true;
    if (mark === 'got_it') { sessionGot++; } else { sessionMiss++; }

    // Optimistic UI: advance immediately, report errors via toast.
    idx++;
    var fields = { card_id: entry.id, mark: mark };
    if (PERSIST_POSITION) positionFields(fields);

    card.classList.add('leaving');
    setTimeout(function () {
      card.classList.remove('leaving');
      renderCard();
    }, 140);

    if (!CAN_SAVE) return;   // anonymous visitor: the tally is session-only

    postForm('/review/mark_card_eval.php', fields)
      .then(function (res) {
        if (!res.ok) { showToast('Could not save: ' + (res.error || 'unknown error')); return; }
        updateScoreChip(res.score);
      })
      .catch(function () { showToast('Could not save — check your connection.'); });
  }

  function toggleFlag() {
    if (!CAN_SAVE || !flagBtn || idx >= DECK.length) return;
    var entry = DECK[idx];
    entry.flagged = !entry.flagged;
    renderFlag(entry);

    postForm('/review/toggle_flag_eval.php', { card_id: entry.id, flagged: entry.flagged ? 1 : 0 })
      .then(function (res) {
        if (!res.ok) {
          entry.flagged = !entry.flagged;
          renderFlag(entry);
          showToast('Could not save flag: ' + (res.error || 'unknown error'));
        }
      })
      .catch(function () {
        entry.flagged = !entry.flagged;
        renderFlag(entry);
        showToast('Could not save flag — check your connection.');
      });
  }

  card.addEventListener('click', function (e) {
    if (flagBtn && flagBtn.contains(e.target)) return;
    flipCard();
  });
  card.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); flipCard(); }
  });
  if (flagBtn) {
    flagBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleFlag();
    });
  }
  btnGot.addEventListener('click', function () { markCurrent('got_it'); });
  btnMiss.addEventListener('click', function () { markCurrent('needs_review'); });
  btnPrev.addEventListener('click', goBack);
  btnNext.addEventListener('click', goForward);
  if (btnDoneBack) btnDoneBack.addEventListener('click', goBack);

  document.addEventListener('keydown', function (e) {
    if (e.target.matches('input, textarea, select')) return;
    if (e.key === ' ') { e.preventDefault(); flipCard(); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); goBack(); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); goForward(); }
    else if (e.key === '1') { markCurrent('got_it'); }
    else if (e.key === '2') { markCurrent('needs_review'); }
    else if (e.key === 'f' || e.key === 'F') { toggleFlag(); }
    else if ((e.key === 'e' || e.key === 'E') && typeof CAN_EDIT !== 'undefined' && CAN_EDIT) { openEditor(); }
  });

  renderCard();
})();
