<?php

/*
+---------------------------------------------------------------------------+
| Revive Adserver                                                           |
| http://www.revive-adserver.com                                            |
|                                                                           |
| Copyright: See the COPYRIGHT.txt file.                                    |
| License: GPLv2 or later, see the LICENSE.txt file.                        |
+---------------------------------------------------------------------------+
*/

/**
 * The pgsql data access layer code the delivery engine.
 *
 * @package    OpenXDal
 * @subpackage Delivery
 */

/**
 * The function to open a database connection, or return the resource if already open
 *
 * @param string $database The name of the database config section to use. If not found, it falls back to 'database'
 *
 * @return Pgsql\Connection|false
 */
function OA_Dal_Delivery_connect($database = 'database')
{
    $defaultConnection = 'database' === $database || 'rawDatabase' === $database;

    // If a connection already exists, then return that
    if ($defaultConnection && isset($GLOBALS['_MAX']['ADMIN_DB_LINK']) && $GLOBALS['_MAX']['ADMIN_DB_LINK'] instanceof \Pgsql\Connection) {
        return $GLOBALS['_MAX']['ADMIN_DB_LINK'];
    }

    // No connection exists, so create one
    $conf = $GLOBALS['_MAX']['CONF'];
    $dbConf = empty($conf[$database]) ? $conf['database'] : $conf[$database];
    $dbParams = [];

    if ($dbConf['protocol'] == 'unix') {
        $dbConf['host'] = $dbConf['socket'];
    } else {
        $dbConf['port'] ??= 5432;
    }

    $dbParams[] = empty($dbConf['port']) ? '' : 'port=' . $dbConf['port'];
    $dbParams[] = empty($dbConf['host']) ? '' : 'host=' . $dbConf['host'];
    $dbParams[] = empty($dbConf['username']) ? '' : 'user=' . $dbConf['username'];
    $dbParams[] = empty($dbConf['password']) ? '' : 'password=' . $dbConf['password'];
    $dbParams[] = empty($dbConf['name']) ? '' : 'dbname=' . $dbConf['name'];
    $dbLink = $dbConf['persistent'] ? @pg_pconnect(implode(' ', $dbParams)) : @pg_connect(implode(' ', $dbParams));

    if (!$dbLink) {
        $err = error_get_last();
        OX_Delivery_logMessage('DB connection error: ' . $err['message'], 4);

        return false;
    }

    if (!empty($conf['databasePgsql']['schema'])) {
        @pg_query($dbLink, "SET search_path='{$conf['databasePgsql']['schema']}'");
    }

    if (!empty($conf['databaseCharset']['checkComplete']) && !empty($conf['databaseCharset']['clientCharset'])) {
        @pg_client_encoding($dbLink);
    }

    if ($defaultConnection) {
        $GLOBALS['_MAX']['ADMIN_DB_LINK'] = $GLOBALS['_MAX']['RAW_DB_LINK'] = $dbLink;
    }

    return $dbLink;
}

/**
 * The function to pass a query to a database link
 *
 * @param string $query    The SQL query to execute
 * @param string $database The database to use for this query
 *                         (Must match the database section name in the conf file)
 * @return resource|false  The PgSQL resource if the query suceeded
 *                          or false on failure
 */
function OA_Dal_Delivery_query($query, $database = 'database')
{
    $dbLink = OA_Dal_Delivery_getDbLink($database);

    if (!$dbLink instanceof \Pgsql\Connection) {
        return false;
    }

    $result = @pg_query($dbLink, $query);

    if (!$result) {
        OX_Delivery_logMessage('DB query error: ' . pg_last_error($dbLink), 4);
        OX_Delivery_logMessage(' - failing query: ' . $query, 5);
    }

    return $result;
}

/**
 * The function to fetch a result from a database resource
 *
 * @param resource  The PgSQL resource
 * @return array
 */
function OA_Dal_Delivery_fetchAssoc($resource)
{
    return pg_fetch_assoc($resource);
}

/**
 * The function to retrieve the last-insert-id from the database
 *
 * @param string $database The name of the database config to use
 *                         (Must match the database section name in the conf file)
 * @param string $table    The name of the table we need to get the ID from
 * @param string $column   The name of the column we need to get the ID from
 * @return int|false       The last insert ID (zero if last query didn't generate an ID)
 *                         or false on failure
 */
