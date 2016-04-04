<?php
//This file is part of PhpSqlX.
//
//PhpSqlX is free software: you can redistribute it and/or modify
//it under the terms of the GNU General Public License as published by
//the Free Software Foundation, either version 3 of the License, or
//(at your option) any later version.
//
//PhpSqlX is distributed in the hope that it will be useful,
//but WITHOUT ANY WARRANTY; without even the implied warranty of
//MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//GNU General Public License for more details.
//
//You should have received a copy of the GNU General Public License
//along with PhpSqlX.  If not, see <http://www.gnu.org/licenses/>.
/**
 * PhpSqlX by www.veershrivastav.com
 * 
 * File: phpsqlx_sqlite3
 * 
 * Author: Aayush Sahay
 * Date: June 5, 2015, 01:18:34 PM
 *
 */

require_once('PhpSqlX.php');

define("DATABASE_MAIN", "sqlite_master");
define("DATABASE_TEMP", "sqlite_temp_master");

class phpsqlx_sqlite3 implements PhpSqlX {

    private $dbname = 'dbconnect';
    private $path = '/';
    private static $instance = NULL;
    private $tablecolumns = NULL;
    private $tablecolumndetails = NULL;
    private $connection = NULL;
    private $mastertables = array();
    private $transaction = NULL;

    /*     * * CONSTRUCTOR ** */
    private function __construct($dbname, $path) {
        $this->mastertables[] = DATABASE_MAIN;
        $this->mastertables[] = DATABASE_TEMP;

        if (isset($path)) {
            $this->path = $path;
            $this->dbname = $dbname;
            $dbname = $path . $dbname . ".db";
        }
        $this->connection = new SQLite3($dbname);
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
                    $sql .= " AUTOINCREMENT ";
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

        if (array_key_exists("temp", $flags) || array_key_exists("temporary", $flags)) {
            if (isset($flags['temp']) && $flags['temp'] == true)
                $sql .= "TEMPORARY ";
            elseif (isset($flags["temporary"]) && $flags["temporary"] == true)
                $sql .= "TEMPORARY ";
        }

        $sql .= "TABLE ";

        if (array_key_exists("ifnot", $flags)) {
            if (isset($flags["ifnot"]) && $flags["ifnot"] == true) {
                $sql .= "IF NOT EXISTS ";
            }
        } else {
            /*             * * JUST TO BE SAFE ** */
            $sql .= "IF NOT EXISTS ";
        }

        if (strpos($tablename, "sqlite_") === 0) {
            trigger_error("table name cannot start with \"sqlite_\"", E_USER_ERROR);
            return false;
        }

        $sql .= $tablename;

        if (!$column = $this->create_column_builder($columnname)) {
            return false;
        }

        $sql .= "(" . $column . ");";

        if ($result = $this->connection->exec($sql)) {
            return true;
        }

        return false;
    }

