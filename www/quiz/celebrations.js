// Correct-answer celebrations — a Khan-Academy-style "ding" moment. Each
// right answer plays one of six short sound + fullscreen-animation pairs,
// cycled in order so no two rights in a row feel the same:
//   1. bell ding      + confetti storm
//   2. trumpet fanfare + trumpet blasting music notes
//   3. cha-ching       + gold coin fountain
//   4. fireworks       + fireworks bursts
//   5. "Who's awesome? YOU are!!" (spoken) + slamming text
//   6. "Winner winner, chicken dinner!" (deep boomy voice) + championship trophy
// Everything is capped at 3 seconds, drawn on a click-through canvas so the
// Next button stays usable, and synthesized with Web Audio / speech
// synthesis — no audio files. The canvas/animation approach is borrowed from
// the hackleyclubz.org chat effects.
//
// Public API: window.flashcardsCelebrate()
(function () {
  'use strict';

  var DURATION_CAP = 3000;              // nothing runs longer than this
  var MASTER_VOLUME = 0.5;

  // ── Audio unlock ──────────────────────────────────────────────────────────
  // Browsers only let audio start from a user gesture, but the celebration
  // fires from a fetch callback. So the context is created and resumed on the
  // first click/keypress (there is always one before an answer is checked),
  // and speech synthesis is primed the same way for iOS.
  var audioCtx = null;
  var speechPrimed = false;

  function unlockAudio() {
    var AC = window.AudioContext || window.webkitAudioContext;
    if (AC && !audioCtx) {
      try { audioCtx = new AC(); } catch (e) { audioCtx = null; }
    }
    if (audioCtx && audioCtx.state === 'suspended') {
      audioCtx.resume().catch(function () {});
    }
    if (!speechPrimed && window.speechSynthesis) {
      speechPrimed = true;
      try { speechSynthesis.speak(new SpeechSynthesisUtterance('')); } catch (e) {}
    }
  }
  document.addEventListener('pointerdown', unlockAudio);
  document.addEventListener('keydown', unlockAudio);

  function now() { return audioCtx.currentTime; }

  // A gain node that ramps down and disconnects itself — every sound goes
  // through one of these so nothing is left running.
  function envelope(at, peak, decay) {
    var g = audioCtx.createGain();
    g.gain.setValueAtTime(0.0001, at);
    g.gain.exponentialRampToValueAtTime(peak * MASTER_VOLUME, at + 0.01);
    g.gain.exponentialRampToValueAtTime(0.0001, at + decay);
    g.connect(audioCtx.destination);
    return g;
  }

  function tone(type, freq, at, peak, decay, glideTo) {
    var o = audioCtx.createOscillator();
    o.type = type;
    o.frequency.setValueAtTime(freq, at);
    if (glideTo) o.frequency.exponentialRampToValueAtTime(glideTo, at + decay);
    o.connect(envelope(at, peak, decay));
    o.start(at);
    o.stop(at + decay + 0.05);
  }

  var noiseBuffer = null;
  function makeNoise(at, peak, decay, filterType, filterFreq) {
    if (!noiseBuffer) {
      noiseBuffer = audioCtx.createBuffer(1, audioCtx.sampleRate, audioCtx.sampleRate);
      var data = noiseBuffer.getChannelData(0);
      for (var i = 0; i < data.length; i++) data[i] = Math.random() * 2 - 1;
    }
    var src = audioCtx.createBufferSource();
    src.buffer = noiseBuffer;
    var f = audioCtx.createBiquadFilter();
    f.type = filterType;
    f.frequency.setValueAtTime(filterFreq, at);
    src.connect(f);
    f.connect(envelope(at, peak, decay));
    src.start(at);
    src.stop(at + decay + 0.05);
  }

  function speak(text, pitch, rate) {
    if (!window.speechSynthesis) return false;
    try {
      speechSynthesis.cancel();
      var u = new SpeechSynthesisUtterance(text);
      u.pitch = pitch;
      u.rate = rate;
      u.volume = 1;
      speechSynthesis.speak(u);
      return true;
    } catch (e) { return false; }
  }

  // ── The six sounds ────────────────────────────────────────────────────────

  // 1. A bright celebration bell: inharmonic partials of one strike, plus a
  // higher answering ding — the classic "you got it!" chime.
  function bellSound() {
    var t = now();
    [1, 2.51, 3.98].forEach(function (p, i) {
      tone('sine', 1046.5 * p, t, 0.5 / (i + 1), 1.1);
    });
    [1, 2.51].forEach(function (p, i) {
      tone('sine', 1568 * p, t + 0.18, 0.35 / (i + 1), 1.0);
    });
  }

  // 2. Ta-da-da-DAAA — two detuned sawtooths through a lowpass, close enough
  // to brass for a two-second fanfare.
  function trumpetSound() {
    var t = now();
    var notes = [
      { f: 392.0, at: 0, len: 0.16 },     // G4
      { f: 523.25, at: 0.17, len: 0.16 }, // C5
      { f: 659.25, at: 0.34, len: 0.16 }, // E5
      { f: 783.99, at: 0.51, len: 0.75 }  // G5 — the big held one
    ];
    notes.forEach(function (n) {
      [0, 6].forEach(function (detune) {
        var o = audioCtx.createOscillator();
        o.type = 'sawtooth';
        o.frequency.setValueAtTime(n.f, t + n.at);
        o.detune.setValueAtTime(detune, t + n.at);
        var lp = audioCtx.createBiquadFilter();
        lp.type = 'lowpass';
        lp.frequency.setValueAtTime(2200, t + n.at);
        o.connect(lp);
        lp.connect(envelope(t + n.at, 0.22, n.len));
        o.start(t + n.at);
        o.stop(t + n.at + n.len + 0.05);
      });
    });
  }

  // 3. Cha-ching: the register's kerchunk, the counter bell, then a spray of
  // tiny coin pings.
  function chachingSound() {
    var t = now();
    tone('square', 220, t, 0.15, 0.07);                  // kerchunk
    [0.08, 0.16].forEach(function (at) {                 // the double bell
      [1, 2.51].forEach(function (p, i) {
        tone('sine', 2093 * p, t + at, 0.4 / (i + 1), 0.5);
      });
    });
    for (var i = 0; i < 8; i++) {                        // coins landing
      tone('sine', 2500 + Math.random() * 2700, t + 0.25 + Math.random() * 0.45,
           0.12, 0.15 + Math.random() * 0.2);
    }
  }

  // 4. Fireworks: a rising whistle, three booms, and a crackle of tiny snaps.
  function fireworksSound() {
    var t = now();
    tone('sine', 500, t, 0.08, 0.55, 1400);              // the rocket whistles up
    [0.6, 1.0, 1.45].forEach(function (at) {             // the booms
      makeNoise(t + at, 0.55, 0.7, 'lowpass', 400);
    });
    for (var i = 0; i < 22; i++) {                       // the crackle
      makeNoise(t + 0.7 + Math.random() * 1.5, 0.1, 0.05, 'highpass', 3000);
    }
  }

  // 5 & 6. The spoken ones. If speech synthesis is missing, the bell stands in
  // so the moment never plays silent.
  function awesomeSound() {
    if (!speak("Who's awesome? YOU are!!", 1.5, 1.1)) bellSound();
  }

  function winnerSound() {
    // The bass drop under the announcer makes the "booming videogame voice".
    // The rate is a touch quicker than the old one-word shout so the whole
    // phrase fits inside the 3-second cap.
    var t = now();
    tone('sawtooth', 55, t, 0.4, 1.6);
    tone('sawtooth', 82.5, t, 0.3, 1.6);
    if (!speak('Winner winner, chicken dinner!', 0, 0.95)) trumpetSound();
  }

  // ── Canvas overlay (the hackleyclubz pattern) ─────────────────────────────
  // One fullscreen, click-through canvas per celebration; a spec object owns
  // draw(ctx, W, H, tSeconds) and its duration; rAF drives it and everything
  // is torn down at the end. Starting a new one cancels the last.
  var activeCleanup = null;

  function play(spec) {
    if (activeCleanup) activeCleanup();
    var canvas = document.createElement('canvas');
    canvas.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;' +
      'z-index:9999;pointer-events:none;';
    document.body.appendChild(canvas);
    var ctx = canvas.getContext('2d');
    function resize() { canvas.width = window.innerWidth; canvas.height = window.innerHeight; }
    resize();
    window.addEventListener('resize', resize);

    var stopped = false;
    var t0 = null;
    var duration = Math.min(spec.duration, DURATION_CAP);
    function cleanup() {
      stopped = true;
      window.removeEventListener('resize', resize);
      canvas.remove();
      if (activeCleanup === cleanup) activeCleanup = null;
    }
    activeCleanup = cleanup;
    function frame(ts) {
      if (stopped) return;
      if (t0 === null) t0 = ts;
      var e = ts - t0;
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.globalAlpha = 1;
      if (e < duration) {
        spec.draw(ctx, canvas.width, canvas.height, e / 1000);
        requestAnimationFrame(frame);
      } else {
        cleanup();
      }
    }
    requestAnimationFrame(frame);
  }

  function circle(ctx, x, y, r) { ctx.beginPath(); ctx.arc(x, y, r, 0, Math.PI * 2); ctx.fill(); }
  function hsl(h, s, l) { return 'hsl(' + (((h % 1) + 1) % 1) * 360 + ',' + s + '%,' + l + '%)'; }
  // Every spec fades in fast and out gently so the teardown never pops.
  function fadeInOut(t, dur, out) {
    return Math.min(Math.min(1, t / 0.15), t > dur - out ? Math.max(0, (dur - t) / out) : 1);
  }
  function popText(ctx, text, x, y, size, fill, stroke, alpha, scale) {
    ctx.save();
    ctx.translate(x, y);
    ctx.scale(scale, scale);
    ctx.font = '900 ' + Math.round(size) + 'px -apple-system, "Arial Black", sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.globalAlpha = alpha;
    ctx.lineWidth = size * 0.12;
    ctx.lineJoin = 'round';
    ctx.strokeStyle = stroke;
    ctx.strokeText(text, 0, 0);
    ctx.fillStyle = fill;
    ctx.fillText(text, 0, 0);
    ctx.restore();
    ctx.globalAlpha = 1;
  }

  // ── 1. Confetti storm ─────────────────────────────────────────────────────
  // The hackleyclubz confetti: rects, circles and ribbons in full-spectrum
  // colors bursting across the top and tumbling down, each piece fading out on
  // its own so the storm dissolves rather than stops.
  function confettiSpec() {
    var duration = 2800;
    var COLORS = [];
    for (var c = 0; c < 36; c++) COLORS.push('hsl(' + c * 10 + ',100%,60%)');
    COLORS.push('#ffffff', '#fffbe6', '#e0f7ff');
    var SHAPES = ['rect', 'circle', 'ribbon'];
    var pieces = [];
    for (var i = 0; i < 320; i++) {
      var shape = SHAPES[Math.floor(Math.random() * SHAPES.length)];
      var ribbon = shape === 'ribbon';
      pieces.push({
        x: Math.random(), y: -0.02 - Math.random() * 0.4,
        vx: (Math.random() - 0.5) * 0.35, vy: 0.35 + Math.random() * 0.5,
        g: 0.25 + Math.random() * 0.25,
        rot: Math.random() * Math.PI * 2, rotV: (Math.random() - 0.5) * 14,
        wob: Math.random() * Math.PI * 2, wobS: 3 + Math.random() * 5,
        wobA: 8 + Math.random() * 18,
        w: ribbon ? 3 + Math.random() * 4 : 8 + Math.random() * 10,
        h: ribbon ? 14 + Math.random() * 12 : 8 + Math.random() * 10,
        shape: shape,
        color: COLORS[Math.floor(Math.random() * COLORS.length)],
        decay: 0.55 + Math.random() * 0.35        // alpha lost per second
      });
    }
    return { duration: duration, draw: function (ctx, W, H, t) {
      pieces.forEach(function (p) {
        var alpha = Math.max(0, 1 - p.decay * t);
        if (alpha <= 0) return;
        var x = (p.x + p.vx * t) * W + Math.sin(p.wob + p.wobS * t) * p.wobA;
        var y = (p.y + p.vy * t + 0.5 * p.g * t * t) * H;
        if (y > H + 30) return;
        ctx.save();
        ctx.translate(x, y);
        ctx.rotate(p.rot + p.rotV * t);
        ctx.globalAlpha = alpha;
        ctx.fillStyle = p.color;
        if (p.shape === 'circle') circle(ctx, 0, 0, p.w / 2);
        else ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
        ctx.restore();
      });
      ctx.globalAlpha = 1;
    }};
  }

  // ── 2. Trumpet blast ──────────────────────────────────────────────────────
  // A big trumpet swings in from the left and blasts music notes and rings out
  // of its bell in time with the fanfare's three short notes and the held one.
  function trumpetSpec() {
    var duration = 2600, dur = duration / 1000;
    var BLASTS = [0.35, 0.55, 0.75, 1.0];
    var notes = [];
    BLASTS.forEach(function (at, bi) {
      var n = bi === 3 ? 10 : 4;                   // the held note erupts
      for (var i = 0; i < n; i++) notes.push({
        at: at, glyph: Math.random() < 0.5 ? '🎵' : '🎶',
        angle: -0.7 + Math.random() * 1.4,
        speed: 0.25 + Math.random() * 0.3,
        size: 22 + Math.random() * 22,
        spin: (Math.random() - 0.5) * 6,
        wob: Math.random() * Math.PI * 2
      });
    });
    return { duration: duration, draw: function (ctx, W, H, t) {
      var fade = fadeInOut(t, dur, 0.4);
      var S = Math.min(W, H);
      // The trumpet swings in, then recoils a little on every blast.
      var enter = Math.min(1, t / 0.35);
      var ez = 1 - Math.pow(1 - enter, 3);
      var recoil = 0;
      BLASTS.forEach(function (at) {
        var bt = t - at;
        if (bt > 0 && bt < 0.2) recoil = Math.max(recoil, (1 - bt / 0.2) * 0.06);
      });
      var tx = (-0.25 + 0.6 * ez - recoil) * W;
      var ty = H * 0.42 + Math.sin(t * 2.5) * S * 0.015;
      // The 🎺 glyph points its bell lower-left, so the emoji is drawn
      // mirrored (bell lower-right) and the blasts come from there.
      var bellX = tx + S * 0.13, bellY = ty + S * 0.09;

      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';

      // Blast rings growing out of the bell.
      BLASTS.forEach(function (at, bi) {
        var bt = t - at;
        if (bt < 0 || bt > 0.6) return;
        var r = S * (0.04 + (bi === 3 ? 0.5 : 0.3) * bt / 0.6);
        ctx.globalAlpha = fade * (1 - bt / 0.6) * 0.8;
        ctx.strokeStyle = hsl(0.12 + bi * 0.08, 95, 60);
        ctx.lineWidth = 5 * (1 - bt / 0.6) + 1;
        ctx.beginPath(); ctx.arc(bellX, bellY, r, 0, Math.PI * 2); ctx.stroke();
      });
      ctx.globalAlpha = 1;

      // The notes fly out to the right, wobbling like melody lines.
      notes.forEach(function (n) {
        var nt = t - n.at;
        if (nt < 0 || nt > 1.3) return;
        var d = n.speed * nt * S * 2.2 * (1 - Math.min(0.6, nt * 0.45));
        var x = bellX + Math.cos(n.angle) * d;
        var y = bellY + Math.sin(n.angle) * d + Math.sin(nt * 7 + n.wob) * S * 0.02 - nt * S * 0.06;
        ctx.save();
        ctx.translate(x, y);
        ctx.rotate(n.spin * nt * 0.4);
        ctx.globalAlpha = fade * Math.max(0, 1 - nt / 1.3);
        ctx.font = n.size + 'px sans-serif';
        ctx.fillText(n.glyph, 0, 0);
        ctx.restore();
      });
      ctx.globalAlpha = fade;
      ctx.font = Math.round(S * 0.32) + 'px sans-serif';
      ctx.save();
      ctx.translate(tx, ty);
      ctx.scale(-1, 1);                                  // bell to the right
      ctx.rotate(0.15 + 0.03 * Math.sin(t * 3));
      ctx.fillText('🎺', 0, 0);
      ctx.restore();
      ctx.globalAlpha = 1;
    }};
  }

  // ── 3. Gold coin fountain ─────────────────────────────────────────────────
  // Coins erupt from the bottom center, flip in the air (a squashed ellipse
  // fakes the 3D spin), catch glints of light, and fall back out of frame.
  function coinsSpec() {
    var duration = 2800, dur = duration / 1000;
    var coins = [];
    for (var i = 0; i < 26; i++) {
      coins.push({
        at: Math.random() * 0.5,
        vx: (Math.random() - 0.5) * 0.5,
        vy: -(0.85 + Math.random() * 0.55),        // up, hard
        g: 1.15,
        r: 14 + Math.random() * 14,
        spin: 4 + Math.random() * 7,
        phase: Math.random() * Math.PI * 2,
        glint: Math.random() * Math.PI * 2
      });
    }
    return { duration: duration, draw: function (ctx, W, H, t) {
      var fade = fadeInOut(t, dur, 0.4);
      coins.forEach(function (c) {
        var ct = t - c.at;
        if (ct < 0) return;
        var x = W / 2 + c.vx * ct * H;
        var y = H + 20 + (c.vy * ct + 0.5 * c.g * ct * ct) * H;
        if (y > H + 40 && ct > 0.5) return;
        var flip = Math.cos(c.spin * ct + c.phase);      // -1..1 → edge-on at 0
        var rx = Math.max(2, Math.abs(flip)) * c.r;
        ctx.save();
        ctx.translate(x, y);
        ctx.globalAlpha = fade;
        // Rim, face, and the embossed $ (only readable when facing us).
        ctx.fillStyle = '#8f6a10';
        ctx.beginPath(); ctx.ellipse(0, 0, rx, c.r, 0, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = flip > 0 ? '#ffd24a' : '#e3ac26';
        ctx.beginPath(); ctx.ellipse(0, 0, rx * 0.85, c.r * 0.85, 0, 0, Math.PI * 2); ctx.fill();
        if (Math.abs(flip) > 0.45) {
          ctx.fillStyle = '#8f6a10';
          ctx.font = '700 ' + Math.round(c.r * 1.1) + 'px sans-serif';
          ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
          ctx.save(); ctx.scale(Math.abs(flip), 1); ctx.fillText('$', 0, 1); ctx.restore();
        }
        // A glint sweeping the rim.
        var gl = Math.sin(t * 9 + c.glint);
        if (gl > 0.8) {
          ctx.globalAlpha = fade * (gl - 0.8) / 0.2;
          ctx.fillStyle = '#fff';
          circle(ctx, -rx * 0.4, -c.r * 0.5, c.r * 0.16);
        }
        ctx.restore();
      });
      ctx.globalAlpha = 1;
    }};
  }

  // ── 4. Fireworks ──────────────────────────────────────────────────────────
  // The hackleyclubz fireworks, trimmed to fit: a light scrim instead of a
  // blackout, seven rockets, shorter spark life.
  function fireworksMiniSpec() {
    var duration = 2900, life = 1.1, gravity = 300, dur = duration / 1000;
    var rockets = [];
    for (var i = 0; i < 7; i++) {
      var hue = Math.random(), n = 40 + Math.floor(Math.random() * 25), sparks = [];
      for (var j = 0; j < n; j++) sparks.push({
        angle: Math.random() * Math.PI * 2, speed: 130 + Math.random() * 200,
        size: 2 + Math.random() * 3, hueShift: (Math.random() - 0.5) * 0.16,
        twS: 14 + Math.random() * 16, twP: Math.random() * Math.PI * 2
      });
      rockets.push({
        launchAt: i * 0.16 + Math.random() * 0.1, x: 0.12 + Math.random() * 0.76,
        apexY: 0.15 + Math.random() * 0.35, rise: 0.3 + Math.random() * 0.2,
        hue: hue, sparks: sparks
      });
    }
    return { duration: duration, draw: function (ctx, W, H, t) {
      var fade = fadeInOut(t, dur, 0.5);
      ctx.fillStyle = 'rgba(6,10,28,' + (0.8 * fade) + ')';
      ctx.fillRect(0, 0, W, H);
      rockets.forEach(function (r) {
        var phase = t - r.launchAt; if (phase < 0) return;
        var ax = r.x * W, ay = r.apexY * H;
        if (phase < r.rise) {
          var e = 1 - Math.pow(1 - phase / r.rise, 2);
          ctx.globalAlpha = fade;
          ctx.fillStyle = hsl(r.hue, 60, 70);
          circle(ctx, ax, H * 1.02 + (ay - H * 1.02) * e, 4);
        } else {
          var te = phase - r.rise; if (te > life) return;
          var sparkFade = Math.max(0, 1 - te / life);
          if (te < 0.1) {
            ctx.globalAlpha = fade;
            ctx.fillStyle = 'rgba(255,255,255,.95)';
            circle(ctx, ax, ay, 40 * (1 - te / 0.1));
          }
          r.sparks.forEach(function (s) {
            var d = s.speed * te * (1 - Math.min(0.85, te * 0.5));
            var x = ax + Math.cos(s.angle) * d;
            var y = ay + Math.sin(s.angle) * d + 0.5 * gravity * te * te;
            var tw = 0.72 + 0.28 * Math.sin(te * s.twS + s.twP);
            ctx.globalAlpha = fade * sparkFade * tw;
            ctx.fillStyle = hsl(r.hue + s.hueShift, 90, 55);
            circle(ctx, x, y, s.size * 2.2);
            ctx.fillStyle = hsl(r.hue + s.hueShift, 90, 62);
            circle(ctx, x, y, s.size);
            ctx.fillStyle = '#fff';
            circle(ctx, x, y, s.size * 0.45);
          });
        }
      });
      ctx.globalAlpha = 1;
    }};
  }

  // ── 5. WHO'S AWESOME? YOU ARE!! ───────────────────────────────────────────
  // The question pops in and wobbles, then the answer slams down over it with
  // a star burst and a screen shake.
  function awesomeSpec() {
    var duration = 2800, dur = duration / 1000;
    var SLAM = 1.15;
    var stars = [];
    for (var i = 0; i < 26; i++) {
      stars.push({
        angle: Math.random() * Math.PI * 2,
        speed: 0.25 + Math.random() * 0.45,
        size: 14 + Math.random() * 20,
        spin: (Math.random() - 0.5) * 10,
        star: Math.random() < 0.6
      });
    }
    return { duration: duration, draw: function (ctx, W, H, t) {
      var fade = fadeInOut(t, dur, 0.4);
      var S = Math.min(W, H);
      ctx.fillStyle = 'rgba(30,10,50,' + (0.55 * fade) + ')';
      ctx.fillRect(0, 0, W, H);

      var st = t - SLAM;
      var shake = (st > 0 && st < 0.3) ? (1 - st / 0.3) : 0;
      var sx = Math.sin(t * 80) * 8 * shake, sy = Math.cos(t * 67) * 6 * shake;

      // The question, popping in with an overshoot and a taunting wobble.
      var qPop = Math.min(1, t / 0.25);
      var qScale = 1 + 0.35 * Math.sin(Math.min(1, qPop) * Math.PI)  // overshoot
                 + (qPop >= 1 ? 0.04 * Math.sin(t * 6) : 0);
      var qAlpha = fade * (st > 0 ? Math.max(0.35, 1 - st * 2) : 1);
      popText(ctx, "WHO'S AWESOME?", W / 2 + sx, H * 0.36 + sy, S * 0.072,
              '#ffe14a', '#6b21a8', qAlpha, qScale * qPop);

      // The star burst behind the slam.
      if (st > 0) {
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        stars.forEach(function (s) {
          var d = s.speed * st * S * 1.6 * (1 - Math.min(0.6, st * 0.5));
          var x = W / 2 + Math.cos(s.angle) * d;
          var y = H * 0.56 + Math.sin(s.angle) * d;
          ctx.globalAlpha = fade * Math.max(0, 1 - st / 1.2);
          if (s.star) {
            ctx.font = s.size + 'px sans-serif';
            ctx.save(); ctx.translate(x, y); ctx.rotate(s.spin * st);
            ctx.fillText('⭐', 0, 0); ctx.restore();
          } else {
            ctx.fillStyle = hsl(s.angle / (Math.PI * 2), 95, 62);
            circle(ctx, x, y, s.size * 0.3);
          }
        });
        ctx.globalAlpha = 1;

        // YOU ARE!! slams in huge.
        var slamPop = Math.min(1, st / 0.12);
        var slamScale = 1.6 - 0.6 * slamPop;             // lands from above
        popText(ctx, 'YOU ARE!!', W / 2 + sx, H * 0.58 + sy, S * 0.105,
                '#ff4a8d', '#ffffff', fade * slamPop, slamScale);
      }
    }};
  }

  // ── 6. Championship trophy ────────────────────────────────────────────────
  // Golden light rays wheel behind a trophy that rises to center with an
  // overshoot, sparkles twinkling around it, WINNER WINNER stamped above and
  // CHICKEN DINNER! slamming in below.
  function trophySpec() {
    var duration = 2900, dur = duration / 1000;
    var sparkles = [];
    for (var i = 0; i < 20; i++) {
      sparkles.push({
        x: 0.5 + (Math.random() - 0.5) * 0.5,
        y: 0.5 + (Math.random() - 0.5) * 0.55,
        at: 0.5 + Math.random() * 1.6,
        size: 10 + Math.random() * 16
      });
    }
    return { duration: duration, draw: function (ctx, W, H, t) {
      var fade = fadeInOut(t, dur, 0.5);
      var S = Math.min(W, H), cx = W / 2, cy = H * 0.55;
      ctx.fillStyle = 'rgba(20,12,2,' + (0.6 * fade) + ')';
      ctx.fillRect(0, 0, W, H);

      // The wheeling rays.
      var RAYS = 14, R = Math.sqrt(W * W + H * H) * 0.6;
      for (var r = 0; r < RAYS; r++) {
        var a0 = t * 0.35 + r * 2 * Math.PI / RAYS;
        ctx.globalAlpha = 0.16 * fade * (r % 2 ? 0.6 : 1);
        ctx.fillStyle = '#ffcf40';
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, R, a0, a0 + Math.PI / RAYS);
        ctx.closePath();
        ctx.fill();
      }
      ctx.globalAlpha = 1;

      // The trophy rises with an overshoot, then bobs proudly.
      var rise = Math.min(1, t / 0.55);
      var ez = 1 - Math.pow(1 - rise, 3);
      var overshoot = rise >= 1 ? 0 : Math.sin(rise * Math.PI) * 0.05;
      var ty = H * 1.15 + (cy - H * 1.15) * ez - overshoot * H
             + (rise >= 1 ? Math.sin(t * 2.4) * S * 0.01 : 0);
      ctx.globalAlpha = fade;
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.font = Math.round(S * 0.3) + 'px sans-serif';
      ctx.fillText('🏆', cx, ty);

      // Sparkles winking on around it.
      sparkles.forEach(function (sp) {
        var spt = t - sp.at;
        if (spt < 0 || spt > 0.7) return;
        var a = Math.sin((spt / 0.7) * Math.PI);
        ctx.globalAlpha = fade * a;
        ctx.font = sp.size + 'px sans-serif';
        ctx.fillText('✨', sp.x * W, sp.y * H);
      });
      ctx.globalAlpha = 1;

      // WINNER WINNER stamps in as the trophy lands...
      var wt = t - 0.6;
      if (wt > 0) {
        var pop = Math.min(1, wt / 0.15);
        popText(ctx, 'WINNER WINNER', cx, H * 0.2, S * 0.085,
                '#ffd24a', '#7a4a00', fade * pop, 1.5 - 0.5 * pop);
      }

      // ...and CHICKEN DINNER! slams in underneath, timed to the second half
      // of the announcer's line, with a drumstick on either side.
      var ct = t - 1.3;
      if (ct > 0) {
        var cpop = Math.min(1, ct / 0.15);
        popText(ctx, '🍗 CHICKEN DINNER! 🍗', cx, H * 0.86, S * 0.075,
                '#ffffff', '#b3261e', fade * cpop, 1.5 - 0.5 * cpop);
      }
    }};
  }

  // ── The rotation ──────────────────────────────────────────────────────────
  var CELEBRATIONS = [
    { sound: bellSound, spec: confettiSpec },
    { sound: trumpetSound, spec: trumpetSpec },
    { sound: chachingSound, spec: coinsSpec },
    { sound: fireworksSound, spec: fireworksMiniSpec },
    { sound: awesomeSound, spec: awesomeSpec },
    { sound: winnerSound, spec: trophySpec }
  ];

  // The cycle position survives page loads so consecutive rights across rounds
  // still rotate; storage failures just restart the cycle.
  var CYCLE_KEY = 'flashcards-celebration-idx';

  window.flashcardsCelebrate = function () {
    var idx = 0;
    try { idx = (parseInt(localStorage.getItem(CYCLE_KEY), 10) || 0) % CELEBRATIONS.length; } catch (e) {}
    var celebration = CELEBRATIONS[idx];
    try { localStorage.setItem(CYCLE_KEY, String((idx + 1) % CELEBRATIONS.length)); } catch (e) {}

    if (window.speechSynthesis) {
      try { speechSynthesis.cancel(); } catch (e) {}    // a fast Next shouldn't stack voices
    }
    if (audioCtx && audioCtx.state === 'running') {
      try { celebration.sound(); } catch (e) { /* the animation still plays */ }
    }
    play(celebration.spec());
  };
})();
