<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!defined('PROJECT_ROOT_PATH')) {
    define('PROJECT_ROOT_PATH', dirname(__DIR__, 2) . '/');
}

include_once PROJECT_ROOT_PATH . 'Model/SapInvoiceHandler.php';
include_once PROJECT_ROOT_PATH . 'Model/SapDiagnosticHandler.php';

$sapHandler = new SapDiagnosticHandler();

// Gestione Azioni Rapide
$action_result = null;
if (isset($_POST['action'])) {
    if ($_POST['action'] === 'clean') {
        $action_result = ["msg" => "PULIZIA DB Eseguita", "res" => $sapHandler->runSqlCleanup()];
    } elseif ($_POST['action'] === 'reset') {
        $action_result = ["msg" => "RESET SAP Inviato", "res" => $sapHandler->runSqlReset()];
    } elseif ($_POST['action'] === 'iis_reset') {
        $action_result = ["msg" => "RESET IIS Inviato", "res" => $sapHandler->runIisReset(), "type" => "iis"];
    }
}

// Gestione Filtri
$filters = [
    'date' => $_GET['date'] ?? '',
    'result' => $_GET['result'] ?? 'Tutti'
];

$data = $sapHandler->getFullDiagnostics($filters);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SAP Monitor Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg-body: #f4f7f9;
            --card-bg: #ffffff;
            --text-main: #2c3e50;
            --text-muted: #7f8c8d;
            --color-verde: #27ae60;
            --color-giallo: #f39c12;
            --color-rosso: #e74c3c;
            --color-offline: #34495e;
            --primary: #3498db;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        h1 { margin: 0; font-size: 24px; color: var(--primary); }

        /* Uptime Box */
        .uptime-box {
            background: #fff;
            border-left: 5px solid var(--primary);
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .uptime-info { display: flex; align-items: center; gap: 15px; }
        .uptime-label { font-weight: bold; color: var(--text-muted); font-size: 13px; text-transform: uppercase; }
        .uptime-value { font-family: monospace; font-size: 14px; background: #f8f9fa; padding: 4px 8px; border-radius: 4px; }

        /* Grid */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        .card-title { font-weight: bold; font-size: 18px; }

        /* Status Badge */
        .badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            color: white;
            text-transform: uppercase;
        }
        .badge-verde { background: var(--color-verde); }
        .badge-giallo { background: var(--color-giallo); }
        .badge-rosso { background: var(--color-rosso); }
        .badge-offline { background: var(--color-offline); }

        .stat-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .stat-label { color: var(--text-muted); }
        .stat-value { font-weight: 600; }

        /* Process Status Aggregation */
        .process-groups {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            width: 100%;
        }
        .process-group-card {
            background: #fefefe;
            border-left: 4px solid var(--primary);
            padding: 15px;
            border-radius: 8px;
            flex: 1;
            min-width: 280px;
            max-height: 220px;
            overflow-y: auto;
            border: 1px solid #f1f1f1;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.02);
        }
        .process-group-header {
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            color: var(--text-dark);
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 5px;
        }
        .process-instance {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            font-size: 13px;
        }
        .name-badge {
            background: #e7f0fd;
            color: #0d6efd;
            padding: 3px 8px;
            border-radius: 4px;
            font-weight: 500;
        }
        .time-badge {
            background: #f0f0f0;
            color: #6c757d;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
        }

        /* Actions */
        .actions-section {
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .actions-title { font-weight: bold; font-size: 16px; margin-right: 10px; }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            font-size: 13px;
        }
        .btn-clean { background: #f1c40f; color: #000; }
        .btn-clean:hover { background: #d4ac0d; }
        .btn-reset { background: #e74c3c; color: #fff; }
        .btn-reset:hover { background: #c0392b; }
        .btn-iis { background: #00bcd4; color: #fff; }
        .btn-iis:hover { background: #00acc1; }
        .btn-refresh { background: #95a5a6; color: #fff; }

        /* Guide Widget */
        .actions-guide {
            margin-left: auto;
            font-size: 11px;
            background: #fafafa;
            border: 1px solid #eaeaea;
            padding: 12px;
            border-radius: 8px;
            color: #666;
            max-width: 380px;
            box-shadow: inset 0 0 5px rgba(0,0,0,0.02);
        }
        .guide-item { display: flex; align-items: center; gap: 10px; margin-bottom: 5px; }
        .dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
        .dot-yellow { background: #f1c40f; }
        .dot-azure { background: #00bcd4; }
        .dot-red { background: #e74c3c; }

        /* Logs Table */
        .logs-container {
            background: #fff;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 13px;
        }
        th { text-align: left; padding: 12px; border-bottom: 2px solid #eee; color: var(--text-muted); }
        td { padding: 12px; border-bottom: 1px solid #f9f9f9; }
        tr:hover { background: #fcfcfc; }

        /* Color Association Tips */
        .card-tip {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 5px 10px;
            border-radius: 4px;
            margin-top: 15px;
            display: inline-block;
            letter-spacing: 0.5px;
        }
        .tip-yellow { background: #f1c40f1a; color: #d4ac0d; border: 1px solid #f1c40f; }
        .tip-azure { background: #00bcd41a; color: #00acc1; border: 1px solid #00bcd4; }
        .tip-red { background: #e74c3c1a; color: #c0392b; border: 1px solid #e74c3c; }
        .tip-gray { background: #f8f9fa; color: #666; border: 1px solid #ddd; }
        
        .border-yellow { border-top: 5px solid #f1c40f !important; }
        .border-azure { border-top: 5px solid #00bcd4 !important; }
        .border-red { border-top: 5px solid #e74c3c !important; }
        .border-gray { border-top: 5px solid #ccc !important; }

        /* Auto Refresh Toggle */
        .toggle-container {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 40px;
            height: 20px;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 14px; width: 14px;
            left: 3px; bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider { background-color: var(--primary); }
        input:checked + .slider:before { transform: translateX(20px); }

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }

        @media (max-width: 600px) {
            header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .grid { grid-template-columns: 1fr; }
            .actions-section { flex-direction: column; align-items: flex-start; }
            .uptime-box { flex-direction: column; align-items: flex-start; gap: 10px; }
        }
    </style>
</head>
<body>

<div class="container">
    <header>
        <h1>💎 SAP Monitor Dashboard</h1>
        <div class="toggle-container">
            <span>Auto Refresh (60s)</span>
            <label class="switch">
                <input type="checkbox" id="autoRefreshToggle">
                <span class="slider"></span>
            </label>
            <a href="dashboard.php" class="btn btn-refresh" style="padding: 5px 10px;">Aggiorna Ora</a>
        </div>
    </header>

    <?php if ($action_result): ?>
    <div class="alert alert-success">
        <strong><?= $action_result['msg'] ?></strong>: <?= htmlspecialchars((string)$action_result['res']) ?>
        <?php if(isset($action_result['type']) && $action_result['type'] === 'iis'): ?>
            <br><small>💡 Il bridge web si sta riavviando. Attendi circa 10 secondi prima di rinfrescare la pagina.</small>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="background: #eef2f5; padding: 20px; border-radius: 8px; margin-bottom: 30px;">
        <h2 style="margin-top:0; color: #2c3e50; font-size: 18px; border-bottom: 2px solid #bdc3c7; padding-bottom: 10px;">
            🛡️ SETTORE "AZIONE" (Orchestratore)
        </h2>
        <p style="font-size: 13px; color: #7f8c8d; margin-bottom: 15px;">
            Questi sono gli unici valori reali utilizzati dal Guardiano per stabilire se fermare il batch e lanciare l'allarme WhatsApp.
        </p>

        <div class="grid">
            <!-- Card SAP SOAP -->
            <div class="card" style="border-top: 5px solid #9b59b6 !important;">
                <div class="card-header">
                    <span class="card-title">🔌 SAP DI-Server (SOAP)</span>
                    <?php 
                        $s_stato = 'OFFLINE'; $s_lbl = 'badge-offline';
                        if (isset($data['soap'])) {
                            $s_stato = $data['soap']['is_alive'] ? (($data['soap']['total_time_ms'] > 2000) ? 'ROSSO' : 'VERDE') : 'OFFLINE';
                            if ($s_stato == 'VERDE') $s_lbl = 'badge-verde';
                            elseif ($s_stato == 'ROSSO') $s_lbl = 'badge-rosso';
                        }
                    ?>
                    <span class="badge <?= $s_lbl ?>"><?= $s_stato ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Connessione DI-Server:</span>
                    <span class="stat-value"><?= (isset($data['soap']) && $data['soap']['is_alive']) ? 'Attivo' : 'Offline' ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Latenza Reale XML:</span>
                    <span class="stat-value"><?= $data['soap']['total_time_ms'] ?? 0 ?> ms</span>
                </div>
                <div class="card-tip" style="background:#9b59b61a; color:#8e44ad; border:1px solid #9b59b6;">💡 Veritiero indicatore. Se > 2000ms scatta l'allarme bloccante.</div>
            </div>

            <!-- Card Database -->
            <div class="card border-yellow">
                <div class="card-header">
                    <span class="card-title">🗄 Database (MSSQL)</span>
                    <?php 
                        $d_stato = $data['db']['stato'];
                        if ($d_stato == 'VERDE') $d_lbl = 'badge-verde';
                        elseif ($d_stato == 'GIALLO') $d_lbl = 'badge-giallo';
                        elseif ($d_stato == 'ROSSO') $d_lbl = 'badge-rosso';
                        else $d_lbl = 'badge-offline';
                    ?>
                    <span class="badge <?= $d_lbl ?>"><?= $d_stato ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Deep Test (Table OINV):</span>
                    <span class="stat-value"><?= $data['db']['deep_test'] ? '🟢 Passato' : '🔴 Fallito' ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Tempo Query Test:</span>
                    <span class="stat-value"><?= $data['db']['query_time_ms'] ?> ms</span>
                </div>
                <div class="card-tip tip-yellow">💡 Veritiero indicatore di blocco intermedio. Se > 200ms scatta il GIALLO e pulisce le query pendenti. Se la "Query Test" va in timeout (2500ms) scatta il ROSSO.</div>
            </div>
        </div>
    </div>

    <!-- SEZIONE 2: TELEMETRIA E DIAGNOSTICA -->
    <div style="background: #f8fbff; padding: 20px; border-radius: 8px; margin-bottom: 30px;">
        <h2 style="margin-top:0; color: #2c3e50; font-size: 18px; border-bottom: 2px solid #bdc3c7; padding-bottom: 10px;">
            📊 SETTORE "TELEMETRIA" (Dashboard Multidimensionale)
        </h2>
        <p style="font-size: 13px; color: #7f8c8d; margin-bottom: 15px;">
            Dati informativi e panoramica dei processi per agevolare l'indagine. L'Orchestratore non vi agisce direttamente.
        </p>

        <!-- Sezione Uptime Processi -->
        <div class="uptime-box" style="flex-direction: column; align-items: flex-start; padding: 20px;">
            <div style="width: 100%; display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <span class="uptime-label" style="font-size: 16px; font-weight: bold; color: var(--secondary);">📡 Processi Windows Real-time</span>
                <div class="btn-group">
                    <?php 
                        $hasSap = !empty($data['uptime']['SAP']['instances']);
                        $hasIis = !empty($data['uptime']['IIS']['instances']);
                    ?>
                    <span class="badge <?= $hasSap ? 'badge-verde' : 'badge-rosso' ?>">SAP B1: <?= $hasSap ? 'ACTIVE' : 'OFFLINE' ?></span>
                    <span class="badge <?= $hasIis ? 'badge-verde' : 'badge-rosso' ?>">IIS WWP: <?= $hasIis ? 'ACTIVE' : 'OFFLINE' ?></span>
                </div>
            </div>

            <?php if (!is_array($data['uptime']) || isset($data['uptime']['error'])): ?>
                <div class="alert alert-danger" style="width: 100%;">
                    <?= htmlspecialchars($data['uptime']['error'] ?? 'Errore nel caricamento dei processi') ?>
                </div>
            <?php else: ?>
                <div class="process-groups">
                    <?php foreach(['SAP', 'IIS'] as $type): 
                        $group = $data['uptime'][$type]; 
                        $bClass = ($type === 'SAP') ? 'border-red' : 'border-azure';
                        $tClass = ($type === 'SAP') ? 'tip-red' : 'tip-azure';
                        $tMsg = ($type === 'SAP') ? 'In caso di errore usa il tasto ROSSO' : 'In caso di errore usa il tasto AZZURRO';
                    ?>
                    <div class="process-group-card <?= $bClass ?>">
                        <div class="process-group-header">
                            <span><?= $group['icon'] ?></span>
                            <span><?= $group['label'] ?></span>
                            <small style="margin-left: auto; font-weight: normal; font-size: 10px; color: #999;">
                                (<?= count($group['instances']) ?> istanze)
                            </small>
                        </div>
                        <?php if (empty($group['instances'])): ?>
                            <div style="color: #dc3545; font-size: 12px; font-style: italic; padding: 10px 0;">Nessuna istanza rilevata</div>
                        <?php else: ?>
                            <?php foreach($group['instances'] as $inst): ?>
                            <div class="process-instance">
                                <span class="name-badge"><?= htmlspecialchars($inst['name']) ?></span>
                                <span class="time-badge"><?= htmlspecialchars($inst['start']) ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid" style="grid-template-columns: 1fr;">
            <!-- Card Web Server (Spinto fuori dall'orchestratore, solo telemetria) -->
            <div class="card border-azure">
                <div class="card-header">
                    <span class="card-title">🌐 SAP Web Gateway (IIS)</span>
                    <?php 
                        $h_stato = str_replace(array('🟢 ', '🟡 ', '🔴 ', '⚫ '), '', $data['http']['stato']);
                        $h_class = strtolower($h_stato);
                        if ($h_class == 'verde') $lbl = 'badge-verde';
                        elseif ($h_class == 'giallo') $lbl = 'badge-giallo';
                        elseif ($h_class == 'rosso') $lbl = 'badge-rosso';
                        else $lbl = 'badge-offline';
                    ?>
                    <span class="badge <?= $lbl ?>"><?= $h_stato ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">HTTP Endp:</span>
                    <span class="stat-value"><?= $data['http']['is_alive'] ? 'Attivo' : 'Offline' ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Latenza Risposta:</span>
                    <span class="stat-value"><?= $data['http']['total_time_ms'] ?> ms</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Grafico Latenza -->
    <div class="card" style="margin-bottom: 30px;">
        <div class="card-header">
            <span class="card-title">📉 Telemetria Real-Time (Ultimi 2 giorni - Cron-Check)</span>
        </div>
        <canvas id="latencyChart" height="100"></canvas>
    </div>

    <!-- Azioni Rapide -->
    <div class="actions-section">
        <span class="actions-title">⚡ Azioni Rapide:</span>
        <form method="post" style="display: flex; gap: 15px;">
            <button type="submit" name="action" value="clean" class="btn btn-clean">LANCIA CLEAN DB</button>
            <button type="submit" name="action" value="iis_reset" class="btn btn-iis">LANCIA RESET IIS</button>
            <button type="submit" name="action" value="reset" class="btn btn-reset">LANCIA RESET SAP</button>
        </form>

        <div class="actions-guide">
            <div class="guide-item"><span class="dot dot-yellow"></span> <b>GIALLO</b>: Se il riquadro <b>DATABASE</b> è rosso, premi questo.</div>
            <div class="guide-item"><span class="dot dot-azure"></span> <b>AZZURRO</b>: Se il riquadro <b>IIS WORKER PROCESSES</b> o <b>SAP WEB GATEWAY</b> è rosso, premi questo.</div>
            <div class="guide-item"><span class="dot dot-red"></span> <b>ROSSO</b>: Se il riquadro <b>SAP BUSINESS ONE</b> è rosso, premi questo.</div>
            <div style="margin-top: 8px; border-top: 1px solid #eee; padding-top: 5px; font-style: italic; font-size: 10px; color: #888;">
                💡 <b>Nota</b>: Se il Web Gateway è rosso, il server Windows non accetta connessioni web. Prova sempre prima l'AZZURRO.
            </div>
        </div>
    </div>

    <!-- Ultime Operazioni -->
    <div class="logs-container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin: 0;">📜 Ultime Operazioni</h3>
            <form method="get" style="display: flex; gap: 10px; align-items: center; font-size: 13px;">
                <label>Data:</label>
                <input type="date" name="date" value="<?= htmlspecialchars($filters['date']) ?>" style="padding: 4px; border-radius: 4px; border: 1px solid #ccc;">
                <label>Esito:</label>
                <select name="result" style="padding: 4px; border-radius: 4px; border: 1px solid #ccc;">
                    <option value="Tutti" <?= $filters['result'] == 'Tutti' ? 'selected' : '' ?>>Tutti</option>
                    <option value="Successo" <?= $filters['result'] == 'Successo' ? 'selected' : '' ?>>Successo</option>
                    <option value="Fallito" <?= $filters['result'] == 'Fallito' ? 'selected' : '' ?>>Fallito</option>
                </select>
                <button type="submit" class="btn btn-refresh" style="padding: 4px 10px;">Filtra</button>
                <?php if(!empty($filters['date']) || $filters['result'] !== 'Tutti'): ?>
                    <a href="dashboard.php" style="color: var(--color-rosso); text-decoration: none;">Reset</a>
                <?php endif; ?>
            </form>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Data/Ora</th>
                    <th>Azione</th>
                    <th>Ping Iniziale</th>
                    <th>Stato Finale</th>
                    <th>Esito</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data['logs'])): ?>
                    <tr><td colspan="5" style="text-align:center;">Nessuna operazione registrata.</td></tr>
                <?php else: ?>
                    <?php foreach ($data['logs'] as $log): ?>
                    <tr>
                        <td style="white-space:nowrap;"><?= $log['data_check'] ?? '-' ?></td>
                        <td><strong><?= htmlspecialchars($log['action']) ?></strong></td>
                        <td>
                            <span class="badge <?= stripos($log['ping_result'], 'VERDE')!==false ? 'badge-verde' : (stripos($log['ping_result'], 'GIALLO')!==false ? 'badge-giallo' : 'badge-rosso') ?>">
                                <?= $log['ping_result'] ?>
                            </span><br>
                            <small style="color: #666; font-size: 11px;">IIS: <?= round((float)($log['ping_delay'] ?? 0),0) ?>ms | DB: <?= round((float)($log['db_ping_delay'] ?? 0),0) ?>ms | SAP: <?= round((float)($log['soap_ping_delay'] ?? 0),0) ?>ms</small>
                        </td>
                        <td>
                            <?php if ($log['post_ping_result']): ?>
                                <span class="badge <?= stripos($log['post_ping_result'], 'VERDE')!==false ? 'badge-verde' : (stripos($log['post_ping_result'], 'GIALLO')!==false ? 'badge-giallo' : 'badge-rosso') ?>">
                                    <?= $log['post_ping_result'] ?>
                                </span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong style="color: <?= $log['result'] === 'COMPLETATO' ? 'var(--color-verde)' : 'var(--color-rosso)' ?>">
                                <?= $log['result'] ?>
                            </strong>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    // Logica Auto Refresh
    const refreshToggle = document.getElementById('autoRefreshToggle');
    let refreshInterval;

    // Carica stato da localStorage
    const autoRefreshEnabled = localStorage.getItem('sap_auto_refresh') === 'true';
    refreshToggle.checked = autoRefreshEnabled;

    function startRefresh() {
        refreshInterval = setInterval(() => {
            window.location.reload();
        }, 60000); // 60 secondi
    }

    function stopRefresh() {
        clearInterval(refreshInterval);
    }

    if (autoRefreshEnabled) {
        startRefresh();
    }

    refreshToggle.addEventListener('change', (e) => {
        localStorage.setItem('sap_auto_refresh', e.target.checked);
        if (e.target.checked) {
            startRefresh();
        } else {
            stopRefresh();
        }
    });

    // Rimuovi parametri POST al refresh per evitare reinvii multipli se l'utente preme F5
    if ( window.history.replaceState ) {
        window.history.replaceState( null, null, window.location.href );
    }

    // Rendering Grafico Latenza
    const ctx = document.getElementById('latencyChart').getContext('2d');
    const chartData = <?= json_encode($data['chart_logs']) ?>;
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartData.map(l => l.date),
            datasets: [
                {
                    label: 'IIS Web (ms)',
                    data: chartData.map(l => l.web),
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    tension: 0.4,
                    pointRadius: 3
                },
                {
                    label: 'Database SQL (ms)',
                    data: chartData.map(l => l.db),
                    borderColor: '#f1c40f',
                    backgroundColor: 'rgba(241, 196, 15, 0.1)',
                    tension: 0.4,
                    pointRadius: 3
                },
                {
                    label: 'SAP SOAP (ms)',
                    data: chartData.map(l => l.soap),
                    borderColor: '#9b59b6',
                    backgroundColor: 'rgba(155, 89, 182, 0.1)',
                    tension: 0.4,
                    pointRadius: 4,
                    yAxisID: 'y'
                }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: true } },
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'ms' } },
                x: { grid: { display: false } }
            }
        }
    });
</script>

</body>
</html>
