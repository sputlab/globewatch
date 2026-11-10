<?php
require_once __DIR__ . '/config.php';

final class GwDb {
    public static ?PDO $pdo = null;
}

function db(): PDO {
    if (GwDb::$pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        GwDb::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,               // fail fast instead of hanging the request
        ]);
    }
    return GwDb::$pdo;
}

/**
 * Drops the current connection so the next db() call opens a new one.
 * Used to recover from "MySQL server has gone away" (2006): on long requests
 * (many external calls in a row) the connection may outlive wait_timeout.
 */
function forceReconnectDb(): PDO {
    GwDb::$pdo = null;
    return db();
}
