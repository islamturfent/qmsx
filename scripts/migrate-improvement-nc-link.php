<?php
/**
 * İyileştirme Fırsatı -> Uygunsuzluk (NC) bağlantısı.
 *
 * `improvements` tablosuna seçimlik `linked_nc_id` (bağlı uygunsuzluk) sütunu
 * ekler. OFI uygulandığında mevcut bir NC'ye bağlanabilir veya ilgili CAPA'ya
 * yönlendirilebilir.
 */
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

$cols = $pdo->query('SHOW COLUMNS FROM improvements')->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('linked_nc_id', $cols, true)) {
    $pdo->exec('ALTER TABLE improvements ADD COLUMN linked_nc_id INT UNSIGNED NULL AFTER responsible');
}

echo "improvements.linked_nc_id eklendi." . PHP_EOL;
