<?php

declare(strict_types=1);

/**
 * Müşteri / tedarikçi / dış taraf sözleşme yönetimi modulu yardimcilari.
 *
 * Sözleşme; sözleşme kodu, ad, taraf, tür, başlangıç/bitiş/yenileme tarihi,
 * tutar ve durum. Kapsam tek kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';

const QMS_CONTRACT_TYPES = ['customer', 'supplier', 'other'];
const QMS_CONTRACT_STATUSES = ['active', 'expiring', 'expired', 'terminated'];

/**
 * Kapsam içindeki sözleşmeler (ad bazında sıralı).
 * `eff_status` goreli durumu yansitir: durum active iken bitiş/yenileme tarihi
 * yaklaştıysa "expiring", geçtiyse "expired" (kullanıcı yine de durumu
 * manuel değiştirebilir; bu yalnız görünüm/count içindir).
 */
function qmsContractList(PDO $pdo, int $userId, string $role, string $statusFilter = ''): array
{
    $scope = qmsCompanyScope('c.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $sql = 'SELECT c.*, co.company_name
            FROM contracts c
            INNER JOIN companies co ON co.id = c.company_id
            WHERE c.active = 1' . $scope['sql'];
    $params = $scope['params'];
    $filter = (string) $statusFilter;
    if ($filter === 'expiring') {
        $sql .= ' AND c.status = \'active\' AND c.end_date IS NOT NULL AND c.end_date < DATE_ADD(CURDATE(), INTERVAL 60 DAY)';
    } elseif ($filter === 'expired_auto') {
        $sql .= ' AND c.status = \'active\' AND c.end_date IS NOT NULL AND c.end_date < CURDATE()';
    } elseif (in_array($filter, QMS_CONTRACT_STATUSES, true)) {
        $sql .= ' AND c.status = ?';
        $params[] = $filter;
    }
    $sql .= ' ORDER BY c.contract_name ASC, c.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Kapsam içindeki tek sözleşme; bulunamazsa bos dizi. */
function qmsContractFind(PDO $pdo, int $id, int $userId, string $role): array
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id = ? AND active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** Yeni sözleşme ekler; @return int|null */
function qmsContractAdd(PDO $pdo, array $data, int $userId, string $role): ?int
{
    $companyId = (int) ($data['company_id'] ?? 0);
    $scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT companies.id FROM companies WHERE companies.id = ? AND companies.active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$companyId], $scope['params']));
    if (!$stmt->fetchColumn()) {
        return null;
    }
    $name = trim((string) ($data['contract_name'] ?? ''));
    if ($name === '') {
        return null;
    }
    $type = (string) ($data['contract_type'] ?? 'customer');
    if (!in_array($type, QMS_CONTRACT_TYPES, true)) {
        $type = 'customer';
    }
    $status = (string) ($data['status'] ?? 'active');
    if (!in_array($status, QMS_CONTRACT_STATUSES, true)) {
        $status = 'active';
    }
    $value = (string) ($data['value_amount'] ?? '') === '' ? null : round((float) $data['value_amount'], 2);
    $ins = $pdo->prepare('INSERT INTO contracts
        (company_id, contract_code, contract_name, party_name, contract_type, start_date, end_date, renewal_date, value_amount, currency, status, notes, active, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?)');
    $ins->execute([
        $companyId,
        trim((string) ($data['contract_code'] ?? '')) !== '' ? mb_substr(trim((string) $data['contract_code']), 0, 40) : null,
        mb_substr($name, 0, 190),
        trim((string) ($data['party_name'] ?? '')) !== '' ? mb_substr(trim((string) $data['party_name']), 0, 190) : null,
        $type,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['start_date'] ?? '')) ? (string) $data['start_date'] : null,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['end_date'] ?? '')) ? (string) $data['end_date'] : null,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['renewal_date'] ?? '')) ? (string) $data['renewal_date'] : null,
        $value,
        trim((string) ($data['currency'] ?? 'TRY')) !== '' ? mb_substr(trim((string) $data['currency']), 0, 8) : 'TRY',
        $status,
        trim((string) ($data['notes'] ?? '')) !== '' ? mb_substr((string) $data['notes'], 0, 4000) : null,
        $userId ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Sözleşmeyi gunceller (kapsam içinde olmali). */
function qmsContractUpdate(PDO $pdo, int $id, array $data, int $userId, string $role): bool
{
    if (!qmsContractFind($pdo, $id, $userId, $role)) {
        return false;
    }
    $name = trim((string) ($data['contract_name'] ?? ''));
    if ($name === '') {
        return false;
    }
    $type = (string) ($data['contract_type'] ?? 'customer');
    if (!in_array($type, QMS_CONTRACT_TYPES, true)) {
        $type = 'customer';
    }
    $status = (string) ($data['status'] ?? 'active');
    if (!in_array($status, QMS_CONTRACT_STATUSES, true)) {
        $status = 'active';
    }
    $value = (string) ($data['value_amount'] ?? '') === '' ? null : round((float) $data['value_amount'], 2);
    $pdo->prepare('UPDATE contracts SET
        contract_code=?, contract_name=?, party_name=?, contract_type=?, start_date=?, end_date=?, renewal_date=?,
        value_amount=?, currency=?, status=?, notes=? WHERE id=?')->execute([
        trim((string) ($data['contract_code'] ?? '')) !== '' ? mb_substr(trim((string) $data['contract_code']), 0, 40) : null,
        mb_substr($name, 0, 190),
        trim((string) ($data['party_name'] ?? '')) !== '' ? mb_substr(trim((string) $data['party_name']), 0, 190) : null,
        $type,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['start_date'] ?? '')) ? (string) $data['start_date'] : null,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['end_date'] ?? '')) ? (string) $data['end_date'] : null,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['renewal_date'] ?? '')) ? (string) $data['renewal_date'] : null,
        $value,
        trim((string) ($data['currency'] ?? 'TRY')) !== '' ? mb_substr(trim((string) $data['currency']), 0, 8) : 'TRY',
        $status,
        trim((string) ($data['notes'] ?? '')) !== '' ? mb_substr((string) $data['notes'], 0, 4000) : null,
        $id,
    ]);
    return true;
}

