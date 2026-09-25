<?php

declare(strict_types=1);

/**
 * Dış denetim & kapama takibi modulu yardimcilari.
 *
 * Dış kuruluslardan (müşteri, belgelendirme, mevzuat) gelen denetimler ve
 * bulgularinin kapanisi. Bulgu gecikmesi durum + termin tarihinden turetilir;
 * saklanan kopya yok. Kapsam tek kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';

/** Dış denetim kaynagi. */
const QMS_EXTERNAL_AUDIT_TYPES = ['customer', 'certification', 'regulatory', 'other'];

/** Dış denetim durumlari. */
const QMS_EXTERNAL_AUDIT_STATUSES = ['planned', 'conducted', 'closed'];

/** Bulgu kategorileri. */
const QMS_FINDING_CATEGORIES = ['major', 'minor', 'observation'];

/** Bulgu durumlari. */
const QMS_FINDING_STATUSES = ['open', 'in_progress', 'closed'];

/** @return array<string, string> */
function qmsExternalAuditTypeLabels(): array
{
    return ['customer' => 'Müşteri', 'certification' => 'Belgelendirme', 'regulatory' => 'Mevzuat', 'other' => 'Diğer'];
}

/** @return array<string, string> */
function qmsExternalAuditStatusLabels(): array
{
    return ['planned' => 'Planlandı', 'conducted' => 'Gerçekleşti', 'closed' => 'Kapatıldı'];
}

/** @return array<string, string> */
function qmsFindingStatusLabels(): array
{
    return ['open' => 'Açık', 'in_progress' => 'Devam Ediyor', 'closed' => 'Kapalı'];
}

/** @return array<string, string> */
function qmsFindingCategoryLabels(): array
{
    return ['major' => 'Önemli', 'minor' => 'Küçük', 'observation' => 'Gözlem'];
}

/** @return array<string, string> */
function qmsExternalAuditTypeI18nKeys(): array
{
    return ['customer' => 'extAuditTypeCustomerLabel', 'certification' => 'extAuditTypeCertificationLabel', 'regulatory' => 'extAuditTypeRegulatoryLabel', 'other' => 'extAuditTypeOtherLabel'];
}

/** @return array<string, string> */
function qmsExternalAuditStatusI18nKeys(): array
{
    return ['planned' => 'extAuditStatusPlannedLabel', 'conducted' => 'extAuditStatusConductedLabel', 'closed' => 'extAuditStatusClosedLabel'];
}

/** @return array<string, string> */
function qmsFindingStatusI18nKeys(): array
{
    return ['open' => 'extFindingStatusOpenLabel', 'in_progress' => 'extFindingStatusInProgressLabel', 'closed' => 'extFindingStatusClosedLabel'];
}

/** Bulgu gecikmis mi (acikken ve termin gecmis mi)? */
function qmsFindingIsOverdue(string $status, ?string $dueDate, string $today): bool
{
    if ($dueDate === null || $dueDate === '') {
        return false;
    }
    return in_array($status, ['open', 'in_progress'], true) && $dueDate < $today;
}

/**
 * Kapsam icindeki dış denetimler (en yeniden eskiye) + ozet.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsExternalAuditList(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('a.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $today = date('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT a.*, companies.company_name,
                (SELECT COUNT(*) FROM external_audit_findings f
                  WHERE f.external_audit_id = a.id AND f.active = 1
                    AND f.status <> \'closed\') AS open_findings,
                (SELECT COUNT(*) FROM external_audit_findings f
                  WHERE f.external_audit_id = a.id AND f.active = 1
                    AND f.status <> \'closed\' AND f.due_date IS NOT NULL AND f.due_date < ?) AS overdue_findings
         FROM external_audits a
         INNER JOIN companies ON companies.id = a.company_id
         WHERE a.active = 1' . $scope['sql'] . '
         ORDER BY a.audit_date IS NULL ASC, a.audit_date DESC, a.id DESC'
    );
    $stmt->execute(array_merge([$today], $scope['params']));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kapsam icindeki tek dış denetim.
 *
 * @return array<string, mixed> Bos dizi: yok veya kapsam disi.
 */
