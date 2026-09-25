<?php

declare(strict_types=1);

/**
 * Ic denetim programi modulu yardimcilari.
 *
 * Yillik program, ayni sirketin denetimlerini bir planda bir araya getirir.
 * Kapsam tek kaynaktan gelir (includes/access.php); durum akisi
 * `draft -> active -> completed` seklindedir.
 */

require_once __DIR__ . '/access.php';

/** Program durumlari. */
const QMS_AUDIT_PROGRAM_STATUSES = ['draft', 'active', 'completed'];

/** @return array<string, string> */
function qmsAuditProgramStatusLabels(): array
{
    return [
        'draft' => 'Taslak',
        'active' => 'Aktif',
        'completed' => 'Tamamlandı',
    ];
}

/** @return array<string, string> */
function qmsAuditProgramStatusI18nKeys(): array
{
    return [
        'draft' => 'auditProgramStatusDraftLabel',
        'active' => 'auditProgramStatusActiveLabel',
        'completed' => 'auditProgramStatusCompletedLabel',
    ];
}

/**
 * Kapsam icindeki tek denetim programi.
 *
 * @return array<string, mixed> Bos dizi: yok veya kapsam disi.
 */
function qmsAuditProgramFind(PDO $pdo, int $programId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('audit_programs.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT audit_programs.*, companies.company_name
         FROM audit_programs
         INNER JOIN companies ON companies.id = audit_programs.company_id
         WHERE audit_programs.id = ? AND audit_programs.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$programId], $scope['params']));

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Kapsam icindeki programlar (en yeni yil once), denetim sayilariyla.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsAuditProgramList(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('audit_programs.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT audit_programs.*, companies.company_name,
                (SELECT COUNT(*) FROM audit_program_audits
                  WHERE audit_program_audits.program_id = audit_programs.id) AS audit_count,
                (SELECT COUNT(*) FROM audit_program_audits
                  INNER JOIN audits ON audits.id = audit_program_audits.audit_id
                  WHERE audit_program_audits.program_id = audit_programs.id AND audits.status = \'done\') AS done_count
         FROM audit_programs
         INNER JOIN companies ON companies.id = audit_programs.company_id
         WHERE audit_programs.active = 1' . $scope['sql'] . '
         ORDER BY audit_programs.year DESC, audit_programs.id DESC'
    );
    $stmt->execute($scope['params']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Bir programin bagli denetimleri (durum ve denetci sayisi ile).
 *
 * @return array<int, array<string, mixed>>
 */
function qmsAuditProgramAudits(PDO $pdo, int $programId): array
{
    $stmt = $pdo->prepare(
        'SELECT audits.id, audits.title, audits.audit_type, audits.planned_date, audits.status,
                audits.created_at,
                (SELECT COUNT(*) FROM audit_auditors WHERE audit_auditors.audit_id = audits.id) AS auditor_count
         FROM audit_program_audits
         INNER JOIN audits ON audits.id = audit_program_audits.audit_id
         WHERE audit_program_audits.program_id = :program_id AND audits.active = 1
         ORDER BY audits.created_at DESC, audits.id DESC'
    );
    $stmt->execute(['program_id' => $programId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Program icin baglanabilir denetimler (aynı sirket ve kapsam icinde).
 *
 * @return array<int, array<string, mixed>>
 */
function qmsAuditProgramAvailableAudits(PDO $pdo, int $programId, int $companyId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('audits.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT audits.id, audits.title, audits.audit_type, audits.planned_date, audits.status
         FROM audits
         WHERE audits.active = 1 AND audits.company_id = ?'
        . $scope['sql'] . '
         ORDER BY audits.created_at DESC, audits.id DESC'
    );
    $stmt->execute(array_merge([$companyId], $scope['params']));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Program icin bir denetimi baglar (kapsam ve sirket uyumlulugunu dogrular).
 *
 * @return bool Basarili ise true.
 */
function qmsAuditProgramLinkAudit(PDO $pdo, int $programId, int $auditId, int $companyId, int $userId, string $role): bool
{
    $allowed = array_column(qmsAuditProgramAvailableAudits($pdo, $programId, $companyId, $userId, $role), 'id');
    if (!in_array($auditId, array_map('intval', $allowed), true)) {
        return false;
    }

    try {
        $pdo->prepare('INSERT IGNORE INTO audit_program_audits (program_id, audit_id, added_by) VALUES (?,?,?)')
            ->execute([$programId, $auditId, $userId ?: null]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Bir denetimi programdan cikarir.
 */
function qmsAuditProgramUnlinkAudit(PDO $pdo, int $programId, int $auditId): void
{
    $pdo->prepare('DELETE FROM audit_program_audits WHERE program_id = ? AND audit_id = ?')
        ->execute([$programId, $auditId]);
}
