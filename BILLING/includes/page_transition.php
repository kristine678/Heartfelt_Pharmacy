<?php
/**
 * PAGE TRANSITION — ONE shared partial (markup + CSS + JS in this single file).
 * Required by includes/header.php immediately after <body> so it exists on EVERY staff page
 * (Menu, Inventory, History, Setting, Pharmacy, Help Center) with zero per-page code.
 *
 * Arriving: the overlay covers the page on load with the BIG logo already twirling (GIF-like),
 *           then fades out (~300ms) after one visible twirl (~600ms) and is removed (display:none).
 * Leaving:  same-origin internal links are intercepted — the overlay fades in with the twirl,
 *           then the browser navigates; the NEW page's own arriving overlay continues the effect,
 *           so the whole page change reads as one continuous animation.
 * Failsafe: default CSS state is opacity:0 + pointer-events:none (a broken script can never leave
 *           a pink screen stuck over the page), plus a 1800ms hard timeout that force-hides it.
 * Only transform/opacity animate; prefers-reduced-motion swaps the spin for a quick static fade.
 */
?>
<!-- PAGE TRANSITION: full-screen branded overlay (first element in <body>) -->
<div id="pageTransition" class="page-transition" aria-hidden="true">
    <div class="pt-inner">
        <!-- Soft glow layer: pulses via OPACITY only (no filter animation). -->
        <span class="pt-glow"></span>
        <!-- PAGE TRANSITION: orbit ring carries the sparkle dots around the spinning logo
             (the ring rotates via transform; each dot only twinkles its opacity). -->
        <span class="pt-orbit">
            <span class="pt-spark pt-spark-1"></span>
            <span class="pt-spark pt-spark-2"></span>
            <span class="pt-spark pt-spark-3"></span>
            <span class="pt-spark pt-spark-4"></span>
            <span class="pt-spark pt-spark-5"></span>
        </span>
        <div class="pt-logo">
            <!-- PAGE TRANSITION: the provided hp-logo SVG, verbatim except the gradient id is
                 suffixed -pt to avoid a duplicate HTML id with the sidebar badge's heartGrad. -->
            <svg class="hp-logo" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <linearGradient id="heartGradPt" x1="0" y1="0" x2="1" y2="1">
                        <stop offset="0%" stop-color="#ff8fbb"/>
                        <stop offset="100%" stop-color="#e63b80"/>
                    </linearGradient>
                </defs>
                <!-- heart shape -->
                <path d="M60 104
                         C 20 78, 4 54, 4 34
                         C 4 14, 20 2, 36 6
                         C 48 9, 56 18, 60 26
                         C 64 18, 72 9, 84 6
                         C 100 2, 116 14, 116 34
                         C 116 54, 100 78, 60 104 Z"
                      fill="url(#heartGradPt)" stroke="#b8265f" stroke-width="2"/>
                <!-- first aid cross -->
                <g fill="#ffffff">
                    <rect x="50" y="30" width="20" height="46" rx="4"/>
                    <rect x="34" y="46" width="52" height="20" rx="4"/>
                </g>
            </svg>
        </div>
        <!-- NEW (Task 4): brand name under the big logo, fading in slightly after it. -->
        <div class="pt-brand">
            <strong>Heartfelt Pharmacy</strong>
            <small>Your health, our heart.</small>
        </div>
    </div>
</div>