function qmsExternalAuditFind(PDO $pdo, int $auditId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('a.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $today = date('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT a.*, companies.company_name,
                (SELECT COUNT(*) FROM external_audit_findings f
                  WHERE f.external_audit_id = a.id AND f.active = 1) AS finding_count,
                (SELECT COUNT(*) FROM external_audit_findings f
                  WHERE f.external_audit_id = a.id AND f.active = 1 AND f.status <> \'closed\') AS open_findings,
                (SELECT COUNT(*) FROM external_audit_findings f
                  WHERE f.external_audit_id = a.id AND f.active = 1
                    AND f.status <> \'closed\' AND f.due_date IS NOT NULL AND f.due_date < ?) AS overdue_findings
         FROM external_audits a
         INNER JOIN companies ON companies.id = a.company_id
         WHERE a.id = ? AND a.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$today, $auditId], $scope['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Bir dış denetimin bulgulari (turetilmis gecikme bayragiyla).
 *
 * @return array<int, array<string, mixed>>
 */
function qmsExternalFindings(PDO $pdo, int $auditId): array
{
    $today = date('Y-m-d');
    $stmt = $pdo->prepare(
        'SELECT id, finding_text, category, due_date, status, closed_date, notes
         FROM external_audit_findings
         WHERE external_audit_id = ? AND active = 1
         ORDER BY id DESC'
    );
    $stmt->execute([$auditId]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['overdue'] = qmsFindingIsOverdue($row['status'], $row['due_date'], $today);
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Bir bulgu ekler (dış denetim kapsam icinde olmali).
 *
 * @param array{
 *     finding_text: string, category: string, due_date: string, notes: string
 * } $data
 * @return int|null Yeni bulgu id'si; dogrulama basarisizsa null.
 */
function qmsExternalAddFinding(PDO $pdo, int $auditId, array $data, int $userId, string $role): ?int
{
    $audit = qmsExternalAuditFind($pdo, $auditId, $userId, $role);
    if ($audit === []) {
        return null;
    }

    $text = trim((string) ($data['finding_text'] ?? ''));
    $category = (string) ($data['category'] ?? '');
    if ($text === '' || !in_array($category, QMS_FINDING_CATEGORIES, true)) {
        return null;
    }
    $dueDate = trim((string) ($data['due_date'] ?? ''));
    if ($dueDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) !== 1) {
        return null;
    }

    $insert = $pdo->prepare(
        'INSERT INTO external_audit_findings
            (external_audit_id, finding_text, category, due_date, status, notes, active)
         VALUES (?,?,?,?,\'open\',?,1)'
    );
    $insert->execute([
        $auditId,
        mb_substr($text, 0, 255),
        $category,
        $dueDate !== '' ? $dueDate : null,
        trim((string) ($data['notes'] ?? '')) !== '' ? mb_substr(trim((string) $data['notes']), 0, 4000) : null,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Bir bulgunun durumunu gunceller (acik -> devam -> kapali).
 */
function qmsExternalUpdateFindingStatus(PDO $pdo, int $auditId, int $findingId, string $status, int $userId, string $role): bool
{
    $audit = qmsExternalAuditFind($pdo, $auditId, $userId, $role);
    if ($audit === [] || !in_array($status, QMS_FINDING_STATUSES, true)) {
        return false;
    }

    $closedDate = $status === 'closed' ? date('Y-m-d') : null;
    $stmt = $pdo->prepare(
        'UPDATE external_audit_findings SET status = ?, closed_date = ? WHERE id = ? AND external_audit_id = ?'
    );
    $stmt->execute([$status, $closedDate, $findingId, $auditId]);
    return $stmt->rowCount() > 0;
}
