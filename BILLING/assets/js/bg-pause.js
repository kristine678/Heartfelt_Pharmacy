// FIX (Bug 2): ONE shared background snippet — pauses every .bg-fx/.bg-anim animation loop while
// the browser tab is hidden (visibilitychange). Loaded by footer.php (staff pages), login.php and
// the public catalog so the behaviour is tuned in a single place.
(function () {
    function applyBgPause() {
        document.body.classList.toggle('bg-fx-paused', document.hidden);
    }
    document.addEventListener('visibilitychange', applyBgPause);
    applyBgPause();
})();
