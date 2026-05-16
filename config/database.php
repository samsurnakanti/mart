<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_PORT = '3307';
const DB_USER = 'root';
const DB_PASS = '';
const DB_NAME = 'vmcmarts';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $server = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $server->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(160) NOT NULL UNIQUE,
            phone VARCHAR(30) DEFAULT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('user','admin','super_admin') NOT NULL DEFAULT 'user',
            wallet_points INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    try {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('user','admin','super_admin') NOT NULL DEFAULT 'user'");
    } catch (Throwable $ignored) {
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL UNIQUE,
            image_path VARCHAR(255) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sliders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            subtitle TEXT DEFAULT NULL,
            button_text VARCHAR(80) DEFAULT NULL,
            button_link VARCHAR(255) DEFAULT NULL,
            image_path VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(180) NOT NULL,
            category VARCHAR(80) NOT NULL,
            description TEXT DEFAULT NULL,
            mrp DECIMAL(10,2) NOT NULL DEFAULT 0,
            selling_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
            discount_points INT NOT NULL DEFAULT 0,
            stock INT NOT NULL DEFAULT 0,
            image_path VARCHAR(255) DEFAULT NULL,
            product_type ENUM('regular','discount_points') NOT NULL DEFAULT 'regular',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            image_path VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            subtotal DECIMAL(10,2) NOT NULL,
            tax_total DECIMAL(10,2) NOT NULL,
            points_used INT NOT NULL DEFAULT 0,
            grand_total DECIMAL(10,2) NOT NULL,
            points_earned INT NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'Placed',
            shipping_name VARCHAR(120) NOT NULL,
            shipping_phone VARCHAR(30) NOT NULL,
            shipping_address TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(180) NOT NULL,
            qty INT NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            tax_amount DECIMAL(10,2) NOT NULL,
            points_value INT NOT NULL DEFAULT 0,
            product_type VARCHAR(40) NOT NULL,
            FOREIGN KEY (order_id) REFERENCES orders(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_cards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            source_order_id INT NOT NULL,
            source_order_item_id INT NOT NULL UNIQUE,
            card_name VARCHAR(180) NOT NULL,
            total_points INT NOT NULL DEFAULT 0,
            remaining_points INT NOT NULL DEFAULT 0,
            status ENUM('active','exhausted') NOT NULL DEFAULT 'active',
            activated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (source_order_id) REFERENCES orders(id),
            FOREIGN KEY (source_order_item_id) REFERENCES order_items(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wallet_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            order_id INT DEFAULT NULL,
            points INT NOT NULL,
            type ENUM('credit','debit') NOT NULL,
            note VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    seed_defaults($pdo);
    seed_categories($pdo);
    seed_sliders($pdo);
    seed_catalog_products($pdo);
}

function seed_defaults(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute(['superadmin@vmcmarts.local']);
    if (!$stmt->fetch()) {
        $create = $pdo->prepare('INSERT INTO users (name,email,phone,password_hash,role,wallet_points) VALUES (?,?,?,?,?,?)');
        $create->execute(['VMCmarts Super Admin', 'superadmin@vmcmarts.local', '9999900001', password_hash('super123', PASSWORD_DEFAULT), 'super_admin', 0]);
    }

    $stmt->execute(['admin@vmcmarts.local']);
    if (!$stmt->fetch()) {
        $create = $pdo->prepare('INSERT INTO users (name,email,phone,password_hash,role,wallet_points) VALUES (?,?,?,?,?,?)');
        $create->execute(['VMCmarts Admin', 'admin@vmcmarts.local', '9999900000', password_hash('admin123', PASSWORD_DEFAULT), 'admin', 0]);
    }

    $count = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $seed = $pdo->prepare('INSERT INTO products (name,category,description,mrp,selling_price,tax_percent,discount_points,stock,product_type,image_path) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ([
        ['Fresh Tomato', 'Vegetables', 'Local farm tomatoes, packed fresh.', 35, 24, 5, 2, 100, 'regular', null],
        ['BRU Instant Coffee', 'Tea & Coffee', 'Rich aroma instant coffee pack.', 145, 99, 12, 5, 80, 'regular', null],
        ['Surf Excel Liquid', 'Home Care', 'Liquid detergent for daily washing.', 275, 220, 18, 8, 40, 'regular', null],
        ['VMC Discount Card 500', 'Discount Cards', 'Buy this product and get 500 wallet discount points.', 500, 500, 0, 500, 999, 'discount_points', null],
        ['VMC Discount Card 1000', 'Discount Cards', 'Buy this product and get 1000 wallet discount points.', 1000, 1000, 0, 1000, 999, 'discount_points', null],
    ] as $row) {
        $seed->execute($row);
    }
}

function seed_catalog_products(PDO $pdo): void
{
    $products = [
        ['Onion 1kg', 'Vegetables', 'Fresh onions selected for daily cooking.', 60, 42, 5, 2, 80, 'regular', 'assets/cat-vegetables.svg'],
        ['Tomato 1kg', 'Vegetables', 'Ripe red tomatoes for curries, salads and chutneys.', 55, 38, 5, 2, 75, 'regular', 'assets/cat-vegetables.svg'],
        ['Potato 1kg', 'Vegetables', 'Firm potatoes for fries, curry and snacks.', 48, 34, 5, 2, 90, 'regular', 'assets/cat-vegetables.svg'],
        ['Carrot 500g', 'Vegetables', 'Crunchy carrots rich in natural sweetness.', 45, 32, 5, 2, 60, 'regular', 'assets/cat-vegetables.svg'],
        ['Capsicum 500g', 'Vegetables', 'Fresh green capsicum for stir fry and toppings.', 70, 54, 5, 3, 45, 'regular', 'assets/cat-vegetables.svg'],

        ['Sona Masoori Rice 5kg', 'Grocery', 'Premium daily-use rice for soft cooked meals.', 420, 365, 5, 10, 35, 'regular', 'assets/cat-grocery.svg'],
        ['Toor Dal 1kg', 'Grocery', 'Clean and sorted toor dal for sambar and dal fry.', 180, 152, 5, 6, 50, 'regular', 'assets/cat-grocery.svg'],
        ['Sunflower Oil 1L', 'Grocery', 'Refined sunflower oil for everyday cooking.', 175, 148, 5, 5, 55, 'regular', 'assets/cat-grocery.svg'],
        ['Aashirvaad Atta 5kg', 'Grocery', 'Whole wheat atta for soft rotis.', 310, 279, 5, 8, 40, 'regular', 'assets/cat-grocery.svg'],
        ['Sugar 1kg', 'Grocery', 'Fine white sugar for tea, coffee and sweets.', 55, 46, 5, 2, 85, 'regular', 'assets/cat-grocery.svg'],

        ['BRU Instant Coffee 100g', 'Tea & Coffee', 'Rich aroma instant coffee for daily refreshment.', 245, 199, 12, 8, 50, 'regular', 'assets/cat-tea.svg'],
        ['Tata Tea Gold 250g', 'Tea & Coffee', 'Aromatic tea blend with strong taste.', 180, 155, 12, 5, 55, 'regular', 'assets/cat-tea.svg'],
        ['Red Label Tea 500g', 'Tea & Coffee', 'Classic tea for family mornings.', 340, 299, 12, 8, 35, 'regular', 'assets/cat-tea.svg'],
        ['Nescafe Classic 50g', 'Tea & Coffee', 'Classic instant coffee with deep flavor.', 180, 158, 12, 5, 45, 'regular', 'assets/cat-tea.svg'],
        ['Green Tea 25 Bags', 'Tea & Coffee', 'Light green tea bags for a fresh routine.', 175, 139, 12, 6, 30, 'regular', 'assets/cat-tea.svg'],

        ['Heritage Milk 1L', 'Dairy', 'Fresh toned milk for tea, coffee and cooking.', 75, 68, 5, 2, 70, 'regular', 'assets/cat-dairy.svg'],
        ['Farm Fresh Eggs 12pcs', 'Dairy', 'Protein-rich fresh eggs.', 105, 88, 5, 4, 45, 'regular', 'assets/cat-dairy.svg'],
        ['Paneer 200g', 'Dairy', 'Soft paneer for curries and snacks.', 120, 98, 5, 4, 28, 'regular', 'assets/cat-dairy.svg'],
        ['Curd 500g', 'Dairy', 'Thick curd with natural taste.', 70, 58, 5, 2, 50, 'regular', 'assets/cat-dairy.svg'],
        ['Brown Bread 400g', 'Dairy', 'Soft sliced bread for breakfast and sandwiches.', 65, 52, 5, 2, 42, 'regular', 'assets/cat-dairy.svg'],

        ['Surf Excel Liquid 1L', 'Home Care', 'Powerful liquid detergent for machine wash.', 275, 220, 18, 8, 40, 'regular', 'assets/cat-home.svg'],
        ['Harpic Toilet Cleaner 1L', 'Home Care', 'Deep cleaning toilet cleaner.', 210, 178, 18, 6, 45, 'regular', 'assets/cat-home.svg'],
        ['Vim Dishwash Gel 750ml', 'Home Care', 'Grease-cutting dishwash gel.', 185, 152, 18, 6, 38, 'regular', 'assets/cat-home.svg'],
        ['Lizol Floor Cleaner 1L', 'Home Care', 'Disinfectant floor cleaner with fresh fragrance.', 240, 199, 18, 7, 36, 'regular', 'assets/cat-home.svg'],
        ['Garbage Bags Large 30pcs', 'Home Care', 'Strong garbage bags for kitchen and home use.', 150, 119, 18, 5, 48, 'regular', 'assets/cat-home.svg'],

        ['VMC Discount Card 250', 'Discount Cards', 'Buy and receive 250 wallet discount points.', 250, 250, 0, 250, 999, 'discount_points', 'assets/cat-card.svg'],
        ['VMC Discount Card 750', 'Discount Cards', 'Buy and receive 750 wallet discount points.', 750, 750, 0, 750, 999, 'discount_points', 'assets/cat-card.svg'],
        ['VMC Discount Card 1500', 'Discount Cards', 'Buy and receive 1500 wallet discount points.', 1500, 1500, 0, 1500, 999, 'discount_points', 'assets/cat-card.svg'],
        ['VMC Family Saver Card', 'Discount Cards', 'Family saver card with 2000 wallet points.', 2000, 2000, 0, 2000, 999, 'discount_points', 'assets/cat-card.svg'],
        ['VMC Monthly Saver Card', 'Discount Cards', 'Monthly saver card with 3000 wallet points.', 3000, 3000, 0, 3000, 999, 'discount_points', 'assets/cat-card.svg'],
    ];

    $exists = $pdo->prepare('SELECT id FROM products WHERE name = ? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO products (name,category,description,mrp,selling_price,tax_percent,discount_points,stock,product_type,image_path) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($products as $row) {
        $exists->execute([$row[0]]);
        if (!$exists->fetch()) {
            $insert->execute($row);
        }
    }
}

function seed_categories(PDO $pdo): void
{
    $defaults = [
        ['Vegetables', 'assets/cat-vegetables.svg', 10],
        ['Grocery', 'assets/cat-grocery.svg', 20],
        ['Tea & Coffee', 'assets/cat-tea.svg', 30],
        ['Dairy', 'assets/cat-dairy.svg', 40],
        ['Home Care', 'assets/cat-home.svg', 50],
        ['Discount Cards', 'assets/cat-card.svg', 60],
    ];
    $exists = $pdo->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO categories (name,image_path,sort_order) VALUES (?,?,?)');
    foreach ($defaults as $row) {
        $exists->execute([$row[0]]);
        if (!$exists->fetch()) {
            $insert->execute($row);
        }
    }

    $missing = $pdo->query("
        SELECT DISTINCT p.category
        FROM products p
        LEFT JOIN categories c ON c.name = p.category
        WHERE c.id IS NULL
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($missing as $name) {
        $insert->execute([$name, null, 999]);
    }
}

function seed_sliders(PDO $pdo): void
{
    $count = (int)$pdo->query('SELECT COUNT(*) FROM sliders')->fetchColumn();
    if ($count > 0) {
        return;
    }
    $insert = $pdo->prepare('INSERT INTO sliders (title,subtitle,button_text,button_link,image_path,sort_order) VALUES (?,?,?,?,?,?)');
    foreach ([
        ['Fresh groceries for every day', 'Shop quality daily essentials from VMCmarts.', 'Shop Products', 'index.php?page=products', 'assets/slider-fresh.svg', 10],
        ['Discount cards for wallet savings', 'Buy a card first, then use approved points on later orders.', 'View Cards', 'index.php?page=products&category=Discount+Cards', 'assets/slider-wallet.svg', 20],
    ] as $row) {
        $insert->execute($row);
    }
}