function OA_Dal_Delivery_insertId($database, $table, $column)
{
    $dbLink = OA_Dal_Delivery_getDbLink($database);

    if (!$dbLink instanceof \Pgsql\Connection) {
        return false;
    }

    $seqName = substr($column, 0, 29) . '_seq';
    $seqName = substr($table, 0, 62 - strlen($seqName)) . '_' . $seqName;
    $query = "SELECT currval('\"" . pg_escape_string($dbLink, $seqName) . "\"')";

    return pg_fetch_result(pg_query($dbLink, $query), 0, 0);
}

function OA_Dal_Delivery_numRows($result)
{
    return pg_num_rows($result);
}

function OA_Dal_Delivery_result($result, $row_number, $field_name)
{
    return pg_fetch_result($result, $row_number, $field_name);
}

function OX_escapeString($string)
{
    $dbLink = OA_Dal_Delivery_getDbLink('rawDatabase');

    if (!$dbLink instanceof \Pgsql\Connection) {
        return false;
    }

    return pg_escape_string($dbLink, $string);
}

function OX_escapeIdentifier($string)
{
    $dbLink = OA_Dal_Delivery_getDbLink('rawDatabase');

    if (!$dbLink instanceof \Pgsql\Connection) {
        return false;
    }

    return pg_escape_identifier($dbLink, $string);
}

function OX_unescapeBlob($blob)
{
    return pg_unescape_bytea($blob);
}

function OX_Dal_Delivery_regex($column, $regexp)
{
    return "(CASE WHEN {$column} ~* E'{$regexp}' THEN 1 ELSE 0 END)";
}

function OX_bucket_updateTable($tableName, $aQuery, $increment = true, $counter = 'count')
{
    OA_Dal_Delivery_connect('rawDatabase');

    $prefix = $GLOBALS['_MAX']['CONF']['table']['prefix'];
    $query = OX_bucket_prepareUpdateQuery($prefix . $tableName, $aQuery, $increment, $counter);
    return OA_Dal_Delivery_query(
        $query,
        'rawDatabase',
    );
}

function OX_bucket_prepareUpdateQuery($tableName, $aQuery, $increment = true, $counter = 'count')
{
    $aQuery[$counter] = $increment ? 1 : -1;

    $qCounter = OX_escapeIdentifier($counter);

    $aFields = array_map('OX_escapeIdentifier', array_keys($aQuery));
    $aValues = OX_bucket_quoteArgs($aQuery);
    $aConflict = array_diff($aFields, [$qCounter]);

    return "INSERT INTO {$tableName} AS i
            (" . implode(', ', $aFields) . ")
            VALUES (" . implode(", ", $aValues) . ")
            ON CONFLICT (" . implode(', ', $aConflict) . ")
            DO UPDATE SET {$qCounter} = i.{$qCounter} + EXCLUDED.{$qCounter}";
}

function OX_bucket_quoteArgs($aArgs)
{
    $array = $aArgs;
    foreach ($array as &$value) {
        if (!is_int($value)) {
            $value = "'" . OX_escapeString($value) . "'";
        }
    }
    return $array;
}

function OA_Dal_Delivery_getKeywordCondition($operator, $keyword)
{
    // Escape properly
    $keyword = OX_escapeString(stripslashes($keyword));

    $p1 = "(' ' || d.keyword || ' ')";
    $p2 = "ILIKE '% $keyword %'";

    if ($operator == 'OR') {
        return "OR {$p1} {$p2} ";
    } elseif ($operator == 'AND') {
        return "AND {$p1} {$p2} ";
    } else {
        return "AND {$p1} NOT {$p2} ";
    }
}

function OA_Dal_Delivery_getDbLink(string $database = 'database'): ?\Pgsql\Connection
{
    // Connect to the database if necessary
    $dbName = ($database == 'rawDatabase') ? 'RAW_DB_LINK' : 'ADMIN_DB_LINK';

    if (!empty($GLOBALS['_MAX'][$dbName])) {
        return $GLOBALS['_MAX'][$dbName];
    }

    return OA_Dal_Delivery_connect($database) ?: null;
}
