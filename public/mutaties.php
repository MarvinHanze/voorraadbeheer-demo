<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/partials.php';
auth_check();

$user = currentUser();
$message = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mutate') {
    verifyCSRF();

    if (!canMoveStock($user['role'])) {
        $message = 'Je rol (' . roleLabel($user['role']) . ') mag geen voorraadmutaties uitvoeren.';
        $msgType = 'error';
    } else {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $type      = $_POST['type'] ?? 'uit';
        $reason    = $_POST['reason'] ?? 'verkoop';
        $qty       = (int) ($_POST['quantity'] ?? 0);
        $reference = trim($_POST['reference'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');
        $lotNumber = trim($_POST['lot_number'] ?? '');

        $allowedReasons = ['verkoop', 'derving', 'correctie', 'ontvangst', 'overig'];
        if (!in_array($reason, $allowedReasons, true)) {
            $reason = 'overig';
        }

        if ($reason === 'derving' && !canWriteOff($user['role'])) {
            $message = 'Afboeken als derving is voorbehouden aan de rol Beheerder. Je huidige rol (' . roleLabel($user['role']) . ') mag dit niet.';
            $msgType = 'error';
        } elseif ($productId <= 0 || $qty <= 0) {
            $message = 'Kies een artikel en vul een aantal groter dan 0 in.';
            $msgType = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                $result = applyStockMutation($pdo, $productId, $type, $qty, $reason, $reference, $notes, $user['name'], $lotNumber);
                $pdo->commit();
                $message = 'Mutatie geregistreerd: ' . ($type === 'in' ? '+' : '-') . $qty . ' voor "' . $result['product']['name']
                    . '" — nieuwe voorraadpositie: ' . $result['new_quantity'] . '.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = $e->getMessage();
                $msgType = 'error';
            }
        }
    }
}

$products = $pdo->query("SELECT id, name, sku, quantity, min_stock, location FROM voorraad_products WHERE status != 'stopgezet' ORDER BY name")->fetchAll();

