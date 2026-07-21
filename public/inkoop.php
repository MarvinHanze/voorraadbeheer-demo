<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/partials.php';
auth_check();

$user = currentUser();
$message = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_proposal') {
        if (!canManageProducts($user['role'])) {
            $message = 'Je rol mag geen inkoopvoorstellen genereren.';
            $msgType = 'error';
        } else {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM voorraad_products WHERE id = ?');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            if (!$product) {
                $message = 'Artikel niet gevonden.';
                $msgType = 'error';
            } else {
                $shortage = (int) $product['min_stock'] * 2 - (int) $product['quantity'];
                $proposedQty = (int) $product['reorder_qty'] > 0 ? (int) $product['reorder_qty'] : max(1, $shortage);
                $result = createPurchaseProposal($pdo, $product, $proposedQty);
                $message = 'Inkoopvoorstel gegenereerd voor "' . $product['name'] . '" — gesimuleerde magic link naar '
                    . $result['to_email'] . ' (DEMO: er is geen echte e-mail verzonden).';
            }
        }
    } elseif ($action === 'expire') {
        if (!canManageSettings($user['role'])) {
            $message = 'Alleen Beheerder mag voorstellen handmatig laten verlopen.';
            $msgType = 'error';
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE voorraad_email_log SET status = 'verlopen' WHERE id = ? AND status = 'verzonden'");
            $stmt->execute([$id]);
            $message = 'Inkoopvoorstel gemarkeerd als verlopen.';
        }
    }
}

$lowStockProducts = $pdo->query("
    SELECT * FROM voorraad_products WHERE quantity < min_stock AND status != 'stopgezet' ORDER BY (min_stock - quantity) DESC
")->fetchAll();

$proposals = $pdo->query("
    SELECT e.*, p.name AS product_name, p.sku
    FROM voorraad_email_log e
    LEFT JOIN voorraad_products p ON p.id = e.product_id
    ORDER BY e.id DESC
    LIMIT 100
")->fetchAll();

renderPageStart('Inkoopvoorstellen', 'inkoop');
renderFlash($message, $msgType);
$canManage = canManageProducts($user['role']);
?>

<div class="mb-6">
    <h1 class="text-xl font-bold text-slate-900">Inkoopvoorstellen &amp; magic links</h1>
    <p class="text-sm text-slate-500">
        Trigger-based inkoop: zodra een artikel onder de minimumvoorraad zakt, kan een inkoopvoorstel met
        "magic link" naar de leverancier worden gegenereerd.
        <span class="hz-badge hz-badge--orange">DEMO/SIMULATIE</span> — er wordt nooit een echte e-mail verzonden
        of externe leverancier benaderd; alles wordt uitsluitend gelogd in <code>voorraad_email_log</code>.
    </p>
</div>

<!-- ── Artikelen onder minimumvoorraad ──────────────────────────────────── -->
<div class="hz-card mb-8">
    <div class="hz-card__header"><h2 class="text-base font-semibold text-slate-900">Artikelen onder minimumvoorraad</h2></div>
    <?php if (!$lowStockProducts): ?>
        <p class="text-sm text-slate-400">Alle artikelen zitten boven hun minimumvoorraad.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="hz-table">
            <thead><tr><th>Artikel</th><th>Voorraadpositie</th><th>Leverancier</th><th>Actie</th></tr></thead>
            <tbody>
            <?php foreach ($lowStockProducts as $p): ?>
                <tr>
                    <td>
                        <p class="font-medium text-slate-800"><?= e($p['name']) ?></p>
                        <p class="text-xs text-slate-400 font-mono"><?= e($p['sku']) ?></p>
                    </td>
                    <td class="text-red-600 font-semibold"><?= (int) $p['quantity'] ?> / min. <?= (int) $p['min_stock'] ?></td>
                    <td>
                        <?= e($p['supplier_name'] ?: 'Onbekend') ?>
                        <span class="block text-xs text-slate-400"><?= e($p['supplier_email'] ?: '-') ?></span>
                    </td>
                    <td>
                        <?php if ($canManage): ?>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="create_proposal">
                            <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                            <button type="submit" class="hz-btn hz-btn--primary" style="padding:.4rem .8rem;font-size:.8rem;">Genereer inkoopvoorstel</button>
                        </form>
                        <?php else: ?>
                            <span class="hz-badge hz-badge--gray">Alleen-lezen</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── Log van inkoopvoorstellen / magic links ─────────────────────────── -->
<div class="hz-card">
    <div class="hz-card__header"><h2 class="text-base font-semibold text-slate-900">Verzonden inkoopvoorstellen (gesimuleerde e-maillog)</h2></div>
    <div class="overflow-x-auto">
        <table class="hz-table">
            <thead><tr><th>Artikel</th><th>Leverancier</th><th>Aangevraagd</th><th>Status</th><th>Verzonden op</th><th>Bevestigd op</th><th>Magic link (demo)</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($proposals as $p): ?>
                <tr>
                    <td>
                        <p class="font-medium text-slate-800"><?= e($p['product_name'] ?? '(verwijderd artikel)') ?></p>
                        <p class="text-xs text-slate-400 font-mono"><?= e($p['sku'] ?? '') ?></p>
                    </td>
                    <td class="text-slate-600"><?= e($p['to_email']) ?></td>
                    <td><?= (int) $p['proposed_qty'] ?> st.</td>
                    <td><?= proposalStatusBadge($p['status']) ?></td>
                    <td class="text-slate-400 whitespace-nowrap"><?= nl_datum($p['created_at']) ?></td>
                    <td class="text-slate-400 whitespace-nowrap"><?= nl_datum($p['confirmed_at']) ?></td>
                    <td>
                        <?php if ($p['status'] === 'verzonden'): ?>
                            <a href="<?= BASE ?>/bevestig.php?token=<?= e($p['token']) ?>" target="_blank" class="text-brand-700 underline text-xs">Open link</a>
                        <?php else: ?>
                            <span class="text-slate-300 text-xs">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($p['status'] === 'verzonden' && canManageSettings($user['role'])): ?>
                        <form method="post" data-hz-confirm="Dit inkoopvoorstel als verlopen markeren?">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="expire">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button type="submit" class="hz-btn hz-btn--secondary" style="padding:.3rem .6rem;font-size:.75rem;">Laten verlopen</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$proposals): ?>
                <tr><td colspan="8" class="text-center text-slate-400">Nog geen inkoopvoorstellen gegenereerd.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php renderPageEnd(); ?>
