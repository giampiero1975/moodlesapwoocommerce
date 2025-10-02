<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
include 'connect.php';
include 'email.php';

class PayPal
{

    private $clientId;

    private $clientSecret;

    private $apiBase = "https://api-m.paypal.com";

    // produzione
    # private $apiBase = "https://api-m.sandbox.paypal.com"; // sviluppo
    private $accessToken;

    public function __construct($clientId, $clientSecret)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->accessToken = $this->getAccessToken();
        echo "<br>Token: " . $this->accessToken . "<br>";
    }

    private function getAccessToken()
    {
        $url = $this->apiBase . "/v1/oauth2/token";
        $headers = [
            "Accept: application/json",
            "Accept-Language: en_US"
        ];
        $postFields = "grant_type=client_credentials";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERPWD, $this->clientId . ":" . $this->clientSecret);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (! $response) {
            die("cURL Error: " . curl_error($ch));
        }

        if ($httpStatus !== 200 && $httpStatus !== 201) {
            echo "HTTP Status Code: $httpStatus\n";
            echo "Response: " . $response . "\n";
            die("Authentication Failed");
        }

        $result = json_decode($response, true);
        curl_close($ch);

        # print_r($result);
        return $result['access_token'];
    }

    // Recupera la lista dei pagamenti ricevuti
    public function getReceivedPayments($startDate, $endDate)
    {
        $url = $this->apiBase . "/v1/reporting/transactions?start_date=" . $startDate . "&end_date=" . $endDate . "&fields=all&transaction_type=T0006&transaction_status=S";
        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer " . $this->accessToken
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (! $response) {
            die("cURL Error: " . curl_error($ch));
        }

        if ($httpStatus !== 200) {
            echo "HTTP Status Code: $httpStatus\n";
            echo "Response: " . $response . "\n";
            die("Failed to fetch received payments");
        }

        $data = json_decode($response, true);
        # ####################################################
        foreach ($data['transaction_details'] as $transaction) {
            $payerInfo = $transaction['payer_info'] ?? [];
            $transactionInfo = $transaction['transaction_info'] ?? [];
            $cartInfo = $transaction['cart_info']['item_details'][0] ?? []; // Primo articolo acquistato

            $payerInfo = $transaction['payer_info'] ?? [];
            $transactionInfo = $transaction['transaction_info'] ?? [];
            $cartInfo = $transaction['cart_info']['item_details'][0] ?? [];
            $shippingInfo = $transaction['shipping_info'] ?? [];

            $fullPhone = isset($payerInfo['phone_number']) ? '+' . ($payerInfo['phone_number']['country_code'] ?? '') . ' ' . ($payerInfo['phone_number']['national_number'] ?? '') : 'N/A';

            if ($transactionInfo['transaction_amount']['value'] <= 0)
                continue;

            # echo "<pre>";
            # print_r($transaction);
            # print_r($shippingInfo);
            # print_r($payerInfo);
            # print_r($cartInfo);
            # exit;
            $date = new DateTime($transactionInfo['transaction_initiation_date']);
            $formattedDate = $date->format('Y-m-d H:i:s');
            $result[] = [
                'transaction_id' => $transactionInfo['transaction_id'] ?? 'N/A',
                'transaction_date' => $formattedDate,
                'paying_name' => $payerInfo['payer_name']['alternate_full_name'] ?? 'N/A',
                'paying_email' => $payerInfo['email_address'] ?? 'N/A',
                'paying_phone' => $fullPhone,
                'paying_nat' => $payerInfo['country_code'] ?? 'N/A',
                'paying_account' => $payerInfo['account_id'] ?? 'N/A',
                'Stato Account PayPal' => $payerInfo['payer_status'] === 'Y' ? 'Verificato' : 'Non verificato',
                'Stato Indirizzo' => $payerInfo['address_status'] === 'Y' ? 'Confermato' : 'Non confermato',

                'billing_address' => $shippingInfo['address']['line1'] ?? 'N/A',
                'billing_city' => $shippingInfo['address']['city'] ?? 'N/A',
                'billing_prov' => $shippingInfo['address']['state'] ?? 'N/A',
                'billing_postalcode' => $shippingInfo['address']['postal_code'] ?? 'N/A',

                'Indirizzo di Spedizione' => $shippingInfo['name'] ?? 'N/A',
                'amount' => ($transactionInfo['transaction_amount']['value'] ?? '0.00'),
                'fee_amount' => ($transactionInfo['fee_amount']['value'] ?? '0.00'),
                'Stato Transazione' => $transactionInfo['transaction_status'] ?? 'N/A',
                'item_purchased' => ($cartInfo['item_name'] ?? 'N/A')
                # 'Articolo Acquistato' => ($cartInfo['item_name'] ?? 'N/A') . ' (' . ($cartInfo['item_quantity'] ?? '1') . ')',
                # 'Descrizione Articolo' => $cartInfo['item_description'] ?? 'N/A',
            ];
        }
        # #####################################################
        return $result ?? [];
    }
}

// Inserisci le tue credenziali
$clientId = "AaKMyL45nw0_oFMv3xkJV72Uw7bk7DDCUkIgDyAGaY4g1gyw5WwSAG8meH8fXVeNmYzZ1YQM3FoMNG9j";
$secretId = "EB7xdC1tjFaR86wNSItX6U5axnpn4Mnijr2qjRZF1tkoQYHJRB7s0zc-lDPet4YgzLJSfauNudaZKyHH";

// Imposta un intervallo di date per i pagamenti ricevuti
// data odierna 24h
#$startDate = "2025-02-01T00:00:00Z";
#$endDate = "2025-02-28T23:59:59Z";

$startDate = date("Y-m-d")."T00:00:00Z";
$endDate = date("Y-m-d")."T23:59:59Z";

$paypal = new PayPal($clientId, $secretId);

// Recupera i pagamenti ricevuti
$receivedPayments = $paypal->getReceivedPayments($startDate, $endDate);
// Mostra i pagamenti ricevuti
/*
echo "<pre>";
print_r($receivedPayments);
echo "</pre>";
*/
if (empty($receivedPayments))
    exit();

// Ciclo su tutte le transazioni ricevute
foreach ($receivedPayments as $tx) {
    $transaction_id = $tx['transaction_id'];
    $date = new DateTime($tx['transaction_date']);
    $formatted_date = $date->format('Y-m-d H:i:s');

    // Verifica se la transazione esiste già
    $check_sql = "SELECT id FROM results WHERE transaction_id = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("s", $transaction_id);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 0) {
        // Inserimento della nuova transazione
        $insert_sql = "INSERT INTO results (
            transaction_id, date_transaction, paying_name, paying_email, paying_phone,
            paying_nat, paying_account, billing_address, billing_city, billing_prov,
            billing_postalcode, amount, fee_amount, item_purchased
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("sssssssssssdss", $transaction_id, $formatted_date, $tx['paying_name'], $tx['paying_email'], $tx['paying_phone'], $tx['paying_nat'], $tx['paying_account'], $tx['billing_address'], $tx['billing_city'], $tx['billing_prov'], $tx['billing_postalcode'], $tx['amount'], $tx['fee_amount'], $tx['item_purchased']);

        if ($insert_stmt->execute()) {
            echo "Nuovo pagamento salvato correttamente per ID: $transaction_id<br>";
            sendNotificationEmail($tx); // 📧 Invio email per la nuova transazione
        } else {
            echo "Errore durante il salvataggio della transazione $transaction_id: " . $conn->error;
        }
    } else {
        echo "Transazione già registrata: $transaction_id<br>";
    }

    $stmt->close();
}
$conn->close();
?>
