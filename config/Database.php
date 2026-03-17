<?php
// config/Database.php
class Database {
    private static $pdo = null;
 
    public static function get(): PDO {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                "pgsql:host=aws-1-us-east-1.pooler.supabase.com;port=5432;dbname=postgres;sslmode=require",
                "postgres.gezldklnrxkwjpmfjhwz",
                "senati123$%",
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_PERSISTENT         => false,
                ]
            );
        }
        return self::$pdo;
    }
}