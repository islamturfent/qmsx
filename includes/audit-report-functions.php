<?php

declare(strict_types=1);

/**
 * Denetim raporu taslagi yardimcilari.
 *
 * Rapor, denetim + kontrol listesi + uygunsuzluklarin kuralli bir derlemesidir;
 * dis bir AI servisi kullanmaz. Uretilen metin kullanici tarafindan duzenlenir,
 * taslak (draft) veya kesinlesmis (final) olarak kaydedilir ve PDF'e verilir.
 * Kapsam tek kaynaktan gelir (qmsAuditRecordScope).
 */

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/vocabulary.php';

/** Rapor durumlari. */
const QMS_AUDIT_REPORT_STATUSES = ['draft', 'final'];

/**
 * Kapsam icindeki bir denetim raporu (audit_id bazli).
 *
 * @return array<string, mixed> Bos dizi: rapor yok veya kapsam disi.
 */
function qmsAuditReportFind(PDO $pdo, int $auditId, int $userId, string $role): array
{
    if ($auditId <= 0) {
        return [];
    }

    $scope = qmsAuditRecordScope($pdo, $userId, 'audits.id', 'audits.company_id');

    $stmt = $pdo->prepare(
        'SELECT audit_reports.*, audits.title AS audit_title
         FROM audit_reports
         INNER JOIN audits ON audits.id = audit_reports.audit_id
         WHERE audit_reports.audit_id = ? AND audit_reports.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$auditId], $scope['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Raporun dayandigi denetim verisinin anlik gorunumu (kaynak).
 *
 * @return array<string, mixed>
 */
function qmsAuditReportSnapshot(PDO $pdo, int $auditId): array
{
    $audit = $pdo->prepare(
        'SELECT audits.*, companies.company_name
         FROM audits INNER JOIN companies ON companies.id = audits.company_id
         WHERE audits.id = ? LIMIT 1'
    );
    $audit->execute([$auditId]);
    $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
    if (!$auditRow) {
        return [];
    }

    $auditors = [];
    $auditorStmt = $pdo->prepare(
        'SELECT users.full_name
         FROM audit_auditors
         INNER JOIN auditors ON auditors.id = audit_auditors.auditor_id
         INNER JOIN users ON users.id = auditors.user_id
         WHERE audit_auditors.audit_id = ? AND auditors.active = 1'
    );
    $auditorStmt->execute([$auditId]);
    $auditors = $auditorStmt->fetchAll(PDO::FETCH_COLUMN);

    $items = $pdo->prepare(
        'SELECT item_text, requirement_ref, result_status, notes
         FROM audit_checklist_items WHERE audit_id = ? AND active = 1
         ORDER BY id'
    );
    $items->execute([$auditId]);
    $itemRows = $items->fetchAll(PDO::FETCH_ASSOC);

    $nonconformities = $pdo->prepare(
        'SELECT title, severity, status FROM nonconformities
         WHERE audit_id = ? AND active = 1 ORDER BY id'
    );
    $nonconformities->execute([$auditId]);
    $ncRows = $nonconformities->fetchAll(PDO::FETCH_ASSOC);

    $count = ['total' => count($itemRows), 'compliant' => 0, 'noncompliant' => 0, 'not_applicable' => 0, 'pending' => 0];
    foreach ($itemRows as $item) {
        $key = $item['result_status'] ?? 'pending';
        if (isset($count[$key])) {
            $count[$key]++;
        } else {
            $count['pending']++;
        }
    }

    $severityCounts = ['critical' => 0, 'major' => 0, 'minor' => 0];
    $openNc = 0;
    foreach ($ncRows as $nc) {
        $severity = $nc['severity'] ?? 'major';
        if (isset($severityCounts[$severity])) {
            $severityCounts[$severity]++;
        }
        if (($nc['status'] ?? '') !== 'closed') {
            $openNc++;
        }
    }

    $noncompliantItems = array_values(array_filter(
        $itemRows,
        static fn(array $item): bool => ($item['result_status'] ?? '') === 'noncompliant'
    ));

    return [
        'audit' => $auditRow,
        'auditors' => $auditors,
        'checklist' => $count,
        'nonconformities' => $ncRows,
        'severity_counts' => $severityCounts,
        'open_nonconformities' => $openNc,
        'noncompliant_items' => $noncompliantItems,
    ];
}

/**
 * Kuralli rapor metnini uretir.
 *
 * @param array<string, mixed> $snapshot qmsAuditReportSnapshot() ciktisi.
 * @return array{title: string, scope_text: string, methodology_text: string, findings_text: string, nonconformity_summary: string, conclusion: string, recommendations: string}
 */
function qmsGenerateAuditReportText(array $snapshot): array
{
    $audit = $snapshot['audit'] ?? [];
    $companyName = (string) ($audit['company_name'] ?? '');
    $title = (string) ($audit['title'] ?? 'Denetim Raporu');
    $auditType = (string) ($audit['audit_type'] ?? '');
    $plannedDate = (string) ($audit['planned_date'] ?? '');
    $auditors = $snapshot['auditors'] ?? [];
    $list = $snapshot['checklist'] ?? [];
    $sev = $snapshot['severity_counts'] ?? ['critical' => 0, 'major' => 0, 'minor' => 0];
    $ncCount = count($snapshot['nonconformities'] ?? []);
    $openNc = $snapshot['open_nonconformities'] ?? 0;

    $auditorList = $auditors ? implode(', ', $auditors) : 'Belirtilmedi';

    $scopeText = 'Bu denetim ' . $companyName . ' şirketinde "' . $title . '" kapsamında'
        . ($plannedDate ? ' ' . $plannedDate . ' tarihinde' : '')
        . ' gerçekleştirilmiştir.'
        . ($auditType ? ' Denetim türü: ' . $auditType . '.' : '')
        . ' Denetim ekibi: ' . $auditorList . '. Kapsam, planlanan denetim alanlarıyla sınırlıdır.';

    $total = (int) ($list['total'] ?? 0);
    $methodologyText = 'Denetim, ' . $total . ' maddelik kontrol listesi üzerinden yürütülmüş;'
        . ' her madde uygun / uygun değil / uygulanamaz olarak değerlendirilmiştir.'
        . ' Bulgular; kayıt incelemesi, gözlem ve ilgili kişilerle görüşmeler yoluyla toplanmıştır.';

    $compliant = (int) ($list['compliant'] ?? 0);
    $noncompliant = (int) ($list['noncompliant'] ?? 0);
    $na = (int) ($list['not_applicable'] ?? 0);
    $pending = (int) ($list['pending'] ?? 0);

    $findings = 'Kontrol listesinin ' . $compliant . ' maddesi uygun, ' . $noncompliant
        . ' maddesi uygun değil, ' . $na . ' maddesi uygulanamaz bulunmuştur'
        . ($pending ? '; ' . $pending . ' madde için değerlendirme beklemektedir' : '') . '.';

    if ($noncompliant > 0 && $snapshot['noncompliant_items']) {
        $titles = array_map(static fn(array $item): string => (string) $item['item_text'], $snapshot['noncompliant_items']);
        $findings .= ' Uygun bulunmayan maddeler: ' . implode('; ', array_slice($titles, 0, 6)) . (count($titles) > 6 ? '…' : '') . '.';
    }

    $severityLabels = qmsSeverityLabels();
    $sevParts = [];
    foreach (['critical' => 'kritik', 'major' => 'majör', 'minor' => 'minör'] as $sevKey => $phrase) {
        if ($sev[$sevKey] > 0) {
            $sevParts[] = $sev[$sevKey] . ' ' . $phrase;
        }
    }
    $ncSummary = $ncCount . ' uygunsuzluk tespit edilmiştir'
        . ($sevParts ? ' (' . implode(', ', $sevParts) . ')' : '')
        . '; bunlardan ' . $openNc . ' tanesi hâlâ açıktır.'
        . ' Uygunsuzlukların her biri için sorumlu, termin ve düzeltici faaliyet kaydı oluşturulmalıdır.';

    if ($ncCount === 0 && $noncompliant === 0) {
        $conclusion = 'Süreç genel olarak gereksinimlere uygun bulunmuştur. Tespit edilen zayıf nokta '
            . 'kaydedilmemiş; sistemin mevcut hâliyle sürdürülebilir olduğu değerlendirilmiştir.';
    } elseif ($sev['critical'] > 0) {
        $conclusion = 'Kritik uygunsuzluklar tespit edilmiştir. Bu bulgular, sürecin gereksinimlere '
            . 'uygunluk açısından ciddi şekilde risk taşıdığını gösterir; düzeltici faaliyetlerin '
            . 'önceliklendirilmesi ve izlenmesi zorunludur.';
    } elseif ($noncompliant > 1 || $ncCount >= 3) {
        $conclusion = 'Süreçte önemli sayıda uygunsuzluk bulunmuştur. Temel süreçler çalışmakla birlikte, '
            . 'tespit edilen bulguların giderilmesi ve tekrarının önlenmesi için sistematik düzeltici '
            . 'faaliyet yürütülmelidir.';
    } else {
        $conclusion = 'Süreç genel olarak gereksinimlere uygun bulunmuştur. Tespit edilen sınırlı sayıdaki '
            . 'uygunsuzluk, izole zayıf noktalar olarak değerlendirilmiş; uygun düzeltici faaliyetlerle '
            . 'giderilmesi önerilmiştir.';
    }

    $recommendations = [];
    if ($sev['critical'] > 0) {
        $recommendations[] = 'Kritik uygunsuzluklar için derhâl düzeltici faaliyet başlatın ve üst yönetime raporlayın.';
    }
    if ($openNc > 0) {
        $recommendations[] = 'Açık uygunsuzlukların terminini ve kapanışını takip edin; kapanmayan kayıtları aylık gözden geçirin.';
    }
    if ($noncompliant > 0) {
        $recommendations[] = 'Uygun bulunmayan kontrol maddelerinin kök neden analizini yapın ve süreç iyileştirmesi planlayın.';
    }
    if ($pending > 0) {
        $recommendations[] = 'Bekleyen kontrol maddeleri için değerlendirmeyi tamamlayarak kontrollere son verin.';
    }
    $recommendations[] = 'Denetim bulguları, bir sonraki yönetim gözden geçirmesinde girdi olarak ele alınmalıdır.';

    return [
        'title' => $title . ' - Denetim Raporu',
        'scope_text' => $scopeText,
        'methodology_text' => $methodologyText,
        'findings_text' => $findings,
        'nonconformity_summary' => $ncSummary,
        'conclusion' => $conclusion,
        'recommendations' => implode("\n", $recommendations),
    ];
}

/**
 * Rapor metinlerini dogrular ve dondurur (bos -> null).
 *
 * @return array<string, string|null>
 */
function qmsAuditReportNormalize(array $input): array
{
    $fields = ['title', 'scope_text', 'methodology_text', 'findings_text', 'nonconformity_summary', 'conclusion', 'recommendations'];
    $out = [];
    foreach ($fields as $field) {
        $value = trim((string) ($input[$field] ?? ''));
        $out[$field] = $value === '' ? null : $value;
    }
    return $out;
}

/**
 * Raporu kaydeder (her denetim icin tek satir, upsert).
 *
 * @return int Rapor id'si.
 */
function qmsSaveAuditReport(PDO $pdo, int $auditId, int $companyId, array $data, int $userId): int
{
    $status = (string) ($data['status'] ?? 'draft');
    if (!in_array($status, QMS_AUDIT_REPORT_STATUSES, true)) {
        $status = 'draft';
    }
    $reportDate = trim((string) ($data['report_date'] ?? ''));
    if ($reportDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate) !== 1) {
        $reportDate = date('Y-m-d');
    }
    $normalized = qmsAuditReportNormalize($data);
    $title = $normalized['title'] ?? 'Denetim Raporu';

    $existing = $pdo->prepare('SELECT id FROM audit_reports WHERE audit_id = ?');
    $existing->execute([$auditId]);

    if ($reportId = (int) $existing->fetchColumn()) {
        $stmt = $pdo->prepare(
            'UPDATE audit_reports SET title = :title, status = :status, report_date = :report_date,
                scope_text = :scope_text, methodology_text = :methodology_text, findings_text = :findings_text,
                nonconformity_summary = :nonconformity_summary, conclusion = :conclusion,
                recommendations = :recommendations, updated_by = :updated_by
             WHERE id = :id'
        );
        $stmt->execute(array_merge($normalized, [
            'title' => $title,
            'status' => $status,
            'report_date' => $reportDate !== '' ? $reportDate : null,
            'updated_by' => $userId ?: null,
            'id' => $reportId,
        ]));
        return $reportId;
    }

    $insert = $pdo->prepare(
        'INSERT INTO audit_reports
            (audit_id, company_id, title, status, report_date,
             scope_text, methodology_text, findings_text, nonconformity_summary,
             conclusion, recommendations, generated_by, created_by, updated_by, active)
         VALUES
            (:audit_id, :company_id, :title, :status, :report_date,
             :scope_text, :methodology_text, :findings_text, :nonconformity_summary,
             :conclusion, :recommendations, :generated_by, :created_by, :updated_by, 1)'
    );
    $insert->execute(array_merge($normalized, [
        'audit_id' => $auditId,
        'company_id' => $companyId,
        'title' => $title,
        'status' => $status,
        'report_date' => $reportDate !== '' ? $reportDate : null,
        'generated_by' => $userId ?: null,
        'created_by' => $userId ?: null,
        'updated_by' => $userId ?: null,
    ]));

    return (int) $pdo->lastInsertId();
}

/**
 * Raporu onaylayarak kesinlesme bilgisini yazar (final).
 */
function qmsAuditReportFinalize(PDO $pdo, int $reportId, int $userId): void
{
    $pdo->prepare(
        'UPDATE audit_reports SET status = \'final\', approved_by = :approved_by, approved_at = NOW(),
            updated_by = :updated_by WHERE id = :id'
    )->execute([
        'approved_by' => $userId ?: null,
        'updated_by' => $userId ?: null,
        'id' => $reportId,
    ]);
}
