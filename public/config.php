<?php
declare(strict_types=1);

define('DB_HOST', 'y11ovnrne4yk4p9zbhe39tti');
define('DB_NAME', 'demos');
define('DB_USER', 'mysql');
define('DB_PASS', '23ns613Dyo1vgiAOQCt2ABFZzujOsxuyROvqNk4unUoZxWpwN9nIPrMNTt4QFkzG');
define('BASE', '/voorraadbeheer');
define('DEMO_RESET_MINUTES', 30);

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
}

function seed_data(PDO $pdo): void
{
    $count = (int) $pdo->query("SELECT COUNT(*) FROM voorraad_products")->fetchColumn();
    if ($count > 0) {
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

    $insertProd = $pdo->prepare("INSERT INTO voorraad_products (name, sku, category, quantity, min_stock, location, unit_price, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $insertMov  = $pdo->prepare("INSERT INTO voorraad_movements (product_id, type, quantity, reference, notes) VALUES (?, ?, ?, ?, ?)");

    $pdo->beginTransaction();
    try {
        foreach ($products as $p) {
            $insertProd->execute($p);
            $pid = (int) $pdo->lastInsertId();

            // create a couple of movements per product
            $insertMov->execute([$pid, 'in',  $p[3] + 5, 'ORD-' . mt_rand(1000, 9999), 'Initiele voorraad']);
            if ($p[3] > 0) {
                $insertMov->execute([$pid, 'uit', mt_rand(1, min(5, $p[3])), 'VER-' . mt_rand(1000, 9999), 'Uitgift']);
            }
        }
        $pdo->exec("UPDATE voorraad_settings SET last_reset = NOW() WHERE id = 1");
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
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
        $pdo->exec("DELETE FROM voorraad_movements");
        $pdo->exec("DELETE FROM voorraad_products");
        $pdo->exec("UPDATE voorraad_settings SET last_reset = NOW() WHERE id = 1");
        seed_data($pdo);
    }
}

init_schema($pdo);
seed_data($pdo);
maybe_reset($pdo);

session_start();

function auth_check(): void
{
    if (empty($_SESSION['user'])) {
        header('Location: ' . BASE . '/login.php');
        exit;
    }
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
