<?php

declare(strict_types=1);

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/risk-functions.php';
require_once __DIR__ . '/supplier-functions.php';
require_once __DIR__ . '/complaint-functions.php';
require_once __DIR__ . '/performance-functions.php';
require_once __DIR__ . '/review-functions.php';
require_once __DIR__ . '/audit-program-functions.php';
require_once __DIR__ . '/equipment-functions.php';

function buildReportExportData(PDO $pdo, int $userId, bool $isSuperAdmin, array $query): array
{
    // Kapsam tek kaynaktan (includes/access.php): sistem admini atandigi
    // sirketler, sirket kullanicisi kendi sirketi, denetci atandigi denetimlerin
    // sirketleri, super admin kisitlamasiz.
    $role = (string) ($_SESSION['qms_role'] ?? '');
    if ($isSuperAdmin) {
        $role = 'super_admin';
    } elseif (!in_array($role, ['system_admin', 'company_user', 'auditor'], true)) {
        $role = 'system_admin';
    }

    // DIKKAT: `?? []` YAZMAYIN. qmsVisibleCompanyIds super admin icin null
    // ("kisitlama yok") doner; [] ise "hicbir sirket" demektir. Sarmalamak super
    // adminin tum rapor verisini sifirlar.
    $companyIds = qmsVisibleCompanyIds($pdo, $userId, $role);

    $scope = qmsCompanyScope('companies.id', $companyIds);
    $scopeSql = $scope['sql'];
    $scopeParams = $scope['params'];

    $companyStmt = $pdo->prepare(
        'SELECT companies.id, companies.company_name FROM companies WHERE companies.active = 1'
        . $scopeSql . ' ORDER BY companies.company_name'
    );
    $companyStmt->execute($scopeParams);
    $companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);
    $allowedCompanyIds = array_map('intval', array_column($companies, 'id'));

    $defaultStart = date('Y-m-d', strtotime('-12 months +1 day'));
    $defaultEnd = date('Y-m-d');
    $startDate = (string) ($query['start_date'] ?? $defaultStart);
    $endDate = (string) ($query['end_date'] ?? $defaultEnd);
    $selectedCompanyId = (int) ($query['company_id'] ?? 0);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        $startDate = $defaultStart;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $endDate = $defaultEnd;
    }
    if ($startDate > $endDate) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }
    if ($selectedCompanyId > 0 && !in_array($selectedCompanyId, $allowedCompanyIds, true)) {
        $selectedCompanyId = 0;
    }

    $fetchRows = static function (string $sql, array $params) use ($pdo, $selectedCompanyId): array {
        if ($selectedCompanyId > 0) {
            $sql .= ' AND companies.id = ?';
            $params[] = $selectedCompanyId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    $periodParams = array_merge([$startDate . ' 00:00:00', $endDate . ' 23:59:59'], $scopeParams);
    $audits = $fetchRows(
        "SELECT audits.id, audits.company_id, audits.status, audits.created_at,
                audits.title, audits.audit_type, audits.planned_date, companies.company_name
         FROM audits INNER JOIN companies ON companies.id = audits.company_id
         WHERE audits.active = 1 AND audits.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );
    $nonconformities = $fetchRows(
        "SELECT nonconformities.id, nonconformities.company_id, nonconformities.status,
                nonconformities.created_at, nonconformities.updated_at, nonconformities.title,
                nonconformities.severity, nonconformities.responsible_person, nonconformities.due_date,
                companies.company_name
         FROM nonconformities INNER JOIN companies ON companies.id = nonconformities.company_id
         WHERE nonconformities.active = 1 AND nonconformities.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );
    $actions = $fetchRows(
        "SELECT corrective_actions.id, nonconformities.company_id, corrective_actions.status,
                corrective_actions.due_date, corrective_actions.created_at, corrective_actions.completed_at,
                corrective_actions.action_text, corrective_actions.responsible_person,
                companies.company_name
         FROM corrective_actions
         INNER JOIN nonconformities ON nonconformities.id = corrective_actions.nonconformity_id
         INNER JOIN companies ON companies.id = nonconformities.company_id
         WHERE corrective_actions.active = 1 AND corrective_actions.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );
    $risks = $fetchRows(
        "SELECT risks.id, risks.company_id, risks.title, risks.category, risks.status, risks.due_date,
                risks.initial_likelihood, risks.initial_impact, risks.residual_likelihood, risks.residual_impact,
                risks.created_at, companies.company_name
         FROM risks INNER JOIN companies ON companies.id = risks.company_id
         WHERE risks.active = 1 AND risks.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );
    $documents = $fetchRows(
        "SELECT documents.id, documents.company_id, documents.status, documents.review_date,
                documents.created_at, companies.company_name
         FROM documents INNER JOIN companies ON companies.id = documents.company_id
         WHERE documents.active = 1 AND documents.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );
    $reviewDocuments = $fetchRows(
        "SELECT documents.id, documents.company_id, documents.review_date, documents.status,
                companies.company_name
         FROM documents INNER JOIN companies ON companies.id = documents.company_id
         WHERE documents.active = 1 AND documents.status <> 'archived'" . $scopeSql,
        $scopeParams
    );
    $trainings = $fetchRows(
        "SELECT trainings.id, trainings.company_id, trainings.title, trainings.category,
                trainings.provider, trainings.status, trainings.planned_date, trainings.completed_date,
                trainings.created_at, companies.company_name,
                (SELECT COUNT(*) FROM training_participants
                  WHERE training_participants.training_id = trainings.id) AS participant_count,
                (SELECT COUNT(*) FROM training_participants
                  WHERE training_participants.training_id = trainings.id
                    AND training_participants.status = 'completed') AS participant_completed
         FROM trainings INNER JOIN companies ON companies.id = trainings.company_id
         WHERE trainings.active = 1 AND trainings.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );

    $suppliers = $fetchRows(
        "SELECT suppliers.id, suppliers.company_id, suppliers.name, suppliers.supplier_code,
                suppliers.category, suppliers.risk_class, suppliers.status, suppliers.approved_date,
                suppliers.created_at, companies.company_name
         FROM suppliers INNER JOIN companies ON companies.id = suppliers.company_id
         WHERE suppliers.active = 1 AND suppliers.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );

    // Tedarikci puanlari degerlendirmelerden hesaplanir (kopya kolon yok).
    $evaluationsBySupplier = [];
    $supplierIds = array_map('intval', array_column($suppliers, 'id'));
    if ($supplierIds !== []) {
        $marks = implode(',', array_fill(0, count($supplierIds), '?'));
        $evaluationStmt = $pdo->prepare(
            "SELECT * FROM supplier_evaluations WHERE supplier_id IN ($marks)"
        );
        $evaluationStmt->execute($supplierIds);
        foreach ($evaluationStmt->fetchAll(PDO::FETCH_ASSOC) as $evaluation) {
            $evaluationsBySupplier[(int) $evaluation['supplier_id']][] = $evaluation;
        }
    }

    $supplierSummaries = [];
    $supplierScoreTotal = 0.0;
    $supplierScoreCount = 0;
    foreach ($suppliers as $supplier) {
        $summary = qmsSupplierEvaluationSummary($evaluationsBySupplier[(int) $supplier['id']] ?? []);
        $supplierSummaries[(int) $supplier['id']] = $summary;
        if ($summary['average'] !== null) {
            $supplierScoreTotal += $summary['average'];
            $supplierScoreCount++;
        }
    }
    $supplierAverageScore = $supplierScoreCount > 0 ? round($supplierScoreTotal / $supplierScoreCount, 1) : null;

    $complaints = $fetchRows(
        "SELECT complaints.id, complaints.company_id, complaints.complaint_code, complaints.subject,
                complaints.source, complaints.channel, complaints.severity, complaints.status,
                complaints.received_date, complaints.due_date, complaints.closed_date,
                complaints.nonconformity_id, complaints.created_at, companies.company_name
         FROM complaints INNER JOIN companies ON companies.id = complaints.company_id
         WHERE complaints.active = 1 AND complaints.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );

    $performanceYear = (int) substr($endDate, 0, 4);
    $performanceTargets = $fetchRows(
        "SELECT performance_targets.id, companies.company_name,
                performance_targets.kpi_key, performance_targets.target_value,
                performance_targets.target_year, performance_targets.note
         FROM performance_targets
         INNER JOIN companies ON companies.id = performance_targets.company_id
         WHERE performance_targets.target_year = ?" . $scopeSql . "
         ORDER BY companies.company_name, performance_targets.kpi_key",
        array_merge([$performanceYear], $scopeParams)
    );

    $reviews = $fetchRows(
        "SELECT management_reviews.id, management_reviews.company_id, management_reviews.title,
                management_reviews.review_date, management_reviews.period_start, management_reviews.period_end,
                management_reviews.status, management_reviews.next_review_date, management_reviews.created_at,
                companies.company_name,
                (SELECT COUNT(*) FROM management_review_items
                  WHERE management_review_items.review_id = management_reviews.id) AS item_count,
                (SELECT COUNT(*) FROM management_review_items
                  WHERE management_review_items.review_id = management_reviews.id
                    AND management_review_items.item_type = 'action') AS action_count
         FROM management_reviews INNER JOIN companies ON companies.id = management_reviews.company_id
         WHERE management_reviews.active = 1 AND management_reviews.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );

    $auditPrograms = $fetchRows(
        "SELECT audit_programs.id, audit_programs.company_id, audit_programs.title,
                audit_programs.year, audit_programs.status, audit_programs.approved_date,
                audit_programs.created_at, companies.company_name,
                (SELECT COUNT(*) FROM audit_program_audits
                  WHERE audit_program_audits.program_id = audit_programs.id) AS audit_count
         FROM audit_programs INNER JOIN companies ON companies.id = audit_programs.company_id
         WHERE audit_programs.active = 1 AND audit_programs.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );

    $equipment = $fetchRows(
        "SELECT equipment.id, equipment.company_id, equipment.name, equipment.asset_code,
                equipment.category, equipment.status, equipment.next_calibration_date,
                equipment.created_at, companies.company_name,
                (SELECT COUNT(*) FROM calibrations WHERE calibrations.equipment_id = equipment.id AND calibrations.active = 1) AS calibration_count
         FROM equipment INNER JOIN companies ON companies.id = equipment.company_id
         WHERE equipment.active = 1 AND equipment.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );

    $equipmentList = [];
    $equipmentOverdue = 0;
    foreach ($equipment as $item) {
        $calStatus = qmsEquipmentCalibrationStatus($item);
        if ($calStatus === 'overdue') {
            $equipmentOverdue++;
        }
        $equipmentList[] = [
            'company_name' => $item['company_name'],
            'name' => $item['name'],
            'asset_code' => $item['asset_code'],
            'category' => $item['category'],
            'next_calibration_date' => $item['next_calibration_date'],
            'cal_status' => qmsEquipmentCalibrationStatusLabels()[$calStatus] ?? $calStatus,
            'calibration_count' => (int) $item['calibration_count'],
        ];
    }

    $openComplaints = array_filter(
        $complaints,
        static fn(array $item): bool => qmsComplaintIsOpen((string) $item['status'])
    );

    $closedNonconformities = array_filter(
        $nonconformities,
        static fn(array $item): bool => $item['status'] === 'closed'
    );
    $totalCloseDays = 0;
    foreach ($closedNonconformities as $item) {
        $createdAt = new DateTime($item['created_at']);
        $closedAt = new DateTime($item['updated_at'] ?: $item['created_at']);
        $totalCloseDays += max(0, (int) $createdAt->diff($closedAt)->format('%a'));
    }

    $completedActions = array_filter(
        $actions,
        static fn(array $item): bool => in_array($item['status'], ['completed', 'closed'], true)
    );
    $overdueActions = array_filter(
        $actions,
        static fn(array $item): bool => !empty($item['due_date'])
            && $item['due_date'] < date('Y-m-d')
            && !in_array($item['status'], ['completed', 'closed'], true)
    );
    $reviewThreshold = date('Y-m-d', strtotime('+30 days'));
    $reviewDue = array_filter(
        $reviewDocuments,
        static fn(array $item): bool => !empty($item['review_date']) && $item['review_date'] <= $reviewThreshold
    );

    $completedTrainings = array_filter(
        $trainings,
        static fn(array $item): bool => $item['status'] === 'completed'
    );

    $auditCount = count($audits);
    $nonconformityCount = count($nonconformities);
    $metrics = [
        'audit_count' => $auditCount,
        'nonconformity_rate' => $auditCount > 0 ? round(($nonconformityCount / $auditCount) * 100, 1) : 0,
        'action_completion_rate' => count($actions) > 0
            ? round((count($completedActions) / count($actions)) * 100, 1)
            : 0,
        'overdue_actions' => count($overdueActions),
        'average_close_days' => count($closedNonconformities) > 0
            ? round($totalCloseDays / count($closedNonconformities), 1)
            : 0,
        'review_due_documents' => count($reviewDue),
        'training_count' => count($trainings),
        'training_completion_rate' => count($trainings) > 0
            ? round((count($completedTrainings) / count($trainings)) * 100, 1)
            : 0,
        'supplier_count' => count($suppliers),
        'supplier_average_score' => $supplierAverageScore,
        'complaint_count' => count($complaints),
        'complaint_open_count' => count($openComplaints),
        'review_count' => count($reviews),
        'audit_program_count' => count($auditPrograms),
        'audit_program_active' => count(array_filter($auditPrograms, static fn(array $item): bool => $item['status'] === 'active')),
        'equipment_count' => count($equipment),
        'equipment_overdue' => $equipmentOverdue,
    ];

    $documentStatuses = ['draft' => 0, 'review' => 0, 'approved' => 0, 'published' => 0, 'archived' => 0];
    foreach ($documents as $document) {
        if (isset($documentStatuses[$document['status']])) {
            $documentStatuses[$document['status']]++;
        }
    }

    $months = [];
    $cursor = new DateTime(date('Y-m-01', strtotime($startDate)));
    $lastMonth = new DateTime(date('Y-m-01', strtotime($endDate)));
    while ($cursor <= $lastMonth && count($months) < 24) {
        $key = $cursor->format('Y-m');
        $months[$key] = ['label' => $cursor->format('m/Y'), 'audits' => 0, 'nonconformities' => 0];
        $cursor->modify('+1 month');
    }
    foreach ($audits as $audit) {
        $key = date('Y-m', strtotime($audit['created_at']));
        if (isset($months[$key])) {
            $months[$key]['audits']++;
        }
    }
    foreach ($nonconformities as $item) {
        $key = date('Y-m', strtotime($item['created_at']));
        if (isset($months[$key])) {
            $months[$key]['nonconformities']++;
        }
    }

    $companyPerformance = [];
    foreach ($companies as $company) {
        if ($selectedCompanyId > 0 && (int) $company['id'] !== $selectedCompanyId) {
            continue;
        }
        $companyPerformance[(int) $company['id']] = [
            'name' => $company['company_name'],
            'audits' => 0,
            'nonconformities' => 0,
            'actions' => 0,
            'completed' => 0,
            'trainings' => 0,
            'trainings_completed' => 0,
            'suppliers' => 0,
            'suppliers_approved' => 0,
            'complaints' => 0,
            'complaints_open' => 0,
            'reviews' => 0,
            'reviews_actions' => 0,
            'audit_programs' => 0,
            'audit_programs_active' => 0,
            'equipment' => 0,
            'equipment_overdue' => 0,
        ];
    }
    foreach ($audits as $item) {
        if (isset($companyPerformance[(int) $item['company_id']])) {
            $companyPerformance[(int) $item['company_id']]['audits']++;
        }
    }
    foreach ($nonconformities as $item) {
        if (isset($companyPerformance[(int) $item['company_id']])) {
            $companyPerformance[(int) $item['company_id']]['nonconformities']++;
        }
    }
    foreach ($actions as $item) {
        if (!isset($companyPerformance[(int) $item['company_id']])) {
            continue;
        }
        $companyPerformance[(int) $item['company_id']]['actions']++;
        if (in_array($item['status'], ['completed', 'closed'], true)) {
            $companyPerformance[(int) $item['company_id']]['completed']++;
        }
    }
    foreach ($trainings as $item) {
        if (!isset($companyPerformance[(int) $item['company_id']])) {
            continue;
        }
        $companyPerformance[(int) $item['company_id']]['trainings']++;
        if ($item['status'] === 'completed') {
            $companyPerformance[(int) $item['company_id']]['trainings_completed']++;
        }
    }
    foreach ($suppliers as $item) {
        if (!isset($companyPerformance[(int) $item['company_id']])) {
            continue;
        }
        $companyPerformance[(int) $item['company_id']]['suppliers']++;
        if ($item['status'] === 'approved') {
            $companyPerformance[(int) $item['company_id']]['suppliers_approved']++;
        }
    }
    foreach ($complaints as $item) {
        if (!isset($companyPerformance[(int) $item['company_id']])) {
            continue;
        }
        $companyPerformance[(int) $item['company_id']]['complaints']++;
        if (qmsComplaintIsOpen((string) $item['status'])) {
            $companyPerformance[(int) $item['company_id']]['complaints_open']++;
        }
    }
    foreach ($reviews as $item) {
        if (!isset($companyPerformance[(int) $item['company_id']])) {
            continue;
        }
        $companyPerformance[(int) $item['company_id']]['reviews']++;
        $companyPerformance[(int) $item['company_id']]['reviews_actions'] += (int) $item['action_count'];
    }
    foreach ($auditPrograms as $item) {
        if (!isset($companyPerformance[(int) $item['company_id']])) {
            continue;
        }
        $companyPerformance[(int) $item['company_id']]['audit_programs']++;
        if ($item['status'] === 'active') {
            $companyPerformance[(int) $item['company_id']]['audit_programs_active']++;
        }
    }
    foreach ($equipment as $item) {
        if (!isset($companyPerformance[(int) $item['company_id']])) {
            continue;
        }
        $companyPerformance[(int) $item['company_id']]['equipment']++;
        if (qmsEquipmentCalibrationStatus($item) === 'overdue') {
            $companyPerformance[(int) $item['company_id']]['equipment_overdue']++;
        }
    }

    $selectedCompanyName = 'Tüm şirketler';
    if ($selectedCompanyId > 0) {
        foreach ($companies as $company) {
            if ((int) $company['id'] === $selectedCompanyId) {
                $selectedCompanyName = $company['company_name'];
                break;
            }
        }
    }

    // Detay listeleri: rapor ciktilari KPI'larin yaninda kayit dokumu de verir.
    $auditList = [];
    foreach ($audits as $item) {
        $auditList[] = [
            'company_name' => $item['company_name'],
            'title' => $item['title'],
            'audit_type' => $item['audit_type'],
            'status' => $item['status'],
            'planned_date' => $item['planned_date'],
        ];
    }

    $nonconformityList = [];
    foreach ($nonconformities as $item) {
        $nonconformityList[] = [
            'company_name' => $item['company_name'],
            'title' => $item['title'],
            'severity' => $item['severity'],
            'status' => $item['status'],
            'responsible_person' => $item['responsible_person'],
            'due_date' => $item['due_date'],
        ];
    }

    $actionList = [];
    foreach ($actions as $item) {
        $actionList[] = [
            'company_name' => $item['company_name'],
            'action_text' => $item['action_text'],
            'responsible_person' => $item['responsible_person'],
            'status' => $item['status'],
            'due_date' => $item['due_date'],
            'completed_at' => $item['completed_at'],
        ];
    }

    // Risk seviyesi ve puani risk moduluyle ayni kaynaktan hesaplanir.
    $riskList = [];
    foreach ($risks as $item) {
        $initialScore = qmsRiskScore($item['initial_likelihood'] !== null ? (int) $item['initial_likelihood'] : null, $item['initial_impact'] !== null ? (int) $item['initial_impact'] : null);
        $residualScore = qmsRiskScore($item['residual_likelihood'] !== null ? (int) $item['residual_likelihood'] : null, $item['residual_impact'] !== null ? (int) $item['residual_impact'] : null);
        $effectiveScore = $residualScore ?? $initialScore;

        $riskList[] = [
            'company_name' => $item['company_name'],
            'title' => $item['title'],
            'category' => $item['category'],
            'initial_score' => $initialScore,
            'residual_score' => $residualScore,
            'level' => qmsRiskLevelLabel(qmsRiskLevel($effectiveScore)),
            'status' => $item['status'],
            'due_date' => $item['due_date'],
        ];
    }

    $trainingList = [];
    foreach ($trainings as $item) {
        $trainingList[] = [
            'company_name' => $item['company_name'],
            'title' => $item['title'],
            'category' => $item['category'],
            'provider' => $item['provider'],
            'status' => $item['status'],
            'planned_date' => $item['planned_date'],
            'completed_date' => $item['completed_date'],
            'participants' => (int) $item['participant_count'],
            'participants_completed' => (int) $item['participant_completed'],
        ];
    }

    $supplierStatusLabels = qmsSupplierStatusLabels();
    $supplierRiskLabels = qmsSupplierRiskLabels();
    $supplierList = [];
    foreach ($suppliers as $item) {
        $summary = $supplierSummaries[(int) $item['id']];
        $supplierList[] = [
            'company_name' => $item['company_name'],
            'name' => $item['name'],
            'supplier_code' => $item['supplier_code'],
            'category' => $item['category'],
            'risk_class' => $supplierRiskLabels[$item['risk_class']] ?? $item['risk_class'],
            'status' => $supplierStatusLabels[$item['status']] ?? $item['status'],
            'approved_date' => $item['approved_date'],
            'score' => $summary['average'],
            'last_evaluation' => $summary['latest_date'],
        ];
    }

    $complaintStatusLabels = qmsComplaintStatusLabels();
    $complaintSeverityLabels = qmsComplaintSeverityLabels();
    $complaintSourceLabels = qmsComplaintSourceLabels();
    $complaintList = [];
    foreach ($complaints as $item) {
        $complaintList[] = [
            'company_name' => $item['company_name'],
            'complaint_code' => $item['complaint_code'],
            'subject' => $item['subject'],
            'source' => $complaintSourceLabels[$item['source']] ?? $item['source'],
            'severity' => $complaintSeverityLabels[$item['severity']] ?? $item['severity'],
            'status' => $complaintStatusLabels[$item['status']] ?? $item['status'],
            'received_date' => $item['received_date'],
            'due_date' => $item['due_date'],
            'closed_date' => $item['closed_date'],
            'linked_nonconformity' => (int) $item['nonconformity_id'] > 0 ? 'Evet' : 'Hayır',
        ];
    }

    $performanceKpiLabels = qmsPerformanceKpiLabels();
    $performanceTargetList = [];
    foreach ($performanceTargets as $item) {
        $performanceTargetList[] = [
            'company_name' => $item['company_name'],
            'kpi' => $performanceKpiLabels[$item['kpi_key']] ?? $item['kpi_key'],
            'target_value' => $item['target_value'],
            'target_year' => $item['target_year'],
            'note' => $item['note'],
        ];
    }

    $reviewStatusLabels = qmsReviewStatusLabels();
    $reviewList = [];
    foreach ($reviews as $item) {
        $reviewList[] = [
            'company_name' => $item['company_name'],
            'title' => $item['title'],
            'review_date' => $item['review_date'],
            'period' => $item['period_start'] . ' - ' . $item['period_end'],
            'status' => $reviewStatusLabels[$item['status']] ?? $item['status'],
            'items' => (int) $item['item_count'],
            'actions' => (int) $item['action_count'],
            'next_review_date' => $item['next_review_date'],
        ];
    }

    $auditProgramStatusLabels = qmsAuditProgramStatusLabels();
    $auditProgramList = [];
    foreach ($auditPrograms as $item) {
        $auditProgramList[] = [
            'company_name' => $item['company_name'],
            'title' => $item['title'],
            'year' => (int) $item['year'],
            'status' => $auditProgramStatusLabels[$item['status']] ?? $item['status'],
            'linked_audits' => (int) $item['audit_count'],
            'approved_date' => $item['approved_date'],
        ];
    }

    return [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'company_id' => $selectedCompanyId,
        'company_name' => $selectedCompanyName,
        'metrics' => $metrics,
        'document_statuses' => $documentStatuses,
        'months' => array_values($months),
        'company_performance' => array_values($companyPerformance),
        'audit_list' => $auditList,
        'nonconformity_list' => $nonconformityList,
        'action_list' => $actionList,
        'risk_list' => $riskList,
        'training_list' => $trainingList,
        'supplier_list' => $supplierList,
        'complaint_list' => $complaintList,
        'performance_target_list' => $performanceTargetList,
        'review_list' => $reviewList,
        'audit_program_list' => $auditProgramList,
        'equipment_list' => $equipmentList,
    ];
}
