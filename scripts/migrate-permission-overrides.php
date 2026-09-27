<?php
/**
 * RBAC izin override'lari icin tablo ekler.
 *
 * Izinler varsayilan olarak includes/permissions.php icindeki qmsPermissions()
 * sabit kaynagindan gelir. Super admin, Izinler (RBAC) sayfasindan tek tek
 * izinleri ac/kapa yapabilmek icin bu tabloya override kaydedilir.
 * Super admin rolu her zaman varsayilan izinlere sahip olur (override uygulanmaz)
 * boylece kendini sisteme kilitleyemez.
 */
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS permission_overrides (
        action VARCHAR(80)  NOT NULL,
        role   VARCHAR(40)  NOT NULL,
        allowed TINYINT(1)  NOT NULL DEFAULT 1,
        PRIMARY KEY (action, role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

echo "permission_overrides tablosu hazir." . PHP_EOL;
