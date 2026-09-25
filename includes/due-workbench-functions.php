<?php

declare(strict_types=1);

/**
 * "Vadesi Gelen / Geciken Isler" workbench yardimcilari.
 *
 * Moduller arasi geciken kayitlari toplar: duzeltici faaliyet, uygunsuzluk,
 * egitim, ekipman kalibrasyonu, dis denetim bulgusu, dokuman gozden gecirme ve
 * sikayet terminleri. Kapsam tek kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';

/** Geciken kaydin referans etiketi / simgesi. */
const QMS_OVERDUE_SECTIONS = ['actions', 'nonconformities', 'trainings', 'equipment', 'findings', 'documents', 'complaints'];

/**
 * Kapsam icindeki geciken kayitlari sekisyon seklinde toplar.
 *
 * Geciken = tarih bugunden once VE hala acik (kapanis/iptal/arsiv haric).
 *
 * @return array<string, array{label_key: string, icon: string, count: int,
 *         rows: array<int, array{id: int, title: string, company: string,
 *         due: string, extra: string, link: string}>}>
 */
function qmsOverdueWorkbench(PDO $pdo, int $userId, string $role): array
{
    $companyIds = qmsVisibleCompanyIds($pdo, $userId, $role);
    $today = date('Y-m-d');
    $sections = [
        'actions' => ['label_key' => 'overdueActionsLabel', 'icon' => 'alert', 'count' => 0, 'rows' => []],
        'nonconformities' => ['label_key' => 'overdueNonconformitiesLabel', 'icon' => 'alert', 'count' => 0, 'rows' => []],
        'trainings' => ['label_key' => 'overdueTrainingsLabel', 'icon' => 'training', 'count' => 0, 'rows' => []],
        'equipment' => ['label_key' => 'overdueEquipmentLabel', 'icon' => 'equipment', 'count' => 0, 'rows' => []],
        'findings' => ['label_key' => 'overdueFindingsLabel', 'icon' => 'external', 'count' => 0, 'rows' => []],
        'documents' => ['label_key' => 'overdueDocumentsLabel', 'icon' => 'documents', 'count' => 0, 'rows' => []],
        'complaints' => ['label_key' => 'overdueComplaintsLabel', 'icon' => 'complaints', 'count' => 0, 'rows' => []],
    ];

    // Geciken duzeltici faaliyet (sirket uygunsuzluk uzerinden).
    $scopeActions = qmsCompanyScope('n.company_id', $companyIds);
    $stmt = $pdo->prepare(
        "SELECT ca.id, ca.action_text, ca.due_date, ca.responsible_person, n.title AS parent, co.company_name
         FROM corrective_actions ca
         INNER JOIN nonconformities n ON n.id = ca.nonconformity_id
         INNER JOIN companies co ON co.id = n.company_id
         WHERE ca.active = 1 AND ca.due_date IS NOT NULL AND ca.due_date < ?
           AND ca.status NOT IN ('completed', 'closed')" . $scopeActions['sql'] . '
         ORDER BY ca.due_date ASC, ca.id ASC'
    );
    $stmt->execute(array_merge([$today], $scopeActions['params']));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sections['actions']['rows'][] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['action_text'],
            'company' => (string) $row['company_name'],
            'due' => (string) $row['due_date'],
            'extra' => (string) $row['responsible_person'],
            'link' => 'corrective-action-detail.php?id=' . (int) $row['id'],
        ];
    }

    // Geciken uygunsuzluk.
    $scopeNc = qmsCompanyScope('nonconformities.company_id', $companyIds);
    $stmt = $pdo->prepare(
        "SELECT nonconformities.id, nonconformities.title, nonconformities.due_date,
                nonconformities.responsible_person, companies.company_name
         FROM nonconformities INNER JOIN companies ON companies.id = nonconformities.company_id
         WHERE nonconformities.active = 1 AND nonconformities.due_date IS NOT NULL
           AND nonconformities.due_date < ? AND nonconformities.status NOT IN ('closed')"
        . $scopeNc['sql'] . '
         ORDER BY nonconformities.due_date ASC, nonconformities.id ASC'
    );
    $stmt->execute(array_merge([$today], $scopeNc['params']));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sections['nonconformities']['rows'][] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'company' => (string) $row['company_name'],
            'due' => (string) $row['due_date'],
            'extra' => (string) $row['responsible_person'],
            'link' => 'nonconformity-detail.php?id=' . (int) $row['id'],
        ];
    }

    // Geciken egitim.
    $scopeTrain = qmsCompanyScope('trainings.company_id', $companyIds);
    $stmt = $pdo->prepare(
        "SELECT trainings.id, trainings.title, trainings.planned_date, trainings.provider, companies.company_name
         FROM trainings INNER JOIN companies ON companies.id = trainings.company_id
         WHERE trainings.active = 1 AND trainings.planned_date IS NOT NULL
           AND trainings.planned_date < ? AND trainings.status NOT IN ('completed', 'cancelled')"
        . $scopeTrain['sql'] . '
         ORDER BY trainings.planned_date ASC, trainings.id ASC'
    );
    $stmt->execute(array_merge([$today], $scopeTrain['params']));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sections['trainings']['rows'][] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'company' => (string) $row['company_name'],
            'due' => (string) $row['planned_date'],
            'extra' => (string) $row['provider'],
            'link' => 'trainings.php',
        ];
    }

    // Kalibrasyonu secik ekipman.
    $scopeEq = qmsCompanyScope('equipment.company_id', $companyIds);
    $stmt = $pdo->prepare(
        "SELECT equipment.id, equipment.name, equipment.asset_code, equipment.next_calibration_date, companies.company_name
         FROM equipment INNER JOIN companies ON companies.id = equipment.company_id
         WHERE equipment.active = 1 AND equipment.next_calibration_date IS NOT NULL
           AND equipment.next_calibration_date < ? AND equipment.status NOT IN ('out_of_service')"
        . $scopeEq['sql'] . '
         ORDER BY equipment.next_calibration_date ASC, equipment.id ASC'
    );
    $stmt->execute(array_merge([$today], $scopeEq['params']));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sections['equipment']['rows'][] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['name'],
            'company' => (string) $row['company_name'],
            'due' => (string) $row['next_calibration_date'],
            'extra' => (string) $row['asset_code'],
            'link' => 'equipment.php',
        ];
    }

    // Acik dis denetim bulgulari (termin asmis).
    $scopeFind = qmsCompanyScope('ea.company_id', $companyIds);
    $stmt = $pdo->prepare(
        "SELECT eaf.id, eaf.finding_text, eaf.due_date, eaf.severity, co.company_name
         FROM external_audit_findings eaf
         INNER JOIN external_audits ea ON ea.id = eaf.external_audit_id
         INNER JOIN companies co ON co.id = ea.company_id
         WHERE eaf.active = 1 AND eaf.due_date IS NOT NULL AND eaf.due_date < ?
           AND eaf.status NOT IN ('closed')" . $scopeFind['sql'] . '
         ORDER BY eaf.due_date ASC, eaf.id ASC'
    );
    $stmt->execute(array_merge([$today], $scopeFind['params']));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sections['findings']['rows'][] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['finding_text'],
            'company' => (string) $row['company_name'],
            'due' => (string) $row['due_date'],
            'extra' => (string) $row['severity'],
            'link' => 'external-audits.php',
        ];
    }

    // Gozden gecirilecek dokumanlar.
    $scopeDoc = qmsCompanyScope('documents.company_id', $companyIds);
    $stmt = $pdo->prepare(
        "SELECT documents.id, documents.title, documents.document_code, documents.review_date, companies.company_name
         FROM documents INNER JOIN companies ON companies.id = documents.company_id
         WHERE documents.active = 1 AND documents.review_date IS NOT NULL
           AND documents.review_date < ? AND documents.status NOT IN ('archived')"
        . $scopeDoc['sql'] . '
         ORDER BY documents.review_date ASC, documents.id ASC'
    );
    $stmt->execute(array_merge([$today], $scopeDoc['params']));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sections['documents']['rows'][] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'company' => (string) $row['company_name'],
            'due' => (string) $row['review_date'],
            'extra' => (string) $row['document_code'],
            'link' => 'document-detail.php?id=' . (int) $row['id'],
        ];
    }

    // Termini gecen sikayet.
    $scopeComp = qmsCompanyScope('complaints.company_id', $companyIds);
    $stmt = $pdo->prepare(
        "SELECT complaints.id, complaints.subject, complaints.complaint_code, complaints.due_date,
                complaints.responsible_person, companies.company_name
         FROM complaints INNER JOIN companies ON companies.id = complaints.company_id
         WHERE complaints.active = 1 AND complaints.due_date IS NOT NULL
           AND complaints.due_date < ? AND complaints.status NOT IN ('closed', 'rejected')"
        . $scopeComp['sql'] . '
         ORDER BY complaints.due_date ASC, complaints.id ASC'
    );
    $stmt->execute(array_merge([$today], $scopeComp['params']));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sections['complaints']['rows'][] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['subject'],
            'company' => (string) $row['company_name'],
            'due' => (string) $row['due_date'],
            'extra' => (string) $row['complaint_code'],
            'link' => 'complaint-detail.php?id=' . (int) $row['id'],
        ];
    }

    foreach ($sections as $k => &$sec) {
        $sec['count'] = count($sec['rows']);
    }
    unset($sec);

    return $sections;
}

