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
    <style>
        :root { --vb-amber: #f59e0b; --vb-dark: #1c1917; --vb-steel: #292524; }
        body.vb-industrial {
            min-height: 100vh;
            background-color: var(--vb-dark);
            background-image:
                linear-gradient(rgba(245,158,11,.10) 1px, transparent 1px),
                linear-gradient(90deg, rgba(245,158,11,.10) 1px, transparent 1px);
            background-size: 36px 36px;
            display: flex; align-items: center; justify-content: center; padding: 1rem;
            position: relative;
        }
        body.vb-industrial::before {
            content: ""; position: absolute; inset: 0;
            background: repeating-linear-gradient(135deg, rgba(245,158,11,.04) 0 2px, transparent 2px 14px);
            pointer-events: none;
        }
        .vb-hazard {
            height: 6px; width: 100%;
            background: repeating-linear-gradient(-45deg, var(--vb-amber) 0 14px, #1c1917 14px 28px);
        }
        .vb-panel {
            background: #201c1a;
            border: 1px solid rgba(245,158,11,.25);
            border-radius: 4px;
            box-shadow: 0 20px 45px rgba(0,0,0,.5);
        }
        .vb-panel input {
            background: #14110f !important; border-color: #3a332c !important; color: #f5f1ea;
            border-radius: 3px !important;
        }
        .vb-panel input::placeholder { color: #6b6058; }
        .vb-panel label { color: #b8afa3 !important; }
        .vb-badge-icon {
            width: 64px; height: 64px; border-radius: 4px;
            background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.4);
        }
    </style>
</head>
<body class="vb-industrial antialiased">

<div class="w-full max-w-sm relative z-10">
    <div class="text-center mb-6">
        <div class="inline-flex items-center justify-center vb-badge-icon mb-4">
            <svg class="w-9 h-9 text-brand-500" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-white uppercase tracking-wide">Voorraadbeheer</h1>
        <p class="text-sm mt-1" style="color:#a8a29e;">Artikelen, voorraadposities &amp; magazijnlocaties</p>
    </div>

    <div class="vb-panel">
        <div class="vb-hazard"></div>
        <div class="p-6">
            <?php if ($error !== ''): ?>
                <div class="mb-4 px-4 py-3 text-sm font-medium bg-red-950 text-red-300 border border-red-900 rounded">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-4">
                <?= csrfField() ?>
                <div>
                    <label for="email" class="block text-sm font-medium mb-1">E-mailadres</label>
                    <input type="email" name="email" id="email" required autofocus
                           value="<?= e($_POST['email'] ?? 'anna.devries@voorraad-demo.nl') ?>"
                           class="w-full px-3 py-2.5 text-sm border focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-shadow">
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium mb-1">Wachtwoord</label>
                    <input type="password" name="password" id="password" required value="demo123"
                           class="w-full px-3 py-2.5 text-sm border focus:ring-2 focus:ring-brand-500 focus:border-brand-500 outline-none transition-shadow">
                </div>
                <button type="submit"
                        class="w-full py-2.5 px-4 text-sm font-bold text-slate-900 bg-brand-500 rounded hover:bg-brand-600 transition-colors uppercase tracking-wide">
                    Inloggen
                </button>
            </form>
        </div>
    </div>

    <div class="mt-6 text-center text-xs space-y-0.5" style="color:#78716c;">
        <p>Demo-accounts (wachtwoord voor alle drie: <strong>demo123</strong>)</p>
        <p>anna.devries@voorraad-demo.nl (Beheerder)</p>
        <p>tom.bakker@voorraad-demo.nl (Magazijnmedewerker)</p>
        <p>lisa.jansen@voorraad-demo.nl (Viewer)</p>
    </div>
</div>

</body>
</html>
