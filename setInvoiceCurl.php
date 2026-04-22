<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!defined('PROJECT_ROOT_PATH')) {
    define('PROJECT_ROOT_PATH', __DIR__ . '/');
}

require_once 'guardian/check.php';

exit;

try {
	// 1. GENERAZIONE BATCH ID
    $batchID = "PRENOTATO_" . date("Ymd_His");
	
    // --- C. RECUPERO E INVIO FATTURE ---
    // verifico che siamo presenti pagamenti non elaborati(sales='0') che non siano stati inseriti più di 30 minuti fà per inviarlo una sola volta
	$moodle = new DBMoodle('mdlapps_moodleadmin');
    $enrol_paypal = $moodle->select("SELECT * FROM moodle_payments WHERE sales='0' and `logfile` IS null LIMIT 5;");

	if (empty($enrol_paypal)) {
		die("SISTEMA: Nessun record da elaborare (Coda vuota).\n");
	}

    // 3. UPDATE DI QUEI 10 SPECIFICI (Utilizziamo gli ID appena estratti)
    $ids_da_prenotare = [];
    foreach ($enrol_paypal as $row) {
        $ids_da_prenotare[] = $row['id'];
    }
    
    // Trasformiamo l'array in una stringa (es: 101,102,103...)
    $lista_id = implode(',', $ids_da_prenotare);
    
    // Eseguiamo l'update mirato solo su questi ID
	// echo "UPDATE moodle_payments SET logfile = '$batchID' WHERE id IN ($lista_id)";
    $sql_update = "UPDATE moodle_payments SET logfile = '$batchID' WHERE id IN ($lista_id)";
    $moodle->create($sql_update);

    $url = null;
    foreach ($enrol_paypal as $keyEnrol) {
		echo "<br> Entro nel foreach";
        $current_id = $keyEnrol['id'];

        if (empty($current_id)) {
            echo "⚠️ Attenzione: Trovato record senza ID, lo salto.<br>";
            continue; // <--- IL SALVAGENTE!
        }
		
        $url = "http://moodlesapwoocommerce.metmi.lan/index.php/sap/ins?"; # url di ese
        $url .= "id=" . $current_id;
        echo "<br>".$url;

        // step1
        $curlSES = curl_init();
        // step2
        curl_setopt($curlSES, CURLOPT_URL, $url);
        curl_setopt($curlSES, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlSES, CURLOPT_HEADER, false);
        // step3
        $result = curl_exec($curlSES);

        echo "<br>" . date("H:i:s") . " ";
        if (! $result) {
            echo $url . " - Errore: " . curl_error($curlSES) . " - Codice errore: " . curl_errno($curlSES);
        } else {
            // step5
            echo $url . " - " . $result;
        }
        // step4
        curl_close($curlSES);

        sleep(120); // imposto 2 minuti per ogni chiamata
    }
} catch (Exception $e) {
    echo $url . "<br>Err: " . $e->getMessage();
    echo $url . "<br>Code: " . $e->getCode();
}
