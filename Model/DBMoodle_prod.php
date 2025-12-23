<?php
#[\AllowDynamicProperties]
class dbmoodle
{

    protected $connection = null;

    protected $maps = null;

    public $mdl;
    public $host;
    public $user;
    public $pass;
    
    public function __construct($mdl)
    {
        $this->mdl = $mdl;
        $this->host = "192.168.11.16";
        //$this->user = "moodle";
        $this->pass = "RmnPbT78";

        // Logica condizionale per impostare l'utente del database
        if ($this->mdl === 'mdlapps_moodleadmin') {
            $this->user = "mdlapps"; // Utente per il database 'mdlapps_moodleadmin'
        } else {
            // Questo coprirà tutti gli altri database Moodle (es. 'mdl_formazioneoss')
            $this->user = "moodle"; // Utente per i database Moodle specifici
        }
        
        try {
            # echo "</br>MDL: ".$this->mdl;
            $this->connection = new PDO("mysql:host=$this->host;dbname=$this->mdl;charset=utf8mb4", "$this->user", "$this->pass", 
                array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ));
            # $this->connection->set_charset('utf8mb4');
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
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
                // echo "<br>Status code: {$statusCode} \n" . $error;
                return array(
                    "status" => $statusCode,
                    "error" => $error
                );
            } else {
                return json_decode($response, true);
            }
        } catch (Exception $e) {
            throw new Exception("\n1. " . $e->getMessage());
        }
        return false;
    }

    public function select($query = "", $params = [])
    {
        try {
            // echo $query;
            // print_r($params);
            $stmt = $this->executeStatement($query, $params);
            $stmt->setFetchMode(PDO::FETCH_ASSOC);
            $reponse = $stmt->fetchAll();
            return $reponse;
        } catch (Exception $e) {
            throw new Exception("\n" . $e->getMessage());
        }
        return false;
    }

    public function create($query, $params = [])
    {
        // echo "\n".$query."\n";
        // print_r($params);
        try {
            $stmt = $this->executeStatement($query, $params);
            return $stmt;
        } catch (Exception $e) {
            throw new Exception("\n1. " . $e->getMessage());
        }
        return false;
    }

    public function insCrm($query, $params)
    {
        try {
            $stmt = $this->executeStatement($query, $params);
            return $stmt;
        } catch (Exception $e) {
            throw new Exception("\n1. " . $e->getMessage());
        }
        return false;
    }

    private function executeStatement($query = "", $params = [])
    {
        try {
            $stmt = $this->connection->prepare($query);

            if ($stmt === false) {
                throw new Exception("\n2. Unable to do prepared statement: " . $query);
            }

            /**
             * if( $params ) {
             * $stmt->bind_param($params[0], $params[1]);
             * }
             *
             * $stmt->execute();
             */

            if (! empty($params))
                $stmt->execute($params);
            else
                $stmt->execute();

            return $stmt;
        } catch (Exception $e) {
            throw new Exception("\n3. " . $e->getMessage());
        }
    }
}