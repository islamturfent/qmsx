<?php

declare(strict_types=1);

function buildReportExportData(PDO $pdo, int $userId, bool $isSuperAdmin, array $query): array
{
    $companyIds = [];
    if (!$isSuperAdmin) {
        $assignmentStmt = $pdo->prepare(
            "SELECT company_id FROM company_admin_assignments WHERE admin_user_id = :user_id AND active = 1"
        );
        $assignmentStmt->execute(["user_id" => $userId]);
        $companyIds = array_map('intval', $assignmentStmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $scopeSql = '';
    $scopeParams = [];
    if (!$isSuperAdmin) {
        if ($companyIds) {
            $scopeSql = ' AND companies.id IN (' . implode(',', array_fill(0, count($companyIds), '?')) . ')';
            $scopeParams = $companyIds;
        } else {
            $scopeSql = ' AND 1 = 0';
        }
    }

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
        "SELECT audits.id, audits.company_id, audits.status, audits.created_at, companies.company_name
         FROM audits INNER JOIN companies ON companies.id = audits.company_id
         WHERE audits.active = 1 AND audits.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );
    $nonconformities = $fetchRows(
        "SELECT nonconformities.id, nonconformities.company_id, nonconformities.status,
                nonconformities.created_at, nonconformities.updated_at, companies.company_name
         FROM nonconformities INNER JOIN companies ON companies.id = nonconformities.company_id
         WHERE nonconformities.active = 1 AND nonconformities.created_at BETWEEN ? AND ?" . $scopeSql,
        $periodParams
    );
    $actions = $fetchRows(
        "SELECT corrective_actions.id, nonconformities.company_id, corrective_actions.status,
                corrective_actions.due_date, corrective_actions.created_at, corrective_actions.completed_at,
                companies.company_name
         FROM corrective_actions
         INNER JOIN nonconformities ON nonconformities.id = corrective_actions.nonconformity_id
         INNER JOIN companies ON companies.id = nonconformities.company_id
         WHERE corrective_actions.active = 1 AND corrective_actions.created_at BETWEEN ? AND ?" . $scopeSql,
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

    $selectedCompanyName = 'Tüm şirketler';
    if ($selectedCompanyId > 0) {
        foreach ($companies as $company) {
            if ((int) $company['id'] === $selectedCompanyId) {
                $selectedCompanyName = $company['company_name'];
                break;
            }
        }
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
    ];
}
