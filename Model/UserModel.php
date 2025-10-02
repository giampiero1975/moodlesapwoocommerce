<?php
require_once PROJECT_ROOT_PATH . "/Model/DBMoodle.php";
require_once PROJECT_ROOT_PATH . "phplogger.php";
require_once PROJECT_ROOT_PATH . 'inc/utility.php';

class UserModel extends dbmoodle
{
    public function checkSettingMoodle(){
        try {
            $logger = Logger::get_logger();
            // verifica campi setting moodle
            $logger->log("Verifica setting dei campi moodle");
            $sql = "SELECT shortname, `name` FROM mdl_user_info_field;";
            $logger->log($sql);
            $fielsdmoodle = $this->select($sql);
            
            foreach ($fielsdmoodle as $keyfielsdmoodle => $valuefielsdmoodle){
                $arrFiels[$valuefielsdmoodle['shortname']]=null;
            }
            
            $settingMoodle = [
                #'localita'=>'localita',
                #'provincia'=>'provincia',
                #'indirizzo'=>'indirizzo',
                #'codici'=>'cap',
                #'telefono'=>'telefono',
                #'partitaiva'=>'partita iva',
                'Rag'=>'Ragione sociale',
                'CF'=>'CF',
                #'CU'=>'IPACodePA',
                'IND'=>'Indirizzo fatturazione',
                'Loc'=>'Comune fatturazione',
                'CAP'=>'CAP fatturazione',
                'PR'=>'Provincia fatturazione',
                'EM'=>'EM',
                #'PEC'=>'PEC'
            ];
            
            $this->settingMoodle=null;
            foreach ($settingMoodle as $keySettingMoodle =>$valueSettingMoodle){
                
                #echo "<br>".$keySettingMoodle;
                if (!array_key_exists($keySettingMoodle, $arrFiels)) {
                    $this->settingMoodle.="Il campo non &egrave; configurato correttamente: [<b>".$keySettingMoodle."</b>] ". $valueSettingMoodle."<br>";
                    $logger->log("Il campo non è configurato correttamente: [".$keySettingMoodle."] ". $valueSettingMoodle);
                }
            }
            // $logger->log("Configurazione Moodle corretta");
            return $this->settingMoodle;
        } catch (Exception $e) {
            echo "<br>".$e->getMessage();
            $logger->log($e->getMessage());
        }
    }
    
