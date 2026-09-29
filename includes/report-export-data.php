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
require_once __DIR__ . '/audit-log-functions.php';

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

    $satisfactionResponses = $fetchRows(
        "SELECT responses.id, responses.company_id, responses.customer_name, responses.responded_at,
                responses.overall_score, responses.comment, companies.company_name
         FROM satisfaction_responses responses
         INNER JOIN companies ON companies.id = responses.company_id
         WHERE responses.active = 1 AND responses.responded_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );
    $satisfactionList = [];
    $satisfactionScoreSum = 0;
    foreach ($satisfactionResponses as $item) {
        $satisfactionScoreSum += (int) $item['overall_score'];
        $satisfactionList[] = [
            'company_name' => $item['company_name'],
            'customer_name' => $item['customer_name'] ?: '-',
            'responded_at' => $item['responded_at'],
            'overall_score' => (int) $item['overall_score'],
            'comment' => $item['comment'] ?: '-',
        ];
    }
    $satisfactionCount = count($satisfactionResponses);
    $satisfactionAvg = $satisfactionCount > 0 ? round($satisfactionScoreSum / $satisfactionCount, 1) : 0;

    $today = date('Y-m-d');
    $staff = $fetchRows(
        "SELECT s.id, s.company_id, s.first_name, s.last_name, s.employee_code, s.department,
                s.position, s.created_at, companies.company_name,
                (SELECT COUNT(*) FROM staff_competencies c
                  WHERE c.staff_id = s.id AND c.active = 1) AS competency_count
         FROM staff_members s
         INNER JOIN companies ON companies.id = s.company_id
         WHERE s.active = 1 AND s.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );
    $staffIds = [];
    $personnelList = [];
    foreach ($staff as $item) {
        $staffIds[] = (int) $item['id'];
        $personnelList[] = [
            'company_name' => $item['company_name'],
            'name' => $item['first_name'] . ' ' . $item['last_name'],
            'employee_code' => $item['employee_code'] ?: '-',
            'department' => $item['department'] ?: '-',
            'position' => $item['position'] ?: '-',
            'competency_count' => (int) $item['competency_count'],
        ];
    }
    $externalAudits = $fetchRows(
        "SELECT a.id, a.company_id, a.audit_type, a.title, a.audited_by, a.audit_date, a.status,
                a.created_at, companies.company_name,
                (SELECT COUNT(*) FROM external_audit_findings f
                  WHERE f.external_audit_id = a.id AND f.active = 1 AND f.status <> 'closed') AS open_findings
         FROM external_audits a
         INNER JOIN companies ON companies.id = a.company_id
         WHERE a.active = 1 AND a.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );
    $qualityCosts = $fetchRows(
        "SELECT c.id, c.company_id, c.cost_type, c.title, c.amount, c.incurred_on, companies.company_name
         FROM quality_costs c
         INNER JOIN companies ON companies.id = c.company_id
         WHERE c.active = 1 AND c.incurred_on BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );
    $qualityCostTotal = 0.0;
    $qualityCostFailure = 0.0;
    $qualityCostList = [];
    foreach ($qualityCosts as $item) {
        $amt = (float) $item['amount'];
        $qualityCostTotal += $amt;
        if (in_array($item['cost_type'], ['internal_failure', 'external_failure'], true)) {
            $qualityCostFailure += $amt;
        }
        $qualityCostList[] = [
            'company_name' => $item['company_name'],
            'title' => $item['title'],
            'cost_type' => $item['cost_type'],
            'amount' => $amt,
            'incurred_on' => $item['incurred_on'],
        ];
    }
    $qualityCostTotal = round($qualityCostTotal, 2);
    $qualityCostFailure = round($qualityCostFailure, 2);

    $copies = $fetchRows(
        "SELECT c.id, c.company_id, c.copy_no, c.recipient_name, c.status, c.distributed_on,
                c.returned_on, companies.company_name, documents.document_code,
                documents.title AS document_title
         FROM document_copies c
         INNER JOIN companies ON companies.id = c.company_id
         INNER JOIN documents ON documents.id = c.document_id
         WHERE c.active = 1 AND c.distributed_on BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );
    $approvalRuns = $fetchRows(
        "SELECT r.id, r.company_id, r.subject, r.status, r.created_at, companies.company_name
         FROM approval_runs r
         INNER JOIN companies ON companies.id = r.company_id
         WHERE r.active = 1 AND r.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );
    $approvalRunCount = count($approvalRuns);
    $approvalRunApproved = 0;
    $approvalRunPending = 0;
    $approvalRunList = [];
    foreach ($approvalRuns as $item) {
        if ($item['status'] === 'approved') {
            $approvalRunApproved++;
        }
        if ($item['status'] === 'in_progress') {
            $approvalRunPending++;
        }
        $approvalRunList[] = [
            'company_name' => $item['company_name'],
            'subject' => $item['subject'],
            'status' => $item['status'],
            'created_at' => $item['created_at'],
        ];
    }

    $copyCount = count($copies);
    $copyReturned = 0;
    $copyList = [];
    foreach ($copies as $item) {
        if ($item['status'] === 'returned') {
            $copyReturned++;
        }
        $copyList[] = [
            'company_name' => $item['company_name'],
            'document_code' => $item['document_code'],
            'document_title' => $item['document_title'],
            'copy_no' => $item['copy_no'],
            'recipient_name' => $item['recipient_name'],
            'status' => $item['status'],
            'distributed_on' => $item['distributed_on'],
        ];
    }

    $externalAuditCount = count($externalAudits);
    $externalAuditOpen = 0;
    $externalAuditList = [];
    foreach ($externalAudits as $item) {
        $externalAuditOpen += (int) $item['open_findings'];
        $externalAuditList[] = [
            'company_name' => $item['company_name'],
            'title' => $item['title'],
            'audit_type' => $item['audit_type'],
            'audited_by' => $item['audited_by'] ?: '-',
            'audit_date' => $item['audit_date'] ?: '-',
            'status' => $item['status'],
            'open_findings' => (int) $item['open_findings'],
        ];
    }

    $personnelCount = count($staff);
    $personnelExpired = 0;
    if ($staffIds !== []) {
        $marks = implode(',', array_fill(0, count($staffIds), '?'));
        $expStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM staff_competencies
             WHERE staff_id IN ($marks) AND active = 1
               AND next_assessment_date IS NOT NULL AND next_assessment_date < ?"
        );
        $expStmt->execute(array_merge($staffIds, [$today]));
        $personnelExpired = (int) $expStmt->fetchColumn();
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

    $periodPair = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];
    $internalParams = array_merge($periodPair, $periodPair, $periodPair, $scopeParams);
    $internalSurveySql =
        'SELECT s.id, s.title, companies.company_name AS company_name,
                (SELECT COUNT(DISTINCT r.user_id) FROM internal_survey_responses r
                 WHERE r.survey_id = s.id AND r.submitted_at BETWEEN ? AND ?) AS respondents,
                (SELECT COUNT(r.id) FROM internal_survey_responses r
                 WHERE r.survey_id = s.id AND r.submitted_at BETWEEN ? AND ?) AS answers,
                (SELECT AVG(r.rating) FROM internal_survey_responses r
                 WHERE r.survey_id = s.id AND r.submitted_at BETWEEN ? AND ?) AS avg_rating
         FROM internal_surveys s
         INNER JOIN companies ON companies.id = s.company_id
         WHERE s.active = 1 AND s.published = 1' . $scopeSql;
    if ($selectedCompanyId > 0) {
        $internalSurveySql .= ' AND companies.id = ?';
        $internalParams[] = $selectedCompanyId;
    }
    $internalSurveySql .= ' ORDER BY s.id DESC';
    $internalSurveyStmt = $pdo->prepare($internalSurveySql);
    $internalSurveyStmt->execute($internalParams);
    $internalSurveys = $internalSurveyStmt->fetchAll(PDO::FETCH_ASSOC);
    $internalSurveyList = [];
    $internalSurveyRespondents = 0;
    $internalSurveyAnswers = 0;
    $internalSurveyScoreSum = 0.0;
    foreach ($internalSurveys as $item) {
        $internalSurveyRespondents += (int) $item['respondents'];
        $internalSurveyAnswers += (int) $item['answers'];
        if ($item['avg_rating'] !== null) {
            $internalSurveyScoreSum += (float) $item['avg_rating'] * (int) $item['answers'];
        }
        $internalSurveyList[] = [
            'company_name' => $item['company_name'],
            'title' => $item['title'],
            'respondents' => (int) $item['respondents'],
            'answers' => (int) $item['answers'],
            'avg_rating' => $item['avg_rating'] !== null ? round((float) $item['avg_rating'], 1) : null,
        ];
    }
    $internalSurveyAvg = $internalSurveyAnswers > 0 ? round($internalSurveyScoreSum / $internalSurveyAnswers, 1) : null;

    $improvementsRaw = $fetchRows(
        "SELECT i.id, i.company_id, i.title, i.category, i.benefit_type, i.impact, i.priority, i.status, i.target_date, companies.company_name
         FROM improvements i
         INNER JOIN companies ON companies.id = i.company_id
         WHERE i.active = 1 AND i.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );
    usort($improvementsRaw, static fn(array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);
    $improvementStatusLabels = ['submitted' => 'Önerildi', 'under_review' => 'Değerlendirmede', 'approved' => 'Onaylandı', 'rejected' => 'Reddedildi', 'implemented' => 'Uygulandı', 'closed' => 'Kapandı'];
    $improvementList = [];
    $improvementOpen = 0;
    $improvementImplemented = 0;
    foreach ($improvementsRaw as $item) {
        $st = (string) $item['status'];
        if (!in_array($st, ['rejected', 'closed', 'implemented'], true)) {
            $improvementOpen++;
        }
        if ($st === 'implemented') {
            $improvementImplemented++;
        }
        $improvementList[] = [
            'company_name' => $item['company_name'],
            'title' => $item['title'],
            'category' => $item['category'],
            'benefit_type' => $item['benefit_type'],
            'impact' => $item['impact'],
            'priority' => $item['priority'],
            'status_label' => $improvementStatusLabels[$st] ?? $st,
            'target_date' => $item['target_date'],
        ];
    }
    $improvementCount = count($improvementList);

    $contractsRaw = $fetchRows(
        "SELECT c.id, c.contract_code, c.contract_name, c.party_name, c.contract_type, c.start_date, c.end_date,
                c.renewal_date, c.value_amount, c.currency, c.status, companies.company_name
         FROM contracts c
         INNER JOIN companies ON companies.id = c.company_id
         WHERE c.active = 1" . $scopeSql,
        $scopeParams
    );
    usort($contractsRaw, static fn(array $a, array $b): int => strcmp((string) $a['contract_name'], (string) $b['contract_name']));
    $contractStatusLabels = ['active' => 'Aktif', 'expiring' => 'Süresi Doluyor', 'expired' => 'Süresi Doldu', 'terminated' => 'Feshedildi'];
    $contractTypeLabels = ['customer' => 'Müşteri', 'supplier' => 'Tedarikçi', 'other' => 'Diğer'];
    $contractList = [];
    $contractActive = 0;
    $contractExpiring = 0;
    $expiringCut = date('Y-m-d', strtotime('+60 days'));
    $todayDate = date('Y-m-d');
    foreach ($contractsRaw as $item) {
        $isActive = (string) $item['status'] === 'active';
        $near = $isActive && $item['end_date'] !== null && (string) $item['end_date'] <= $expiringCut && (string) $item['end_date'] >= $todayDate;
        if ($isActive) {
            $contractActive++;
        }
        if ($near) {
            $contractExpiring++;
        }
        $contractList[] = [
            'company_name' => $item['company_name'],
            'contract_code' => $item['contract_code'],
            'contract_name' => $item['contract_name'],
            'party_name' => $item['party_name'],
            'type_label' => $contractTypeLabels[$item['contract_type']] ?? $item['contract_type'],
            'start_date' => $item['start_date'],
            'end_date' => $item['end_date'],
            'renewal_date' => $item['renewal_date'],
            'value_amount' => $item['value_amount'] !== null ? (float) $item['value_amount'] : null,
            'currency' => $item['currency'],
            'status_label' => $contractStatusLabels[$item['status']] ?? $item['status'],
        ];
    }
    $contractCount = count($contractList);

    $instrumentsRaw = $fetchRows(
        "SELECT i.id, i.instrument_code, i.name, i.instrument_type, i.location, i.interval_months,
                i.last_calibration_date, i.next_calibration_date, i.status, companies.company_name
         FROM instruments i
         INNER JOIN companies ON companies.id = i.company_id
         WHERE i.active = 1" . $scopeSql,
        $scopeParams
    );
    usort($instrumentsRaw, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
    $instrumentList = [];
    $instrumentActive = 0;
    $instrumentOverdue = 0;
    $todayDateI = date('Y-m-d');
    foreach ($instrumentsRaw as $item) {
        $isActive = (string) $item['status'] === 'active';
        $overdue = $isActive && $item['next_calibration_date'] !== null && (string) $item['next_calibration_date'] < $todayDateI;
        if ($isActive) {
            $instrumentActive++;
        }
        if ($overdue) {
            $instrumentOverdue++;
        }
        $instrumentList[] = [
            'company_name' => $item['company_name'],
            'instrument_code' => $item['instrument_code'],
            'name' => $item['name'],
            'instrument_type' => $item['instrument_type'],
            'location' => $item['location'],
            'next_calibration_date' => $item['next_calibration_date'],
            'status_label' => $item['status'] === 'active' ? 'Aktif' : 'Hizmet Dışı',
        ];
    }
    $instrumentCount = count($instrumentList);

    $incidentsRaw = $fetchRows(
        "SELECT i.id, i.incident_code, i.title, i.incident_type, i.severity, i.status, i.reported_at, i.responsible, companies.company_name
         FROM incidents i
         INNER JOIN companies ON companies.id = i.company_id
         WHERE i.active = 1" . $scopeSql,
        $scopeParams
    );
    usort($incidentsRaw, static fn(array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);
    $incidentTypeLabels = ['accident' => 'Kaza', 'near_miss' => 'Ramak Kala', 'quality' => 'Kalite', 'security' => 'Güvenlik', 'other' => 'Diğer'];
    $incidentStatusLabels = ['open' => 'Açık', 'under_review' => 'İnceleniyor', 'investigation' => 'Soruşturuluyor', 'closed' => 'Kapandı'];
    $incidentList = [];
    $incidentOpen = 0;
    $incidentCritical = 0;
    foreach ($incidentsRaw as $item) {
        if ($item['status'] !== 'closed') {
            $incidentOpen++;
        }
        if ($item['severity'] === 'critical') {
            $incidentCritical++;
        }
        $incidentList[] = [
            'company_name' => $item['company_name'],
            'incident_code' => $item['incident_code'],
            'title' => $item['title'],
            'type_label' => $incidentTypeLabels[$item['incident_type']] ?? $item['incident_type'],
            'severity_label' => ['low' => 'Düşük', 'medium' => 'Orta', 'high' => 'Yüksek', 'critical' => 'Kritik'][$item['severity']] ?? $item['severity'],
            'status_label' => $incidentStatusLabels[$item['status']] ?? $item['status'],
            'reported_at' => $item['reported_at'],
            'responsible' => $item['responsible'],
        ];
    }
    $incidentCount = count($incidentList);

    $deliveriesRaw = $fetchRows(
        "SELECT d.id, d.customer_name, d.period, d.orders_total, d.on_time_orders,
                d.quantity_delivered, d.quantity_rejected, companies.company_name
         FROM delivery_performance d
         INNER JOIN companies ON companies.id = d.company_id
         WHERE d.active = 1" . $scopeSql,
        $scopeParams
    );
    usort($deliveriesRaw, static fn(array $a, array $b): int => strcmp((string) $b['period'], (string) $a['period']) ?: strcmp((string) $a['customer_name'], (string) $b['customer_name']));
    $deliveryList = [];
    $deliveryOrders = 0;
    $deliveryOnTime = 0;
    $deliveryRejected = 0;
    foreach ($deliveriesRaw as $item) {
        $deliveryOrders += (int) $item['orders_total'];
        $deliveryOnTime += (int) $item['on_time_orders'];
        $deliveryRejected += (int) $item['quantity_rejected'];
        $total = (int) $item['orders_total'];
        $deliveryList[] = [
            'company_name' => $item['company_name'],
            'customer_name' => $item['customer_name'],
            'period' => $item['period'],
            'orders_total' => $total,
            'on_time_orders' => (int) $item['on_time_orders'],
            'quantity_delivered' => (int) $item['quantity_delivered'],
            'quantity_rejected' => (int) $item['quantity_rejected'],
            'on_time_rate' => $total > 0 ? round(((int) $item['on_time_orders'] / $total) * 100, 1) : 0.0,
        ];
    }
    $deliveryCount = count($deliveryList);
    $deliveryOnTimeRate = $deliveryOrders > 0 ? round(($deliveryOnTime / $deliveryOrders) * 100, 1) : 0.0;

    $calibrationsRaw = $fetchRows(
        "SELECT k.id, k.instrument_id, k.calibration_date, k.due_date, k.result, k.cert_number, k.lab_name,
                i.name AS instrument_name, i.instrument_code, companies.company_name
         FROM instrument_calibrations k
         INNER JOIN instruments i ON i.id = k.instrument_id
         INNER JOIN companies ON companies.id = k.company_id
         WHERE k.active = 1" . $scopeSql,
        $scopeParams
    );
    usort($calibrationsRaw, static fn(array $a, array $b): int => strcmp((string) $a['instrument_name'], (string) $b['instrument_name']) ?: strcmp((string) $b['calibration_date'], (string) $a['calibration_date']));
    $calibrationList = [];
    $calibrationFail = 0;
    foreach ($calibrationsRaw as $item) {
        if ($item['result'] === 'fail') {
            $calibrationFail++;
        }
        $calibrationList[] = [
            'company_name' => $item['company_name'],
            'instrument_name' => $item['instrument_name'],
            'instrument_code' => $item['instrument_code'],
            'calibration_date' => $item['calibration_date'],
            'due_date' => $item['due_date'],
            'result' => $item['result'],
            'result_label' => ['pass' => 'Başarılı', 'fail' => 'Başarısız'][$item['result']] ?? $item['result'],
            'lab_name' => $item['lab_name'],
            'cert_number' => $item['cert_number'],
        ];
    }
    $calibrationCount = count($calibrationList);

    // Denetim bulgulari (acik; kapsamli).
    $findingsList = [];
    $findingsRaw = $fetchRows(
        "SELECT cit.item_text, cit.requirement_ref, a.title AS audit_title,
                a.planned_date, companies.company_name, nc.status AS nc_status
         FROM audit_checklist_items cit
         INNER JOIN audits a ON a.id = cit.audit_id
         INNER JOIN companies ON companies.id = a.company_id
         LEFT JOIN nonconformities nc ON nc.checklist_item_id = cit.id AND nc.active = 1
         WHERE cit.active = 1 AND cit.result_status = 'noncompliant'
           AND (nc.id IS NULL OR nc.status <> 'closed')" . $scopeSql, $scopeParams
    );
    foreach ($findingsRaw as $f) {
        $findingsList[] = [
            'company_name' => $f['company_name'],
            'audit_title' => $f['audit_title'],
            'item_text' => $f['item_text'],
            'requirement_ref' => $f['requirement_ref'] ?: null,
            'planned_date' => $f['planned_date'],
            'nc_status' => $f['nc_status'] ?: null,
        ];
    }

    // Kok neden analizi: analiz bekleyen (acik ve analysiz) uygunsuzluklar.
    $rootCauseList = [];
    $rootCauseRaw = $fetchRows(
        "SELECT n.title AS nc_title, n.severity, companies.company_name
         FROM nonconformities n
         INNER JOIN companies ON companies.id = n.company_id
         LEFT JOIN nc_root_cause rc ON rc.nonconformity_id = n.id AND rc.active = 1
         WHERE n.active = 1 AND n.status <> 'closed' AND rc.id IS NULL" . $scopeSql, $scopeParams
    );
    foreach ($rootCauseRaw as $r) {
        $rootCauseList[] = [
            'company_name' => $r['company_name'],
            'nc_title' => $r['nc_title'],
            'severity' => $r['severity'],
        ];
    }

    // Dagitim & imza onayi bekleyen kopyalar.
    $docConfirmList = [];
    $docConfirmRaw = $fetchRows(
        "SELECT dc.copy_no, dc.recipient_name, dc.location, documents.title AS document_title,
                companies.company_name, dc.received_confirmed
         FROM document_copies dc
         INNER JOIN documents ON documents.id = dc.document_id
         INNER JOIN companies ON companies.id = dc.company_id
         WHERE dc.active = 1 AND dc.status = 'distributed'" . $scopeSql, $scopeParams
    );
    foreach ($docConfirmRaw as $d) {
        $docConfirmList[] = [
            'company_name' => $d['company_name'],
            'document_title' => $d['document_title'],
            'copy_no' => $d['copy_no'],
            'recipient_name' => $d['recipient_name'],
            'location' => $d['location'],
            'confirmed' => (int) $d['received_confirmed'] === 1,
        ];
    }

    // Dogrulama bekleyen faaliyetler.
    $verificationList = [];
    $verificationRaw = $fetchRows(
        "SELECT ca.action_text, ca.due_date, n.title AS nc_title, n.severity,
                companies.company_name
         FROM corrective_actions ca
         INNER JOIN nonconformities n ON n.id = ca.nonconformity_id
         INNER JOIN companies ON companies.id = n.company_id
         WHERE ca.active = 1 AND ca.status = 'verification'" . $scopeSql, $scopeParams
    );
    foreach ($verificationRaw as $v) {
        $verificationList[] = [
            'company_name' => $v['company_name'],
            'action_text' => $v['action_text'],
            'nc_title' => $v['nc_title'],
            'severity' => $v['severity'],
            'due_date' => $v['due_date'],
        ];
    }

    // Denetim izi: donemdeki kayitlar (kapsamli) + ozet. $fetchRows kullanilmaz
    // cunku bu yardimci SQL'in sonuna 'AND companies.id = ?' ekler ve ORDER BY/LIMIT
    // ile bozulur; burada kapsam + secili sirket filtreleri acikca kurulur.
    $auditCompanyId = (int) ($query['company_id'] ?? 0);
    $auditSql = "SELECT audit_log.id, audit_log.entity_type, audit_log.action, audit_log.summary,
                audit_log.created_at, companies.company_name, users.full_name AS actor_name
         FROM audit_log
         LEFT JOIN companies ON companies.id = audit_log.company_id
         LEFT JOIN users ON users.id = audit_log.actor_user_id
         WHERE audit_log.created_at BETWEEN ? AND ?" . $scopeSql;
    $auditP = $periodParams;
    if ($auditCompanyId > 0) {
        $auditSql .= ' AND audit_log.company_id = ?';
        $auditP[] = $auditCompanyId;
    }
    $auditSql .= ' ORDER BY audit_log.id DESC LIMIT 300';
    $auditStmt = $pdo->prepare($auditSql);
    $auditStmt->execute($auditP);
    $auditTrailRaw = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
    $auditAgg = qmsAuditLogAggregate($auditTrailRaw);
    $entityLabels = qmsAuditLogEntityLabels();
    $actionLabelsQ = qmsAuditLogActionLabels();
    $auditTrailList = [];
    foreach ($auditTrailRaw as $item) {
        $auditTrailList[] = [
            'company_name' => $item['company_name'] ?? 'Sistem',
            'actor_name' => $item['actor_name'] ?? 'Sistem',
            'entity_label' => $entityLabels[$item['entity_type']] ?? $item['entity_type'],
            'action_label' => $actionLabelsQ[$item['action']] ?? $item['action'],
            'summary' => (string) $item['summary'],
            'created_at' => $item['created_at'],
        ];
    }
    $countSql = 'SELECT COUNT(*) FROM audit_log LEFT JOIN companies ON companies.id = audit_log.company_id WHERE audit_log.created_at BETWEEN ? AND ?' . $scopeSql;
    $countP = $periodParams;
    if ($auditCompanyId > 0) {
        $countSql .= ' AND audit_log.company_id = ?';
        $countP[] = $auditCompanyId;
    }
    $auditCountStmt = $pdo->prepare($countSql);
    $auditCountStmt->execute($countP);
    $auditTrailTotal = (int) $auditCountStmt->fetchColumn();

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
        'satisfaction_count' => $satisfactionCount,
        'satisfaction_avg' => $satisfactionAvg,
        'personnel_count' => $personnelCount,
        'personnel_expired' => $personnelExpired,
        'external_audit_count' => $externalAuditCount,
        'external_audit_open' => $externalAuditOpen,
        'quality_cost_total' => $qualityCostTotal,
        'quality_cost_failure' => $qualityCostFailure,
        'copy_count' => $copyCount,
        'copy_returned' => $copyReturned,
        'approval_run_count' => $approvalRunCount,
        'approval_run_approved' => $approvalRunApproved,
        'approval_run_pending' => $approvalRunPending,
        'internal_survey_count' => count($internalSurveyList),
        'internal_survey_respondents' => $internalSurveyRespondents,
        'internal_survey_avg' => $internalSurveyAvg,
        'improvement_count' => $improvementCount,
        'improvement_open' => $improvementOpen,
        'improvement_implemented' => $improvementImplemented,
        'contract_count' => $contractCount,
        'contract_active' => $contractActive,
        'contract_expiring' => $contractExpiring,
        'instrument_count' => $instrumentCount,
        'instrument_active' => $instrumentActive,
        'instrument_overdue' => $instrumentOverdue,
        'incident_count' => $incidentCount,
        'incident_open' => $incidentOpen,
        'incident_critical' => $incidentCritical,
        'delivery_count' => $deliveryCount,
        'delivery_ontime_rate' => $deliveryOnTimeRate,
        'delivery_rejected' => $deliveryRejected,
        'calibration_count' => $calibrationCount,
        'calibration_fail' => $calibrationFail,
        'audit_trail_count' => $auditTrailTotal,
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

    // COQ aylik trend (rapor donemi icinde, kategori bazinda toplamlar).
    $qualityCostTrend = [];
    foreach ($months as $key => $m) {
        $qualityCostTrend[$key] = [
            'label' => $m['label'],
            'prevention' => 0.0,
            'appraisal' => 0.0,
            'internal_failure' => 0.0,
            'external_failure' => 0.0,
            'total' => 0.0,
        ];
    }
    foreach ($qualityCosts as $item) {
        $key = date('Y-m', strtotime($item['incurred_on']));
        if (!isset($qualityCostTrend[$key])) {
            continue;
        }
        $amt = (float) $item['amount'];
        if (isset($qualityCostTrend[$key][$item['cost_type']])) {
            $qualityCostTrend[$key][$item['cost_type']] += $amt;
            $qualityCostTrend[$key]['total'] += $amt;
        }
    }
    foreach ($qualityCostTrend as $key => $row) {
        foreach (['prevention', 'appraisal', 'internal_failure', 'external_failure', 'total'] as $col) {
            $qualityCostTrend[$key][$col] = round($row[$col], 2);
        }
    }
    $qualityCostTrend = array_values($qualityCostTrend);

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

    // Vadesi gecen yetkinlik degerlendirmeleri (personel bazinda).
    $compScope = qmsCompanyScope('s.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $compScopeSql = $compScope['sql'];
    $compScopeParams = $compScope['params'];
    $competencyOverdueList = [];
    $compStmt = $pdo->prepare(
        "SELECT s.first_name, s.last_name, co.company_name, sc.competency_name, sc.next_assessment_date
         FROM staff_competencies sc
         INNER JOIN staff_members s ON s.id = sc.staff_id
         INNER JOIN companies co ON co.id = s.company_id
         WHERE sc.active = 1 AND sc.next_assessment_date IS NOT NULL AND sc.next_assessment_date < ?"
        . $compScopeSql
    );
    $compStmt->execute(array_merge([date('Y-m-d')], $compScopeParams));
    foreach ($compStmt->fetchAll(PDO::FETCH_ASSOC) as $compRow) {
        $competencyOverdueList[] = [
            'person' => trim((string) $compRow['first_name'] . ' ' . (string) $compRow['last_name']),
            'company' => (string) $compRow['company_name'],
            'competency' => (string) $compRow['competency_name'],
            'due_date' => (string) $compRow['next_assessment_date'],
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
        'satisfaction_list' => $satisfactionList,
        'personnel_list' => $personnelList,
        'external_audit_list' => $externalAuditList,
        'quality_cost_list' => $qualityCostList,
        'quality_cost_trend' => $qualityCostTrend,
        'copy_list' => $copyList,
        'approval_run_list' => $approvalRunList,
        'internal_survey_list' => $internalSurveyList,
        'improvement_list' => $improvementList,
        'contract_list' => $contractList,
        'instrument_list' => $instrumentList,
        'incident_list' => $incidentList,
        'delivery_list' => $deliveryList,
        'competency_overdue_list' => $competencyOverdueList,
        'calibration_list' => $calibrationList,
        'findings_list' => $findingsList,
        'root_cause_list' => $rootCauseList,
        'doc_confirm_list' => $docConfirmList,
        'verification_list' => $verificationList,
        'audit_trail_list' => $auditTrailList,
        'audit_trail_entity' => $auditAgg['entity'],
        'audit_trail_action' => $auditAgg['action'],
    ];
}
