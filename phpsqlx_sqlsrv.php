<?php
/**
 * PhpSqlX by www.veershrivastav.com
 * 
 * File: phpsqlx_sqlsrv
 * 
 * Author: Aayush Sahay
 * Date: July 17, 2015, 02:07:17 AM
 *
 */

require_once('PhpSqlX.php');

class phpsqlx_sqlsrv implements PhpSqlX {

    private $hostname = '';
    private $dbname = '';
    private $user = '';
    private $password = '';
    private static $error = null;
    
    //Connection of this class
    private $connection = null;
    private $connInfo = array();
    
    //Table columns
    private $tablecolumns = null;
    private $tablecolumndetails = null;
    
    //transaction flag
    private $transaction = false;
    
    //Instance of Page for Singlton Use
    private static $instance = null;

    /*     * * CONSTRUCTOR ** */
    private function __construct($hostname, $username, $password, $dbname) {
        $this->hostname = $hostname;
        $this->user = $username;
        $this->password = $password;
        $this->dbname = $dbname;
     
        $this->connInfo["Database"] = $dbname;
        
        if($username !== null && $password !== null)
        {
            $this->connInfo["UID"] = $username;
            $this->connInfo["PWD"] = $password;
        }
        
        $this->connect();
    }

    private function connect() {
        $this->connection = sqlsrv_connect($this->hostname,$this->connInfo);
        
        if(!($this->connection)) {
            //trigger_error("Incorrect Connection Parameter",E_USER_WARNING);
            self::$error = true;
        }
    }
    
    /*     * * CREATE COLUMN BUILDER ** */
    private function create_column_builder($columns) {
        $primaryset = false;
        $columnsqlarr = array();
        foreach ($columns as $column) {
            if (!(gettype($column) == 'object')) {
                trigger_error("Object exepected for individual column, " . gettype($column) . " passed", E_USER_ERROR);
                return false;
            }

            if (!(isset($column->name) && isset($column->datatype))) {
                trigger_error("Undefined column name passed in one of the columns", E_USER_ERROR);
                return false;
            }

            $sql = " " . $column->name . " " . $column->datatype;

            if (isset($column->length)) {
                $sql .= "(" . $column->length . ") ";
            }

            if (isset($column->notnull)) {
                if ($column->notnull) {
                    $sql .= " NOT NULL ";
                }
            }

            if (isset($column->default)) {
                //$affinity = get_type($column->datatype);
                $sql .= " DEFAULT '" . $column->default . "' ";
            }

            if (isset($column->primary)) {
                if ($column->primary && !$primaryset) {
                    $sql .= " PRIMARY KEY";
                    $primaryset = true;
                }
            }

            if (isset($column->autoincrement)) {
                if ($column->autoincrement) {
                    $sql .= " AUTO_INCREMENT ";
                }
            }

            if (isset($column->unique)) {
                if ($column->unique) {
                    $sql .= " UNIQUE ";
                }
            }

            $columnsqlarr[] = $sql;
        }
        return implode(",", $columnsqlarr);
    }

    /*     * * CLAUSE BUILDER ** */
    private function clause_builder($clause, $glue, $checkTable = true) {
        $clausereturn = array();
        if (sizeof($clause) > 0) {
            foreach ($clause as $key => $value) {
                if (!(gettype($value) == 'array')) {
                    $value = addslashes($value);
                }

                if ($checkTable) {
                    if (in_array($key, $this->tablecolumns)) {
                        if (gettype($value) == 'array') {
                            $tempval = array();
                            $i = 0;
                            foreach ($value as $val) {
                                $tempval[] = "?";
                                $i++;
                            }
                            $clausereturn[] = " $key IN (" . implode(",", $tempval) . ")";
                        } else {
                            $clausereturn[] = " $key = ? ";
                        }
                    }
                } else {
                    if (gettype($value) == 'array') {
                        $tempval = array();
                        $i = 0;
                        foreach ($value as $val) {
                            $tempval[] = "?";
                            $i++;
                        }
                        $clausereturn[] = " $key IN (" . implode(",", $tempval) . ")";
                    } else {
                        $clausereturn[] = " $key = ? ";
                    }
                }
            }
            //implode to string and return
            return array(implode(" $glue ", $clausereturn));
        }
        return false;
    }

