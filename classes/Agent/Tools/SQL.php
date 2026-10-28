<?php
namespace Agent\Tools;

/** Bounded PostgreSQL research against a separately authenticated, curated schema. */
class SQL
{
    /** List the curated engineering research relations available on the dedicated read-only connection. */
    public static function tables() { return \Agent\DatabaseResearch::tables(); }

    /**
     * Describe columns of one curated relation. Production schema metadata is available through schema_catalog; production rows are not.
     * @param string $table Relation returned by tables, optionally prefixed engineering_research.
     */
    public static function describe(string $table) { return \Agent\DatabaseResearch::describe($table); }

    /**
     * Query one curated relation with SELECT, filters, grouping, ordering and safe aggregates. Use AS name for aliases. No joins, subqueries, CTEs, casts or quoted identifiers. Bind values with ? placeholders or :name placeholders; use ordinary single-quoted literals. Results are capped at 100 rows and 32 KiB, with a one-second statement timeout.
     * @param string $sql One SELECT over engineering_research (no trailing semicolon required)
     * @param array $params Scalar or null parameter values, positional list or named object
     * @param int $limit Maximum returned rows, clamped to 1..100
     */
    public static function query(string $sql, array $params = [], int $limit = 50) { return \Agent\DatabaseResearch::query($sql, $params, $limit); }

    /**
     * Inspect a bounded query plan for the same restricted SELECT grammar. EXPLAIN never uses ANALYZE and does not execute the query.
     * @param string $sql One SELECT over a curated engineering_research relation
     * @param array $params Scalar or null parameter values, positional list or named object
     */
    public static function explain(string $sql, array $params = []) { return \Agent\DatabaseResearch::query($sql, $params, 100, true); }
}