/**
 * Kullaniciya atanmis geciken kayitlar (bildirim merkezi icin, canli hesaplanir).
 *
 * @return array<int, array{label: string, text: string, company: string,
 *         due: string, link: string, sub: string}>
 */
function qmsUserOverdueAssignments(PDO $pdo, int $userId): array
{
    $today = date('Y-m-d');
    $items = [];

    $stmt = $pdo->prepare(
        "SELECT ca.id, ca.action_text, ca.due_date, ca.action_type, co.company_name
         FROM corrective_actions ca
         INNER JOIN nonconformities n ON n.id = ca.nonconformity_id
         INNER JOIN companies co ON co.id = n.company_id
         WHERE ca.active = 1 AND ca.responsible_user_id = ?
           AND ca.due_date IS NOT NULL AND ca.due_date < ?
           AND ca.status NOT IN ('completed', 'closed')
         ORDER BY ca.due_date ASC"
    );
    $stmt->execute([$userId, $today]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'label' => 'Düzeltici Faaliyet',
            'text' => (string) $r['action_text'],
            'company' => (string) $r['company_name'],
            'due' => (string) $r['due_date'],
            'link' => 'corrective-action-detail.php?id=' . (int) $r['id'],
            'sub' => (string) $r['action_type'],
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT c.id, c.subject, c.due_date, c.complaint_code, co.company_name
         FROM complaints c INNER JOIN companies co ON co.id = c.company_id
         WHERE c.active = 1 AND c.responsible_user_id = ?
           AND c.due_date IS NOT NULL AND c.due_date < ?
           AND c.status NOT IN ('closed', 'rejected')
         ORDER BY c.due_date ASC"
    );
    $stmt->execute([$userId, $today]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'label' => 'Şikayet',
            'text' => (string) $r['subject'],
            'company' => (string) $r['company_name'],
            'due' => (string) $r['due_date'],
            'link' => 'complaint-detail.php?id=' . (int) $r['id'],
            'sub' => (string) $r['complaint_code'],
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT e.id, e.name, e.asset_code, e.next_calibration_date, co.company_name
         FROM equipment e INNER JOIN companies co ON co.id = e.company_id
         WHERE e.active = 1 AND e.responsible_user_id = ?
           AND e.next_calibration_date IS NOT NULL AND e.next_calibration_date < ?
           AND e.status NOT IN ('out_of_service')
         ORDER BY e.next_calibration_date ASC"
    );
    $stmt->execute([$userId, $today]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'label' => 'Kalibrasyon',
            'text' => (string) $r['name'],
            'company' => (string) $r['company_name'],
            'due' => (string) $r['next_calibration_date'],
            'link' => 'equipment.php',
            'sub' => (string) $r['asset_code'],
        ];
    }

    usort($items, static fn(array $a, array $b): int => strcmp($a['due'], $b['due']));
    return $items;
}