/** Sözleşmeyi siler (aktif=0), kapsam içinde olmali. */
function qmsContractDelete(PDO $pdo, int $id, int $userId, string $role): bool
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('UPDATE contracts SET active = 0 WHERE id = ?' . $scope['sql']);
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->rowCount() > 0;
}

/** Sözleşmeye bagli dosyalar (en yeni önce). */
function qmsContractAttachments(PDO $pdo, int $contractId): array
{
    $stmt = $pdo->prepare('SELECT id, contract_id, original_name, mime_type, file_size, created_at
                           FROM contract_attachments WHERE contract_id = ? ORDER BY id DESC');
    $stmt->execute([$contractId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Kapsam icindeki sözlesmenin tek dosyası; bulunamazsa bos dizi. */
function qmsContractAttachmentFind(PDO $pdo, int $attId, int $userId, string $role): array
{
    $stmt = $pdo->prepare('SELECT a.*, a.contract_id FROM contract_attachments a WHERE a.id = ? LIMIT 1');
    $stmt->execute([$attId]);
    $att = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$att || !qmsContractFind($pdo, (int) $att['contract_id'], $userId, $role)) {
        return [];
    }
    return $att;
}

/** Sözlesmeye dosya ekler (kapsam icinde olmali). @return bool */
function qmsContractAddAttachment(PDO $pdo, int $contractId, array $file, int $userId, string $role): bool
{
    if (!qmsContractFind($pdo, $contractId, $userId, $role)) {
        return false;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 10 * 1024 * 1024) {
        return false;
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $stored = bin2hex(random_bytes(20)) . '.' . $ext;
    $dir = __DIR__ . '/../storage/contracts';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $target = $dir . '/' . $stored;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return false;
    }
    $ins = $pdo->prepare('INSERT INTO contract_attachments (contract_id, original_name, stored_name, mime_type, file_size, uploaded_by) VALUES (?,?,?,?,?,?)');
    $ins->execute([
        $contractId,
        mb_substr((string) $file['name'], 0, 255),
        $stored,
        (string) ($file['type'] ?? null) !== '' ? mb_substr((string) $file['type'], 0, 120) : null,
        (int) $file['size'],
        $userId ?: null,
    ]);
    return true;
}

/**
 * Coklu dosya yukleme: $_FILES["attachment_files"] (name[], tmp_name[] ...)
 * icerisindeki her dosyayi tek tek isler; kac tanesinin eklendigini doner.
 */
function qmsContractAddAttachments(PDO $pdo, int $contractId, array $files, int $userId, string $role): int
{
    if (!qmsContractFind($pdo, $contractId, $userId, $role)) {
        return 0;
    }
    $names = $files['name'] ?? [];
    if (!is_array($names)) {
        $names = [$names];
    }
    $added = 0;
    for ($i = 0; $i < count($names); $i++) {
        $tmp = $files['tmp_name'] ?? '';
        $types = $files['type'] ?? '';
        $errors = $files['error'] ?? UPLOAD_ERR_NO_FILE;
        $sizes = $files['size'] ?? 0;
        $one = [
            'name' => $names[$i] ?? null,
            'tmp_name' => is_array($tmp) ? ($tmp[$i] ?? null) : null,
            'type' => is_array($types) ? ($types[$i] ?? null) : null,
            'error' => is_array($errors) ? ($errors[$i] ?? UPLOAD_ERR_NO_FILE) : (int) $errors,
            'size' => is_array($sizes) ? ((int) ($sizes[$i] ?? 0)) : (int) $sizes,
        ];
        if (qmsContractAddAttachment($pdo, $contractId, $one, $userId, $role)) {
            $added++;
        }
    }
    return $added;
}

/** Sözlesme dosyasini siler (kapsam icinde olmali). */
function qmsContractDeleteAttachment(PDO $pdo, int $attId, int $userId, string $role): bool
{
    $att = qmsContractAttachmentFind($pdo, $attId, $userId, $role);
    if (!$att) {
        return false;
    }
    $pdo->prepare('DELETE FROM contract_attachments WHERE id = ?')->execute([$attId]);
    $p = __DIR__ . '/../storage/contracts/' . $att['stored_name'];
    if (is_file($p)) {
        @unlink($p);
    }
    return true;
}

/** Durum etiketi metni (TR). */
function qmsContractStatusLabel(string $status): string
{
    return [
        'active' => 'Aktif',
        'expiring' => 'Süresi Doluyor',
        'expired' => 'Süresi Doldu',
        'terminated' => 'Feshedildi',
    ][$status] ?? $status;
}