$movements = $pdo->query("
    SELECT m.*, p.name AS product_name, p.sku, p.category, p.location
    FROM voorraad_movements m
    JOIN voorraad_products p ON p.id = m.product_id
    ORDER BY m.id DESC
    LIMIT 200
")->fetchAll();

renderPageStart('Mutaties', 'mutaties');
renderFlash($message, $msgType);
$canMove = canMoveStock($user['role']);
$canWriteOffRole = canWriteOff($user['role']);
?>

<div class="mb-6">
    <h1 class="text-xl font-bold text-slate-900">Voorraadmutaties</h1>
    <p class="text-sm text-slate-500">
        Elke mutatie wordt atomisch verwerkt: de artikelrij wordt gelockt (<code>SELECT ... FOR UPDATE</code>) binnen
        één databasetransactie, zodat gelijktijdige mutaties op hetzelfde artikel elkaar niet kunnen overschrijven.
    </p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <!-- ── Nieuwe mutatie ─────────────────────────────────────────────── -->
    <div class="hz-card lg:col-span-1">
        <div class="hz-card__header"><h2 class="text-base font-semibold text-slate-900">Nieuwe mutatie</h2></div>
        <?php if (!$canMove): ?>
            <p class="text-sm text-slate-500">Je rol (<?= e(roleLabel($user['role'])) ?>) heeft alleen-lezen toegang tot voorraadmutaties.</p>
        <?php else: ?>
        <form method="post" class="space-y-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="mutate">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Artikel *</label>
                <select name="product_id" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    <option value="">Kies artikel...</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['sku']) ?>) — <?= (int) $p['quantity'] ?> op voorraad</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Type *</label>
                    <select name="type" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                        <option value="in">Inkomend (+)</option>
                        <option value="uit" selected>Uitgaand (-)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Aantal *</label>
                    <input type="number" name="quantity" min="1" value="1" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Reden *</label>
                <select name="reason" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    <option value="verkoop">Verkoop</option>
                    <option value="ontvangst">Ontvangst</option>
                    <option value="correctie">Correctie</option>
                    <option value="overig">Overig</option>
                    <option value="derving" <?= $canWriteOffRole ? '' : 'disabled' ?>>Derving (afboeken)<?= $canWriteOffRole ? '' : ' — alleen Beheerder' ?></option>
                </select>
                <?php if (!$canWriteOffRole): ?>
                    <p class="text-xs text-slate-400 mt-1"><?= termTooltip('Waarom uitgegrijsd?', 'Afboeken als derving (verloren/beschadigde voorraad) is bewust voorbehouden aan de rol Beheerder — granulair rechtensysteem.') ?></p>
                <?php endif; ?>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Referentie</label>
                <input type="text" name="reference" placeholder="bijv. ORD-1234" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Lotnummer (optioneel)</label>
                <input type="text" name="lot_number" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Notitie</label>
                <textarea name="notes" rows="2" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm"></textarea>
            </div>
            <button type="submit" class="hz-btn hz-btn--primary w-full">Mutatie registreren</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- ── Historie ───────────────────────────────────────────────────── -->
    <div class="hz-card lg:col-span-2">
        <div class="hz-card__header">
            <h2 class="text-base font-semibold text-slate-900">Mutatiehistorie</h2>
        </div>
        <div class="flex flex-wrap gap-2 mb-3">
            <input type="text" id="mSearch" placeholder="Zoek op artikel/SKU..." class="px-3 py-1.5 border border-slate-300 rounded-lg text-sm flex-1 min-w-[160px]">
            <select id="mType" class="px-3 py-1.5 border border-slate-300 rounded-lg text-sm">
                <option value="">Alle types</option>
                <option value="in">Inkomend</option>
                <option value="uit">Uitgaand</option>
            </select>
            <select id="mReason" class="px-3 py-1.5 border border-slate-300 rounded-lg text-sm">
                <option value="">Alle redenen</option>
                <option value="verkoop">Verkoop</option>
                <option value="derving">Derving</option>
                <option value="correctie">Correctie</option>
                <option value="ontvangst">Ontvangst</option>
                <option value="overig">Overig</option>
            </select>
        </div>
        <div class="overflow-x-auto" style="max-height:560px;overflow-y:auto;">
            <table class="hz-table" data-hz-sortable id="movementTable">
                <thead>
                <tr>
                    <th data-key="date">Datum</th>
                    <th data-key="product">Artikel</th>
                    <th>Type</th>
                    <th>Reden</th>
                    <th data-key="qty">Aantal</th>
                    <th>Referentie</th>
                    <th>Door</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($movements as $m): ?>
                    <tr data-row
                        data-name="<?= e(strtolower($m['product_name'])) ?>"
                        data-sku="<?= e(strtolower($m['sku'])) ?>"
                        data-type="<?= e($m['type']) ?>"
                        data-reason="<?= e($m['reason']) ?>">
                        <td data-col="date" class="text-slate-400 whitespace-nowrap"><?= nl_datum($m['created_at']) ?></td>
                        <td data-col="product">
                            <?= e($m['product_name']) ?>
                            <span class="text-xs text-slate-400 font-mono block"><?= e($m['sku']) ?></span>
                        </td>
                        <td><?= movementTypeBadge($m['type']) ?></td>
                        <td><span class="hz-badge <?= movementReasonBadgeClass($m['reason']) ?>"><?= e(movementReasonLabel($m['reason'])) ?></span></td>
                        <td data-col="qty" class="font-semibold"><?= (int) $m['quantity'] ?></td>
                        <td class="text-slate-500"><?= e($m['reference'] ?: '-') ?><?= $m['lot_number'] ? ' · lot ' . e($m['lot_number']) : '' ?></td>
                        <td class="text-slate-500"><?= e($m['actor'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$movements): ?>
                    <tr><td colspan="7" class="text-center text-slate-400">Nog geen mutaties.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.getElementById('mSearch').addEventListener('input', applyMFilters);
document.getElementById('mType').addEventListener('change', applyMFilters);
document.getElementById('mReason').addEventListener('change', applyMFilters);
function applyMFilters() {
    const q = document.getElementById('mSearch').value.toLowerCase();
    const type = document.getElementById('mType').value;
    const reason = document.getElementById('mReason').value;
    document.querySelectorAll('#movementTable tbody tr[data-row]').forEach(function (row) {
        const matchSearch = !q || row.dataset.name.includes(q) || row.dataset.sku.includes(q);
        const matchType = !type || row.dataset.type === type;
        const matchReason = !reason || row.dataset.reason === reason;
        row.style.display = (matchSearch && matchType && matchReason) ? '' : 'none';
    });
}
</script>

<?php renderPageEnd(); ?>
