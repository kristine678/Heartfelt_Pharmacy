<?php
/**
 * TASK 1 — Sales.
 *
 * Where the money comes from: the SAME completed orders History lists (`orders` with
 * status = 'paid' joined to `order_items`). No second table, no cached copy — every figure on this
 * page is an SQL aggregate from salesReport() in config/db.php, which api/sales.php also serves, so
 * the first paint and the chart refreshes can never disagree.
 *
 * Daily / Monthly / Yearly with the previous period to compare against, a trend chart, a category
 * donut (by quantity or by revenue), and the top selling medicines.
 */
require_once 'config/db.php';
require_once 'includes/permissions.php';

// TASK 2/3: Sales is gated by the page permission — admins always have it, staff only once an
// admin grants it from the Users page ("Manage access").
requirePageAccess('sales');

$activePage = 'sales';

$requestedPeriod = (string)($_GET['period'] ?? 'daily');
$report = salesReport($pdo, $requestedPeriod, [
    'date' => $_GET['date'] ?? date('Y-m-d'),
    'month' => $_GET['month'] ?? date('Y-m'),
    'year' => $_GET['year'] ?? date('Y'),
]);
$period = $report['period'];
// Page-specific stylesheet (tabs, KPI cards, chart wrappers, donut legend).
$hpExtraStyles = ['assets/css/sales.css'];

/** Pink trend pill for a period-over-period change. Null means "no earlier sales to compare". */
$changeBadge = static function (?float $change, string $previousLabel): string {
    if ($change === null) {
        return '<span class="sales-trend neutral" title="No sales in ' . htmlspecialchars($previousLabel, ENT_QUOTES, 'UTF-8') . ' to compare with">— no prior sales</span>';
    }
    $direction = $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat');
    $arrow = $change > 0 ? '▲' : ($change < 0 ? '▼' : '■');
    $sign = $change > 0 ? '+' : '';

    return '<span class="sales-trend ' . $direction . '" title="vs ' . htmlspecialchars($previousLabel, ENT_QUOTES, 'UTF-8') . '">'
        . $arrow . ' ' . $sign . number_format($change, 1) . '%</span>';
};

include 'includes/header.php';
?>
<div class="page-title sales-page-title">
    <div>
        <h1>Sales</h1>
        <p class="page-subtitle">
            Completed sales for <strong id="sales-range-label"><?= htmlspecialchars($report['range']['label'], ENT_QUOTES, 'UTF-8') ?></strong>
            &middot; compared with <span id="sales-previous-label"><?= htmlspecialchars($report['previous_range']['label'], ENT_QUOTES, 'UTF-8') ?></span>
        </p>
    </div>
    <div class="page-title-actions">
        <a href="history.php" class="btn btn-secondary"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> View History</a>
    </div>
</div>

<div class="sales-controls form-card">
    <div class="sales-tabs" role="tablist" aria-label="Sales period">
        <?php foreach (['daily' => ['Daily', 'fa-calendar-day'], 'monthly' => ['Monthly', 'fa-calendar-days'], 'yearly' => ['Yearly', 'fa-calendar']] as $tabKey => $tab) : ?>
            <button type="button" role="tab" class="sales-tab <?= $period === $tabKey ? 'active' : '' ?>"
                    id="sales-tab-<?= $tabKey ?>" data-period="<?= $tabKey ?>"
                    aria-selected="<?= $period === $tabKey ? 'true' : 'false' ?>"
                    aria-controls="sales-panel">
                <i class="fa-solid <?= $tab[1] ?>" aria-hidden="true"></i> <?= $tab[0] ?>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="sales-pickers">
        <label class="sales-picker" data-picker="daily">
            <span>Date</span>
            <input type="date" id="sales-date" value="<?= htmlspecialchars((string)($report['input']['date'] ?? date('Y-m-d')), ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <label class="sales-picker" data-picker="monthly">
            <span>Month</span>
            <input type="month" id="sales-month" value="<?= htmlspecialchars((string)($report['input']['month'] ?? date('Y-m')), ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <label class="sales-picker" data-picker="yearly">
            <span>Year</span>
            <input type="number" id="sales-year" min="2000" max="2100" step="1" value="<?= htmlspecialchars((string)($report['input']['year'] ?? date('Y')), ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <button type="button" class="btn btn-primary" id="sales-apply"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Show</button>
    </div>
</div>

