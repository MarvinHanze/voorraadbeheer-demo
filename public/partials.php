<?php

declare(strict_types=1);

/**
 * Gedeelde presentatie-helpers voor alle pagina's van Voorraadbeheer.
 * csrfField()/verifyCSRF()/e() staan al in config.php — dit bestand bevat
 * uitsluitend herbruikbare HTML-fragmenten (topnav, badges, kaarten) zodat
 * die niet in 6 losse bestanden gedupliceerd hoeven te worden.
 */

/**
 * Statusbadge voor een artikel (actief/uitverkocht/stopgezet).
 */
function productStatusBadge(string $status): string
{
    $map = [
        'actief'      => ['hz-badge--green', 'Actief'],
        'uitverkocht' => ['hz-badge--red', 'Uitverkocht'],
        'stopgezet'   => ['hz-badge--gray', 'Stopgezet'],
    ];
    [$class, $label] = $map[$status] ?? ['hz-badge--gray', ucfirst($status)];
    return '<span class="hz-badge ' . $class . '">' . e($label) . '</span>';
}

/**
 * Badge voor het type mutatie (in/uit).
 */
function movementTypeBadge(string $type): string
{
    return $type === 'in'
        ? '<span class="hz-badge hz-badge--green">Inkomend</span>'
        : '<span class="hz-badge hz-badge--orange">Uitgaand</span>';
}

/**
 * Nederlandse labels voor de reden van een mutatie.
 */
function movementReasonLabel(string $reason): string
{
    return match ($reason) {
        'verkoop'   => 'Verkoop',
        'derving'   => 'Derving',
        'correctie' => 'Correctie',
        'ontvangst' => 'Ontvangst',
        'overig'    => 'Overig',
        default     => ucfirst($reason),
    };
}

function movementReasonBadgeClass(string $reason): string
{
    return match ($reason) {
        'derving'   => 'hz-badge--red',
        'ontvangst' => 'hz-badge--green',
        'correctie' => 'hz-badge--orange',
        default     => 'hz-badge--gray',
    };
}

/**
 * Badge voor de status van een inkoopvoorstel/magic link.
 */
function proposalStatusBadge(string $status): string
{
    $map = [
        'verzonden' => ['hz-badge--orange', 'Verzonden'],
        'bevestigd' => ['hz-badge--green', 'Bevestigd'],
        'verlopen'  => ['hz-badge--gray', 'Verlopen'],
    ];
    [$class, $label] = $map[$status] ?? ['hz-badge--gray', ucfirst($status)];
    return '<span class="hz-badge ' . $class . '">' . e($label) . '</span>';
}

function syncLevelBadge(string $level): string
{
    $map = [
        'info'    => ['hz-badge--gray', 'Info'],
        'success' => ['hz-badge--green', 'Succes'],
        'warning' => ['hz-badge--orange', 'Waarschuwing'],
        'error'   => ['hz-badge--red', 'Fout'],
    ];
    [$class, $label] = $map[$level] ?? ['hz-badge--gray', ucfirst($level)];
    return '<span class="hz-badge ' . $class . '">' . e($label) . '</span>';
}

/**
 * Kleine info-bubble die vaktermen uitlegt via de hz-tooltip component
 * (consistente NL-terminologie + uitleg, ook voor foutmeldingen/tooltips).
 */
function termTooltip(string $label, string $explanation): string
{
    return '<span class="hz-tooltip" tabindex="0">' . e($label)
        . ' <svg class="hz-icon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:inline;vertical-align:-2px;color:var(--hz-text-muted);"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" d="M12 16v-4M12 8h.01"/></svg>'
        . '<span class="hz-tooltip__bubble" style="white-space:normal;width:220px;">' . e($explanation) . '</span></span>';
}

/**
 * Pagina-header: <head> + opening body + topnav. $active is de bestandsnaam
 * zonder extensie, gebruikt om het actieve nav-item te markeren.
 */
