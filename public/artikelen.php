<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/partials.php';
auth_check();

$user = currentUser();
$message = '';
$msgType = 'success';

// ── CSV-export (GET, streamt direct, géén HTML-output) ─────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="artikelen_export_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['naam', 'sku', 'categorie', 'aantal', 'minimum_voorraad', 'maximum_voorraad', 'locatie', 'verkoopprijs', 'inkoopprijs', 'leverancier', 'leverancier_email', 'status']);
    $all = $pdo->query('SELECT * FROM voorraad_products ORDER BY name')->fetchAll();
    foreach ($all as $p) {
        fputcsv($out, [
            $p['name'], $p['sku'], $p['category'], $p['quantity'], $p['min_stock'], $p['max_stock'],
            $p['location'], $p['unit_price'], $p['purchase_price'], $p['supplier_name'], $p['supplier_email'], $p['status'],
        ]);
    }
    fclose($out);
    exit;
}

// ── POST-acties ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $action = $_POST['action'] ?? '';

    // De verwijderknop in de product-modal deelt zijn <form> met de hidden
    // action=save; om niet te leunen op document-volgorde bij dubbele
    // POST-sleutels gebruikt die knop een apart, ondubbelzinnig veld.
    if (($_POST['do_delete'] ?? '') === '1') {
        $action = 'delete';
    }

    if ($action === 'save') {
        if (!canManageProducts($user['role'])) {
            $message = 'Je rol (' . roleLabel($user['role']) . ') mag geen artikelen toevoegen/bewerken.';
            $msgType = 'error';
        } else {
            $id       = (int) ($_POST['id'] ?? 0);
            $name     = trim($_POST['name'] ?? '');
            $sku      = trim($_POST['sku'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $qty      = max(0, (int) ($_POST['quantity'] ?? 0));
            $minStock = max(0, (int) ($_POST['min_stock'] ?? 5));
            $maxStock = max(0, (int) ($_POST['max_stock'] ?? 0));
            $reorder  = max(0, (int) ($_POST['reorder_qty'] ?? 0));
            $location = trim($_POST['location'] ?? '');
            $unitPrice = max(0, (float) str_replace(',', '.', (string) ($_POST['unit_price'] ?? 0)));
            $purchasePrice = max(0, (float) str_replace(',', '.', (string) ($_POST['purchase_price'] ?? 0)));
            $supplierName  = trim($_POST['supplier_name'] ?? '');
            $supplierEmail = trim($_POST['supplier_email'] ?? '');
            $status   = $_POST['status'] ?? 'actief';
            $allowedStatus = ['actief', 'uitverkocht', 'stopgezet'];
            if (!in_array($status, $allowedStatus, true)) {
                $status = 'actief';
            }

            if ($name === '' || $sku === '' || $category === '') {
                $message = 'Artikelnaam, SKU en categorie zijn verplicht.';
                $msgType = 'error';
            } else {
                try {
                    if ($id > 0) {
                        $stmt = $pdo->prepare('UPDATE voorraad_products SET name=?, sku=?, category=?, quantity=?, min_stock=?, max_stock=?, reorder_qty=?, location=?, unit_price=?, purchase_price=?, supplier_name=?, supplier_email=?, status=? WHERE id=?');
                        $stmt->execute([$name, $sku, $category, $qty, $minStock, $maxStock, $reorder, $location, $unitPrice, $purchasePrice, $supplierName, $supplierEmail, $status, $id]);
                        $message = 'Artikel "' . $name . '" bijgewerkt.';
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO voorraad_products (name, sku, category, quantity, min_stock, max_stock, reorder_qty, location, unit_price, purchase_price, supplier_name, supplier_email, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
                        $stmt->execute([$name, $sku, $category, $qty, $minStock, $maxStock, $reorder, $location, $unitPrice, $purchasePrice, $supplierName, $supplierEmail, $status]);
                        $message = 'Artikel "' . $name . '" toegevoegd.';
                    }
                } catch (PDOException $e) {
                    $message = $e->getCode() === '23000' ? 'Deze SKU bestaat al bij een ander artikel.' : 'Databasefout bij opslaan.';
                    $msgType = 'error';
                }
            }
        }
    } elseif ($action === 'delete') {
        if (!canManageProducts($user['role'])) {
            $message = 'Onvoldoende rechten om artikelen te verwijderen.';
            $msgType = 'error';
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare('DELETE FROM voorraad_products WHERE id = ?');
                $stmt->execute([$id]);
                $message = 'Artikel verwijderd.';
            }
        }
    } elseif ($action === 'bulk_delete') {
        if (!canManageProducts($user['role'])) {
            $message = 'Onvoldoende rechten om artikelen te verwijderen.';
            $msgType = 'error';
        } else {
            $ids = array_values(array_filter(array_map('intval', $_POST['ids'] ?? []), fn($i) => $i > 0));
            if ($ids) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("DELETE FROM voorraad_products WHERE id IN ($in)");
                $stmt->execute($ids);
                $message = count($ids) . ' artikel(en) verwijderd.';
            } else {
                $message = 'Geen artikelen geselecteerd.';
                $msgType = 'error';
            }
        }
    } elseif ($action === 'add_batch') {
        if (!canMoveStock($user['role'])) {
            $message = 'Je rol mag geen batches/lots ontvangen.';
            $msgType = 'error';
        } else {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $lot       = trim($_POST['lot_number'] ?? '');
            $batchQty  = max(0, (int) ($_POST['batch_quantity'] ?? 0));
            $expiry    = trim($_POST['expiry_date'] ?? '') ?: null;

            if ($productId <= 0 || $lot === '' || $batchQty <= 0) {
                $message = 'Vul artikel, lotnummer en een aantal groter dan 0 in.';
                $msgType = 'error';
            } else {
                try {
                    $pdo->beginTransaction();
                    applyStockMutation($pdo, $productId, 'in', $batchQty, 'ontvangst', 'BATCH-' . $lot, 'Batch/lot-ontvangst', $user['name'], $lot);
                    $ins = $pdo->prepare('INSERT INTO voorraad_batches (product_id, lot_number, quantity, expiry_date) VALUES (?, ?, ?, ?)');
                    $ins->execute([$productId, $lot, $batchQty, $expiry]);
                    $pdo->commit();
                    $message = 'Batch/lot "' . $lot . '" geregistreerd en voorraad atomisch bijgewerkt (+' . $batchQty . ').';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $message = 'Batch registreren mislukt: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }
    } elseif ($action === 'delete_batch') {
        if (!canMoveStock($user['role'])) {
            $message = 'Onvoldoende rechten.';
            $msgType = 'error';
        } else {
            $batchId = (int) ($_POST['batch_id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM voorraad_batches WHERE id = ?');
            $stmt->execute([$batchId]);
            $message = 'Batchregistratie verwijderd (dit corrigeert alleen de administratie, niet de voorraadpositie zelf).';
        }
    } elseif ($action === 'csv_import') {
        if (!canManageProducts($user['role'])) {
            $message = 'Onvoldoende rechten voor bulk-import.';
            $msgType = 'error';
        } elseif (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $message = 'Kies eerst een geldig CSV-bestand.';
            $msgType = 'error';
        } else {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            $header = $handle ? fgetcsv($handle, 0, ',') : false;
            if ($handle === false || $header === false) {
                $message = 'Kon het CSV-bestand niet lezen.';
                $msgType = 'error';
            } else {
                $header = array_map(fn($h) => strtolower(trim((string) $h)), $header);
                $colMap = [
                    'naam' => 'name', 'sku' => 'sku', 'categorie' => 'category', 'aantal' => 'quantity',
                    'minimum_voorraad' => 'min_stock', 'maximum_voorraad' => 'max_stock', 'locatie' => 'location',
                    'verkoopprijs' => 'unit_price', 'inkoopprijs' => 'purchase_price',
                    'leverancier' => 'supplier_name', 'leverancier_email' => 'supplier_email', 'status' => 'status',
                ];
                $created = 0;
                $updated = 0;
                $errors = [];
                $rowNum = 1;

                $pdo->beginTransaction();
                try {
                    while (($row = fgetcsv($handle, 0, ',')) !== false) {
                        $rowNum++;
                        if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) {
                            continue;
                        }
                        $data = [];
                        foreach ($header as $i => $h) {
                            if (isset($colMap[$h])) {
                                $data[$colMap[$h]] = trim((string) ($row[$i] ?? ''));
                            }
                        }
                        if (empty($data['name']) || empty($data['sku']) || empty($data['category'])) {
                            $errors[] = "Rij {$rowNum}: naam, sku en categorie zijn verplicht — overgeslagen.";
                            continue;
                        }
                        $rowStatus = in_array($data['status'] ?? '', ['actief', 'uitverkocht', 'stopgezet'], true) ? $data['status'] : 'actief';
                        $qty   = max(0, (int) ($data['quantity'] ?? 0));
                        $minS  = max(0, (int) ($data['min_stock'] ?? 5));
                        $maxS  = max(0, (int) ($data['max_stock'] ?? 0));
                        $unitP = max(0.0, (float) str_replace(',', '.', (string) ($data['unit_price'] ?? '0')));
                        $purP  = max(0.0, (float) str_replace(',', '.', (string) ($data['purchase_price'] ?? '0')));

                        $find = $pdo->prepare('SELECT id FROM voorraad_products WHERE sku = ?');
                        $find->execute([$data['sku']]);
                        $existingId = $find->fetchColumn();

                        if ($existingId) {
                            $upd = $pdo->prepare('UPDATE voorraad_products SET name=?, category=?, quantity=?, min_stock=?, max_stock=?, location=?, unit_price=?, purchase_price=?, supplier_name=?, supplier_email=?, status=? WHERE id=?');
                            $upd->execute([
                                $data['name'], $data['category'], $qty, $minS, $maxS, $data['location'] ?? '',
                                $unitP, $purP, $data['supplier_name'] ?? '', $data['supplier_email'] ?? '', $rowStatus, $existingId,
                            ]);
                            $updated++;
                        } else {
                            $ins = $pdo->prepare('INSERT INTO voorraad_products (name, sku, category, quantity, min_stock, max_stock, location, unit_price, purchase_price, supplier_name, supplier_email, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                            $ins->execute([
                                $data['name'], $data['sku'], $data['category'], $qty, $minS, $maxS, $data['location'] ?? '',
                                $unitP, $purP, $data['supplier_name'] ?? '', $data['supplier_email'] ?? '', $rowStatus,
                            ]);
                            $created++;
                        }
                    }
                    $pdo->commit();
                    fclose($handle);
                    $message = "CSV-import voltooid: {$created} nieuw aangemaakt, {$updated} bijgewerkt.";
                    if ($errors) {
                        $message .= ' Overgeslagen: ' . implode(' ', array_slice($errors, 0, 5));
                        $msgType = 'error';
                    }
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    fclose($handle);
                    $message = 'CSV-import volledig teruggedraaid door een fout: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }
    }
}

// ── Data ─────────────────────────────────────────────────────────────────
$products = $pdo->query("
    SELECT p.*,
        COALESCE(SUM(CASE WHEN m.type = 'in' THEN m.quantity ELSE 0 END), 0) AS total_in,
        COALESCE(SUM(CASE WHEN m.type = 'uit' THEN m.quantity ELSE 0 END), 0) AS total_out,
        MAX(m.created_at) AS last_movement
    FROM voorraad_products p
    LEFT JOIN voorraad_movements m ON m.product_id = p.id
    GROUP BY p.id
    ORDER BY p.name
")->fetchAll();

$batchRows = $pdo->query('SELECT * FROM voorraad_batches ORDER BY received_at DESC')->fetchAll();
$batchesByProduct = [];
foreach ($batchRows as $b) {
    $batchesByProduct[(int) $b['product_id']][] = $b;
}

$categories = [];
$locations = [];
foreach ($products as $p) {
    $categories[$p['category']] = true;
    if ($p['location'] !== '') {
        $locations[$p['location']] = true;
    }
}

renderPageStart('Artikelen', 'artikelen');
renderFlash($message, $msgType);
$canManage = canManageProducts($user['role']);
?>

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="text-xl font-bold text-slate-900">Artikelen</h1>
        <p class="text-sm text-slate-500">Artikelbeheer, voorraadposities, prijsbeheer en batch-/lot-tracking.</p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="<?= BASE ?>/artikelen.php?export=csv" class="hz-btn hz-btn--secondary">CSV exporteren</a>
        <?php if ($canManage): ?>
            <button type="button" data-hz-modal-open="importModal" class="hz-btn hz-btn--secondary">CSV-bulkimport</button>
            <button type="button" data-hz-modal-open="productModal" onclick="prepAddModal()" class="hz-btn hz-btn--primary">+ Artikel toevoegen</button>
        <?php endif; ?>
    </div>
</div>

<!-- ── Zoek & filter ────────────────────────────────────────────────────── -->
<div class="hz-card mb-4">
    <div class="flex flex-wrap gap-3">
        <input type="text" id="search" placeholder="Zoek op naam of SKU..."
               class="flex-1 min-w-[200px] px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
        <select id="filterCategory" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
            <option value="">Alle categorieën</option>
            <?php foreach (array_keys($categories) as $cat): ?>
                <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="filterLocation" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
            <option value="">Alle magazijnlocaties</option>
            <?php foreach (array_keys($locations) as $loc): ?>
                <option value="<?= e($loc) ?>"><?= e($loc) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="filterStatus" class="px-3 py-2 border border-slate-300 rounded-lg text-sm">
            <option value="">Alle statussen</option>
            <option value="actief">Actief</option>
            <option value="uitverkocht">Uitverkocht</option>
            <option value="stopgezet">Stopgezet</option>
        </select>
    </div>
</div>

<!-- ── Bulk-select formulier + tabel ────────────────────────────────────── -->
<form method="post" id="bulkForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="bulk_delete">
    <div class="hz-card">
        <?php if ($canManage): ?>
        <div class="flex items-center justify-between mb-3">
            <p class="text-sm text-slate-500"><span id="selCount">0</span> geselecteerd</p>
            <button type="submit" class="hz-btn hz-btn--danger" data-hz-confirm="Weet je zeker dat je alle geselecteerde artikelen wilt verwijderen? Dit kan niet ongedaan worden gemaakt.">
                Verwijder selectie
            </button>
        </div>
        <?php endif; ?>
        <div class="overflow-x-auto">
            <table class="hz-table" data-hz-sortable id="productTable">
                <thead>
                <tr>
                    <?php if ($canManage): ?><th style="cursor:default;"><input type="checkbox" class="hz-checkbox" data-hz-select-all></th><?php endif; ?>
                    <th data-key="name">Artikel</th>
                    <th data-key="sku">SKU</th>
                    <th data-key="category">Categorie</th>
                    <th data-key="quantity">Voorraadpositie</th>
                    <th data-key="location">Magazijnlocatie</th>
                    <th data-key="value">Waarde (inkoop)</th>
                    <th>Status</th>
                    <th>Acties</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $p):
                    $low = (int) $p['quantity'] < (int) $p['min_stock'];
                    $over = (int) $p['max_stock'] > 0 && (int) $p['quantity'] > (int) $p['max_stock'];
                    $value = (int) $p['quantity'] * (float) $p['purchase_price'];
                    $rid = 'detail' . (int) $p['id'];
                ?>
                <tr data-row
                    data-name="<?= e(strtolower($p['name'])) ?>"
                    data-sku="<?= e(strtolower($p['sku'])) ?>"
                    data-category="<?= e($p['category']) ?>"
                    data-location="<?= e($p['location']) ?>"
                    data-status="<?= e($p['status']) ?>">
                    <?php if ($canManage): ?><td><input type="checkbox" class="hz-checkbox" name="ids[]" value="<?= (int) $p['id'] ?>"></td><?php endif; ?>
                    <td data-col="name">
                        <button type="button" data-hz-expand="<?= $rid ?>" class="hz-table__expand-btn" title="Details">&#9662;</button>
                        <span class="font-medium text-slate-800"><?= e($p['name']) ?></span>
                        <?php if ($low): ?><span class="hz-badge hz-badge--red ml-1">Laag</span><?php endif; ?>
                        <?php if ($over): ?><span class="hz-badge hz-badge--orange ml-1">Over</span><?php endif; ?>
                    </td>
                    <td data-col="sku" class="font-mono text-xs text-slate-500"><?= e($p['sku']) ?></td>
                    <td data-col="category"><span class="hz-badge hz-badge--gray"><?= e($p['category']) ?></span></td>
                    <td data-col="quantity" class="<?= $low ? 'text-red-600 font-semibold' : '' ?>"><?= (int) $p['quantity'] ?> / min. <?= (int) $p['min_stock'] ?></td>
                    <td data-col="location"><?= e($p['location']) ?></td>
                    <td data-col="value"><?= nl_euro((float) $value) ?></td>
                    <td><?= productStatusBadge($p['status']) ?></td>
                    <td>
                        <?php if ($canManage): ?>
                        <button type="button" onclick='openEditModal(<?= json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)' class="hz-icon-btn" title="Bewerken">&#9998;</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="hz-table__detail-row" id="<?= $rid ?>" style="display:none;">
                    <td colspan="<?= $canManage ? 8 : 7 ?>">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                            <div>
                                <p class="text-xs uppercase text-slate-400 mb-1">Statistieken</p>
                                <p>Totaal ontvangen: <strong><?= (int) $p['total_in'] ?></strong></p>
                                <p>Totaal uitgegeven: <strong><?= (int) $p['total_out'] ?></strong></p>
                                <p>Laatste mutatie: <?= nl_datum($p['last_movement']) ?></p>
                                <p>Verkoopprijs: <?= nl_euro((float) $p['unit_price']) ?> &middot; Inkoopprijs: <?= nl_euro((float) $p['purchase_price']) ?></p>
                            </div>
                            <div>
                                <p class="text-xs uppercase text-slate-400 mb-1">Leverancier</p>
                                <p><?= e($p['supplier_name'] ?: '-') ?></p>
                                <p class="text-slate-500"><?= e($p['supplier_email'] ?: '-') ?></p>
                                <p class="text-slate-500">Herbestel-hoeveelheid: <?= (int) $p['reorder_qty'] ?></p>
                                <p class="text-slate-500">Overvoorraadgrens: <?= (int) $p['max_stock'] ?: '-' ?></p>
                            </div>
                            <div>
                                <p class="text-xs uppercase text-slate-400 mb-1 flex items-center justify-between">
                                    Batches/lots
                                    <?php if (canMoveStock($user['role'])): ?>
                                    <button type="button" data-hz-modal-open="batchModal" onclick='prepBatchModal(<?= json_encode(["id" => (int) $p["id"], "name" => $p["name"]], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)' class="text-brand-700 normal-case font-medium text-xs">+ toevoegen</button>
                                    <?php endif; ?>
                                </p>
                                <?php if (empty($batchesByProduct[(int) $p['id']])): ?>
                                    <p class="text-slate-400">Geen batches geregistreerd.</p>
                                <?php else: ?>
                                    <ul class="space-y-1">
                                    <?php foreach ($batchesByProduct[(int) $p['id']] as $b): ?>
                                        <li class="flex items-center justify-between gap-2">
                                            <span class="font-mono text-xs"><?= e($b['lot_number']) ?> (<?= (int) $b['quantity'] ?> st.)</span>
                                            <span class="text-xs text-slate-400"><?= $b['expiry_date'] ? 'THT ' . nl_datum($b['expiry_date'], 'd-m-Y') : nl_datum($b['received_at'], 'd-m-Y') ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$products): ?>
                    <tr><td colspan="8" class="text-center text-slate-400">Geen artikelen gevonden.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<!-- ── Product-modal (add/edit) — eigen <form>, los van bulkForm ───────── -->
<div class="hz-modal__backdrop" id="productModal">
    <div class="hz-modal" style="max-width:560px;">
        <form method="post" id="productForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="f_id" value="0">
            <div class="hz-modal__header">
                <h2 id="productModalTitle" class="text-lg font-semibold text-slate-800">Artikel toevoegen</h2>
                <button type="button" data-hz-modal-close class="hz-icon-btn">&times;</button>
            </div>
            <div class="grid grid-cols-2 gap-3 max-h-[65vh] overflow-y-auto pr-1">
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Artikelnaam *</label>
                    <input type="text" name="name" id="f_name" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">SKU *</label>
                    <input type="text" name="sku" id="f_sku" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Categorie *</label>
                    <input type="text" name="category" id="f_category" required list="categoryList" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    <datalist id="categoryList"><?php foreach (array_keys($categories) as $cat): ?><option value="<?= e($cat) ?>"><?php endforeach; ?></datalist>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Voorraadpositie</label>
                    <input type="number" name="quantity" id="f_quantity" min="0" value="0" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Minimumvoorraad</label>
                    <input type="number" name="min_stock" id="f_min_stock" min="0" value="5" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Overvoorraadgrens</label>
                    <input type="number" name="max_stock" id="f_max_stock" min="0" value="0" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Herbestel-hoeveelheid</label>
                    <input type="number" name="reorder_qty" id="f_reorder_qty" min="0" value="0" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Magazijnlocatie</label>
                    <input type="text" name="location" id="f_location" list="locationList" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                    <datalist id="locationList"><?php foreach (array_keys($locations) as $loc): ?><option value="<?= e($loc) ?>"><?php endforeach; ?></datalist>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Verkoopprijs (€)</label>
                    <input type="number" name="unit_price" id="f_unit_price" min="0" step="0.01" value="0" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Inkoopprijs (€)</label>
                    <input type="number" name="purchase_price" id="f_purchase_price" min="0" step="0.01" value="0" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Leverancier</label>
                    <input type="text" name="supplier_name" id="f_supplier_name" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Leverancier e-mail</label>
                    <input type="email" name="supplier_email" id="f_supplier_email" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-slate-600 mb-1">Status</label>
                    <select name="status" id="f_status" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                        <option value="actief">Actief</option>
                        <option value="uitverkocht">Uitverkocht</option>
                        <option value="stopgezet">Stopgezet</option>
                    </select>
                </div>
            </div>
            <div class="hz-modal__footer">
                <div id="f_deleteWrap" style="display:none;margin-right:auto;">
                    <button type="submit" name="do_delete" value="1" class="hz-btn hz-btn--danger" data-hz-confirm="Dit artikel definitief verwijderen? Dit kan niet ongedaan worden gemaakt.">Verwijderen</button>
                </div>
                <button type="button" data-hz-modal-close class="hz-btn hz-btn--secondary">Annuleren</button>
                <button type="submit" class="hz-btn hz-btn--primary">Opslaan</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Batch/lot-modal ──────────────────────────────────────────────────── -->
<div class="hz-modal__backdrop" id="batchModal">
    <div class="hz-modal" style="max-width:420px;">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_batch">
            <input type="hidden" name="product_id" id="b_product_id" value="0">
            <div class="hz-modal__header">
                <h2 class="text-lg font-semibold text-slate-800">Batch/lot ontvangen — <span id="b_product_name"></span></h2>
                <button type="button" data-hz-modal-close class="hz-icon-btn">&times;</button>
            </div>
            <p class="text-xs text-slate-500 mb-3">Dit registreert een nieuwe batch én verhoogt de voorraadpositie atomisch (transactie met rij-locking).</p>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Lotnummer *</label>
                    <input type="text" name="lot_number" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Aantal ontvangen *</label>
                    <input type="number" name="batch_quantity" min="1" value="1" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Houdbaar tot (optioneel)</label>
                    <input type="date" name="expiry_date" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
            </div>
            <div class="hz-modal__footer">
                <button type="button" data-hz-modal-close class="hz-btn hz-btn--secondary">Annuleren</button>
                <button type="submit" class="hz-btn hz-btn--primary">Registreren</button>
            </div>
        </form>
    </div>
</div>

<!-- ── CSV-bulkimport-modal ─────────────────────────────────────────────── -->
<div class="hz-modal__backdrop" id="importModal">
    <div class="hz-modal">
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="csv_import">
            <div class="hz-modal__header">
                <h2 class="text-lg font-semibold text-slate-800">CSV-bulkimport artikelen</h2>
                <button type="button" data-hz-modal-close class="hz-icon-btn">&times;</button>
            </div>
            <p class="text-sm text-slate-600 mb-2">Verwachte kolomkoppen (komma-gescheiden, bestaande SKU's worden bijgewerkt):</p>
            <p class="text-xs font-mono bg-slate-50 border border-slate-200 rounded-lg p-2 mb-3 break-words">naam,sku,categorie,aantal,minimum_voorraad,maximum_voorraad,locatie,verkoopprijs,inkoopprijs,leverancier,leverancier_email,status</p>
            <div class="hz-dropzone">
                <input type="file" name="csv_file" accept=".csv,text/csv" required>
                <p>Sleep een CSV-bestand hierheen of klik om te kiezen</p>
                <div class="hz-dropzone__preview"></div>
            </div>
            <div class="hz-modal__footer">
                <button type="button" data-hz-modal-close class="hz-btn hz-btn--secondary">Annuleren</button>
                <button type="submit" class="hz-btn hz-btn--primary">Importeren</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('search').addEventListener('input', applyFilters);
document.getElementById('filterCategory').addEventListener('change', applyFilters);
document.getElementById('filterLocation').addEventListener('change', applyFilters);
document.getElementById('filterStatus').addEventListener('change', applyFilters);

function applyFilters() {
    const q = document.getElementById('search').value.toLowerCase();
    const cat = document.getElementById('filterCategory').value;
    const loc = document.getElementById('filterLocation').value;
    const stat = document.getElementById('filterStatus').value;
    document.querySelectorAll('#productTable tbody tr[data-row]').forEach(function (row) {
        const matchSearch = !q || row.dataset.name.includes(q) || row.dataset.sku.includes(q);
        const matchCat = !cat || row.dataset.category === cat;
        const matchLoc = !loc || row.dataset.location === loc;
        const matchStat = !stat || row.dataset.status === stat;
        row.style.display = (matchSearch && matchCat && matchLoc && matchStat) ? '' : 'none';
    });
}

function prepAddModal() {
    document.getElementById('productModalTitle').textContent = 'Artikel toevoegen';
    document.getElementById('f_id').value = '0';
    ['name','sku','category','location','supplier_name','supplier_email'].forEach(function (k) { document.getElementById('f_' + k).value = ''; });
    document.getElementById('f_quantity').value = '0';
    document.getElementById('f_min_stock').value = '5';
    document.getElementById('f_max_stock').value = '0';
    document.getElementById('f_reorder_qty').value = '0';
    document.getElementById('f_unit_price').value = '0';
    document.getElementById('f_purchase_price').value = '0';
    document.getElementById('f_status').value = 'actief';
    document.getElementById('f_deleteWrap').style.display = 'none';
}

function openEditModal(p) {
    document.getElementById('productModal').classList.add('hz-is-open');
    document.getElementById('productModalTitle').textContent = 'Artikel bewerken';
    document.getElementById('f_id').value = p.id;
    document.getElementById('f_name').value = p.name;
    document.getElementById('f_sku').value = p.sku;
    document.getElementById('f_category').value = p.category;
    document.getElementById('f_quantity').value = p.quantity;
    document.getElementById('f_min_stock').value = p.min_stock;
    document.getElementById('f_max_stock').value = p.max_stock;
    document.getElementById('f_reorder_qty').value = p.reorder_qty;
    document.getElementById('f_location').value = p.location;
    document.getElementById('f_unit_price').value = p.unit_price;
    document.getElementById('f_purchase_price').value = p.purchase_price;
    document.getElementById('f_supplier_name').value = p.supplier_name;
    document.getElementById('f_supplier_email').value = p.supplier_email;
    document.getElementById('f_status').value = p.status;
    document.getElementById('f_deleteWrap').style.display = 'block';
}

function prepBatchModal(data) {
    document.getElementById('b_product_id').value = data.id;
    document.getElementById('b_product_name').textContent = data.name;
}

// Bulk-selectie teller (puur cosmetisch, geen invloed op confirm-gedrag van de submit-knop hierboven)
function updateSelCount() {
    var el = document.getElementById('selCount');
    if (!el) return;
    el.textContent = document.querySelectorAll('#bulkForm tbody input[type=checkbox]:checked').length;
}
document.querySelectorAll('#bulkForm input[type=checkbox]').forEach(function (cb) {
    cb.addEventListener('change', updateSelCount);
});
</script>

<?php renderPageEnd(); ?>
