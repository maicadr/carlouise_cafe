# CarLouise Cafe Management System

PHP 8 + MySQL (MySQLi, prepared statements) + Bootstrap 5 + Chart.js. Runs locally on XAMPP. All libraries are bundled in `assets/vendor`, so it works **offline**.

## Folder structure
```
carlouise_cafe/
├── index.php            entry point (redirects to login/dashboard)
├── setup.php            first-time owner account creation (delete afterwards)
├── config/              config.php (credentials), config.example.php, database.php (MySQLi helpers)
├── auth/                login.php, logout.php, session.php (sessions, CSRF, role permissions)
├── includes/            functions, layout, orders.php (orders/payments logic), inventory.php (stock logic)
├── pages/               dashboard, pos, orders, order_view, kitchen, ticket, receipt, products, sales,
│                        payments, inventory, transfers, replenishment, reports, users, settings, profile
├── assets/              css, js, vendor (bootstrap, icons, chart.js), uploads (product images)
└── database/            carlouise_cafe.sql (schema + sample data)
```

## Installation (XAMPP)
1. Install/open **XAMPP**. Start **Apache** and **MySQL** in the control panel.
2. Copy the `carlouise_cafe` folder into `C:\xampp\htdocs\`.
3. Open `http://localhost/phpmyadmin` → **Import** → choose `database/carlouise_cafe.sql` → **Import**.
   (It creates the `carlouise_cafe` database, all tables, indexes, foreign keys and sample data.)
4. Check `config/config.php`. XAMPP defaults (user `root`, empty password) already work. If you set a MySQL password, put it in `DB_PASS`. Keep `BASE_URL` as `/carlouise_cafe` if the folder name is unchanged.
5. Open `http://localhost/carlouise_cafe/setup.php` and create the **owner** account. Tick *"create demo accounts"* to also get test accounts, then **delete `setup.php`**.
6. Log in at `http://localhost/carlouise_cafe/`.

## Test accounts (created by setup.php with "demo accounts" ticked)
| Role | Username | Password |
|---|---|---|
| Owner / Administrator | the one you chose in setup | the one you chose |
| Cashier | `cashier1` | `Cashier#2026` (temporary) |
| Kitchen Staff | `kitchen1` | `Kitchen#2026` (temporary) |
| Inventory Manager | `stock1` | `Stock#2026` (temporary) |

**These demo passwords are temporary test credentials.** Change or deactivate them in *Users* before real use.

## Business rules implemented
- **Owner role is oversight-only:** the owner sees dashboard, orders (view), sales, payments (and refunds), reports, inventory, products, users and settings. The owner cannot create orders, take payments or run the kitchen screen; those belong to Cashier and Kitchen.
- **Order flow:** Pending → Preparing → Ready → Completed. Kitchen staff advance the steps; cashiers can only mark Ready orders as Completed (served).
- **Duplicate protection:** each POS screen carries a one-time token; double clicks/retries return the existing order. Payments are locked per order, so a second payment is rejected.
- **Payment:** an order is marked paid only in the same database transaction that saves the payment. Cash needs enough money received (change is computed); GCash needs a reference number. Methods are configurable in Settings.
- **Cancellation:** only open orders. Cashiers need an administrator's username/password; reason is required. A paid order's payment is marked refunded and deducted ingredients are put back. Completed orders are refunded by the owner under Payments (stock is not restored).
- **Sales definition:** sales = payments still in "paid" status. Cancelled orders and refunded payments are excluded.
- **Inventory:** per-location stock; ingredient rules (Products → Ingredients) deduct stock when an order is saved. Insufficient stock blocks the order unless the owner ticks the "negative stock" override (recorded in the audit log).
- **Transfers/receiving/replenishment** run in database transactions. Requested replenishment quantities are only added to stock after you confirm receipt.
- **Printing:** receipts and kitchen tickets open as 80 mm print layouts and use the **browser print dialog** (choose your receipt or standard printer). Nothing prints silently or automatically without that dialog.
- **Security:** `password_hash`/`password_verify`, session auth with timeout, server-side role checks on every page and action, CSRF tokens, prepared statements, output escaping, login throttling, audit log, uploads restricted to images.

## Testing checklist
1. **Login/roles:** log in as each role; menu shows only allowed items; opening e.g. `/pages/users.php` as cashier shows "Access denied".
2. **POS:** as cashier add products, pick dine-in + table, add a note, *Send to kitchen*. Double-click the button → only one order exists.
3. **Kitchen:** as kitchen user the order appears within 8 s; press *Start preparing* → *Mark as ready* → *Mark as completed*. Print the ticket (printer icon).
4. **Ready alert:** as cashier, *Orders* shows a green "ready to serve" banner; press *Mark served*.
5. **Payment:** open the order → pay Cash with too little money (error), then enough (change shown) → receipt. Try paying again (rejected). Try GCash without a reference (error).
6. **Stock deduction:** *Inventory* shows reduced Kitchen quantities after selling a Cafe Latte; *Stock Transfers → movement history* lists `Order usage`.
7. **Cancel:** create a Mocha order, cancel as cashier with wrong then correct admin credentials; stock is restored.
8. **Refund:** as owner refund a completed, paid order under *Payments*; it disappears from Sales and reports.
9. **Transfer:** transfer Espresso Beans Stock Room → Kitchen; both quantities change. Try more than available (blocked, nothing changes).
10. **Low stock:** Chocolate Cake Slice (ready) starts below its minimum → shows on dashboard/inventory. As cashier press *Report low*.
11. **Replenishment:** as inventory user create a request (stock unchanged), then *Confirm received* (stock increases).
12. **Reports:** run every report, filter by dates/product/category, *Export CSV*, *Print*.
13. **Products:** add a product with an image; deactivate it → it vanishes from the POS. Edit ingredient rules.
14. **Users/Settings:** add a user, deactivate it (login fails); add a table and a payment method.
15. **Database:** in phpMyAdmin check `orders`, `order_items`, `payments`, `stock_movements`, `audit_logs` hold matching records.

## Notes
- To start with a clean database, delete the sample data sections at the bottom of the SQL file (categories, products, inventory items, ingredients) before importing.
- Back up the `carlouise_cafe` database regularly (phpMyAdmin → Export).
- For LAN use by several devices, open `http://<computer-ip>/carlouise_cafe/` and allow Apache through the firewall. Use HTTPS if exposed beyond your local network.
