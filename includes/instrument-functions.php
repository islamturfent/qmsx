<?php

declare(strict_types=1);

/**
 * Kalibrasyon & Metroloji / Ölçü alet takvimi modulu yardimcilari.
 *
 * Ölçü aletleri icin aralik temelli kalibrasyon takvimi: son ve sonraki
 * kalibrasyon tarihi, aralik (ay), sorumlu, durum, konum.
 */

require_once __DIR__ . '/access.php';

const QMS_INSTRUMENT_STATUSES = ['active', 'out_of_service'];

/** Kapsam içindeki aletler (ad bazında sıralı). */
function qmsInstrumentList(PDO $pdo, int $userId, string $role, string $statusFilter = ''): array
{
    $scope = qmsCompanyScope('i.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $sql = 'SELECT i.*, c.company_name
            FROM instruments i
            INNER JOIN companies c ON c.id = i.company_id
            WHERE i.active = 1' . $scope['sql'];
    $params = $scope['params'];
    $filter = (string) $statusFilter;
    if ($filter === 'overdue') {
        $sql .= ' AND i.status = \'active\' AND i.next_calibration_date IS NOT NULL AND i.next_calibration_date < CURDATE()';
    } elseif (in_array($filter, QMS_INSTRUMENT_STATUSES, true)) {
        $sql .= ' AND i.status = ?';
        $params[] = $filter;
    }
    $sql .= ' ORDER BY i.name ASC, i.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Kapsam içindeki tek alet; bulunamazsa bos dizi. */
function qmsInstrumentFind(PDO $pdo, int $id, int $userId, string $role): array
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT * FROM instruments WHERE id = ? AND active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** Yeni alet ekler; @return int|null */
function qmsInstrumentAdd(PDO $pdo, array $data, int $userId, string $role): ?int
{
    $companyId = (int) ($data['company_id'] ?? 0);
    $scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT companies.id FROM companies WHERE companies.id = ? AND companies.active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$companyId], $scope['params']));
    if (!$stmt->fetchColumn()) {
        return null;
    }
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        return null;
    }
    $status = (string) ($data['status'] ?? 'active');
    if (!in_array($status, QMS_INSTRUMENT_STATUSES, true)) {
        $status = 'active';
    }
    $interval = max(1, (int) ($data['interval_months'] ?? 12));
    $ins = $pdo->prepare('INSERT INTO instruments
        (company_id, instrument_code, name, instrument_type, location, interval_months, last_calibration_date, next_calibration_date, responsible, status, notes, active, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?)');
    $ins->execute([
        $companyId,
        trim((string) ($data['instrument_code'] ?? '')) !== '' ? mb_substr(trim((string) $data['instrument_code']), 0, 40) : null,
        mb_substr($name, 0, 190),
        trim((string) ($data['instrument_type'] ?? '')) !== '' ? mb_substr(trim((string) $data['instrument_type']), 0, 120) : null,
        trim((string) ($data['location'] ?? '')) !== '' ? mb_substr(trim((string) $data['location']), 0, 190) : null,
        $interval,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['last_calibration_date'] ?? '')) ? (string) $data['last_calibration_date'] : null,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['next_calibration_date'] ?? '')) ? (string) $data['next_calibration_date'] : null,
        trim((string) ($data['responsible'] ?? '')) !== '' ? mb_substr(trim((string) $data['responsible']), 0, 180) : null,
        $status,
        trim((string) ($data['notes'] ?? '')) !== '' ? mb_substr((string) $data['notes'], 0, 4000) : null,
        $userId ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Aleti gunceller (kapsam içinde olmali). */
function qmsInstrumentUpdate(PDO $pdo, int $id, array $data, int $userId, string $role): bool
{
    if (!qmsInstrumentFind($pdo, $id, $userId, $role)) {
        return false;
    }
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        return false;
    }
    $status = (string) ($data['status'] ?? 'active');
    if (!in_array($status, QMS_INSTRUMENT_STATUSES, true)) {
        $status = 'active';
    }
    $interval = max(1, (int) ($data['interval_months'] ?? 12));
    $pdo->prepare('UPDATE instruments SET
        instrument_code=?, name=?, instrument_type=?, location=?, interval_months=?, last_calibration_date=?,
        next_calibration_date=?, responsible=?, status=?, notes=? WHERE id=?')->execute([
        trim((string) ($data['instrument_code'] ?? '')) !== '' ? mb_substr(trim((string) $data['instrument_code']), 0, 40) : null,
        mb_substr($name, 0, 190),
        trim((string) ($data['instrument_type'] ?? '')) !== '' ? mb_substr(trim((string) $data['instrument_type']), 0, 120) : null,
        trim((string) ($data['location'] ?? '')) !== '' ? mb_substr(trim((string) $data['location']), 0, 190) : null,
        $interval,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['last_calibration_date'] ?? '')) ? (string) $data['last_calibration_date'] : null,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['next_calibration_date'] ?? '')) ? (string) $data['next_calibration_date'] : null,
        trim((string) ($data['responsible'] ?? '')) !== '' ? mb_substr(trim((string) $data['responsible']), 0, 180) : null,
        $status,
        trim((string) ($data['notes'] ?? '')) !== '' ? mb_substr((string) $data['notes'], 0, 4000) : null,
        $id,
    ]);
    return true;
}

/**
 * Hizli "kalibre et": son kalibrasyon = bugun, sonraki = bugun + interval ay.
 * Kapsam içinde olmali.
 */
function qmsInstrumentCalibrate(PDO $pdo, int $id, int $userId, string $role): bool
{
    $ins = qmsInstrumentFind($pdo, $id, $userId, $role);
    if (!$ins) {
        return false;
    }
    $interval = max(1, (int) $ins['interval_months']);
    $last = date('Y-m-d');
    $next = date('Y-m-d', strtotime('+' . $interval . ' months'));
    $pdo->prepare('UPDATE instruments SET last_calibration_date = ?, next_calibration_date = ? WHERE id = ?')->execute([$last, $next, $id]);
    return true;
}

/** Aleti siler (aktif=0), kapsam içinde olmali. */
function qmsInstrumentDelete(PDO $pdo, int $id, int $userId, string $role): bool
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('UPDATE instruments SET active = 0 WHERE id = ?' . $scope['sql']);
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->rowCount() > 0;
}

/** Durum etiketi metni (TR). */
function qmsInstrumentStatusLabel(string $status): string
{
    return ['active' => 'Aktif', 'out_of_service' => 'Hizmet Dışı'][$status] ?? $status;
}
