<?php

declare(strict_types=1);

/**
 * Egitim yonetimi modulu yardimcilari.
 *
 * Durum listeleri, dogrulama ve kapsamli kayit okuma tek yerde tutulur; sayfalar
 * kendi kopyalarini yazmaz. Kapsam tek kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';

/** Egitim durum akisi: planlandi -> devam -> tamamlandi, ayrica iptal. */
const QMS_TRAINING_STATUSES = ['planned', 'in_progress', 'completed', 'cancelled'];

/** Katilimci durumu: atandi -> katildi -> tamamladi. */
const QMS_TRAINING_PARTICIPANT_STATUSES = ['assigned', 'attended', 'completed'];

/** @return array<string, string> */
function qmsTrainingStatusLabels(): array
{
    return [
        'planned' => 'Planlandı',
        'in_progress' => 'Devam Ediyor',
        'completed' => 'Tamamlandı',
        'cancelled' => 'İptal Edildi',
    ];
}

/** @return array<string, string> */
function qmsTrainingParticipantStatusLabels(): array
{
    return [
        'assigned' => 'Atandı',
        'attended' => 'Katıldı',
        'completed' => 'Tamamladı',
    ];
}

/**
 * Egıtim alanlari icin i18n anahtarlari - arayuzde sabit metin yazilmaz.
 *
 * @return array<string, string>
 */
function qmsTrainingStatusI18nKeys(): array
{
    return [
        'planned' => 'trainingStatusPlannedLabel',
        'in_progress' => 'trainingStatusInProgressLabel',
        'completed' => 'trainingStatusCompletedLabel',
        'cancelled' => 'trainingStatusCancelledLabel',
    ];
}

/** @return array<string, string> */
function qmsTrainingParticipantStatusI18nKeys(): array
{
    return [
        'assigned' => 'trainingParticipantAssignedLabel',
        'attended' => 'trainingParticipantAttendedLabel',
        'completed' => 'trainingParticipantCompletedLabel',
    ];
}

function qmsTrainingText(mixed $value, int $max): string
{
    return mb_substr(trim((string) $value), 0, $max);
}

/** Gecerli bir tarih (Y-m-d) degilse null. */
function qmsTrainingDate(mixed $value): ?string
{
    $value = trim((string) $value);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
}

/** Sure (saat): bos ise null, gecersiz veya aralik disiysa null. */
function qmsTrainingDuration(mixed $value): ?float
{
    $value = str_replace(',', '.', trim((string) $value));

    if ($value === '' || !is_numeric($value)) {
        return null;
    }

    $hours = (float) $value;

    return $hours >= 0 && $hours <= 1000 ? round($hours, 2) : null;
}

/** Puan: bos ise null, gecersiz veya 0-100 disiysa null. */
function qmsTrainingScore(mixed $value): ?float
{
    $value = str_replace(',', '.', trim((string) $value));

    if ($value === '' || !is_numeric($value)) {
        return null;
    }

    $score = (float) $value;

    return $score >= 0 && $score <= 100 ? round($score, 2) : null;
}

/**
 * Kapsam icindeki tek egitim kaydi.
 *
 * Kayit id ile tek basina okunmaz: kapsam cumlesi sorgunun parcasidir, boylece
 * id degistirilerek baska sirketin egitimi acilamaz.
 *
 * @return array<string, mixed> Bos dizi: kayit yok veya kapsam disi.
 */
