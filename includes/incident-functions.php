<?php

declare(strict_types=1);

/**
 * Olay / Olay Raporlama modulu yardimcilari.
 *
 * Kaza, ramak-kala, kalite/emniyet/guvenlik olaylari gibi kayitlari izler;
 * siddet ve durum akisi ile takip edilir. Kapsam tek kaynaktan gelir
 * (includes/access.php).
 */

require_once __DIR__ . '/access.php';

const QMS_INCIDENT_TYPES = ['accident', 'near_miss', 'quality', 'security', 'other'];
const QMS_INCIDENT_SEVERITIES = ['low', 'medium', 'high', 'critical'];
const QMS_INCIDENT_STATUSES = ['open', 'under_review', 'investigation', 'closed'];

/** Kapsam içindeki olaylar (en yeniden eskiye). */
function qmsIncidentList(PDO $pdo, int $userId, string $role, string $statusFilter = ''): array
{
    $scope = qmsCompanyScope('i.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $sql = 'SELECT i.*, c.company_name
            FROM incidents i
            INNER JOIN companies c ON c.id = i.company_id
            WHERE i.active = 1' . $scope['sql'];
    $params = $scope['params'];
    $filter = (string) $statusFilter;
    if ($filter === 'open') {
        $sql .= ' AND i.status NOT IN (\'closed\')';
    } elseif (in_array($filter, QMS_INCIDENT_STATUSES, true)) {
        $sql .= ' AND i.status = ?';
        $params[] = $filter;
    }
    $sql .= ' ORDER BY i.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Kapsam içindeki tek olay; bulunamazsa bos dizi. */
function qmsIncidentFind(PDO $pdo, int $id, int $userId, string $role): array
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT * FROM incidents WHERE id = ? AND active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** Yeni olay ekler; @return int|null */
function qmsIncidentAdd(PDO $pdo, array $data, int $userId, string $role): ?int
{
    $companyId = (int) ($data['company_id'] ?? 0);
    $scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT companies.id FROM companies WHERE companies.id = ? AND companies.active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$companyId], $scope['params']));
    if (!$stmt->fetchColumn()) {
        return null;
    }
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        return null;
    }
    $type = (string) ($data['incident_type'] ?? 'other');
    if (!in_array($type, QMS_INCIDENT_TYPES, true)) {
        $type = 'other';
    }
    $severity = (string) ($data['severity'] ?? 'medium');
    if (!in_array($severity, QMS_INCIDENT_SEVERITIES, true)) {
        $severity = 'medium';
    }
    $status = (string) ($data['status'] ?? 'open');
    if (!in_array($status, QMS_INCIDENT_STATUSES, true)) {
        $status = 'open';
    }
    $ins = $pdo->prepare('INSERT INTO incidents
        (company_id, incident_code, title, description, location, incident_type, severity, reported_at, responsible, status, notes, active, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?)');
    $ins->execute([
        $companyId,
        trim((string) ($data['incident_code'] ?? '')) !== '' ? mb_substr(trim((string) $data['incident_code']), 0, 40) : null,
        mb_substr($title, 0, 190),
        trim((string) ($data['description'] ?? '')) !== '' ? mb_substr((string) $data['description'], 0, 4000) : null,
        trim((string) ($data['location'] ?? '')) !== '' ? mb_substr((string) $data['location'], 0, 190) : null,
        $type,
        $severity,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['reported_at'] ?? '')) ? (string) $data['reported_at'] : null,
        trim((string) ($data['responsible'] ?? '')) !== '' ? mb_substr(trim((string) $data['responsible']), 0, 180) : null,
        $status,
        trim((string) ($data['notes'] ?? '')) !== '' ? mb_substr((string) $data['notes'], 0, 4000) : null,
        $userId ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Olayı gunceller (kapsam içinde olmali). */
function qmsIncidentUpdate(PDO $pdo, int $id, array $data, int $userId, string $role): bool
{
    if (!qmsIncidentFind($pdo, $id, $userId, $role)) {
        return false;
    }
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        return false;
    }
    $type = (string) ($data['incident_type'] ?? 'other');
    if (!in_array($type, QMS_INCIDENT_TYPES, true)) {
        $type = 'other';
    }
    $severity = (string) ($data['severity'] ?? 'medium');
    if (!in_array($severity, QMS_INCIDENT_SEVERITIES, true)) {
        $severity = 'medium';
    }
    $status = (string) ($data['status'] ?? 'open');
    if (!in_array($status, QMS_INCIDENT_STATUSES, true)) {
        $status = 'open';
    }
    $pdo->prepare('UPDATE incidents SET
        incident_code=?, title=?, description=?, location=?, incident_type=?, severity=?, reported_at=?,
        responsible=?, status=?, notes=? WHERE id=?')->execute([
        trim((string) ($data['incident_code'] ?? '')) !== '' ? mb_substr(trim((string) $data['incident_code']), 0, 40) : null,
        mb_substr($title, 0, 190),
        trim((string) ($data['description'] ?? '')) !== '' ? mb_substr((string) $data['description'], 0, 4000) : null,
        trim((string) ($data['location'] ?? '')) !== '' ? mb_substr((string) $data['location'], 0, 190) : null,
        $type,
        $severity,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['reported_at'] ?? '')) ? (string) $data['reported_at'] : null,
        trim((string) ($data['responsible'] ?? '')) !== '' ? mb_substr(trim((string) $data['responsible']), 0, 180) : null,
        $status,
        trim((string) ($data['notes'] ?? '')) !== '' ? mb_substr((string) $data['notes'], 0, 4000) : null,
        $id,
    ]);
    return true;
}

