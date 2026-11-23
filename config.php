<?php
// ============================================================
// NexLead CRM — Database Configuration
// ============================================================

define('DB_HOST', getenv('mysql.railway.internal'));
define('DB_USER', getenv('root'));
define('DB_PASS', getenv('LofkVlIbIFoCCivewPomvXCwYqVIgLRR'));
define('DB_NAME', getenv('railway'));
define('DB_PORT', getenv('MYSQLPORT') ?: 3306);

class Database {
    private static ?PDO $instance = null;

    public static function connect(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                DB_HOST, DB_PORT, DB_NAME
            );
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
                exit;
            }
        }
        return self::$instance;
    }
}
