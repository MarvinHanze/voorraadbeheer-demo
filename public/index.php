<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/partials.php';
auth_check();

$user = currentUser();
$message = '';
$msgType = 'success';

// ── Trigger-based inkoop: genereer een inkoopvoorstel (magic link) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_proposal') {
    verifyCSRF();
    if (!canManageProducts($user['role'])) {
        $message = 'Je rol (' . roleLabel($user['role']) . ') mag geen inkoopvoorstellen genereren.';
        $msgType = 'error';
    } else {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM voorraad_products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) {
            $message = 'Artikel niet gevonden.';
            $msgType = 'error';
        } elseif (hasOpenProposal($pdo, $productId)) {
            $message = 'Voor "' . $product['name'] . '" staat al een openstaand inkoopvoorstel — wacht op bevestiging of laat het bestaande voorstel eerst verlopen.';
            $msgType = 'error';
        } else {
            $shortage = (int) $product['min_stock'] * 2 - (int) $product['quantity'];
            $proposedQty = (int) $product['reorder_qty'] > 0 ? (int) $product['reorder_qty'] : max(1, $shortage);
            $result = createPurchaseProposal($pdo, $product, $proposedQty);
            $message = 'Inkoopvoorstel gegenereerd voor "' . $product['name'] . '" — gesimuleerde magic link verzonden naar '
                . $result['to_email'] . ' (DEMO: geen echte e-mail). Bekijk het bij Inkoopvoorstellen.';
        }
    }
}

// ── Basisdata ────────────────────────────────────────────────────────────
$products = $pdo->query('SELECT * FROM voorraad_products ORDER BY name')->fetchAll();

$totalProducts   = count($products);
$lowStock        = 0;
$overStock       = 0;
$totalValueSale  = 0.0;
$totalValueBuy   = 0.0;
$locations       = [];
$categories      = [];
$categoryValue   = [];

foreach ($products as $p) {
    $qty = (int) $p['quantity'];
    if ($qty < (int) $p['min_stock']) {
        $lowStock++;
    }
    if ((int) $p['max_stock'] > 0 && $qty > (int) $p['max_stock']) {
        $overStock++;
    }
    $totalValueSale += $qty * (float) $p['unit_price'];
    $totalValueBuy  += $qty * (float) $p['purchase_price'];
    if ($p['location'] !== '') {
        $locations[$p['location']] = true;
    }
    $categories[$p['category']] = true;
    $categoryValue[$p['category']] = ($categoryValue[$p['category']] ?? 0) + $qty * (float) $p['purchase_price'];
}
arsort($categoryValue);

// ── Voorraadwaarderapport: top artikelen op basis van (aantal x inkoopprijs) ─
$valueReport = $products;
usort($valueReport, function ($a, $b) {
    return ($b['quantity'] * $b['purchase_price']) <=> ($a['quantity'] * $a['purchase_price']);
});
$valueReport = array_slice($valueReport, 0, 8);

