<?php
// Configurazione database
$host = 'dbmoodle.met.dmz';
$db = 'mdlapps_moodleadmin';
$user = 'moodle';
$pass = 'RmnPbT78';

// Connessione al database
$conn = new mysqli($host, $user, $pass, $db);

// Verifica connessione
if ($conn->connect_error) {
    die("Connessione fallita: " . $conn->connect_error);
}
?>
