<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/partials.php'; // voor proposalStatusBadge() — renderPageStart/End worden hier niet gebruikt (publieke standalone pagina)
// Bewust GEEN auth_check(): dit is de publieke "magic link"-pagina die een
// leverancier (extern, niet ingelogd) zou openen vanuit de gesimuleerde
// inkoopvoorstel-e-mail. Uitsluitend te bereiken met een geldig token.

$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$notice = '';
$noticeType = 'info';

$stmt = $pdo->prepare('SELECT e.*, p.name AS product_name, p.sku, p.location FROM voorraad_email_log e LEFT JOIN voorraad_products p ON p.id = e.product_id WHERE e.token = ? LIMIT 1');
$stmt->execute([$token]);
$proposal = $token !== '' ? $stmt->fetch() : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    verifyCSRF();
    if (!$proposal) {
        $notice = 'Ongeldige of onbekende bevestigingslink.';
        $noticeType = 'error';
    } elseif ($proposal['status'] !== 'verzonden') {
        $notice = 'Deze link is niet (meer) actief.';
        $noticeType = 'error';
    } else {
        try {
            $pdo->beginTransaction();
            if ($proposal['product_id']) {
                applyStockMutation(
                    $pdo,
                    (int) $proposal['product_id'],
                    'in',
                    (int) $proposal['proposed_qty'],
                    'ontvangst',
                    'INKOOP-' . substr($proposal['token'], 0, 8),
                    'Levering bevestigd via magic link (DEMO-simulatie, geen echte leverancierskoppeling)',
                    'Leverancier (magic link, demo)'
                );
            }
            $upd = $pdo->prepare("UPDATE voorraad_email_log SET status = 'bevestigd', confirmed_at = NOW() WHERE id = ?");
            $upd->execute([$proposal['id']]);
            $pdo->commit();
            $notice = 'Bedankt! De levering is bevestigd en de voorraad is bijgewerkt (gesimuleerd).';
            $noticeType = 'success';
            // Herlaad de proposal-gegevens voor de weergave hieronder.
            $stmt->execute([$token]);
            $proposal = $stmt->fetch();
        } catch (RuntimeException $e) {
            // Verwachte, veilige business-regel-melding — mag getoond worden (ook op deze
            // publieke, niet-ingelogde pagina).
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $notice = 'Bevestigen mislukt: ' . $e->getMessage();
            $noticeType = 'error';
        } catch (Throwable $e) {
            // Onverwachte fout: dit is een publieke, niet-ingelogde pagina — nooit paden/SQL
            // lekken naar een anonieme bezoeker. Wel server-side loggen.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Voorraadbeheer] Magic-link bevestiging mislukt: ' . $e->getMessage());
            $notice = 'Bevestigen mislukt door een onverwachte fout. Probeer het later opnieuw.';
            $noticeType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Levering bevestigen — Voorraadbeheer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { brand: { 500: '#f59e0b', 600: '#d97706' } } } } }
    </script>
    <link rel="stylesheet" href="<?= BASE ?>/assets/css/components.css">
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center px-4 py-10">
<div class="w-full max-w-lg">
    <div class="mb-4 px-4 py-2 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-xs text-center font-medium">
        DEMO/SIMULATIE — dit is een gesimuleerde magic-link-pagina. Er is geen echte e-mail verzonden en geen
        externe leverancier betrokken; deze pagina bestaat uitsluitend om de "trigger-based inkoop"-flow te tonen.
    </div>

    <div class="hz-card">
        <?php if ($notice): ?>
            <div class="mb-4 px-4 py-3 rounded-lg text-sm font-medium <?= $noticeType === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200' ?>">
                <?= e($notice) ?>
            </div>
        <?php endif; ?>

        <?php if (!$proposal): ?>
            <h1 class="text-lg font-bold text-slate-900 mb-2">Link niet gevonden</h1>
            <p class="text-sm text-slate-500">Deze bevestigingslink is ongeldig of onbekend.</p>
        <?php else: ?>
            <h1 class="text-lg font-bold text-slate-900 mb-1">Inkoopvoorstel</h1>
            <p class="text-sm text-slate-500 mb-4"><?= e($proposal['subject']) ?></p>

            <div class="grid grid-cols-2 gap-3 text-sm mb-4">
                <div><p class="text-xs text-slate-400 uppercase">Artikel</p><p class="font-medium"><?= e($proposal['product_name'] ?? '-') ?></p></div>
                <div><p class="text-xs text-slate-400 uppercase">SKU</p><p class="font-mono"><?= e($proposal['sku'] ?? '-') ?></p></div>
                <div><p class="text-xs text-slate-400 uppercase">Gevraagd aantal</p><p class="font-medium"><?= (int) $proposal['proposed_qty'] ?> stuks</p></div>
                <div><p class="text-xs text-slate-400 uppercase">Status</p><p><?= proposalStatusBadge($proposal['status']) ?></p></div>
            </div>

            <?php if ($proposal['status'] === 'verzonden'): ?>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="confirm">
                    <input type="hidden" name="token" value="<?= e($proposal['token']) ?>">
                    <button type="submit" class="hz-btn hz-btn--primary w-full">Bevestig levering (simulatie)</button>
                </form>
            <?php elseif ($proposal['status'] === 'bevestigd'): ?>
                <p class="text-sm text-emerald-700">Deze levering is al bevestigd op <?= nl_datum($proposal['confirmed_at']) ?>.</p>
            <?php else: ?>
                <p class="text-sm text-slate-500">Deze link is verlopen.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
