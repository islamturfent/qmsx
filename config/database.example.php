<?php

/**
 * Ornek veritabani ayarlari.
 *
 * `config/database.php` ortama ozel oldugu ve kimlik bilgisi icerdigi icin
 * surum kontrolune dahil edilmez. Yeni bir kurulumda bu dosyayi
 * `config/database.php` olarak kopyalayip kendi degerlerinizi yazin.
 */

$host = "localhost";
$dbname = "qms";
$username = "root";
$password = "";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    die("Veritabanı bağlantı hatası: " . $e->getMessage());
}
