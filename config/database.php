<?php
/** MySQLi connection + prepared-statement helpers. */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $db->set_charset('utf8mb4');
    $db->query("SET time_zone = '" . DB_TIMEZONE_OFFSET . "'");
} catch (Throwable $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    echo '<h3>Cannot connect to the database.</h3><p>Make sure MySQL is running in XAMPP, the database <code>carlouise_cafe</code> has been imported, and <code>config/config.php</code> has the right credentials.</p>';
    if (defined('APP_DEBUG') && APP_DEBUG) echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}

function db(): mysqli { global $db; return $db; }

function db_run(string $sql, array $params = []): mysqli_stmt {
    $stmt = db()->prepare($sql);
    if ($params) {
        $types = '';
        foreach ($params as $p) $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}
/** All rows as associative arrays. */
function db_all(string $sql, array $params = []): array {
    $stmt = db_run($sql, $params);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
function db_one(string $sql, array $params = []): ?array {
    $rows = db_all($sql, $params);
    return $rows[0] ?? null;
}
function db_val(string $sql, array $params = []) {
    $row = db_one($sql, $params);
    return $row ? array_values($row)[0] : null;
}
/** Run INSERT/UPDATE/DELETE. Returns affected rows. */
function db_exec(string $sql, array $params = []): int {
    $stmt = db_run($sql, $params);
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}
/** Run INSERT and return the new auto-increment id. */
function db_insert(string $sql, array $params = []): int {
    $stmt = db_run($sql, $params);
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}
/** Run $fn inside a transaction; rolls back on any exception. */
function db_tx(callable $fn) {
    db()->begin_transaction();
    try {
        $r = $fn();
        db()->commit();
        return $r;
    } catch (Throwable $e) {
        db()->rollback();
        throw $e;
    }
}
/** Build "?,?,?" placeholders for an IN() list. */
function db_in(array $values): string { return implode(',', array_fill(0, count($values), '?')); }
