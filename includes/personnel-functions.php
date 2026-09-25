<?php

declare(strict_types=1);

/**
 * Personel & Yetkinlik modulu yardimcilari.
 *
 * Sirkete bagli personel kaydi + yetkinlik matrisi. Yetkinlik seviyesi 1-5;
 * yetkinlik durumu sonraki degerlendirme tarihinden turetilir (saklanan kopya
 * yok), ayni ekipman/kalibrasyon ve doküman gözden geçirme desenindeki gibi.
 * Kapsam tek kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';

/** Yetkinlik seviyesi araligi (1-5). */
const QMS_PERSONNEL_LEVELS = [1, 2, 3, 4, 5];

/** Sonraki degerlendirmenin "yaklasan" sayildigi gun sayisi. */
const QMS_COMPETENCY_DUE_SOON_DAYS = 30;

/** @return array<int, string> */
function qmsPersonnelLevelLabels(): array
{
    return [
        1 => 'Başlangıç',
        2 => 'Temel',
        3 => 'Yetkin',
        4 => 'İleri',
        5 => 'Uzman',
    ];
}

/** @return array<string, string> */
function qmsCompetencyStatusLabels(): array
{
    return [
        'expired' => 'Değerlendirme Geçti',
        'due_soon' => 'Yaklaşıyor',
        'ok' => 'Güncel',
        'not_scheduled' => 'Planlanmadı',
    ];
}

/**
 * Sonraki degerlendirme tarihinden turetilen yetkinlik durumu.
 */
function qmsCompetencyStatus(?string $nextDate, string $today): string
{
    if ($nextDate === null || $nextDate === '') {
        return 'not_scheduled';
    }
    $due = (int) date('Ymd', strtotime($nextDate));
    $tod = (int) date('Ymd', strtotime($today));
    if ($due < $tod) {
        return 'expired';
    }
    $soon = (int) date('Ymd', strtotime($today . ' +' . QMS_COMPETENCY_DUE_SOON_DAYS . ' days'));
    if ($due <= $soon) {
        return 'due_soon';
    }
    return 'ok';
}

/**
 * Kapsam icindeki personel (en yeniden eskiye) + yetkinlik ozeti.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsPersonnelList(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('s.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $today = date('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT s.*, companies.company_name,
                (SELECT COUNT(*) FROM staff_competencies c
                  WHERE c.staff_id = s.id AND c.active = 1) AS competency_count,
                (SELECT COUNT(*) FROM staff_competencies c
                  WHERE c.staff_id = s.id AND c.active = 1
                    AND c.next_assessment_date IS NOT NULL
                    AND c.next_assessment_date < ?) AS expired_count
         FROM staff_members s
         INNER JOIN companies ON companies.id = s.company_id
         WHERE s.active = 1' . $scope['sql'] . '
         ORDER BY s.last_name ASC, s.first_name ASC'
    );
    $stmt->execute(array_merge([$today], $scope['params']));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kapsam icindeki tek personel.
 *
 * @return array<string, mixed> Bos dizi: yok veya kapsam disi.
 */
function qmsPersonnelFind(PDO $pdo, int $staffId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('s.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $today = date('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT s.*, companies.company_name,
                (SELECT COUNT(*) FROM staff_competencies c
                  WHERE c.staff_id = s.id AND c.active = 1) AS competency_count,
                (SELECT COUNT(*) FROM staff_competencies c
                  WHERE c.staff_id = s.id AND c.active = 1
                    AND c.next_assessment_date IS NOT NULL
                    AND c.next_assessment_date < ?) AS expired_count
         FROM staff_members s
         INNER JOIN companies ON companies.id = s.company_id
         WHERE s.id = ? AND s.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$today, $staffId], $scope['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Personelin yetkinlikleri (turetilmis durumla, en yeniden eskiye).
 *
 * @return array<int, array<string, mixed>>
 */
function qmsPersonnelCompetencies(PDO $pdo, int $staffId): array
{
    $today = date('Y-m-d');
    $stmt = $pdo->prepare(
        'SELECT id, competency_name, level, achieved_date, next_assessment_date, notes
         FROM staff_competencies
         WHERE staff_id = ? AND active = 1
         ORDER BY id DESC'
    );
    $stmt->execute([$staffId]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['status'] = qmsCompetencyStatus($row['next_assessment_date'], $today);
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Personele yeni yetkinlik ekler (personel kapsam icinde olmali).
 *
 * @param array{
 *     competency_name: string, level: int, achieved_date: string,
 *     next_assessment_date: string, notes: string
 * } $data
 * @return int|null Yeni yetkinlik id'si; dogrulama basarisizsa null.
 */
function qmsPersonnelAddCompetency(PDO $pdo, int $staffId, array $data, int $userId, string $role): ?int
{
    $staff = qmsPersonnelFind($pdo, $staffId, $userId, $role);
    if ($staff === []) {
        return null;
    }

    $name = trim((string) ($data['competency_name'] ?? ''));
    $level = (int) ($data['level'] ?? 0);
    if ($name === '' || !in_array($level, QMS_PERSONNEL_LEVELS, true)) {
        return null;
    }

    $achieved = trim((string) ($data['achieved_date'] ?? ''));
    $next = trim((string) ($data['next_assessment_date'] ?? ''));
    $dateOk = fn(string $v): bool => $v === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
    if (!$dateOk($achieved) || !$dateOk($next)) {
        return null;
    }

    $insert = $pdo->prepare(
        'INSERT INTO staff_competencies
            (staff_id, competency_name, level, achieved_date, next_assessment_date, notes, active)
         VALUES (?,?,?,?,?,?,1)'
    );
    $insert->execute([
        $staffId,
        mb_substr($name, 0, 180),
        $level,
        $achieved !== '' ? $achieved : null,
        $next !== '' ? $next : null,
        trim((string) ($data['notes'] ?? '')) !== '' ? mb_substr(trim((string) $data['notes']), 0, 4000) : null,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Personele ait bir yetkinligi kaldirir (aktif = 0).
 */
function qmsPersonnelRemoveCompetency(PDO $pdo, int $staffId, int $competencyId, int $userId, string $role): bool
{
    $staff = qmsPersonnelFind($pdo, $staffId, $userId, $role);
    if ($staff === [] || $competencyId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('UPDATE staff_competencies SET active = 0 WHERE id = ? AND staff_id = ?');
    $stmt->execute([$competencyId, $staffId]);
    return $stmt->rowCount() > 0;
}
