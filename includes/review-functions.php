<?php

declare(strict_types=1);

/**
 * Yonetimin gozden gecirmesi modulu yardimcilari.
 *
 * Durum/tur listeleri, alan dogrulama, kapsamli okuma ve bildirim kurallari tek
 * yerde tutulur; sayfalar kendi kopyalarini yazmaz. Kapsam tek kaynaktan gelir
 * (includes/access.php). Girdi degerleri (guncel KPI'lar) rapor motorundan
 * okunur, burada saklanmaz.
 */

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/notifications.php';

/** Gozden gecirme akisi: planlandi -> tamamlandi. */
const QMS_REVIEW_STATUSES = ['planned', 'completed'];

/** Kalem turleri: girdi / karar / aksiyon. */
const QMS_REVIEW_ITEM_TYPES = ['input', 'decision', 'action'];

/** @return array<string, string> */
function qmsReviewStatusLabels(): array
{
    return [
        'planned' => 'Planlandı',
        'completed' => 'Tamamlandı',
    ];
}

/** @return array<string, string> */
function qmsReviewStatusI18nKeys(): array
{
    return [
        'planned' => 'reviewStatusPlannedLabel',
        'completed' => 'reviewStatusCompletedLabel',
    ];
}

/** @return array<string, string> */
function qmsReviewItemTypeLabels(): array
{
    return [
        'input' => 'Girdi',
        'decision' => 'Karar',
        'action' => 'Aksiyon',
    ];
}

/** @return array<string, string> */
function qmsReviewItemTypeI18nKeys(): array
{
    return [
        'input' => 'reviewItemTypeInputLabel',
        'decision' => 'reviewItemTypeDecisionLabel',
        'action' => 'reviewItemTypeActionLabel',
    ];
}

function qmsReviewText(mixed $value, int $max): string
{
    return mb_substr(trim((string) $value), 0, $max);
}

/** Gecerli bir tarih (Y-m-d) degilse null. */
function qmsReviewDate(mixed $value): ?string
{
    $value = trim((string) $value);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
}

/**
 * Kapsam icindeki tek gozden gecirme kaydi.
 *
 * @return array<string, mixed> Bos dizi: kayit yok veya kapsam disi.
 */
function qmsReviewFind(PDO $pdo, int $reviewId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('management_reviews.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT management_reviews.*, companies.company_name
         FROM management_reviews
         INNER JOIN companies ON companies.id = management_reviews.company_id
         WHERE management_reviews.id = ? AND management_reviews.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$reviewId], $scope['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Kapsam icindeki gozden gecirme kayitlari (en yeniden eskiye), kalem sayilariyla.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsReviewList(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('management_reviews.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT management_reviews.*, companies.company_name,
                (SELECT COUNT(*) FROM management_review_items
                  WHERE management_review_items.review_id = management_reviews.id) AS item_count,
                (SELECT COUNT(*) FROM management_review_items
                  WHERE management_review_items.review_id = management_reviews.id
                    AND management_review_items.item_type = \'action\') AS action_count
         FROM management_reviews
         INNER JOIN companies ON companies.id = management_reviews.company_id
         WHERE management_reviews.active = 1' . $scope['sql'] . '
         ORDER BY management_reviews.review_date DESC, management_reviews.id DESC'
    );
    $stmt->execute($scope['params']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Bir gozden gecirmenin kalemleri (girdi/karar/aksiyon).
 *
 * @return array<int, array<string, mixed>>
 */
function qmsReviewItems(PDO $pdo, int $reviewId): array
{
    $stmt = $pdo->prepare(
        'SELECT management_review_items.*, users.full_name AS responsible_full_name,
                nonconformities.title AS nonconformity_title
         FROM management_review_items
         LEFT JOIN users ON users.id = management_review_items.responsible_user_id
         LEFT JOIN nonconformities ON nonconformities.id = management_review_items.nonconformity_id
         WHERE management_review_items.review_id = :review_id
         ORDER BY management_review_items.item_type, management_review_items.id'
    );
    $stmt->execute(['review_id' => $reviewId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kapsam icindeki tek gozden gecirme kalemi (duzenleme ve silme icin).
 *
 * @return array<string, mixed>
 */
function qmsReviewItemFind(PDO $pdo, int $itemId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('management_reviews.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT management_review_items.*, management_reviews.company_id
         FROM management_review_items
         INNER JOIN management_reviews ON management_reviews.id = management_review_items.review_id
         WHERE management_review_items.id = ? AND management_reviews.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$itemId], $scope['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Gozden gecirme tamamlandiginda sirketin atanmis sistem adminlerine bildirim.
 *
 * @param array{company_id: int, title: string, link: string, previous_status: string, new_status: string, actor_user_id: int} $context
 * @return int Bildirim yazilan kullanici sayisi.
 */
function qmsReviewNotifyStatusChange(PDO $pdo, array $context): int
{
    if (($context['new_status'] ?? '') !== 'completed' || ($context['previous_status'] ?? '') === 'completed') {
        return 0;
    }

    return qmsNotifyCompanyAdmins(
        $pdo,
        (int) ($context['company_id'] ?? 0),
        'review_completed',
        'Yönetimin gözden geçirmesi tamamlandı',
        (string) ($context['title'] ?? ''),
        (string) ($context['link'] ?? ''),
        (int) ($context['actor_user_id'] ?? 0)
    );
}
