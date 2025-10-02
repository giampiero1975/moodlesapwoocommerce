<?php

/**
 * Classe WooCommerceModel
 * Gestisce la comunicazione con le API REST di diverse istanze di WooCommerce.
 */
class WooCommerceModel {
    
    private $wc_url;
    private $consumer_key;
    private $consumer_secret;
    
    /**
     * Il costruttore riceve un identificatore e carica le credenziali corrette
     * dalla configurazione centrale in config.php.
     * @param string $instance_identifier L'identificatore dell'istanza (es. 'mdl_formazioneoss')
     */
    public function __construct($instance_identifier) {
        // Carica la configurazione delle costanti
        $config = new costanti();
        
        // Controlla se l'istanza richiesta esiste nella configurazione
        if (isset($config::WOOCOMMERCE_INSTANCES[$instance_identifier])) {
            $instance_config = $config::WOOCOMMERCE_INSTANCES[$instance_identifier];
            
            // Imposta le proprietà della classe con i dati corretti
            $this->wc_url = $instance_config['url'] . '/wp-json/wc/v3/orders';
            $this->consumer_key = $instance_config['key'];
            $this->consumer_secret = $instance_config['secret'];
        } else {
            // Se la configurazione non esiste, lancia un'eccezione per bloccare il processo
            throw new Exception("Configurazione WooCommerce non trovata per l'istanza: " . $instance_identifier);
        }
    }
    
    /**
     * Recupera i dettagli di un singolo ordine. La logica interna non cambia.
     * @param int $order_id L'ID dell'ordine di WooCommerce.
     * @return array|null
     */
    public function getOrderById($order_id) {
        $url = $this->wc_url . '/' . $order_id;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->consumer_key . ":" . $this->consumer_secret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200) {
            return json_decode($response, true);
        } else {
            return null;
        }
    }
}
?>