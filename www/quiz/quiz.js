// Typed-quiz engine. The page embeds QUESTIONS (prompt sides only — the
// answer sides stay on the server), ANSWER_LIST (answer texts for the letter hint),
// ROUND_SETTINGS, and CSRF. Every answer POSTs to answer_eval.php and waits
// for the verdict; nothing is judged in the browser.
(function () {
  'use strict';

  if (typeof QUESTIONS === 'undefined' || !QUESTIONS.length) return;

  // Cheers are picked at random so the same card twice doesn't feel canned.
  var CHEERS = [
    { burst: '🎉', title: 'Nailed it!' },
    { burst: '⭐', title: 'Memory wizard!' },
    { burst: '🚀', title: 'Yes! Exactly right.' },
    { burst: '🌟', title: 'Brilliant.' },
    { burst: '💡', title: 'That is the one!' },
    { burst: '🎯', title: 'Bullseye.' },
    { burst: '🤩', title: 'Look at you go!' },
    { burst: '🌈', title: 'Beautiful work.' },
    { burst: '🔥', title: 'Too easy for you.' },
    { burst: '🍓', title: 'Sweet — spot on.' }
  ];

  var STREAK_CHEERS = {
    3: { burst: '🔥', title: 'Three in a row!' },
    5: { burst: '⚡', title: 'Five straight — unstoppable!' },
    8: { burst: '🏆', title: 'Eight in a row. Showing off now.' },
    12: { burst: '👑', title: 'Twelve straight. Flashcard royalty.' }
  };

  var CLOSE_CHEERS = [
    { burst: '✍️', title: 'So close — just the spelling!' },
    { burst: '🧠', title: 'You knew it! A letter or two off.' },
    { burst: '👌', title: 'Right answer, sneaky typo.' }
  ];

  var KIND_MISSES = [
    { burst: '🌱', title: 'Not this time — now you know it.' },
    { burst: '📚', title: 'Tricky one. Into the memory bank it goes.' },
    { burst: '👀', title: 'Have a good look at this one.' },
    { burst: '💪', title: 'Missed it — you will get it next round.' }
  ];

  var idx = 0;
  var phase = 'asking';           // 'asking' while typing, 'feedback' after a verdict
  var sessionPoints = 0;
  var sessionRight = 0;
  var streak = 0;
  var bestStreak = 0;
  var currentAttemptId = null;
  var busy = false;

  // Every verdict is kept (answers[n] mirrors questions[n]) so the back arrow
  // can re-show any answered question. reviewIdx is the question being looked
  // back at, or null when on the live question; stashedTyping preserves a
  // half-typed answer across a look back.
  var answers = [];
  var reviewIdx = null;
  var stashedTyping = '';

  // A refresh survives the round: progress is snapshotted to sessionStorage at
  // each verdict (the answer is already recorded server-side by then, so the
  // snapshot points at the NEXT question — resuming never re-asks a card whose
  // back was just shown) and cleared when the round ends. The snapshot only
  // resumes into a round with the same settings; storage failures (private
  // mode) just mean a refresh deals a fresh round, as before.
  var STORAGE_KEY = 'quiz-round-in-progress';
  var SETTINGS_KEY = JSON.stringify(ROUND_SETTINGS);

  function loadSavedRound() {
    try {
      var saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY));
      if (!saved || saved.settings !== SETTINGS_KEY) return null;
      if (!Array.isArray(saved.questions) || !saved.questions.length) return null;
      if (typeof saved.nextIdx !== 'number' || saved.nextIdx < 0 || saved.nextIdx > saved.questions.length) return null;
      return saved;
    } catch (e) {
      return null;
    }
  }

  function saveRoundProgress() {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
        settings: SETTINGS_KEY,
        questions: questions,
        answers: answers,
        nextIdx: idx + 1,
        points: sessionPoints,
        right: sessionRight,
        streak: streak,
        bestStreak: bestStreak
      }));
    } catch (e) { /* nothing to do — the round just won't survive a refresh */ }
  }

  function clearSavedRound() {
    try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) {}
  }

  var restored = loadSavedRound();
  var questions = restored ? restored.questions : QUESTIONS;
  if (restored) {
    idx = restored.nextIdx;
    answers = Array.isArray(restored.answers) ? restored.answers : [];
    sessionPoints = restored.points || 0;
    sessionRight = restored.right || 0;
    streak = restored.streak || 0;
    bestStreak = restored.bestStreak || 0;
  }

  var stage = document.getElementById('quiz-stage');
  var promptEl = document.getElementById('quiz-prompt');
  var hintBtn = document.getElementById('quiz-hint-btn');
  var hintEl = document.getElementById('quiz-hint');
  var choicesBtn = document.getElementById('quiz-choices-btn');
  var choicesEl = document.getElementById('quiz-choices');
  var prevBtn = document.getElementById('quiz-prev-btn');
  var fwdBtn = document.getElementById('quiz-fwd-btn');
  var form = document.getElementById('quiz-form');
  var input = document.getElementById('quiz-input');
  var checkBtn = document.getElementById('quiz-check');
  var pointsEl = document.getElementById('quiz-points');
  var streakEl = document.getElementById('quiz-streak');
  var progressFill = document.getElementById('quiz-progress-fill');
  var progressText = document.getElementById('quiz-progress-text');
  var feedback = document.getElementById('quiz-feedback');
  var fbBurst = document.getElementById('quiz-feedback-burst');
  var fbTitle = document.getElementById('quiz-feedback-title');
  var fbFront = document.getElementById('quiz-feedback-front');
  var fbBack = document.getElementById('quiz-feedback-back');
  var fbYours = document.getElementById('quiz-feedback-yours');
  var fbPoints = document.getElementById('quiz-feedback-points');
  var claimBtn = document.getElementById('quiz-claim');
  var nextBtn = document.getElementById('quiz-next');
  var donePanel = document.getElementById('quiz-done');
  var doneTitle = document.getElementById('quiz-done-title');
  var doneBurst = document.getElementById('quiz-done-burst');
  var doneScore = document.getElementById('quiz-done-score');
  var doneTally = document.getElementById('quiz-done-tally');
  var toast = document.getElementById('toast');
  var toastTimer;

  function showToast(message) {
    toast.textContent = message;
    toast.classList.remove('hidden');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.classList.add('hidden'); }, 4000);
  }

  function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
  }

  function postForm(url, fields) {
    var body = new FormData();
    body.append('csrf', CSRF);
    Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  // The prompt side of a card: front-to-back that is the front's image (when
  // it has one) above its text (when it has any); back-to-front it is the
  // back's text. Every prompt has at least one of the two.
  function renderPrompt(el, q) {
    el.textContent = '';
    if (q.prompt_image_url) {
      var img = document.createElement('img');
      img.className = 'flashcard-image';
      img.src = q.prompt_image_url;
      img.alt = q.prompt_text ? '' : 'Card image';
      el.appendChild(img);
    }
    if (q.prompt_text) {
      var text = document.createElement('div');
      text.className = 'quiz-prompt-text';
      text.textContent = q.prompt_text;
      el.appendChild(text);
    }
  }

  // Preload the next card's image so it appears the moment Next is pressed.
  function preloadNextImage() {
    var next = questions[idx + 1];
    if (next && next.prompt_image_url) {
      var img = new Image();
      img.src = next.prompt_image_url;
    }
  }

  var BACK_TO_FRONT = ROUND_SETTINGS.direction === 'back';

  function renderProgress() {
    if (reviewIdx !== null) {
      progressText.textContent = 'Looking back at question ' + (reviewIdx + 1) + ' of ' + questions.length;
      return;                    // the fill bar keeps showing the real frontier
    }
    var done = Math.min(idx, questions.length);
    progressFill.style.width = (done / questions.length) * 100 + '%';
    progressText.textContent = done < questions.length
      ? 'Question ' + (done + 1) + ' of ' + questions.length
      : questions.length + ' of ' + questions.length;
  }

  // ----- looking back at answered questions -----

  function currentPos() {
    return reviewIdx === null ? idx : reviewIdx;
  }

  // Back appears whenever there is something behind; forward appears only when
  // the question being looked at has already been answered (so it can never
  // skip ahead past an unanswered one).
  function renderNav() {
    prevBtn.classList.toggle('hidden', currentPos() === 0);
    fwdBtn.classList.toggle('hidden', reviewIdx === null && phase !== 'feedback');
  }

  function goBack() {
    if (busy || currentPos() === 0) return;
    if (reviewIdx === null && phase === 'asking') {
      stashedTyping = input.value;   // don't lose a half-typed answer
    }
    showReview(currentPos() - 1);
  }

  function goForward() {
    if (busy) return;
    if (reviewIdx !== null) {
      if (reviewIdx + 1 === idx) {
        exitReview();
      } else {
        showReview(reviewIdx + 1);
      }
    } else if (phase === 'feedback') {
      nextQuestion();
    }
  }

  function showReview(n) {
    reviewIdx = n;
    renderAnsweredView(n, false);
  }

  function exitReview() {
    reviewIdx = null;
    if (phase === 'asking') {
      renderQuestion();
      input.value = stashedTyping;
    } else {
      renderAnsweredView(idx, true);
      nextBtn.focus({ preventScroll: true });
    }
  }

  function renderStreak() {
    if (streak >= 2) {
      streakEl.textContent = '🔥 ' + streak + ' in a row';
      streakEl.classList.remove('hidden');
    } else {
      streakEl.classList.add('hidden');
    }
  }

  function renderQuestion() {
    renderProgress();

    if (idx >= questions.length) {
      finishRound();
      return;
    }

    var q = questions[idx];
    phase = 'asking';
    currentAttemptId = null;
    feedback.classList.add('hidden');
    form.classList.remove('hidden');
    renderPrompt(promptEl, q);
    promptEl.classList.remove('pop');
    void promptEl.offsetWidth;          // restart the entrance animation
    promptEl.classList.add('pop');
    preloadNextImage();

    hintEl.classList.add('hidden');
    hintEl.textContent = '';
    hintBtn.classList.remove('hidden');
    choicesBtn.classList.add('hidden');
    choicesEl.classList.add('hidden');
    choicesEl.textContent = '';

    input.value = '';
    input.disabled = false;
    checkBtn.disabled = false;
    renderStreak();
    renderNav();
    input.focus();
  }

  function showHint() {
    var q = questions[idx];
    hintEl.textContent = '';

    var letters = document.createElement('div');
    letters.className = 'quiz-hint-letters';
    letters.textContent = q.words + (q.words === 1 ? ' word, ' : ' words, ')
      + q.letters + (q.letters === 1 ? ' letter' : ' letters')
      + (q.first_letter ? ', starts with "' + q.first_letter + '"' : '');
    hintEl.appendChild(letters);

    hintEl.classList.remove('hidden');
    hintBtn.classList.add('hidden');
    if (q.first_letter) {
      choicesBtn.textContent = 'Show answers starting with "' + q.first_letter + '"';
      choicesBtn.classList.remove('hidden');
    }
    input.focus();
  }

  // The second hint: every answer in the deck that starts with the same
  // letter, as tappable chips that fill the answer box. The right one is in
  // the list, but nothing marks it — spotting it is the game.
  function showChoices() {
    var q = questions[idx];
    var letter = String(q.first_letter || '').toLowerCase();
    choicesEl.textContent = '';

    ANSWER_LIST.forEach(function (answer) {
      if (answer.charAt(0).toLowerCase() !== letter) return;
      var chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'quiz-choice-chip';
      chip.textContent = answer;
      chip.addEventListener('click', function () {
        if (phase !== 'asking') return;
        input.value = answer;
        input.focus();
      });
      choicesEl.appendChild(chip);
    });

    choicesEl.classList.remove('hidden');
    choicesBtn.classList.add('hidden');
    input.focus();
  }

  function submitAnswer() {
    if (phase !== 'asking' || busy) return;
    var answer = input.value.trim();
    if (!answer) { input.focus(); return; }

    busy = true;
    input.disabled = true;
    checkBtn.disabled = true;

    postForm('/quiz/answer_eval.php', {
      card_id: questions[idx].card_id,
      answer: answer,
      direction: ROUND_SETTINGS.direction || 'front'
    })
      .then(function (res) {
        busy = false;
        if (!res.ok) {
          input.disabled = false;
          checkBtn.disabled = false;
          input.focus();
          showToast('Could not check that: ' + (res.error || 'unknown error'));
          return;
        }
        showFeedback(res, answer);
      })
      .catch(function () {
        busy = false;
        input.disabled = false;
        checkBtn.disabled = false;
        input.focus();
        showToast('Could not check that — check your connection.');
      });
  }

  function showFeedback(res, typed) {
    phase = 'feedback';
    currentAttemptId = res.attempt_id;

    var landed = res.result === 'correct' || res.result === 'close';
    if (landed) {
      sessionRight++;
      streak++;
      bestStreak = Math.max(bestStreak, streak);
    } else {
      streak = 0;
    }
    sessionPoints += res.points;
    pointsEl.textContent = sessionPoints;

    var cheer;
    if (res.result === 'correct') {
      cheer = STREAK_CHEERS[streak] || pick(CHEERS);
      // The full song-and-dance (sound + fullscreen animation, cycling
      // through six of them) lives in celebrations.js.
      if (window.flashcardsCelebrate) window.flashcardsCelebrate();
    } else if (res.result === 'close') {
      cheer = pick(CLOSE_CHEERS);
    } else {
      cheer = pick(KIND_MISSES);
    }

    // "front" is the prompt side (shown small), "back" the answer side (big):
    // back-to-front swaps them. The card's image rides along with the front.
    answers[idx] = {
      result: res.result,
      points: res.points,
      front: BACK_TO_FRONT ? res.back_text : res.front_text,
      back: BACK_TO_FRONT ? res.front_text : res.back_text,
      image: res.image_url || null,
      typed: typed,
      claimed: false,
      canClaim: res.can_claim_correct,
      burst: cheer.burst,
      title: cheer.title
    };
    saveRoundProgress();

    renderAnsweredView(idx, true);
    renderStreak();

    // Show the verdict from the top — focusing the button alone would scroll a
    // phone straight past the answer we just revealed.
    feedback.scrollIntoView({ block: 'nearest' });
    nextBtn.focus({ preventScroll: true });
  }

  // The feedback panel, filled from a stored verdict. Live shows the cheer and
  // the claim button; a look back shows a calmer "already answered" header.
  function renderAnsweredView(n, live) {
    var a = answers[n];

    renderPrompt(promptEl, questions[n]);
    form.classList.add('hidden');
    hintBtn.classList.add('hidden');
    hintEl.classList.add('hidden');
    choicesBtn.classList.add('hidden');
    choicesEl.classList.add('hidden');

    feedback.className = 'quiz-feedback result-' + a.result
      + (a.claimed ? ' claimed' : '')
      + (live ? '' : ' quiz-reviewing');
    fbBurst.textContent = live ? a.burst : '📖';
    fbTitle.textContent = live ? a.title : 'You answered this one already';
    fbFront.textContent = a.front || '';
    fbFront.classList.toggle('hidden', !a.front);
    fbBack.textContent = '';
    if (BACK_TO_FRONT && a.image) {
      // The answer is the front, so show its picture with the text.
      var answerImg = document.createElement('img');
      answerImg.className = 'flashcard-image quiz-feedback-image';
      answerImg.src = a.image;
      answerImg.alt = '';
      fbBack.appendChild(answerImg);
    }
    fbBack.appendChild(document.createTextNode(a.back));

    if (a.result === 'correct') {
      fbYours.classList.add('hidden');
    } else {
      fbYours.textContent = 'You typed "' + a.typed + '"';
      fbYours.classList.remove('hidden');
    }

    fbPoints.textContent = a.points > 0
      ? '+' + a.points + ' points' + (a.claimed ? ' — counted!' : '')
      : 'No points this time';

    // The escape hatch: a back can be phrased more than one way, so anything
    // that scored nothing can be claimed as right anyway. Live only.
    claimBtn.classList.toggle('hidden', !(live && a.canClaim && !a.claimed));
    claimBtn.disabled = false;

    renderProgress();
    renderNav();
  }

  function claimCorrect() {
    if (!currentAttemptId || claimBtn.disabled) return;
    claimBtn.disabled = true;

    postForm('/quiz/claim_correct_eval.php', { attempt_id: currentAttemptId })
      .then(function (res) {
        if (!res.ok) {
          claimBtn.disabled = false;
          showToast('Could not count that: ' + (res.error || 'unknown error'));
          return;
        }
        sessionPoints += res.points;
        sessionRight++;
        pointsEl.textContent = sessionPoints;
        answers[idx].claimed = true;
        answers[idx].points = res.points;
        saveRoundProgress();
        claimBtn.classList.add('hidden');
        fbPoints.textContent = '+' + res.points + ' points — counted!';
        feedback.classList.add('claimed');
        if (window.flashcardsCelebrate) window.flashcardsCelebrate();
        nextBtn.focus();
      })
      .catch(function () {
        claimBtn.disabled = false;
        showToast('Could not count that — check your connection.');
      });
  }

  function nextQuestion() {
    if (reviewIdx !== null) { goForward(); return; }
    if (phase !== 'feedback') return;
    idx++;
    feedback.classList.remove('claimed');
    renderQuestion();
  }

  function finishRound() {
    clearSavedRound();
    stage.classList.add('hidden');
    donePanel.classList.remove('hidden');

    var pct = Math.round((sessionRight / questions.length) * 100);
    if (pct === 100) {
      doneBurst.textContent = '🏆';
      doneTitle.textContent = 'A perfect round!';
    } else if (pct >= 80) {
      doneBurst.textContent = '🎉';
      doneTitle.textContent = 'Great round!';
    } else if (pct >= 50) {
      doneBurst.textContent = '💪';
      doneTitle.textContent = 'Solid work.';
    } else {
      doneBurst.textContent = '🌱';
      doneTitle.textContent = 'Every round makes the next one easier.';
    }

    doneScore.textContent = sessionPoints + ' points';
    doneTally.textContent = sessionRight + ' of ' + questions.length + ' right (' + pct + '%)'
      + (bestStreak >= 3 ? ' · best streak: ' + bestStreak : '');
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    submitAnswer();
  });
  hintBtn.addEventListener('click', showHint);
  choicesBtn.addEventListener('click', showChoices);
  claimBtn.addEventListener('click', claimCorrect);
  nextBtn.addEventListener('click', nextQuestion);
  prevBtn.addEventListener('click', goBack);
  fwdBtn.addEventListener('click', goForward);

  // Enter moves on from the verdict even when focus has wandered off the
  // button; the arrow keys mirror the nav buttons (except while typing, where
  // they have to keep moving the cursor).
  document.addEventListener('keydown', function (e) {
    if (e.target !== input) {
      if (e.key === 'ArrowLeft') { goBack(); return; }
      if (e.key === 'ArrowRight') { goForward(); return; }
    }
    if (e.key !== 'Enter') return;
    if (reviewIdx !== null) {
      e.preventDefault();
      goForward();
      return;
    }
    if (phase !== 'feedback') return;
    if (e.target === nextBtn || e.target === claimBtn) return;
    e.preventDefault();
    nextQuestion();
  });

  pointsEl.textContent = sessionPoints;   // restored rounds resume mid-score
  renderQuestion();
})();
