/**
 * Calling CRM — Ambient Nature FX (realistic motion + insect GIF sprites)
 * SVG creatures + optional images from insects/sprites/
 * Config: window.CRM_NATURE_FX = { enabled, frequency, types, assets }
 */
(function (window, document) {
  'use strict';

  var FREQ = {
    low:    { min: 25000, max: 50000,  maxConcurrent: 2 },
    medium: { min: 12000, max: 28000,  maxConcurrent: 2 },
    high:   { min: 6000,  max: 15000,  maxConcurrent: 3 }
  };

  var TYPE_POOL = {
    butterflies: ['butterfly'],
    fireflies:   ['firefly'],
    leaves:      ['leaf'],
    feathers:    ['feather'],
    birds:       ['bird'],
    insects:     ['insect'] // resolved to GIF/image sprites from config.assets
  };

  var BUTTERFLY_PALETTES = [
    { wing: '#e8784a', wing2: '#f5b895', spot: '#2a1f24', edge: '#c45a32' },
    { wing: '#6b8fd4', wing2: '#a8c0ef', spot: '#1e2430', edge: '#3d5a9e' },
    { wing: '#c45374', wing2: '#f0b4c8', spot: '#2f1820', edge: '#9a3d5c' },
    { wing: '#d4a017', wing2: '#f0d78c', spot: '#3a2a10', edge: '#a87810' },
    { wing: '#5a9e6b', wing2: '#b5d9bc', spot: '#1e2a20', edge: '#3d7048' },
    { wing: '#8b6bb5', wing2: '#d4c0ef', spot: '#241830', edge: '#5e4480' }
  ];

  var LEAF_COLORS = [
    { fill: '#6f9e4e', tip: '#c9b24a' },
    { fill: '#8fbc5a', tip: '#d4c86a' },
    { fill: '#c9a04a', tip: '#e8c878' },
    { fill: '#a86b3c', tip: '#d4a06a' },
    { fill: '#5a8f4a', tip: '#9ec46a' },
    { fill: '#d4784a', tip: '#efb08a' }
  ];

  function rand(min, max) {
    return min + Math.random() * (max - min);
  }

  function pick(arr) {
    return arr[Math.floor(Math.random() * arr.length)];
  }

  function clamp(n, a, b) {
    return Math.max(a, Math.min(b, n));
  }

  function easeInOutSine(t) {
    return -(Math.cos(Math.PI * t) - 1) / 2;
  }

  /* ---------- Realistic SVG builders ---------- */

  function svgButterfly() {
    var p = pick(BUTTERFLY_PALETTES);
    var id = 'bf' + Math.floor(rand(1, 99999));
    return (
      '<svg viewBox="0 0 80 64" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
        '<defs>' +
          '<radialGradient id="' + id + 'a" cx="35%" cy="40%" r="65%">' +
            '<stop offset="0%" stop-color="' + p.wing2 + '"/>' +
            '<stop offset="70%" stop-color="' + p.wing + '"/>' +
            '<stop offset="100%" stop-color="' + p.edge + '"/>' +
          '</radialGradient>' +
          '<radialGradient id="' + id + 'b" cx="65%" cy="40%" r="65%">' +
            '<stop offset="0%" stop-color="' + p.wing2 + '"/>' +
            '<stop offset="70%" stop-color="' + p.wing + '"/>' +
            '<stop offset="100%" stop-color="' + p.edge + '"/>' +
          '</radialGradient>' +
        '</defs>' +
        /* left wings — hinge at body */
        '<g class="nf-wing-l" style="transform-origin:40px 32px">' +
          '<path fill="url(#' + id + 'a)" opacity="0.92" d="M38 28 C22 8 4 10 6 26 C8 38 22 42 38 34 Z"/>' +
          '<path fill="url(#' + id + 'a)" opacity="0.88" d="M38 34 C20 38 8 48 12 56 C20 60 32 52 38 40 Z"/>' +
          '<circle cx="16" cy="22" r="2.2" fill="' + p.spot + '" opacity="0.35"/>' +
          '<circle cx="20" cy="48" r="1.6" fill="' + p.spot + '" opacity="0.3"/>' +
        '</g>' +
        '<g class="nf-wing-r" style="transform-origin:40px 32px">' +
          '<path fill="url(#' + id + 'b)" opacity="0.9" d="M42 28 C58 8 76 10 74 26 C72 38 58 42 42 34 Z"/>' +
          '<path fill="url(#' + id + 'b)" opacity="0.86" d="M42 34 C60 38 72 48 68 56 C60 60 48 52 42 40 Z"/>' +
          '<circle cx="64" cy="22" r="2.2" fill="' + p.spot + '" opacity="0.35"/>' +
          '<circle cx="60" cy="48" r="1.6" fill="' + p.spot + '" opacity="0.3"/>' +
        '</g>' +
        /* body + head + antennae */
        '<ellipse cx="40" cy="34" rx="3.2" ry="13" fill="#2a2228"/>' +
        '<ellipse cx="40" cy="34" rx="2" ry="11" fill="#4a3e46" opacity="0.5"/>' +
        '<circle cx="40" cy="20" r="3.4" fill="#2a2228"/>' +
        '<circle cx="38.5" cy="19" r="0.7" fill="#eee" opacity="0.7"/>' +
        '<circle cx="41.5" cy="19" r="0.7" fill="#eee" opacity="0.7"/>' +
        '<path d="M38 17 Q32 6 28 8" stroke="#2a2228" stroke-width="1.1" fill="none" stroke-linecap="round"/>' +
        '<path d="M42 17 Q48 6 52 8" stroke="#2a2228" stroke-width="1.1" fill="none" stroke-linecap="round"/>' +
        '<circle cx="28" cy="8" r="1.1" fill="#2a2228"/>' +
        '<circle cx="52" cy="8" r="1.1" fill="#2a2228"/>' +
      '</svg>'
    );
  }

  function svgLeaf() {
    var c = pick(LEAF_COLORS);
    var id = 'lf' + Math.floor(rand(1, 99999));
    return (
      '<svg viewBox="0 0 48 72" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
        '<defs>' +
          '<linearGradient id="' + id + '" x1="0%" y1="0%" x2="100%" y2="100%">' +
            '<stop offset="0%" stop-color="' + c.tip + '"/>' +
            '<stop offset="45%" stop-color="' + c.fill + '"/>' +
            '<stop offset="100%" stop-color="#4a6a38"/>' +
          '</linearGradient>' +
        '</defs>' +
        '<path fill="url(#' + id + ')" opacity="0.9" d="M24 4 C38 14 44 34 26 68 C24 70 24 70 22 68 C4 34 10 14 24 4Z"/>' +
        '<path d="M24 10 L24 62" stroke="#3d5230" stroke-width="1.4" opacity="0.45" fill="none"/>' +
        '<path d="M24 22 Q34 28 36 34 M24 34 Q14 40 12 46 M24 46 Q32 50 34 54" ' +
          'stroke="#3d5230" stroke-width="0.9" opacity="0.35" fill="none"/>' +
        '<path d="M22 68 L24 72 L26 68" fill="#5a4a30" opacity="0.55"/>' +
      '</svg>'
    );
  }

  function svgFeather() {
    var base = pick(['#f7eef2', '#efe4d8', '#e8dce6', '#f2ebe3', '#ddd0d8']);
    var tip = pick(['#c4a8b4', '#b8a090', '#a8909c', '#d0c0b0']);
    var id = 'ft' + Math.floor(rand(1, 99999));
    return (
      '<svg viewBox="0 0 36 80" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
        '<defs>' +
          '<linearGradient id="' + id + '" x1="0%" y1="0%" x2="0%" y2="100%">' +
            '<stop offset="0%" stop-color="' + tip + '"/>' +
            '<stop offset="40%" stop-color="' + base + '"/>' +
            '<stop offset="100%" stop-color="#fff" stop-opacity="0.85"/>' +
          '</linearGradient>' +
        '</defs>' +
        '<path fill="url(#' + id + ')" opacity="0.82" d="M18 2 C28 16 30 40 20 76 C18 78 18 78 16 76 C6 40 8 16 18 2Z"/>' +
        '<path d="M18 6 L18 74" stroke="#a8909a" stroke-width="1.1" opacity="0.55" fill="none"/>' +
        '<path d="M18 14 Q28 20 30 26 M18 22 Q8 28 6 34 M18 30 Q28 36 29 42 M18 38 Q9 44 7 50 M18 46 Q26 52 27 56 M18 54 Q11 60 10 64" ' +
          'stroke="#b8a0aa" stroke-width="0.75" opacity="0.42" fill="none"/>' +
      '</svg>'
    );
  }

  function svgFirefly() {
    var glow = pick(['#ffe566', '#fff1a8', '#ffd34a', '#e8ff9a']);
    var id = 'ff' + Math.floor(rand(1, 99999));
    return (
      '<svg viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
        '<defs>' +
          '<radialGradient id="' + id + '" cx="50%" cy="50%" r="50%">' +
            '<stop offset="0%" stop-color="#fffef0" stop-opacity="1"/>' +
            '<stop offset="35%" stop-color="' + glow + '" stop-opacity="0.85"/>' +
            '<stop offset="70%" stop-color="' + glow + '" stop-opacity="0.25"/>' +
            '<stop offset="100%" stop-color="' + glow + '" stop-opacity="0"/>' +
          '</radialGradient>' +
        '</defs>' +
        '<circle class="nf-glow" cx="20" cy="22" r="16" fill="url(#' + id + ')"/>' +
        /* tiny body */
        '<ellipse cx="20" cy="18" rx="2.2" ry="4.5" fill="#3a4a28" opacity="0.75"/>' +
        '<circle cx="20" cy="13.5" r="1.8" fill="#2a3220" opacity="0.8"/>' +
        '<ellipse class="nf-glow-core" cx="20" cy="24" rx="3.5" ry="4" fill="' + glow + '" opacity="0.95"/>' +
        '<path d="M18 16 L12 12 M22 16 L28 12" stroke="#3a4a28" stroke-width="0.7" opacity="0.5" fill="none"/>' +
      '</svg>'
    );
  }

  function svgBird() {
    var body = pick(['#5c4a52', '#6a5a48', '#4a5560', '#6b5560', '#7a6a58']);
    var wing = pick(['#3a3238', '#4a4038', '#2e3540', '#504048']);
    return (
      '<svg viewBox="0 0 72 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
        /* silhouette sparrow-like in flight */
        '<g class="nf-wing-l" style="transform-origin:36px 20px">' +
          '<path fill="' + wing + '" opacity="0.85" d="M34 20 C22 8 8 6 4 14 C10 18 22 22 34 20Z"/>' +
        '</g>' +
        '<g class="nf-wing-r" style="transform-origin:36px 20px">' +
          '<path fill="' + wing + '" opacity="0.8" d="M38 20 C50 8 64 6 68 14 C62 18 50 22 38 20Z"/>' +
        '</g>' +
        '<ellipse cx="36" cy="21" rx="9" ry="4.2" fill="' + body + '" opacity="0.92"/>' +
        '<ellipse cx="46" cy="19" rx="4.5" ry="3.2" fill="' + body + '" opacity="0.92"/>' +
        '<path d="M50 18 L56 17 L50 20Z" fill="#e8a050" opacity="0.9"/>' +
        '<path d="M28 22 L24 28 L30 23Z" fill="' + wing + '" opacity="0.7"/>' +
        '<circle cx="48" cy="17.5" r="0.8" fill="#1a1210"/>' +
      '</svg>'
    );
  }

  function svgLadybug() {
    return (
      '<svg viewBox="0 0 44 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
        /* legs */
        '<g stroke="#2a2228" stroke-width="1.3" fill="none" stroke-linecap="round" opacity="0.75">' +
          '<path class="nf-leg" d="M14 22 L6 18 M14 26 L5 28 M16 30 L10 36"/>' +
          '<path class="nf-leg" d="M30 22 L38 18 M30 26 L39 28 M28 30 L34 36"/>' +
        '</g>' +
        /* body */
        '<ellipse cx="22" cy="24" rx="14" ry="11" fill="#d44545"/>' +
        '<ellipse cx="22" cy="24" rx="14" ry="11" fill="none" stroke="#a03030" stroke-width="0.6" opacity="0.4"/>' +
        '<path d="M22 13 L22 35" stroke="#2a2228" stroke-width="1.4"/>' +
        '<circle cx="15" cy="20" r="2.3" fill="#2a2228"/>' +
        '<circle cx="29" cy="20" r="2.3" fill="#2a2228"/>' +
        '<circle cx="14" cy="28" r="2" fill="#2a2228"/>' +
        '<circle cx="30" cy="28" r="2" fill="#2a2228"/>' +
        '<circle cx="22" cy="30" r="1.5" fill="#2a2228" opacity="0.8"/>' +
        /* head */
        '<ellipse cx="22" cy="13" rx="7.5" ry="5.5" fill="#2a2228"/>' +
        '<circle cx="19" cy="12" r="1" fill="#eee" opacity="0.5"/>' +
        '<circle cx="25" cy="12" r="1" fill="#eee" opacity="0.5"/>' +
        '<path d="M17 9 Q14 2 12 4 M27 9 Q30 2 32 4" stroke="#2a2228" stroke-width="1.2" fill="none" stroke-linecap="round"/>' +
        /* highlight */
        '<ellipse cx="16" cy="20" rx="3" ry="2" fill="#fff" opacity="0.18"/>' +
      '</svg>'
    );
  }

  function svgDragonfly() {
    var wing = pick(['#7eb8c9', '#9ad0dc', '#a8c8b8', '#8ec4d4']);
    var body = pick(['#3d5a4a', '#4a6a58', '#3a5560', '#2e4a40']);
    return (
      '<svg viewBox="0 0 96 40" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' +
        '<g class="nf-wing nf-wing-tl" style="transform-origin:48px 18px">' +
          '<ellipse cx="30" cy="10" rx="22" ry="7" fill="' + wing + '" opacity="0.35" stroke="' + wing + '" stroke-width="0.6"/>' +
          '<path d="M14 10 Q30 6 46 10" stroke="#fff" stroke-width="0.5" opacity="0.35" fill="none"/>' +
        '</g>' +
        '<g class="nf-wing nf-wing-tr" style="transform-origin:48px 18px">' +
          '<ellipse cx="66" cy="10" rx="22" ry="7" fill="' + wing + '" opacity="0.32" stroke="' + wing + '" stroke-width="0.6"/>' +
        '</g>' +
        '<g class="nf-wing nf-wing-bl" style="transform-origin:48px 22px">' +
          '<ellipse cx="32" cy="28" rx="18" ry="5.5" fill="' + wing + '" opacity="0.3" stroke="' + wing + '" stroke-width="0.5"/>' +
        '</g>' +
        '<g class="nf-wing nf-wing-br" style="transform-origin:48px 22px">' +
          '<ellipse cx="64" cy="28" rx="18" ry="5.5" fill="' + wing + '" opacity="0.28" stroke="' + wing + '" stroke-width="0.5"/>' +
        '</g>' +
        /* segmented abdomen */
        '<ellipse cx="28" cy="20" rx="18" ry="3.2" fill="' + body + '"/>' +
        '<ellipse cx="48" cy="20" rx="8" ry="4" fill="' + body + '"/>' +
        '<circle cx="60" cy="20" r="4.5" fill="' + body + '"/>' +
        '<circle cx="62" cy="18.5" r="1.1" fill="#1a2028"/>' +
        '<circle cx="58" cy="18.5" r="1.1" fill="#1a2028"/>' +
        '<path d="M64 17 Q70 12 72 14 M64 17 Q70 22 72 20" stroke="' + body + '" stroke-width="1" fill="none"/>' +
      '</svg>'
    );
  }

  function pickWeighted(assets) {
    if (!assets || !assets.length) return null;
    var total = 0;
    var i;
    for (i = 0; i < assets.length; i++) total += (assets[i].weight || 1);
    var r = Math.random() * total;
    for (i = 0; i < assets.length; i++) {
      r -= (assets[i].weight || 1);
      if (r <= 0) return assets[i];
    }
    return assets[assets.length - 1];
  }

  function buildSprite(asset) {
    var blend = asset.blend || 'multiply';
    return (
      '<img class="nf-sprite nf-blend-' + blend + '" src="' + asset.src + '" ' +
      'alt="" draggable="false" decoding="async">'
    );
  }

  function buildSvg(kind) {
    switch (kind) {
      case 'butterfly': return svgButterfly();
      case 'leaf':      return svgLeaf();
      case 'feather':   return svgFeather();
      case 'firefly':   return svgFirefly();
      case 'bird':      return svgBird();
      case 'ladybug':   return svgLadybug();
      case 'dragonfly': return svgDragonfly();
      default:          return svgButterfly();
    }
  }

  /* ---------- Organic path planning ---------- */

  function planMotion(kind, vw, vh, asset) {
    var motionType = kind;
    if (kind === 'insect' && asset) {
      motionType = asset.motion || 'fly';
    }

    var fromLeft = Math.random() > 0.5;
    // Force travel direction: ltr/from = left→right, rtl/to = right→left, random = either
    if (asset) {
      var dir = String(asset.direction || '').toLowerCase();
      if (dir === 'rtl' || dir === 'to') {
        fromLeft = false;
      } else if (dir === 'ltr' || dir === 'from') {
        fromLeft = true;
      }
      // random / empty → keep Math.random()
    }
    var p = {
      kind: kind,
      motionType: motionType,
      asset: asset || null,
      fromLeft: fromLeft,
      x0: 0, y0: 0, x1: 0, y1: 0, x2: 0, y2: 0, x3: 0, y3: 0,
      duration: 6000,
      sizeW: 48,
      sizeH: 48,
      opacity: 0.55,
      followPath: true,
      tumble: 0,
      swayAmp: 0,
      bobAmp: 0,
      bobFreq: 3,
      bank: 12,
      flapVar: 1,
      faceOffset: 0,
      keepUpright: false
    };

    if (kind === 'insect' && asset) {
      var scale = rand(0.85, 1.15);
      p.sizeW = Math.round((asset.w || 56) * scale);
      p.sizeH = Math.round((asset.h || 48) * scale);
      p.opacity = (asset.opacity || 0.7) * rand(0.9, 1.05);

      if (motionType === 'crawl') {
        // Ladybug: diagonal top-left → bottom-right
        if (asset.path === 'diag-tl-br' || asset.id === 'ladybug') {
          p.fromLeft = true;
          p.x0 = -50;
          p.y0 = rand(0.04, 0.14) * vh;
          p.x3 = vw + 50;
          p.y3 = rand(0.78, 0.94) * vh;
          p.x1 = vw * 0.32;
          p.y1 = vh * rand(0.28, 0.4);
          p.x2 = vw * 0.68;
          p.y2 = vh * rand(0.55, 0.7);
          p.duration = rand(18000, 28000);
          p.followPath = true;
          p.bank = 4;
          p.bobAmp = 1.2;
          p.bobFreq = 10;
          p.keepUpright = true;
        } else {
          p.x0 = fromLeft ? -60 : vw + 60;
          p.y0 = rand(0.55, 0.9) * vh;
          p.x3 = fromLeft ? vw + 60 : -60;
          p.y3 = p.y0 + rand(-40, 25);
          p.x1 = (p.x0 + p.x3) * 0.33;
          p.y1 = p.y0 + rand(-20, 12);
          p.x2 = (p.x0 + p.x3) * 0.66;
          p.y2 = p.y3 + rand(-15, 15);
          p.duration = rand(18000, 28000);
          p.followPath = true;
          p.bank = 4;
          p.bobAmp = 1.5;
          p.bobFreq = 10;
          if (asset.direction === 'rtl' || asset.direction === 'ltr' || asset.direction === 'from' || asset.direction === 'to' || asset.custom || asset.id === 'beetle') {
            p.keepUpright = true;
          }
        }
      } else if (motionType === 'hop') {
        // Arc jumps — bottom zone for hopper-jump, otherwise lower half
        var hopYMin = 0.55;
        var hopYMax = 0.82;
        if (asset.zone === 'bottom' || asset.id === 'hopper-jump') {
          hopYMin = 0.78;
          hopYMax = 0.92;
        }
        p.x0 = fromLeft ? -70 : vw + 70;
        p.y0 = rand(hopYMin, hopYMax) * vh;
        p.x3 = fromLeft ? vw + 70 : -70;
        p.y3 = clamp(p.y0 + rand(-20, 25), vh * hopYMin, vh * 0.95);
        p.x1 = (p.x0 + p.x3) * 0.35;
        p.y1 = Math.min(p.y0, p.y3) - rand(50, 100); // hop peak
        p.x2 = (p.x0 + p.x3) * 0.7;
        p.y2 = Math.min(p.y0, p.y3) - rand(25, 60);
        p.duration = rand(14000, 22000);
        p.followPath = true;
        p.bank = 10;
        p.bobAmp = 0;
        p.swayAmp = 4;
        // Keep GIF upright; only path direction changes
        if (asset.direction === 'rtl' || asset.direction === 'ltr' || asset.direction === 'from' || asset.direction === 'to' || asset.custom || asset.id === 'hopper-jump' || asset.id === 'cricket') {
          p.keepUpright = true;
        }
      } else {
        // fly — mosquitoes / flying bugs
        p.x0 = fromLeft ? -80 : vw + 80;
        p.y0 = rand(0.1, 0.55) * vh;
        p.x3 = fromLeft ? vw + 80 : -80;
        p.y3 = clamp(p.y0 + rand(-0.18, 0.22) * vh, 40, vh * 0.7);
        p.x1 = (p.x0 + p.x3) * 0.3 + rand(-50, 50);
        p.y1 = p.y0 + rand(-90, 70);
        p.x2 = (p.x0 + p.x3) * 0.7 + rand(-50, 50);
        p.y2 = p.y3 + rand(-70, 90);
        p.duration = rand(16000, 26000);
        p.followPath = true;
        p.bank = 14;
        p.bobAmp = rand(8, 16);
        p.bobFreq = rand(6, 11);
        p.swayAmp = rand(8, 18);
        // Keep GIF facing as-drawn; only the path goes LTR/RTL
        if (asset.direction === 'rtl' || asset.direction === 'ltr' || asset.direction === 'from' || asset.direction === 'to' || asset.custom || asset.id === 'mosquito2' || asset.id === 'bug-fly') {
          p.keepUpright = true;
        }
      }
      return p;
    }

    if (kind === 'leaf') {
      p.x0 = rand(0.08, 0.92) * vw;
      p.y0 = rand(-60, 40);
      p.x3 = p.x0 + rand(-0.35, 0.35) * vw;
      p.y3 = vh + rand(40, 100);
      p.x1 = p.x0 + rand(-120, 120);
      p.y1 = vh * rand(0.25, 0.4);
      p.x2 = p.x3 + rand(-100, 100);
      p.y2 = vh * rand(0.55, 0.75);
      p.duration = rand(14000, 22000);
      p.sizeW = rand(28, 46);
      p.sizeH = p.sizeW * 1.45;
      p.opacity = rand(0.45, 0.7);
      p.followPath = false;
      p.tumble = rand(280, 520) * (Math.random() > 0.5 ? 1 : -1);
      p.swayAmp = rand(18, 42);
      p.bobAmp = 0;
    } else if (kind === 'feather') {
      p.x0 = rand(0.1, 0.9) * vw;
      p.y0 = rand(-40, 30);
      p.x3 = p.x0 + rand(-0.2, 0.2) * vw;
      p.y3 = vh + rand(30, 80);
      p.x1 = p.x0 + rand(-90, 90);
      p.y1 = vh * rand(0.2, 0.35);
      p.x2 = p.x3 + rand(-70, 70);
      p.y2 = vh * rand(0.6, 0.8);
      p.duration = rand(16000, 26000);
      p.sizeW = rand(22, 36);
      p.sizeH = p.sizeW * 2.1;
      p.opacity = rand(0.38, 0.58);
      p.followPath = false;
      p.tumble = rand(60, 160) * (Math.random() > 0.5 ? 1 : -1);
      p.swayAmp = rand(28, 55);
    } else if (kind === 'firefly') {
      p.x0 = rand(0.15, 0.85) * vw;
      p.y0 = rand(0.2, 0.7) * vh;
      p.x3 = clamp(p.x0 + rand(-0.3, 0.3) * vw, 40, vw - 40);
      p.y3 = clamp(p.y0 + rand(-0.25, 0.25) * vh, 40, vh - 40);
      p.x1 = p.x0 + rand(-100, 100);
      p.y1 = p.y0 + rand(-80, 80);
      p.x2 = p.x3 + rand(-100, 100);
      p.y2 = p.y3 + rand(-80, 80);
      p.duration = rand(12000, 20000);
      p.sizeW = rand(22, 36);
      p.sizeH = p.sizeW;
      p.opacity = rand(0.5, 0.8);
      p.followPath = false;
      p.swayAmp = rand(10, 22);
      p.bobAmp = rand(8, 16);
      p.bobFreq = rand(4, 7);
    } else if (kind === 'ladybug') {
      p.x0 = fromLeft ? -50 : vw + 50;
      p.y0 = rand(0.6, 0.88) * vh;
      p.x3 = fromLeft ? vw + 50 : -50;
      p.y3 = p.y0 + rand(-50, 30);
      p.x1 = (p.x0 + p.x3) * 0.33;
      p.y1 = p.y0 + rand(-25, 15);
      p.x2 = (p.x0 + p.x3) * 0.66;
      p.y2 = p.y3 + rand(-20, 20);
      p.duration = rand(16000, 26000);
      p.sizeW = rand(26, 38);
      p.sizeH = p.sizeW * 0.9;
      p.opacity = rand(0.55, 0.75);
      p.followPath = true;
      p.bank = 6;
      p.bobAmp = 2;
      p.bobFreq = 8;
    } else if (kind === 'bird') {
      p.x0 = fromLeft ? -70 : vw + 70;
      p.y0 = rand(0.08, 0.4) * vh;
      p.x3 = fromLeft ? vw + 70 : -70;
      p.y3 = clamp(p.y0 + rand(-0.12, 0.18) * vh, 30, vh * 0.55);
      p.x1 = (p.x0 + p.x3) * 0.35;
      p.y1 = Math.min(p.y0, p.y3) - rand(20, 80);
      p.x2 = (p.x0 + p.x3) * 0.7;
      p.y2 = Math.max(p.y0, p.y3) + rand(-30, 50);
      p.duration = rand(12000, 20000);
      p.sizeW = rand(48, 72);
      p.sizeH = p.sizeW * 0.55;
      p.opacity = rand(0.4, 0.62);
      p.followPath = true;
      p.bank = 18;
      p.bobAmp = 4;
      p.bobFreq = 2;
      p.flapVar = rand(0.85, 1.2);
    } else if (kind === 'dragonfly') {
      p.x0 = fromLeft ? -80 : vw + 80;
      p.y0 = rand(0.12, 0.5) * vh;
      p.x3 = fromLeft ? vw + 80 : -80;
      p.y3 = p.y0 + rand(-0.1, 0.15) * vh;
      p.x1 = (p.x0 + p.x3) * 0.3 + rand(-40, 40);
      p.y1 = p.y0 + rand(-60, 40);
      p.x2 = (p.x0 + p.x3) * 0.65 + rand(-40, 40);
      p.y2 = p.y3 + rand(-40, 60);
      p.duration = rand(12000, 20000);
      p.sizeW = rand(56, 84);
      p.sizeH = p.sizeW * 0.42;
      p.opacity = rand(0.42, 0.62);
      p.followPath = true;
      p.bank = 8;
      p.bobAmp = 3;
      p.bobFreq = 5;
    } else {
      p.x0 = fromLeft ? -70 : vw + 70;
      p.y0 = rand(0.1, 0.55) * vh;
      p.x3 = fromLeft ? vw + 70 : -70;
      p.y3 = clamp(p.y0 + rand(-0.2, 0.25) * vh, 40, vh * 0.7);
      p.x1 = (p.x0 + p.x3) * 0.3 + rand(-60, 60);
      p.y1 = p.y0 + rand(-100, 80);
      p.x2 = (p.x0 + p.x3) * 0.7 + rand(-60, 60);
      p.y2 = p.y3 + rand(-80, 100);
      p.duration = rand(14000, 24000);
      p.sizeW = rand(42, 68);
      p.sizeH = p.sizeW * 0.8;
      p.opacity = rand(0.5, 0.72);
      p.followPath = true;
      p.bank = 22;
      p.bobAmp = rand(12, 22);
      p.bobFreq = rand(5, 9);
      p.flapVar = rand(0.7, 1.3);
      p.swayAmp = rand(6, 14);
    }

    return p;
  }

  function cubic(t, a, b, c, d) {
    var u = 1 - t;
    return u * u * u * a + 3 * u * u * t * b + 3 * u * t * t * c + t * t * t * d;
  }

  function cubicDeriv(t, a, b, c, d) {
    var u = 1 - t;
    return 3 * u * u * (b - a) + 6 * u * t * (c - b) + 3 * t * t * (d - c);
  }

  /* ---------- Engine ---------- */

  var NatureFx = {
    config: null,
    layer: null,
    timer: null,
    active: 0,
    kinds: [],
    assets: [],
    paused: false,
    reduced: false,
    preloaded: false,

    init: function (cfg) {
      if (!cfg || !cfg.enabled) return;
      if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        this.reduced = true;
        return;
      }
      if (window.innerWidth < 480 && (navigator.hardwareConcurrency || 4) <= 2) {
        cfg.frequency = 'low';
      }

      this.config = cfg;
      this.assets = Array.isArray(cfg.assets) ? cfg.assets : [];
      this.kinds = this.resolveKinds(cfg.types || []);
      if (!this.kinds.length) return;

      this.preloadAssets();
      this.ensureLayer();
      this.bindVisibility();
      // Show something soon on every page, then continue on the normal schedule
      this.scheduleNext(rand(1500, 3500));
      var self = this;
      setTimeout(function () {
        if (!self.paused && !self.reduced) {
          self.spawnBurst();
        }
      }, rand(1200, 2500));
    },

    preloadAssets: function () {
      if (this.preloaded || !this.assets.length) return;
      this.preloaded = true;
      var i, img;
      for (i = 0; i < this.assets.length; i++) {
        img = new Image();
        img.decoding = 'async';
        img.src = this.assets[i].src;
      }
    },

    resolveKinds: function (types) {
      var out = [];
      var i, pool;
      var hasInsects = false;
      for (i = 0; i < types.length; i++) {
        if (types[i] === 'insects' && this.assets && this.assets.length) {
          hasInsects = true;
          // Strong weight so customized GIF insects show on every page
          out.push('insect', 'insect', 'insect', 'insect', 'insect', 'insect');
          continue;
        }
        pool = TYPE_POOL[types[i]];
        if (pool) out = out.concat(pool);
      }
      if (!out.length && types.indexOf('insects') !== -1) {
        out = ['ladybug', 'dragonfly'];
      }
      // If "all" is on and we have sprites, prefer insects over SVG fillers
      if (hasInsects && out.length > 6) {
        // keep insect entries + a light mix of other kinds
        var others = [];
        for (i = 0; i < out.length; i++) {
          if (out[i] !== 'insect') others.push(out[i]);
        }
        out = ['insect', 'insect', 'insect', 'insect', 'insect', 'insect'];
        if (others.length) {
          out.push(others[0]);
          if (others.length > 1) out.push(others[1]);
        }
      }
      return out;
    },

    ensureLayer: function () {
      var el = document.getElementById('crm-nature-fx');
      if (!el) {
        el = document.createElement('div');
        el.id = 'crm-nature-fx';
        el.setAttribute('aria-hidden', 'true');
        document.body.appendChild(el);
      }
      this.layer = el;
    },

    bindVisibility: function () {
      var self = this;
      document.addEventListener('visibilitychange', function () {
        self.paused = document.hidden;
        if (!document.hidden && !self.timer) {
          self.scheduleNext(rand(2500, 7000));
        }
      });
    },

    freqOpts: function () {
      return FREQ[this.config.frequency] || FREQ.medium;
    },

    scheduleNext: function (delay) {
      var self = this;
      if (this.timer) clearTimeout(this.timer);
      var opts = this.freqOpts();
      var wait = typeof delay === 'number' ? delay : rand(opts.min, opts.max);
      this.timer = setTimeout(function () {
        self.timer = null;
        if (!self.paused && !self.reduced) self.spawnBurst();
        self.scheduleNext();
      }, wait);
    },

    spawnBurst: function () {
      var opts = this.freqOpts();
      var slots = opts.maxConcurrent - this.active;
      if (slots <= 0) return;
      var count = 1 + (slots > 1 && Math.random() > 0.55 ? 1 : 0);
      count = Math.min(count, slots);
      for (var i = 0; i < count; i++) this.spawnOne();
    },

    /**
     * Play every insect sprite across the screen (for Settings review).
     * Staggers them on different lanes so you can see each one.
     */
    previewAllInsects: function () {
      var self = this;
      var list = this.assets && this.assets.length ? this.assets.slice() : [];
      if (!list.length) {
        this.ensureLayer();
        this.spawnOne();
        return;
      }
      this.ensureLayer();
      this.preloadAssets();
      this.showPreviewBanner('Playing all ' + list.length + ' insects…');

      var i;
      for (i = 0; i < list.length; i++) {
        (function (asset, index) {
          setTimeout(function () {
            self.showPreviewBanner(
              (index + 1) + '/' + list.length + ' · ' + (asset.id || 'insect') +
              ' · ' + (asset.motion || 'fly')
            );
            self.spawnInsect(asset, {
              lane: index,
              total: list.length,
              preview: true
            });
          }, index * 2200);
        })(list[i], i);
      }

      setTimeout(function () {
        self.hidePreviewBanner();
      }, list.length * 2200 + 18000);
    },

    showPreviewBanner: function (text) {
      var el = document.getElementById('crm-nature-fx-banner');
      if (!el) {
        el = document.createElement('div');
        el.id = 'crm-nature-fx-banner';
        el.setAttribute('aria-live', 'polite');
        document.body.appendChild(el);
      }
      el.textContent = text;
      el.className = 'nf-preview-banner is-on';
    },

    hidePreviewBanner: function () {
      var el = document.getElementById('crm-nature-fx-banner');
      if (el) el.className = 'nf-preview-banner';
    },

    /** Spawn one specific insect asset (optional preview lane layout). */
    spawnInsect: function (asset, opts) {
      if (!asset) return;
      this.ensureLayer();
      opts = opts || {};
      this.spawnOne({
        kind: 'insect',
        asset: asset,
        preview: !!opts.preview,
        lane: opts.lane || 0,
        total: opts.total || 1
      });
    },

    spawnOne: function (forced) {
      if (!this.layer) this.ensureLayer();
      if (!this.layer) return;

      forced = forced || null;
      var kind = forced && forced.kind ? forced.kind : pick(this.kinds.length ? this.kinds : ['insect']);
      var asset = forced && forced.asset ? forced.asset : null;

      if (!asset && kind === 'insect') {
        asset = pickWeighted(this.assets);
        if (!asset) {
          kind = pick(['ladybug', 'dragonfly']);
        }
      }

      var vw = window.innerWidth;
      var vh = window.innerHeight;
      var motion = planMotion(kind, vw, vh, asset);
      var motionType = motion.motionType || kind;

      // Preview: spread insects on clear horizontal lanes; honor sprite direction / path
      if (forced && forced.preview && asset) {
        var total = Math.max(1, forced.total || 1);
        var lane = forced.lane || 0;
        var yLane;

        if (asset.path === 'diag-tl-br' || asset.id === 'ladybug') {
          motion.fromLeft = true;
          motion.keepUpright = true;
          motion.x0 = -50;
          motion.y0 = vh * 0.08;
          motion.x1 = vw * 0.33;
          motion.y1 = vh * 0.35;
          motion.x2 = vw * 0.66;
          motion.y2 = vh * 0.65;
          motion.x3 = vw + 50;
          motion.y3 = vh * 0.88;
          motion.duration = rand(16000, 20000);
          motion.opacity = Math.min(0.95, (asset.opacity || 0.75) + 0.15);
          motion.sizeW = Math.round((asset.w || 56) * 1.15);
          motion.sizeH = Math.round((asset.h || 48) * 1.15);
        } else {
          if (asset.zone === 'bottom' || asset.id === 'hopper-jump' || asset.id === 'cricket') {
            yLane = vh * 0.85;
          } else {
            yLane = vh * (0.12 + (lane % total) * (0.7 / total));
          }
          if (asset.direction === 'rtl' || asset.direction === 'to') {
            motion.fromLeft = false;
          } else if (asset.direction === 'ltr' || asset.direction === 'from') {
            motion.fromLeft = true;
          } else {
            motion.fromLeft = Math.random() > 0.5;
          }
          if (motion.fromLeft) {
            motion.x0 = -80;
            motion.x3 = vw + 80;
            motion.x1 = vw * 0.33;
            motion.x2 = vw * 0.66;
          } else {
            motion.x0 = vw + 80;
            motion.x3 = -80;
            motion.x1 = vw * 0.66;
            motion.x2 = vw * 0.33;
          }
          motion.y0 = yLane;
          motion.y3 = yLane + rand(-20, 20);
          motion.y1 = yLane - (motionType === 'hop' ? 70 : 30);
          motion.y2 = yLane - (motionType === 'hop' ? 40 : 10);
          motion.duration = rand(14000, 18000);
          motion.opacity = Math.min(0.95, (asset.opacity || 0.75) + 0.15);
          motion.sizeW = Math.round((asset.w || 56) * 1.15);
          motion.sizeH = Math.round((asset.h || 48) * 1.15);
          if (asset.direction === 'rtl' || asset.direction === 'ltr' || asset.direction === 'from' || asset.direction === 'to' || asset.custom || asset.id === 'mosquito2' || asset.id === 'bug-fly' || asset.id === 'hopper-jump' || asset.id === 'cricket' || asset.id === 'beetle') {
            motion.keepUpright = true;
          }
        }
      }

      var el = document.createElement('div');
      el.className = 'nf-actor nf-' + (asset ? 'sprite nf-motion-' + motionType : kind);
      if (asset) {
        el.className += ' nf-id-' + (asset.id || 'bug');
        // Soft shadow only for cut-out sprites (multiply/screen). Opaque white-box GIFs
        // get a rectangular drop-shadow that looks like a bottom border.
        var blendMode = String(asset.blend || 'multiply').toLowerCase();
        if (!asset.custom && blendMode !== 'normal') {
          el.className += ' nf-has-shadow';
        }
      }
      if (forced && forced.preview) {
        el.className += ' nf-previewing';
      }
      el.style.width = motion.sizeW + 'px';
      el.style.height = motion.sizeH + 'px';
      el.style.opacity = '0';
      if (motion.flapVar && motion.flapVar !== 1) {
        el.style.setProperty('--nf-flap', motion.flapVar.toFixed(2));
      }

      // Name is already in the top preview banner — avoid a dark pill under the GIF
      el.innerHTML = asset ? buildSprite(asset) : buildSvg(kind);

      this.layer.appendChild(el);
      this.active += 1;

      var self = this;
      var start = performance.now();
      var blinkPhase = rand(0, Math.PI * 2);
      var lastAngle = motion.fromLeft ? 0 : 180;

      function frame(now) {
        var rawT = clamp((now - start) / motion.duration, 0, 1);
        var t = (kind === 'leaf' || kind === 'feather') ? rawT : easeInOutSine(rawT);

        var x = cubic(t, motion.x0, motion.x1, motion.x2, motion.x3);
        var y = cubic(t, motion.y0, motion.y1, motion.y2, motion.y3);

        if (motion.swayAmp) {
          x += Math.sin(rawT * Math.PI * (kind === 'feather' ? 2.2 : 3.5) + blinkPhase) *
            motion.swayAmp * (0.4 + 0.6 * Math.sin(rawT * Math.PI));
        }
        if (motion.bobAmp) {
          y += Math.sin(rawT * Math.PI * motion.bobFreq + blinkPhase) * motion.bobAmp;
        }

        if (kind === 'butterfly') {
          var hop = Math.abs(Math.sin(rawT * Math.PI * motion.bobFreq * 1.7));
          y -= hop * motion.bobAmp * 0.6;
          x += Math.sin(rawT * Math.PI * 6 + blinkPhase) * (motion.swayAmp || 8);
        }

        if (kind === 'firefly') {
          x += Math.sin(rawT * 18 + blinkPhase) * 5;
          y += Math.cos(rawT * 14 + blinkPhase * 0.7) * 4;
        }

        if (motionType === 'crawl' || kind === 'ladybug') {
          y += Math.sin(rawT * Math.PI * 14) * 1.5;
        }

        if (motionType === 'hop') {
          var bounce = Math.abs(Math.sin(rawT * Math.PI * 3));
          y -= bounce * 12;
        }

        if (motionType === 'fly' && asset && !(forced && forced.preview)) {
          x += Math.sin(rawT * Math.PI * 9 + blinkPhase) * 10;
          y += Math.cos(rawT * Math.PI * 7 + blinkPhase) * 8;
        }

        var rot = 0;
        var mirror = '';
        if (motion.keepUpright) {
          // Move along path only — do not rotate/flip the GIF artwork
          rot = 0;
          mirror = '';
        } else if (motion.followPath) {
          var dx = cubicDeriv(Math.min(t, 0.999), motion.x0, motion.x1, motion.x2, motion.x3);
          var dy = cubicDeriv(Math.min(t, 0.999), motion.y0, motion.y1, motion.y2, motion.y3);
          var angle = Math.atan2(dy, dx) * 180 / Math.PI;
          var diff = angle - lastAngle;
          while (diff > 180) diff -= 360;
          while (diff < -180) diff += 360;
          lastAngle += diff * 0.18;
          rot = lastAngle;
          var bank = clamp(diff * 0.8, -motion.bank, motion.bank);
          rot += bank;

          if (asset && (motionType === 'crawl' || motionType === 'hop')) {
            rot = clamp(dy * 0.15, -18, 18);
            if (!motion.fromLeft) {
              mirror = ' scaleX(-1)';
            }
          }
        } else {
          rot = motion.tumble * rawT;
          if (kind === 'leaf') rot += Math.sin(rawT * Math.PI * 4) * 40;
          if (kind === 'feather') rot += Math.sin(rawT * Math.PI * 2.5) * 25;
        }

        var fade;
        if (rawT < 0.05) fade = rawT / 0.05;
        else if (rawT > 0.92) fade = (1 - rawT) / 0.08;
        else fade = 1;

        var op = motion.opacity * fade;
        if (kind === 'firefly') {
          op *= 0.45 + 0.55 * Math.pow(Math.abs(Math.sin(rawT * 22 + Math.sin(rawT * 7) * 3)), 0.4);
        }

        var scale = 1;
        if (kind === 'butterfly') {
          scale = 0.96 + 0.06 * Math.abs(Math.sin(rawT * Math.PI * motion.bobFreq * 2));
        }
        if (kind === 'leaf') {
          scale = 0.75 + 0.25 * Math.abs(Math.cos(rawT * Math.PI * 3));
        }
        if (motionType === 'hop') {
          scale = 0.92 + 0.1 * Math.abs(Math.sin(rawT * Math.PI * 3));
        }

        el.style.opacity = String(op);
        el.style.transform =
          'translate3d(' + x + 'px,' + y + 'px,0) rotate(' + rot + 'deg) scale(' + scale + ')' + mirror;

        if (rawT < 1) {
          requestAnimationFrame(frame);
        } else {
          if (el.parentNode) el.parentNode.removeChild(el);
          self.active = Math.max(0, self.active - 1);
        }
      }

      requestAnimationFrame(frame);
    },

    destroy: function () {
      if (this.timer) clearTimeout(this.timer);
      this.timer = null;
      this.hidePreviewBanner();
      if (this.layer && this.layer.parentNode) {
        this.layer.parentNode.removeChild(this.layer);
      }
      this.layer = null;
      this.active = 0;
    }
  };

  window.CRMNatureFx = NatureFx;

  function boot() {
    if (window.CRM_NATURE_FX && window.CRM_NATURE_FX.enabled) {
      NatureFx.init(window.CRM_NATURE_FX);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window, document);
