// FIX (logo animations): ONE shared snippet for both logo animations, included on every page.
//   ANIMATION 1 — pop-in on the login page (.login-badge .hp-logo): plays once per login page load.
//   ANIMATION 2 — 3D twirl on staff pages (.brand-mark .hp-logo): plays once on EVERY staff page
//   load. This app navigates via full page reloads, so a load == a section change; clicks that do
//   NOT navigate (Charge, filters, modals...) never reload and therefore never re-trigger it.
// Only transform/opacity are animated (see style.css). Reduced motion: nothing is added and the
// logos simply render statically.
(function () {
    'use strict';

    // Guard against accidental double-inclusion.
    if (window.__hpLogoAnimLoaded) {
        return;
    }
    window.__hpLogoAnimLoaded = true;

    // prefers-reduced-motion: return BEFORE marking the document, so the login logo's
    // CSS pre-animation hidden state (html.hp-anim) never applies and both logos
    // show immediately without any animation.
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    // Marks JS availability as early as possible. On login.php this file is loaded in <head>,
    // so the hidden pre-pop state is applied before first paint (zero flash). On staff pages the
    // same class is harmless (only .login-badge/.brand-mark rules key off it).
    document.documentElement.classList.add('hp-anim');

    function triggerLogoAnimations() {
        // ANIMATION 1 — pop-in, login page only.
        var popLogo = document.querySelector('.login-badge .hp-logo');
        if (popLogo) {
            // Double requestAnimationFrame: guarantees the hidden state has painted once
            // before the pop class lands, so the entrance is never skipped or partial.
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    popLogo.classList.add('hp-logo-play');
                });
            });
        }

        // ANIMATION 2 — sidebar badge twirl, once per staff page load.
        var brandLogo = document.querySelector('.brand-mark .hp-logo');
        if (brandLogo) {
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    brandLogo.classList.add('hp-logo-twirl-play');
                });
            });
        }
    }

    // Works no matter where this file is included: <head> (login.php, document still loading)
    // or end of <body> (includes/footer.php, readyState already "interactive").
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', triggerLogoAnimations);
    } else {
        triggerLogoAnimations();
    }
})();
