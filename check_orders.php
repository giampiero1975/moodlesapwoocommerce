<?php
// Impostiamo un output più leggibile per il browser
header('Content-Type: text/plain; charset=utf-8');

// Includiamo i file necessari per la connessione e la logica
require_once __DIR__ . "/inc/bootstrap.php";
require_once PROJECT_ROOT_PATH . 'Model/WooCommerceModel.php';

echo "==================================================\n";
echo "  VERIFICA DATI COMPLETI ORDINI WOOCOMMERCE \n";
echo "==================================================\n\n";

// Gli ID degli ordini che vogliamo analizzare
$order_ids_to_check = [7502, 7881, 7892, 7884];

// Identificatore dell'istanza da usare (dal tuo config.php)
$instance_identifier = 'mdl_formazioneoss';

try {
    // Creiamo un'istanza del nostro connettore WooCommerce
    $wcModel = new WooCommerceModel($instance_identifier);
    echo "Connessione all'istanza: " . $instance_identifier . "\n\n";
    
    foreach ($order_ids_to_check as $order_id) {
        echo "--- Recupero dati completi per l'ordine #" . $order_id . " ---\n\n";
        
        // Chiamiamo l'API per ottenere i dettagli dell'ordine
        $orderData = $wcModel->getOrderById($order_id);
        
        if ($orderData) {
            // Se la chiamata ha successo, stampiamo l'intero output JSON formattato
            echo json_encode($orderData, JSON_PRETTY_PRINT);
            echo "\n\n";
            
        } else {
            // Se la chiamata fallisce
            echo "ERRORE: Impossibile recuperare i dati per l'ordine #" . $order_id . "\n\n";
        }
    }
    
} catch (Exception $e) {
    echo "ERRORE CRITICO: " . $e->getMessage() . "\n";
}

echo "--- Fine della verifica ---";

?>