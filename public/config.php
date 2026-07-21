<?php
declare(strict_types=1);

require_once __DIR__ . '/assets/icons.php';

define('DB_HOST', 'y11ovnrne4yk4p9zbhe39tti');
define('DB_NAME', 'demos');
define('DB_USER', 'mysql');
define('DB_PASS', '23ns613Dyo1vgiAOQCt2ABFZzujOsxuyROvqNk4unUoZxWpwN9nIPrMNTt4QFkzG');
define('BASE', '/voorraadbeheer');
define('DEMO_RESET_MINUTES', 30);
define('DEMO_PASSWORD_HASH', '$2y$12$xP5XI843YDt2Ow1j0sPPvee9FRk2XYYepLdJPlYeh65Gv.B/RKtES');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed');
}

function init_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS voorraad_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            sku VARCHAR(50) UNIQUE NOT NULL,
            category VARCHAR(100) NOT NULL,
            quantity INT DEFAULT 0,
            min_stock INT DEFAULT 5,
            location VARCHAR(100) DEFAULT '',
            unit_price DECIMAL(10,2) DEFAULT 0.00,
            status ENUM('actief','uitverkocht','stopgezet') DEFAULT 'actief',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS voorraad_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            type ENUM('in','uit') NOT NULL,
            quantity INT NOT NULL,
            reference VARCHAR(100) DEFAULT '',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES voorraad_products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS voorraad_settings (
            id INT PRIMARY KEY DEFAULT 1,
            last_reset DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("INSERT IGNORE INTO voorraad_settings (id, last_reset) VALUES (1, NULL)");

    // --- Batch-/lot-tracking per artikel ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS voorraad_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            lot_number VARCHAR(50) NOT NULL,
            quantity INT DEFAULT 0,
            expiry_date DATE NULL,
            received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES voorraad_products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // --- Granulair gebruikersbeheer (rollen: wie mag toevoegen/verplaatsen/afboeken) ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS voorraad_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(150) UNIQUE NOT NULL,
            role ENUM('beheerder','magazijnmedewerker','viewer') DEFAULT 'magazijnmedewerker',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // --- Gesimuleerde e-maillog voor magic-link inkoopvoorstellen (geen echte mail) ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS voorraad_email_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(64) UNIQUE NOT NULL,
            to_email VARCHAR(150) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body TEXT,
            type ENUM('inkoopvoorstel','magic_link') DEFAULT 'inkoopvoorstel',
            product_id INT NULL,
            proposed_qty INT DEFAULT 0,
            status ENUM('verzonden','bevestigd','verlopen') DEFAULT 'verzonden',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME NULL,
            FOREIGN KEY (product_id) REFERENCES voorraad_products(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // --- Mock e-commerce/inkoop-integratie instellingen (géén echte externe API-call) ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS voorraad_integration_settings (
            id INT PRIMARY KEY DEFAULT 1,
            platform ENUM('shopify','magento','woocommerce') DEFAULT 'shopify',
            api_key VARCHAR(255) DEFAULT '',
            shop_url VARCHAR(255) DEFAULT '',
            enabled TINYINT(1) DEFAULT 0,
            last_sync DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("INSERT IGNORE INTO voorraad_integration_settings (id) VALUES (1)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS voorraad_sync_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            platform VARCHAR(50) NOT NULL,
            message VARCHAR(255) NOT NULL,
            level ENUM('info','success','warning','error') DEFAULT 'info',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Voegt defensief ontbrekende kolommen toe aan tabellen die al vóór deze
 * uitbreiding bestonden (voorkomt fouten als de gedeelde demo-DB al een
 * oudere tabelversie bevat).
 */
function migrate_columns(PDO $pdo): void
{
    $columnsToAdd = [
        'voorraad_products' => [
            'max_stock'      => "ALTER TABLE voorraad_products ADD COLUMN max_stock INT DEFAULT 0 AFTER min_stock",
            'supplier_name'  => "ALTER TABLE voorraad_products ADD COLUMN supplier_name VARCHAR(150) DEFAULT '' AFTER location",
            'supplier_email' => "ALTER TABLE voorraad_products ADD COLUMN supplier_email VARCHAR(150) DEFAULT '' AFTER supplier_name",
            'reorder_qty'    => "ALTER TABLE voorraad_products ADD COLUMN reorder_qty INT DEFAULT 0 AFTER max_stock",
            'purchase_price' => "ALTER TABLE voorraad_products ADD COLUMN purchase_price DECIMAL(10,2) DEFAULT 0.00 AFTER unit_price",
        ],
        'voorraad_movements' => [
            'reason'     => "ALTER TABLE voorraad_movements ADD COLUMN reason ENUM('verkoop','derving','correctie','ontvangst','overig') DEFAULT 'verkoop' AFTER quantity",
            'lot_number' => "ALTER TABLE voorraad_movements ADD COLUMN lot_number VARCHAR(50) DEFAULT '' AFTER reference",
            'actor'      => "ALTER TABLE voorraad_movements ADD COLUMN actor VARCHAR(150) DEFAULT '' AFTER notes",
        ],
        'voorraad_users' => [
            'password_hash' => "ALTER TABLE voorraad_users ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL AFTER role",
        ],
    ];

    foreach ($columnsToAdd as $table => $columns) {
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($columns as $colName => $sql) {
            if (!in_array($colName, $existing, true)) {
                $pdo->exec($sql);
            }
        }
    }
}

function seed_data(PDO $pdo): void
{
    $count = (int) $pdo->query("SELECT COUNT(*) FROM voorraad_products")->fetchColumn();
    if ($count > 0) {
        ensure_singletons($pdo);
        return;
    }

    $products = [
        ['Laptop Dell XPS 15',     'ELEC-001', 'Electronica',          24, 10, 'Magazijn A',  1299.99, 'actief'],
        ['Monitor LG UltraWide',   'ELEC-002', 'Electronica',           8,  5, 'Magazijn A',   549.00, 'actief'],
        ['Mechanisch Toetsenbord', 'ELEC-003', 'Electronica',          42, 15, 'Kantoor 201',   89.95, 'actief'],
        ['Wireless Muis Logitech', 'ELEC-004', 'Electronica',          67, 20, 'Kantoor 201',   34.99, 'actief'],
        ['USB-C Hub 7-in-1',       'ELEC-005', 'Electronica',           3, 10, 'Magazijn A',    49.99, 'actief'],
        ['Webcam HD 1080p',        'ELEC-006', 'Electronica',          15,  8, 'Kantoor 305',   69.99, 'actief'],
        ['Noise Cancelling Kop',   'ELEC-007', 'Electronica',           0,  5, 'Magazijn A',  199.99, 'uitverkocht'],
        ['Printer Papier A4 (doos)','KANT-001','Kantoorbenodigdheden', 120, 30, 'Magazijn B',   12.50, 'actief'],
        ['Balpennen Blauw (100st)','KANT-002', 'Kantoorbenodigdheden',  85, 25, 'Kantoor 201',    8.99, 'actief'],
        ['Whiteboard Stiften Set', 'KANT-003', 'Kantoorbenodigdheden',  12,  5, 'Kantoor 305',   14.95, 'actief'],
        ['Ordners Map A4 (10st)',  'KANT-004', 'Kantoorbenodigdheden',  45, 10, 'Magazijn B',   22.50, 'actief'],
        ['Stapler Heavy Duty',     'KANT-005', 'Kantoorbenodigdheden',   2,  5, 'Kantoor 201',   18.99, 'actief'],
        ['Ergonomische bureaustoel','MEUB-001','Meubilair',             14,  3, 'Magazijn C',  349.00, 'actief'],
        ['Zit-Sta Bureau Elektrisch','MEUB-002','Meubilair',             6,  2, 'Magazijn C',  599.00, 'actief'],
        ['Boekenplank Metaal 180cm','MEUB-003','Meubilair',              9,  3, 'Magazijn C',  129.00, 'actief'],
        ['Vergaderstoel (per stuk)','MEUB-004','Meubilair',             28, 10, 'Magazijn C',   89.00, 'actief'],
        ['Microsoft 365 Business','SOFT-001', 'Software Licenties',    50, 10, 'Serverruimte', 12.99, 'actief'],
        ['Adobe Creative Cloud',  'SOFT-002', 'Software Licenties',    18,  5, 'Serverruimte', 22.99, 'actief'],
        ['JetBrains All Products','SOFT-003', 'Software Licenties',     4,  5, 'Serverruimte', 24.99, 'actief'],
        ['Zoom Business License',  'SOFT-004', 'Software Licenties',   30, 10, 'Serverruimte',  9.99, 'actief'],
    ];

    // Aanvullende demo-informatie per SKU: overvoorraad-grens en leverancier.
    $supplierInfo = [
        'ELEC-001' => [40, 'TechDistro B.V.', 'inkoop@techdistro-demo.nl'],
        'ELEC-002' => [30, 'TechDistro B.V.', 'inkoop@techdistro-demo.nl'],
        'ELEC-005' => [25, 'ITSupplies Benelux', 'sales@itsupplies-demo.nl'],
        'KANT-001' => [200, 'KantoorGroothandel NL', 'orders@kantoorgroothandel-demo.nl'],
        'KANT-005' => [20, 'KantoorGroothandel NL', 'orders@kantoorgroothandel-demo.nl'],
        'MEUB-001' => [20, 'MeubelPartners', 'verkoop@meubelpartners-demo.nl'],
    ];

    // Batches/lots voor een aantal artikelen (illustratief, niet 1-op-1 met totale voorraad).
    $batchInfo = [
        'ELEC-001' => [['LOT-2026-014', 12, '2027-06-01'], ['LOT-2026-021', 12, '2027-09-15']],
        'KANT-001' => [['LOT-PAP-0525', 60, null], ['LOT-PAP-0625', 60, null]],
    ];

    $insertProd = $pdo->prepare("INSERT INTO voorraad_products (name, sku, category, quantity, min_stock, location, unit_price, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $insertMov  = $pdo->prepare("INSERT INTO voorraad_movements (product_id, type, quantity, reference, notes, reason, actor) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $updateSupp = $pdo->prepare("UPDATE voorraad_products SET max_stock = ?, supplier_name = ?, supplier_email = ? WHERE id = ?");
    $updatePurch = $pdo->prepare("UPDATE voorraad_products SET purchase_price = ? WHERE id = ?");
    $insertBatch = $pdo->prepare("INSERT INTO voorraad_batches (product_id, lot_number, quantity, expiry_date) VALUES (?, ?, ?, ?)");
    $insertEmail = $pdo->prepare("INSERT INTO voorraad_email_log (token, to_email, subject, body, type, product_id, proposed_qty, status, confirmed_at) VALUES (?, ?, ?, ?, 'inkoopvoorstel', ?, ?, ?, ?)");

    $pdo->beginTransaction();
    try {
        $skuToId = [];
        foreach ($products as $p) {
            $insertProd->execute($p);
            $pid = (int) $pdo->lastInsertId();
            $skuToId[$p[1]] = $pid;

            // Inkoopprijs is illustratief ca. 55-70% van de verkoopprijs (marge), voor het voorraadwaarderapport.
            $margeFactor = mt_rand(55, 70) / 100;
            $updatePurch->execute([round((float) $p[6] * $margeFactor, 2), $pid]);

            // create a couple of movements per product
            $insertMov->execute([$pid, 'in',  $p[3] + 5, 'ORD-' . mt_rand(1000, 9999), 'Initiele voorraad', 'ontvangst', 'Systeem (seed)']);
            if ($p[3] > 0) {
                $insertMov->execute([$pid, 'uit', mt_rand(1, min(5, $p[3])), 'VER-' . mt_rand(1000, 9999), 'Uitgifte', 'verkoop', 'Anna de Vries']);
            }
        }

        // Voorbeeld van derving (verloren/beschadigde artikelen) voor de rapportage-widget.
        if (isset($skuToId['ELEC-006'])) {
            $insertMov->execute([$skuToId['ELEC-006'], 'uit', 2, 'DERV-1001', 'Beschadigd tijdens transport', 'derving', 'Tom Bakker']);
        }
        if (isset($skuToId['KANT-003'])) {
            $insertMov->execute([$skuToId['KANT-003'], 'uit', 3, 'DERV-1002', 'Uitgedroogd / onbruikbaar', 'derving', 'Tom Bakker']);
        }
        if (isset($skuToId['MEUB-003'])) {
            $insertMov->execute([$skuToId['MEUB-003'], 'uit', 1, 'DERV-1003', 'Transportschade', 'derving', 'Tom Bakker']);
        }

        foreach ($supplierInfo as $sku => $info) {
            if (isset($skuToId[$sku])) {
                $updateSupp->execute([$info[0], $info[1], $info[2], $skuToId[$sku]]);
            }
        }

        foreach ($batchInfo as $sku => $batches) {
            if (!isset($skuToId[$sku])) {
                continue;
            }
            foreach ($batches as $b) {
                $insertBatch->execute([$skuToId[$sku], $b[0], $b[1], $b[2]]);
            }
        }

        // Voorbeeld-e-maillog: 1 openstaand inkoopvoorstel + 1 al bevestigd voorstel.
        if (isset($skuToId['ELEC-005'])) {
            $insertEmail->execute([
                bin2hex(random_bytes(20)),
                'sales@itsupplies-demo.nl',
                'Inkoopvoorstel: USB-C Hub 7-in-1 (ELEC-005)',
                "Beste leverancier,\n\nDe voorraad van USB-C Hub 7-in-1 (SKU ELEC-005) is onder het ingestelde minimum gezakt.\nGevraagd aantal: 17 stuks.\n\nDit is een gesimuleerde demo-e-mail, er is geen bericht daadwerkelijk verzonden.",
                $skuToId['ELEC-005'],
                17,
                'verzonden',
                null,
            ]);
        }
        if (isset($skuToId['KANT-005'])) {
            $insertEmail->execute([
                bin2hex(random_bytes(20)),
                'orders@kantoorgroothandel-demo.nl',
                'Inkoopvoorstel: Stapler Heavy Duty (KANT-005)',
                "Beste leverancier,\n\nDe voorraad van Stapler Heavy Duty (SKU KANT-005) is onder het ingestelde minimum gezakt.\nGevraagd aantal: 8 stuks.\n\nDit is een gesimuleerde demo-e-mail, er is geen bericht daadwerkelijk verzonden.",
                $skuToId['KANT-005'],
                8,
                'bevestigd',
                date('Y-m-d H:i:s', time() - 3600),
            ]);
        }

        $pdo->exec("UPDATE voorraad_settings SET last_reset = NOW() WHERE id = 1");
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    ensure_singletons($pdo);
}

/**
 * Zorgt dat "instellingen-achtige" enkelvoudige data (gebruikers, mock-integratie)
 * altijd aanwezig is, ook als producten al eerder geseed waren. Idempotent.
 */
function ensure_singletons(PDO $pdo): void
{
    $pdo->exec("INSERT IGNORE INTO voorraad_integration_settings (id) VALUES (1)");

    $userCount = (int) $pdo->query("SELECT COUNT(*) FROM voorraad_users")->fetchColumn();
    if ($userCount === 0) {
        $insertUser = $pdo->prepare("INSERT IGNORE INTO voorraad_users (name, email, role, password_hash) VALUES (?, ?, ?, ?)");
        $insertUser->execute(['Anna de Vries', 'anna.devries@voorraad-demo.nl', 'beheerder', DEMO_PASSWORD_HASH]);
        $insertUser->execute(['Tom Bakker', 'tom.bakker@voorraad-demo.nl', 'magazijnmedewerker', DEMO_PASSWORD_HASH]);
        $insertUser->execute(['Lisa Jansen', 'lisa.jansen@voorraad-demo.nl', 'viewer', DEMO_PASSWORD_HASH]);
    }

    // Defensief: als deze rijen al bestonden vóórdat password_hash werd toegevoegd
    // (migrate_columns loopt vóór seed_data), staat het wachtwoord nog op NULL.
    backfill_user_passwords($pdo);
}

/**
 * Backfilt password_hash voor bestaande users-rijen zonder wachtwoord
 * (idempotent, veilig om bij elke request te draaien).
 */
function backfill_user_passwords(PDO $pdo): void
{
    $stmt = $pdo->prepare("UPDATE voorraad_users SET password_hash = ? WHERE password_hash IS NULL OR password_hash = ''");
    $stmt->execute([DEMO_PASSWORD_HASH]);
}

function maybe_reset(PDO $pdo): void
{
    $row = $pdo->query("SELECT last_reset FROM voorraad_settings WHERE id = 1")->fetch();
    if (!$row || !$row['last_reset']) {
        $pdo->exec("UPDATE voorraad_settings SET last_reset = NOW() WHERE id = 1");
        return;
    }
    $last = strtotime($row['last_reset']);
    if (time() - $last >= DEMO_RESET_MINUTES * 60) {
        $pdo->exec("DELETE FROM voorraad_email_log");
        $pdo->exec("DELETE FROM voorraad_batches");
        $pdo->exec("DELETE FROM voorraad_movements");
        $pdo->exec("DELETE FROM voorraad_sync_log");
        $pdo->exec("DELETE FROM voorraad_products");
        $pdo->exec("UPDATE voorraad_settings SET last_reset = NOW() WHERE id = 1");
        seed_data($pdo);
    }
}

init_schema($pdo);
migrate_columns($pdo);
seed_data($pdo);
maybe_reset($pdo);

session_start();

function auth_check(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . BASE . '/login.php');
        exit;
    }
}

/**
 * Huidige ingelogde gebruiker (uit sessie, gezet door login.php na verificatie
 * tegen voorraad_users). Retourneert een viewer-achtig "leeg" record als er
 * (onverwacht) niets in de sessie staat, zodat aanroepers nooit een undefined-
 * index-warning krijgen.
 */
function currentUser(): array
{
    return [
        'id'    => (int) ($_SESSION['user_id'] ?? 0),
        'name'  => (string) ($_SESSION['user_name'] ?? ''),
        'email' => (string) ($_SESSION['user_email'] ?? ''),
        'role'  => (string) ($_SESSION['user_role'] ?? 'viewer'),
    ];
}

function currentRole(): string
{
    return (string) ($_SESSION['user_role'] ?? 'viewer');
}

function roleLabel(string $role): string
{
    return match ($role) {
        'beheerder'          => 'Beheerder',
        'magazijnmedewerker' => 'Magazijnmedewerker',
        'viewer'             => 'Viewer',
        default              => ucfirst($role),
    };
}

function roleBadgeClass(string $role): string
{
    return match ($role) {
        'beheerder'          => 'hz-badge--red',
        'magazijnmedewerker' => 'hz-badge--orange',
        'viewer'             => 'hz-badge--gray',
        default              => 'hz-badge--gray',
    };
}

/**
 * Granulair rechtensysteem: legt precies vast wie mag toevoegen/verplaatsen
 * (magazijnmedewerker + beheerder) en wie mag afboeken als derving of
 * gebruikers/instellingen mag beheren (uitsluitend beheerder).
 */
function canMoveStock(string $role): bool
{
    return in_array($role, ['beheerder', 'magazijnmedewerker'], true);
}

function canWriteOff(string $role): bool
{
    // Afboeken (derving) is bewust strikter dan gewone in/uit-mutaties.
    return $role === 'beheerder';
}

function canManageProducts(string $role): bool
{
    return in_array($role, ['beheerder', 'magazijnmedewerker'], true);
}

function canManageSettings(string $role): bool
{
    return $role === 'beheerder';
}

/**
 * Stopt de request met een 403 als de huidige rol niet in de toegestane lijst zit.
 * Gebruikt Nederlandse terminologie in de foutmelding, consistent met de rest van de UI.
 */
function requireRole(array $allowedRoles): void
{
    if (!in_array(currentRole(), $allowedRoles, true)) {
        http_response_code(403);
        exit('Geen toegang: je huidige rol (' . e(roleLabel(currentRole())) . ') heeft onvoldoende rechten voor deze actie.');
    }
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function generateCSRFToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(generateCSRFToken()) . '">';
}

function verifyCSRF(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Ongeldige aanvraag');
    }
}

/**
 * Nederlandse datumnotatie helper. Geeft "-" terug voor lege/onbekende datums.
 */
function nl_datum(?string $dt, string $fmt = 'd-m-Y H:i'): string
{
    if (!$dt) {
        return '-';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return '-';
    }
    return date($fmt, $ts);
}

/**
 * Nederlandse valutanotatie helper (euro, komma als decimaalteken).
 */
function nl_euro(float $bedrag): string
{
    return '€ ' . number_format($bedrag, 2, ',', '.');
}

/**
 * Voert één voorraadmutatie atomisch uit: locked read (SELECT ... FOR UPDATE)
 * op de artikelrij, valideert de nieuwe hoeveelheid (nooit negatief), werkt
 * voorraad_products bij en logt de mutatie in voorraad_movements — alles
 * binnen dezelfde transactie. Dit voorkomt race conditions (bijv. twee
 * gelijktijdige "afboeken"-requests die elkaars aftrek negeren) doordat de
 * rij-lock de tweede transactie laat wachten tot de eerste commit/rollback
 * heeft gedaan, waarna deze de bijgewerkte hoeveelheid ziet.
 *
 * @throws RuntimeException bij een ongeldige mutatie (bijv. onvoldoende voorraad).
 */
function applyStockMutation(
    PDO $pdo,
    int $productId,
    string $type,
    int $qty,
    string $reason,
    string $reference,
    string $notes,
    string $actor,
    string $lotNumber = ''
): array {
    if ($qty <= 0) {
        throw new RuntimeException('Aantal moet groter zijn dan 0.');
    }
    if (!in_array($type, ['in', 'uit'], true)) {
        throw new RuntimeException('Ongeldig mutatietype.');
    }

    $weStartedTransaction = !$pdo->inTransaction();
    if ($weStartedTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM voorraad_products WHERE id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) {
            throw new RuntimeException('Artikel niet gevonden.');
        }

        $newQty = $type === 'in'
            ? (int) $product['quantity'] + $qty
            : (int) $product['quantity'] - $qty;

        if ($newQty < 0) {
            throw new RuntimeException('Onvoldoende voorraadpositie: er kan niet meer worden afgeboekt/uitgegeven dan de huidige voorraad (' . (int) $product['quantity'] . ' stuks).');
        }

        $newStatus = $product['status'];
        if ($newQty === 0 && $newStatus === 'actief') {
            $newStatus = 'uitverkocht';
        } elseif ($newQty > 0 && $newStatus === 'uitverkocht') {
            $newStatus = 'actief';
        }

        $upd = $pdo->prepare('UPDATE voorraad_products SET quantity = ?, status = ? WHERE id = ?');
        $upd->execute([$newQty, $newStatus, $productId]);

        $mov = $pdo->prepare(
            'INSERT INTO voorraad_movements (product_id, type, quantity, reference, notes, reason, lot_number, actor)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $mov->execute([$productId, $type, $qty, $reference, $notes, $reason, $lotNumber, $actor]);

        if ($weStartedTransaction) {
            $pdo->commit();
        }

        return ['product' => $product, 'new_quantity' => $newQty, 'new_status' => $newStatus];
    } catch (Throwable $e) {
        if ($weStartedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Genereert een unieke magic-link-token voor een gesimuleerd inkoopvoorstel.
 */
function generateMagicLinkToken(): string
{
    return bin2hex(random_bytes(20));
}

/**
 * Maakt een "trigger-based" inkoopvoorstel aan: schrijft een gesimuleerde
 * e-mail (met magic link) weg in voorraad_email_log. Er wordt GEEN echte
 * e-mail verzonden — dit is uitsluitend een demo/simulatie, altijd zo
 * gelabeld in de UI.
 */
function createPurchaseProposal(PDO $pdo, array $product, int $proposedQty): array
{
    $token = generateMagicLinkToken();
    $supplierEmail = ($product['supplier_email'] ?? '') !== ''
        ? $product['supplier_email']
        : 'inkoop@onbekende-leverancier-demo.nl';
    $supplierName = ($product['supplier_name'] ?? '') !== '' ? $product['supplier_name'] : 'leverancier';
    $subject = 'Inkoopvoorstel: ' . $product['name'] . ' (' . $product['sku'] . ')';
    $confirmUrl = BASE . '/bevestig.php?token=' . $token;
    $body = "Beste {$supplierName},\n\n"
        . "De voorraad van {$product['name']} (SKU {$product['sku']}) is onder het ingestelde minimum gezakt.\n"
        . "Gevraagd aantal: {$proposedQty} stuks.\n\n"
        . "Bevestigingslink (DEMO/SIMULATIE — er is geen echte e-mail verzonden en dit is geen werkende externe koppeling):\n"
        . $confirmUrl . "\n";

    $stmt = $pdo->prepare(
        "INSERT INTO voorraad_email_log (token, to_email, subject, body, type, product_id, proposed_qty, status)
         VALUES (?, ?, ?, ?, 'inkoopvoorstel', ?, ?, 'verzonden')"
    );
    $stmt->execute([$token, $supplierEmail, $subject, $body, $product['id'], $proposedQty]);

    return ['token' => $token, 'to_email' => $supplierEmail, 'confirm_url' => $confirmUrl];
}

/**
 * Schrijft een regel weg in de gesimuleerde synchronisatielog van de
 * e-commerce/inkoop-mock-integratie (voorraad_sync_log). Er wordt nooit een
 * echte externe API aangeroepen.
 */
function logSync(PDO $pdo, string $platform, string $message, string $level = 'info'): void
{
    $stmt = $pdo->prepare('INSERT INTO voorraad_sync_log (platform, message, level) VALUES (?, ?, ?)');
    $stmt->execute([$platform, $message, $level]);
}
