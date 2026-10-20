CREATE DATABASE IF NOT EXISTS billing_system;
USE billing_system;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) DEFAULT 'staff'
);

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    table_number VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    image VARCHAR(255) DEFAULT NULL,
    course_section VARCHAR(50) DEFAULT 'Main',
    stock_quantity INT NOT NULL DEFAULT 0,
    expiration_date DATE DEFAULT NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    service_charge_rate DECIMAL(5,2) NOT NULL DEFAULT 5.00
);

CREATE TABLE IF NOT EXISTS pharmacy_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_threshold_days INT NOT NULL DEFAULT 30,
    notify_email VARCHAR(255) DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS medicines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    batch_number VARCHAR(100) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    supplier VARCHAR(150) NOT NULL,
    expiration_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Menu Rule 3: expiry filtering runs on every Menu load, poll and charge — indexed here
    -- for fresh installs. Existing databases: php scripts/add_expiry_index.php (idempotent).
    KEY idx_medicines_expiration_date (expiration_date)
);

CREATE TABLE IF NOT EXISTS expiry_statuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id INT NOT NULL,
    status VARCHAR(50) NOT NULL,
    days_remaining INT NOT NULL,
    threshold_days INT NOT NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    order_type VARCHAR(50) DEFAULT 'Dine In',
    status VARCHAR(50) DEFAULT 'open',
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    service_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    modifiers VARCHAR(255) DEFAULT NULL,
    price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- Accounts are seeded by a script instead of a committed password hash: a hash in this file
-- is a reusable credential, and it had drifted from the documented login (it matched neither
-- of the passwords in the docs, so login always failed with "Invalid username or password.").
--
--   php scripts/seed_admin.php        (reads ADMIN_USERNAME / ADMIN_PASSWORD from .env)
--
-- Creates/resets the `admin` account (role=admin, status=active, bcrypt hash).
-- Additional staff accounts are created in-app via staff-signup.php or the admin Users page.

INSERT INTO settings (tax_rate, service_charge_rate) VALUES (10.00, 5.00);
INSERT INTO pharmacy_settings (alert_threshold_days, notify_email) VALUES (30, 'admin@example.com');

INSERT INTO categories (name) VALUES
('Bar'),
('Food'),
('Wine'),
('Soup'),
('Pizza'),
('Fish');

INSERT INTO products (category_id, name, price, image, course_section, stock_quantity, expiration_date) VALUES
(1, 'Sparkling Lemon', 8.50, 'assets/img/lemon.svg', 'Drink', 25, '2026-06-15'),
(1, 'Berry Mocktail', 9.00, 'assets/img/mocktail.svg', 'Drink', 30, '2026-08-20'),
(2, 'Chicken Burger', 14.50, 'assets/img/burger.svg', 'Main', 15, '2025-12-10'),
(2, 'Garden Pasta', 13.00, 'assets/img/pasta.svg', 'Main', 20, '2026-03-05'),
(3, 'Rose Pinot', 18.00, 'assets/img/wine.svg', 'Drink', 40, '2027-01-01'),
(4, 'Tomato Basil Soup', 7.50, 'assets/img/soup.svg', 'Starter', 10, '2025-11-30'),
(5, 'Margherita Pizza', 16.00, 'assets/img/pizza.svg', 'Main', 18, '2026-02-14'),
(6, 'Grilled Salmon', 21.00, 'assets/img/salmon.svg', 'Main', 12, '2025-10-22');

INSERT INTO customers (name, phone, email, table_number) VALUES
('Ava Martinez', '555-0101', 'ava@example.com', 'T1'),
('Noah Chen', '555-0102', 'noah@example.com', 'T2');

INSERT INTO medicines (name, batch_number, quantity, supplier, expiration_date) VALUES
('Paracetamol', 'PAR-001', 120, 'Medi Supply', '2026-12-15'),
('Amoxicillin', 'AMX-202', 80, 'Life Care', '2026-08-20'),
('Vitamin C', 'VIT-110', 50, 'Sunrise Pharma', '2027-01-10');
