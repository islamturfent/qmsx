<?php
/**
 * Coklu denetci atamasi icin baglanti tablosu.
 *
 * Bir denetime birden fazla denetci atanabildigi icin, onceki adimda eklenen
 * tek kolon (audits.auditor_id) yeterli degil. Bu kolon daha hicbir sorguda
 * kullanilmadigi ve bos oldugu icin kaldirilir; yerine audit_auditors gelir.
 *
 * Idempotent: tablo varsa yeniden olusturulmaz, kolon yoksa zaten atlanir.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/database.php';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS audit_auditors (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        audit_id INT NOT NULL,
        auditor_id INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_audit_auditor (audit_id, auditor_id),
        INDEX idx_audit_auditors_auditor (auditor_id),
        CONSTRAINT fk_audit_auditors_audit FOREIGN KEY (audit_id) REFERENCES audits(id) ON DELETE CASCADE,
        CONSTRAINT fk_audit_auditors_auditor FOREIGN KEY (auditor_id) REFERENCES auditors(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// Artik kullanilmayan tek-kolon bagi kaldir (yalnizca varsa).
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audits' AND COLUMN_NAME = 'auditor_id'"
);
$stmt->execute();
$dropped = false;
if ((int) $stmt->fetchColumn() > 0) {
    $pdo->exec("ALTER TABLE audits DROP FOREIGN KEY fk_audits_auditor");
    $pdo->exec("ALTER TABLE audits DROP INDEX idx_audits_auditor");
    $pdo->exec("ALTER TABLE audits DROP COLUMN auditor_id");
    $dropped = true;
}

echo 'audit_auditors tablosu hazir.' . PHP_EOL;
echo 'audits.auditor_id kolonu: ' . ($dropped ? 'kaldirildi (yerini audit_auditors aldi)' : 'zaten yok') . PHP_EOL;
echo "Mevcut kayitlar korundu." . PHP_EOL;
