<?php
/***********************************************************************************
*   This library provides interface for database connections
*   Only MySQL and PostreSQL databases are supported
*
*     Classes:
*    - dbConnection  - db connection (mysql or postgres)
*    - dbRecordSet    - record set
*
*    Global Variables (it is assumed that there are several global variables)
*     - $dbType - type of the db, can be mysql or postgres
*/

// -- Fix Using MySQLi Call To Undefined Function
function mysqli_field_name($result, $field_offset) {
    $properties = mysqli_fetch_field_direct($result, $field_offset);
    return is_object($properties) ? $properties->name : null;
}

function mysqli_field_len($result, $field_offset) {
    $properties = mysqli_fetch_field_direct($result, $field_offset);
    return is_object($properties) ? $properties->length : null;
}

function mysqli_field_flags($result, $field_offset) {
    static $flags;
    $flags_num = mysqli_fetch_field_direct($result, $field_offset)->flags;
    if (!isset($flags)) {
        $flags = array();
        $constants = get_defined_constants(true);
        foreach ($constants['mysqli'] as $c => $n) if (preg_match('/MYSQLI_(.*)_FLAG$/', $c, $m)) if (!array_key_exists($n, $flags)) $flags[$n] = $m[1];
    }
    $result = array();
    foreach ($flags as $n => $t) if ($flags_num & $n) $result[] = $t;
    $return = implode(' ', $result);
    $return = str_replace('PRI_KEY','PRIMARY_KEY', $return);
    $return = strtolower($return);
    return $return;
}

function mysqli_field_type($result, $field_offset) {
    static $types;
    $type_id = mysqli_fetch_field_direct($result, $field_offset)->type;
    if (!isset($types)) {
        $types = array();
        $constants = get_defined_constants(true);
        foreach ($constants['mysqli'] as $c => $n) if (preg_match('/^MYSQLI_TYPE_(.*)/', $c, $m)) $types[$n] = $m[1];
    }
     return array_key_exists($type_id, $types) ? $types[$type_id] : NULL;
}
// -- End -- Fix Using MySQLi Call To Undefined Function -- By kn007

class dbConnection {
    public $dbConn    = null;
    public $debug     = false;
    public $dbType;
    public $dbVersion;
    public $dbName;
    public $res_sql;
    public $res_data;
    public $res_errMsg;
    public $res_affectedRows;
    public $res_rowCount;
    public $res_fieldCount;
    public $res_fields;
    public $res_fieldsInfo;
    
    // -- Constructor
    function __construct($dbType) {
        $dbType = strtolower($dbType);
        if ($dbType != 'postgres' && $dbType != 'mysql') {
            die('<b>ERROR:</b> Only two database types are supported, postgres and mysql... <b>w2db.php, line ' . __LINE__ . '</b>');
        }
        $this->dbType = $dbType;
    }
    
    // -- Clean up
    function __destruct() {
        if ($this->dbConn == null) return;
        if ($this->dbType == 'postgres') { @pg_close($this->dbConn); }
        if ($this->dbType == 'mysql') { @mysqli_close($this->dbConn); }
    }
    
    // -- Connect to the db
    public function connect($dbIP, $dbUser, $dbPass, $dbName, $dbPort = null) {        
        // check parameters
        if ($dbIP   == '') die('<b>ERROR:</b> no database host provided... <b>w2db.php, line ' . __LINE__ . '</b>');
        if ($dbName == '') die('<b>ERROR:</b> no database name provided... <b>w2db.php, line ' . __LINE__ . '</b>');
        if ($dbUser == '') die('<b>ERROR:</b> no database user provided... <b>w2db.php, line ' . __LINE__ . '</b>');
        //if ($dbPass == '') die('no database password provided');
        $this->dbName = $dbName;
        
        // connect
        if ($this->dbType == 'postgres') {
            $this->dbConn = pg_connect("host=$dbIP ".($dbPort != null ? "port=$dbPort " : "")."dbname=$dbName user=$dbUser password=$dbPass");
            if (!$this->dbConn) {
                $this->dbConn = null;
                print("<b>ERROR:</b> Cannot connect to postgres.<br>");
                return false;
            }
            $this->dbVersion = pg_version($this->dbConn);
            $this->dbVersion['host'] = pg_host($this->dbConn);
        }
        if ($this->dbType == 'mysql') {
            $this->dbConn = mysqli_connect($dbIP.($dbPort != null ? ":".$dbPort : ""), $dbUser, $dbPass, $dbName);
            if (!$this->dbConn) {
                $this->dbConn = null;
                print("<b>ERROR:</b> Cannot connect to mysql.<br>");
                return false;
            }
			mysqli_query($this->dbConn, "set names utf8");
            $this->dbVersion = Array();
            $this->dbVersion['client']   = mysqli_get_client_info($this->dbConn);
            $this->dbVersion['protocol'] = mysqli_get_proto_info($this->dbConn);
            $this->dbVersion['server']   = mysqli_get_server_info($this->dbConn);
            $this->dbVersion['host']     = mysqli_get_host_info($this->dbConn);
        }
    }    
	
