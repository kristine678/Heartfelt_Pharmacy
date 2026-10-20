<?php
/**
 * FIX (Bug 2): ONE shared animated-background partial, required by header.php (all staff pages),
 * login.php and the public catalog — tuning colors/counts happens here only.
 *
 * Renders <div class="bg-fx"> with 72 twinkling sparkles, 6 large star flashes and 12 popping
 * medicine SVG icons (capsule, tablet, bottle, blister, syringe, cross, band-aid, drops).
 * Pure transform/opacity animation; the keyframes, dark-pink base, reduced-motion, print and
 * tab-hidden pause behaviour live in assets/css/style.css + assets/js/bg-pause.js.
 */
?>
<?php
// FIX (Bug 2): deterministic pseudo-random layout so every request looks organic but stays stable.
$bgFxSparkles = '';
mt_srand(20260929);
for ($i = 0; $i < 72; $i++) {
    $edgeBand = $i % 3; // FIX (Bug 2): two of three bands hug the edges; one lands anywhere.
    if ($edgeBand === 0) {
        $left = mt_rand(1, 14);
        if (mt_rand(0, 1) === 1) {
            $left = mt_rand(76, 92); // right edge
        }
        $top = mt_rand(2, 96);
    } elseif ($edgeBand === 1) {
        $left = mt_rand(1, 96);
        $top = mt_rand(2, 14); // top band
    } else {
        $left = mt_rand(2, 95);
        $top = mt_rand(2, 96);
    }
    $bgFxSparkles .= sprintf('<span class="sparkle s%d" style="left:%d%%; top:%d%%; animation-delay:%.2fs; animation-duration:%.1fs;"></span>', $i % 4, $left, $top, mt_rand(0, 340) / 100, mt_rand(20, 40) / 10);
}
for ($i = 0; $i < 6; $i++) {
    $bgFxSparkles .= sprintf('<span class="star-flash" style="left:%d%%; top:%d%%; animation-delay:%.1fs;"></span>', mt_rand(3, 94), mt_rand(3, 94), mt_rand(0, 70) / 10);
}
$bgFxMedicines = '';
$bgFxPositions = [[3, 7], [88, 4], [6, 74], [90, 70], [4, 38], [92, 36], [24, 3], [70, 5], [16, 90], [74, 88], [44, 2], [30, 93]];
// FIX (Bug 2): white/pink/rose flat icons — brighter fills now the app background is deep pink.
$bgFxIcons = [
    '<svg viewBox="0 0 64 64"><rect x="6" y="24" width="52" height="17" rx="8.5" fill="#ffffff" transform="rotate(-24 32 32)"/><rect x="6" y="24" width="26" height="17" rx="8.5" fill="#ff5c9a" transform="rotate(-24 32 32)"/></svg>',
    '<svg viewBox="0 0 64 64"><circle cx="32" cy="32" r="17" fill="#ffd7e8"/><circle cx="26" cy="27" r="5" fill="#ffffff"/></svg>',
    '<svg viewBox="0 0 64 64"><rect x="20" y="14" width="24" height="38" rx="7" fill="#ffffff"/><rect x="24" y="8" width="16" height="9" rx="3" fill="#ff85b3"/><rect x="24" y="30" width="16" height="12" rx="2" fill="#ff5c9a"/></svg>',
    '<svg viewBox="0 0 64 64"><rect x="12" y="20" width="40" height="25" rx="4" fill="#ffffff" transform="rotate(12 32 32)"/><circle cx="24" cy="32" r="5" fill="#ff5c9a" transform="rotate(12 32 32)"/><circle cx="40" cy="32" r="5" fill="#ff85b3" transform="rotate(12 32 32)"/></svg>',
    '<svg viewBox="0 0 64 64"><rect x="28" y="8" width="8" height="34" rx="4" fill="#ffd7e8"/><polygon points="28,42 36,42 32,56" fill="#ffffff"/><rect x="24" y="4" width="16" height="7" rx="3" fill="#ffffff"/></svg>',
    '<svg viewBox="0 0 64 64"><path fill="#ffffff" d="M25 12h14v13h13v14H39v13H25V39H12V25h13z"/></svg>',
    '<svg viewBox="0 0 64 64"><rect x="8" y="26" width="48" height="13" rx="6.5" fill="#ffffff"/><rect x="18" y="26" width="10" height="13" fill="#ff5c9a"/><rect x="38" y="26" width="10" height="13" fill="#ff5c9a"/></svg>',
    '<svg viewBox="0 0 64 64"><rect x="24" y="10" width="16" height="30" rx="6" fill="#ffd7e8" transform="rotate(18 32 32)"/><circle cx="32" cy="49" r="5" fill="#ffffff" transform="rotate(18 32 32)"/></svg>',
];
foreach ($bgFxPositions as $index => $pos) {
    $bgFxMedicines .= sprintf('<span class="medicine-pop m%d" style="left:%d%%; top:%d%%; animation-delay:%.1fs; animation-duration:%.1fs;">%s</span>', $index % 8, $pos[0], $pos[1], mt_rand(0, 55) / 10, mt_rand(50, 90) / 10, $bgFxIcons[$index % count($bgFxIcons)]);
}
?>
<!-- FIX (Bug 2): shared background layer — deep-pink base (see .bg-fx::before in style.css),
     twinkling sparkles, star flashes and popping medicines. Behind sidebar/cards (z-index 0). -->
<div class="bg-fx" aria-hidden="true">
    <?php echo $bgFxSparkles; ?>
    <?php echo $bgFxMedicines; ?>
</div>
