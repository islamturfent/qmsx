<?php

declare(strict_types=1);

/**
 * Panel (dashboard) yardimcilari: 12 aylik trend ve kuralli donem ozeti.
 *
 * Trend ve ozet, rapor motorundan bagimsiz, dogrudan toplu (aggregate)
 * sorgularla hesaplanir. Boylece dashboard her goruntulendiginde raporun tam
 * kayit setini yuklemek zorunda kalmaz. Kapsam tek kaynaktan gelir
 * (includes/access.php); super admin "kisitlama yok" (null) kalir.
 */

require_once __DIR__ . '/access.php';

/** Panelde gosterilen trend ay sayisi. */
const QMS_TREND_MONTHS = 12;

/** @return array<int, string> Kisa Turkce ay adlari (1-12). */
function qmsMonthShortLabels(): array
{
    return [
        1 => 'Oca', 2 => 'Şub', 3 => 'Mar', 4 => 'Nis', 5 => 'May', 6 => 'Haz',
        7 => 'Tem', 8 => 'Ağu', 9 => 'Eyl', 10 => 'Eki', 11 => 'Kas', 12 => 'Ara',
    ];
}

/**
 * Son N ay icin aylik toplamlar (en eskiden en yeniye).
 *
 * Seriler: audits, nonconformities, actions_completed, trainings_completed,
 * complaints. Her hucre ay anahtari ("Y-m") ve kisa ay etiketlidir.
 *
 * @return array<string, array{label: string, audits: int, nonconformities: int, actions_completed: int, trainings_completed: int, complaints: int, prevention: float, appraisal: float, internal_failure: float, external_failure: float, cost_total: float}>
 */
function qmsDashboardTrend(PDO $pdo, int $userId, string $role, int $months = QMS_TREND_MONTHS): array
{
    $companyIds = qmsVisibleCompanyIds($pdo, $userId, $role);
    $scope = qmsCompanyScope('co.id', $companyIds);

    $start = date('Y-m-01', strtotime('-' . (max(2, min(24, $months)) - 1) . ' months'));
    $startParam = $start . ' 00:00:00';

    // Bos kovalar (en eskiden bu aya).
    $monthsShort = qmsMonthShortLabels();
    $buckets = [];
    $cursor = new DateTime($start);
    $now = new DateTime(date('Y-m-01'));
    while ($cursor <= $now && count($buckets) < 26) {
        $key = $cursor->format('Y-m');
        $buckets[$key] = [
            'label' => $monthsShort[(int) $cursor->format('n')] . ' ' . $cursor->format('y'),
            'audits' => 0,
            'nonconformities' => 0,
            'actions_completed' => 0,
            'trainings_completed' => 0,
            'complaints' => 0,
            'prevention' => 0.0,
            'appraisal' => 0.0,
            'internal_failure' => 0.0,
            'external_failure' => 0.0,
            'cost_total' => 0.0,
        ];
        $cursor->modify('+1 month');
    }

    $fill = static function (string $sql, string $bucketKey) use ($pdo, $startParam, $scope, &$buckets): void {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge([$startParam], $scope['params']));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['ym'] ?? '';
            if (isset($buckets[$key])) {
                $buckets[$key][$bucketKey] += (int) $row['c'];
            }
        }
    };

    $fill(
        "SELECT DATE_FORMAT(a.created_at, '%Y-%m') AS ym, COUNT(*) AS c
         FROM audits a INNER JOIN companies co ON co.id = a.company_id
         WHERE a.active = 1 AND a.created_at >= ?" . $scope['sql'] . '
         GROUP BY ym',
        'audits'
    );
    $fill(
        "SELECT DATE_FORMAT(n.created_at, '%Y-%m') AS ym, COUNT(*) AS c
         FROM nonconformities n INNER JOIN companies co ON co.id = n.company_id
         WHERE n.active = 1 AND n.created_at >= ?" . $scope['sql'] . '
         GROUP BY ym',
        'nonconformities'
    );
    $fill(
        "SELECT DATE_FORMAT(ca.completed_at, '%Y-%m') AS ym, COUNT(*) AS c
         FROM corrective_actions ca
         INNER JOIN nonconformities n ON n.id = ca.nonconformity_id
         INNER JOIN companies co ON co.id = n.company_id
         WHERE ca.active = 1 AND ca.completed_at IS NOT NULL AND ca.completed_at >= ?" . $scope['sql'] . '
         GROUP BY ym',
        'actions_completed'
    );
    $fill(
        "SELECT DATE_FORMAT(t.completed_date, '%Y-%m') AS ym, COUNT(*) AS c
         FROM trainings t INNER JOIN companies co ON co.id = t.company_id
         WHERE t.active = 1 AND t.completed_date IS NOT NULL AND t.completed_date >= ?" . $scope['sql'] . '
         GROUP BY ym',
        'trainings_completed'
    );
    $fill(
        "SELECT DATE_FORMAT(cc.created_at, '%Y-%m') AS ym, COUNT(*) AS c
         FROM complaints cc INNER JOIN companies co ON co.id = cc.company_id
         WHERE cc.active = 1 AND cc.created_at >= ?" . $scope['sql'] . '
         GROUP BY ym',
        'complaints'
    );

    // COQ: kalite maliyeti aylik kategorileri (para toplamlari).
    $costStmt = $pdo->prepare(
        "SELECT DATE_FORMAT(c.incurred_on, '%Y-%m') AS ym, c.cost_type, SUM(c.amount) AS s
         FROM quality_costs c INNER JOIN companies co ON co.id = c.company_id
         WHERE c.active = 1 AND c.incurred_on >= ?" . $scope['sql'] . '
         GROUP BY ym, c.cost_type'
    );
    $costStmt->execute(array_merge([$startParam], $scope['params']));
    foreach ($costStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['ym'] ?? '';
        if (isset($buckets[$key]) && isset($buckets[$key][$row['cost_type']])) {
            $buckets[$key][$row['cost_type']] += (float) $row['s'];
            $buckets[$key]['cost_total'] += (float) $row['s'];
        }
    }
    foreach ($buckets as &$b) {
        foreach (['prevention', 'appraisal', 'internal_failure', 'external_failure', 'cost_total'] as $kk) {
            $b[$kk] = round($b[$kk], 2);
        }
    }
    unset($b);

    return $buckets;
}

