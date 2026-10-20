<?php
require_once 'config/db.php';

$rows = getSellableMedicineGroups($pdo);
$categories = [];
foreach ($rows as $medicine) {
    $category = trim((string)($medicine['category'] ?? 'Uncategorized')) ?: 'Uncategorized';
    if (!isset($categories[$category])) {
        $categories[$category] = $category;
    }
}
$categoryList = array_keys($categories);
sort($categoryList, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Heartfelt Pharmacy | Browse Medicines</title>
    <!-- FIX (Bug 2): catalog now uses the shared stylesheet and dark-pink theme tokens
         instead of its own private pale-pink palette. -->
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        :root {
            /* FIX (Task 2): catalog palette now mirrors the shared bolder pink tokens in style.css
               (same hex values, single source of truth — no page-by-page drift). */
            --pink-50: #fff0f6;
            --pink-100: #ffd6e8;
            --pink-200: #ffadd2;
            --pink-300: #ff85bd;
            --pink-400: #ff5ca3;
            --pink-500: #ff2e8a;
            --pink-600: #e6136e;
            --pink-700: #b8095a;
            --pink-900: #6b0836;
            --white: #ffffff;
            --muted: #7d5a72;
            --border: #f8d9e7;
            --soft-shadow: rgba(230, 19, 110, 0.16);
            /* FIX (Task 2): deep tokens derive from the shared palette like style.css does. */
            --deep-rose: var(--pink-600);
            --deep-pink: var(--pink-500);
            --dark-magenta: var(--pink-700);
            --dark-surface: linear-gradient(160deg, var(--deep-rose) 0%, var(--deep-pink) 45%, var(--dark-magenta) 100%);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            /* FIX (Bug 2): dark pink gradient (was pale pink) with fixed attachment. */
            background: var(--dark-surface);
            background-attachment: fixed;
            color: var(--pink-900);
        }

        /* FIX (Bug 2): the bg-fx layer shows through the shell, so panels need the light
           card surfaces on top (z-index) — same stacking model as the staff dashboard. */
        .public-shell {
            position: relative;
            z-index: 1;
        }

        .public-shell {
            max-width: 1180px;
            margin: 0 auto;
            padding: 36px 20px 52px;
        }

        /* FIX (Bug 2): cards keep semi-solid white surfaces for 4.5:1+ contrast on the dark bg. */
        .public-topbar,
        .catalog-search,
        .chip {
            backdrop-filter: blur(8px);
        }
        .public-topbar {
            background: rgba(255, 255, 255, 0.92);
        }
        .catalog-card {
            background: rgba(255, 255, 255, 0.92);
        }
        .catalog-card.is-out-of-stock {
            opacity: 0.62;
            filter: grayscale(0.35);
        }
        .catalog-card.is-out-of-stock .catalog-placeholder {
            opacity: 0.7;
        }

        .public-topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            padding: 18px 22px;
            border: 1px solid var(--border);
            border-radius: 20px;
            background: rgba(255,255,255,0.7);
            box-shadow: 0 14px 32px rgba(255, 92, 154, 0.08);
            backdrop-filter: blur(8px);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 800;
            letter-spacing: 0.02em;
        }

        .brand-mark {
            display: grid;
            place-items: center;
            width: 42px;
            height: 42px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--pink-500), var(--pink-300));
            color: var(--white);
            box-shadow: 0 12px 22px rgba(255, 92, 154, 0.2);
        }

        .brand-mark svg {
            width: 24px;
            height: 24px;
        }

        .catalog-header {
            margin-bottom: 20px;
        }

        .eyebrow {
            margin: 0 0 8px;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--pink-600);
            font-weight: 800;
            font-size: 0.74rem;
        }

        .catalog-title {
            margin: 0;
            font-size: clamp(2rem, 4vw, 3rem);
            line-height: 1.1;
        }

        .catalog-subtitle {
            margin: 10px 0 0;
            color: var(--muted);
            font-size: 1rem;
        }

        .catalog-controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin: 22px 0 24px;
        }

        .catalog-search {
            flex: 1 1 260px;
            min-width: 220px;
            padding: 13px 16px;
            border-radius: 14px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.8);
            color: var(--pink-900);
            font-size: 0.98rem;
            box-shadow: inset 0 1px 2px rgba(255, 92, 154, 0.08);
        }

        .catalog-search:focus {
            outline: 2px solid rgba(255, 92, 154, 0.2);
            border-color: var(--pink-300);
        }

        .chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .chip {
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.65);
            color: var(--pink-700);
            border-radius: 999px;
            padding: 9px 14px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 200ms ease, background 200ms ease, color 200ms ease;
        }

        .chip.active {
            background: linear-gradient(135deg, var(--pink-500), var(--pink-300));
            border-color: transparent;
            color: var(--white);
            box-shadow: 0 10px 18px rgba(255, 92, 154, 0.18);
        }

        .chip:hover {
            transform: translateY(-1px);
        }

        .catalog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
        }

        .catalog-card {
            display: flex;
            flex-direction: column;
            min-height: 100%;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.82);
            border-radius: 20px;
            overflow: hidden;
            transition: transform 220ms ease, box-shadow 220ms ease;
            box-shadow: 0 14px 28px rgba(255, 92, 154, 0.07);
        }

        .catalog-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 18px 34px rgba(255, 92, 154, 0.12);
        }

        .catalog-card img {
            display: block;
            width: 100%;
            height: 140px;
            object-fit: cover;
            background: linear-gradient(135deg, #fff3f9, #ffe2f0);
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
        }

        .catalog-placeholder {
            display: grid;
            place-items: center;
            width: 100%;
            height: 170px;
            background: linear-gradient(135deg, #ffedf5, #ffe0ee);
            color: var(--pink-500);
            font-size: 3rem;
        }

        .catalog-body {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 16px;
            flex: 1;
        }

        .catalog-category {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255, 92, 154, 0.08);
            color: var(--pink-700);
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .catalog-card h3 {
            margin: 0;
            font-size: 1.2rem;
            color: var(--pink-900);
        }

        .catalog-price {
            margin-top: auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding-top: 8px;
            border-top: 1px solid #f8dfe9;
        }

        .price-tag {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--pink-700);
        }

        .stock-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 9px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 800;
        }

        .stock-pill.in-stock {
            background: rgba(38, 166, 154, 0.1);
            color: #0a7a68;
        }

        .stock-pill.low-stock {
            background: rgba(255, 170, 0, 0.12);
            color: #9d5a00;
        }

        .stock-pill.out-of-stock {
            background: rgba(234, 67, 53, 0.12);
            color: #a12d2d;
        }

        .empty-state {
            display: none;
            padding: 26px 18px;
            border-radius: 18px;
            background: rgba(255,255,255,0.75);
            border: 1px dashed var(--pink-200);
            color: var(--muted);
            text-align: center;
            font-weight: 600;
        }

        @media (max-width: 640px) {
            .public-topbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .catalog-controls {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <!-- FIX (Bug 2): shared animated background layer — same partial as every other page. -->
    <?php require __DIR__ . '/includes/bg_fx.php'; ?>

    <div class="public-shell">
        <header class="public-topbar">
            <div class="brand">
                <span class="brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 64 64" focusable="false"><path d="M32 50c-10.8-7.3-18-13.5-18-22.2C14 22.7 18.7 18 24.7 18c4 0 6.7 2 8.3 4.5C34.5 20 37.2 18 41.2 18 47.3 18 52 22.7 52 27.8 52 36.5 44.8 42.7 32 50Z" fill="#fff"/><path d="M32 26.5v13M25.5 33h13" stroke="#ff7cb3" stroke-width="3" stroke-linecap="round"/></svg>
                </span>
                <span>Heartfelt Pharmacy</span>
            </div>
        </header>

        <section class="catalog-header">
            <p class="eyebrow">Public catalog</p>
            <h1 class="catalog-title">Available medicines and stock</h1>
            <p class="catalog-subtitle">Browse current stock without signing in. Inventory updates are reflected in real time.</p>
        </section>

        <div class="catalog-controls">
            <input id="searchInput" class="catalog-search" type="search" placeholder="Search medicines..." aria-label="Search medicines" />
            <div class="chip-row" aria-label="Medicine categories">
                <button class="chip active" type="button" data-category="all">All</button>
                <?php foreach ($categoryList as $category) : ?>
                    <button class="chip" type="button" data-category="<?= htmlspecialchars(strtolower(str_replace(' ', '-', trim($category)))) ?>"><?= htmlspecialchars($category) ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="catalogGrid" class="catalog-grid">
            <?php foreach ($rows as $medicine) : ?>
                <?php
                $category = trim((string)($medicine['category'] ?? 'Uncategorized')) ?: 'Uncategorized';
                $categoryKey = strtolower(str_replace(' ', '-', trim($category)));
                $quantity = (int)($medicine['quantity'] ?? 0);
                $price = (float)($medicine['price'] ?? 0);
                $image = trim((string)($medicine['image'] ?? ''));
                // FIX (Bug 1): catalog mirrors the Menu's availability rules — qty 0 renders as a
                // greyed-out "Out of Stock" card, never as a purchasable item.
                $stockStatus = $quantity <= 0 ? 'Out of stock' : ($quantity <= 10 ? 'Low stock' : 'In stock');
                $stockClass = $quantity <= 0 ? 'out-of-stock' : ($quantity <= 10 ? 'low-stock' : 'in-stock');
                ?>
                <article class="catalog-card <?= $quantity <= 0 ? 'is-out-of-stock' : '' ?>" data-name="<?= htmlspecialchars(strtolower((string)$medicine['name'])) ?>" data-category="<?= htmlspecialchars($categoryKey) ?>">
                    <?php if ($image !== '') : ?>
                        <img src="<?= htmlspecialchars($image) ?>" alt="<?= htmlspecialchars((string)$medicine['name']) ?>" />
                    <?php else : ?>
                        <div class="catalog-placeholder" aria-hidden="true">✚</div>
                    <?php endif; ?>
                    <div class="catalog-body">
                        <span class="catalog-category"><?= htmlspecialchars($category) ?></span>
                        <h3><?= htmlspecialchars((string)$medicine['name']) ?></h3>
                                <div class="catalog-price">
                            <span class="price-tag"><?= '₱' . number_format($price, 2) ?></span>
                            <span class="stock-pill <?= htmlspecialchars($stockClass) ?>"><?= htmlspecialchars($stockStatus) ?></span>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div id="catalogEmpty" class="empty-state">No medicines match your search.</div>
    </div>

    <script>
        const searchInput = document.getElementById('searchInput');
        const catalogGrid = document.getElementById('catalogGrid');
        const emptyState = document.getElementById('catalogEmpty');
        const categoryButtons = [...document.querySelectorAll('.chip')];

        let activeCategory = 'all';

        function applyFilter() {
            const query = (searchInput.value || '').trim().toLowerCase();
            const cards = [...catalogGrid.querySelectorAll('.catalog-card')];
            let visible = 0;

            cards.forEach((card) => {
                const matchesText = !query || (card.dataset.name || '').includes(query);
                const matchesCategory = activeCategory === 'all' || (card.dataset.category || '') === activeCategory;
                const shouldShow = matchesText && matchesCategory;
                card.style.display = shouldShow ? '' : 'none';
                if (shouldShow) visible += 1;
            });

            emptyState.style.display = visible === 0 ? 'block' : 'none';
        }

        searchInput.addEventListener('input', applyFilter);

        categoryButtons.forEach((button) => {
            button.addEventListener('click', () => {
                activeCategory = button.dataset.category || 'all';
                categoryButtons.forEach((chip) => chip.classList.toggle('active', chip === button));
                applyFilter();
            });
        });
    </script>
    <!-- FIX (Bug 2): ONE shared pause snippet — animations stop when the tab is hidden. -->
    <script src="assets/js/bg-pause.js"></script>
</body>
</html>