/** Olayı siler (aktif=0), kapsam içinde olmali. */
function qmsIncidentDelete(PDO $pdo, int $id, int $userId, string $role): bool
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('UPDATE incidents SET active = 0 WHERE id = ?' . $scope['sql']);
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->rowCount() > 0;
}

/** Olaya bagli varsa uygunsuzluk id'si; yoksa 0. */
function qmsIncidentLinkedNonconformity(PDO $pdo, int $incidentId): int
{
    $stmt = $pdo->prepare('SELECT id FROM nonconformities WHERE incident_id = ? AND active = 1 LIMIT 1');
    $stmt->execute([$incidentId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Olaydan bir uygunsuzluk (nonconformity) olusturur ve baglar.
 * Zaten olusturulduysa mevcut id doner. @return int|null
 */
function qmsIncidentCreateNonconformity(PDO $pdo, int $incidentId, int $userId, string $role): ?int
{
    $inc = qmsIncidentFind($pdo, $incidentId, $userId, $role);
    if (!$inc) {
        return null;
    }
    $linked = qmsIncidentLinkedNonconformity($pdo, $incidentId);
    if ($linked > 0) {
        return $linked;
    }
    $title = mb_substr(trim((string) ($inc['title'] ?? '')), 0, 255);
    if ($title === '') {
        $title = 'Olay uygunsuzluğu';
    }
    $description = trim((string) ($inc['description'] ?? '')) !== '' ? mb_substr((string) $inc['description'], 0, 4000) : null;
    $insert = $pdo->prepare('INSERT INTO nonconformities
        (company_id, audit_id, source, title, description, severity, status, incident_id, active)
        VALUES (?, NULL, \'incident\', ?, ?, ?, \'open\', ?, 1)');
    $insert->execute([
        (int) $inc['company_id'],
        $title,
        $description,
        (string) $inc['severity'],
        $incidentId,
    ]);
    $newNcId = (int) $pdo->lastInsertId();

    // Yeni olusturulan olay kaynakli uygunsuzluk icin sirket adminlerine bildirim
    // (tercihe bagli eposta). CAPA zinciri, uygunsuzluk detayindan duzeltici
    // faaliyet acilmasyla surer; kapanis bildirimi zaten durum degisiminde gider.
    require_once __DIR__ . '/notifications.php';
    $link = 'nonconformity-detail.php?id=' . $newNcId;
    $suffix = ((string) $inc['severity']) === 'critical' ? ' (Kritik)' : '';
    qmsNotifyCompanyAdmins(
        $pdo,
        (int) $inc['company_id'],
        'nc_status_changed',
        'Yeni olay uygunsuzluğu açıldı',
        'Olaydan uygunsuzluk: ' . $title . $suffix,
        $link,
        $userId
    );

    return $newNcId;
}

/**
 * Olaydan tek akista CAPA acar: gerekirse uygunsuzluk olusturur, ona bagli
 * duzeltici faaliyet yazar, sirket adminlerine bildirim + denetim izi gonderir.
 *
 * @return int|null Yeni duzeltici faaliyet id'si; basarisizlikta null.
 */
function qmsIncidentCreateCorrectiveAction(PDO $pdo, int $incidentId, int $userId, string $role): ?int
{
    $inc = qmsIncidentFind($pdo, $incidentId, $userId, $role);
    if (!$inc) {
        return null;
    }

    // NC var mi diye bak; yoksa olustur (idempotent).
    $ncId = qmsIncidentLinkedNonconformity($pdo, $incidentId);
    if ($ncId <= 0) {
        $ncId = (int) qmsIncidentCreateNonconformity($pdo, $incidentId, $userId, $role);
    }
    if ($ncId <= 0) {
        return null;
    }

    $title = mb_substr(trim((string) ($inc['title'] ?? '')), 0, 255);
    if ($title === '') {
        $title = 'Olay düzeltici faaliyeti';
    }
    $actionText = 'Olay: ' . $title . ' için düzeltici faaliyet.';

    $insert = $pdo->prepare(
        'INSERT INTO corrective_actions
            (nonconformity_id, action_type, action_text, status, active)
         VALUES (?, \'corrective\', ?, \'planned\', 1)'
    );
    $insert->execute([$ncId, $actionText]);
    $newActionId = (int) $pdo->lastInsertId();

    $link = 'corrective-action-detail.php?id=' . $newActionId;

    // Sirket adminlerine bildirim (tercihe bagli eposta).
    require_once __DIR__ . '/notifications.php';
    require_once __DIR__ . '/capa-functions.php';
    $suffix = ((string) $inc['severity']) === 'critical' ? ' (Kritik)' : '';
    qmsNotifyCompanyAdmins(
        $pdo,
        (int) $inc['company_id'],
        'capa_opened_from_incident',
        'Olaydan CAPA açıldı',
        'Olay: ' . $title . ' için düzeltici faaliyet açıldı' . $suffix,
        $link,
        $userId
    );

    // Denetim izi.
    require_once __DIR__ . '/audit-log-functions.php';
    qmsAuditLog($pdo, (int) $inc['company_id'], $userId, 'incident', $incidentId, 'capa_created', 'Olaydan düzeltici faaliyet açıldı: ' . $title);

    return $newActionId;
}

/**
 * Sirketin sistem adminlerine yeni olay bildirimi gonderir (tercihe bagli eposta).
 */
function qmsIncidentNotify(PDO $pdo, int $companyId, string $title, string $severity, string $link): void
{
    if ($companyId <= 0) {
        return;
    }
    require_once __DIR__ . '/notifications.php';
    $suffix = $severity === 'critical' ? ' (Kritik)' : '';
    qmsNotifyCompanyAdmins($pdo, $companyId, 'incident_reported', 'Yeni Olay', 'Yeni olay: ' . $title . $suffix, $link);
}

/** Tür etiketi (TR). */
function qmsIncidentTypeLabel(string $type): string
{
    return [
        'accident' => 'Kaza',
        'near_miss' => 'Ramak Kala',
        'quality' => 'Kalite',
        'security' => 'Güvenlik',
        'other' => 'Diğer',
    ][$type] ?? $type;
}

/** Şiddet etiketi (TR). */
function qmsIncidentSeverityLabel(string $severity): string
{
    return ['low' => 'Düşük', 'medium' => 'Orta', 'high' => 'Yüksek', 'critical' => 'Kritik'][$severity] ?? $severity;
}

/** Durum etiketi (TR). */
function qmsIncidentStatusLabel(string $status): string
{
    return [
        'open' => 'Açık',
        'under_review' => 'İnceleniyor',
        'investigation' => 'Soruşturuluyor',
        'closed' => 'Kapandı',
    ][$status] ?? $status;
}