function renderPageStart(string $title, string $active): void
{
    $user = currentUser();
    $navItems = [
        'index'      => ['Dashboard', BASE . '/index.php'],
        'artikelen'  => ['Artikelen', BASE . '/artikelen.php'],
        'mutaties'   => ['Mutaties', BASE . '/mutaties.php'],
        'inkoop'     => ['Inkoopvoorstellen', BASE . '/inkoop.php'],
    ];
    if (canManageSettings($user['role'])) {
        $navItems['instellingen'] = ['Instellingen', BASE . '/instellingen.php'];
    }
    ?>
<!DOCTYPE html>
<html lang="nl" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> — Voorraadbeheer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: { 50: '#fffbeb', 100: '#fef3c7', 500: '#f59e0b', 600: '#d97706', 700: '#b45309' },
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="<?= BASE ?>/assets/css/components.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <style>
        .hz-shell { display: flex; min-height: 100vh; }
        @media (max-width: 900px) {
            .hz-sidebar { position: fixed; left: 0; top: 0; bottom: 0; z-index: 85; transform: translateX(-100%); transition: transform .2s ease; }
            .hz-sidebar.hz-is-open { transform: translateX(0); }
        }
    </style>
</head>
<body class="h-full bg-slate-50 text-slate-800 antialiased">

<nav class="bg-white shadow-sm border-b border-slate-200">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16 gap-4">
            <div class="flex items-center gap-3 shrink-0">
                <button type="button" data-hz-mobile-toggle="mobileNav" class="hz-hamburger md:hidden" aria-label="Menu">
                    <span></span><span></span><span></span>
                </button>
                <div class="w-8 h-8 bg-brand-500 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                </div>
                <h1 class="text-lg font-bold text-slate-900 tracking-tight hidden sm:block">Voorraadbeheer</h1>
            </div>
            <div class="hidden md:flex items-center gap-1 overflow-x-auto">
                <?php foreach ($navItems as $key => [$label, $href]): ?>
                    <a href="<?= $href ?>"
                       class="px-3 py-2 rounded-lg text-sm font-medium whitespace-nowrap transition-colors <?= $active === $key ? 'bg-brand-50 text-brand-700' : 'text-slate-500 hover:text-slate-900 hover:bg-slate-50' ?>">
                        <?= e($label) ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="flex items-center gap-3 shrink-0">
                <div class="hz-dropdown">
                    <button type="button" class="hz-icon-btn" data-hz-dropdown-trigger="themePickerMenu" aria-label="Thema wijzigen" title="Thema">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 011.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C22 6.012 17.461 2 12 2z"/></svg>
                    </button>
                    <div class="hz-dropdown__menu hz-dropdown__menu--picker" id="themePickerMenu" data-hz-picker="theme">
                        <button type="button" data-hz-picker-value=""><span class="hz-picker-swatch" style="background:#2563eb;"></span> Klassiek Blauw</button>
                        <button type="button" data-hz-picker-value="dark"><span class="hz-picker-swatch" style="background:#0f172a;"></span> Donker</button>
                        <button type="button" data-hz-picker-value="emerald"><span class="hz-picker-swatch" style="background:#059669;"></span> Warehouse Groen</button>
                        <button type="button" data-hz-picker-value="violet"><span class="hz-picker-swatch" style="background:#7c3aed;"></span> Modern Violet</button>
                        <button type="button" data-hz-picker-value="rose"><span class="hz-picker-swatch" style="background:#e11d48;"></span> Zacht Roze</button>
                        <button type="button" data-hz-picker-value="amber"><span class="hz-picker-swatch" style="background:#d97706;"></span> Industrieel Amber</button>
                        <button type="button" data-hz-picker-value="teal"><span class="hz-picker-swatch" style="background:#0d9488;"></span> Fris Teal</button>
                        <button type="button" data-hz-picker-value="contrast"><span class="hz-picker-swatch" style="background:#000;"></span> Hoog Contrast</button>
                    </div>
                </div>
                <div class="hz-dropdown">
                    <button type="button" class="hz-icon-btn hidden sm:inline-flex" data-hz-dropdown-trigger="fontPickerMenu" aria-label="Lettertype wijzigen" title="Lettertype">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>
                    </button>
                    <div class="hz-dropdown__menu hz-dropdown__menu--picker" id="fontPickerMenu" data-hz-picker="font">
                        <button type="button" data-hz-picker-value="">Systeemlettertype</button>
                        <button type="button" data-hz-picker-value="inter" style="font-family:'Inter',sans-serif;">Inter</button>
                        <button type="button" data-hz-picker-value="plex" style="font-family:'IBM Plex Sans',sans-serif;">IBM Plex Sans</button>
                        <button type="button" data-hz-picker-value="work" style="font-family:'Work Sans',sans-serif;">Work Sans</button>
                        <button type="button" data-hz-picker-value="manrope" style="font-family:'Manrope',sans-serif;">Manrope</button>
                        <button type="button" data-hz-picker-value="spacegrotesk" style="font-family:'Space Grotesk',sans-serif;">Space Grotesk</button>
                        <button type="button" data-hz-picker-value="dmsans" style="font-family:'DM Sans',sans-serif;">DM Sans</button>
                        <button type="button" data-hz-picker-value="publicsans" style="font-family:'Public Sans',sans-serif;">Public Sans</button>
                    </div>
                </div>
                <span class="hz-badge <?= roleBadgeClass($user['role']) ?> hidden md:inline-flex"><?= e(roleLabel($user['role'])) ?></span>
                <span class="text-sm text-slate-500 hidden lg:block"><?= e($user['name']) ?></span>
                <a href="<?= BASE ?>/logout.php"
                   class="inline-flex items-center gap-1 text-sm font-medium text-slate-500 hover:text-red-600 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                    <span class="hidden sm:inline">Uitloggen</span>
                </a>
            </div>
        </div>
    </div>
</nav>

<div id="mobileNav" class="hz-mobile-overlay">
    <div class="flex items-center justify-between mb-6">
        <h2 class="font-bold text-slate-900">Menu</h2>
        <button type="button" data-hz-mobile-toggle="mobileNav" class="hz-icon-btn" aria-label="Sluiten">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    <div class="flex flex-col gap-1">
        <?php foreach ($navItems as $key => [$label, $href]): ?>
            <a href="<?= $href ?>" class="px-3 py-3 rounded-lg text-base font-medium <?= $active === $key ? 'bg-brand-50 text-brand-700' : 'text-slate-600' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full">
    <?php
}

function renderFlash(string $message, string $type = 'success'): void
{
    if ($message === '') {
        return;
    }
    $cls = $type === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200';
    echo '<div class="mb-6 px-4 py-3 rounded-lg text-sm font-medium ' . $cls . '">' . e($message) . '</div>';
}

function renderPageEnd(): void
{
    ?>
</main>
<script src="<?= BASE ?>/assets/js/components.js"></script>
</body>
</html>
    <?php
}