    public function getUsers($userid, $courseid, $tipo, $paymentid, $mdl, $cost)
    {
        $util = new utility();
        $logger = Logger::get_logger();
        $this->user = $userid;
        $this->courseid = $courseid;
        $this->tipo = $tipo;
        $this->paymentid = $paymentid;
        $this->mdl = $mdl;
        $this->cost = $cost;
           
        $logger->log("classe: ".__CLASS__ ." metodo: " .__METHOD__);
        
        switch ($this->tipo) {
                case 'paypal':
                case 'els_paypal':
                case 'woocommerce':
                    $sql = "SELECT "
                        ."mdl_user_enrolments.id, mdl_enrol.enrol, mdl_enrol.courseid, '".$this->cost."' as cost, mdl_enrol.currency, mdl_course.fullname, trim(mdl_course.idnumber) AS idnumber "
                          ."FROM mdl_user_enrolments JOIN mdl_enrol ON mdl_user_enrolments.enrolid = mdl_enrol.id "
                          ."JOIN mdl_course ON mdl_course.id = mdl_enrol.courseid "
                          ."WHERE mdl_user_enrolments.userid=".$this->user." and mdl_enrol.courseid=".$this->courseid." and mdl_enrol.enrol='".$this->tipo."';";
                break;
                case 'manual':
                    $sql = "SELECT 
                                mdl_pagamenti.id,
                                mdl_pagamenti.scontato as cost,
                                mdl_pagamenti.datapagamento,
                                mdl_enrol.enrol, 
                                mdl_enrol.courseid, 
                                mdl_enrol.currency, 
                                mdl_course.fullname, 
                                TRIM(mdl_course.idnumber) AS idnumber
                                FROM mdl_user_enrolments
	                               JOIN mdl_enrol ON mdl_user_enrolments.enrolid = mdl_enrol.id
	                               JOIN mdl_course ON mdl_course.id = mdl_enrol.courseid
	                               JOIN mdlapps_moodleadmin.mdl_pagamenti AS mdl_pagamenti
		                              ON (mdl_pagamenti.iduser = mdl_user_enrolments.userid and mdl_pagamenti.idcorso = mdl_enrol.courseid AND mdl_pagamenti.mdl='$this->mdl')
                            WHERE mdl_pagamenti.iduser=$this->user
                            AND mdl_pagamenti.datapagamento is not null 
                            AND mdl_enrol.courseid=$this->courseid 
                            AND mdl_enrol.enrol='$this->tipo';";
                break;
                default:
                    $logger->log("Metodo pagamento non riconosciuto: ".$this->tipo);
                break;
            }
            
        $logger->log($sql);
        $daticorso = $this->select($sql);
        if(empty($daticorso)){
            $logger->log("Pagamento non presente per l'ID: ". $this->user);
            return false;
        }
        
        $sql = "DROP VIEW IF EXISTS _fatturazione;";
        $this->create($sql);

        // echo "</br> Mdl: ". $this->mdl;
        $sql = "CREATE VIEW `_fatturazione` AS
                SELECT _user.id, _user.lastname, _user.firstname, _user.email, _field.shortname, _field.name,_data.`data` AS valore
                FROM 
                    mdl_user_info_data AS _data, 
                    mdl_user_info_field AS _field, 
                    mdl_user AS _user
                    WHERE _data.userid =".$this->user."
                    AND _data.fieldid = _field.id
                    AND _user.id = _data.userid;";
        $this->create($sql);
        $logger->log($sql);
            
        $sql = "SELECT id, 
                    CONCAT(lastname,' ', firstname) AS nome, email, 
                    MAX(CASE WHEN shortname = 'telefono' THEN upper(trim(valore)) END) `telefono`,
                    MAX(CASE WHEN shortname = 'partitaiva' THEN upper(trim(valore)) END) `partitaiva`,
                    MAX(CASE WHEN shortname = 'Rag' THEN upper(trim(valore)) END) `Rag`,
                    MAX(CASE WHEN shortname = 'CF' THEN upper(trim(valore)) END) `CF`,
                    MAX(CASE WHEN shortname = 'CU' THEN upper(trim(valore)) END) `IPACodePA`,
                    MAX(CASE WHEN shortname = 'IND' THEN upper(trim(valore)) END) `fattind`,
                    MAX(CASE WHEN shortname = 'Loc' THEN upper(trim(valore)) END) `fattcomune`,
                    MAX(CASE WHEN shortname = 'cap' THEN upper(trim(valore)) END) `fattcap`,
                    MAX(CASE WHEN shortname = 'PR' THEN upper(trim(valore)) END) `fattprov`,
                    MAX(CASE WHEN shortname = 'EM' THEN upper(trim(valore)) END) `EM`,
                    MAX(CASE WHEN shortname = 'PEC' THEN upper(trim(valore)) END) `PEC`
                    FROM `_fatturazione` 
                    GROUP BY id;";
        $logger->log($sql);
        $datiutente = $this->select($sql);
        $datiutente['0'] = $util->normalizzaArray($datiutente['0']);

        # pulisco la stringa da eventuali caratteri speciali
        foreach ($datiutente[0] as $key =>$value) {
            #pulizia spazi email
            if($key=='PEC'||$key=='EM'||$key=='email')
                $datiutente[0][$key]=str_replace(" ","",$datiutente[0][$key]);
        }
        
        $datiMerge = array_merge($datiutente, $daticorso);
        return $datiMerge;
    }
}