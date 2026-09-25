<?php

declare(strict_types=1);

/**
 * Kalibrasyon / Ekipman modulu yardimcilari.
 *
 * Ekipman kaydi + kalibrasyon gecmisi. Kalibrasyon durumu sonraki termin
 * tarihinden turetilir (kayitli kopya kolon yok): planlanmamis / kalibre / yakinda /
 * suresi gecmis. Kapsam tek kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/notifications.php';

/** Ekipman isletim durumlari. */
const QMS_EQUIPMENT_STATUSES = ['operational', 'out_of_service'];

/** Kalibrasyon sonuclari. */
const QMS_CALIBRATION_RESULTS = ['ok', 'failed', 'conditioned'];

/** Kalibrasyon durumlari (turetildigi icin sabit olarak tutulmaz ama etiketlenir). */
const QMS_EQUIPMENT_CAL_STATUSES = ['not_scheduled', 'calibrated', 'due_soon', 'overdue'];

/** Kalibrasyon uyarisi icin kalan gün. */
const QMS_CALIBRATION_DUE_SOON_DAYS = 30;

/** @return array<string, string> */
function qmsEquipmentStatusLabels(): array
{
    return ['operational' => 'Çalışıyor', 'out_of_service' => 'Hizmet Dışı'];
}

/** @return array<string, string> */
function qmsEquipmentCalibrationStatusLabels(): array
{
    return [
        'not_scheduled' => 'Planlanmamış',
        'calibrated' => 'Kalibre',
        'due_soon' => 'Yakında',
        'overdue' => 'Süresi Geçmiş',
    ];
}

/** @return array<string, string> */
function qmsEquipmentCalibrationResultLabels(): array
{
    return ['ok' => 'Uygun', 'failed' => 'Uygun Değil', 'conditioned' => 'Koşullu'];
}

/**
 * Sonraki kalibrasyon tarihinden kalibrasyon durumunu tureter.
 */
function qmsEquipmentCalibrationStatus(array $equipment): string
{
    $next = isset($equipment['next_calibration_date']) ? (string) $equipment['next_calibration_date'] : '';
    if ($next === '' || $next === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $next)) {
        return 'not_scheduled';
    }
    $today = new DateTime(date('Y-m-d'));
    $due = new DateTime($next);
    if ($due < $today) {
        return 'overdue';
    }
    if ($due <= (clone $today)->modify('+' . QMS_CALIBRATION_DUE_SOON_DAYS . ' days')) {
        return 'due_soon';
    }
    return 'calibrated';
}

/**
 * Kapsam icindeki tek ekipman kaydi.
 *
 * @return array<string, mixed> Bos dizi: yok veya kapsam disi.
 */
function qmsEquipmentFind(PDO $pdo, int $equipmentId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('equipment.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT equipment.*, companies.company_name, users.full_name AS responsible_full_name
         FROM equipment
         INNER JOIN companies ON companies.id = equipment.company_id
         LEFT JOIN users ON users.id = equipment.responsible_user_id
         WHERE equipment.id = ? AND equipment.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$equipmentId], $scope['params']));

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Kapsam icindeki ekipman listesi, kalibrasyon durumuyla.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsEquipmentList(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('equipment.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT equipment.*, companies.company_name,
                (SELECT COUNT(*) FROM calibrations WHERE calibrations.equipment_id = equipment.id AND calibrations.active = 1) AS calibration_count
         FROM equipment
         INNER JOIN companies ON companies.id = equipment.company_id
         WHERE equipment.active = 1' . $scope['sql'] . '
         ORDER BY equipment.next_calibration_date IS NULL, equipment.next_calibration_date ASC, equipment.id DESC'
    );
    $stmt->execute($scope['params']);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $index => $row) {
        $rows[$index]['cal_status'] = qmsEquipmentCalibrationStatus($row);
    }

    return $rows;
}

/**
 * Bir ekipmanin kalibrasyon gecmisi.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsEquipmentCalibrations(PDO $pdo, int $equipmentId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM calibrations WHERE equipment_id = ? AND active = 1 ORDER BY calibrated_on DESC, id DESC'
    );
    $stmt->execute([$equipmentId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kapsam icindeki tek kalibrasyon kaydi (duzenleme/silme icin).
 *
 * @return array<string, mixed>
 */
function qmsCalibrationFind(PDO $pdo, int $calibrationId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('equipment.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT calibrations.*, equipment.company_id, equipment.name AS equipment_name
         FROM calibrations
         INNER JOIN equipment ON equipment.id = calibrations.equipment_id
         WHERE calibrations.id = ? AND calibrations.active = 1 AND equipment.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$calibrationId], $scope['params']));

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Ekipman icin sorumlu olabilecek kullanicilar (sirketin sistem kullanicilari).
 *
 * @return array<int, array<string, mixed>>
 */
function qmsEquipmentResponsibleOptions(PDO $pdo, int $companyId): array
{
    return qmsCompanyResponsibleOptions($pdo, $companyId);
}