    /*     * * TABLE DESC ** */
    private function describe_table($tablename) {
        if (!(gettype($tablename) == 'string')) {
            trigger_error("tablename should be string", E_USER_ERROR);
            return false;
        }

        $this->tablecolumns = array();
        $this->tablecolumndetails = new stdClass();

        $temp_result = array();

        foreach ($this->mastertables as $table) {
            $q = "SELECT name FROM " . $table . " WHERE type='table' AND name='" . $tablename . "';";
            if (!($this->connection->query($q)->fetchArray(SQLITE3_ASSOC))) {
                $temp_result[] = false;
            } else {
                $temp_result[] = true;
            }
        }

        if ($temp_result[0] == false && $temp_result[1] == false) {
            trigger_error("table \"" . $tablename . "\" does not exist", E_USER_ERROR);
            return false;
        }

        $result = $this->connection->query("PRAGMA table_info(" . $tablename . ")");

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            //Array to store column name of the table
            $this->tablecolumns[] = $row['name'];

            //Detailed descrption of the table.
            $this->tablecolumndetails->$row['name'] = new stdClass();
            $this->tablecolumndetails->$row['name']->type = $row['type'];
            $this->tablecolumndetails->$row['name']->null = $row['notnull'];
            $this->tablecolumndetails->$row['name']->key = $row['pk'];
            $this->tablecolumndetails->$row['name']->default = $row['dflt_value'];
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

        $sql .= $insertclause[0] . " VALUES " . $insertclause[1];

        if (!($stmt = $this->connection->prepare($sql))) {
            return false;
        }

        $i = 1;
        foreach ($data as $key => $value) {
            $stmt->bindValue($i, $value);
            $i++;
        }

        if ($result = $stmt->execute()) {
            $newId = $this->connection->lastInsertRowID();
            $stmt->close();
            if ($newId > 0)
                return $newId;
            else
                return true;
        }
        else {
            $stmt->close();
            return false;
        }
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

        $sql .= $insertclause[0] . " VALUES " . $insertclause[1];

        if (!($stmt = $this->connection->prepare($sql))) {
            return false;
        }

        $i = 1;
        foreach ($datas as $data) {
            foreach ($data as $key => $value) {
                $stmt->bindValue($i, $value);
                $i++;
            }
        }

        if ($result = $stmt->execute()) {
            $newId = $this->connection->lastInsertRowID();
            $stmt->close();
            if ($newId > 0)
                return $newId;
            else
                return true;
        }
        else {
            $stmt->close();
            return false;
        }
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

        if ($limitcount && $limitoffset) {
            $sql .= " LIMIT " . $limitcount . " OFFSET " . $limitoffset;
        } elseif ($limitcount && !$limitoffset) {
            $sql .= " LIMIT " . $limitcount;
        }

        if (!($stmt = $this->connection->prepare($sql))) {
            return false;
        }

        if ($where) {
            $i = 1;
            foreach ($where as $key => $value) {
                if (!(gettype($value) == 'array')) {
                    $value = addslashes($value);
                    $stmt->bindValue($i, $value);
                    $i++;
                } else {
                    foreach ($value as $val) {
                        $val = addslashes($val);
                        $stmt->bindValue($i, $val);
                        $i++;
                    }
                }
            }
        }

        if ($result = $stmt->execute()) {
            if ($result->numColumns() == 0) {
                $stmt->close();
                return false;
            } else {
                $returnresult = array();
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $resultrow = new stdClass();
                    foreach ($row as $key => $val) {
                        $resultrow->$key = $val;
                    }
                    $returnresult[] = $resultrow;
                }
            }
            $stmt->close();
            return $returnresult;
        } else {
            $stmt->close();
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

        if ($limitcount && $limitoffset) {
            $sql .= " LIMIT " . $limitcount . " OFFSET " . $limitoffset;
        } elseif ($limitcount && !$limitoffset) {
            $sql .= " LIMIT " . $limitcount;
        }

        if (!($stmt = $this->connection->prepare($sql))) {
            return false;
        }

        if ($where) {
            $i = 1;
            foreach ($where as $key => $value) {
                if (!(gettype($value) == 'array')) {
                    $value = addslashes($value);
                    $stmt->bindValue($i, $value);
                    $i++;
                } else {
                    foreach ($value as $val) {
                        $val = addslashes($val);
                        $stmt->bindValue($i, $val);
                        $i++;
                    }
                }
            }
        }

        if ($result = $stmt->execute()) {
            if ($result->numColumns() == 0) {
                $stmt->close();
                return false;
            } else {
                $returnresult = array();
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $resultrow = new stdClass();
                    foreach ($row as $key => $val) {
                        $resultrow->$key = $val;
                    }
                    $returnresult[] = $resultrow;
                }
            }

            $stmt->close();
            return $returnresult;
        } else {
            $stmt->close();
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

        if (!($stmt = $this->connection->prepare($sql))) {
            return false;
        }

        $i = 1;
        if ($data) {
            foreach ($data as $key => $value) {
                if (!(gettype($value) == 'array')) {
                    $value = addslashes($value);
                    $stmt->bindValue($i, $value);
                    $i++;
                } else {
                    foreach ($value as $val) {
                        $val = addslashes($val);
                        $stmt->bindValue($i, $val);
                        $i++;
                    }
                }
            }
        }

        if ($where) {
            foreach ($where as $key => $value) {
                if (!(gettype($value) == 'array')) {
                    $value = addslashes($value);
                    $stmt->bindValue($i, $value);
                    $i++;
                } else {
                    foreach ($value as $val) {
                        $val = addslashes($val);
                        $stmt->bindValue($i, $val);
                        $i++;
                    }
                }
            }
        }

        if ($result = $stmt->execute()) {
            $stmt->close();
            return true;
        } else {
            $stmt->close();
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

        //Initiate Prepared Statement			
        if (!($stmt = $this->connection->prepare($sql))) {
            return false;
        }

        $i = 1;
        if ($where) {
            foreach ($where as $key => $value) {
                if (!(gettype($value) == 'array')) {
                    $value = addslashes($value);
                    $stmt->bindValue($i, $value);
                    $i++;
                } else {
                    foreach ($value as $val) {
                        $val = addslashes($val);
                        $stmt->bindValue($i, $val);
                        $i++;
                    }
                }
            }
        }

        if ($result = $stmt->execute()) {
            $stmt->close();
            return true;
        } else {
            $stmt->close();
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
        //Initiate Prepared Statement
        if (!($stmt = $this->connection->prepare($sql))) {
            return false;
        }

        if (isset($param)) {
            $i = 1;
            foreach ($param as $key => $value) {
                if (!(gettype($value) == 'array')) {
                    $value = addslashes($value);
                    $stmt->bindValue($i, $value);
                    $i++;
                } else {
                    foreach ($value as $val) {
                        $val = addslashes($val);
                        $stmt->bindValue($i, $val);
                        $i++;
                    }
                }
            }
        }

        if ($result = $stmt->execute()) {
            $stmt->close();
            return true;
        } else {
            $stmt->close();
            return false;
        }
    }

    /*     * * TRANSACTION ** */
    public function begin_transaction() {
        if (!$this->transaction) {
            $this->connection->exec("BEGIN;");
            $this->transaction = true;
        }
        return true;
    }

    public function rollback_transaction() {
        $this->connection->exec("ROLLBACK;");
        $this->transaction = false;
    }

    public function complete_transaction() {
        $this->connection->exec("COMMIT;");
        $this->transaction = false;
    }
    
    /*     * * GETINSTANCE ** */
    public static function getInstance($params) {
        $path = NULL;
        $dbname = '';

        if (!(gettype($params) == 'array')) {
            trigger_error("Associative array exepected in param, " . gettype($params) . " passed", E_USER_ERROR);
            return false;
        }

        foreach ($params as $param => $val) {
            $$param = $val;
        }

        if (!isset(self::$instance)) {
            self::$instance = new phpsqlx_mysqlnd($dbname, $path);
        }
        return self::$instance;
    }

    /*     * * GET CREDENTIALS ** */
    public function getCredentials() {
        return array(
            "dbname" => $this->dbname,
            "path" => $this->path
        );
    }

}

?>