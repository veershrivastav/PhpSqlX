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
 * File: PhpSqlX Interface
 * 
 * Author: Veer Shrivastav
 * Date: May 10, 2015, 9:58:51 PM
 *
 */
interface PhpSqlX {
    
    public function create_table ($tablename, $columnname, $flags);
    
    public function get_records ($tablename, $columnname, $where, $sort, $orderby, $limitcount, $limitoffset);
    
    public function get_record ($tablename, $columnname, $where, $sort, $orderby, $limitcount, $limitoffset);
    
    public function get_records_sql ($sql, $where, $sort, $orderby, $limitcount, $limitoffset);
    
    public function get_record_sql ($sql, $where, $sort, $orderby, $limitcount, $limitoffset);
    
    public function delete_records ($tablename, $where);
    
    public function update_records ($tablename, $data, $where);
    
    public function insert_record ($tablename, $data);
    
    public function insert_records ($tablname, $datas);
    
    public function raw_execute_sql ($sql, $param);
    
    public function begin_transaction();
    
    public function rollback_transaction();
    
    public function complete_transaction();
    
    public function getCredentials();
}