/**
 * Denetci is yuku: her denetci icin atanmis aktif denetim, acik uygunsuzluk
 * ve acik duzeltici/onleyici faaliyet sayisi (kapsamli).
 *
 * @return array<int, array{id: int, name: string, company: string,
 *         assigned_audits: int, open_nonconformities: int, open_actions: int,
 *         total_open: int}>
 */
function qmsAuditorWorkload(PDO $pdo, int $userId, string $role): array
{
    $companyIds = qmsVisibleCompanyIds($pdo, $userId, $role);
    $scope = qmsCompanyScope('aud.company_id', $companyIds);

    $stmt = $pdo->prepare(
        "SELECT aud.id, aud.first_name, aud.last_name, aud.company_id, co.company_name
         FROM auditors aud INNER JOIN companies co ON co.id = aud.company_id
         WHERE aud.active = 1" . $scope['sql'] . ' ORDER BY aud.first_name, aud.last_name'
    );
    $stmt->execute($scope['params']);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $aud) {
        $audId = (int) $aud['id'];

        $asm = $pdo->prepare('SELECT COUNT(*) FROM audit_auditors aa INNER JOIN audits a ON a.id = aa.audit_id WHERE aa.auditor_id = ? AND a.active = 1');
        $asm->execute([$audId]);
        $assigned = (int) $asm->fetchColumn();

        $idm = $pdo->prepare('SELECT a.id FROM audit_auditors aa INNER JOIN audits a ON a.id = aa.audit_id WHERE aa.auditor_id = ? AND a.active = 1');
        $idm->execute([$audId]);
        $auditIds = array_map('intval', array_column($idm->fetchAll(PDO::FETCH_ASSOC), 'id'));

        $openNc = 0;
        $openAct = 0;
        if ($auditIds !== []) {
            $in = implode(',', $auditIds);
            $openNc = (int) $pdo->query(
                "SELECT COUNT(*) FROM nonconformities WHERE active = 1 AND status <> 'closed' AND audit_id IN ($in)"
            )->fetchColumn();
            $openAct = (int) $pdo->query(
                "SELECT COUNT(*) FROM corrective_actions ca
                 INNER JOIN nonconformities n ON n.id = ca.nonconformity_id
                 WHERE ca.active = 1 AND ca.status NOT IN ('completed', 'closed') AND n.audit_id IN ($in)"
            )->fetchColumn();
        }

        $rows[] = [
            'id' => $audId,
            'name' => trim((string) $aud['first_name'] . ' ' . (string) $aud['last_name']),
            'company' => (string) $aud['company_name'],
            'assigned_audits' => $assigned,
            'open_nonconformities' => $openNc,
            'open_actions' => $openAct,
            'total_open' => $openNc + $openAct,
        ];
    }
    return $rows;
}

/**
 * Geciken duzeltici faaliyet sayisi (menu rozeti icin tek sorgu).
 */
function qmsOverdueActionCount(PDO $pdo, int $userId, string $role): int
{
    $companyIds = qmsVisibleCompanyIds($pdo, $userId, $role);
    $scope = qmsCompanyScope('n.company_id', $companyIds);
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM corrective_actions ca
         INNER JOIN nonconformities n ON n.id = ca.nonconformity_id
         WHERE ca.active = 1 AND ca.due_date IS NOT NULL AND ca.due_date < CURDATE()
           AND ca.status NOT IN ('completed', 'closed')" . $scope['sql']
    );
    $stmt->execute($scope['params']);
    return (int) $stmt->fetchColumn();
}
