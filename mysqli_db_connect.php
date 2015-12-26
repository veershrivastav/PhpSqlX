<?php
/**
 * Quiz Management by www.veershubhranshu.com
 * 
 *  File: mysqli_db_connect
 * 
 * Author: Veer Shrivastav
 * Date: May 11, 2015, 12:44:34 AM
 *
 */

require_once 'DatabaseConnect.php';

define("MYSQLI_DB_ENGINE_INNODB", "InnoDB");
define("MYSQLI_DB_ENGINE_MRG_MYISAM", "MRG_MYISAM");
define("MYSQLI_DB_ENGINE_MYISAM", "MyISAM");
define("MYSQLI_DB_ENGINE_BLACKHOLE", "BLACKHOLE");
define("MYSQLI_DB_ENGINE_CSV", "CSV");
define("MYSQLI_DB_ENGINE_MEMORY", "MEMORY");
define("MYSQLI_DB_ENGINE_ARCHIVE", "ARCHIVE");

class mysqli_db_connect implements DatabaseConnect {
    
    private $hostname = '';
    private $dbname = '';
    private $user = '';
    private $password = '';
    private $port = 3306;
    private $socket = null;
    private static $error = null;
    
    //Connection of this class
    private $connection = null;
    
    //Table columns
    private $tablecolumns = null;
    private $tablecolumndetails = null;
    
    //transaction flag
    private $transaction = false;
    
    //Prepare statement
    private $statement = null;

    //Instance of Page for Singlton Use
    private static $instance = null;

    private function __construct($hostname, $username, $password, $dbname, $port, $socket) {
        $this->hostname = $hostname;
        $this->user = $username;
        $this->password = $password;
        $this->dbname = $dbname;
        $this->port = $port;
        $this->socket = $socket;
        
        if(!$hostname) {
            $this->hostname = ini_get("mysqli.default_host");
        }
        if(!$username) {
            $this->user = ini_get("mysqli.default_user");
        }
        if(!$password) {
            $this->password = ini_get("mysqli.default_pw");
        }
        if(!$port) {
            $this->port = ini_get("mysqli.default_port");
        }
        if(!$socket) {
            $this->socket = ini_get("mysqli.default_socket");
        }
        $this->connect();
    }

    private function connect() {
        $this->connection = new mysqli($this->hostname,$this->user,$this->password,$this->dbname, $this->port, $this->socket);
        
        if($this->connection->connect_errno) {
            //trigger_error("Incorrect Connection Parameter",E_USER_WARNING);
            self::$error = true;
        }
    }
    
    private function describe_table( $tablename) {
        
        if(!gettype($tablename) == 'string') {
            trigger_error("tablename should be string", E_USER_ERROR);
            return false;
        }
        
        //Set to Null before execution
        $this->tablecolumns = array();
        $this->tablecolumndetails = new stdClass();
        
        if (!($result = $this->connection->query("DESCRIBE $tablename"))) {
            return false;
        }
        while ($row = $result->fetch_assoc()) {
            //Array to store column name of the table
            $this->tablecolumns[] = $row['Field'];
            
            //Detailed descrption of the table.
            $this->tablecolumndetails->$row['Field'] = new stdClass();
            $this->tablecolumndetails->$row['Field']->type = $row['Type'];
            $this->tablecolumndetails->$row['Field']->null = $row['Null'];
            $this->tablecolumndetails->$row['Field']->key = $row['Key'];
            $this->tablecolumndetails->$row['Field']->default = $row['Default'];
            $this->tablecolumndetails->$row['Field']->extra = $row['Extra'];
        }
        return true;
    }
    