    /*     * * CREATE TABLE ** */
    public function create_table($tablename, $columnname, $flags = array()) {
        if (!(gettype($columnname) == 'array')) {
            trigger_error("Associative array exepected in column name, " . gettype($columnname) . " passed", E_USER_ERROR);
            return false;
        }

        if (!(gettype($flags) == 'array')) {
            trigger_error("Associative array exepected in flags, " . gettype($flags) . " passed", E_USER_ERROR);
            return false;
        }

        if (!(gettype($tablename) == 'string')) {
            trigger_error("string exepected as table name, " . gettype($tablename) . " passed", E_USER_ERROR);
            return false;
        }

        $sql = "CREATE ";
        
        //check if temporary table to be created
        if (array_key_exists("temp", $flags) || array_key_exists("temporary", $flags)) {
            if (isset($flags["temp"]) && $flags["temp"] == true) {
                $sql .= "TEMPORARY ";
            } else if (isset($flags["temporary"]) && $flags["temporary"] == true) {
                $sql .= "TEMPORARY ";
            }
        }
        
        //add TABLE key Word
        $sql .= "TABLE " . $tablename . " ";
/*
        if (array_key_exists("ifnot", $flags)) {
            if (isset($flags["ifnot"]) && $flags["ifnot"] == true) {
                $sql .= "IF NOT EXISTS ";
            }
        } else {
            $sql .= "IF NOT EXISTS ";
        }
*/
        if (!$column = $this->create_column_builder($columnname)) {
            return false;
        }

        $sql .= "(" . $column . ");";

        if($result = sqlsrv_query($this->connection, $sql)) {
            return true;
        }

        return false;
    }

    /* * * TABLE DESC * * */
    private function describe_table($tablename) {
        if (!(gettype($tablename) == 'string')) {
            trigger_error("tablename should be string", E_USER_ERROR);
            return false;
        }

        $this->tablecolumns = array();
        $this->tablecolumndetails = new stdClass();
        
        $q = "select * from information_schema.COLUMNS where TABLE_NAME='" . $tablename . "';";

        $stmt = sqlsrv_query($this->connection, $q);
        if(!$stmt)
        {
            trigger_error("table \"" . $tablename . "\" does not exist", E_USER_ERROR);
            return false;
        }

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            //Array to store column name of the table
            $this->tablecolumns[] = $row['COLUMN_NAME'];
            //Detailed descrption of the table.
            $this->tablecolumndetails->$row['COLUMN_NAME'] = new stdClass();
            $this->tablecolumndetails->$row['COLUMN_NAME']->type = $row['DATA_TYPE'];
            $this->tablecolumndetails->$row['COLUMN_NAME']->null = $row['IS_NULLABLE'];
            //$this->tablecolumndetails->$row['Column_name']->key = $row['pk'];
            $this->tablecolumndetails->$row['COLUMN_NAME']->default = $row['COLUMN_DEFAULT'];
        }