function qmsTrainingFind(PDO $pdo, int $trainingId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('trainings.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT trainings.*, companies.company_name
         FROM trainings
         INNER JOIN companies ON companies.id = trainings.company_id
         WHERE trainings.id = ? AND trainings.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$trainingId], $scope['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Kapsam icindeki egitim kayitlari (liste).
 *
 * @return array<int, array<string, mixed>>
 */
function qmsTrainingList(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('trainings.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT trainings.*, companies.company_name
         FROM trainings
         INNER JOIN companies ON companies.id = trainings.company_id
         WHERE trainings.active = 1' . $scope['sql'] . '
         ORDER BY trainings.planned_date IS NULL, trainings.planned_date DESC, trainings.id DESC'
    );
    $stmt->execute($scope['params']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Bir egitimin katilimcilar, kullanici bilgisiyle birlikte.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsTrainingParticipants(PDO $pdo, int $trainingId): array
{
    $stmt = $pdo->prepare(
        'SELECT training_participants.*, users.full_name, users.role
         FROM training_participants
         INNER JOIN users ON users.id = training_participants.user_id
         WHERE training_participants.training_id = :training_id
         ORDER BY users.full_name'
    );
    $stmt->execute(['training_id' => $trainingId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Katilimci ozeti: toplam, tamamlayan ve yuzde.
 *
 * @param array<int, array<string, mixed>> $participants
 * @return array{total: int, completed: int, attended: int, rate: float}
 */
function qmsTrainingParticipantSummary(array $participants): array
{
    $total = count($participants);
    $completed = 0;
    $attended = 0;

    foreach ($participants as $participant) {
        if (($participant['status'] ?? '') === 'completed') {
            $completed++;
        } elseif (($participant['status'] ?? '') === 'attended') {
            $attended++;
        }
    }

    return [
        'total' => $total,
        'completed' => $completed,
        'attended' => $attended,
        'rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0.0,
    ];
}

/**
 * Bir kullanici hesabini ayni sirketteki bir personele eslestirir.
 *
 * Oncelik: staff_members.user_id baglantisi; yoksa email; son olarak ad+soyad.
 *
 * @return array{id:int}|null
 */
function qmsTrainingFindStaffByUser(PDO $pdo, int $companyId, int $userId): ?array
{
    $u = $pdo->prepare('SELECT email, full_name FROM users WHERE id = ? AND active = 1 LIMIT 1');
    $u->execute([$userId]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return null;
    }

    // 1) Acik kullanici baglantisi (tercih edilen).
    $c = $pdo->prepare('SELECT id FROM staff_members WHERE company_id = ? AND user_id = ? AND active = 1 LIMIT 1');
    $c->execute([$companyId, $userId]);
    if ($v = $c->fetchColumn()) {
        return ['id' => (int) $v];
    }

    // 2) Email eslesmesi.
    $email = trim((string) ($user['email'] ?? ''));
    if ($email !== '') {
        $ce = $pdo->prepare('SELECT id FROM staff_members WHERE company_id = ? AND LOWER(email) = LOWER(?) AND active = 1 LIMIT 1');
        $ce->execute([$companyId, $email]);
        if ($v = $ce->fetchColumn()) {
            return ['id' => (int) $v];
        }
    }

    // 3) Ad + soyad eslesmesi.
    $parts = preg_split('/\s+/', trim((string) ($user['full_name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($parts) && count($parts) >= 2) {
        $fn = $parts[0];
        $ln = implode(' ', array_slice($parts, 1));
        $cn = $pdo->prepare('SELECT id FROM staff_members WHERE company_id = ? AND first_name = ? AND last_name = ? AND active = 1 LIMIT 1');
        $cn->execute([$companyId, $fn, $ln]);
        if ($v = $cn->fetchColumn()) {
            return ['id' => (int) $v];
        }
    }

    return null;
}

/**
 * Bir katilimcinin egitimi tamamlamasiyla ilgili personelin yetkinligini
 * gunceller. Egitim hedef yetkinlik (target_competency) tasiyorsa ve katilimci
 * bir personele baglanabiliyorsa staff_competencies kaydi olusturur/gunceller.
 *
 * Vade varsayilan olarak +1 yildir; egitimin tamamlanma tarihi esas alinir.
 *
 * @return bool Personel bulunup yetkinlik yazildiysa true.
 */
function qmsTrainingSyncCompetency(PDO $pdo, int $trainingId, int $participantUserId): bool
{
    $train = $pdo->prepare(
        'SELECT company_id, title, target_competency, completed_date FROM trainings WHERE id = ? AND active = 1 LIMIT 1'
    );
    $train->execute([$trainingId]);
    $t = $train->fetch(PDO::FETCH_ASSOC);
    if (!$t) {
        return false;
    }

    $competency = trim((string) ($t['target_competency'] ?? ''));
    if ($competency === '') {
        return false;
    }
    $companyId = (int) $t['company_id'];

    $staff = qmsTrainingFindStaffByUser($pdo, $companyId, $participantUserId);
    if (!$staff) {
        return false;
    }
    $staffId = (int) $staff['id'];

    $achieved = trim((string) ($t['completed_date'] ?? ''));
    if ($achieved === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $achieved)) {
        $achieved = date('Y-m-d');
    }
    $nextAssessment = date('Y-m-d', strtotime($achieved . ' +1 year'));
    $notes = 'Eğitim tamamlandı: ' . mb_substr(trim((string) $t['title']), 0, 200);

    $find = $pdo->prepare('SELECT id FROM staff_competencies WHERE staff_id = ? AND competency_name = ? AND active = 1 LIMIT 1');
    $find->execute([$staffId, $competency]);
    $existing = $find->fetchColumn();

    if ($existing) {
        $upd = $pdo->prepare(
            'UPDATE staff_competencies SET achieved_date = ?, next_assessment_date = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $upd->execute([$achieved, $nextAssessment, $notes, (int) $existing]);
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO staff_competencies (staff_id, competency_name, level, achieved_date, next_assessment_date, notes, active)
             VALUES (?, ?, NULL, ?, ?, ?, 1)'
        );
        $ins->execute([$staffId, $competency, $achieved, $nextAssessment, $notes]);
    }

    return true;
}