    private function clause_builder($clause, $glue = 'AND', $checkTable = true) {
        $clausereturn = array();
        $valuearray = array();
        if(sizeof($clause) > 0) {
            foreach ($clause as $key => $value) {
                if(!(gettype($value) == 'array')) {
                    $value = addslashes($value);
                }
                if($checkTable) {
                    if(in_array($key, $this->tablecolumns)) {
                        if(gettype($value) == 'array') {
                            $tempval = array ();
                            $i=0;
                            foreach ($value as $val) {
                                $tempval[] = "'$val'";
                            }
                            $clausereturn[] = " $key IN (".  implode(",", $tempval).")";
                        } else {
                            $clausereturn[] = " $key = '$value' ";
                        }
                    }
                } else {
                    if(gettype($value) == 'array') {
                        $tempval = array ();
                        $i=0;
                        foreach ($value as $val) {
                            $tempval[] = "'$val'";
                        }
                        $clausereturn[] = " $key IN (".  implode(",", $tempval).")";
                    } else {
                        $clausereturn[] = " $key = '$value' ";
                    }
                }
            }
            //implode to string and return
            return implode(" $glue ", $clausereturn);    
        }
        return false;
    }

    private function insert_clause_builder ($data) {
        if(!(gettype($data) == 'object' || gettype($data) == 'array')) {
            trigger_error("Unsupported datatype for data passed to insert. Object or Array expected, ".  gettype($data)." passed", E_USER_ERROR);
            return false;
        }
        
        // check for size of data
        if(!(sizeof($data) > 0)) {
            return false;
        }
        
        $columnname = array();
        $valuearray = array();
        foreach ($data as $key=>$val) {
            if(in_array($key, $this->tablecolumns)) {
                $columnname[] = $key;
                $valuearray[] = "'$val'";
            }
        }
        return array("(".implode(",", $columnname).")", "(".implode(',', $valuearray).")" );
    }

    private function create_column_builder($columns) {
        $primaryset = false;
        $columnsqlarr = array();
        foreach ($columns as $column) {
            if (!gettype($column) == 'object') {
                trigger_error("Object exepected for individual column, ".gettype($column)." passed", E_USER_ERROR);
                return false;
            }
            
            if (!(isset($column->name) && isset($column->datatype))) {
                trigger_error("Undefined column name passed in one of the columns", E_USER_ERROR);
                return false;
            }
            
            $sql = " $column->name $column->datatype";
            
            if(isset($column->length)) {
                $sql .= "($column->length) ";
            }
            
            if(isset($column->notnull)) {
                if($column->notnull) {
                    $sql .= " NOT NULL ";
                }
            }
            
            if(isset($column->default)) {
                $sql .= " DEFAULT '$column->default' ";
            }
            
            if(isset($column->autoincrement)) {
                if($column->autoincrement) {
                    $sql .= " AUTO_INCREMENT ";
                }
            }
            
            if(isset($column->unique)) {
                if($column->unique) {
                    $sql .= " UNIQUE ";
                }
            }
            
            if(isset($column->primary)) {
                if($column->primary && !$primaryset) {
                    $sql .= " PRIMARY KEY ";
                    $primaryset = true;
                }
            }
            $columnsqlarr[] = $sql;
        }
        return implode(",", $columnsqlarr);
    }
    
    public static function getInstance($params) {
        $hostname = null;
        $username=null;
        $password=null;
        $dbname = '';
        $port=null;
        $socket=null;
        
        if (!gettype($params) == 'array') {
            trigger_error("Associative array exepected in param, ".gettype($params)." passed", E_USER_ERROR);
            return false;
        }
        
        foreach ($params as $param => $val) {
            $$param = $val;
        }
        
        if(!isset(self::$instance)) {
            self::$instance = new mysqli_db_connect($hostname, $username, $password, $dbname, $port, $socket);
            if (self::$error) {
                self::$instance = false;
            }
        }
        return self::$instance;
    }
    
