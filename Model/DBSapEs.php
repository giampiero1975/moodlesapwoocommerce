<?php
require_once PROJECT_ROOT_PATH . "/inc/config_es.php";
require_once PROJECT_ROOT_PATH . "phplogger.php";
class dbsapEs
{
    protected $connection = null;
    protected $maps=null;
    
    public function __construct()
    { 
        $config = new costantiEs();
        $this->db = $config::REQUESTDB;
        $this->username = "sa";
        $this->password = "Wie@q&OxfePH";
        $this->host = "192.168.10.44";
        $this->port = "1433";
        try {            
            /*
            $this->connection = new PDO("sqlsrv:Server=192.168.10.44;Database=".$this->db, "sa", "Wie@q&OxfePH", array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ));            
            */
            
            $this->connection = new PDO("dblib:host=$this->host:$this->port;dbname=$this->db", "$this->username", "$this->password", array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            
        } catch (Exception $e) {
            throw new Exception(__FILE__." >> ".$e->getMessage());
        }
    }
    
    public function getCurl($url, $data)
    {
        $headers = array(
            'Authorization: Basic U2VuZWM6OGVOWDRSYWZlMWFt',
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded; charset=utf-8'
        );
        try {
            $channel = curl_init($url);
            
            curl_setopt($channel, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($channel, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($channel, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($channel, CURLOPT_POSTFIELDS, $data);
            // curl_setopt($channel, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($channel); // execute the request
            $statusCode = curl_getInfo($channel, CURLINFO_HTTP_CODE);
            
            
            $error = curl_error($channel);
            curl_close($channel);
            
            http_response_code($statusCode);
            if ($statusCode != 200) {
                //echo "<br>Status code: {$statusCode} \n" . $error;
                return array("status"=>$statusCode, "error"=>$error);
            } else {
                return json_decode($response, true);
            }
            
        } catch (Exception $e) {
            throw New Exception( "\n1. ".$e->getMessage() );
        }
        return false;
        
    }
    
    public function select($query = "" , $params = [])
    {
        $logger = Logger::get_logger();
        try {
            // echo $query;
            // print_r($params);
            $stmt = $this->executeStatement( $query , $params );
            $stmt->setFetchMode(PDO::FETCH_ASSOC);
            $reponse = $stmt->fetchAll();
            return $reponse;
            
        } catch(Exception $e) {
            throw New Exception( "\n1. ".$e->getMessage() );
            $logger->log("Errore sql SAP: ". $e->getMessage());
        }
        return false;
    }
    
    public function updCrm($query, $params=[]){
        try {
            $stmt = $this->executeStatement( $query , $params );
            return $stmt;
            
        } catch(Exception $e) {
            throw New Exception( "\n\nClass: ". __CLASS__ ." Function: ".__FUNCTION__." Line: ".__LINE__." ".$e->getMessage() );
            $logger->log("Errore sql SAP: \n\nClass: ". __CLASS__ ."\nFunction: ".__FUNCTION__."\nLine: ".__LINE__." ".$e->getMessage());
        }
        return false;
    }
    
    public function insCrm($query, $params){
        try {
            $stmt = $this->executeStatement( $query , $params );
            return $stmt;
            
        } catch(Exception $e) {
            throw New Exception( "\n1. ".$e->getMessage() );
        }
        return false;
    }
    
    private function executeStatement($query = "" , $params = [])
    {
        try {
            $stmt = $this->connection->prepare( $query );
            
            if($stmt === false) {
                throw New Exception("\n2. Unable to do prepared statement: " . $query);
            }

            if(!empty($params))
                $stmt->execute($params);
            else
                $stmt->execute();
            
            return $stmt;
        } catch(Exception $e) {
            throw New Exception( "\n3. ".$e->getMessage() );
        }
    }
}