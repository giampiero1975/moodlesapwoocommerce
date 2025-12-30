<?php
include_once 'Model/DBMoodle.php';
date_default_timezone_set('Europe/Rome');
try {
    $moodle = new dbmoodle('mdlapps_moodleadmin');
    // verifico che siamo presenti pagamenti non elaborati(sales='0') che non siano stati inseriti più di 30 minuti fà per inviarlo una sola volta
    $enrol_paypal = $moodle->select("SELECT * FROM moodle_payments WHERE sales='0' and `logfile` IS null;");

    # echo "<pre>";
    if (empty($enrol_paypal)) {
        #echo "<br>pagamenti non presenti";
        exit();
    }
    
    #print_r($enrol_paypal);
    #die();
    $url=null;
    foreach ($enrol_paypal as $keyEnrol) {
        // --- INIZIO FIX ANTI-DUPLICATI ---
        $current_id = $keyEnrol['id'];
        
        // 1. PRIMA di fare qualsiasi cosa, proviamo a "prenotare" questo ID sul database.
        //    Usiamo un update diretto. Se logfile NON è null (quindi qualcun altro lo sta facendo),
        //    la query non aggiornerà nessuna riga.
        $sql_lock = "UPDATE moodle_payments SET logfile = 'IN_CORSO_LOCK' WHERE id = '$current_id' AND logfile IS NULL";
        
        // ATTENZIONE: Qui devi usare il metodo della tua classe per eseguire query di Update.
        // Se la tua classe DBMoodle ha un metodo ->query() o ->execute(), usalo qui.
        // Esempio generico:
        $moodle->query($sql_lock);
        
        // 2. CONTROLLO FONDAMENTALE: Abbiamo aggiornato davvero la riga?
        //    Se affected_rows è 0, significa che logfile NON era null (qualcun altro lo ha preso).
        if ($moodle->affected_rows == 0) {
            echo "\n" . date("H:i:s") . " ID $current_id saltato: già in lavorazione da un altro processo.";
            continue; // SALTA SUBITO AL PROSSIMO ELEMENTO DEL CICLO
        }
        // --- FINE FIX ---
        
        $url = "http://moodlesapwoocommerce.metmi.lan/index.php/sap/ins?"; # url di ese
        $url .= "id=" . $keyEnrol['id'];
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
