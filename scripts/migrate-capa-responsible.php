<?php
/**
 * Duzeltici faaliyet icin gercek sorumlu bagi.
 *
 * responsible_person serbest metin oldugu icin kime bildirim gidecegi belli
 * degildi. Bu alan, sorumlunun sistem kullanicisi olmasi durumunda kullanilir;
 * serbest metin alani disaridan gelen kisiler icin korunur.
 *
 * Idempotent: kolon ve kisit yalnizca yoksa eklenir.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/database.php';

$columnExists = function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column"
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
};

$constraintExists = function (string $name) use ($pdo): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = :name"
    );
    $stmt->execute(['name' => $name]);
    return (int) $stmt->fetchColumn() > 0;
};

$added = [];

if (!$columnExists('corrective_actions', 'responsible_user_id')) {
    $pdo->exec("ALTER TABLE corrective_actions ADD COLUMN responsible_user_id INT DEFAULT NULL");
    $added[] = 'corrective_actions.responsible_user_id';
}

if (!$constraintExists('fk_capa_responsible_user')) {
    $pdo->exec(
        "ALTER TABLE corrective_actions
         ADD CONSTRAINT fk_capa_responsible_user FOREIGN KEY (responsible_user_id)
         REFERENCES users(id) ON DELETE SET NULL"
    );
    $added[] = 'fk_capa_responsible_user';
}

echo 'Eklenenler: ' . ($added ? implode(', ', $added) : 'yok (zaten tam)') . PHP_EOL;
echo "Mevcut kayitlar korundu." . PHP_EOL;
