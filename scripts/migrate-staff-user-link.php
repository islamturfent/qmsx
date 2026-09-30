<?php
/**
 * Personel -> Kullanıcı bağlantısı.
 *
 * `staff_members` tablosuna seçimlik `user_id` (kullanıcı hesabı) sütunu ekler.
 * Eğitim katılımcısı bir personeli tamamlayınca yetkinlik kaydının güncellenmesi
 * bu bağlantıyı kullanır (email/ad-soyad eşleşmesi yedek olarak denenir).
 */
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

$cols = $pdo->query('SHOW COLUMNS FROM staff_members')->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('user_id', $cols, true)) {
    $pdo->exec('ALTER TABLE staff_members ADD COLUMN user_id INT UNSIGNED NULL AFTER company_id');
}

echo "staff_members.user_id eklendi." . PHP_EOL;
