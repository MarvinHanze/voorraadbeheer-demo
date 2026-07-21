<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/partials.php';
auth_check();

$user = currentUser();
$message = '';
$msgType = 'success';

if (!canManageSettings($user['role'])) {
    renderPageStart('Instellingen', 'instellingen');
    renderFlash('', '');
    ?>
    <div class="hz-card">
        <h1 class="text-lg font-bold text-slate-900 mb-2">Geen toegang</h1>
        <p class="text-sm text-slate-600">Instellingen (gebruikersbeheer &amp; systeemintegraties) zijn alleen
            toegankelijk voor de rol <strong>Beheerder</strong>. Je huidige rol is
            <strong><?= e(roleLabel($user['role'])) ?></strong>.</p>
    </div>
    <?php
    renderPageEnd();
    exit;
}

$platformLabels = ['shopify' => 'Shopify', 'magento' => 'Magento', 'woocommerce' => 'WooCommerce'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $action = $_POST['action'] ?? '';
    // De verwijderknop in de gebruiker-modal deelt zijn <form> met de hidden
    // action=save_user; om niet te leunen op document-volgorde bij dubbele
    // POST-sleutels gebruikt die knop een apart, ondubbelzinnig veld.
    if (($_POST['do_delete'] ?? '') === '1') {
        $action = 'delete_user';
    }

    if ($action === 'save_integration') {
        $platform = $_POST['platform'] ?? 'shopify';
        if (!isset($platformLabels[$platform])) {
            $platform = 'shopify';
        }
        $apiKey  = trim($_POST['api_key'] ?? '');
        $shopUrl = trim($_POST['shop_url'] ?? '');
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE voorraad_integration_settings SET platform = ?, api_key = ?, shop_url = ?, enabled = ? WHERE id = 1');
        $stmt->execute([$platform, $apiKey, $shopUrl, $enabled]);
        $message = 'Integratie-instellingen opgeslagen (mock — er is geen echte externe koppeling actief).';
    } elseif ($action === 'simulate_sync') {
        $settings = $pdo->query('SELECT * FROM voorraad_integration_settings WHERE id = 1')->fetch();
        if (!$settings || !$settings['enabled']) {
            $message = 'Schakel de integratie eerst in voordat je een synchronisatie simuleert.';
            $msgType = 'error';
        } else {
            $platform = $settings['platform'];
            $label = $platformLabels[$platform] ?? ucfirst($platform);
            $productCount = (int) $pdo->query('SELECT COUNT(*) FROM voorraad_products')->fetchColumn();
            $updatedCount = random_int(3, max(4, min(20, $productCount)));
            $newOrders = random_int(0, 5);
            $latencyMs = random_int(180, 640);

            logSync($pdo, $platform, "Verbinding met {$label}-API tot stand gebracht (DEMO, geen echte aanroep).", 'info');
            logSync($pdo, $platform, "{$updatedCount} artikel(en) gesynchroniseerd op basis van SKU.", 'success');
            if ($newOrders > 0) {
                logSync($pdo, $platform, "{$newOrders} nieuwe inkomende bestelling(en) opgehaald en voorraad dienovereenkomstig aangepast (gesimuleerd).", 'success');
            } else {
                logSync($pdo, $platform, 'Geen nieuwe bestellingen gevonden sinds de vorige synchronisatie.', 'info');
            }
            if (random_int(1, 10) === 1) {
                logSync($pdo, $platform, 'Eén artikel kon niet worden gematcht op SKU en is overgeslagen (voorbeeldwaarschuwing).', 'warning');
            }
            logSync($pdo, $platform, "Synchronisatie voltooid in {$latencyMs} ms.", 'success');

            $upd = $pdo->prepare('UPDATE voorraad_integration_settings SET last_sync = NOW() WHERE id = 1');
            $upd->execute();
            $message = "Synchronisatie met {$label} gesimuleerd — zie logregels hieronder.";
        }
    } elseif ($action === 'save_user') {
        $id    = (int) ($_POST['id'] ?? 0);
        $name  = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role  = $_POST['role'] ?? 'viewer';
        if (!in_array($role, ['beheerder', 'magazijnmedewerker', 'viewer'], true)) {
            $role = 'viewer';
        }
        if ($name === '' || $email === '') {
            $message = 'Naam en e-mailadres zijn verplicht.';
            $msgType = 'error';
        } else {
            try {
                if ($id > 0) {
                    // Bescherm tegen het degraderen van de laatst overgebleven beheerder.
                    if ($role !== 'beheerder') {
                        $beheerderCount = (int) $pdo->query("SELECT COUNT(*) FROM voorraad_users WHERE role = 'beheerder'")->fetchColumn();
                        $stmt = $pdo->prepare('SELECT role FROM voorraad_users WHERE id = ?');
                        $stmt->execute([$id]);
                        $currentRoleOfTarget = $stmt->fetchColumn();
                        if ($currentRoleOfTarget === 'beheerder' && $beheerderCount <= 1) {
                            $message = 'Kan de rol niet wijzigen: er moet minimaal één Beheerder overblijven.';
                            $msgType = 'error';
                        }
                    }
                    if ($message === '') {
                        $stmt = $pdo->prepare('UPDATE voorraad_users SET name = ?, email = ?, role = ? WHERE id = ?');
                        $stmt->execute([$name, $email, $role, $id]);
                        $message = 'Gebruiker "' . $name . '" bijgewerkt.';
                    }
                } else {
                    $stmt = $pdo->prepare('INSERT INTO voorraad_users (name, email, role, password_hash) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$name, $email, $role, DEMO_PASSWORD_HASH]);
                    $message = 'Gebruiker "' . $name . '" toegevoegd (demo-wachtwoord: demo123).';
                }
            } catch (PDOException $e) {
                $message = $e->getCode() === '23000' ? 'Dit e-mailadres is al in gebruik.' : 'Databasefout bij opslaan.';
                $msgType = 'error';
            }
        }
    } elseif ($action === 'delete_user') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === $user['id']) {
            $message = 'Je kunt je eigen account niet verwijderen.';
            $msgType = 'error';
        } else {
            $stmt = $pdo->prepare('SELECT role FROM voorraad_users WHERE id = ?');
            $stmt->execute([$id]);
            $targetRole = $stmt->fetchColumn();
            $beheerderCount = (int) $pdo->query("SELECT COUNT(*) FROM voorraad_users WHERE role = 'beheerder'")->fetchColumn();
            if ($targetRole === 'beheerder' && $beheerderCount <= 1) {
                $message = 'Kan de laatste Beheerder niet verwijderen.';
                $msgType = 'error';
            } else {
                $del = $pdo->prepare('DELETE FROM voorraad_users WHERE id = ?');
                $del->execute([$id]);
                $message = 'Gebruiker verwijderd.';
            }
        }
    }
}

