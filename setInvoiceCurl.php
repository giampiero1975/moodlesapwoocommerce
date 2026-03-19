<?php
if (!defined('PROJECT_ROOT_PATH')) {
    define('PROJECT_ROOT_PATH', __DIR__ . '/');
}

include_once 'Model/SapInvoiceHandler.php';
$sapHandler = new SapServiceHandler();
/*
$risultato = $sapHandler->getSystemUptimeCheck();

echo "<pre>";
echo "Risultato ottenuto:\n";
echo "------------------------------\n";
var_dump($risultato);
echo "------------------------------\n";
echo "</pre>";

if (strpos($risultato, 'UP') !== false || strpos($risultato, 'Rilevati') !== false) {
    echo "<b style='color:green'>TEST SUPERATO: PHP vede i processi!</b>";
} else {
    echo "<b style='color:red'>TEST FALLITO: PHP non vede ancora i processi.</b>";
}
die();
echo "===== MONITORAGGIO SAP SYSTEM =====<br>";
*/

// 1. Diagnosi
$stats = $sapHandler->pingWebService();
echo "1. DIAGNOSI: " . $stats['stato'] . " ({$stats['total_time_ms']}ms)<br>";

die();
/*
// -- 🧪 INIZIO FORZATURA (DA RIMUOVERE DOPO IL TEST) ---
echo "<br>⚠️ SIMULAZIONE ATTIVA: Sovrascrivo i dati per test GIALLO...<br>";
// $stats['total_time_ms'] = 499; // <--- Questo valore farà scattare il GIALLO
$stats['total_time_ms'] = 2001; // <--- Questo valore farà scattare il ROSSO
$stats['is_alive'] = true;
// --- 🧪 FINE FORZATURA ---
*/

// 2. L'ORCHESTRATORE: gestisce log, reset e attese internamente
$procedi = $sapHandler->gestisciStatoServer($stats);
// 3. Elaborazione Fatture (solo se il sistema è pronto)
if ($procedi) {
    echo "==========================================<br>";
    echo "SISTEMA PRONTO: Avvio invio fatture...<br>";
    
    // Qui chiameresti il metodo processPendingInvoices() di cui parlavamo prima
    // echo $sapHandler->processPendingInvoices();
} else {
    echo "==========================================<br>";
    exit("INTERRUZIONE: Sistema non ripristinato dopo la manovra.<br>");
}

try {
    // --- C. RECUPERO E INVIO FATTURE ---
    // verifico che siamo presenti pagamenti non elaborati(sales='0') che non siano stati inseriti più di 30 minuti fà per inviarlo una sola volta
	$moodle = new DBMoodle();
    $enrol_paypal = $moodle->select("SELECT * FROM moodle_payments WHERE sales='0' and `logfile` IS null;");

    # echo "<pre>";
    if (empty($enrol_paypal)) {
        # echo "<br>pagamenti non presenti";
        exit();
    }

    # print_r($enrol_paypal);
    # die();
    $url = null;
    foreach ($enrol_paypal as $keyEnrol) {
        $current_id = $keyEnrol['id'];

        if (empty($current_id)) {
            echo "⚠️ Attenzione: Trovato record senza ID, lo salto.<br>";
            continue; // <--- IL SALVAGENTE!
        }
		
        $url = "http://moodlesapwoocommerce.metmi.lan/index.php/sap/ins?"; # url di ese
        $url .= "id=" . $current_id;
        # echo "<br>".$url;

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

        sleep(60); // imposto 2 minuti per ogni chiamata
    }
} catch (Exception $e) {
    echo $url . "<br>Err: " . $e->getMessage();
    echo $url . "<br>Code: " . $e->getCode();
}
