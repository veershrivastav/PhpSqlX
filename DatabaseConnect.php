<?php
/**
 * Quiz Management by www.veershubhranshu.com
 * 
 *  File: DatabaseConnect
 * 
 * Author: Veer Shrivastav
 * Date: May 10, 2015, 9:58:51 PM
 *
 */
interface DatabaseConnect {
    
    public function create_table ($tablename, $columnname, $flags);
    
    public function get_records ($tablename, $columnname, $where, $sort, $orderby, $limitcount, $limitoffset);
    
    public function get_record ($tablename, $columnname, $where, $sort, $orderby, $limitcount, $limitoffset);
    
    public function get_records_sql ($sql, $where, $sort, $orderby, $limitcount, $limitoffset);
    
    public function get_record_sql ($sql, $where, $sort, $orderby, $limitcount, $limitoffset);
    
    public function delete_records ($tablename, $where);
    
    public function update_records ($tablename, $data, $where);
    
    public function insert_record ($tablename, $data);
    
    public function insert_records ($tablname, $datas);
    
    public function raw_execute_sql ($sql);
    
    public function begin_transaction();
    
    public function rollback_transaction();
    
    public function complete_transaction();
    
    public function xml_install(SimpleXMLElement $xmlObj);
    
    public function getCredentials();
}