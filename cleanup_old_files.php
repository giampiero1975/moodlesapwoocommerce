<?php
date_default_timezone_set('Europe/Rome');

if (!defined('PROJECT_ROOT_PATH')) {
    define('PROJECT_ROOT_PATH', __DIR__ . DIRECTORY_SEPARATOR);
}

if (!function_exists('runOldFilesCleanup')) {
    function runOldFilesCleanup(bool $confirm = false, int $retentionDays = 60, int $maxPrintedRows = 200): array
    {
        $dryRun = !$confirm;
        $cutoffTimestamp = strtotime('-' . $retentionDays . ' days');
        $output = '';

        $targets = [
            [
                'label' => 'log',
                'directory' => PROJECT_ROOT_PATH . 'logs',
                'extension' => 'log',
            ],
            [
                'label' => 'pdf fatture',
                'directory' => PROJECT_ROOT_PATH . 'fatture',
                'extension' => 'pdf',
            ],
        ];

        $totals = [
            'scanned' => 0,
            'matched' => 0,
            'deleted' => 0,
            'errors' => 0,
            'bytes' => 0,
            'printed' => 0,
        ];

        $output .= "Pulizia file vecchi moodlesapwoocommerce\n";
        $output .= "Retention: " . $retentionDays . " giorni\n";
        $output .= "Soglia: " . date('Y-m-d H:i:s', $cutoffTimestamp) . "\n";
        $output .= "Modalita: " . ($dryRun ? 'DRY RUN (nessun file cancellato)' : 'CONFERMATA (cancellazione attiva)') . "\n\n";

        foreach ($targets as $target) {
            cleanupOldFilesDirectory($target, $cutoffTimestamp, $dryRun, $maxPrintedRows, $totals, $output);
        }

        $output .= "\nRiepilogo\n";
        $output .= "File controllati: " . $totals['scanned'] . "\n";
        $output .= "File oltre soglia: " . $totals['matched'] . "\n";
        $output .= ($dryRun ? "File cancellabili: " : "File cancellati: ") . $totals['deleted'] . "\n";
        $output .= "Spazio stimato/liberato: " . cleanupFormatBytes($totals['bytes']) . "\n";
        $output .= "Errori: " . $totals['errors'] . "\n";

        return [
            'ok' => $totals['errors'] === 0,
            'dry_run' => $dryRun,
            'totals' => $totals,
            'output' => $output,
        ];
    }

    function cleanupOldFilesDirectory(array $target, int $cutoffTimestamp, bool $dryRun, int $maxPrintedRows, array &$totals, string &$output): void
    {
        $directory = $target['directory'];
        $extension = strtolower($target['extension']);
        $label = $target['label'];

        $basePath = realpath($directory);
        if ($basePath === false || !is_dir($basePath)) {
            $output .= "Cartella non trovata: " . $directory . "\n";
            return;
        }

        $output .= "Cartella " . $label . ": " . $basePath . "\n";

        $items = scandir($basePath);
        if ($items === false) {
            $output .= "  ERRORE: impossibile leggere la cartella\n\n";
            $totals['errors']++;
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $filePath = $basePath . DIRECTORY_SEPARATOR . $item;
            if (!is_file($filePath)) {
                continue;
            }

            $totals['scanned']++;

            if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== $extension) {
                continue;
            }

            $mtime = filemtime($filePath);
            if ($mtime === false || $mtime >= $cutoffTimestamp) {
                continue;
            }

            $realFilePath = realpath($filePath);
            if ($realFilePath === false || strpos($realFilePath, $basePath . DIRECTORY_SEPARATOR) !== 0) {
                $output .= "  SKIP sicurezza: " . $item . "\n";
                $totals['errors']++;
                continue;
            }

            $fileSize = filesize($realFilePath);
            if ($fileSize === false) {
                $fileSize = 0;
            }

            $totals['matched']++;
            $totals['bytes'] += $fileSize;

            if (!$dryRun && !unlink($realFilePath)) {
                $output .= "  ERRORE cancellazione: " . $item . "\n";
                $totals['errors']++;
                continue;
            }

            $totals['deleted']++;
            if ($totals['printed'] < $maxPrintedRows) {
                $output .= "  " . ($dryRun ? 'cancellabile' : 'cancellato') . ": " . $item . " (" . date('Y-m-d H:i:s', $mtime) . ", " . cleanupFormatBytes($fileSize) . ")\n";
                $totals['printed']++;
            }
        }

        $output .= "\n";
    }

    function cleanupFormatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = $bytes;
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value = $value / 1024;
            $unitIndex++;
        }

        return round($value, 2) . ' ' . $units[$unitIndex];
    }
}

$scriptFile = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : false;
if ($scriptFile !== false && $scriptFile === __FILE__) {
    $isCli = (PHP_SAPI === 'cli');
    $confirm = $isCli
        ? in_array('--confirm', $argv, true)
        : (isset($_GET['confirm']) && $_GET['confirm'] === '1');

    if (!$isCli) {
        header('Content-Type: text/plain; charset=utf-8');
    }

    $result = runOldFilesCleanup($confirm);
    echo $result['output'];

    if (!$confirm) {
        echo "\nPer cancellare davvero:\n";
        echo $isCli
            ? "php cleanup_old_files.php --confirm\n"
            : "cleanup_old_files.php?confirm=1\n";
    }
}