    // -- Execute SQL
    public function execute($sql) {
        // hide errors
        $ini_err = ini_get('display_errors');
        ini_set('display_errors', 0);
        $res = false;
        
        $this->res_errMsg = null;
        
        // --- process sql
        if ($this->dbType == 'postgres') {
            $this->res_data = pg_query($this->dbConn, $sql);
            if (!$this->res_data) {
                $this->res_errMsg       = pg_last_error($this->dbConn);
            } else {
                $this->res_errMsg       = pg_result_error($this->res_data);
                $this->res_affectedRows = pg_affected_rows($this->res_data);
                $this->res_rowCount     = pg_num_rows($this->res_data);
                $this->res_fieldCount   = pg_num_fields($this->res_data);
                $res = new dbRecordSet($this->dbType, $this->res_data, $this->res_rowCount, $this->res_fieldCount);
                // -- parse field names
                for ($i=0; $i<$this->res_fieldCount; $i++) {
                    $this->res_fields[$i] = pg_field_name($this->res_data, $i);
                    $this->res_fieldsInfo[$i] = Array();
                    $this->res_fieldsInfo[$i]['type']    = pg_field_type($this->res_data, $i);
                    $this->res_fieldsInfo[$i]['len']     = pg_field_size($this->res_data, $i);
                    $this->res_fieldsInfo[$i]['is_null'] = pg_field_is_null($this->res_data, $i);
                    $this->res_fieldsInfo[$i]['prt_len'] = pg_field_prtlen($this->res_data, $i);
                }
            }
            // log error
            if ($this->res_errMsg != '') {
                // put here code to log error
            }
        }
        
        // --- mysql
        if ($this->dbType == 'mysql') {
            $this->res_data = mysqli_query($this->dbConn, $sql);
            if (!$this->res_data) {
                $this->res_errMsg         = mysqli_error($this->dbConn);
            } else {
                @$this->res_errMsg          = mysqli_error($this->res_data);
                @$this->res_affectedRows = mysqli_affected_rows($this->res_data);
                @$this->res_rowCount     = mysqli_num_rows($this->res_data);
                @$this->res_fieldCount     = mysqli_num_fields($this->res_data);
                @$res = new dbRecordSet($this->dbType, $this->res_data, $this->res_rowCount, $this->res_fieldCount);
                // -- parse field names
                for ($i=0; $i<$this->res_fieldCount; $i++) {
                    $this->res_fields[$i] = mysqli_field_name($this->res_data, $i);
                    $this->res_fieldsInfo[$i] = Array();
                    $this->res_fieldsInfo[$i]['type']  = mysqli_field_type($this->res_data, $i);
                    $this->res_fieldsInfo[$i]['len']   = mysqli_field_len($this->res_data, $i);
                    $this->res_fieldsInfo[$i]['flags'] = mysqli_field_flags($this->res_data, $i);
                }
            }
            // log error
            if ($this->res_errMsg != '') {
                // put here code to log error
            }
        }
        
        $this->res_sql = $sql;

        // show debug info if on
        if ($this->debug == true) {
            print("<pre>".$sql."<hr>");
            if ($this->res_errMsg != '') print("<span style='color: red'>".$this->res_errMsg."</span><hr>");
            print("</pre>");
        }
        // restore errors
        ini_set('display_errors', $ini_err);
        
        return $res;
    }
    
