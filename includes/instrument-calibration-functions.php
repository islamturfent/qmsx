<?php

declare(strict_types=1);

/**
 * Kalibrasyon & Metroloji sertifika/gecmis modulu yardimcilari.
 *
 * Ölçü aleti başına kalibrasyon geçmişi kaydi: kalibrasyon tarihi, sonraki
 * tarih, sonuç (pass/fail), sertifika no, laboratuvar, sertifika dosyasi
 * (storage/calibrations), uygulayan. Kayit eklendiginde aletin son/sonraki
 * kalibrasyon tarihleri de guncellenir. Kapsam tek kaynaktan gelir.
 */

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/instrument-functions.php';

const QMS_INSTRUMENT_CALIB_RESULTS = ['pass', 'fail'];

const QMS_INSTRUMENT_CALIB_STORAGE = __DIR__ . '/../storage/calibrations';

/** Kapsam içindeki kalibrasyon kayitlari (en yeni once). */
function qmsInstCalibList(PDO $pdo, int $userId, string $role, int $instrumentId = 0, string $resultFilter = ''): array
{
    $scope = qmsCompanyScope('k.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $sql = 'SELECT k.*, i.name AS instrument_name, i.instrument_code, co.company_name
            FROM instrument_calibrations k
            INNER JOIN instruments i ON i.id = k.instrument_id
            INNER JOIN companies co ON co.id = k.company_id
            WHERE k.active = 1' . $scope['sql'];
    $params = $scope['params'];
    if ($instrumentId > 0) {
        $sql .= ' AND k.instrument_id = ?';
        $params[] = $instrumentId;
    }
    if (in_array($resultFilter, QMS_INSTRUMENT_CALIB_RESULTS, true)) {
        $sql .= ' AND k.result = ?';
        $params[] = $resultFilter;
    }
    $sql .= ' ORDER BY k.calibration_date DESC, k.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Kapsam içindeki tek kayit; bulunamazsa bos dizi. */
function qmsInstCalibFind(PDO $pdo, int $id, int $userId, string $role): array
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT * FROM instrument_calibrations WHERE id = ? AND active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Kalibrasyon kaydi ekler; aletin kalibrasyon tarihini de gunceller.
 * @param array $file $_FILES['certificate'] veya [] (dosya yok)
 * @return int|null
 */
function qmsInstCalibAdd(PDO $pdo, array $data, array $file, int $userId, string $role): ?int
{
    $instrument = qmsInstrumentFind($pdo, (int) ($data['instrument_id'] ?? 0), $userId, $role);
    if (!$instrument) {
        return null;
    }
    $result = (string) ($data['result'] ?? 'pass');
    if (!in_array($result, QMS_INSTRUMENT_CALIB_RESULTS, true)) {
        $result = 'pass';
    }
    $calDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['calibration_date'] ?? '')) ? (string) $data['calibration_date'] : null;
    $dueDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['due_date'] ?? '')) ? (string) $data['due_date'] : null;
    if ($calDate === null) {
        return null;
    }

    // Sertifika dosyasi (opsiyonel; en fazla 10 MB).
    $stored = null;
    $hasFile = ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($hasFile) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 10 * 1024 * 1024) {
            return null;
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $stored = bin2hex(random_bytes(20)) . '.' . $ext;
        $dir = QMS_INSTRUMENT_CALIB_STORAGE;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
            return null;
        }
    }

    $ins = $pdo->prepare('INSERT INTO instrument_calibrations
        (instrument_id, company_id, calibration_date, due_date, result, cert_number, lab_name, certificate_file, performed_by, notes, active, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,1,?)');
    $ins->execute([
        (int) $instrument['id'],
        (int) $instrument['company_id'],
        $calDate,
        $dueDate,
        $result,
        trim((string) ($data['cert_number'] ?? '')) !== '' ? mb_substr(trim((string) $data['cert_number']), 0, 80) : null,
        trim((string) ($data['lab_name'] ?? '')) !== '' ? mb_substr(trim((string) $data['lab_name']), 0, 180) : null,
        $stored,
        trim((string) ($data['performed_by'] ?? '')) !== '' ? mb_substr(trim((string) $data['performed_by']), 0, 180) : null,
        trim((string) ($data['notes'] ?? '')) !== '' ? mb_substr((string) $data['notes'], 0, 4000) : null,
        $userId ?: null,
    ]);
    $id = (int) $pdo->lastInsertId();

    // Yeni kalibrasyon son/sonraki tarihleri alete yansir.
    $pdo->prepare('UPDATE instruments SET last_calibration_date = ?, next_calibration_date = ? WHERE id = ?')->execute([
        $calDate,
        $dueDate,
        (int) $instrument['id'],
    ]);

    return $id;
}

/** Kaydi siler (aktif=0) ve sertifika dosyasini kaldirir; kapsam içinde olmali. */
function qmsInstCalibDelete(PDO $pdo, int $id, int $userId, string $role): bool
{
    $row = qmsInstCalibFind($pdo, $id, $userId, $role);
    if (!$row) {
        return false;
    }
    $pdo->prepare('UPDATE instrument_calibrations SET active = 0 WHERE id = ?')->execute([$id]);
    if (!empty($row['certificate_file'])) {
        $p = QMS_INSTRUMENT_CALIB_STORAGE . '/' . $row['certificate_file'];
        if (is_file($p)) {
            @unlink($p);
        }
    }
    return true;
}

/** Sertifika dosyasini indirmek icin kayit + yol bilgisi; yoksa bos dizi. */
function qmsInstCalibDownload(PDO $pdo, int $id, int $userId, string $role): array
{
    $row = qmsInstCalibFind($pdo, $id, $userId, $role);
    if (!$row || empty($row['certificate_file'])) {
        return [];
    }
    $path = QMS_INSTRUMENT_CALIB_STORAGE . '/' . $row['certificate_file'];
    if (!is_file($path)) {
        return [];
    }
    return ['path' => $path, 'name' => 'kalibrasyon-sertifikasi-' . (int) $row['id'] . '-' . basename($row['certificate_file'])];
}

/** Sonuç etiketi (TR). */
function qmsInstCalibResultLabel(string $result): string
{
    return ['pass' => 'Başarılı', 'fail' => 'Başarısız'][$result] ?? $result;
}

/**
 * Mevcut bir kalibrasyon kaydina sertifika dosyasi ekler veya degistirir.
 * Eski dosya silinir; kayit kapsam icinde olmali.
 *
 * @param array $file $_FILES['certificate']
 * @return bool
 */
function qmsInstCalibAttachCert(PDO $pdo, int $id, array $file, int $userId, string $role): bool
{
    $row = qmsInstCalibFind($pdo, $id, $userId, $role);
    if (!$row) {
        return false;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 10 * 1024 * 1024) {
        return false;
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $stored = bin2hex(random_bytes(20)) . '.' . $ext;
    $dir = QMS_INSTRUMENT_CALIB_STORAGE;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
        return false;
    }

    $pdo->prepare('UPDATE instrument_calibrations SET certificate_file = ? WHERE id = ?')->execute([$stored, $id]);
    if (!empty($row['certificate_file'])) {
        $old = $dir . '/' . $row['certificate_file'];
        if (is_file($old)) {
            @unlink($old);
        }
    }
    return true;
}
