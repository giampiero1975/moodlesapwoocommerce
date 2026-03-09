<?php
require_once "inc/bootstrap.php";
require_once "SapServiceHandler.php";

$handler = new SapServiceHandler();

// Esempio Setter: aumentiamo i tentativi per un test più "assetante"
$handler->setMaxRetries(5);
$handler->setRetryWait(2);

// Esecuzione
$xmlTest = "..."; // Il tuo XML di test
$docNumTest = "2026300243";

echo "Esecuzione ciclo SAP...\n";
$status = $handler->executeInvoiceCycle($xmlTest, $docNumTest);

// --- UTILIZZO DEI GETTER ---
if ($status) {
    echo "OPERAZIONE RIUSCITA!\n";
    echo "SAP DocEntry: " . $handler->getLastDocEntry() . "\n";
    echo "Tentativi eseguiti: " . $handler->getAttempts() . "\n";
    echo "Recuperata da DB (Polling)? " . ($handler->wasRecovered() ? "Sì" : "No") . "\n";
} else {
    echo "OPERAZIONE FALLITA!\n";
    echo "Dettaglio Errore: " . $handler->getLastError() . "\n";
}