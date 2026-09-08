<?php

declare(strict_types=1);

namespace Smilo\Model;

use PDO;

class Database
{
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';

    public static function connect(array $config): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        self::$driver = $config['driver'] ?? 'sqlite';

        if (self::$driver === 'mysql') {
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4";
            self::$pdo = new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } else {
            $dbPath = $config['sqlite_path'] ?? __DIR__ . '/../../storage/database/smilo.db';
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            self::$pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::$pdo->exec('PRAGMA journal_mode=WAL');
            self::$pdo->exec('PRAGMA foreign_keys=ON');
        }

        return self::$pdo;
    }

    public static function driver(): string
    {
        return self::$driver;
    }

    public static function migrate(PDO $db): void
    {
        if (self::$driver === 'mysql') {
            self::migrateMysql($db);
        } else {
            self::migrateSqlite($db);
        }
    }

    private static function migrateMysql(PDO $db): void
    {
        $db->exec("SET FOREIGN_KEY_CHECKS = 0");

        $db->exec("
            CREATE TABLE IF NOT EXISTS sellers (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(255) NOT NULL,
                location    VARCHAR(255) NOT NULL,
                verified    TINYINT(1) NOT NULL DEFAULT 0,
                phone       VARCHAR(50),
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sellers_verified (verified)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS products (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                seller_id       INT NOT NULL,
                name            VARCHAR(255) NOT NULL,
                description     TEXT,
                price           DECIMAL(12,2) NOT NULL,
                original_price  DECIMAL(12,2),
                discount        INT,
                image           VARCHAR(500),
                category        VARCHAR(100) NOT NULL,
                `condition`     VARCHAR(50) NOT NULL DEFAULT 'Brand New',
                rating          DECIMAL(2,1) DEFAULT 0.0,
                review_count    INT DEFAULT 0,
                promoted        TINYINT(1) NOT NULL DEFAULT 0,
                status          ENUM('active','sold','removed') NOT NULL DEFAULT 'active',
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (seller_id) REFERENCES sellers(id),
                INDEX idx_products_category (category),
                INDEX idx_products_status (status),
                INDEX idx_products_seller (seller_id),
                INDEX idx_products_price (price),
                INDEX idx_products_promoted (promoted)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    }

    private static function migrateSqlite(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS sellers (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL,
                location    TEXT NOT NULL,
                verified    INTEGER NOT NULL DEFAULT 0,
                phone       TEXT,
                created_at  TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS products (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                seller_id       INTEGER NOT NULL,
                name            TEXT NOT NULL,
                description     TEXT,
                price           REAL NOT NULL,
                original_price  REAL,
                discount        INTEGER,
                image           TEXT,
                category        TEXT NOT NULL,
                condition       TEXT NOT NULL DEFAULT 'Brand New',
                rating          REAL DEFAULT 0,
                review_count    INTEGER DEFAULT 0,
                promoted        INTEGER NOT NULL DEFAULT 0,
                status          TEXT NOT NULL DEFAULT 'active',
                created_at      TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (seller_id) REFERENCES sellers(id)
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_products_category ON products(category)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_products_status ON products(status)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_products_seller ON products(seller_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_products_price ON products(price)');
    }

    public static function seed(PDO $db): void
    {
        $sellerCount = (int) $db->query('SELECT COUNT(*) FROM sellers')->fetchColumn();
        $productCount = (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
        if ($sellerCount > 0 && $productCount > 0) {
            return;
        }

        // Partial seed? Clean up and retry
        if ($sellerCount > 0 || $productCount > 0) {
            if (self::$driver === 'mysql') {
                $db->exec('SET FOREIGN_KEY_CHECKS = 0');
                $db->exec('TRUNCATE TABLE products');
                $db->exec('TRUNCATE TABLE sellers');
                $db->exec('SET FOREIGN_KEY_CHECKS = 1');
            } else {
                $db->exec('DELETE FROM products');
                $db->exec('DELETE FROM sellers');
            }
        }

        // Sellers
        $sellers = [
            ['TechHub Lagos',     'Ikeja, Lagos',        1],
            ['GadgetPoint',       'Wuse 2, Abuja',       1],
            ['Chioma Electronics','Port Harcourt, Rivers',0],
            ['Musa Phones & More','Kano, Kano',           0],
            ['PrimeDeals Store',  'Lekki, Lagos',         1],
            ['Emeka Home Appl.',  'Aba, Abia',            0],
            ['SwiftMart NG',      'Yaba, Lagos',          1],
            ['Bola Fashion House','Ibadan, Oyo',          1],
        ];

        $stmt = $db->prepare('INSERT INTO sellers (name, location, verified) VALUES (?, ?, ?)');
        foreach ($sellers as [$name, $loc, $ver]) {
            $stmt->execute([$name, $loc, $ver]);
        }

        // Products — seller_id cycles 1-8
        $products = [
            [1, 'Samsung Galaxy S24 Ultra 256GB', 'Samsung\'s flagship with S Pen and titanium frame.', 27499, 29999, 8, '/images/products/samsung-s24.jpg', 'Cellphones & Tablets', 'Brand New', 4.5, 312, 1],
            [2, 'Apple iPhone 15 Pro Max 256GB', 'Apple\'s premium smartphone with A17 Pro chip.', 32999, null, null, '/images/products/iphone-15-pro.jpg', 'Cellphones & Tablets', 'Brand New', 4.8, 567, 1],
            [3, 'Sony WH-1000XM5 Headphones', 'Industry-leading noise cancellation.', 5499, 6499, 15, '/images/products/sony-xm5.jpg', 'Headphones', 'Brand New', 4.7, 892, 0],
            [4, 'Apple MacBook Air M3 15"', 'Powerful M3 chip, stunning display.', 28999, null, null, '/images/products/macbook-air-m3.jpg', 'Computers & Laptops', 'Brand New', 4.9, 234, 0],
            [5, 'Samsung 65" QN85D Neo QLED', 'Stunning 4K picture with Quantum Matrix.', 22999, 27999, 18, '/images/products/samsung-tv.jpg', 'TV & Audio', 'Brand New', 4.4, 156, 0],
            [6, 'Sony PlayStation 5 Slim', 'Next-gen gaming console.', 8999, null, null, '/images/products/ps5-slim.jpg', 'Gaming', 'Brand New', 4.8, 1245, 1],
            [7, 'Apple Watch Series 9 45mm', 'Advanced health features.', 7499, null, null, '/images/products/apple-watch-9.jpg', 'Wearables', 'Brand New', 4.6, 423, 0],
            [8, 'JBL Charge 5 Speaker', 'Powerful portable Bluetooth speaker.', 2499, 2999, 17, '/images/products/jbl-charge-5.jpg', 'TV & Audio', 'Brand New', 4.5, 678, 0],
            [1, 'Nike Air Force 1 Low', 'Classic white sneakers.', 1699, null, null, '/images/products/nike-af1.jpg', 'Fashion', 'Brand New', 4.3, 934, 0],
            [2, 'Xiaomi 14 Ultra 512GB', 'Leica-tuned camera system.', 19999, 22999, 13, '/images/products/xiaomi-14.jpg', 'Cellphones & Tablets', 'Brand New', 4.6, 189, 0],
            [3, 'Dyson V15 Detect Vacuum', 'Laser reveals invisible dust.', 12999, null, null, '/images/products/dyson-v15.jpg', 'Home & Kitchen', 'Brand New', 4.7, 312, 0],
            [4, 'GoPro HERO12 Black', 'Waterproof action camera.', 6499, 7499, 13, '/images/products/gopro-12.jpg', 'Cameras', 'Brand New', 4.4, 267, 0],
            [5, 'Lenovo ThinkPad X1 Carbon', 'Premium business ultrabook.', 21999, null, null, '/images/products/thinkpad-x1.jpg', 'Computers & Laptops', 'Brand New', 4.5, 178, 0],
            [6, 'Adidas Ultraboost Light', 'Responsive running shoes.', 2299, 2999, 23, '/images/products/ultraboost.jpg', 'Fashion', 'Brand New', 4.4, 421, 0],
            [7, 'Samsung Galaxy Watch6', 'Advanced health monitoring.', 5999, 6999, 14, '/images/products/watch6.jpg', 'Wearables', 'Brand New', 4.3, 267, 0],
            [8, 'Dell Inspiron 15 3520', 'Reliable everyday laptop.', 8999, null, null, '/images/products/dell-inspiron.jpg', 'Computers & Laptops', 'Brand New', 4.2, 156, 0],
            [1, 'Nintendo Switch OLED', 'Vibrant OLED screen.', 5499, null, null, '/images/products/switch-oled.jpg', 'Gaming', 'Brand New', 4.8, 678, 0],
            [2, 'Samsung Galaxy Buds3 Pro', 'Intelligent ANC earbuds.', 2999, 3499, 14, '/images/products/buds3-pro.jpg', 'Headphones', 'Brand New', 4.6, 234, 0],
            [3, 'Philips AirFryer XL 6.2L', 'Rapid air technology.', 1899, 2499, 24, '/images/products/airfryer.jpg', 'Home & Kitchen', 'Brand New', 4.5, 567, 0],
            [4, 'Canon EOS R50 Camera', '24.2MP mirrorless camera.', 11499, null, null, '/images/products/eos-r50.jpg', 'Cameras', 'Brand New', 4.7, 189, 0],
            [5, 'Nike Pegasus 40', 'Responsive road running shoes.', 1999, 2599, 23, '/images/products/pegasus-40.jpg', 'Fashion', 'Brand New', 4.5, 345, 0],
            [6, 'Bose QC Ultra Earbuds', 'Immersive spatial audio.', 4999, null, null, '/images/products/bose-qc.jpg', 'Headphones', 'Brand New', 4.6, 312, 0],
            [7, 'HP DeskJet 4155e Printer', 'All-in-one wireless printer.', 1299, null, null, '/images/products/hp-printer.jpg', 'Computers & Laptops', 'Brand New', 4.1, 423, 0],
            [8, 'Asus ROG Ally 7"', 'Handheld gaming console.', 9999, null, null, '/images/products/rog-ally.jpg', 'Gaming', 'Brand New', 4.4, 198, 0],
            [1, 'Xbox Series X 1TB', 'Most powerful Xbox.', 8499, null, null, '/images/products/xbox-series-x.jpg', 'Gaming', 'Brand New', 4.7, 567, 0],
            [2, 'Huawei MatePad Pro 12.6"', 'Premium tablet with keyboard.', 8999, 10999, 18, '/images/products/matepad-pro.jpg', 'Computers & Laptops', 'Brand New', 4.3, 134, 0],
            [3, 'Logitech MX Master 3S', 'Ergonomic wireless mouse.', 1499, null, null, '/images/products/mx-master-3s.jpg', 'Computers & Laptops', 'Brand New', 4.8, 789, 0],
            [4, 'JBL Flip 6 Speaker', 'Portable waterproof speaker.', 1499, 1899, 21, '/images/products/jbl-flip-6.jpg', 'TV & Audio', 'Brand New', 4.5, 456, 0],
            [5, 'Russell Hobbs Kettle', '1.7L stainless steel kettle.', 499, null, null, '/images/products/hobbs-kettle.jpg', 'Home & Kitchen', 'Brand New', 4.2, 234, 0],
            [6, 'Garmin Fenix 7 Solar', 'GPS multisport watch.', 11999, 13999, 14, '/images/products/fenix-7.jpg', 'Wearables', 'Brand New', 4.7, 312, 0],
        ];

        $stmt = $db->prepare(
            'INSERT INTO products (seller_id, name, description, price, original_price, discount, image, category, `condition`, rating, review_count, promoted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($products as $p) {
            $stmt->execute($p);
        }
    }
}
