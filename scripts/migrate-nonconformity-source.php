<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/database.php';

// Idempotent: source sutunu zaten varsa islem yapma.
$has = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'nonconformities' AND column_name = 'source'"
)->fetchColumn();

if ((int) $has > 0) {
    echo "nonconformities.source already present; nothing to do.\n";
    exit(0);
}

// 1) Mevcut FK'yi kaldir (audit_id'yi NULL yapabilmek icin).
$pdo->exec("ALTER TABLE nonconformities DROP FOREIGN KEY fk_nonconformities_audit");
// 2) audit_id NULL olabilir.
$pdo->exec("ALTER TABLE nonconformities MODIFY COLUMN audit_id int(11) NULL");
// 3) Kaynak sutunu ekle.
$pdo->exec("ALTER TABLE nonconformities ADD COLUMN source varchar(20) NOT NULL DEFAULT 'audit' AFTER audit_id");
// 4) FK'yi yeniden ekle; audit silinince kendi uygunsuzluklari cascade ile gider,
//    sikayet kaynaklilar NULL audit_id ile korunur.
$pdo->exec(
    "ALTER TABLE nonconformities
     ADD CONSTRAINT fk_nonconformities_audit FOREIGN KEY (audit_id)
     REFERENCES audits (id) ON DELETE CASCADE"
);

echo "nonconformities.audit_id -> nullable, source column added, FK re-applied.\n";
