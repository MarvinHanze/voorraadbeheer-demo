<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
auth_check();

// --- API handling for AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'save') {
        $id       = (int) ($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $sku      = trim($_POST['sku'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $qty      = max(0, (int) ($_POST['quantity'] ?? 0));
        $minStock = max(0, (int) ($_POST['min_stock'] ?? 5));
        $location = trim($_POST['location'] ?? '');
        $price    = max(0, (float) ($_POST['unit_price'] ?? 0));
        $status   = $_POST['status'] ?? 'actief';

        if ($name === '' || $sku === '' || $category === '') {
            echo json_encode(['ok' => false, 'error' => 'Verplichte velden ontbreken']);
            exit;
        }

        $allowedStatus = ['actief', 'uitverkocht', 'stopgezet'];
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'actief';
        }

        if ($qty < $minStock && $status === 'actief') {
            $status = 'actief'; // keep actief, warning shown in UI
        }
        if ($qty === 0 && $status === 'actief') {
            $status = 'uitverkocht';
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE voorraad_products SET name=?, sku=?, category=?, quantity=?, min_stock=?, location=?, unit_price=?, status=? WHERE id=?");
                $stmt->execute([$name, $sku, $category, $qty, $minStock, $location, $price, $status, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO voorraad_products (name, sku, category, quantity, min_stock, location, unit_price, status) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->execute([$name, $sku, $category, $qty, $minStock, $location, $price, $status]);
            }
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                echo json_encode(['ok' => false, 'error' => 'SKU bestaat al']);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Database fout']);
            }
        }
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM voorraad_products WHERE id = ?");
            $stmt->execute([$id]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Onbekende actie']);
    exit;
}

// --- Fetch data ---
$products = $pdo->query("
    SELECT p.*, 
           COALESCE(SUM(CASE WHEN m.type = 'in' THEN m.quantity ELSE 0 END), 0) as total_in,
           COALESCE(SUM(CASE WHEN m.type = 'uit' THEN m.quantity ELSE 0 END), 0) as total_out
    FROM voorraad_products p
    LEFT JOIN voorraad_movements m ON m.product_id = p.id
    GROUP BY p.id
    ORDER BY p.name
")->fetchAll();

$totalProducts  = count($products);
$lowStock       = 0;
$totalValue     = 0.0;
$locations      = [];
$categories     = [];

foreach ($products as $p) {
    if ($p['quantity'] < $p['min_stock']) {
        $lowStock++;
    }
    $totalValue += (float) $p['quantity'] * (float) $p['unit_price'];
    $locations[$p['location']]  = true;
    $categories[$p['category']] = true;
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voorraadbeheer Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { brand: '#f59e0b' } } } }
    </script>
    <style>
        .modal-overlay { background: rgba(0,0,0,0.4); }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen">

<!-- Nav -->
<nav class="bg-white border-b border-slate-200 sticky top-0 z-30">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-14 flex items-center justify-between">
        <div class="flex items-center gap-2.5">
            <div class="w-8 h-8 bg-brand rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
            </div>
            <span class="font-bold text-slate-800">Voorraadbeheer</span>
        </div>
        <a href="<?= e(BASE) ?>/logout.php" class="text-sm text-slate-500 hover:text-slate-700 transition-colors flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
            Uitloggen
        </a>
    </div>
</nav>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

    <!-- Stat cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-blue-50 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Totaal Producten</p>
                    <p class="text-xl font-bold text-slate-800"><?= $totalProducts ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-red-50 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Lager Voorraad</p>
                    <p class="text-xl font-bold text-slate-800"><?= $lowStock ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-green-50 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Waarde Voorraad</p>
                    <p class="text-xl font-bold text-slate-800">&euro; <?= number_format($totalValue, 2, ',', '.') ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-purple-50 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Aantal Locaties</p>
                    <p class="text-xl font-bold text-slate-800"><?= count($locations) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 mb-6">
        <div class="p-4 flex flex-col sm:flex-row gap-3 items-start sm:items-center justify-between">
            <div class="flex flex-col sm:flex-row gap-3 flex-1 w-full sm:w-auto">
                <input type="text" id="search" placeholder="Zoek producten..."
                    class="flex-1 min-w-0 px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
                <select id="filterCategory"
                    class="px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
                    <option value="">Alle categorieën</option>
                    <?php foreach (array_keys($categories) as $cat): ?>
                        <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filterLocation"
                    class="px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
                    <option value="">Alle locaties</option>
                    <?php foreach (array_keys($locations) as $loc): ?>
                        <option value="<?= e($loc) ?>"><?= e($loc) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filterStatus"
                    class="px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
                    <option value="">Alle statussen</option>
                    <option value="actief">Actief</option>
                    <option value="uitverkocht">Uitverkocht</option>
                    <option value="stopgezet">Stopgezet</option>
                </select>
            </div>
            <button onclick="openModal()"
                class="bg-brand hover:bg-amber-600 text-white font-medium px-4 py-2 rounded-lg text-sm transition-colors flex items-center gap-2 whitespace-nowrap">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Product toevoegen
            </button>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-t border-slate-200 bg-slate-50">
                        <th class="text-left px-4 py-3 font-medium text-slate-600">Product</th>
                        <th class="text-left px-4 py-3 font-medium text-slate-600">SKU</th>
                        <th class="text-left px-4 py-3 font-medium text-slate-600 hidden md:table-cell">Categorie</th>
                        <th class="text-right px-4 py-3 font-medium text-slate-600">Voorraad</th>
                        <th class="text-right px-4 py-3 font-medium text-slate-600 hidden lg:table-cell">Min.</th>
                        <th class="text-left px-4 py-3 font-medium text-slate-600 hidden lg:table-cell">Locatie</th>
                        <th class="text-right px-4 py-3 font-medium text-slate-600 hidden sm:table-cell">Prijs</th>
                        <th class="text-center px-4 py-3 font-medium text-slate-600">Status</th>
                        <th class="text-right px-4 py-3 font-medium text-slate-600">Acties</th>
                    </tr>
                </thead>
                <tbody id="productTable">
                    <?php foreach ($products as $p):
                        $low = $p['quantity'] < $p['min_stock'];
                    ?>
                    <tr class="border-t border-slate-100 hover:bg-slate-50 transition-colors product-row"
                        data-name="<?= e(strtolower($p['name'])) ?>"
                        data-sku="<?= e(strtolower($p['sku'])) ?>"
                        data-category="<?= e($p['category']) ?>"
                        data-location="<?= e($p['location']) ?>"
                        data-status="<?= e($p['status']) ?>">
                        <td class="px-4 py-3 font-medium text-slate-800"><?= e($p['name']) ?></td>
                        <td class="px-4 py-3 text-slate-500 font-mono text-xs"><?= e($p['sku']) ?></td>
                        <td class="px-4 py-3 text-slate-600 hidden md:table-cell">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-700">
                                <?= e($p['category']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right font-medium <?= $low ? 'text-red-600' : 'text-slate-800' ?>">
                            <?= $p['quantity'] ?>
                            <?php if ($low): ?>
                                <svg class="w-3.5 h-3.5 inline ml-1 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                </svg>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-right text-slate-500 hidden lg:table-cell"><?= $p['min_stock'] ?></td>
                        <td class="px-4 py-3 text-slate-600 hidden lg:table-cell"><?= e($p['location']) ?></td>
                        <td class="px-4 py-3 text-right text-slate-800 hidden sm:table-cell">&euro; <?= number_format((float)$p['unit_price'], 2, ',', '.') ?></td>
                        <td class="px-4 py-3 text-center">
                            <?php
                                $badgeClass = match($p['status']) {
                                    'actief'    => 'bg-green-50 text-green-700',
                                    'uitverkocht' => 'bg-red-50 text-red-700',
                                    'stopgezet' => 'bg-slate-100 text-slate-500',
                                    default     => 'bg-slate-100 text-slate-700',
                                };
                            ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $badgeClass ?>">
                                <?= e(ucfirst($p['status'])) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button onclick='editProduct(<?= json_encode($p) ?>)'
                                class="text-slate-400 hover:text-brand transition-colors p-1" title="Bewerken">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </button>
                            <button onclick="deleteProduct(<?= (int) $p['id'] ?>, '<?= e($p['name']) ?>')"
                                class="text-slate-400 hover:text-red-600 transition-colors p-1" title="Verwijderen">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- Modal -->
<div id="modal" class="fixed inset-0 z-50 hidden items-center justify-center p-4 modal-overlay">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200">
            <h2 id="modalTitle" class="text-lg font-semibold text-slate-800">Product toevoegen</h2>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form id="productForm" class="p-6 space-y-4" onsubmit="return saveProduct(event)">
            <input type="hidden" id="formId" value="0">
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Naam *</label>
                    <input type="text" id="formName" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">SKU *</label>
                    <input type="text" id="formSku" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Categorie *</label>
                    <input type="text" id="formCategory" required list="categoryList" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
                    <datalist id="categoryList">
                        <?php foreach (array_keys($categories) as $cat): ?>
                            <option value="<?= e($cat) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Voorraad</label>
                    <input type="number" id="formQuantity" min="0" value="0" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Min. Voorraad</label>
                    <input type="number" id="formMinStock" min="0" value="5" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Locatie</label>
                    <input type="text" id="formLocation" list="locationList" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
                    <datalist id="locationList">
                        <?php foreach (array_keys($locations) as $loc): ?>
                            <option value="<?= e($loc) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Prijs (€)</label>
                    <input type="number" id="formPrice" min="0" step="0.01" value="0" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                    <select id="formStatus" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
                        <option value="actief">Actief</option>
                        <option value="uitverkocht">Uitverkocht</option>
                        <option value="stopgezet">Stopgezet</option>
                    </select>
                </div>
            </div>
            <div id="formError" class="hidden bg-red-50 text-red-700 text-sm rounded-lg px-4 py-3"></div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 hover:bg-slate-50 transition-colors">Annuleren</button>
                <button type="submit" class="bg-brand hover:bg-amber-600 text-white font-medium px-4 py-2 rounded-lg text-sm transition-colors">Opslaan</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete confirm -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4 modal-overlay">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-sm p-6">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 bg-red-50 rounded-full flex items-center justify-center">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </div>
            <div>
                <h3 class="font-semibold text-slate-800">Product verwijderen</h3>
                <p id="deleteName" class="text-sm text-slate-500"></p>
            </div>
        </div>
        <p class="text-sm text-slate-600 mb-6">Weet je zeker dat je dit product wilt verwijderen? Dit kan niet ongedaan worden gemaakt.</p>
        <div class="flex justify-end gap-3">
            <button onclick="closeDeleteModal()" class="px-4 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 hover:bg-slate-50 transition-colors">Annuleren</button>
            <button id="confirmDeleteBtn" class="bg-red-600 hover:bg-red-700 text-white font-medium px-4 py-2 rounded-lg text-sm transition-colors">Verwijderen</button>
        </div>
    </div>
</div>

<script>
const BASE = '<?= e(BASE) ?>';
let deleteId = null;

// --- Search & Filter ---
document.getElementById('search').addEventListener('input', applyFilters);
document.getElementById('filterCategory').addEventListener('change', applyFilters);
document.getElementById('filterLocation').addEventListener('change', applyFilters);
document.getElementById('filterStatus').addEventListener('change', applyFilters);

function applyFilters() {
    const q = document.getElementById('search').value.toLowerCase();
    const cat = document.getElementById('filterCategory').value;
    const loc = document.getElementById('filterLocation').value;
    const stat = document.getElementById('filterStatus').value;

    document.querySelectorAll('.product-row').forEach(row => {
        const name = row.dataset.name;
        const sku = row.dataset.sku;
        const matchSearch = !q || name.includes(q) || sku.includes(q);
        const matchCat = !cat || row.dataset.category === cat;
        const matchLoc = !loc || row.dataset.location === loc;
        const matchStat = !stat || row.dataset.status === stat;
        row.style.display = (matchSearch && matchCat && matchLoc && matchStat) ? '' : 'none';
    });
}

// --- Modal ---
function openModal() {
    document.getElementById('modalTitle').textContent = 'Product toevoegen';
    document.getElementById('formId').value = '0';
    document.getElementById('formName').value = '';
    document.getElementById('formSku').value = '';
    document.getElementById('formCategory').value = '';
    document.getElementById('formQuantity').value = '0';
    document.getElementById('formMinStock').value = '5';
    document.getElementById('formLocation').value = '';
    document.getElementById('formPrice').value = '0';
    document.getElementById('formStatus').value = 'actief';
    document.getElementById('formError').classList.add('hidden');
    const m = document.getElementById('modal');
    m.classList.remove('hidden');
    m.classList.add('flex');
}

function closeModal() {
    const m = document.getElementById('modal');
    m.classList.add('hidden');
    m.classList.remove('flex');
}

function editProduct(p) {
    document.getElementById('modalTitle').textContent = 'Product bewerken';
    document.getElementById('formId').value = p.id;
    document.getElementById('formName').value = p.name;
    document.getElementById('formSku').value = p.sku;
    document.getElementById('formCategory').value = p.category;
    document.getElementById('formQuantity').value = p.quantity;
    document.getElementById('formMinStock').value = p.min_stock;
    document.getElementById('formLocation').value = p.location;
    document.getElementById('formPrice').value = p.unit_price;
    document.getElementById('formStatus').value = p.status;
    document.getElementById('formError').classList.add('hidden');
    const m = document.getElementById('modal');
    m.classList.remove('hidden');
    m.classList.add('flex');
}

async function saveProduct(e) {
    e.preventDefault();
    const fd = new FormData();
    fd.append('action', 'save');
    fd.append('id', document.getElementById('formId').value);
    fd.append('name', document.getElementById('formName').value);
    fd.append('sku', document.getElementById('formSku').value);
    fd.append('category', document.getElementById('formCategory').value);
    fd.append('quantity', document.getElementById('formQuantity').value);
    fd.append('min_stock', document.getElementById('formMinStock').value);
    fd.append('location', document.getElementById('formLocation').value);
    fd.append('unit_price', document.getElementById('formPrice').value);
    fd.append('status', document.getElementById('formStatus').value);

    const res = await fetch(BASE + '/index.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
        location.reload();
    } else {
        const err = document.getElementById('formError');
        err.textContent = data.error || 'Er is een fout opgetreden';
        err.classList.remove('hidden');
    }
}

// --- Delete ---
function deleteProduct(id, name) {
    deleteId = id;
    document.getElementById('deleteName').textContent = name;
    const m = document.getElementById('deleteModal');
    m.classList.remove('hidden');
    m.classList.add('flex');
    document.getElementById('confirmDeleteBtn').onclick = confirmDelete;
}

function closeDeleteModal() {
    const m = document.getElementById('deleteModal');
    m.classList.add('hidden');
    m.classList.remove('flex');
    deleteId = null;
}

async function confirmDelete() {
    if (!deleteId) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', deleteId);
    await fetch(BASE + '/index.php', { method: 'POST', body: fd });
    location.reload();
}

// Close modals on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal();
        closeDeleteModal();
    }
});
</script>

</body>
</html>