// ── Omloopsnelheid (laatste 90 dagen, benadering t.o.v. huidige voorraad) ──
$turnoverRows = $pdo->query("
    SELECT p.id, p.name, p.sku, p.quantity,
           COALESCE(SUM(CASE WHEN m.type = 'uit' AND m.reason = 'verkoop' AND m.created_at >= (NOW() - INTERVAL 90 DAY) THEN m.quantity ELSE 0 END), 0) AS sold_qty
    FROM voorraad_products p
    LEFT JOIN voorraad_movements m ON m.product_id = p.id
    GROUP BY p.id, p.name, p.sku, p.quantity
")->fetchAll();

$turnoverTotal = 0.0;
$turnoverCount = 0;
foreach ($turnoverRows as &$t) {
    $avgStockProxy = max(1, (int) $t['quantity']);
    $t['turnover'] = round(((int) $t['sold_qty']) / $avgStockProxy, 2);
    if ((int) $t['sold_qty'] > 0) {
        $turnoverTotal += $t['turnover'];
        $turnoverCount++;
    }
}
unset($t);
usort($turnoverRows, fn($a, $b) => $b['turnover'] <=> $a['turnover']);
$topTurnover  = array_slice($turnoverRows, 0, 5);
$avgTurnover  = $turnoverCount > 0 ? round($turnoverTotal / $turnoverCount, 2) : 0.0;

// ── Top X derving/verloren artikelen ────────────────────────────────────────
$topDerving = $pdo->query("
    SELECT p.id, p.name, p.sku, p.category,
           SUM(m.quantity) AS lost_qty,
           SUM(m.quantity * p.purchase_price) AS lost_value
    FROM voorraad_movements m
    JOIN voorraad_products p ON p.id = m.product_id
    WHERE m.reason = 'derving'
    GROUP BY p.id, p.name, p.sku, p.category
    ORDER BY lost_value DESC
    LIMIT 5
")->fetchAll();

$totalDerving = $pdo->query("
    SELECT COALESCE(SUM(m.quantity), 0) AS qty, COALESCE(SUM(m.quantity * p.purchase_price), 0) AS value
    FROM voorraad_movements m JOIN voorraad_products p ON p.id = m.product_id
    WHERE m.reason = 'derving'
")->fetch();

// ── Laag/overvoorraad-lijst (voor proactieve notificaties + magic link) ────
$lowStockProducts = array_values(array_filter($products, fn($p) => (int) $p['quantity'] < (int) $p['min_stock']));

// ── Recente mutaties ─────────────────────────────────────────────────────
$recentMovements = $pdo->query("
    SELECT m.*, p.name AS product_name, p.sku
    FROM voorraad_movements m
    JOIN voorraad_products p ON p.id = m.product_id
    ORDER BY m.id DESC
    LIMIT 8
")->fetchAll();

// ── Openstaande inkoopvoorstellen ───────────────────────────────────────────
$openProposals = (int) $pdo->query("SELECT COUNT(*) FROM voorraad_email_log WHERE status = 'verzonden'")->fetchColumn();

// ── Voorraad per magazijnlocatie (locatiebeheer-rapportage) ────────────────
$locationReport = stockByLocation($pdo);

renderPageStart('Dashboard', 'index');
renderFlash($message, $msgType);

$hourNow = (int) date('G');
$greeting = $hourNow < 12 ? 'Goedemorgen' : ($hourNow < 18 ? 'Goedemiddag' : 'Goedenavond');
?>

<!-- ── Welkomstbanner ────────────────────────────────────────────────── -->
<div class="hz-welcome-banner hz-reveal">
    <div class="hz-welcome-banner__content flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="hz-welcome-banner__icon">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </div>
            <div>
                <h1><?= e($greeting) ?>, <?= e(explode(' ', $user['name'])[0]) ?></h1>
                <p><?= e(nl_datum(date('Y-m-d H:i:s'), 'd-m-Y')) ?> &middot; <?= $totalProducts ?> artikelen in beheer over <?= count($locations) ?> locaties</p>
            </div>
        </div>
        <div class="hz-welcome-banner__stat">
            <p class="text-xs uppercase tracking-wide">Totale voorraadwaarde</p>
            <p><?= nl_euro($totalValueBuy) ?></p>
        </div>
    </div>
</div>

<!-- ── Proactieve notificaties ─────────────────────────────────────────── -->
<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-6 hz-reveal" style="animation-delay:.05s;">
    <div class="flex items-center gap-3 px-4 py-3 rounded-lg border <?= $lowStock > 0 ? 'bg-red-50 border-red-200 text-red-800' : 'bg-emerald-50 border-emerald-200 text-emerald-800' ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
        <p class="text-sm"><strong><?= $lowStock ?></strong> artikel<?= $lowStock === 1 ? '' : 'en' ?> onder minimumvoorraad — <a href="<?= BASE ?>/inkoop.php" class="underline">bekijk inkoopvoorstellen</a></p>
    </div>
    <div class="flex items-center gap-3 px-4 py-3 rounded-lg border <?= $overStock > 0 ? 'bg-amber-50 border-amber-200 text-amber-800' : 'bg-slate-50 border-slate-200 text-slate-600' ?>">
        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 11l7-7 7 7M5 19l7-7 7 7"/></svg>
        <p class="text-sm"><strong><?= $overStock ?></strong> artikel<?= $overStock === 1 ? '' : 'en' ?> boven de ingestelde <?= termTooltip('overvoorraadgrens', 'Het maximum aantal stuks dat voor dit artikel als wenselijk is ingesteld (max_stock). Boven deze grens is er sprake van overvoorraad.') ?></p>
    </div>
</div>

<!-- ── Stat cards ─────────────────────────────────────────────────────── -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <div class="hz-card hz-card--stat hz-card--hover hz-reveal" style="animation-delay:.1s;">
        <div class="hz-stat-icon hz-stat-icon--blue"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg></div>
        <p class="hz-card__label">Totaal Artikelen</p>
        <p class="hz-card__value"><?= $totalProducts ?></p>
        <p class="text-sm text-slate-500 mt-1"><?= count($categories) ?> categorieën</p>
    </div>
    <div class="hz-card hz-card--stat hz-card--hover hz-reveal" style="animation-delay:.15s;">
        <div class="hz-stat-icon hz-stat-icon--red"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg></div>
        <p class="hz-card__label">Lage Voorraad</p>
        <p class="hz-card__value <?= $lowStock > 0 ? 'text-red-600' : '' ?>"><?= $lowStock ?></p>
        <p class="text-sm text-slate-500 mt-1"><?= $openProposals ?> openstaand inkoopvoorstel<?= $openProposals === 1 ? '' : 'len' ?></p>
    </div>
    <div class="hz-card hz-card--stat hz-card--hover hz-reveal" style="animation-delay:.2s;">
        <div class="hz-stat-icon hz-stat-icon--emerald"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V6m0 2v8m0 0v2m0-2c-1.11 0-2.08-.402-2.599-1"/></svg></div>
        <p class="hz-card__label"><?= termTooltip('Voorraadwaarde', 'Som van aantal × inkoopprijs, over alle artikelen — het "Voorraadwaarderapport".') ?></p>
        <p class="hz-card__value"><?= nl_euro($totalValueBuy) ?></p>
        <p class="text-sm text-slate-500 mt-1">verkoopwaarde <?= nl_euro($totalValueSale) ?></p>
    </div>
    <div class="hz-card hz-card--stat hz-card--hover hz-reveal" style="animation-delay:.25s;">
        <div class="hz-stat-icon hz-stat-icon--amber"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg></div>
        <p class="hz-card__label"><?= termTooltip('Omloopsnelheid', 'Gemiddeld aantal keer dat de voorraad van een artikel de afgelopen 90 dagen is "omgezet" via verkoop, t.o.v. de huidige voorraadpositie.') ?></p>
        <p class="hz-card__value"><?= number_format($avgTurnover, 2, ',', '.') ?></p>
        <p class="text-sm text-slate-500 mt-1"><?= count($locations) ?> magazijnlocaties</p>
    </div>
</div>

<!-- ── Grafieken ──────────────────────────────────────────────────────── -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <div class="hz-card hz-chart-card hz-reveal" style="animation-delay:.3s;">
        <div class="hz-card__header">
            <h2 class="text-base font-semibold text-slate-900">Voorraadwaarde per categorie</h2>
        </div>
        <canvas id="chartCategory" role="img" aria-label="Donutgrafiek van voorraadwaarde per categorie"></canvas>
    </div>
    <div class="hz-card hz-chart-card hz-reveal" style="animation-delay:.35s;">
        <div class="hz-card__header">
            <h2 class="text-base font-semibold text-slate-900">Voorraadwaarde per magazijnlocatie</h2>
        </div>
        <canvas id="chartLocation" role="img" aria-label="Staafgrafiek van voorraadwaarde per magazijnlocatie"></canvas>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- ── Voorraadwaarderapport ──────────────────────────────────────── -->
    <div class="hz-card hz-reveal" style="animation-delay:.4s;">
        <div class="hz-card__header">
            <h2 class="text-base font-semibold text-slate-900">Voorraadwaarderapport (top 8)</h2>
        </div>
        <p class="text-sm text-slate-500 mb-3">Artikelen met de hoogste voorraadwaarde (aantal × inkoopprijs).</p>
        <div class="overflow-x-auto">
            <table class="hz-table">
                <thead><tr><th>Artikel</th><th>Voorraadpositie</th><th>Waarde</th></tr></thead>
                <tbody>
                <?php foreach ($valueReport as $v): $val = $v['quantity'] * $v['purchase_price']; ?>
                    <tr>
                        <td>
                            <p class="font-medium text-slate-800"><?= e($v['name']) ?></p>
                            <p class="text-xs text-slate-400 font-mono"><?= e($v['sku']) ?></p>
                        </td>
                        <td><?= (int) $v['quantity'] ?> st.</td>
                        <td class="font-semibold"><?= nl_euro((float) $val) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$valueReport): ?>
                    <tr><td colspan="3" class="text-center text-slate-400">Geen artikelen.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Omloopsnelheid top 5 ───────────────────────────────────────── -->
    <div class="hz-card hz-reveal" style="animation-delay:.45s;">
        <div class="hz-card__header">
            <h2 class="text-base font-semibold text-slate-900">Omloopsnelheid (top 5, laatste 90 dagen)</h2>
        </div>
        <p class="text-sm text-slate-500 mb-3">Verkochte stuks t.o.v. huidige voorraadpositie.</p>
        <div class="overflow-x-auto">
            <table class="hz-table">
                <thead><tr><th>Artikel</th><th>Verkocht</th><th>Omloopsnelheid</th></tr></thead>
                <tbody>
                <?php foreach ($topTurnover as $t): ?>
                    <tr>
                        <td>
                            <p class="font-medium text-slate-800"><?= e($t['name']) ?></p>
                            <p class="text-xs text-slate-400 font-mono"><?= e($t['sku']) ?></p>
                        </td>
                        <td><?= (int) $t['sold_qty'] ?> st.</td>
                        <td class="font-semibold"><?= number_format((float) $t['turnover'], 2, ',', '.') ?>x</td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$topTurnover): ?>
                    <tr><td colspan="3" class="text-center text-slate-400">Nog geen verkoopmutaties.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- ── Top derving/verloren artikelen ─────────────────────────────── -->
    <div class="hz-card border-l-4 border-red-400 hz-reveal" style="animation-delay:.5s;">
        <div class="hz-card__header">
            <h2 class="text-base font-semibold text-slate-900">Top derving/verloren artikelen</h2>
        </div>
        <p class="text-sm text-slate-500 mb-3">
            Totale derving: <strong><?= (int) $totalDerving['qty'] ?> stuks</strong>,
            waarde <strong><?= nl_euro((float) $totalDerving['value']) ?></strong>.
        </p>
        <?php if (!$topDerving): ?>
            <p class="text-sm text-slate-400">Geen derving geregistreerd.</p>
        <?php else: ?>
            <ul class="divide-y divide-slate-100 text-sm">
                <?php foreach ($topDerving as $d): ?>
                <li class="py-2 flex items-center justify-between gap-2">
                    <div>
                        <p class="font-medium text-slate-800"><?= e($d['name']) ?></p>
                        <p class="text-xs text-slate-400"><?= e($d['category']) ?> · <?= e($d['sku']) ?></p>
                    </div>
                    <div class="text-right">
                        <p class="font-semibold text-red-600"><?= (int) $d['lost_qty'] ?> st.</p>
                        <p class="text-xs text-slate-400"><?= nl_euro((float) $d['lost_value']) ?></p>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- ── Lage voorraad + trigger-based inkoopvoorstel ────────────────── -->
    <div class="hz-card border-l-4 border-amber-400 hz-reveal" style="animation-delay:.55s;">
        <div class="hz-card__header">
            <h2 class="text-base font-semibold text-slate-900">Lage voorraad — actie vereist</h2>
        </div>
        <?php if (!$lowStockProducts): ?>
            <p class="text-sm text-slate-400">Geen artikelen onder de minimumvoorraad.</p>
        <?php else: ?>
            <ul class="divide-y divide-slate-100 text-sm">
                <?php foreach (array_slice($lowStockProducts, 0, 6) as $p): ?>
                <li class="py-2 flex items-center justify-between gap-2">
                    <div>
                        <p class="font-medium text-slate-800"><?= e($p['name']) ?></p>
                        <p class="text-xs text-slate-400"><?= (int) $p['quantity'] ?> / min. <?= (int) $p['min_stock'] ?> · <?= e($p['location']) ?></p>
                    </div>
                    <?php if (hasOpenProposal($pdo, (int) $p['id'])): ?>
                        <span class="hz-badge hz-badge--orange shrink-0">Al aangevraagd</span>
                    <?php elseif (canManageProducts($user['role'])): ?>
                    <form method="post" class="shrink-0">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="create_proposal">
                        <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                        <button type="submit" class="hz-btn hz-btn--secondary" style="padding:.35rem .7rem;font-size:.78rem;">Inkoopvoorstel</button>
                    </form>
                    <?php else: ?>
                        <span class="hz-badge hz-badge--gray">Alleen-lezen</span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<!-- ── Voorraad per magazijnlocatie (locatiebeheer) ─────────────────────── -->
<div class="hz-card mb-8 hz-reveal" style="animation-delay:.6s;">
    <div class="hz-card__header">
        <h2 class="text-base font-semibold text-slate-900">Voorraad per magazijnlocatie</h2>
        <a href="<?= BASE ?>/artikelen.php" class="text-sm text-brand-700 hover:underline">Filter artikelen op locatie &rarr;</a>
    </div>
    <p class="text-sm text-slate-500 mb-3">Aantal artikelen, totale voorraadpositie en -waarde per locatie (excl. stopgezette artikelen).</p>
    <div class="overflow-x-auto">
        <table class="hz-table">
            <thead><tr><th>Locatie</th><th>Artikelen</th><th>Totaal aantal</th><th>Voorraadwaarde</th><th>Lage voorraad</th></tr></thead>
            <tbody>
            <?php foreach ($locationReport as $loc): ?>
                <tr>
                    <td class="font-medium text-slate-800"><?= e($loc['location']) ?></td>
                    <td><?= $loc['product_count'] ?></td>
                    <td><?= $loc['total_qty'] ?> st.</td>
                    <td class="font-semibold"><?= nl_euro($loc['total_value']) ?></td>
                    <td>
                        <?php if ($loc['low_stock_count'] > 0): ?>
                            <span class="hz-badge hz-badge--red"><?= $loc['low_stock_count'] ?></span>
                        <?php else: ?>
                            <span class="text-slate-300">0</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$locationReport): ?>
                <tr><td colspan="5" class="text-center text-slate-400">Geen artikelen.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Recente mutaties ───────────────────────────────────────────────── -->
<div class="hz-card mb-8 hz-reveal" style="animation-delay:.65s;">
    <div class="hz-card__header">
        <h2 class="text-base font-semibold text-slate-900">Recente voorraadmutaties</h2>
        <a href="<?= BASE ?>/mutaties.php" class="text-sm text-brand-700 hover:underline">Alle mutaties &rarr;</a>
    </div>
    <div class="overflow-x-auto">
        <table class="hz-table">
            <thead><tr><th>Artikel</th><th>Type</th><th>Reden</th><th>Aantal</th><th>Door</th><th>Datum</th></tr></thead>
            <tbody>
            <?php foreach ($recentMovements as $m): ?>
                <tr>
                    <td><?= e($m['product_name']) ?> <span class="text-xs text-slate-400 font-mono"><?= e($m['sku']) ?></span></td>
                    <td><?= movementTypeBadge($m['type']) ?></td>
                    <td><span class="hz-badge <?= movementReasonBadgeClass($m['reason']) ?>"><?= e(movementReasonLabel($m['reason'])) ?></span></td>
                    <td><?= (int) $m['quantity'] ?></td>
                    <td><?= e($m['actor'] ?: '-') ?></td>
                    <td class="text-slate-400"><?= nl_datum($m['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recentMovements): ?>
                <tr><td colspan="6" class="text-center text-slate-400">Nog geen mutaties.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<footer class="mt-4 pb-8 text-center text-sm text-slate-400">
    Voorraadbeheer Demo &middot; PHP 8.2 + Apache + MySQL &middot; alle inkoopvoorstellen/e-mails/synchronisaties zijn gesimuleerd (geen echte externe koppelingen)
</footer>

<?php
$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
$categoryLabels = array_map('strval', array_keys($categoryValue));
$categoryData   = array_map(fn($v) => round($v, 2), array_values($categoryValue));
$locationLabels = array_column($locationReport, 'location');
$locationData   = array_map(fn($v) => round((float) $v, 2), array_column($locationReport, 'total_value'));
?>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var animation = reduceMotion ? false : { duration: 700, easing: 'easeOutQuart' };
    var palette = ['#2563eb', '#d97706', '#059669', '#dc2626', '#7c3aed', '#0891b2', '#64748b'];

    var categoryCtx = document.getElementById('chartCategory');
    if (categoryCtx) {
        new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($categoryLabels, $jsonFlags) ?>,
                datasets: [{
                    data: <?= json_encode($categoryData, $jsonFlags) ?>,
                    backgroundColor: palette,
                    borderColor: '#ffffff',
                    borderWidth: 2,
                }]
            },
            options: {
                animation: animation,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var v = ctx.parsed || 0;
                                return ctx.label + ': € ' + v.toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            }
                        }
                    }
                }
            }
        });
    }

    var locationCtx = document.getElementById('chartLocation');
    if (locationCtx) {
        new Chart(locationCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode($locationLabels, $jsonFlags) ?>,
                datasets: [{
                    label: 'Voorraadwaarde',
                    data: <?= json_encode($locationData, $jsonFlags) ?>,
                    backgroundColor: '#2563eb',
                    borderRadius: 6,
                    maxBarThickness: 34,
                }]
            },
            options: {
                animation: animation,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                var v = ctx.parsed.x || 0;
                                return '€ ' + v.toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            }
                        }
                    }
                },
                scales: {
                    x: { ticks: { callback: function (v) { return '€ ' + v.toLocaleString('nl-NL'); } } }
                }
            }
        });
    }
})();
</script>

<?php renderPageEnd(); ?>
