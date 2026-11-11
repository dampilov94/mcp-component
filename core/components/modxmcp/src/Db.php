<?php
namespace ModxMCP;

/** Thin helper for the component's own tables, which are plain SQL rather than xPDO models. */
final class Db {
    /** Quoted, prefixed name of a component table, e.g. `modx_modxmcp_changes`. */
    public static function table(\xPDO $modx, $name) {
        return '`' . str_replace('`', '', (string) $modx->getOption('table_prefix', null, '')) . 'modxmcp_' . $name . '`';
    }

    /**
     * Prepare and execute. Throws DbException; its tableMissing flag tells the caller the
     * component tables have not been created yet (file-only deployment).
     * @return \PDOStatement
     */
    public static function run(\xPDO $modx, $sql, array $params = array()) {
        try {
            $statement = $modx->prepare($sql);
            if ($statement && $statement->execute($params)) { return $statement; }
            $info = $statement ? $statement->errorInfo() : array('', 0, 'prepare failed');
        } catch (\PDOException $e) {
            $info = array((string) $e->getCode(), 0, $e->getMessage());
        }
        $message = isset($info[2]) ? (string) $info[2] : '';
        $missing = (string) $info[0] === '42S02' || stripos($message, 'no such table') !== false;
        throw new DbException('Database query failed: ' . $message, $missing);
    }
}
