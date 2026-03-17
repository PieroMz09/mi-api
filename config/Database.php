<?php
// config/Database.php
class Database {
    private static $pdo = null;
 
    public static function get(): PDO {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                "pgsql:host=db.gezldklnrxkwjpmfjhwz.supabase.co;port=6543;dbname=postgres;sslmode=require",
                "postgres",
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