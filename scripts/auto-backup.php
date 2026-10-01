<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Otomatik veritabani yedegi (cli / zamanlanmis gorev).
 * - Sistem ayari `auto_backup_enabled` kapaliysa cikar.
 * - Son yedekten bu yana `auto_backup_interval_hours` gecmediyse atlar.
 * - Yedegi `storage/backups/` altina .sql olarak yazar.
 * - Yalnizca son `auto_backup_retain` adet yedegi saklar.
 *
 * Windows Task Scheduler icin: php -f "C:\xampp\htdocs\qmsx\scripts\auto-backup.php"
 * (ornek gunde 1 kez veya saatlik; interval script icinde kontrol edilir).
 */

require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/includes/settings-functions.php';

$enabled = qmsSetting('auto_backup_enabled', '0') === '1';
if (!$enabled) {
    echo "Auto backup disabled.\n";
    exit(0);
}

$intervalHours = max(1, (int) qmsSetting('auto_backup_interval_hours', '24'));
$retain = max(1, (int) qmsSetting('auto_backup_retain', '7'));

$backupDir = dirname(__DIR__) . '/storage/backups';
if (!is_dir($backupDir)) { @mkdir($backupDir, 0775, true); }

// Son yedekten bu yana gecen sure.
$latest = 0;
foreach (glob($backupDir . '/backup-*.sql') as $f) { $latest = max($latest, filemtime($f)); }
if ($latest > 0 && (time() - $latest) < $intervalHours * 3600) {
    echo "Last backup too recent; skipping.\n";
    exit(0);
}

// mysqldump yolu.
$dumpPath = '';
foreach (['C:/xampp/mysql/bin/mysqldump.exe', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump'] as $candidate) {
    if (is_file($candidate)) { $dumpPath = $candidate; break; }
}
if ($dumpPath === '') { echo "mysqldump not found.\n"; exit(1); }

$cmd = escapeshellarg($dumpPath)
    . ' --host=' . escapeshellarg($host ?? 'localhost')
    . ' --user=' . escapeshellarg($username ?? 'root')
    . ($password !== '' ? ' --password=' . escapeshellarg((string) $password) : '')
    . ' ' . escapeshellarg((string) $dbname);

$out = shell_exec($cmd . ' 2>&1');
if ($out === null || trim((string) $out) === '') { echo "Backup failed (empty output).\n"; exit(1); }

$name = 'backup-' . date('Ymd-His') . '.sql';
file_put_contents($backupDir . '/' . $name, $out);
echo "Backup written: $name\n";

// Retain: en yeni N yedegi tut, eskiyi sil.
$files = glob($backupDir . '/backup-*.sql');
usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
foreach (array_slice($files, $retain) as $old) { @unlink($old); }
echo "Retained latest $retain backups.\n";
