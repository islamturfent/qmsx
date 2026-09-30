<?php

declare(strict_types=1);

/**
 * Denetim izi (audit trail) yardimcilari.
 *
 * Kim/ne/ne zaman/ne degisti bilgisini tutan, salt-ekle (append-only) kayit.
 * Uygulama bu kayitlari asla guncellemez veya silmez; gorunum okunur-yalnizdir.
 * Kayitlar qmsAuditLog() ile ilgili islem noktalarindan yazilir; kapsam tek
 * kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/app-ui.php';

/** @return array<string, string> Kayit turu -> gorunen etiket. */
function qmsAuditLogEntityLabels(): array
{
    return [
        'audit_report' => 'Denetim Raporu',
        'management_review' => 'Gözden Geçirme',
        'complaint' => 'Şikayet',
        'document' => 'Doküman',
        'nonconformity' => 'Uygunsuzluk',
        'corrective_action' => 'Düzeltici Faaliyet',
        'incident' => 'Olay',
        'improvement' => 'İyileştirme Fırsatı',
        'risk' => 'Risk',
        'supplier' => 'Tedarikçi',
        'training' => 'Eğitim',
        'equipment' => 'Ekipman',
        'calibration' => 'Kalibrasyon',
        'user' => 'Kullanıcı',
        'company' => 'Şirket',
    ];
}

/** @return array<string, string> Islem -> etiket. */
function qmsAuditLogActionLabels(): array
{
    return [
        'create' => 'Oluşturma',
        'update' => 'Güncelleme',
        'delete' => 'Silme',
        'status_change' => 'Durum Değişikliği',
        'finalize' => 'Kesinleştirme',
        'generate' => 'Üretim',
        'publish' => 'Yayınlama',
        'archive' => 'Arşivleme',
        'approve' => 'Onay',
        'close' => 'Kapatma',
        'complete' => 'Tamamlama',
        'assign' => 'Atama',
        'capa_created' => 'CAPA Açma',
    ];
}

/** @return array<string, string> Kayit turu -> ikon adi. */
function qmsAuditLogEntityIcons(): array
{
    return [
        'audit_report' => 'reports',
        'management_review' => 'reviews',
        'complaint' => 'complaints',
        'document' => 'documents',
        'nonconformity' => 'alert',
        'corrective_action' => 'checkBadge',
        'incident' => 'alert',
        'improvement' => 'sparkles',
        'risk' => 'warning',
        'supplier' => 'suppliers',
        'training' => 'training',
        'equipment' => 'table',
        'calibration' => 'checkBadge',
        'user' => 'users',
        'company' => 'companies',
    ];
}

/**
 * Kayit kumesini kayit turu ve islem bazinda toplar.
 * @return array{total:int, entity:array<string,int>, action:array<string,int>}
 */
function qmsAuditLogAggregate(array $rows): array
{
    $entityCounts = array_fill_keys(array_keys(qmsAuditLogEntityLabels()), 0);
    $actionCounts = array_fill_keys(array_keys(qmsAuditLogActionLabels()), 0);
    foreach ($rows as $entry) {
        if (isset($entityCounts[$entry['entity_type']])) {
            $entityCounts[$entry['entity_type']]++;
        }
        if (isset($actionCounts[$entry['action']])) {
            $actionCounts[$entry['action']]++;
        }
    }
    return ['total' => count($rows), 'entity' => $entityCounts, 'action' => $actionCounts];
}

/** Bir denetim izi kaydi ekler; salt-ekle tabloya yazar. */
function qmsAuditLog(
    PDO $pdo,
    ?int $companyId,
    int $actorUserId,
    string $entityType,
    ?int $entityId,
    string $action,
    string $summary,
    array $details = []
): int {
    $ip = null;
    if (isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
        $ip = substr($_SERVER['REMOTE_ADDR'], 0, 45);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO audit_log
            (company_id, actor_user_id, entity_type, entity_id, action, summary, details, ip_address)
         VALUES
            (:company_id, :actor_user_id, :entity_type, :entity_id, :action, :summary, :details, :ip_address)'
    );
    $stmt->execute([
        'company_id' => $companyId ?: null,
        'actor_user_id' => $actorUserId,
        'entity_type' => substr($entityType, 0, 40),
        'entity_id' => $entityId ?: null,
        'action' => substr($action, 0, 30),
        'summary' => mb_substr($summary, 0, 500),
        'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
        'ip_address' => $ip,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Kapsam icindeki denetim izi kayitlari (en yeniden eskiye), kullanici adi ile.
 *
 * @param array{company_id?: int, entity_type?: string, action?: string, from?: string, to?: string, limit?: int} $filter
 * @return array<int, array<string, mixed>>
 */
function qmsAuditLogList(PDO $pdo, int $userId, string $role, array $filter = []): array
{
    $where = [];
    $params = [];

    if ($role !== 'super_admin') {
        $companyIds = qmsVisibleCompanyIds($pdo, $userId, $role);
        if ($companyIds === []) {
            $where[] = '1 = 0';
        } elseif ($companyIds !== null) {
            $marks = implode(',', array_fill(0, count($companyIds), '?'));
            $where[] = 'audit_log.company_id IN (' . $marks . ')';
            foreach ($companyIds as $companyId) {
                $params[] = $companyId;
            }
        }
    }

    if (!empty($filter['company_id']) && $role === 'super_admin') {
        $where[] = 'audit_log.company_id = ?';
        $params[] = (int) $filter['company_id'];
    }
    if (!empty($filter['entity_type']) && isset(qmsAuditLogEntityLabels()[$filter['entity_type']])) {
        $where[] = 'audit_log.entity_type = ?';
        $params[] = $filter['entity_type'];
    }
    if (!empty($filter['action']) && isset(qmsAuditLogActionLabels()[$filter['action']])) {
        $where[] = 'audit_log.action = ?';
        $params[] = $filter['action'];
    }
    if (!empty($filter['actor_user_id'])) {
        $where[] = 'audit_log.actor_user_id = ?';
        $params[] = (int) $filter['actor_user_id'];
    }
    if (!empty($filter['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filter['from'])) {
        $where[] = 'audit_log.created_at >= ?';
        $params[] = $filter['from'] . ' 00:00:00';
    }
    if (!empty($filter['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filter['to'])) {
        $where[] = 'audit_log.created_at <= ?';
        $params[] = $filter['to'] . ' 23:59:59';
    }
    $q = trim((string) ($filter['q'] ?? ''));
    if ($q !== '') {
        $where[] = 'audit_log.summary LIKE ?';
        $params[] = '%' . $q . '%';
    }

    $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $limit = min(500, max(10, (int) ($filter['limit'] ?? 100)));

    $stmt = $pdo->prepare(
        'SELECT audit_log.*, users.full_name AS actor_name, companies.company_name
         FROM audit_log
         LEFT JOIN users ON users.id = audit_log.actor_user_id
         LEFT JOIN companies ON companies.id = audit_log.company_id'
        . $whereSql . '
         ORDER BY audit_log.id DESC
         LIMIT ' . $limit
    );
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