/**
 * Kuralli, yerel olarak uretilen "donem ozeti" metni. Dis bir AI servisi
 * kullanmaz; mevcut kayitlari sayiya donusturup cumle kurar.
 *
 * @return array{headline: string, points: array<int, array{tone: string, text: string}>}
 */
function qmsDashboardSummary(PDO $pdo, int $userId, string $role, array $trend): array
{
    $companyIds = qmsVisibleCompanyIds($pdo, $userId, $role);
    $scope = qmsCompanyScope('co.id', $companyIds);

    $agg = static function (string $sql) use ($pdo, $scope): int {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($scope['params']);
        return (int) $stmt->fetchColumn();
    };

    $audits = 0;
    $nonconformities = 0;
    $actionsCompleted = 0;
    $trainingsCompleted = 0;
    $complaints = 0;
    foreach ($trend as $row) {
        $audits += $row['audits'];
        $nonconformities += $row['nonconformities'];
        $actionsCompleted += $row['actions_completed'];
        $trainingsCompleted += $row['trainings_completed'];
        $complaints += $row['complaints'];
    }

    // Baglam icin ek toplular (kapsamli).
    $openNonconformities = $agg(
        'SELECT COUNT(*) FROM nonconformities n INNER JOIN companies co ON co.id = n.company_id'
        . ' WHERE n.active = 1 AND n.status <> \'closed\'' . $scope['sql']
    );
    $openComplaints = $agg(
        'SELECT COUNT(*) FROM complaints cc INNER JOIN companies co ON co.id = cc.company_id'
        . ' WHERE cc.active = 1 AND cc.status IN (\'new\', \'in_review\', \'action_planned\', \'resolved\')' . $scope['sql']
    );
    $overdueActions = $agg(
        'SELECT COUNT(*) FROM corrective_actions ca
         INNER JOIN nonconformities n ON n.id = ca.nonconformity_id
         INNER JOIN companies co ON co.id = n.company_id
         WHERE ca.active = 1 AND ca.status NOT IN (\'completed\', \'closed\')
           AND ca.due_date IS NOT NULL AND ca.due_date < CURDATE()' . $scope['sql']
    );
    $overdueSupplierEval = $agg(
        'SELECT COUNT(*) FROM supplier_evaluation_schedule se
         INNER JOIN companies co ON co.id = se.company_id
         WHERE se.active = 1 AND se.status = \'planned\' AND se.due_date IS NOT NULL
           AND se.due_date < CURDATE()' . $scope['sql']
    );
    $planItemProgress = $agg(
        'SELECT COALESCE(SUM(i.progress),0) FROM quality_plan_items i
         INNER JOIN quality_plans pl ON pl.id = i.plan_id
         INNER JOIN companies co ON co.id = pl.company_id
         WHERE i.active = 1 AND pl.active = 1 AND pl.plan_year = YEAR(CURDATE())' . $scope['sql']
    );
    $planItemTotal = $agg(
        'SELECT COUNT(*) FROM quality_plan_items i
         INNER JOIN quality_plans pl ON pl.id = i.plan_id
         INNER JOIN companies co ON co.id = pl.company_id
         WHERE i.active = 1 AND pl.active = 1 AND pl.plan_year = YEAR(CURDATE())' . $scope['sql']
    );
    $planProgressAvg = $planItemTotal > 0 ? (int) round($planItemProgress / $planItemTotal) : null;
    $currentYear = (int) date('Y');
    $openImprovements = $agg(
        'SELECT COUNT(*) FROM improvements i
         INNER JOIN companies co ON co.id = i.company_id
         WHERE i.active = 1 AND i.status NOT IN (\'rejected\', \'closed\', \'implemented\')' . $scope['sql']
    );
    $implementedImprovements = $agg(
        'SELECT COUNT(*) FROM improvements i
         INNER JOIN companies co ON co.id = i.company_id
         WHERE i.active = 1 AND i.status = \'implemented\'' . $scope['sql']
    );
    $expiringContracts = $agg(
        'SELECT COUNT(*) FROM contracts c
         INNER JOIN companies co ON co.id = c.company_id
         WHERE c.active = 1 AND c.status = \'active\' AND c.end_date IS NOT NULL
           AND c.end_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)' . $scope['sql']
    );
    $overdueInstruments = $agg(
        'SELECT COUNT(*) FROM instruments i
         INNER JOIN companies co ON co.id = i.company_id
         WHERE i.active = 1 AND i.status = \'active\' AND i.next_calibration_date IS NOT NULL
           AND i.next_calibration_date < CURDATE()' . $scope['sql']
    );
    $openIncidents = $agg(
        'SELECT COUNT(*) FROM incidents i
         INNER JOIN companies co ON co.id = i.company_id
         WHERE i.active = 1 AND i.status <> \'closed\'' . $scope['sql']
    );

    $deliveryOrders = 0;
    $deliveryOnTime = 0;
    $deliveryRejected = 0;
    $delStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(d.orders_total),0) AS o, COALESCE(SUM(d.on_time_orders),0) AS ot,
                COALESCE(SUM(d.quantity_rejected),0) AS r
         FROM delivery_performance d INNER JOIN companies co ON co.id = d.company_id
         WHERE d.active = 1' . $scope['sql']
    );
    $delStmt->execute($scope['params']);
    $delRow = $delStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $deliveryOrders = (int) ($delRow['o'] ?? 0);
    $deliveryOnTime = (int) ($delRow['ot'] ?? 0);
    $deliveryRejected = (int) ($delRow['r'] ?? 0);
    $deliveryOnTimePct = $deliveryOrders > 0 ? (int) round(($deliveryOnTime / $deliveryOrders) * 100) : null;

    $failedCalibrations = $agg(
        'SELECT COUNT(*) FROM instrument_calibrations k
         INNER JOIN instruments i ON i.id = k.instrument_id
         INNER JOIN companies co ON co.id = k.company_id
         WHERE k.active = 1 AND k.result = \'fail\'' . $scope['sql']
    );

    $auditTrailRecords = $agg(
        'SELECT COUNT(*) FROM audit_log al
         INNER JOIN companies co ON co.id = al.company_id' . $scope['sql']
    );

    // Devam eden ve vadeyi gecen denetimler (kapsamli).
    $openAudits = $agg(
        'SELECT COUNT(*) FROM audits a
         INNER JOIN companies co ON co.id = a.company_id
         WHERE a.active = 1 AND a.status IN (\'planned\', \'in_progress\')' . $scope['sql']
    );
    $overdueAudits = $agg(
        'SELECT COUNT(*) FROM audits a
         INNER JOIN companies co ON co.id = a.company_id
         WHERE a.active = 1 AND a.status IN (\'planned\', \'in_progress\')
           AND a.planned_date IS NOT NULL AND a.planned_date < CURDATE()' . $scope['sql']
    );

    // Acik denetim bulgulari (kapatilmamis NC'ye bagli uygun bulunmayan maddeler).
    $openFindings = $agg(
        'SELECT COUNT(*) FROM audit_checklist_items cit
         INNER JOIN audits a ON a.id = cit.audit_id
         INNER JOIN companies co ON co.id = a.company_id
         LEFT JOIN nonconformities nc ON nc.checklist_item_id = cit.id AND nc.active = 1
         WHERE cit.active = 1 AND cit.result_status = \'noncompliant\'
           AND (nc.id IS NULL OR nc.status <> \'closed\')' . $scope['sql']
    );
    // Kok neden analizi bekleyen uygunsuzluklar.
    $ncPendingAnalysis = $agg(
        'SELECT COUNT(*) FROM nonconformities n
         INNER JOIN companies co ON co.id = n.company_id
         LEFT JOIN nc_root_cause rc ON rc.nonconformity_id = n.id AND rc.active = 1
         WHERE n.active = 1 AND n.status <> \'closed\' AND rc.id IS NULL' . $scope['sql']
    );
    // Dagitilan dokumanlar icin teslim onayi bekleyen kopyalar.
    $docConfirmPending = $agg(
        'SELECT COUNT(*) FROM document_copies c
         INNER JOIN companies co ON co.id = c.company_id
         WHERE c.active = 1 AND c.status = \'distributed\' AND c.received_confirmed = 0' . $scope['sql']
    );

    // En yogun ay (denetim bazinda).
    $topMonth = null;
    $topAudits = 0;
    foreach ($trend as $row) {
        if ($row['audits'] > $topAudits) {
            $topAudits = $row['audits'];
            $topMonth = $row['label'];
        }
    }

    $points = [];

    if ($audits === 0 && $nonconformities === 0 && $actionsCompleted === 0 && $complaints === 0) {
        $headline = 'Son 12 ayda görünür bir faaliyet kaydı bulunmuyor. İlk kayıtları oluşturdukça özet burada büyüyecek.';
    } else {
        $headline = 'Son 12 ayda ' . $audits . ' denetim, ' . $nonconformities . ' uygunsuzluk '
            . 've ' . $actionsCompleted . ' tamamlanan aksiyon kaydedildi.';
    }

    $points[] = ['tone' => 'neutral', 'text' => $audits . ' denetim gerçekleştirildi' . ($topMonth ? " (en yoğun $topMonth)" : '') . '.'];
    if ($openAudits > 0) {
        $points[] = ['tone' => $overdueAudits > 0 ? 'warning' : 'neutral', 'text' => $openAudits . ' denetim devam ediyor' . ($overdueAudits > 0 ? '; ' . $overdueAudits . ' tanesi vadeyi geçti.' : '.')];
    } else {
        $points[] = ['tone' => 'positive', 'text' => 'Devam eden denetim bulunmuyor.'];
    }

    if ($openFindings > 0) {
        $points[] = ['tone' => 'neutral', 'text' => $openFindings . ' açık denetim bulgusu var; kapanışı takip edilmeli.'];
    } else {
        $points[] = ['tone' => 'positive', 'text' => 'Açık denetim bulgusu bulunmuyor.'];
    }
    if ($ncPendingAnalysis > 0) {
        $points[] = ['tone' => 'warning', 'text' => $ncPendingAnalysis . ' uygunsuzluğun kök neden analizi bekliyor.'];
    } else {
        $points[] = ['tone' => 'positive', 'text' => 'Kök neden analizi bekleyen uygunsuzluk bulunmuyor.'];
    }
    if ($docConfirmPending > 0) {
        $points[] = ['tone' => 'neutral', 'text' => $docConfirmPending . ' dağıtılmış doküman teslim onayı bekliyor.'];
    } else {
        $points[] = ['tone' => 'positive', 'text' => 'Bekleyen doküman teslim onayı bulunmuyor.'];
    }
    $points[] = ['tone' => 'neutral', 'text' => $nonconformities . ' uygunsuzluk açıldı; şu an ' . $openNonconformities . ' açık durumda.'];
    $points[] = ['tone' => 'neutral', 'text' => $actionsCompleted . ' aksiyon tamamlandı.'];

    if ($overdueActions > 0) {
        $points[] = ['tone' => 'warning', 'text' => $overdueActions . ' gecikmiş aksiyon var, dikkat gerektiriyor.'];
    } else {
        $points[] = ['tone' => 'positive', 'text' => 'Gecikmiş aksiyon bulunmuyor.'];
    }

    if ($overdueSupplierEval > 0) {
        $points[] = ['tone' => 'warning', 'text' => $overdueSupplierEval . ' tedarikçi değerlendirmesi gecikmiş.'];
    } else {
        $points[] = ['tone' => 'positive', 'text' => 'Gecikmiş tedarikçi değerlendirmesi bulunmuyor.'];
    }

    if ($planProgressAvg !== null) {
        $points[] = ['tone' => 'neutral', 'text' => $currentYear . ' kalite planı hedefleri ortalama %' . $planProgressAvg . ' ilerlemede.'];
    } else {
        $points[] = ['tone' => 'neutral', 'text' => $currentYear . ' için tanımlı kalite planı hedefi bulunmuyor.'];
    }

    if ($openImprovements > 0) {
        $points[] = ['tone' => 'neutral', 'text' => $openImprovements . ' açık iyileştirme fırsatı var.'];
    } else {
        $points[] = ['tone' => 'positive', 'text' => 'Açık iyileştirme fırsatı bulunmuyor.'];
    }
    if ($implementedImprovements > 0) {
        $points[] = ['tone' => 'positive', 'text' => $implementedImprovements . ' iyileştirme fırsatı uygulandı.'];
    }

    if ($expiringContracts > 0) {
        $points[] = ['tone' => 'warning', 'text' => $expiringContracts . ' sözleşmenin süresi yaklaşıyor, yenilemeyi planlayın.'];
    } else {
        $points[] = ['tone' => 'positive', 'text' => 'Süresi yaklaşan sözleşme bulunmuyor.'];
    }

    if ($overdueInstruments > 0) {
        $points[] = ['tone' => 'warning', 'text' => $overdueInstruments . ' ölçü aletinin kalibrasyonu gecikmiş.'];
    } else {
        $points[] = ['tone' => 'positive', 'text' => 'Geç kalibrasyon bulunmuyor.'];
    }

    if ($openIncidents > 0) {
        $points[] = ['tone' => 'neutral', 'text' => $openIncidents . ' açık olay kaydı var.'];
    } else {
        $points[] = ['tone' => 'positive', 'text' => 'Açık olay kaydı bulunmuyor.'];
    }

    if ($deliveryOrders > 0) {
        $tone = $deliveryOnTimePct !== null && $deliveryOnTimePct >= 95 ? 'positive' : 'neutral';
        $points[] = ['tone' => $tone, 'text' => 'Teslimat performansı: ' . $deliveryOrders . ' siparişin %' . $deliveryOnTimePct . ' zamanında teslim edildi' . ($deliveryRejected > 0 ? ', ' . $deliveryRejected . ' adet reddedildi.' : '.')];
    } else {
        $points[] = ['tone' => 'neutral', 'text' => 'Teslimat performansı kaydı bulunmuyor.'];
    }

    if ($failedCalibrations > 0) {
        $points[] = ['tone' => 'warning', 'text' => $failedCalibrations . ' başarısız kalibrasyon kaydı var.'];
    }

    if ($auditTrailRecords > 0) {
        $points[] = ['tone' => 'neutral', 'text' => 'Denetim izinde ' . $auditTrailRecords . ' kayıt birikmiş durumda.'];
    }

    if ($complaints > 0) {
        $points[] = ['tone' => 'neutral', 'text' => $complaints . ' şikayet alındı, ' . $openComplaints . ' açık.'];
    } else {
        $points[] = ['tone' => 'positive', 'text' => 'Şikayet kaydı bulunmuyor.'];
    }

    if ($trainingsCompleted > 0) {
        $points[] = ['tone' => 'positive', 'text' => $trainingsCompleted . ' eğitim tamamlandı.'];
    }

    return ['headline' => $headline, 'points' => $points];
}

/**
 * Yonetim kokpiti: hedef koyulmus sirketler icin KPI hedef-vs-gerceklesen matrisi.
 *
 * Gercek degerler rapor motorundan (buildReportExportData) okunur; hedefler
 * performance_targets tablosundan. Yalnizca secilen yil icin en az bir hedefi
 * olan sirketler doner (hedefsiz sirket kokpitte yer almaz).
 *
 * @return array<int, array{
 *     id: int, name: string, on_track_count: int, target_count: int,
 *     all_on_track: bool,
 *     rows: array<string, array{kpi_key: string, label_key: string, unit: string,
 *             higher_better: bool, target: ?float, target_note: ?string,
 *             actual: ?float, on_track: ?bool}>
 * }>
 */
function qmsCockpitKpiMatrix(PDO $pdo, int $userId, bool $isSuperAdmin, int $year): array
{
    require_once __DIR__ . '/performance-functions.php';
    require_once __DIR__ . '/report-export-data.php';

    $role = $isSuperAdmin ? 'super_admin' : qmsCurrentRole();
    $scope = qmsCompanyScope('c.id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        "SELECT c.id, c.company_name
         FROM companies c
         INNER JOIN performance_targets t ON t.company_id = c.id AND t.target_year = ?
         WHERE c.active = 1" . $scope['sql'] . '
         GROUP BY c.id, c.company_name
         ORDER BY c.company_name ASC'
    );
    $stmt->execute(array_merge([$year], $scope['params']));
    $targetCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $kpis = qmsPerformanceKpis();
    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-12-31', $year);
    $companies = [];

    foreach ($targetCompanies as $company) {
        $cid = (int) $company['id'];
        $targets = qmsPerformanceTargets($pdo, $cid, $year);
        $report = buildReportExportData($pdo, $userId, $isSuperAdmin, [
            'company_id' => $cid,
            'start_date' => $start,
            'end_date' => $end,
        ]);
        $metrics = $report['metrics'];

        $rows = [];
        $onTrackCount = 0;
        $targetCount = 0;
        foreach ($kpis as $key => $def) {
            $target = $targets[$key] ?? null;
            $actualRaw = $metrics[$key] ?? null;
            $actual = $actualRaw === null ? null : (float) $actualRaw;
            $onTrack = qmsPerformanceOnTrack($target ? $target['target_value'] : null, $actual, $def['higher_better']);
            if ($onTrack !== null) {
                $targetCount++;
                if ($onTrack) {
                    $onTrackCount++;
                }
            }
            $rows[$key] = [
                'kpi_key' => $key,
                'label_key' => $def['label_key'],
                'unit' => $def['unit'],
                'higher_better' => $def['higher_better'],
                'target' => $target ? $target['target_value'] : null,
                'target_note' => $target ? $target['note'] : null,
                'actual' => $actual,
                'on_track' => $onTrack,
            ];
        }

        $companies[] = [
            'id' => $cid,
            'name' => $company['company_name'],
            'rows' => $rows,
            'on_track_count' => $onTrackCount,
            'target_count' => $targetCount,
            'all_on_track' => $targetCount > 0 && $onTrackCount === $targetCount,
        ];
    }

    return $companies;
}
