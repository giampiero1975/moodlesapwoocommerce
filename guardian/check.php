<?php
// guardian/check.php
// Firewall Modulo Indipendente Guardiano SAP

if (!defined('PROJECT_ROOT_PATH')) {
    define('PROJECT_ROOT_PATH', dirname(__DIR__) . '/');
}

date_default_timezone_set('Europe/Rome');

require_once PROJECT_ROOT_PATH . 'Model/SapDiagnosticHandler.php';

$sapHandler = new SapDiagnosticHandler();
$stats = $sapHandler->getCombinedSystemHealth();
// La logica di exit viene gestita esplicitamente nel file chiamante per massima chiarezza.
?>
