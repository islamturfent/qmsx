<?php

declare(strict_types=1);

/**
 * Müşteri memnuniyeti ankette modulu yardimcilari.
 *
 * Sirkete bagli anket sablonlari (soru seti) + müşteri yanitlari (1-5
 * degerlendirme). Ortalama memnuniyet puanı yanitlardan turetilir; saklanan
 * tek puan overall_score'tur. Kapsam tek kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';

/** Geçerli degerlendirme araligi. */
const QMS_SATISFACTION_RATINGS = [1, 2, 3, 4, 5];

/**
 * Kapsam icindeki anketler (en yeniden eskiye) + yanit ve puan ozeti.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsSatisfactionSurveyList(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('s.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT s.id, s.title, s.description, s.company_id, companies.company_name,
                (SELECT COUNT(*) FROM satisfaction_responses r
                  WHERE r.survey_id = s.id AND r.active = 1) AS response_count,
                (SELECT ROUND(AVG(r.overall_score),1) FROM satisfaction_responses r
                  WHERE r.survey_id = s.id AND r.active = 1) AS avg_score
         FROM satisfaction_surveys s
         INNER JOIN companies ON companies.id = s.company_id
         WHERE s.active = 1' . $scope['sql'] . '
         ORDER BY s.id DESC'
    );
    $stmt->execute($scope['params']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kapsam icindeki tek anket + yanit ozeti.
 *
 * @return array<string, mixed> Bos dizi: yok veya kapsam disi.
 */
function qmsSatisfactionSurveyFind(PDO $pdo, int $surveyId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('s.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT s.*, companies.company_name,
                (SELECT COUNT(*) FROM satisfaction_responses r
                  WHERE r.survey_id = s.id AND r.active = 1) AS response_count,
                (SELECT ROUND(AVG(r.overall_score),1) FROM satisfaction_responses r
                  WHERE r.survey_id = s.id AND r.active = 1) AS avg_score
         FROM satisfaction_surveys s
         INNER JOIN companies ON companies.id = s.company_id
         WHERE s.id = ? AND s.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$surveyId], $scope['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Bir anketin aktif sorulari (sirayla).
 *
 * @return array<int, array<string, mixed>>
 */
function qmsSatisfactionQuestions(PDO $pdo, int $surveyId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, question_text, sort_order
         FROM satisfaction_questions
         WHERE survey_id = ? AND active = 1
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$surveyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Bir anketin yanitlari (en yeniden eskiye) + soru bazli puan ortalamasi.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsSatisfactionResponses(PDO $pdo, int $surveyId): array
{
    $stmt = $pdo->prepare(
        'SELECT r.id, r.customer_name, r.customer_contact, r.responded_at, r.overall_score,
                r.comment, r.created_at, companies.company_name
         FROM satisfaction_responses r
         INNER JOIN companies ON companies.id = r.company_id
         WHERE r.survey_id = ? AND r.active = 1
         ORDER BY r.responded_at DESC, r.id DESC'
    );
    $stmt->execute([$surveyId]);

    $responses = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $answerStmt = $pdo->prepare(
            'SELECT question_id, rating FROM satisfaction_response_answers
             WHERE response_id = ? AND active = 1'
        );
        $answerStmt->execute([(int) $row['id']]);
        $row['answers'] = [];
        foreach ($answerStmt->fetchAll(PDO::FETCH_ASSOC) as $answer) {
            $row['answers'][(int) $answer['question_id']] = (int) $answer['rating'];
        }
        $responses[] = $row;
    }

    return $responses;
}

/**
 * Bir anket için yeni bir müşteri yaniti kaydeder.
 *
 * overall_score, yanit icindeki soru puanlarinin ortalamasindan turetilir
 * (5 puanlik olcek). Ankete ait olmayan soru puanlari yok sayilir.
 *
 * @param array{
 *     customer_name: string, customer_contact: string, responded_at: string,
 *     comment: string, answers: array<int, int>
 * } $data
 * @return int|null Yeni yanit id'si; dogrulama basarisizsa null.
 */
function qmsSatisfactionRecordResponse(PDO $pdo, int $surveyId, array $data, int $userId, string $role): ?int
{
    $scope = qmsCompanyScope('s.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare(
        'SELECT s.company_id FROM satisfaction_surveys s
         WHERE s.id = ? AND s.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$surveyId], $scope['params']));
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$survey) {
        return null;
    }
    $companyId = (int) $survey['company_id'];
    $surveyId = (int) $surveyId;

    $respondedAt = trim((string) ($data['responded_at'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $respondedAt)) {
        return null;
    }

    // Sadece anketin kendi sorularinin puanlari kabul edilir.
    $questions = qmsSatisfactionQuestions($pdo, $surveyId);
    $validQuestionIds = array_map('intval', array_column($questions, 'id'));

    $answers = [];
    foreach (($data['answers'] ?? []) as $qid => $rating) {
        $qid = (int) $qid;
        $rating = (int) $rating;
        if (in_array($qid, $validQuestionIds, true) && in_array($rating, QMS_SATISFACTION_RATINGS, true)) {
            $answers[$qid] = $rating;
        }
    }
    if ($answers === []) {
        return null;
    }

    $overall = (int) round(array_sum($answers) / count($answers));
    $overall = max(1, min(5, $overall));

    $pdo->beginTransaction();
    try {
        $insertResponse = $pdo->prepare(
            'INSERT INTO satisfaction_responses
                (survey_id, company_id, customer_name, customer_contact, responded_at, overall_score, comment, active)
             VALUES (:survey_id, :company_id, :customer_name, :customer_contact, :responded_at, :overall_score, :comment, 1)'
        );
        $insertResponse->execute([
            'survey_id' => $surveyId,
            'company_id' => $companyId,
            'customer_name' => trim((string) ($data['customer_name'] ?? '')) !== ''
                ? mb_substr(trim((string) $data['customer_name']), 0, 180) : null,
            'customer_contact' => trim((string) ($data['customer_contact'] ?? '')) !== ''
                ? mb_substr(trim((string) $data['customer_contact']), 0, 180) : null,
            'responded_at' => $respondedAt,
            'overall_score' => $overall,
            'comment' => trim((string) ($data['comment'] ?? '')) !== ''
                ? mb_substr(trim((string) $data['comment']), 0, 4000) : null,
        ]);
        $responseId = (int) $pdo->lastInsertId();

        $insertAnswer = $pdo->prepare(
            'INSERT INTO satisfaction_response_answers (response_id, question_id, rating, active)
             VALUES (?, ?, ?, 1)'
        );
        foreach ($answers as $qid => $rating) {
            $insertAnswer->execute([$responseId, $qid, $rating]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $responseId;
}
