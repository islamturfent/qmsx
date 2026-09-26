<?php

declare(strict_types=1);

/**
 * Müşteri teslimat performans kartı modulu yardimcilari.
 *
 * Müşteri başına dönemsel (YYYY-MM) teslimat istatistikleri: toplam sipariş,
 * zamanında teslim, teslim edilen miktar, reddedilen miktar. Zamanında teslim
 * orani turetilir. Kapsam tek kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';

/**
 * Kapsam içindeki kayitlar (dönem en yeni önce).
 * @param string $periodFilter '' veya YYYY-MM
 */
function qmsDeliveryList(PDO $pdo, int $userId, string $role, string $periodFilter = ''): array
{
    $scope = qmsCompanyScope('d.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $sql = 'SELECT d.*, c.company_name
            FROM delivery_performance d
            INNER JOIN companies c ON c.id = d.company_id
            WHERE d.active = 1' . $scope['sql'];
    $params = $scope['params'];
    if (preg_match('/^\d{4}-\d{2}$/', (string) $periodFilter)) {
        $sql .= ' AND d.period = ?';
        $params[] = $periodFilter;
    }
    $sql .= ' ORDER BY d.period DESC, d.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Kapsam içindeki tek kayıt; bulunamazsa bos dizi. */
function qmsDeliveryFind(PDO $pdo, int $id, int $userId, string $role): array
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT * FROM delivery_performance WHERE id = ? AND active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** Yeni kayit ekler; @return int|null */
function qmsDeliveryAdd(PDO $pdo, array $data, int $userId, string $role): ?int
{
    $companyId = (int) ($data['company_id'] ?? 0);
    $scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT companies.id FROM companies WHERE companies.id = ? AND companies.active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$companyId], $scope['params']));
    if (!$stmt->fetchColumn()) {
        return null;
    }
    $customer = trim((string) ($data['customer_name'] ?? ''));
    $period = (string) ($data['period'] ?? '');
    if ($customer === '' || !preg_match('/^\d{4}-\d{2}$/', $period)) {
        return null;
    }
    $ins = $pdo->prepare('INSERT INTO delivery_performance
        (company_id, customer_name, period, orders_total, on_time_orders, quantity_delivered, quantity_rejected, notes, active)
        VALUES (?,?,?,?,?,?,?,?,1)');
    $ins->execute([
        $companyId,
        mb_substr($customer, 0, 190),
        $period,
        max(0, (int) ($data['orders_total'] ?? 0)),
        max(0, (int) ($data['on_time_orders'] ?? 0)),
        max(0, (int) ($data['quantity_delivered'] ?? 0)),
        max(0, (int) ($data['quantity_rejected'] ?? 0)),
        trim((string) ($data['notes'] ?? '')) !== '' ? mb_substr((string) $data['notes'], 0, 4000) : null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Kaydi gunceller (kapsam içinde olmali). */
function qmsDeliveryUpdate(PDO $pdo, int $id, array $data, int $userId, string $role): bool
{
    if (!qmsDeliveryFind($pdo, $id, $userId, $role)) {
        return false;
    }
    $customer = trim((string) ($data['customer_name'] ?? ''));
    $period = (string) ($data['period'] ?? '');
    if ($customer === '' || !preg_match('/^\d{4}-\d{2}$/', $period)) {
        return false;
    }
    $pdo->prepare('UPDATE delivery_performance SET
        customer_name=?, period=?, orders_total=?, on_time_orders=?, quantity_delivered=?, quantity_rejected=?, notes=? WHERE id=?')->execute([
        mb_substr($customer, 0, 190),
        $period,
        max(0, (int) ($data['orders_total'] ?? 0)),
        max(0, (int) ($data['on_time_orders'] ?? 0)),
        max(0, (int) ($data['quantity_delivered'] ?? 0)),
        max(0, (int) ($data['quantity_rejected'] ?? 0)),
        trim((string) ($data['notes'] ?? '')) !== '' ? mb_substr((string) $data['notes'], 0, 4000) : null,
        $id,
    ]);
    return true;
}

/** Kaydi siler (aktif=0), kapsam içinde olmali. */
function qmsDeliveryDelete(PDO $pdo, int $id, int $userId, string $role): bool
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('UPDATE delivery_performance SET active = 0 WHERE id = ?' . $scope['sql']);
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->rowCount() > 0;
}

/** Zamaninda teslim orani (%) sifirdaysa 0. */
function qmsDeliveryOnTimeRate(array $row): float
{
    $total = (int) $row['orders_total'];
    if ($total <= 0) {
        return 0.0;
    }
    return round(((int) $row['on_time_orders'] / $total) * 100, 1);
}

/**
 * Teslimat kaydina bagli varsa uygunsuzluk id'si; yoksa 0.
 * @param int $deliveryId delivery_performance.id
 */
function qmsDeliveryLinkedNonconformity(PDO $pdo, int $deliveryId): int
{
    $stmt = $pdo->prepare('SELECT id FROM nonconformities WHERE delivery_id = ? AND active = 1 ORDER BY id ASC LIMIT 1');
    $stmt->execute([$deliveryId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Red miktari > 0 olan bir teslimat kaydindan uygunsuzluk (ve ardindan CAPA)
 * olusturur. Kayit kapsam içinde ve red miktari pozitif olmalidir; tekrar
 * cagirildiginda mevcut uygunsuzlugu dondurur (idempotent).
 * @return int|null olusturulan uygunsuzluk id'si ya da basarisizlikta null
 */
function qmsDeliveryCreateNonconformity(PDO $pdo, int $deliveryId, int $userId, string $role): ?int
{
    $delivery = qmsDeliveryFind($pdo, $deliveryId, $userId, $role);
    if (!$delivery) {
        return null;
    }
    if ((int) $delivery['quantity_rejected'] <= 0) {
        return null;
    }
    $linked = qmsDeliveryLinkedNonconformity($pdo, $deliveryId);
    if ($linked > 0) {
        return $linked;
    }
    $customer = trim((string) ($delivery['customer_name'] ?? ''));
    $period = (string) ($delivery['period'] ?? '');
    $title = 'Teslimat reddi: ' . ($customer !== '' ? $customer : 'Müşteri') . ($period !== '' ? ' (' . $period . ')' : '');
    $description = 'Teslimat performans kaydında ' . (int) $delivery['quantity_rejected'] . ' adet reddedilen miktar kaydedildi.'
        . ($customer !== '' ? ' Müşteri: ' . $customer : '');
    $insert = $pdo->prepare('INSERT INTO nonconformities
        (company_id, audit_id, source, title, description, severity, status, delivery_id, active)
        VALUES (?, NULL, \'delivery\', ?, ?, \'major\', \'open\', ?, 1)');
    $insert->execute([
        (int) $delivery['company_id'],
        mb_substr($title, 0, 255),
        mb_substr($description, 0, 4000),
        $deliveryId,
    ]);
    return (int) $pdo->lastInsertId();
}
