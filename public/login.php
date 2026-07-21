<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCSRF();
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    if ($email === 'admin@demo.nl' && password_verify($pass, DEMO_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['user'] = $email;
        seed_data($pdo);
        header('Location: ' . BASE . '/index.php');
        exit;
    }
    $error = 'Ongeldige inloggegevens';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inloggen - Voorraadbeheer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { brand: '#f59e0b' } } } }
    </script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-sm bg-white rounded-xl shadow-lg p-8">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 bg-brand rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
            </div>
            <h1 class="text-xl font-bold text-slate-800">Voorraadbeheer</h1>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 text-red-700 text-sm rounded-lg px-4 py-3 mb-4"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <div>
                <label for="email" class="block text-sm font-medium text-slate-700 mb-1">E-mail</label>
                <input type="email" id="email" name="email" required value="admin@demo.nl"
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium text-slate-700 mb-1">Wachtwoord</label>
                <input type="password" id="password" name="password" required
                    class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-brand focus:border-brand">
            </div>
            <button type="submit"
                class="w-full bg-brand hover:bg-amber-600 text-white font-medium py-2.5 rounded-lg text-sm transition-colors">
                Inloggen
            </button>
        </form>

        <p class="text-xs text-slate-400 mt-4 text-center">Demo: admin@demo.nl / demo123</p>
    </div>
</body>
</html>