<div id="sales-panel" role="tabpanel" aria-labelledby="sales-tab-<?= htmlspecialchars($period, ENT_QUOTES, 'UTF-8') ?>" aria-busy="false">
    <div class="sales-status" id="sales-status" role="status" aria-live="polite" hidden></div>

    <section class="sales-kpis" id="sales-kpis" aria-label="Totals">
        <article class="sales-kpi">
            <p class="sales-kpi-label">Total sales</p>
            <p class="sales-kpi-value" id="sales-kpi-revenue"><?= htmlspecialchars(format_currency($report['totals']['revenue']), ENT_QUOTES, 'UTF-8') ?></p>
            <div id="sales-kpi-revenue-change"><?= $changeBadge($report['change']['revenue'], $report['previous_range']['label']) ?></div>
        </article>
        <article class="sales-kpi">
            <p class="sales-kpi-label">Orders</p>
            <p class="sales-kpi-value" id="sales-kpi-orders"><?= (int)$report['totals']['orders'] ?></p>
            <div id="sales-kpi-orders-change"><?= $changeBadge($report['change']['orders'], $report['previous_range']['label']) ?></div>
        </article>
        <article class="sales-kpi">
            <p class="sales-kpi-label">Items sold</p>
            <p class="sales-kpi-value" id="sales-kpi-items"><?= (int)$report['totals']['items'] ?></p>
            <div id="sales-kpi-items-change"><?= $changeBadge($report['change']['items'], $report['previous_range']['label']) ?></div>
        </article>
        <article class="sales-kpi">
            <p class="sales-kpi-label">Average order value</p>
            <p class="sales-kpi-value" id="sales-kpi-average"><?= htmlspecialchars(format_currency($report['totals']['average']), ENT_QUOTES, 'UTF-8') ?></p>
            <div id="sales-kpi-average-change"><?= $changeBadge($report['change']['average'], $report['previous_range']['label']) ?></div>
        </article>
    </section>

    <div class="sales-empty" id="sales-empty" <?= $report['has_sales'] ? 'hidden' : '' ?>>
        <i class="fa-solid fa-mug-hot" aria-hidden="true"></i>
        <p>No sales for this period.</p>
        <p class="muted">Completed orders show up here as soon as a bill is charged.</p>
    </div>

    <div class="sales-charts">
        <section class="form-card sales-chart-card">
            <h2 id="sales-trend-title">
                <?php
                echo htmlspecialchars(
                    $period === 'daily' ? 'Sales by hour' : ($period === 'monthly' ? 'Sales by day of month' : 'Sales by month'),
                    ENT_QUOTES,
                    'UTF-8'
                );
                ?>
            </h2>
            <div class="sales-canvas-wrap">
                <canvas id="sales-trend-chart" aria-label="Sales trend chart" role="img"></canvas>
                <p class="sales-chart-fallback" id="sales-trend-fallback" hidden></p>
            </div>
        </section>

        <section class="form-card sales-chart-card">
            <div class="sales-chart-head">
                <h2>Categories bought</h2>
                <div class="chip-row sales-metric-toggle" id="sales-metric-toggle" role="group" aria-label="Category chart metric">
                    <button type="button" class="chip active" data-metric="quantity" aria-pressed="true">Quantity</button>
                    <button type="button" class="chip" data-metric="revenue" aria-pressed="false">Revenue</button>
                </div>
            </div>
            <div class="sales-canvas-wrap">
                <canvas id="sales-category-chart" aria-label="Sales by medicine category" role="img"></canvas>
                <p class="sales-chart-fallback" id="sales-category-fallback" hidden></p>
            </div>
            <!-- Custom legend: percentages, counts and money per category (the canvas alone cannot
                 show all three legibly, and this stays readable at any width). -->
            <ul class="sales-legend" id="sales-legend"></ul>
        </section>
    </div>

    <section class="form-card sales-table-card">
        <h2>Top selling medicines</h2>
        <div class="table-wrap">
            <table class="table-basic sales-table">
                <thead>
                    <tr>
                        <th>Medicine</th>
                        <th>Category</th>
                        <th class="num">Quantity</th>
                        <th class="num">Revenue</th>
                    </tr>
                </thead>
                <tbody id="sales-top-body"></tbody>
            </table>
            <p class="empty-state" id="sales-top-empty" hidden>No medicines were sold in this period.</p>
        </div>
    </section>
</div>

<script>
    // The first paint is rendered server-side (numbers, table, legend) so the page is useful even
    // before any script runs; sales.js takes over from this snapshot for period switching.
    window.__salesReport = <?= json_encode($report, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.__salesEndpoint = 'api/sales.php';
    window.__salesToday = <?= json_encode(date('Y-m-d'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script src="assets/js/sales.js?v=20261016"></script>
<?php include 'includes/footer.php'; ?>
