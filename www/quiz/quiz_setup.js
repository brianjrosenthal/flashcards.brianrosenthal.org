// Quiz launcher: when the deck changes, refetch how many questions each card
// pool holds (pool_counts_eval.php), grey out empty pools, and keep the Start
// button off while the chosen pool has nothing to ask. On submit, the
// "category:N" / "subcategory:N" deck value is rewritten into the
// ?subcategory=N / ?category=N every other page uses.
(function () {
  'use strict';

  var form = document.getElementById('quiz-setup');
  if (!form) return;

  var deckSelect = document.getElementById('quiz-deck-select');
  var sourcePicks = form.querySelectorAll('.quiz-source-pick');
  var startBtn = document.getElementById('quiz-start');
  var note = document.getElementById('quiz-setup-note');
  var latestRequest = 0;

  function deckParts() {
    var parts = String(deckSelect ? deckSelect.value : '').split(':');
    return parts.length === 2 ? { type: parts[0], id: parts[1] } : null;
  }

  function applyCounts(counts) {
    sourcePicks.forEach(function (pick) {
      var count = counts[pick.dataset.source] || 0;
      pick.querySelector('.quiz-source-count').textContent = count;
      // An empty pool stays visible and explains itself rather than vanishing.
      pick.classList.toggle('empty', count === 0);
      pick.querySelector('input').disabled = count === 0;
    });

    // If the pool that was selected just emptied out, fall back to all cards.
    var checked = form.querySelector('input[name="source"]:checked');
    if (!checked || checked.disabled) {
      var fallback = form.querySelector('input[name="source"]:not(:disabled)');
      if (fallback) fallback.checked = true;
    }
    refreshStart();
  }

  function refreshStart() {
    var anything = form.querySelector('input[name="source"]:checked');
    var canStart = !!anything && !anything.disabled;
    startBtn.classList.toggle('disabled', !canStart);
    startBtn.disabled = !canStart;
  }

  function showNote(message) {
    if (!note) return;
    note.textContent = message || '';
    note.classList.toggle('hidden', !message);
  }

  function refreshCounts() {
    var deck = deckParts();
    if (!deck) return;
    var requestId = ++latestRequest;
    showNote('Counting cards…');

    fetch('/quiz/pool_counts_eval.php?' + encodeURIComponent(deck.type) + '=' + encodeURIComponent(deck.id),
      { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (requestId !== latestRequest) return;   // a newer pick is in flight
        if (!res.ok) {
          showNote('Could not count that deck: ' + (res.error || 'unknown error'));
          applyCounts({});
          return;
        }
        showNote('');
        applyCounts(res);
      })
      .catch(function () {
        if (requestId !== latestRequest) return;
        showNote('Could not count that deck — check your connection.');
        applyCounts({});
      });
  }

  if (deckSelect) deckSelect.addEventListener('change', refreshCounts);
  form.querySelectorAll('input[name="source"]').forEach(function (input) {
    input.addEventListener('change', refreshStart);
  });

  form.addEventListener('submit', function (e) {
    if (startBtn.disabled) { e.preventDefault(); return; }
    var deck = deckParts();
    if (!deck || !deckSelect) return;
    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = deck.type;
    hidden.value = deck.id;
    form.appendChild(hidden);
    deckSelect.removeAttribute('name');   // keep the URL to the canonical form
  });

  refreshStart();
})();
