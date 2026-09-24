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
 * @return array<string, array{label: string, audits: int, nonconformities: int, actions_completed: int, trainings_completed: int, complaints: int}>
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
    $points[] = ['tone' => 'neutral', 'text' => $nonconformities . ' uygunsuzluk açıldı; şu an ' . $openNonconformities . ' açık durumda.'];
    $points[] = ['tone' => 'neutral', 'text' => $actionsCompleted . ' aksiyon tamamlandı.'];

    if ($overdueActions > 0) {
        $points[] = ['tone' => 'warning', 'text' => $overdueActions . ' gecikmiş aksiyon var, dikkat gerektiriyor.'];
    } else {
        $points[] = ['tone' => 'positive', 'text' => 'Gecikmiş aksiyon bulunmuyor.'];
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
