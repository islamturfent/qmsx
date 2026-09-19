<?php
/**
 * Denetci ve sirket kullanicisi rolleri icin gerekli baglantilar.
 *
 * Idempotent: yalnizca eksik kolonlari ve indeksleri ekler, mevcut kayitlara
 * dokunmaz.
 *
 * - users.company_id      : sirket kullanicisi hesabinin bagli oldugu sirket
 * - auditors.user_id      : denetci kaydinin varsa giris hesabi
 * - audits.auditor_id     : denetimin atandigi denetci
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/database.php';

function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column"
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    if ((int) $stmt->fetchColumn() > 0) {
        return false;
    }
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    return true;
}

function addIndexIfMissing(PDO $pdo, string $table, string $index, string $definition): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index"
    );
    $stmt->execute(['table' => $table, 'index' => $index]);
    if ((int) $stmt->fetchColumn() > 0) {
        return false;
    }
    $pdo->exec("ALTER TABLE $table ADD $definition");
    return true;
}

$added = [];

// 1) Sirket kullanicisi -> sirket bagi
if (addColumnIfMissing($pdo, 'users', 'company_id', 'INT DEFAULT NULL')) {
    $added[] = 'users.company_id';
    $pdo->exec("ALTER TABLE users ADD CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL");
    addIndexIfMissing($pdo, 'users', 'idx_users_company', 'INDEX idx_users_company (company_id)');
}

// 2) Denetci kaydi -> giris hesabi bagi
if (addColumnIfMissing($pdo, 'auditors', 'user_id', 'INT DEFAULT NULL')) {
    $added[] = 'auditors.user_id';
    $pdo->exec("ALTER TABLE auditors ADD CONSTRAINT fk_auditors_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
    addIndexIfMissing($pdo, 'auditors', 'idx_auditors_user', 'INDEX idx_auditors_user (user_id)');
}

// 3) Denetim -> atanmis denetci
if (addColumnIfMissing($pdo, 'audits', 'auditor_id', 'INT DEFAULT NULL')) {
    $added[] = 'audits.auditor_id';
    $pdo->exec("ALTER TABLE audits ADD CONSTRAINT fk_audits_auditor FOREIGN KEY (auditor_id) REFERENCES auditors(id) ON DELETE SET NULL");
    addIndexIfMissing($pdo, 'audits', 'idx_audits_auditor', 'INDEX idx_audits_auditor (auditor_id)');
}

echo 'Eklenen alanlar: ' . ($added ? implode(', ', $added) : 'yok (zaten tam)') . PHP_EOL;
echo "Mevcut kayitlar korundu." . PHP_EOL;
