<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE . '/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if ($email === '' || $pass === '') {
        $error = 'Vul e-mailadres en wachtwoord in.';
    } elseif (($lockoutMsg = loginLockoutMessage($pdo, $email)) !== null) {
        $error = $lockoutMsg;
    } else {
        $stmt = $pdo->prepare('SELECT * FROM voorraad_users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user !== false && $user['password_hash'] && password_verify($pass, (string) $user['password_hash'])) {
            resetLoginAttempts($pdo, $email);
            session_regenerate_id(true);
            $_SESSION['user_id']    = (int) $user['id'];
            $_SESSION['user_name']  = (string) $user['name'];
            $_SESSION['user_email'] = (string) $user['email'];
            $_SESSION['user_role']  = (string) $user['role'];
            header('Location: ' . BASE . '/index.php');
            exit;
        }
        registerFailedLogin($pdo, $email);
        $error = 'Ongeldige inloggegevens.';
    }
}
?>
<!DOCTYPE html>
<html lang="nl" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inloggen — Voorraadbeheer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: { brand: { 50: '#fffbeb', 500: '#f59e0b', 600: '#d97706', 700: '#b45309' } } } }
        }
    </script>
    <link rel="stylesheet" href="<?= BASE ?>/assets/css/components.css">
</head>
<body class="h-full bg-slate-50 antialiased flex items-center justify-center px-4">

<div class="w-full max-w-sm">
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-brand-50 mb-4">
            <svg class="w-9 h-9 text-brand-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-slate-900">Voorraadbeheer</h1>
        <p class="text-sm text-slate-500 mt-1">Artikelen, voorraadposities &amp; magazijnlocaties</p>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
        <?php if ($error !== ''): ?>
            <div class="mb-4 px-4 py-3 rounded-lg text-sm font-medium bg-red-50 text-red-700 border border-red-200">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <?= csrfField() ?>
            <div>
                <label for="email" class="block text-sm font-medium text-slate-700 mb-1">E-mailadres</label>
                <input type="email" name="email" id="email" required autofocus
                       value="<?= e($_POST['email'] ?? 'anna.devries@voorraad-demo.nl') ?>"
                       class="w-full px-3 py-2.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-shadow">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Wachtwoord</label>
                <input type="password" name="password" id="password" required value="demo123"
                       class="w-full px-3 py-2.5 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-shadow">
            </div>
            <button type="submit"
                    class="w-full py-2.5 px-4 text-sm font-semibold text-white bg-brand-500 rounded-lg hover:bg-brand-600 transition-colors shadow-sm">
                Inloggen
            </button>
        </form>
    </div>

    <div class="mt-6 text-center text-xs text-slate-400 space-y-0.5">
        <p>Demo-accounts (wachtwoord voor alle drie: <strong>demo123</strong>)</p>
        <p>anna.devries@voorraad-demo.nl (Beheerder)</p>
        <p>tom.bakker@voorraad-demo.nl (Magazijnmedewerker)</p>
        <p>lisa.jansen@voorraad-demo.nl (Viewer)</p>
    </div>
</div>

</body>
</html>