<style>
/* ============ PAGE TRANSITION: overlay styles ============ */
/* Default = fully hidden and click-through: if JS never runs or errors, the page is untouched. */
.page-transition {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, var(--pink-500), var(--pink-300));
    opacity: 0;
    pointer-events: none;
    transition: opacity 300ms ease;
    will-change: opacity;
}
/* Actively showing: intercepts clicks so users can't click "through" the transition. */
.page-transition.pt-active {
    opacity: 1;
    pointer-events: auto;
}
/* Fully removed after the fade — never blocks clicks again. */
.page-transition.pt-done {
    display: none;
}
.pt-inner {
    position: relative;
    display: grid;
    place-items: center;
}
/* PAGE TRANSITION: the BIG twirling logo — dominant element, ~45-55vmin like a loading GIF. */
.pt-logo {
    /* NEW (Task 3): large vector-scaled logo (no redesign — clean SVG scaling). */
    width: min(46vmin, 320px);
    height: min(46vmin, 320px);
    position: relative;
    z-index: 1;
    animation: ptTwirl 1s ease-in-out infinite;
    transform-style: preserve-3d;
}
/* NEW (Task 3): shrink a bit on phones/small tablets so it never gets cropped. */
@media (max-width: 600px) {
    .pt-logo {
        width: min(55vmin, 240px);
        height: min(55vmin, 240px);
    }
}
.pt-logo .hp-logo {
    width: 100%;
    height: 100%;
    display: block;
}
@keyframes ptTwirl {
    0%   { transform: perspective(900px) rotateY(0deg)   scale(1); }
    50%  { transform: perspective(900px) rotateY(180deg) scale(1.08); }
    100% { transform: perspective(900px) rotateY(360deg) scale(1); }
}
/* PAGE TRANSITION: pulsing glow behind the big logo — opacity only, no filter animation. */
.pt-glow {
    position: absolute;
    width: min(70vmin, 460px);
    height: min(70vmin, 460px);
    border-radius: 50%;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.5), rgba(255, 255, 255, 0) 65%);
    animation: ptGlowPulse 1s ease-in-out infinite;
}
@keyframes ptGlowPulse {
    0%, 100% { opacity: 0.4; transform: scale(1); }
    50%      { opacity: 0.75; transform: scale(1.06); }
}
/* PAGE TRANSITION: orbit ring + sparkle dots twinkling gently around the logo. */
.pt-orbit {
    position: absolute;
    width: min(58vmin, 400px);
    height: min(58vmin, 400px);
    animation: ptOrbitSpin 3.2s linear infinite;
}
@keyframes ptOrbitSpin {
    from { transform: rotate(0deg); }
    to   { transform: rotate(360deg); }
}
.pt-spark {
    position: absolute;
    width: 12px;
    height: 12px;
    background: #ffffff;
    clip-path: polygon(50% 0%, 61% 39%, 100% 50%, 61% 61%, 50% 100%, 39% 61%, 0% 50%, 39% 39%);
    animation: ptSparkle 1.6s ease-in-out infinite;
}
.pt-spark-1 { top: 0; left: 50%; margin-left: -6px; }
.pt-spark-2 { top: 50%; right: 0; margin-top: -6px; animation-delay: 0.35s; width: 9px; height: 9px; }
.pt-spark-3 { bottom: 0; left: 50%; margin-left: -5px; animation-delay: 0.7s; }
.pt-spark-4 { top: 50%; left: 0; margin-top: -5px; animation-delay: 1.05s; width: 9px; height: 9px; }
.pt-spark-5 { top: 14%; right: 16%; animation-delay: 0.5s; width: 8px; height: 8px; }
@keyframes ptSparkle {
    0%, 100% { opacity: 0;   transform: scale(0.4); }
    50%      { opacity: 0.85; transform: scale(1); }
}
/* NEW (Task 4): brand name under the logo, fading in slightly after the logo appears. */
.pt-brand {
    margin-top: 22px;
    text-align: center;
    color: #ffffff;
    text-shadow: 0 2px 12px rgba(107, 8, 54, 0.45);
    animation: ptBrandIn 600ms ease 180ms both;
}
.pt-brand strong {
    display: block;
    font-size: clamp(1.3rem, 4.5vmin, 2.1rem);
    letter-spacing: 0.02em;
}
.pt-brand small {
    display: block;
    margin-top: 4px;
    font-size: clamp(0.85rem, 2.6vmin, 1.05rem);
    opacity: 0.9;
}
@keyframes ptBrandIn {
    from { opacity: 0; transform: translateY(10px); }
    to   { opacity: 1; transform: translateY(0); }
}
/* PAGE TRANSITION: reduced motion — no spin, no orbit, no pulse; JS shortens the fades. */
@media (prefers-reduced-motion: reduce) {
    .pt-logo, .pt-glow, .pt-orbit, .pt-spark, .pt-brand {
        animation: none !important;
    }
    .page-transition {
        transition-duration: 150ms;
    }
}
</style>

