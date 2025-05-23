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
 * The mysqli data access layer code the delivery engine.
 *
 * @package    OpenXDal
 * @subpackage Delivery
 */

/**
 * The function to open a database connection, or return the resource if already open
 *
 * @param string $database The name of the database config section to use. If not found, it falls back to 'database'
 *
 * @return mysqli|false
 */
function OA_Dal_Delivery_connect($database = 'database')
{
    $defaultConnection = 'database' === $database || 'rawDatabase' === $database;

    // If a connection already exists, then return that
    if ($defaultConnection && isset($GLOBALS['_MAX']['ADMIN_DB_LINK']) && $GLOBALS['_MAX']['ADMIN_DB_LINK'] instanceof \mysqli) {
        return $GLOBALS['_MAX']['ADMIN_DB_LINK'];
    }

    // No connection exists, so create one
    $conf = $GLOBALS['_MAX']['CONF'];
    $dbConf = empty($conf[$database]) ? $conf['database'] : $conf[$database];

    $dbPersistent = empty($dbConf['persistent']) ? '' : 'p:';
    $dbHost = $dbConf['host'];
    $dbPort = $dbConf['port'] ?? 3306;
    $dbUser = $dbConf['username'];
    $dbPassword = $dbConf['password'];
    $dbName = $dbConf['name'];

    mysqli_report(MYSQLI_REPORT_OFF);

    if ($dbConf['protocol'] == 'unix' && !empty($dbConf['socket'])) {
        $dbLink = @mysqli_connect($dbPersistent . 'localhost', $dbUser, $dbPassword, $dbName, $dbPort, $dbConf['socket']);
    } elseif ($dbConf['ssl']) {
        $init = mysqli_init();
        mysqli_ssl_set(
            $init,
            null,
            null,
            empty($dbConf['ca']) ? null : $dbConf['ca'],
            empty($dbConf['capath']) ? null : $dbConf['capath'],
            null,
        );
        if ($dbLink = @mysqli_real_connect($init, $dbPersistent . $dbHost, $dbUser, $dbPassword, $dbName, $dbPort)) {
            // Connection successful (else: $dbLink == false)
            $dbLink = $init;
        }
    } else {
        $dbLink = @mysqli_connect($dbPersistent . $dbHost, $dbUser, $dbPassword, $dbName, $dbPort);
    }

    if (!$dbLink) {
        OX_Delivery_logMessage('DB connection error: ' . mysqli_connect_error(), 4);

        return false;
    }

    @mysqli_query($dbLink, "SET SESSION sql_mode=''");

    if (!empty($conf['databaseCharset']['checkComplete']) && !empty($conf['databaseCharset']['clientCharset'])) {
        @mysqli_query($dbLink, "SET NAMES '{$conf['databaseCharset']['clientCharset']}'");
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
 * @return resource|false  The MySQL resource if the query suceeded
 *                          or false on failure
 */
function OA_Dal_Delivery_query($query, $database = 'database')
{
    $dbLink = OA_Dal_Delivery_getDbLink($database);

    if (!$dbLink instanceof \mysqli) {
        return false;
    }

    $result = mysqli_query($dbLink, $query);

    if (!$result) {
        OX_Delivery_logMessage('DB query error: ' . mysqli_error($dbLink), 4);
        OX_Delivery_logMessage(' - failing query: ' . $query, 5);
    }

    return $result;
}

/**
 * The function to fetch a result from a database resource
 *
 * @param mysqli_result $resource The MySQL resource
 * @return array
 */
function OA_Dal_Delivery_fetchAssoc($resource)
{
    return mysqli_fetch_assoc($resource);
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
function OA_Dal_Delivery_insertId($database = 'database', $table = '', $column = '')
{
    $dbLink = OA_Dal_Delivery_getDbLink($database);

    if (!$dbLink instanceof \mysqli) {
        return false;
    }

    return mysqli_insert_id($dbLink);
}

function OA_Dal_Delivery_numRows($result)
{
    return mysqli_num_rows($result);
}

/**
 * @param mysqli_result $result
 * @param $row_number
 * @param $field_name
 *
 * @return mixed
 */
function OA_Dal_Delivery_result($result, $row_number, $field_name)
{
    $result->data_seek($row_number);
    $datarow = $result->fetch_array();
    return $datarow[$field_name];
}

function OX_escapeString($string)
{
    $dbLink = OA_Dal_Delivery_getDbLink('rawDatabase');

    if (!$dbLink instanceof \mysqli) {
        return false;
    }

    return mysqli_real_escape_string($dbLink, $string);
}

function OX_unescapeBlob($blob)
{
    return $blob;
}

function OX_escapeIdentifier($string)
{
    return '`' . $string . '`';
}

function OX_Dal_Delivery_regex($column, $regexp)
{
    return $column . " REGEXP '" . $regexp . "'";
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
    $aQuery = array_map('OX_escapeString', $aQuery);
    $aQuery[$counter] = $increment ? 1 : -1;
    $query = "
        INSERT INTO {$tableName}
            (" . implode(', ', array_keys($aQuery)) . ")
            VALUES ('" . implode("', '", $aQuery) . "')
    ";
    return $query . " ON DUPLICATE KEY UPDATE $counter = $counter + {$aQuery[$counter]}";
}

function OA_Dal_Delivery_getKeywordCondition($operator, $keyword)
{
    // Escape properly
    $keyword = OX_escapeString(stripslashes($keyword));

    $p1 = "CONCAT(' ',d.keyword,' ')";
    $p2 = "LIKE '% $keyword %'";

    if ($operator == 'OR') {
        return "OR {$p1} {$p2} ";
    } elseif ($operator == 'AND') {
        return "AND {$p1} {$p2} ";
    } else {
        return "AND {$p1} NOT {$p2} ";
    }
}

function OA_Dal_Delivery_getDbLink(string $database = 'database'): ?\mysqli
{
    // Connect to the database if necessary
    $dbName = ($database == 'rawDatabase') ? 'RAW_DB_LINK' : 'ADMIN_DB_LINK';

    if (!empty($GLOBALS['_MAX'][$dbName])) {
        return $GLOBALS['_MAX'][$dbName];
    }

    return OA_Dal_Delivery_connect($database) ?: null;
}
