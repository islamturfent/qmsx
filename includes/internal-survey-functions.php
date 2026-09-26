<?php

declare(strict_types=1);

/**
 * İç memnuniyet anketi modulu yardimcilari (çalışan memnuniyeti).
 *
 * Şirkete bağlı anketler + soru seti (1-5 değerlendirme ve/veya serbest metin) +
 * kullanıcı bazlı yanıtlar. Kapsam tek kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';

/** Geçerli değerlendirme araligi. */
const QMS_INTERNAL_RATINGS = [1, 2, 3, 4, 5];

/**
 * Kapsam içindeki anketler + yanıt ve ortalama puan ozeti (en yeniden eskiye).
 */
function qmsInternalSurveyList(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('s.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare(
        'SELECT s.*, c.company_name,
                (SELECT COUNT(DISTINCT r.user_id) FROM internal_survey_responses r
                  WHERE r.survey_id = s.id) AS respond_count,
                (SELECT ROUND(AVG(r.rating),1) FROM internal_survey_responses r
                  WHERE r.survey_id = s.id AND r.rating IS NOT NULL) AS avg_rating
         FROM internal_surveys s
         INNER JOIN companies c ON c.id = s.company_id
         WHERE s.active = 1' . $scope['sql'] . '
         ORDER BY s.id DESC'
    );
    $stmt->execute($scope['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Kapsam içindeki tek anket; bulunamazsa bos dizi. */
function qmsInternalSurveyFind(PDO $pdo, int $surveyId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('s.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare(
        'SELECT s.*, c.company_name
         FROM internal_surveys s
         INNER JOIN companies c ON c.id = s.company_id
         WHERE s.id = ? AND s.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$surveyId], $scope['params']));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** Yeni anket ekler; @return int|null */
function qmsInternalSurveyAdd(PDO $pdo, array $data, int $userId, string $role): ?int
{
    $companyId = (int) ($data['company_id'] ?? 0);
    $scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT companies.id FROM companies WHERE companies.id = ? AND companies.active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$companyId], $scope['params']));
    if (!$stmt->fetchColumn()) {
        return null;
    }
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        return null;
    }
    $ins = $pdo->prepare('INSERT INTO internal_surveys (company_id, title, description, published, active, created_by) VALUES (?,?,?,?,1,?)');
    $ins->execute([
        $companyId,
        mb_substr($title, 0, 190),
        trim((string) ($data['description'] ?? '')) !== '' ? mb_substr((string) $data['description'], 0, 4000) : null,
        !empty($data['published']) ? 1 : 0,
        $userId ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Anketi gunceller (kapsam içinde olmali). */
function qmsInternalSurveyUpdate(PDO $pdo, int $surveyId, array $data, int $userId, string $role): bool
{
    if (!qmsInternalSurveyFind($pdo, $surveyId, $userId, $role)) {
        return false;
    }
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        return false;
    }
    $pdo->prepare('UPDATE internal_surveys SET title=?, description=?, published=? WHERE id=?')->execute([
        mb_substr($title, 0, 190),
        trim((string) ($data['description'] ?? '')) !== '' ? mb_substr((string) $data['description'], 0, 4000) : null,
        !empty($data['published']) ? 1 : 0,
        $surveyId,
    ]);
    return true;
}

/** Anketi siler (aktif=0), kapsam içinde olmali. */
function qmsInternalSurveyDelete(PDO $pdo, int $surveyId, int $userId, string $role): bool
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('UPDATE internal_surveys SET active = 0 WHERE id = ?' . $scope['sql']);
    $stmt->execute(array_merge([$surveyId], $scope['params']));
    return $stmt->rowCount() > 0;
}

