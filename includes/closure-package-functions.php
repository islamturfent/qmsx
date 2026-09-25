<?php

declare(strict_types=1);

/**
 * Kapanis kaniti / denetim raporu paketi yardimcilari.
 *
 * Bir uygunsuzluk icin kok neden, duzeltici faaliyetler (durum/zaman damga),
 * kanit dosyalari ve dogrulama bilgisini toplar; PDF paketi ve denetim izi
 * kaydinda kullanilir. Kapsam tek kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/capa-functions.php';

/**
 * Bir uygunsuzlugun kapanis paketi verisini toplar (kapsamli).
 *
 * @return array<string, mixed> Bos dizi: kayit yok veya kapsam disi.
 */
function qmsClosurePackageData(PDO $pdo, int $nonconformityId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('nonconformities.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT nonconformities.*, companies.company_name
         FROM nonconformities INNER JOIN companies ON companies.id = nonconformities.company_id
         WHERE nonconformities.id = ? AND nonconformities.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$nonconformityId], $scope['params']));
    $nc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$nc) {
        return [];
    }

    $statusLabels = ['open' => 'Açık', 'in_progress' => 'Çalışılıyor', 'verification' => 'Doğrulama', 'closed' => 'Kapalı'];
    $severityLabels = ['minor' => 'Küçük', 'major' => 'Büyük', 'critical' => 'Kritik'];
    $capaStatus = qmsCapaStatusLabels();
    $capaTypes = qmsCapaTypeLabels();

    $actionStmt = $pdo->prepare(
        'SELECT corrective_actions.* FROM corrective_actions
         WHERE corrective_actions.nonconformity_id = ? AND corrective_actions.active = 1
         ORDER BY corrective_actions.id ASC'
    );
    $actionStmt->execute([$nonconformityId]);
    $actions = [];
    foreach ($actionStmt->fetchAll(PDO::FETCH_ASSOC) as $action) {
        $evidences = qmsCapaEvidenceList($pdo, (int) $action['id']);
        $evidenceList = [];
        foreach ($evidences as $ev) {
            $evidenceList[] = [
                'name' => (string) $ev['original_file_name'],
                'note' => (string) ($ev['note'] ?? ''),
                'uploaded_by' => (string) ($ev['uploaded_by_name'] ?? ''),
                'created_at' => (string) ($ev['created_at'] ?? ''),
            ];
        }
        $actions[] = [
            'action_text' => (string) $action['action_text'],
            'type' => $capaTypes[$action['action_type'] ?? 'corrective'] ?? ($action['action_type'] ?? 'corrective'),
            'status' => $capaStatus[$action['status']] ?? $action['status'],
            'responsible_person' => (string) ($action['responsible_person'] ?? ''),
            'due_date' => (string) ($action['due_date'] ?? ''),
            'completed_at' => (string) ($action['completed_at'] ?? ''),
            'closed_at' => (string) ($action['closed_at'] ?? ''),
            'verifier_name' => (string) ($action['verifier_name'] ?? ''),
            'verification_note' => (string) ($action['verification_note'] ?? ''),
            'evidence_note' => (string) ($action['evidence_note'] ?? ''),
            'evidence' => $evidenceList,
        ];
    }

    return [
        'nc_id' => (int) $nc['id'],
        'nc_title' => (string) $nc['title'],
        'nc_description' => (string) ($nc['description'] ?? ''),
        'nc_root_cause' => (string) ($nc['root_cause'] ?? ''),
        'company' => (string) $nc['company_name'],
        'severity' => $severityLabels[$nc['severity']] ?? $nc['severity'],
        'status' => $statusLabels[$nc['status']] ?? $nc['status'],
        'responsible_person' => (string) ($nc['responsible_person'] ?? ''),
        'due_date' => (string) ($nc['due_date'] ?? ''),
        'source' => (string) $nc['source'],
        'actions' => $actions,
    ];
}
