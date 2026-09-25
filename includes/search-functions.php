<?php

declare(strict_types=1);

/**
 * Arama + benzer vaka (semantik arama yuzeyi).
 *
 * Yerel, harici servis kullanmaz: kullanici sorgusu anahtar kelimelere bolunur,
 * kapsam icindeki kayit tiplerinde LIKE eslesmesi yapilir ve ilgi puani
 * (kelime isabeti) ile siralanir. Benzer vaka, ayni anahtar kelimeleri paylasan
 * diger kayitlari getirir. Kapsam tek kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/app-ui.php';

/**
 * Aranabilir kayit tipleri.
 *
 * @return array<string, array{label: string, icon: string, table: string, title_col: string, body_cols: array<int,string>, link: string, link_col: string}>
 */
function qmsSearchTypes(): array
{
    return [
        'nonconformity' => ['label' => 'Uygunsuzluk', 'icon' => 'alert', 'table' => 'nonconformities', 'title_col' => 'title', 'body_cols' => ['description'], 'link' => 'nonconformity-detail.php?id=', 'link_col' => 'id'],
        'complaint' => ['label' => 'Şikayet', 'icon' => 'complaints', 'table' => 'complaints', 'title_col' => 'subject', 'body_cols' => ['description'], 'link' => 'complaint-detail.php?id=', 'link_col' => 'id'],
        'risk' => ['label' => 'Risk', 'icon' => 'warning', 'table' => 'risks', 'title_col' => 'title', 'body_cols' => ['description'], 'link' => 'risk-detail.php?id=', 'link_col' => 'id'],
        'corrective_action' => ['label' => 'Düzeltici Faaliyet', 'icon' => 'checkBadge', 'table' => 'corrective_actions', 'company_col' => 'nonconformities.company_id', 'join' => 'INNER JOIN nonconformities ON nonconformities.id = corrective_actions.nonconformity_id', 'title_col' => 'action_text', 'body_cols' => [], 'link' => 'corrective-action-detail.php?id=', 'link_col' => 'id'],
        'document' => ['label' => 'Doküman', 'icon' => 'documents', 'table' => 'documents', 'title_col' => 'title', 'body_cols' => ['description'], 'link' => 'document-detail.php?id=', 'link_col' => 'id'],
        'supplier' => ['label' => 'Tedarikçi', 'icon' => 'suppliers', 'table' => 'suppliers', 'title_col' => 'name', 'body_cols' => ['category'], 'link' => 'supplier-detail.php?id=', 'link_col' => 'id'],
        'training' => ['label' => 'Eğitim', 'icon' => 'training', 'table' => 'trainings', 'title_col' => 'title', 'body_cols' => ['description'], 'link' => 'training-detail.php?id=', 'link_col' => 'id'],
        'equipment' => ['label' => 'Ekipman', 'icon' => 'table', 'table' => 'equipment', 'title_col' => 'name', 'body_cols' => ['category'], 'link' => 'equipment-detail.php?id=', 'link_col' => 'id'],
        'personnel' => ['label' => 'Personel', 'icon' => 'users', 'table' => 'staff_members', 'title_col' => 'first_name', 'body_cols' => ['position', 'department', 'employee_code'], 'link' => 'personnel-detail.php?id=', 'link_col' => 'id'],
        'review' => ['label' => 'Gözden Geçirme', 'icon' => 'reviews', 'table' => 'management_reviews', 'title_col' => 'title', 'body_cols' => ['scope_notes'], 'link' => 'review-detail.php?id=', 'link_col' => 'id'],
        'finding' => ['label' => 'Dış Denetim Bulgusu', 'icon' => 'alert', 'table' => 'external_audit_findings', 'company_col' => 'ea.company_id', 'join' => 'INNER JOIN external_audits ea ON ea.id = external_audit_findings.external_audit_id', 'title_col' => 'finding_text', 'body_cols' => ['notes'], 'link' => 'external-audits.php?id=', 'link_col' => 'external_audit_id'],
    ];
}

/**
 * Sorguyu anahtar kelimelere boler (>=3 karakterli sozcukler).
 *
 * @return array<int, string>
 */
function qmsSearchKeywords(string $query): array
{
    $tokens = preg_split('/[\s,_\-;:]+/u', mb_strtolower(trim($query))) ?: [];
    $keywords = [];
    foreach ($tokens as $token) {
        if (mb_strlen($token) >= 3) {
            $keywords[] = $token;
        }
    }
    return array_values(array_unique($keywords));
}