<script>
/* ============ PAGE TRANSITION: arriving reveal + leaving interception ============ */
(function () {
    'use strict';
    if (window.__hpPageTransitionLoaded) { return; }
    window.__hpPageTransitionLoaded = true;

    var overlay = document.getElementById('pageTransition');
    if (!overlay) { return; }

    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // PAGE TRANSITION timing: one visible twirl on arrival, a quick beat before navigating.
    var ARRIVE_HOLD_MS = reducedMotion ? 250 : 600;  // NEW (Task 3): 500-700ms window
    var LEAVE_HOLD_MS  = reducedMotion ? 150 : 450;  // NEW (Task 3): 400-500ms window
    var FADE_MS        = reducedMotion ? 150 : 300;
    var SAFETY_MS      = 1800; // NEW (Task 3): hard failsafe — never stuck under a pink screen

    var arriveTimer = null;
    var leaveTimer = null;
    var safetyTimer = null;
    var pendingHref = null;

    function forceHide() {
        window.clearTimeout(arriveTimer);
        window.clearTimeout(leaveTimer);
        overlay.classList.remove('pt-active');
        overlay.classList.add('pt-done');
    }

    function fadeOutOverlay() {
        // Fade via opacity transition, then fully remove from the click flow.
        overlay.style.opacity = '0';
        overlay.style.pointerEvents = 'none';
        window.setTimeout(function () {
            overlay.classList.remove('pt-active');
            overlay.classList.add('pt-done');
            overlay.style.opacity = '';
            overlay.style.pointerEvents = '';
        }, FADE_MS);
    }

    // ARRIVING: runs synchronously at the top of <body>, so the overlay covers the page from the
    // first paint with the big logo already twirling. One twirl later it fades out.
    overlay.classList.add('pt-active');
    arriveTimer = window.setTimeout(fadeOutOverlay, ARRIVE_HOLD_MS);
    safetyTimer = window.setTimeout(forceHide, SAFETY_MS);

    // LEAVING: one delegated document-level listener covers the sidebar <nav> AND every other
    // same-site link — no per-link onclick handlers needed. Capture phase sees clicks first.
    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0) { return; }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }

        var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (!link) { return; }

        var rawHref = link.getAttribute('href') || '';
        if (!rawHref || rawHref.charAt(0) === '#') { return; }          // hash jump / empty
        if (link.hasAttribute('download')) { return; }                  // file downloads
        var linkTarget = (link.getAttribute('target') || '').toLowerCase();
        if (linkTarget && linkTarget !== '_self') { return; }           // new tabs/windows

        var resolved = new URL(link.href, location.href);
        if (resolved.protocol !== 'http:' && resolved.protocol !== 'https:') { return; } // mailto:, tel:, javascript:
        if (resolved.origin !== location.origin) { return; }            // external sites
        var here = new URL(location.href);
        if (resolved.pathname === here.pathname && resolved.search === here.search) { return; } // same page

        // PAGE TRANSITION: latest click wins — cancel any pending navigation first.
        event.preventDefault();
        pendingHref = resolved.href;
        window.clearTimeout(leaveTimer);
        window.clearTimeout(safetyTimer);
        overlay.classList.remove('pt-done');
        overlay.classList.add('pt-active'); // fades 0 -> 1 with the big twirl running
        safetyTimer = window.setTimeout(forceHide, SAFETY_MS);

        leaveTimer = window.setTimeout(function () {
            window.location.href = pendingHref; // new page's arriving overlay continues the flow
        }, LEAVE_HOLD_MS);
    }, true);
})();
</script>