        return true;
    }

    /*     * * INSERT CLAUSE BUILDER ** */
    private function insert_clause_builder($data) {
        if (!(gettype($data) == 'object' || gettype($data) == 'array')) {
            trigger_error("Unsupported datatype for data passed to insert. Object or Array expected, " . gettype($data) . " passed", E_USER_ERROR);
            return false;
        }

        // check for size of data
        if (!(sizeof($data) > 0)) {
            return false;
        }

        $columnname = array();
        $question = array();
        foreach ($data as $key => $val) {
            if (in_array($key, $this->tablecolumns)) {
                $columnname[] = $key;
                $question[] = "?";
            }
        }

        return array("(" . implode(",", $columnname) . ")", "(" . implode(',', $question) . ")",);
    }

    /*     * * INSERT RECORDS** */
    public function insert_record($tablename, $data) {
        //Before any Query, first get the table description
        if (!$this->describe_table($tablename)) {
            return false;
        }

        $sql = "INSERT INTO " . $tablename . " ";

        $insertclause = $this->insert_clause_builder($data);

        $sql .= $insertclause[0] . " VALUES " . $insertclause[1] . "; SELECT SCOPE_IDENTITY();";

        $tempArray = array();
        foreach($data as $key => $value)
        {
            $tempArray[] = $value;
        }
        
        $stmt = sqlsrv_query($this->connection, $sql, $tempArray);

        if(!$stmt)
            return false;

        sqlsrv_next_result($stmt); 
        sqlsrv_fetch($stmt); 
        $newId = sqlsrv_get_field($stmt, 0);        

        var_dump($newId);
        if ($newId > 0)
            return $newId;
        else
            return true;
    }

    public function insert_records($tablename, $datas) {
        //Before any Query, first get the table description
        if (!$this->describe_table($tablename)) {
            return false;
        }

        if (!((gettype($datas) == 'array'))) {
            trigger_error("Unsupported datatype for datas. Array expected, " . gettype($datas) . " passed", E_USER_ERROR);
            return false;
        }

        if (sizeof($datas) < 1) {
            trigger_error("No values passed to insert query", E_USER_ERROR);
            return false;
        }

        $sql = "INSERT INTO " . $tablename . " ";

        //$tempdata = array_values($datas);
        $insertclause = $this->insert_clause_builder($datas[0]);

        $placeholder = array();
        $i = sizeof($datas);
        while ($i > 0) {
            $placeholder[] = $insertclause[1];
            $i--;
        }
        $insertclause[1] = implode(",", $placeholder);

        $sql .= $insertclause[0] . " VALUES " . $insertclause[1] . "; SELECT SCOPE_IDENTITY();";

        $tempArray = array();
        foreach($data as $key => $value)
        {
            $tempArray[] = &$value;
        }
        
        $stmt = sqlsrv_query($this->connection, $sql, $tempArray);
        
        if(!$stmt)
            return false;

        sqlsrv_next_result($stmt); 
        sqlsrv_fetch($stmt); 
        $newId = sqlsrv_get_field($stmt, 0);
        if ($newId > 0)
            return $newId;
        else
            return true;
    }

    /*     * * GET RECORDS ** */
    public function get_record($tablename, $columnname = '*', $where = NULL, $sort = NULL, $sortorder = 'ASC', $limitcount = NULL, $limitoffset = NULL) {
        if ($result = $this->get_records($tablename, $columnname, $where, $sort, $sortorder, $limitcount, $limitoffset)) {
            return $result[0];
        }
        return false;
    }

    public function get_records($tablename, $columnname = '*', $where = NULL, $sort = NULL, $sortorder = 'ASC', $limitcount = NULL, $limitoffset = NULL) {
        //Before any Query, first get the table description
        if (!$this->describe_table($tablename)) {
            return false;
        }

        //Build Column List
        if ($columnname !== '*') {
            if (!(gettype($columnname) == 'object' || gettype($columnname) == 'array' || gettype($columnname) == 'string')) {
                trigger_error("Unsupported datatype for column name. Object or Array or String expected, " . gettype($columnname) . " passed", E_USER_ERROR);
                return false;
            }

            if (!(gettype($columnname) == 'string')) {
                $colum = array();
                foreach ($columnname as $value) {
                    $colum[] = $value;
                }
                $columnname = implode(', ', $colum);
            }
        }

        //SQL Build
        $sql = "SELECT " . $columnname . " FROM " . $tablename . " ";

        if ($where) { //$where clause found
            if (!(gettype($where) == 'object' || gettype($where) == 'array')) {
                trigger_error("Unsupported datatype for where clause, object or array expected " . gettype($where) . " passed", E_USER_ERROR);
                return false;
            }

            if (!$whereclause = $this->clause_builder($where, 'AND')) {
                return false;
            }

            //Bind WHERE clause
            $sql .= ' WHERE ' . $whereclause[0];
        }

        if ($sort) { //sort by
            if (!(gettype($sort) == 'string')) {
                trigger_error("Unsupported datatype for order by clause, string expected " . gettype($sort) . " passed", E_USER_ERROR);
                return false;
            }
            $sql .= " ORDER BY " . $sort . " " . $sortorder;
        }
/*
        if ($limitcount && $limitoffset) {
            $sql .= " LIMIT " . $limitcount . " OFFSET " . $limitoffset;
        } elseif ($limitcount && !$limitoffset) {
            $sql .= " LIMIT " . $limitcount;
        }
*/
        $tempArray = array();
        
        if ($where) {
            foreach ($where as $key => $value) {
                if (!(gettype($value) == 'array')) {
                    $value = addslashes($value);
                    $tempArray[] = &$value;
                } else {
                    foreach ($value as $val) {
                        $val = addslashes($val);
                        $tempArray[] = &$value;
                    }
                }
            }
        }

        if ($limitcount && $limitoffset) {
            $a = $limitcount;
            $b = $limitcount + $limitoffset - 1;
            $sql = "SELECT * FROM (" .
                   "SELECT TOP " . $a . " * FROM (" .
                   "SELECT TOP " . $b . " * FROM (" . $sql .
                   ") ORDER BY 2 ASC" .
                   ") ORDER BY 2 DESC" .
                   ") ORDER BY 2 ASC";
        } elseif ($limitcount && !$limitoffset) {
            $sql = "SELECT TOP " . $limitcount . " * FROM (" . $sql . ")";
        }
        
        $stmt = sqlsrv_query($this->connection, $sql, $tempArray);
        
        if ($stmt) {
            if (sqlsrv_num_rows($stmt) == 0) {
                return false;
            } else {
                $returnresult = array();
                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $resultrow = new stdClass();
                    foreach ($row as $key => $val) {
                        $resultrow->$key = $val;
                    }
                    $returnresult[] = $resultrow;
                }
            }
            return $returnresult;
        } else {
            return false;
        }
    }

    public function get_record_sql($sql, $where = NULL, $sort = NULL, $sortorder = 'ASC', $limitcount = NULL, $limitoffset = NULL) {
        if ($result = $this->get_records_sql($sql, $where, $sort, $sortorder, $limitcount, $limitoffset)) {
            return $result[0];
        }
        return false;
    }

    public function get_records_sql($sql, $where = NULL, $sort = NULL, $sortorder = 'ASC', $limitcount = NULL, $limitoffset = NULL) {
        if ($where) { //$where clause found
            if (!(gettype($where) == 'object' || gettype($where) == 'array')) {
                trigger_error("Unsupported datatype for where clause, object or array expected " . gettype($where) . " passed", E_USER_ERROR);
                return false;
            }

            if (!$whereclause = $this->clause_builder($where, 'AND', false)) {
                return false;
            }

            //Bind WHERE clause
            $sql .= ' WHERE ' . $whereclause[0];
        }

        if ($sort) { //sort by
            if (!(gettype($sort) == 'string')) {
                trigger_error("Unsupported datatype for order by clause, string expected " . gettype($sort) . " passed", E_USER_ERROR);
                return false;
            }
            $sql .= " ORDER BY " . $sort . " " . $sortorder;
        }
/*
        if ($limitcount && $limitoffset) {
            $sql .= " LIMIT " . $limitcount . " OFFSET " . $limitoffset;
        } elseif ($limitcount && !$limitoffset) {
            $sql .= " LIMIT " . $limitcount;
        }
*/        
        $tempArray = array();
        
        if ($where) {
            foreach ($where as $key => $value) {
                if (!(gettype($value) == 'array')) {
                    $value = addslashes($value);
                    $tempArray[] = &$value;
                } else {
                    foreach ($value as $val) {
                        $val = addslashes($val);
                        $tempArray[] = &$value;
                    }
                }
            }
        }

        if ($limitcount && $limitoffset) {
            $a = $limitcount;
            $b = $limitcount + $limitoffset - 1;
            $sql = "SELECT * FROM (" .
                   "SELECT TOP " . $a . " * FROM (" .
                   "SELECT TOP " . $b . " * FROM (" . $sql .
                   ") ORDER BY 2 ASC" .
                   ") ORDER BY 2 DESC" .
                   ") ORDER BY 2 ASC";
        } elseif ($limitcount && !$limitoffset) {
            $sql = "SELECT TOP " . $limitcount . " * FROM (" . $sql . ")";
        }
        
        $stmt = sqlsrv_query($this->connection, $sql, $tempArray);
        
        if ($stmt) {
            if (sqlsrv_num_rows($stmt) == 0) {
                return false;
            } else {
                $returnresult = array();
                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $resultrow = new stdClass();
                    foreach ($row as $key => $val) {
                        $resultrow->$key = $val;
                    }
                    $returnresult[] = $resultrow;
                }
            }
            return $returnresult;
        } else {
            return false;
        }
    }

    /*     * * UPDATE RECORDS ** */
    public function update_records($tablename, $data = NULL, $where = NULL) {
        if (!$data) {
            trigger_error("DATA not provided for update operation", E_USER_ERROR);
            return false;
        }

        if (!$where) {
            trigger_error("WHERE clause not provided for update operation", E_USER_ERROR);
            return false;
        }

        if (!((gettype($data) == 'object' || gettype($data) == 'array') && (gettype($where) == 'object' || gettype($where) == 'array'))) {
            trigger_error("Unsupported datatype for DATA or WHERE", E_USER_ERROR);
            return false;
        }

        //Before any Query, first get the table description
        if (!$this->describe_table($tablename)) {
            return false;
        }

        //Get WHERE clause
        if (!$setdata = $this->clause_builder($data, ',')) {
            return false;
        }

        if (!$whereclause = $this->clause_builder($where, 'AND')) {
            return false;
        }

        $sql = "UPDATE " . $tablename . " SET " . $setdata[0] . " WHERE " . $whereclause[0];

        $tempArray = array();
        
        if ($data) {
            foreach ($data as $key => $value) {
                if (!(gettype($value) == 'array')) {
                    $value = addslashes($value);
                    $tempArray[] = &$value;
                } else {
                    foreach ($value as $val) {
                        $val = addslashes($val);
                        $tempArray[] = &$value;
                    }
                }
            }
        }
        
        if ($where) {
            foreach ($where as $key => $value) {
                if (!(gettype($value) == 'array')) {
                    $value = addslashes($value);
                    $tempArray[] = &$value;
                } else {
                    foreach ($value as $val) {
                        $val = addslashes($val);
                        $tempArray[] = &$value;
                    }
                }
            }
        }

        $stmt = sqlsrv_query($this->connection, $sql, $tempArray);
        
        if ($stmt) {
            return true;
        } else {
            return false;
        }
    }

    /*     * * DELETE RECORDS ** */
    public function delete_records($tablename, $where = NULL) {
        //Before any Query, first get the table description
        if (!$where) {
            trigger_error("WHERE clause not provided", E_USER_ERROR);
            return false;
        }

        if (!(gettype($where) == 'object' || gettype($where) == 'array')) {
            trigger_error("Unsupported datatype for DATA or WHERE", E_USER_ERROR);
            return false;
        }

        if (!$this->describe_table($tablename)) {
            return false;
        }

        if (!$clause = $this->clause_builder($where, 'AND')) {
            return false;
        }

        $sql = "DELETE FROM " . $tablename . " WHERE " . $clause[0];

        $tempArray = array();
        
        if ($where) {
            foreach ($where as $key => $value) {
                if (!(gettype($value) == 'array')) {
                    $value = addslashes($value);
                    $tempArray[] = &$value;
                } else {
                    foreach ($value as $val) {
                        $val = addslashes($val);
                        $tempArray[] = &$value;
                    }
                }
            }
        }

        $stmt = sqlsrv_query($this->connection, $sql, $tempArray);
        
        if ($stmt) {
            return true;
        } else {
            return false;
        }
    }

    /*     * * RAW EXECUTE SQL ** */
    public function raw_execute_sql($sql, $param = NULL) {
        if (isset($param)) {
            if (!(gettype($param) == 'object' || gettype($param) == 'array')) {
                trigger_error("Unsupported datatype for PARAM", E_USER_ERROR);
                return false;
            }

            if (!$clause = $this->clause_builder($param, 'AND', false)) {
                return false;
            }
        }

        $tempArray = array();
        
        if (isset($param)) {
            if ($param) {
                foreach ($param as $key => $value) {
                    if (!(gettype($value) == 'array')) {
                        $value = addslashes($value);
                        $tempArray[] = &$value;
                    } else {
                        foreach ($value as $val) {
                            $val = addslashes($val);
                            $tempArray[] = &$value;
                        }
                    }
                }
            }
        }
        
        $stmt = sqlsrv_query($this->connection, $sql, $tempArray);
        
        if ($stmt) {
            return true;
        } else {
            return false;
        }
    }

    /*     * * TRANSACTION ** */
    public function begin_transaction() {
        if (!$this->transaction) {
            $stmt = sqlsrv_query($this->connection, "BEGIN TRANSACTION;");
            $this->transaction = true;
        }
        return true;
    }

    public function rollback_transaction() {
        $stmt = sqlsrv_query($this->connection, "ROLLBACK TRANSACTION;");
        $this->transaction = false;
    }

    public function complete_transaction() {
        $stmt = sqlsrv_query($this->connection, "COMMIT TRANSACTION;");
        $this->transaction = false;
    }

    /*     * * GETINSTANCE ** */
    public static function getInstance($params) {
        $hostname = null;
        $username=null;
        $password=null;
        $dbname = '';

        if (!(gettype($params) == 'array')) {
            trigger_error("Associative array exepected in param, " . gettype($params) . " passed", E_USER_ERROR);
            return false;
        }

        foreach ($params as $param => $val) {
            $$param = $val;
        }

        if(!isset(self::$instance)) {
            self::$instance = new sqlsrv_db_connect($hostname, $username, $password, $dbname);
            if (self::$error) {
                self::$instance = false;
            }
        }
        return self::$instance;
    }

    /*     * * GET CREDENTIALS ** */
    public function getCredentials() {
        return array(
            "hostname"=>$this->hostname,
            "dbname"=>  $this->dbname,
            "username"=> $this->user,
            "password"=> $this->password,
            );
    }

}

?>