<?php

declare(strict_types=1);

/**
 * Onay & imza workflow modulu yardimcilari.
 *
 * Kayitlara bagli cok adimli onay/imza akisi. Akis, sirali adimlardan olusur;
 * her adim bir kullanici tarafindan imzalanir. Akis durumu adim kararlarindan
 * turetilir (tum adimlar onaylandiysa approved, biri reddettiyse rejected).
 * Kapsam tek kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';

/** Akis durumlari. */
const QMS_APPROVAL_STATUSES = ['in_progress', 'approved', 'rejected'];

/** Adim kararlari. */
const QMS_STEP_DECISIONS = ['pending', 'approved', 'rejected'];

/** Olusturma formunda desteklenen maksimum adim sayisi. */
const QMS_APPROVAL_MAX_STEPS = 5;

/** @return array<string, string> */
function qmsApprovalStatusLabels(): array
{
    return ['in_progress' => 'Devam Ediyor', 'approved' => 'Onaylandı', 'rejected' => 'Reddedildi'];
}

/** @return array<string, string> */
function qmsApprovalStatusI18nKeys(): array
{
    return ['in_progress' => 'approvalStatusInProgressLabel', 'approved' => 'approvalStatusApprovedLabel', 'rejected' => 'approvalStatusRejectedLabel'];
}

/** @return array<string, string> */
function qmsStepDecisionLabels(): array
{
    return ['pending' => 'Beklemede', 'approved' => 'İmzalandı', 'rejected' => 'Reddedildi'];
}

/** @return array<string, string> */
function qmsStepDecisionI18nKeys(): array
{
    return ['pending' => 'stepDecisionPendingLabel', 'approved' => 'stepDecisionApprovedLabel', 'rejected' => 'stepDecisionRejectedLabel'];
}

/**
 * Kapsam icindeki onay akislari (en yeniden eskiye) + adim ozeti.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsApprovalRunList(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('r.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT r.*, companies.company_name, users.full_name AS creator_name,
                (SELECT COUNT(*) FROM approval_run_steps s WHERE s.run_id = r.id AND s.active = 1) AS step_count,
                (SELECT COUNT(*) FROM approval_run_steps s WHERE s.run_id = r.id AND s.active = 1 AND s.decision = \'pending\') AS pending_count
         FROM approval_runs r
         INNER JOIN companies ON companies.id = r.company_id
         LEFT JOIN users ON users.id = r.created_by
         WHERE r.active = 1' . $scope['sql'] . '
         ORDER BY r.id DESC'
    );
    $stmt->execute($scope['params']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kapsam icindeki tek akis.
 *
 * @return array<string, mixed> Bos dizi: yok veya kapsam disi.
 */
