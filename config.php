<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'restaurant_db');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, sql_mode='NO_ENGINE_SUBSTITUTION'",
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
        }
    }
    return $pdo;
}
