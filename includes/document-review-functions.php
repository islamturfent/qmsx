<?php

declare(strict_types=1);

/**
 * Doküman gözden geçirme merkezi yardimcilari.
 *
 * Vadesi gelen doküman kuyrugu + gözden geçirme islem kaydi. Gözden geçirme
 * karari, not ve sonraki tarih document_reviews tablosunda izlenir ve
 * dokümanin review_date'i ilerletilir. Kapsam tek kaynaktan gelir.
 */

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/audit-log-functions.php';

/** Gözden geçirme kararlari. */
const QMS_DOC_REVIEW_OUTCOMES = ['ok', 'needs_revision'];

/** Kaç gün sonrasina kadar "yaklasan" kabul edilir. */
const QMS_DOC_REVIEW_DUE_SOON_DAYS = 30;

/** @return array<string, string> */
function qmsDocumentReviewOutcomeLabels(): array
{
    return ['ok' => 'İnceleme Gerekmez', 'needs_revision' => 'Revizyon Gerekli'];
}

/** @return array<string, string> */
function qmsDocumentReviewOutcomeI18nKeys(): array
{
    return ['ok' => 'docReviewOutcomeOkLabel', 'needs_revision' => 'docReviewOutcomeRevisionLabel'];
}

/** @return array<string, string> */
function qmsDocumentReviewStatusLabels(): array
{
    return [
        'overdue' => 'Vadesi Geçti',
        'due_soon' => 'Yaklaşıyor',
        'on_schedule' => 'Programında',
        'not_scheduled' => 'Planlanmadı',
    ];
}

/** @return array<string, string> */
function qmsDocumentReviewStatusI18nKeys(): array
{
    return [
        'overdue' => 'docReviewStatusOverdueLabel',
        'due_soon' => 'docReviewStatusDueSoonLabel',
        'on_schedule' => 'docReviewStatusOnScheduleLabel',
        'not_scheduled' => 'docReviewStatusNotScheduledLabel',
    ];
}

/**
 * review_date'den turetilen gözden geçirme durumu (kayitli kopya yok).
 */
function qmsDocumentReviewStatus(?string $reviewDate, string $today): string
{
    if ($reviewDate === null || $reviewDate === '') {
        return 'not_scheduled';
    }

    $due = (int) date('Ymd', strtotime($reviewDate));
    $tod = (int) date('Ymd', strtotime($today));
    if ($due < $tod) {
        return 'overdue';
    }
    $soon = (int) date('Ymd', strtotime($today . ' +' . QMS_DOC_REVIEW_DUE_SOON_DAYS . ' days'));
    if ($due <= $soon) {
        return 'due_soon';
    }
    return 'on_schedule';
}

/**
 * Gözden geçirme kuyrugu: aktif ve arsivlenmemis dokümanlar, duruma gore.
 * Vadesi gecenler once listelenir.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsDocumentReviewQueue(PDO $pdo, int $userId, string $role, string $filter = ''): array
{
    $scope = qmsCompanyScope('documents.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $today = date('Y-m-d');

    $stmt = $pdo->prepare(
        'SELECT documents.id, documents.company_id, documents.document_code, documents.title,
                documents.category, documents.owner_name, documents.status, documents.review_date,
                documents.effective_date, documents.current_revision, companies.company_name
         FROM documents
         INNER JOIN companies ON companies.id = documents.company_id
         WHERE documents.active = 1 AND documents.status <> \'archived\'' . $scope['sql'] . '
         ORDER BY documents.review_date IS NULL ASC, documents.review_date ASC, documents.id DESC'
    );
    $stmt->execute($scope['params']);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['review_status'] = qmsDocumentReviewStatus($row['review_date'], $today);
        if ($filter !== '' && $row['review_status'] !== $filter) {
            continue;
        }
        $rows[] = $row;
    }

    return $rows;
}

/**
 * En son gözden geçirme islemleri (gecmis, en yeniden eskiye).
 *
 * @return array<int, array<string, mixed>>
 */
function qmsDocumentReviewHistory(PDO $pdo, int $userId, string $role, int $limit = 20): array
{
    $scope = qmsCompanyScope('dr.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT dr.id, dr.document_id, dr.outcome, dr.notes, dr.reviewed_at, dr.next_review_date,
                dr.created_at, documents.document_code, documents.title AS document_title,
                companies.company_name, users.full_name AS reviewer_name
         FROM document_reviews dr
         INNER JOIN documents ON documents.id = dr.document_id
         INNER JOIN companies ON companies.id = dr.company_id
         LEFT JOIN users ON users.id = dr.reviewer_user_id
         WHERE dr.active = 1' . $scope['sql'] . '
         ORDER BY dr.reviewed_at DESC, dr.id DESC
         LIMIT ' . max(1, (int) $limit)
    );
    $stmt->execute($scope['params']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Bir gözden geçirme islemini kaydeder ve dokümanin sonraki tarihini ilerletir.
 *
 * @param array{
 *     outcome: string, notes: string, reviewed_at: string, next_review_date: string
 * } $data
 * @return int|null Yeni gözden geçirme kaydi id'si; dogrulama basarisizsa null.
 */
function qmsDocumentReviewRecord(PDO $pdo, int $documentId, array $data, int $userId, string $role): ?int
{
    $outcome = (string) ($data['outcome'] ?? '');
    if (!in_array($outcome, QMS_DOC_REVIEW_OUTCOMES, true)) {
        return null;
    }
    $notes = trim((string) ($data['notes'] ?? ''));
    $reviewedAt = trim((string) ($data['reviewed_at'] ?? ''));
    $next = trim((string) ($data['next_review_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewedAt) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $next)) {
        return null;
    }

    $scope = qmsCompanyScope('documents.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare(
        'SELECT documents.company_id FROM documents
         WHERE documents.id = ? AND documents.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$documentId], $scope['params']));
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) {
        return null;
    }
    $companyId = (int) $doc['company_id'];

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO document_reviews
                (document_id, company_id, reviewer_user_id, reviewed_at, outcome, notes, next_review_date, active)
             VALUES (:document_id, :company_id, :reviewer_user_id, :reviewed_at, :outcome, :notes, :next_review_date, 1)'
        );
        $insert->execute([
            'document_id' => $documentId,
            'company_id' => $companyId,
            'reviewer_user_id' => $userId ?: null,
            'reviewed_at' => $reviewedAt,
            'outcome' => $outcome,
            'notes' => $notes !== '' ? mb_substr($notes, 0, 4000) : null,
            'next_review_date' => $next,
        ]);
        $reviewId = (int) $pdo->lastInsertId();

        // Dokümanin gözden geçirme tarihini ilerlet.
        $update = $pdo->prepare('UPDATE documents SET review_date = ?, updated_at = NOW() WHERE id = ?');
        $update->execute([$next, $documentId]);

        qmsAuditLog(
            $pdo,
            $companyId,
            $userId,
            'document',
            $documentId,
            'review',
            'Doküman gözden geçirildi: ' . ($data['document_title'] ?? '')
        );

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $reviewId;
}