/**
 * Bir kayit tipinde anahtar kelimelerle sinirli LIKE aramasi yapar.
 *
 * @param array<int, string> $keywords
 * @return array<int, array<string, mixed>> her eslesmede score = isabet sayisi.
 */
function qmsSearchType(PDO $pdo, string $type, int $userId, string $role, array $keywords, ?int $excludeId = null, int $limit = 8): array
{
    $types = qmsSearchTypes();
    if (!isset($types[$type]) || $keywords === []) {
        return [];
    }
    $def = $types[$type];

    $columns = array_merge([$def['title_col']], $def['body_cols']);
    $companyCol = $def['company_col'] ?? ($def['table'] . '.company_id');
    $join = $def['join'] ?? '';
    $scope = qmsCompanyScope($companyCol, qmsVisibleCompanyIds($pdo, $userId, $role));

    $wheres = [];
    $params = [];
    foreach ($columns as $column) {
        foreach ($keywords as $keyword) {
            $wheres[] = 'LOWER(' . $def['table'] . '.' . $column . ') LIKE ?';
            $params[] = '%' . $keyword . '%';
        }
    }
    if ($excludeId !== null) {
        $wheres[] = $def['table'] . '.' . $def['link_col'] . ' <> ?';
        $params[] = $excludeId;
    }

    $selectCols = [$def['table'] . '.' . $def['link_col'] . ' AS id', $def['table'] . '.' . $def['title_col'] . ' AS title'];
    foreach ($def['body_cols'] as $bodyCol) {
        $selectCols[] = $def['table'] . '.' . $bodyCol . ' AS body';
    }
    $selectSql = implode(', ', $selectCols);

    $sql = 'SELECT ' . $selectSql . ', (' . implode(' + ', array_fill(0, count($wheres) - ($excludeId !== null ? 1 : 0), '1')) . ') AS score FROM ' . $def['table'] . ' ' . $join
        . ' WHERE ' . $def['table'] . '.active = 1 AND (' . implode(' OR ', $wheres) . ')' . $scope['sql']
        . ' ORDER BY score DESC, ' . $def['table'] . '.' . $def['link_col'] . ' DESC LIMIT ' . (int) $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $scope['params']));

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kapsam icindeki genel arama: tur kapsami istenirse yalniz o tur.
 *
 * @param array<int, string>|null $onlyType Tek tur veya null (tumu).
 * @return array<int, array<string, mixed>>
 */
function qmsSearchRecords(PDO $pdo, int $userId, string $role, string $query, ?string $onlyType = null, int $perType = 5): array
{
    $keywords = qmsSearchKeywords($query);
    if ($keywords === []) {
        return [];
    }

    $results = [];
    $types = $onlyType !== null && isset(qmsSearchTypes()[$onlyType]) ? [$onlyType] : array_keys(qmsSearchTypes());
    foreach ($types as $type) {
        foreach (qmsSearchType($pdo, $type, $userId, $role, $keywords, null, $perType) as $row) {
            $row['type'] = $type;
            $results[] = $row;
        }
    }

    usort($results, static fn(array $a, array $b): int => (int) $b['score'] <=> (int) $a['score']);

    return $results;
}

/**
 * Verilen anahtar kelimeleri paylasan, belirtilen kayit disindaki benzer vakalar.
 *
 * @param array<int, string> $keywords
 * @return array<int, array<string, mixed>>
 */
function qmsSimilarCases(PDO $pdo, int $userId, string $role, string $query, string $excludeType, int $excludeId, int $limit = 4): array
{
    $keywords = qmsSearchKeywords($query);
    if ($keywords === []) {
        return [];
    }

    $results = [];
    foreach (array_keys(qmsSearchTypes()) as $type) {
        if ($type === $excludeType) {
            foreach (qmsSearchType($pdo, $type, $userId, $role, $keywords, $excludeId, $limit) as $row) {
                $row['type'] = $type;
                $results[] = $row;
            }
        } else {
            foreach (qmsSearchType($pdo, $type, $userId, $role, $keywords, null, 2) as $row) {
                $row['type'] = $type;
                $results[] = $row;
            }
        }
    }

    usort($results, static fn(array $a, array $b): int => (int) $b['score'] <=> (int) $a['score']);
    return array_slice($results, 0, $limit);
}
