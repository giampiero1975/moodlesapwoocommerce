<?php
// Configurazione database
$host = 'localhost';
$db = 'mdlapps_moodleadmin';
$user = 'root';
$pass = '';

define('PAYPAL_SEND_EMAIL_NOTIFICATIONS', false);

// Connessione al database
$conn = new mysqli($host, $user, $pass, $db);

// Verifica connessione
if ($conn->connect_error) {
    die("Connessione fallita: " . $conn->connect_error);
}
?>