$integration = $pdo->query('SELECT * FROM voorraad_integration_settings WHERE id = 1')->fetch();
$syncLog = $pdo->query('SELECT * FROM voorraad_sync_log ORDER BY id DESC LIMIT 25')->fetchAll();
$users = $pdo->query('SELECT * FROM voorraad_users ORDER BY role, name')->fetchAll();

renderPageStart('Instellingen', 'instellingen');
renderFlash($message, $msgType);
?>

<div class="mb-6">
    <h1 class="text-xl font-bold text-slate-900">Instellingen</h1>
    <p class="text-sm text-slate-500">Gebruikersbeheer en systeemintegraties. Alleen zichtbaar/beschikbaar voor de rol Beheerder.</p>
</div>

<!-- ── Mock e-commerce/inkoop-integratie ────────────────────────────────── -->
<div class="hz-card mb-8">
    <div class="hz-card__header">
        <h2 class="text-base font-semibold text-slate-900">E-commerce &amp; inkoop-koppeling</h2>
        <span class="hz-badge hz-badge--orange">MOCK — geen echte externe API</span>
    </div>
    <p class="text-sm text-slate-500 mb-4">
        Simuleert een koppeling met Shopify, Magento of WooCommerce zodat inkomende bestellingen
        "real-time" verwerkt zouden worden. Er wordt <strong>nooit</strong> een echte externe dienst aangeroepen —
        de API-sleutel hieronder wordt alleen lokaal opgeslagen.
    </p>
    <form method="post" class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_integration">
        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Platform</label>
            <select name="platform" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                <?php foreach ($platformLabels as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $integration['platform'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Webshop-URL</label>
            <input type="text" name="shop_url" value="<?= e($integration['shop_url']) ?>" placeholder="mijnwinkel.myshopify.com" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
        </div>
        <div class="sm:col-span-2">
            <label class="block text-xs font-medium text-slate-600 mb-1">API-sleutel (mock)</label>
            <input type="password" name="api_key" value="<?= e($integration['api_key']) ?>" placeholder="demo-api-key" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
        </div>
        <div class="sm:col-span-2 flex items-center gap-3">
            <label class="hz-toggle">
                <input type="checkbox" name="enabled" <?= $integration['enabled'] ? 'checked' : '' ?>>
                <span class="hz-toggle__track"></span>
            </label>
            <span class="text-sm text-slate-600">Integratie ingeschakeld</span>
        </div>
        <div class="sm:col-span-2">
            <button type="submit" class="hz-btn hz-btn--primary">Opslaan</button>
        </div>
    </form>

    <div class="flex items-center justify-between border-t border-slate-100 pt-4">
        <p class="text-sm text-slate-500">
            Laatste synchronisatie: <strong><?= nl_datum($integration['last_sync']) ?></strong>
        </p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="simulate_sync">
            <button type="submit" class="hz-btn hz-btn--secondary">Simuleer synchronisatie</button>
        </form>
    </div>

    <div class="mt-4 overflow-x-auto" style="max-height:260px;overflow-y:auto;">
        <table class="hz-table">
            <thead><tr><th>Tijdstip</th><th>Platform</th><th>Niveau</th><th>Bericht</th></tr></thead>
            <tbody>
            <?php foreach ($syncLog as $s): ?>
                <tr>
                    <td class="text-slate-400 whitespace-nowrap"><?= nl_datum($s['created_at']) ?></td>
                    <td><?= e($platformLabels[$s['platform']] ?? ucfirst($s['platform'])) ?></td>
                    <td><?= syncLevelBadge($s['level']) ?></td>
                    <td class="text-slate-600"><?= e($s['message']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$syncLog): ?>
                <tr><td colspan="4" class="text-center text-slate-400">Nog geen synchronisaties uitgevoerd.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Granulair gebruikersbeheer ────────────────────────────────────────── -->
<div class="hz-card">
    <div class="hz-card__header">
        <h2 class="text-base font-semibold text-slate-900">Gebruikers &amp; rollen</h2>
        <button type="button" data-hz-modal-open="userModal" onclick="prepAddUser()" class="hz-btn hz-btn--primary">+ Gebruiker toevoegen</button>
    </div>
    <p class="text-sm text-slate-500 mb-3">
        Rollen bepalen wie mag toevoegen/verplaatsen (Beheerder + Magazijnmedewerker), wie mag afboeken als
        derving (uitsluitend Beheerder) en wie alleen mag meekijken (Viewer).
    </p>
    <div class="overflow-x-auto">
        <table class="hz-table">
            <thead><tr><th>Naam</th><th>E-mail</th><th>Rol</th><th>Acties</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td class="font-medium text-slate-800"><?= e($u['name']) ?><?= (int) $u['id'] === $user['id'] ? ' <span class="text-xs text-slate-400">(jij)</span>' : '' ?></td>
                    <td class="text-slate-500"><?= e($u['email']) ?></td>
                    <td><span class="hz-badge <?= roleBadgeClass($u['role']) ?>"><?= e(roleLabel($u['role'])) ?></span></td>
                    <td>
                        <button type="button" onclick='openEditUser(<?= json_encode($u, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)' class="hz-icon-btn" title="Bewerken">&#9998;</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Gebruiker-modal ──────────────────────────────────────────────────── -->
<div class="hz-modal__backdrop" id="userModal">
    <div class="hz-modal" style="max-width:420px;">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_user">
            <input type="hidden" name="id" id="u_id" value="0">
            <div class="hz-modal__header">
                <h2 id="userModalTitle" class="text-lg font-semibold text-slate-800">Gebruiker toevoegen</h2>
                <button type="button" data-hz-modal-close class="hz-icon-btn">&times;</button>
            </div>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Naam *</label>
                    <input type="text" name="name" id="u_name" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">E-mailadres *</label>
                    <input type="email" name="email" id="u_email" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Rol *</label>
                    <select name="role" id="u_role" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm">
                        <option value="beheerder">Beheerder</option>
                        <option value="magazijnmedewerker">Magazijnmedewerker</option>
                        <option value="viewer">Viewer</option>
                    </select>
                </div>
                <p class="text-xs text-slate-400" id="u_pwHint">Nieuwe gebruikers krijgen het demo-wachtwoord <code>demo123</code>.</p>
            </div>
            <div class="hz-modal__footer">
                <div id="u_deleteWrap" style="display:none;margin-right:auto;">
                    <button type="submit" name="do_delete" value="1" class="hz-btn hz-btn--danger" data-hz-confirm="Deze gebruiker verwijderen?">Verwijderen</button>
                </div>
                <button type="button" data-hz-modal-close class="hz-btn hz-btn--secondary">Annuleren</button>
                <button type="submit" class="hz-btn hz-btn--primary">Opslaan</button>
            </div>
        </form>
    </div>
</div>

<script>
function prepAddUser() {
    document.getElementById('userModalTitle').textContent = 'Gebruiker toevoegen';
    document.getElementById('u_id').value = '0';
    document.getElementById('u_name').value = '';
    document.getElementById('u_email').value = '';
    document.getElementById('u_role').value = 'viewer';
    document.getElementById('u_deleteWrap').style.display = 'none';
    document.getElementById('u_pwHint').style.display = 'block';
}
function openEditUser(u) {
    document.getElementById('userModal').classList.add('hz-is-open');
    document.getElementById('userModalTitle').textContent = 'Gebruiker bewerken';
    document.getElementById('u_id').value = u.id;
    document.getElementById('u_name').value = u.name;
    document.getElementById('u_email').value = u.email;
    document.getElementById('u_role').value = u.role;
    document.getElementById('u_deleteWrap').style.display = 'block';
    document.getElementById('u_pwHint').style.display = 'none';
}
</script>

<?php renderPageEnd(); ?>