function qmsApprovalRunFind(PDO $pdo, int $runId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('r.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT r.*, companies.company_name, users.full_name AS creator_name
         FROM approval_runs r
         INNER JOIN companies ON companies.id = r.company_id
         LEFT JOIN users ON users.id = r.created_by
         WHERE r.id = ? AND r.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$runId], $scope['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Bir akisin adimlari (sirayla) + imzalayan adi.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsApprovalSteps(PDO $pdo, int $runId): array
{
    $stmt = $pdo->prepare(
        'SELECT s.*, users.full_name AS approver_name
         FROM approval_run_steps s
         LEFT JOIN users ON users.id = s.approver_user_id
         WHERE s.run_id = ? AND s.active = 1
         ORDER BY s.step_order ASC, s.id ASC'
    );
    $stmt->execute([$runId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Sirasi gelen ilk beklemedeki adim (none ise bos dizi).
 *
 * @return array<string, mixed>
 */
function qmsApprovalCurrentStep(PDO $pdo, int $runId): array
{
    $stmt = $pdo->prepare(
        'SELECT s.*, users.full_name AS approver_name
         FROM approval_run_steps s
         LEFT JOIN users ON users.id = s.approver_user_id
         WHERE s.run_id = ? AND s.active = 1 AND s.decision = \'pending\'
         ORDER BY s.step_order ASC, s.id ASC LIMIT 1'
    );
    $stmt->execute([$runId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Bir akis icin imza atabilecek kullanicilar (aktif sistem + super adminler).
 *
 * @return array<int, array<string, mixed>>
 */
function qmsApprovalApproverOptions(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT users.id, users.full_name FROM users
         WHERE users.active = 1 AND users.role IN ('system_admin', 'super_admin')
         ORDER BY users.full_name"
    );
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Yeni onay/imza akisi olusturur (sirket kapsam icinde olmali).
 *
 * @param array{
 *     company_id: int, subject: string, entity_type: string, entity_id: int,
 *     steps: array<int, array{step_name: string, approver_user_id: int}>
 * } $data
 * @return int|null Yeni akis id'si; dogrulama basarisizsa null.
 */
function qmsApprovalCreateRun(PDO $pdo, array $data, int $userId, string $role): ?int
{
    $companyId = (int) ($data['company_id'] ?? 0);
    $scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT companies.id FROM companies WHERE companies.id = ? AND companies.active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$companyId], $scope['params']));
    if (!$stmt->fetchColumn()) {
        return null;
    }

    $subject = trim((string) ($data['subject'] ?? ''));
    $steps = [];
    foreach (($data['steps'] ?? []) as $step) {
        $name = trim((string) ($step['step_name'] ?? ''));
        $approver = (int) ($step['approver_user_id'] ?? 0);
        if ($name !== '' && $approver > 0) {
            $steps[] = ['step_name' => mb_substr($name, 0, 180), 'approver_user_id' => $approver];
        }
    }
    if ($subject === '' || $steps === [] || count($steps) > QMS_APPROVAL_MAX_STEPS) {
        return null;
    }

    // Onaylayanlar gecerli imzacilar olmali.
    $validIds = array_map('intval', array_column(qmsApprovalApproverOptions($pdo), 'id'));

    $pdo->beginTransaction();
    try {
        $insertRun = $pdo->prepare(
            "INSERT INTO approval_runs (company_id, subject, entity_type, entity_id, status, active, created_by)
             VALUES (?,?,?,?,'in_progress',1,?)"
        );
        $insertRun->execute([
            $companyId,
            $subject,
            trim((string) ($data['entity_type'] ?? '')) !== '' ? mb_substr(trim((string) $data['entity_type']), 0, 60) : null,
            (int) ($data['entity_id'] ?? 0) > 0 ? (int) $data['entity_id'] : null,
            $userId ?: null,
        ]);
        $runId = (int) $pdo->lastInsertId();

        $order = 1;
        $insertStep = $pdo->prepare(
            'INSERT INTO approval_run_steps (run_id, step_order, step_name, approver_user_id, decision, active)
             VALUES (?,?,?,?,\'pending\',1)'
        );
        foreach ($steps as $step) {
            if (!in_array($step['approver_user_id'], $validIds, true)) {
                continue;
            }
            $insertStep->execute([$runId, $order, $step['step_name'], $step['approver_user_id']]);
            $order++;
        }

        if ($order === 1) {
            throw new RuntimeException('no valid approvers');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return null;
    }

    return $runId;
}

/**
 * Sirasi gelen adimi onaylar/reddeder ve akis durumunu yeniden hesaplar.
 */
function qmsApprovalSign(PDO $pdo, int $runId, int $stepId, string $decision, string $comment, int $userId, string $role): bool
{
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        return false;
    }
    $run = qmsApprovalRunFind($pdo, $runId, $userId, $role);
    if ($run === []) {
        return false;
    }
    $current = qmsApprovalCurrentStep($pdo, $runId);
    if ($current === [] || (int) $current['id'] !== $stepId) {
        return false;
    }
    if ((int) $current['approver_user_id'] !== $userId) {
        return false;
    }

    $update = $pdo->prepare(
        'UPDATE approval_run_steps SET decision = ?, comment = ?, signed_at = NOW() WHERE id = ? AND run_id = ?'
    );
    $update->execute([$decision, $comment !== '' ? mb_substr($comment, 0, 4000) : null, $stepId, $runId]);

    // Akis durumunu adimlardan hesapla.
    $steps = qmsApprovalSteps($pdo, $runId);
    $pending = array_filter($steps, static fn(array $s): bool => $s['decision'] === 'pending');
    if ($pending === []) {
        $hasRejected = !empty(array_filter($steps, static fn(array $s): bool => $s['decision'] === 'rejected'));
        $newStatus = $hasRejected ? 'rejected' : 'approved';
    } else {
        $newStatus = 'in_progress';
    }
    $stmt = $pdo->prepare('UPDATE approval_runs SET status = ? WHERE id = ?');
    $stmt->execute([$newStatus, $runId]);

    return true;
}