    public function create_table($tablename, $columnname, $flags = array()) {
        if (!gettype($columnname) == 'array') {
            trigger_error("Associative array exepected in column name, ".gettype($columnname)." passed", E_USER_ERROR);
            return false;
        }
        
        if (!gettype($flags) == 'array') {
            trigger_error("Associative array exepected in flags, ".gettype($flags)." passed", E_USER_ERROR);
            return false;
        }
        
        if (!gettype($tablename) == 'string') {
            trigger_error("string exepected as table name, ".gettype($tablename)." passed", E_USER_ERROR);
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
        $sql .= "TABLE ";
        
        //flag to check if table not exist
        if (array_key_exists("ifnot", $flags)) {
            if (isset($flags["ifnot"]) && $flags["ifnot"] == true) {
                $sql .= "IF NOT EXISTS ";
            }
        } else {
            //Good Practice
            $sql .= "IF NOT EXISTS ";
        }
        
        //Add table name
        $sql .= "$tablename ";
        
        if (!$column = $this->create_column_builder($columnname)) {
            return false;
        }
        
        $sql .= "($column) ";
        
        if (array_key_exists("engine", $flags)) {
                $sql .= " ENGINE='".$flags['engine']."' ";
        }
        
        if (array_key_exists("collation", $flags)) {
                $sql .= " COLLATE ".$flags['collation'];
        }
        
        if($result = $this->connection->query($sql)) {
            return true;
        }
        
        return false;
    }
    
    public function delete_records($tablename, $where = null) {
        //Before any Query, first get the table description
        if(!$where) {
            trigger_error("WHERE clause not provided", E_USER_ERROR);
            return false;
        }
        
        if(!(gettype($where) == 'object' || gettype($where) == 'array')) {
            trigger_error("Unsupported datatype for DATA or WHERE", E_USER_ERROR);
            return false;
        }
        
        if (!$this->describe_table($tablename)) {
            return false;
        }
        
        if(!$clause = $this->clause_builder($where, 'AND')) {
            return false;
        }
        
        if($this->connection->query("DELETE FROM $tablename WHERE ".$clause) === false) {
            error_reporting($this->connection->error, E_ERROR);
            return false;
        }
        
        return true;
    }
    
    public function get_records($tablename, $columnname = '*', $where = null, $sort = null, $sortorder='ASC', $limitcount=null, $limitoffset=null) {
        //Before any Query, first get the table description
        if(!$this->describe_table($tablename)){
            return false;
        }
        
        //Build Column List
        if(!$columnname == '*') {
            if(!(gettype($columnname) == 'object' || gettype($columnname) == 'array' || gettype($columnname) == 'string')) {
                //invalid data type for column name
                trigger_error("Unsupported datatype for column name. Object or Array or String expected, ".gettype($columnname)." passed", E_USER_ERROR);
                return false;
            }
            
            if (!gettype($columnname) == 'string') {
                $colum = array();
                foreach ($columnname as $value) {
                    $colum[] = $value;
                }
                $columnname = implode(', ', $colum);
            }
        }
        
        //SQL Build
        $sql = "SELECT $columnname FROM $tablename ";
        
        $swhere = null;
        $param = array();
        
        if($where) { //$where clause found
            if(!(gettype($where) == 'object' || gettype($where) == 'array')) {
                trigger_error("Unsupported datatype for where clause, object or array expected ".gettype($where)." passed", E_USER_ERROR);
                return false;
            }
            
            if(!$whereclause = $this->clause_builder($where, 'AND')) {
                return false;
            }
            
            //Bind WHERE clause
            $sql .= 'WHERE '.$whereclause;
        }
        
        if($sort) { //sort by
            if(!gettype($sort) == 'string') {
                trigger_error("Unsupported datatype for order by clause, string expected ".  gettype($sort)." passed", E_USER_ERROR);
                return false;
            }
            $sql .= " ORDER BY $sort $sortorder";
        }
        
        if($limitcount && $limitoffset) {
            $sql .= " LIMIT $limitoffset, $limitcount";            
        } else if($limitcount && !$limitoffset) {
            $sql .= " LIMIT $limitcount";
        }
        
        $returnresult = array();
        $result = $this->connection->query($sql);
        if ($result->num_rows == 0) {
            return false;
        } else {
            while ($row = $result->fetch_assoc()) {
                $resultrow = new stdClass();
                foreach ($row as $key=>$val) {
                    $resultrow->$key = $val;
                }
                $returnresult[] = $resultrow;
            }
        }
        return $returnresult;
    }

    public function get_record ($tablename, $columnname = '*', $where = null, $sort = null, $sortorder='ASC', $limitcount=null, $limitoffset=null) {
        if($result = $this->get_records($tablename,$columnname,$where,$sort,$sortorder,$limitcount,$limitoffset)) {
            return $result[0];
        }
        return false;
    }
    
    public function get_record_sql ($sql, $where=null, $sort=null, $sortorder='ASC', $limitcount=null, $limitoffset=null) {
        if($result = $this->get_records_sql($sql, $where, $sort, $sortorder, $limitcount, $limitoffset)) {
            return $result[0];
        }
        return false;
    }
    
    public function get_records_sql($sql, $where=null, $sort=null, $sortorder='ASC', $limitcount=null, $limitoffset=null) {
        
        if($where) { //$where clause found
            if(!(gettype($where) == 'object' || gettype($where) == 'array')) {
                trigger_error("Unsupported datatype for where clause, object or array expected ".gettype($where)." passed", E_USER_ERROR);
                return false;
            }
            
            if(!$whereclause = $this->clause_builder($where, 'AND', false)) {
                return false;
            }
            
            $sql .= " $whereclause ";
        }
        
        if($sort) { //sort by
            if(!gettype($sort) == 'string') {
                trigger_error("Unsupported datatype for order by clause, string expected ".  gettype($sort)." passed", E_USER_ERROR);
                return false;
            }
            $sql .= " ORDER BY $sort $sortorder";
        }
        
        if($limitcount && $limitoffset) {
            $sql .= " LIMIT $limitoffset, $limitcount";            
        } else if($limitcount && !$limitoffset) {
            $sql .= " LIMIT $limitcount";
        }
        
        $returnresult = array();
        $result = $this->connection->query($sql);
        if ($result->num_rows == 0) {
            return false;
        } else {
            while ($row = $result->fetch_assoc()) {
                $resultrow = new stdClass();
                foreach ($row as $key=>$val) {
                    $resultrow->$key = $val;
                }
                $returnresult[] = $resultrow;
            }
        }
        return $returnresult;
    }

    public function insert_record($tablename, $data) {
        //Before any Query, first get the table description
        if(!$this->describe_table($tablename)) {
            return false;
        }
        
        $sql = "INSERT INTO $tablename ";
        
        $insertclause = $this->insert_clause_builder($data);
        
        $sql .= $insertclause[0]." VALUES ".$insertclause[1];
        
        if($this->connection->query($sql) === false) {
            error_reporting($this->connection->error, E_ERROR);
            return false;
        } else {
            $r = $this->connection->insert_id;
            if ($r > 0) {
                return $r;
            }
            return true;
        }
        return false;
    }

    public function insert_records($tablename, $datas) {
        //Before any Query, first get the table description
        if(!$this->describe_table($tablename)) {
            return false;
        }
        if(!((gettype($datas) == 'array'))) {
            trigger_error("Unsupported datatype for datas. Array expected, ".gettype($datas)." passed", E_USER_ERROR);
            return false;
        }
        if(sizeof($datas) < 1) {
            trigger_error("No values passed to insert query", E_USER_ERROR);
            return false;
        }
        
        $cols = $this->insert_clause_builder($datas[0]);
        $param = array();
        $vals = array();
        $sql = "INSERT INTO $tablename ".$cols[0]." VALUES ";
        
        foreach ($datas as $data) {
            $cols = $this->insert_clause_builder($data);
            $vals[] = $cols[1];
        }
        
        $sql .= implode(',', $vals);
        
        if($this->connection->query($sql) === false) {
            error_reporting($this->connection->error, E_ERROR);
            return false;
        } else {
            $r = $this->connection->insert_id;
            if ($r > 0) {
                return $r;
            }
            return true;
        }
        return false;
    }

    public function update_records($tablename, $data = null, $where = null) {
        
        if(!$data) {
            trigger_error("DATA not provided for update operation", E_USER_ERROR);
            return false;
        }
        
        if(!$where) {
            trigger_error("WHERE clause not provided for update operation", E_USER_ERROR);
            return false;
        }
        
        if(!((gettype($data) == 'object' || gettype($data) == 'array') && (gettype($where) == 'object' || gettype($where) == 'array'))) {
            trigger_error("Unsupported datatype for DATA or WHERE", E_USER_ERROR);
            return false;
        }
        
        //Before any Query, first get the table description
        if(!$this->describe_table($tablename)) {
            return false;
        }
        
        //Get WHERE clause
        if(!$setdata = $this->clause_builder($data, ',')) {
            return false;
        }
        
        if(!$whereclause = $this->clause_builder($where, 'AND')) {
            return false;
        }
        
        if ($this->connection->query("UPDATE $tablename SET ".$setdata." WHERE ".$whereclause) === false) {
            error_reporting($this->connection->error, E_ERROR);
            return false;
        }
        
        return true;
    }
    
    public function raw_execute_sql ($sql, $param = false) {
        
        $paramArr = array();
        foreach($params as $para) {
            $paramArr[] = "'$para'";
        }
        
        if($param) {
            $sql = str_replace('?',$params,$sql);
        }
        
        if ($this->connection->query($sql) === false) {
            error_reporting($this->connection->error, E_ERROR);
            return false;
        }
        
        return true;
    }

    public function begin_transaction() {
        if (!$this->transaction) {
            $this->connection->autocommit(FALSE);
        }
        return true;
    }
    
    public function rollback_transaction() {
        $this->connection->rollback();
        $this->transaction = false;
        $this->connection->autocommit(TRUE);
    }
    
    public function complete_transaction () {
        $this->connection->commit();
        $this->transaction = false;
        $this->connection->autocommit(TRUE);
    }

    public function xml_install(SimpleXMLElement $xmlObj) {
        $root = strtolower($xmlObj->getName());
        $tables = array();
        //check if root node is database
        if($root != 'database') {
            trigger_error("XML Error. XML File must have root named as 'database'", E_USER_ERROR);
            return false;
        }
        
        //For each child of Root node
        foreach($xmlObj->children() as $table) {
            $tablenode = strtolower($table->getName());
            //Check if the child node is table
            if($tablenode != 'table') {
                trigger_error("XML Error. XML File must contain table as only child of 'database'", E_USER_ERROR);
                return false;
            }
            
            //Build attributes
            $tableattr = array();
            foreach ($table->attributes() as $attr=>$val) {
                $attr = strtolower($attr);
                $tableattr[$attr] = $val;
            }
            
            if(!array_key_exists('name',$tableattr)) {
                trigger_error("name of table not mentioned in the xml provided",E_USER_ERROR);
                return false;
            }
            
            $tablename = $table['name'];
            
            $columnArray = array();
            $flagsArray = array();
            
            //Prepare flags value.
            $flags = array('temp','ifnot','temporary','engine','collation');
            foreach($flags as $flag) {
                if(isset($table[$flag])) {
                    if(strtolower($table[$flag]) == "false") {
                        $flagsArray[$flag] = false;
                    } else if (strtolower($table[$flag]) == "true") {
                        $flagsArray[$flag] = true;
                    } else {
                        $flagsArray[$flag] = $table[$flag];
                    }
                }
            }
            
            //Prepare Columns
            foreach($table->children() as $column) {
                $columnFlags = array('name','datatype','length','notnull','default','autoincrement','unique','primary');
                $col = new stdClass();
                foreach($columnFlags as $columnFlag) {    
                    if(isset($column->$columnFlag)) {
                        if($column->$columnFlag == "false") {
                            $col->$columnFlag = false;
                        } else if ($column->$columnFlag == "true") {
                            $col->$columnFlag = true;
                        } else {
                            $col->$columnFlag = $column->$columnFlag."";
                        }
                    }       
                }
                $columnArray[] = $col;
            }
            $this->create_table($tablename,$columnArray,$flagsArray);
            $tables[] = $tablename;
        }
        return $tables;
    }
    
    /**
     * Complete Database details, like DBNAME, Connection, port, socket, password
     */
    public function getCredentials() {
        return array(
            "hostname"=>$this->hostname,
            "dbname"=>  $this->dbname,
            "username"=> $this->user,
            "password"=> $this->password,
            "port"=> $this->port,
            "socket"=>  $this->socket
            );
    }
}