/** Bir anketin aktif sorulari (sirayla). */
function qmsInternalSurveyQuestions(PDO $pdo, int $surveyId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, question_text, question_type, sort_order
         FROM internal_survey_questions
         WHERE survey_id = ? AND active = 1
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$surveyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Bir ankete soru ekler (kapsam içinde olmali). @return int|null */
function qmsInternalSurveyAddQuestion(PDO $pdo, int $surveyId, array $data, int $userId, string $role): ?int
{
    if (!qmsInternalSurveyFind($pdo, $surveyId, $userId, $role)) {
        return null;
    }
    $text = trim((string) ($data['question_text'] ?? ''));
    if ($text === '') {
        return null;
    }
    $type = (string) ($data['question_type'] ?? 'rating');
    if (!in_array($type, ['rating', 'text'], true)) {
        $type = 'rating';
    }
    $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM internal_survey_questions WHERE survey_id = ?');
    $max->execute([$surveyId]);
    $sort = (int) $max->fetchColumn() + 1;
    $ins = $pdo->prepare('INSERT INTO internal_survey_questions (survey_id, question_text, question_type, sort_order, active) VALUES (?,?,?,?,1)');
    $ins->execute([$surveyId, mb_substr($text, 0, 500), $type, $sort]);
    return (int) $pdo->lastInsertId();
}

/** Soruyu siler (aktif=0) + yanitlarini temizler; ankete ait ve kapsam içinde olmali. */
function qmsInternalSurveyDeleteQuestion(PDO $pdo, int $questionId, int $userId, string $role): bool
{
    $stmt = $pdo->prepare('SELECT survey_id FROM internal_survey_questions WHERE id = ? AND active = 1');
    $stmt->execute([$questionId]);
    $surveyId = (int) $stmt->fetchColumn();
    if (!$surveyId || !qmsInternalSurveyFind($pdo, $surveyId, $userId, $role)) {
        return false;
    }
    $pdo->prepare('UPDATE internal_survey_questions SET active = 0 WHERE id = ?')->execute([$questionId]);
    $pdo->prepare('DELETE FROM internal_survey_responses WHERE question_id = ?')->execute([$questionId]);
    return true;
}

/**
 * Yayinda bir anket icin sirketin kullanicilarina (ve super adminlere)
 * "doldur" bildirimi + e-posta gonderir. Sessiz; e-posta tercihe bagli.
 */
function qmsInternalSurveyNotifyCompany(PDO $pdo, int $companyId, int $surveyId, string $title): void
{
    if ($companyId <= 0) {
        return;
    }
    require_once __DIR__ . '/notifications.php';
    $stmt = $pdo->prepare("SELECT id FROM users WHERE active = 1 AND (company_id = ? OR role = 'super_admin')");
    $stmt->execute([$companyId]);
    $message = 'Yeni iç memnuniyet anketi: ' . $title;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        qmsNotify($pdo, (int) $uid, 'internal_survey_published', 'Yeni Anket', $message, 'internal-survey-fill.php?fill=' . (int) $surveyId);
    }
}

/** Bir soruya gelen yanit ozeti (ortalama + sayi). */
function qmsInternalSurveyResults(PDO $pdo, int $surveyId): array
{
    $stmt = $pdo->prepare(
        'SELECT q.id AS question_id, q.question_text, q.question_type,
                COUNT(r.id) AS answer_count,
                ROUND(AVG(r.rating),1) AS avg_rating
         FROM internal_survey_questions q
         LEFT JOIN internal_survey_responses r ON r.question_id = q.id
         WHERE q.survey_id = ? AND q.active = 1
         GROUP BY q.id, q.question_text, q.question_type
         ORDER BY q.sort_order ASC, q.id ASC'
    );
    $stmt->execute([$surveyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Kullanıcı bu ankete daha once yanit vermis mi? */
function qmsInternalSurveyHasResponded(PDO $pdo, int $surveyId, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM internal_survey_responses WHERE survey_id = ? AND user_id = ?');
    $stmt->execute([$surveyId, $userId]);
    return (int) $stmt->fetchColumn() > 0;
}

/** Yayinda, kapsam içinde ve kullanicinin henuz yanitlamadigi anketler. */
function qmsInternalFillableSurveys(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('s.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare(
        'SELECT s.*, c.company_name,
                (SELECT COUNT(*) FROM internal_survey_questions q
                  WHERE q.survey_id = s.id AND q.active = 1) AS question_count
         FROM internal_surveys s
         INNER JOIN companies c ON c.id = s.company_id
         WHERE s.active = 1 AND s.published = 1
           AND NOT EXISTS (SELECT 1 FROM internal_survey_responses r
                           WHERE r.survey_id = s.id AND r.user_id = ?)' . $scope['sql'] . '
         ORDER BY s.id DESC'
    );
    $stmt->execute(array_merge([$userId], $scope['params']));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Yayinda ve kapsam içindeki bir ankete kullanıcının yanitlarini kaydeder.
 * Her soru bir satır olur (rating 1-5 veya metin). Tekrar yanıt varsa reddedilir.
 *
 * @param array<int, mixed> $responses question_id => değer
 */
function qmsInternalSurveySubmit(PDO $pdo, int $surveyId, array $responses, int $userId, string $role): bool
{
    $scope = qmsCompanyScope('s.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT s.id FROM internal_surveys s WHERE s.id = ? AND s.active = 1 AND s.published = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$surveyId], $scope['params']));
    if (!$stmt->fetchColumn()) {
        return false;
    }
    if (qmsInternalSurveyHasResponded($pdo, $surveyId, $userId)) {
        return false;
    }

    $questions = qmsInternalSurveyQuestions($pdo, $surveyId);
    $qById = [];
    foreach ($questions as $q) {
        $qById[(int) $q['id']] = $q;
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO internal_survey_responses (survey_id, question_id, user_id, rating, answer_text) VALUES (?,?,?,?,?)');
        $any = false;
        foreach ($responses as $qid => $val) {
            $qid = (int) $qid;
            if (!isset($qById[$qid])) {
                continue;
            }
            $rating = null;
            $text = null;
            if ($qById[$qid]['question_type'] === 'rating') {
                $rating = (int) $val;
                if (!in_array($rating, QMS_INTERNAL_RATINGS, true)) {
                    continue;
                }
            } else {
                $text = trim((string) $val);
                if ($text === '') {
                    continue;
                }
                $text = mb_substr($text, 0, 4000);
            }
            $ins->execute([$surveyId, $qid, $userId, $rating, $text]);
            $any = true;
        }
        if (!$any) {
            $pdo->rollBack();
            return false;
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
