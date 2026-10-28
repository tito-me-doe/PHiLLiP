<?php
namespace Agent;

/** Separate credentials plus a deliberately small SQL dialect; never expose the application PDO. */
class DatabaseResearch
{
    const SCHEMA = 'engineering_research';
    const RELATIONS = [
        'run_daily' => ['day', 'status', 'run_count', 'started_count', 'finished_count', 'mean_queue_seconds', 'mean_execution_seconds', 'max_execution_seconds'],
        'agent_summary' => ['depth', 'paused', 'agent_count', 'assigned_worker_count'],
        'model_summary' => ['status', 'supports_chat', 'supports_tools', 'supports_vision', 'supports_structured_output', 'supports_streaming', 'supports_embeddings', 'supports_rerank', 'supports_reasoning', 'supports_pdf', 'model_count'],
        'schema_catalog' => ['table_schema', 'table_name', 'column_name', 'ordinal_position', 'data_type', 'is_nullable']
    ];
    const FUNCTIONS = ['count', 'sum', 'avg', 'min', 'max', 'round', 'abs', 'ceil', 'ceiling', 'floor', 'coalesce', 'nullif', 'lower', 'upper', 'length', 'date_trunc', 'date_part', 'extract'];
    private static $connection;

    private static function local()
    {
        if (PHP_SAPI !== 'cli' || ToolContext::get('frontend')) throw new \RuntimeException('SQL research is only available to local CLI agents');
    }

    private static function connection()
    {
        self::local();
        if (self::$connection) return self::$connection;
        try { $config = Config::service('database_research'); }
        catch (\Exception $e) { throw new \RuntimeException('Database research is not configured; run php bin/phill-research-provision'); }
        $dsn = isset($config['dsn']) ? $config['dsn'] : getenv('DB_DSN');
        if (!is_string($dsn) || strpos($dsn, 'pgsql:') !== 0) throw new \RuntimeException('SQL research currently supports PostgreSQL PDO connections only');
        // PDO DSN user/password options could override the separate role credentials.
        if (preg_match('/(?:^|[;\s])(?:user|password|options)\s*=/i', substr($dsn, 6))) throw new \RuntimeException('Research DSN must contain connection transport only, without credentials or options');
        if (empty($config['user']) || empty($config['password'])) throw new \RuntimeException('Database research requires dedicated role credentials');
        try {
            $db = new \PDO($dsn, $config['user'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_EMULATE_PREPARES => false]);
            $db->exec("SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY");
            $db->exec("SET search_path = pg_catalog, engineering_research");
            $db->exec("SET standard_conforming_strings = on");
            $db->exec("SET statement_timeout = '1000ms'");
            $db->exec("SET lock_timeout = '100ms'");
            $db->exec("SET idle_in_transaction_session_timeout = '2000ms'");
            $role = $db->query('SELECT rolsuper, rolcreaterole, rolcreatedb, rolreplication, rolbypassrls FROM pg_catalog.pg_roles WHERE rolname = current_user')->fetch();
            foreach ($role ?: [] as $flag) if ($flag === true || $flag === 't' || $flag === 1) throw new \RuntimeException('Research role must have no administrative privileges');
            $memberships = $db->query('SELECT count(*) FROM pg_catalog.pg_auth_members WHERE member = (SELECT oid FROM pg_catalog.pg_roles WHERE rolname = current_user)')->fetchColumn();
            if ((int)$memberships !== 0) throw new \RuntimeException('Research role must not belong to other database roles');
            $unsafe = $db->query("SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname NOT IN ('pg_catalog', 'information_schema', 'engineering_research') AND c.relkind IN ('r','p','v','m','f') AND (pg_catalog.has_table_privilege(current_user,c.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER') OR pg_catalog.has_any_column_privilege(current_user,c.oid,'SELECT,INSERT,UPDATE,REFERENCES'))")->fetchColumn();
            if ((int)$unsafe !== 0) throw new \RuntimeException('Research role has access to non-research data; provisioning must repair its grants');
            $unsafe = $db->query("SELECT count(*) FROM pg_catalog.pg_class c JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'engineering_research' AND c.relkind IN ('r','p','v','m','f') AND (pg_catalog.has_table_privilege(current_user,c.oid,'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER') OR pg_catalog.has_any_column_privilege(current_user,c.oid,'INSERT,UPDATE,REFERENCES'))")->fetchColumn();
            if ((int)$unsafe !== 0) throw new \RuntimeException('Research role must not have mutation privileges on research relations');
            $unsafe = $db->query("SELECT pg_catalog.has_schema_privilege(current_user, 'engineering_research', 'CREATE') OR pg_catalog.has_schema_privilege(current_user, 'public', 'CREATE') OR pg_catalog.has_database_privilege(current_user, current_database(), 'CREATE')")->fetchColumn();
            if ($unsafe === true || $unsafe === 't' || $unsafe === 1) throw new \RuntimeException('Research role must not be allowed to create permanent database objects');
            self::$connection = $db;
        } catch (\PDOException $e) { throw new \RuntimeException('Unable to establish the restricted database research connection'); }
        return self::$connection;
    }

