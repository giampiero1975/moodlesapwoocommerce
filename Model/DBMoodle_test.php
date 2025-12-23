<?php

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

        // ====================================================================
        // --- LOGICA DEBUG: DEVIAZIONE TRAFFICO ---
        // ====================================================================
        
        // Se il DB richiesto è quello di amministrazione (moodle_payments),
        // devio sul database LOCALE 'paypal'.
        if ($this->mdl === 'mdlapps_moodleadmin') {
            
            // --- CONFIGURAZIONE LOCALE (DEBUG) ---
            $this->host = "127.0.0.1"; // O "localhost"
            $this->mdl  = "paypal";    // Nome del tuo DB locale
            $this->user = "root";      // Utente DB locale (Modifica se diverso)
            $this->pass = "";          // Password DB locale (Modifica se diversa)
            
        } else {
            
            // --- CONFIGURAZIONE PRODUZIONE (STANDARD) ---
            // Tutte le altre tabelle/DB (es. mdl_formazioneoss) restano sul server remoto
            $this->host = "192.168.11.16";
            $this->pass = "RmnPbT78"; // Password produzione

            // Gestione utente specifica per produzione
            if ($this->mdl === 'mdlapps_moodleadmin') {
                $this->user = "mdlapps"; 
            } else {
                $this->user = "moodle"; 
            }
        }
        // ====================================================================
        
        try {
            # echo "</br>MDL: ".$this->mdl;
            // Aggiungo il charset alla stringa DSN per sicurezza
            $dsn = "mysql:host=$this->host;dbname=$this->mdl;charset=utf8mb4";
            
            $this->connection = new PDO($dsn, $this->user, $this->pass, 
                array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ));
            
        } catch (Exception $e) {
            // Mostro host e user nel messaggio d'errore per capire subito dove sta fallendo
            throw new Exception("Connection Error ($this->host / $this->user): " . $e->getMessage());
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