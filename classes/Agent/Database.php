<?php
namespace Agent;

class Database
{
    private static $connection;
    private static $statementConnection;
    private static $statements = [];

    public static function connection()
    {
        if (!self::$connection) {
            self::$connection = new \PDO(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASSWORD'), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
            ]);
        }
        return self::$connection;
    }

    public static function row($sql, array $params = []) { return self::query($sql, $params, 'row'); }
    public static function rows($sql, array $params = []) { return self::query($sql, $params, 'rows'); }
    public static function execute($sql, array $params = []) { return self::query($sql, $params, null); }

    /** Reuse prepared handles only; always execute against the current transaction and release the cursor. */
    private static function query($sql, array $params, $mode)
    {
        $db = self::connection();
        if (self::$statementConnection !== $db) {
            self::$statements = [];
            self::$statementConnection = $db;
        }
        $entry = isset(self::$statements[$sql]) ? self::$statements[$sql] : null;
        if (!$entry || $entry->busy) {
            $entry = (object)['statement' => $db->prepare($sql), 'busy' => false];
            // Nested use of the same SQL gets a private handle; active cursors are never displaced.
            if (!isset(self::$statements[$sql])) {
                if (count(self::$statements) >= 32) foreach (self::$statements as $key => $cached) {
                    if (!$cached->busy) { unset(self::$statements[$key]); break; }
                }
                if (count(self::$statements) < 32) self::$statements[$sql] = $entry;
            }
        }
        $entry->busy = true;
        try {
            $entry->statement->execute($params);
            if ($mode === 'row') return $entry->statement->fetch(\PDO::FETCH_ASSOC);
            if ($mode === 'rows') return $entry->statement->fetchAll(\PDO::FETCH_ASSOC);
            return $entry->statement->rowCount();
        } finally {
            try { $entry->statement->closeCursor(); }
            finally { $entry->busy = false; }
        }
    }
}