    public static function tables()
    {
        self::connection();
        $columns = [];
        foreach (self::RELATIONS as $name => $names) $columns[self::SCHEMA . '.' . $name] = $names;
        return ['schema' => self::SCHEMA, 'tables' => array_keys($columns), 'columns' => $columns, 'scope' => 'Live aggregate telemetry and operator-refreshed schema metadata snapshot only; no production records', 'dialect' => 'single-relation SELECT; explicit AS aliases; no joins, subqueries, CTEs, casts or quoted identifiers', 'functions' => self::FUNCTIONS];
    }

    public static function describe($table)
    {
        self::local();
        $table = strtolower(trim($table));
        if (strpos($table, self::SCHEMA . '.') === 0) $table = substr($table, strlen(self::SCHEMA) + 1);
        if (!isset(self::RELATIONS[$table])) throw new \InvalidArgumentException('Describe requires a relation returned by SQL::tables');
        $result = self::query('SELECT column_name, data_type, is_nullable FROM engineering_research.schema_catalog WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position', [self::SCHEMA, $table], 100);
        $result['table'] = self::SCHEMA . '.' . $table;
        return $result;
    }

    /** Lex comments and ordinary strings before applying the positive SQL grammar. */
    private static function tokens($sql)
    {
        if ($sql === '' || strlen($sql) > 8192 || strpos($sql, "\0") !== false || !preg_match('//u', $sql)) throw new \InvalidArgumentException('Research SQL requires 1..8192 bytes of UTF-8');
        $tokens = []; $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $ch = $sql[$i];
            if (ctype_space($ch)) { $i++; continue; }
            if (substr($sql, $i, 2) === '--') { $end = strpos($sql, "\n", $i + 2); $i = $end === false ? $length : $end + 1; continue; }
            if (substr($sql, $i, 2) === '/*') {
                $depth = 1; $i += 2;
                while ($i < $length && $depth) {
                    if (substr($sql, $i, 2) === '/*') { $depth++; $i += 2; }
                    elseif (substr($sql, $i, 2) === '*/') { $depth--; $i += 2; }
                    else $i++;
                }
                if ($depth) throw new \InvalidArgumentException('Unterminated SQL comment');
                continue;
            }
            if ($ch === "'") {
                $start = $i++; $closed = false;
                while ($i < $length) {
                    if ($sql[$i++] !== "'") continue;
                    if ($i < $length && $sql[$i] === "'") { $i++; continue; }
                    $closed = true; break;
                }
                if (!$closed) throw new \InvalidArgumentException('Unterminated SQL literal');
                $tokens[] = ['literal', substr($sql, $start, $i - $start)]; continue;
            }
            $tail = substr($sql, $i);
            if (preg_match('/\A:[a-zA-Z_][a-zA-Z0-9_]*/', $tail, $m)) { $tokens[] = ['parameter', $m[0]]; $i += strlen($m[0]); continue; }
            if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*/', $tail, $m)) { $tokens[] = ['word', strtolower($m[0])]; $i += strlen($m[0]); continue; }
            if (preg_match('/\A[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $tail, $m)) { $tokens[] = ['number', $m[0]]; $i += strlen($m[0]); continue; }
            if (preg_match('/\A(?:<=|>=|<>|!=)/', $tail, $m)) { $tokens[] = ['symbol', $m[0]]; $i += 2; continue; }
            if (strpos('(),.*+-/%=<>!?', $ch) !== false) { $tokens[] = [$ch === '?' ? 'parameter' : 'symbol', $ch]; $i++; continue; }
            // In particular: no dollar/E strings, semicolons, quoted identifiers, casts or custom operators.
            throw new \InvalidArgumentException('Unsupported SQL character; use one SELECT without semicolons, casts or quoted identifiers');
        }
        if (count($tokens) > 2048) throw new \InvalidArgumentException('Research SQL has too many tokens');
        return $tokens;
    }

    private static function validate($sql)
    {
        $tokens = self::tokens($sql);
        if (!$tokens || $tokens[0] !== ['word', 'select']) throw new \InvalidArgumentException('Research SQL accepts SELECT only');
        $selects = 0;
        foreach ($tokens as $i => $token) if ($token[0] === 'word') {
            if ($token[1] === 'select') $selects++;
        }
        // EXTRACT(epoch FROM day) has a FROM inside a function; track top-level relation separately.
        $depth = 0; $relationAt = null;
        foreach ($tokens as $i => $token) {
            if ($token[1] === '(' && $token[0] === 'symbol') $depth++;
            if ($token[1] === ')' && $token[0] === 'symbol') { $depth--; if ($depth < 0) throw new \InvalidArgumentException('Unbalanced SQL parentheses'); }
            if ($token === ['word', 'from'] && $depth === 0) {
                if ($relationAt !== null) throw new \InvalidArgumentException('Use exactly one research relation');
                $relationAt = $i + 1;
            }
        }
        if ($depth !== 0 || $selects !== 1 || $relationAt === null) throw new \InvalidArgumentException('Use one SELECT over one research relation, without subqueries');
        $index = $relationAt;
        if (isset($tokens[$index + 1]) && $tokens[$index + 1] === ['symbol', '.']) {
            if ($tokens[$index] !== ['word', self::SCHEMA]) throw new \InvalidArgumentException('Only engineering_research relations are allowed');
            $index += 2;
        }
        $table = isset($tokens[$index]) && $tokens[$index][0] === 'word' ? $tokens[$index][1] : '';
        if (!isset(self::RELATIONS[$table])) throw new \InvalidArgumentException('Use a relation returned by SQL::tables');
        $relationEnd = $index;
        // All other words must be known columns, small SQL vocabulary, or explicit AS aliases.
        $allowed = array_merge(self::RELATIONS[$table], self::FUNCTIONS, [$table, self::SCHEMA], explode(' ', 'select distinct from as where and or not null is true false in between like ilike group by having order asc desc nulls first last limit offset case when then else end interval date timestamp epoch year month day hour minute second'));
        $aliases = [];
        $reserved = explode(' ', 'into union intersect except with recursive insert update delete merge returning copy call do set reset grant revoke execute prepare deallocate create alter drop truncate lock vacuum analyze explain values table join cross inner left right full natural lateral on using window over filter for share of nowait skip locked fetch rows only collate operator variadic at time zone uescape authorization session_user current_user current_role current_catalog current_schema');
        foreach ($tokens as $i => $token) if ($token === ['word', 'as'] && isset($tokens[$i + 1]) && $tokens[$i + 1][0] === 'word') {
            $alias = $tokens[$i + 1][1];
            if (in_array($alias, $allowed, true) || in_array($alias, $reserved, true)) throw new \InvalidArgumentException('AS aliases must not shadow a column, function or SQL keyword');
            $aliases[] = $alias;
        }
        $allowed = array_merge($allowed, $aliases);
        foreach ($tokens as $i => $token) {
            if ($token[0] !== 'word') continue;
            if (!in_array($token[1], $allowed, true)) throw new \InvalidArgumentException('Unsupported SQL word: use described columns and safe SELECT expressions');
            if (isset($tokens[$i + 1]) && $tokens[$i + 1] === ['symbol', '(']) {
                if (!in_array($token[1], self::FUNCTIONS, true) && !in_array($token[1], ['in', 'not', 'and', 'or', 'where', 'having'], true)) throw new \InvalidArgumentException('SQL function is outside the research allowlist');
                if ($i && $tokens[$i - 1] === ['symbol', '.']) throw new \InvalidArgumentException('Schema-qualified SQL functions are not supported');
            }
        }
        // A comma after FROM could introduce an additional relation, even without a JOIN keyword.
        $depth = 0; $afterFrom = false; $afterClause = false;
        foreach ($tokens as $i => $token) {
            if ($i === $relationEnd) $afterFrom = true;
            if ($token[0] === 'symbol' && $token[1] === '(') $depth++;
            if ($token[0] === 'symbol' && $token[1] === ')') $depth--;
            if ($afterFrom && $depth === 0 && $token[0] === 'word' && in_array($token[1], ['where', 'group', 'having', 'order', 'limit', 'offset'], true)) $afterClause = true;
            if ($afterFrom && !$afterClause && $token === ['symbol', ',']) throw new \InvalidArgumentException('Research SQL supports one relation only');
        }
        // Rebuild tokens with spaces: comments cannot concatenate tokens into a different statement.
        return implode(' ', array_column($tokens, 1));
    }

    public static function query($sql, array $params = [], $limit = 50, $explain = false)
    {
        self::local();
        $sql = self::validate($sql); $limit = max(1, min(100, (int)$limit));
        if (count($params) > 100) throw new \InvalidArgumentException('Research queries support at most 100 bound parameters');
        $integerKeys = array_filter(array_keys($params), 'is_int');
        if ($integerKeys && (count($integerKeys) !== count($params) || array_keys($params) !== range(0, count($params) - 1))) throw new \InvalidArgumentException('Positional research parameters require a zero-based list; do not mix named and positional parameters');
        foreach ($params as $key => $value) {
            if ((!is_int($key) && !preg_match('/^:?[a-zA-Z_][a-zA-Z0-9_]*$/D', $key)) || (!is_scalar($value) && $value !== null) || (is_string($value) && (strlen($value) > 4096 || !preg_match('//u', $value)))) throw new \InvalidArgumentException('Research parameters must be scalar UTF-8 values of at most 4096 bytes');
        }
        $db = self::connection(); $started = microtime(true); $rows = []; $bytes = 0; $truncated = false;
        try {
            $db->beginTransaction();
            $db->exec("SET TRANSACTION READ ONLY");
            $db->exec("SET LOCAL statement_timeout = '1000ms'");
            $db->exec("SET LOCAL lock_timeout = '100ms'");
            $wrapped = 'SELECT * FROM (' . $sql . ') AS research_result LIMIT ' . ($limit + 1);
            $q = $db->prepare($explain ? 'EXPLAIN (FORMAT TEXT, COSTS TRUE) ' . $wrapped : $wrapped);
            foreach ($params as $key => $value) $q->bindValue(is_int($key) ? $key + 1 : ':' . ltrim($key, ':'), $value, $value === null ? \PDO::PARAM_NULL : (is_bool($value) ? \PDO::PARAM_BOOL : (is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR)));
            $q->execute();
            while (($row = $q->fetch()) !== false) {
                $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encoded === false) throw new \RuntimeException('Research result cannot be represented as UTF-8 JSON');
                if (count($rows) >= $limit || $bytes + strlen($encoded) > 28000) { $truncated = true; break; }
                $rows[] = $row; $bytes += strlen($encoded) + 1;
            }
            $q->closeCursor(); $db->rollBack();
        } catch (\PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            $code = $e->getCode();
            if ($code === '57014' || $code === '55P03') throw new \RuntimeException('Research query exceeded its statement or lock time limit');
            throw new \RuntimeException('Research query rejected by PostgreSQL (SQLSTATE ' . preg_replace('/[^A-Z0-9]/', '', (string)$code) . '); check described columns, parameter types and supported SELECT syntax');
        } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        return ['rows' => $rows, 'row_count' => count($rows), 'limit' => $limit, 'truncated' => $truncated, 'elapsed_ms' => round((microtime(true) - $started) * 1000, 2), 'scope' => self::SCHEMA, 'explain' => (bool)$explain];
    }
}