    // -- Return all records as an Array
    public function getAllRecords($rs=null) {
        $ret = Array();
        if ($rs == null) $rs = $this->rs;
        while ($rs && !$rs->EOF) {
            $ret[] = $rs->fields;
            $rs->moveNext();
        }
        return $ret;
    }

    // gets correct date_format for 

    public function dbFieldToDate ($field) {
        if ($this->dbType == 'mysql') {
            return "DATE_FORMAT(".$field.", '%m/%d/%Y')";
        }
        if ($this->dbType == 'postgres') {
            return "TO_CHAR(".$field.", 'mm/dd/yyyy')";
        }
    } 

    public function dbFieldToTime ($field) {
        if ($this->dbType == 'mysql') {
            return "DATE_FORMAT(".$field.", '%h:%i %p')";
        }
        if ($this->dbType == 'postgres') {
            return "TO_CHAR(".$field.", 'hh:mi pm')";
        }
    } 

    public function dbFieldToDateTime ($field) {
        if ($this->dbType == 'mysql') {
            return "DATE_FORMAT(".$field.", '%m/%d/%Y %h:%i %p')";
        }
        if ($this->dbType == 'postgres') {
            return "TO_CHAR(".$field.", 'mm/dd/yyyy hh:mi pm')";
        }
    } 

}

// =============================================
// ----- Record Set class

class dbRecordSet {
    public $dbType;
    public $data;
    public $rowCount;
    public $fieldCount;
    public $EOF;
    public $fields;
    public $current;
    
    function __construct($dbType, $res, $rowCount, $fieldCount) {
        $this->dbType         = $dbType;
        $this->data         = $res;
        $this->rowCount        = $rowCount;
        $this->fieldCount    = $fieldCount;
        if ($rowCount == 0) {
            $this->EOF = true; 
        } else {
            $this->EOF = false;        
            $this->moveFirst();
        }
    }
    
    function __destruct() {
        if ($this->dbType == 'postgres') @pg_free_result($this->data);
        if ($this->dbType == 'mysql') @mysqli_free_result($this->data);
    }
    
    public function moveFirst() {
        if ($this->dbType == 'postgres') {
            if ($this->rowCount == 0) return;
            $this->current = 0;
            $this->fields = pg_fetch_array($this->data, 0);
        }
        if ($this->dbType == 'mysql') {
            if ($this->rowCount == 0) return;
            $this->current = 0;
            mysqli_data_seek($this->data, $this->current);
            $this->fields = mysqli_fetch_array($this->data, MYSQLI_BOTH);
        }
    }
    
    public function moveLast() {
        if ($this->dbType == 'postgres') {
            $this->current = $this->rowCount -1;
            $this->fields = pg_fetch_array($this->data, $this->current);
        }
        if ($this->dbType == 'mysql') {
            $this->current = $this->rowCount -1;
            mysqli_data_seek($this->data, $this->current);
            $this->fields = mysqli_fetch_array($this->data);
        }
    }
    
    public function moveNext() {
        if ($this->dbType == 'postgres') {
            if ($this->EOF) return;
            $this->current++;
            if ($this->current >= $this->rowCount) { $this->EOF = true; $this->fields = Array(); return; }
            $this->fields = pg_fetch_array($this->data, $this->current);
        }
        if ($this->dbType == 'mysql') {
            if ($this->EOF) return;
            $this->current++;
            if ($this->current >= $this->rowCount) { $this->EOF = true; $this->fields = Array(); return; }
            mysqli_data_seek($this->data, $this->current);
            $this->fields = mysqli_fetch_array($this->data);
        }
    }
    
    public function movePrevious() {
        if ($this->dbType == 'postgres') {
            if ($this->current == 0) { return; }
            $this->current--;
            $this->fields = pg_fetch_array($this->data, $this->current);
        }
        if ($this->dbType == 'mysql') {
            if ($this->current == 0) { return; }
            $this->current--;
            mysqli_data_seek($this->data, $this->current);
            $this->fields = mysqli_fetch_array($this->data);
        }
    }
}

