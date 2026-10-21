-- CarLouise Cafe Management System - database schema + sample data
-- Import through phpMyAdmin (Import tab) or: mysql -u root < carlouise_cafe.sql
-- WARNING: re-importing drops and recreates all tables.

CREATE DATABASE IF NOT EXISTS carlouise_cafe CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE carlouise_cafe;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS audit_logs, product_ingredients, replenishments, stock_movements, inventory_stock,
  inventory_items, inventory_locations, payments, order_items, orders, counters, payment_methods,
  dining_tables, products, categories, settings, users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','cashier','kitchen','inventory') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE settings (
  setting_key VARCHAR(60) PRIMARY KEY,
  setting_value TEXT NOT NULL
) ENGINE=InnoDB;

CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  price DECIMAL(10,2) NOT NULL,
  image VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_products_name (name),
  INDEX idx_products_category (category_id),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

CREATE TABLE dining_tables (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE payment_methods (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  is_cash TINYINT(1) NOT NULL DEFAULT 0,
  requires_reference TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE counters (
  name VARCHAR(40) PRIMARY KEY,
  n INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(30) NOT NULL UNIQUE,
  client_token VARCHAR(64) NOT NULL UNIQUE,
  order_type ENUM('dine-in','take-out','delivery') NOT NULL,
  table_id INT UNSIGNED NULL,
  customer_name VARCHAR(100) NULL,
  delivery_address VARCHAR(255) NULL,
  notes TEXT NULL,
  total DECIMAL(10,2) NOT NULL DEFAULT 0,
  status ENUM('pending','preparing','ready','completed','cancelled') NOT NULL DEFAULT 'pending',
  payment_status ENUM('unpaid','paid','refunded') NOT NULL DEFAULT 'unpaid',
  inventory_deducted TINYINT(1) NOT NULL DEFAULT 0,
  cancel_reason VARCHAR(255) NULL,
  cancelled_by INT UNSIGNED NULL,
  authorized_by INT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  preparing_at DATETIME NULL,
  ready_at DATETIME NULL,
  completed_at DATETIME NULL,
  INDEX idx_orders_status (status),
  INDEX idx_orders_created (created_at),
  INDEX idx_orders_payment (payment_status),
  CONSTRAINT fk_orders_table FOREIGN KEY (table_id) REFERENCES dining_tables(id),
  CONSTRAINT fk_orders_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_orders_canceller FOREIGN KEY (cancelled_by) REFERENCES users(id),
  CONSTRAINT fk_orders_authorizer FOREIGN KEY (authorized_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  product_name VARCHAR(120) NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  line_total DECIMAL(10,2) NOT NULL,
  item_notes VARCHAR(255) NULL,
  INDEX idx_items_order (order_id),
  INDEX idx_items_product (product_id),
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_no VARCHAR(30) NOT NULL UNIQUE,
  order_id INT UNSIGNED NOT NULL,
  payment_method_id INT UNSIGNED NOT NULL,
  method_name VARCHAR(40) NOT NULL,
  amount_due DECIMAL(10,2) NOT NULL,
  amount_received DECIMAL(10,2) NOT NULL,
  change_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  reference_no VARCHAR(60) NULL,
  status ENUM('paid','refunded') NOT NULL DEFAULT 'paid',
  cashier_id INT UNSIGNED NOT NULL,
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  refund_reason VARCHAR(255) NULL,
  refunded_by INT UNSIGNED NULL,
  refunded_at DATETIME NULL,
  INDEX idx_payments_order (order_id),
  INDEX idx_payments_paid (paid_at),
  INDEX idx_payments_status (status),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_payments_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
  CONSTRAINT fk_payments_cashier FOREIGN KEY (cashier_id) REFERENCES users(id),
  CONSTRAINT fk_payments_refunder FOREIGN KEY (refunded_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE inventory_locations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(50) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE inventory_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  category VARCHAR(60) NOT NULL DEFAULT 'General',
  unit VARCHAR(20) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_inv_category (category)
) ENGINE=InnoDB;

CREATE TABLE inventory_stock (
  item_id INT UNSIGNED NOT NULL,
  location_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
  min_threshold DECIMAL(12,3) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (item_id, location_id),
  CONSTRAINT fk_stock_item FOREIGN KEY (item_id) REFERENCES inventory_items(id),
  CONSTRAINT fk_stock_location FOREIGN KEY (location_id) REFERENCES inventory_locations(id)
) ENGINE=InnoDB;

CREATE TABLE stock_movements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  from_location_id INT UNSIGNED NULL,
  to_location_id INT UNSIGNED NULL,
  movement_type ENUM('receive','transfer','usage','adjustment','order_usage','order_restore') NOT NULL,
  quantity DECIMAL(12,3) NOT NULL,
  reference VARCHAR(40) NULL,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mov_item (item_id),
  INDEX idx_mov_created (created_at),
  INDEX idx_mov_ref (reference),
  CONSTRAINT fk_mov_item FOREIGN KEY (item_id) REFERENCES inventory_items(id),
  CONSTRAINT fk_mov_from FOREIGN KEY (from_location_id) REFERENCES inventory_locations(id),
  CONSTRAINT fk_mov_to FOREIGN KEY (to_location_id) REFERENCES inventory_locations(id),
  CONSTRAINT fk_mov_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE replenishments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  location_id INT UNSIGNED NOT NULL,
  qty_requested DECIMAL(12,3) NOT NULL,
  qty_received DECIMAL(12,3) NULL,
  status ENUM('requested','received','cancelled') NOT NULL DEFAULT 'requested',
  requested_by INT UNSIGNED NOT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_by INT UNSIGNED NULL,
  received_at DATETIME NULL,
  notes VARCHAR(500) NULL,
  INDEX idx_rep_status (status),
  INDEX idx_rep_item (item_id, location_id),
  CONSTRAINT fk_rep_item FOREIGN KEY (item_id) REFERENCES inventory_items(id),
  CONSTRAINT fk_rep_location FOREIGN KEY (location_id) REFERENCES inventory_locations(id),
  CONSTRAINT fk_rep_requester FOREIGN KEY (requested_by) REFERENCES users(id),
  CONSTRAINT fk_rep_receiver FOREIGN KEY (received_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE product_ingredients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  location_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(12,3) NOT NULL,
  UNIQUE KEY uq_prod_item_loc (product_id, item_id, location_id),
  CONSTRAINT fk_pi_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_pi_item FOREIGN KEY (item_id) REFERENCES inventory_items(id),
  CONSTRAINT fk_pi_location FOREIGN KEY (location_id) REFERENCES inventory_locations(id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NULL,
  entity_id INT UNSIGNED NULL,
  details VARCHAR(500) NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_created (created_at),
  INDEX idx_audit_action (action)
) ENGINE=InnoDB;

-- ------------------------------------------------------------------
-- Reference data (required)
-- ------------------------------------------------------------------
INSERT INTO inventory_locations (code, name) VALUES
  ('kitchen','Kitchen'), ('cashier','Cashier'), ('stockroom','Stock Room');

INSERT INTO payment_methods (name, is_cash, requires_reference, is_active) VALUES
  ('Cash',1,0,1), ('GCash',0,1,1);

INSERT INTO settings (setting_key, setting_value) VALUES
  ('cafe_name','CarLouise Cafe'),
  ('cafe_address','Cebu City, Philippines'),
  ('cafe_phone',''),
  ('receipt_footer','Thank you for visiting CarLouise Cafe!');

INSERT INTO dining_tables (name) VALUES
  ('Table 1'),('Table 2'),('Table 3'),('Table 4'),('Table 5'),('Table 6');

-- ------------------------------------------------------------------
-- Sample data (optional - safe to delete for a clean start)
-- ------------------------------------------------------------------
INSERT INTO categories (name) VALUES
  ('Coffee'),('Non-Coffee Drinks'),('Pastries'),('Cakes'),('Meals'),('Others');

INSERT INTO products (category_id, name, description, price) VALUES
  (1,'Espresso','Double shot',90),
  (1,'Americano','Espresso with hot water',110),
  (1,'Cafe Latte','Espresso with steamed milk',130),
  (1,'Cappuccino','Espresso, milk and foam',130),
  (1,'Mocha','Espresso, chocolate and milk',140),
  (2,'Iced Chocolate','Cold chocolate drink',120),
  (2,'Iced Matcha Latte','Matcha with milk',140),
  (3,'Butter Croissant','Freshly baked',85),
  (3,'Blueberry Muffin','Baked daily',90),
  (4,'Chocolate Cake Slice','Rich chocolate cake',140),
  (4,'Carrot Cake Slice','With cream cheese frosting',140),
  (5,'Chicken Sandwich','Grilled chicken, lettuce, tomato',170),
  (5,'Pasta Carbonara','Creamy bacon pasta',210),
  (6,'Bottled Water','500 ml',40);

INSERT INTO inventory_items (name, category, unit) VALUES
  ('Espresso Beans','Coffee','kg'),
  ('Fresh Milk','Dairy','L'),
  ('Sugar','Dry Goods','kg'),
  ('Chocolate Syrup','Syrups','bottle'),
  ('Flour','Dry Goods','kg'),
  ('Paper Cups 12oz','Packaging','pcs'),
  ('Cup Lids','Packaging','pcs'),
  ('Napkins','Packaging','pack'),
  ('Croissant (ready)','Pastries','pcs'),
  ('Chocolate Cake Slice (ready)','Pastries','pcs'),
  ('Bottled Water 500ml','Beverages','pcs');

INSERT INTO inventory_stock (item_id, location_id, quantity, min_threshold)
  SELECT i.id, l.id, 0, 0 FROM inventory_items i CROSS JOIN inventory_locations l;

CREATE TEMPORARY TABLE seed_stock (item_name VARCHAR(120), loc_code VARCHAR(20), qty DECIMAL(12,3), minq DECIMAL(12,3));
INSERT INTO seed_stock VALUES
  ('Espresso Beans','kitchen',5,2),('Espresso Beans','stockroom',20,5),
  ('Fresh Milk','kitchen',12,6),('Fresh Milk','stockroom',30,10),
  ('Sugar','kitchen',3,1),('Sugar','stockroom',10,3),
  ('Chocolate Syrup','kitchen',4,2),('Chocolate Syrup','stockroom',12,4),
  ('Flour','kitchen',8,3),('Flour','stockroom',25,10),
  ('Paper Cups 12oz','kitchen',100,50),('Paper Cups 12oz','cashier',60,30),('Paper Cups 12oz','stockroom',1000,300),
  ('Cup Lids','kitchen',100,50),('Cup Lids','stockroom',1000,300),
  ('Napkins','cashier',10,5),('Napkins','stockroom',40,10),
  ('Croissant (ready)','cashier',20,8),
  ('Chocolate Cake Slice (ready)','cashier',3,4),
  ('Bottled Water 500ml','cashier',24,12),('Bottled Water 500ml','stockroom',120,48);
UPDATE inventory_stock s
  JOIN inventory_items i ON i.id = s.item_id
  JOIN inventory_locations l ON l.id = s.location_id
  JOIN seed_stock t ON t.item_name = i.name AND t.loc_code = l.code
  SET s.quantity = t.qty, s.min_threshold = t.minq;
DROP TEMPORARY TABLE seed_stock;

-- Ingredient rules: (product, inventory item, deduct-from location, quantity per 1 unit sold)
INSERT INTO product_ingredients (product_id, item_id, location_id, quantity)
  SELECT p.id, i.id, l.id, x.q FROM (
    SELECT 'Espresso' pn,'Espresso Beans' inm,'kitchen' lc,0.018 q UNION ALL
    SELECT 'Espresso','Paper Cups 12oz','kitchen',1 UNION ALL
    SELECT 'Americano','Espresso Beans','kitchen',0.018 UNION ALL
    SELECT 'Americano','Paper Cups 12oz','kitchen',1 UNION ALL
    SELECT 'Cafe Latte','Espresso Beans','kitchen',0.018 UNION ALL
    SELECT 'Cafe Latte','Fresh Milk','kitchen',0.2 UNION ALL
    SELECT 'Cafe Latte','Paper Cups 12oz','kitchen',1 UNION ALL
    SELECT 'Cappuccino','Espresso Beans','kitchen',0.018 UNION ALL
    SELECT 'Cappuccino','Fresh Milk','kitchen',0.15 UNION ALL
    SELECT 'Cappuccino','Paper Cups 12oz','kitchen',1 UNION ALL
    SELECT 'Mocha','Espresso Beans','kitchen',0.018 UNION ALL
    SELECT 'Mocha','Fresh Milk','kitchen',0.15 UNION ALL
    SELECT 'Mocha','Chocolate Syrup','kitchen',0.05 UNION ALL
    SELECT 'Mocha','Paper Cups 12oz','kitchen',1 UNION ALL
    SELECT 'Iced Chocolate','Fresh Milk','kitchen',0.2 UNION ALL
    SELECT 'Iced Chocolate','Chocolate Syrup','kitchen',0.06 UNION ALL
    SELECT 'Iced Chocolate','Paper Cups 12oz','kitchen',1 UNION ALL
    SELECT 'Butter Croissant','Croissant (ready)','cashier',1 UNION ALL
    SELECT 'Chocolate Cake Slice','Chocolate Cake Slice (ready)','cashier',1 UNION ALL
    SELECT 'Bottled Water','Bottled Water 500ml','cashier',1
  ) x
  JOIN products p ON p.name = x.pn
  JOIN inventory_items i ON i.name = x.inm
  JOIN inventory_locations l ON l.code = x.lc;
