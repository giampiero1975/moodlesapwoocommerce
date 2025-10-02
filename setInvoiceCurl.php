<?php
include_once 'Model/DBMoodle.php';
date_default_timezone_set('Europe/Rome');
try {
    $moodle = new dbmoodle('mdlapps_moodleadmin');
    // verifico che siamo presenti pagamenti non elaborati(sales='0') che non siano stati inseriti più di 30 minuti fà per inviarlo una sola volta
    $enrol_paypal = $moodle->select("SELECT * FROM moodle_payments WHERE sales='0' and TIMESTAMPDIFF(MINUTE,data_ins,NOW()) <=30;");
    #$enrol_paypal = $moodle->select("SELECT * FROM moodle_payments WHERE id=2692;");

    # echo "<pre>";
    if (empty($enrol_paypal)) {
        #echo "<br>pagamenti non presenti";
        exit();
    }
    
    #print_r($enrol_paypal);
    #die();
    $url=null;
    foreach ($enrol_paypal as $keyEnrol) {
        #$url = "http://moodlesap.test/index.php/sap/ins?"; # url di svi
        $url = "http://moodlesap.metmi.lan/index.php/sap/ins?"; # url di ese
        $url .= "id=" . $keyEnrol['id'];
        /*
        $url .= "mdl=" . $keyEnrol['mdl'] 
             . "&courseid=" . $keyEnrol['courseid'] 
             . "&userid=" . $keyEnrol['userid'] 
             . "&tipo=". $keyEnrol['method']
             . "&payment_id=". $keyEnrol['payment_id'];
        */
        #echo "<br>".$url;
        
        // step1
        $curlSES = curl_init();
        // step2
        curl_setopt($curlSES, CURLOPT_URL, $url);
        curl_setopt($curlSES, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlSES, CURLOPT_HEADER, false);
        // step3
        $result = curl_exec($curlSES);

        echo "\n" . date("H:i:s")." ";
        if (! $result) {
            echo $url . " - Errore: " . curl_error($curlSES) . " - Codice errore: " . curl_errno($curlSES);
        } else {
            // step5
            echo $url . " - " . $result;
        }
        // step4
        curl_close($curlSES);
        
        sleep(60); // imposto 2 minuti per ogni chiamata
    }
} catch (Exception $e) {
    echo $url . "<br>Err: " . $e->getMessage();
    echo $url . "<br>Code: " . $e->getCode();
}
