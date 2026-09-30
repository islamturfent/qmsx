<?php

declare(strict_types=1);

/**
 * İyileştirme Fırsatı / Öneri (OFI) modulu yardimcilari.
 *
 * Sürekli iyileştirme icin kisa kayitlar: öneri + kapsam + fayda türü + etki +
 * sorumlu + durum akışı. CAPA'dan farkli olarak bir uygunsuzluğa baglanmaz;
 * dogrudan iyileştirme firsati olarak izlenir.
 */

require_once __DIR__ . '/access.php';

const QMS_IMPROVEMENT_STATUSES = ['submitted', 'under_review', 'approved', 'rejected', 'implemented', 'closed'];
const QMS_IMPROVEMENT_FILTERS = ['open', 'implemented', 'closed'];

/**
 * Kapsam içindeki iyileştirme kayitlari (en yeniden eskiye).
 * `eff_status` goreli daraltmaya yardimci olur: open = rejected/closed harici.
 */
function qmsImprovementList(PDO $pdo, int $userId, string $role, string $statusFilter = ''): array
{
    $scope = qmsCompanyScope('i.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $sql = 'SELECT i.*, u.full_name AS suggester_name, c.company_name
            FROM improvements i
            INNER JOIN companies c ON c.id = i.company_id
            LEFT JOIN users u ON u.id = i.suggested_by
            WHERE i.active = 1' . $scope['sql'];
    $params = $scope['params'];
    $filter = (string) $statusFilter;
    if ($filter === 'open') {
        $sql .= ' AND i.status NOT IN (\'rejected\', \'closed\', \'implemented\')';
    } elseif ($filter === 'implemented') {
        $sql .= ' AND i.status = \'implemented\'';
    } elseif ($filter === 'closed') {
        $sql .= ' AND i.status = \'closed\'';
    }
    $sql .= ' ORDER BY i.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Kapsam içindeki tek kayit; bulunamazsa bos dizi. */
function qmsImprovementFind(PDO $pdo, int $id, int $userId, string $role): array
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT * FROM improvements WHERE id = ? AND active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** Yeni öneri ekler; @return int|null */
function qmsImprovementAdd(PDO $pdo, array $data, int $userId, string $role): ?int
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
    $ins = $pdo->prepare('INSERT INTO improvements
        (company_id, title, description, category, benefit_type, impact, priority, responsible, target_date, status, eval_score, result, active, suggested_by, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)');
    $ins->execute([
        $companyId,
        mb_substr($title, 0, 190),
        trim((string) ($data['description'] ?? '')) !== '' ? mb_substr((string) $data['description'], 0, 4000) : null,
        trim((string) ($data['category'] ?? '')) !== '' ? mb_substr(trim((string) $data['category']), 0, 120) : null,
        (string) ($data['benefit_type'] ?? 'quality'),
        (string) ($data['impact'] ?? 'medium'),
        (string) ($data['priority'] ?? 'normal'),
        trim((string) ($data['responsible'] ?? '')) !== '' ? mb_substr(trim((string) $data['responsible']), 0, 180) : null,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['target_date'] ?? '')) ? (string) $data['target_date'] : null,
        (string) ($data['status'] ?? 'submitted'),
        isset($data['eval_score']) && $data['eval_score'] !== '' ? max(1, min(5, (int) $data['eval_score'])) : null,
        trim((string) ($data['result'] ?? '')) !== '' ? mb_substr((string) $data['result'], 0, 4000) : null,
        isset($data['suggested_by']) && (int) $data['suggested_by'] > 0 ? (int) $data['suggested_by'] : null,
        $userId ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Öneriyi gunceller (kapsam içinde olmali). */
function qmsImprovementUpdate(PDO $pdo, int $id, array $data, int $userId, string $role): bool
{
    $found = qmsImprovementFind($pdo, $id, $userId, $role);
    if (!$found) {
        return false;
    }
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        return false;
    }

    // Bagli uygunsuzluk: yalnizca bu sirketin acik NC'leri kabul edilir.
    $ncId = (int) ($data['linked_nc_id'] ?? 0);
    if ($ncId > 0) {
        $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
        $chk = $pdo->prepare('SELECT id FROM nonconformities WHERE id = ? AND active = 1 AND status <> \'closed\'' . $scope['sql'] . ' LIMIT 1');
        $chk->execute(array_merge([$ncId], $scope['params']));
        if (!$chk->fetchColumn()) {
            $ncId = 0;
        }
    }

    $pdo->prepare('UPDATE improvements SET
        title=?, description=?, category=?, benefit_type=?, impact=?, priority=?, responsible=?,
        linked_nc_id=?, target_date=?, status=?, eval_score=?, result=? WHERE id=?')->execute([
        mb_substr($title, 0, 190),
        trim((string) ($data['description'] ?? '')) !== '' ? mb_substr((string) $data['description'], 0, 4000) : null,
        trim((string) ($data['category'] ?? '')) !== '' ? mb_substr(trim((string) $data['category']), 0, 120) : null,
        (string) ($data['benefit_type'] ?? 'quality'),
        (string) ($data['impact'] ?? 'medium'),
        (string) ($data['priority'] ?? 'normal'),
        trim((string) ($data['responsible'] ?? '')) !== '' ? mb_substr(trim((string) $data['responsible']), 0, 180) : null,
        $ncId > 0 ? $ncId : null,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['target_date'] ?? '')) ? (string) $data['target_date'] : null,
        (string) ($data['status'] ?? 'submitted'),
        isset($data['eval_score']) && $data['eval_score'] !== '' ? max(1, min(5, (int) $data['eval_score'])) : null,
        trim((string) ($data['result'] ?? '')) !== '' ? mb_substr((string) $data['result'], 0, 4000) : null,
        $id,
    ]);
    return true;
}

/**
 * Bir sirketin acik uygunsuzluklari (OFI -> NC baglama secenekleri).
 *
 * @return array<int, array{id:int, title:string}>
 */
function qmsImprovementOpenNcOptions(PDO $pdo, int $companyId): array
{
    if ($companyId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT id, title FROM nonconformities WHERE company_id = ? AND active = 1 AND status <> \'closed\' ORDER BY id DESC'
    );
    $stmt->execute([$companyId]);
    return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title']], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Bir OFI'nin bagli uygunsuzlugunun ozeti (varsa): id + baslik + durum.
 *
 * @return array<string, mixed>|null
 */
function qmsImprovementLinkedNc(PDO $pdo, int $improvementId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT n.id, n.title, n.status FROM nonconformities n
         INNER JOIN improvements i ON i.linked_nc_id = n.id
         WHERE i.id = ? AND n.active = 1 LIMIT 1'
    );
    $stmt->execute([$improvementId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Öneriyi siler (aktif=0), kapsam içinde olmali. */
function qmsImprovementDelete(PDO $pdo, int $id, int $userId, string $role): bool
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('UPDATE improvements SET active = 0 WHERE id = ?' . $scope['sql']);
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->rowCount() > 0;
}

/**
 * Sirketin sistem adminlerine iyilestirme bildirimi gonderir (tercihe bagli eposta).
 * @param string $type 'improvement_submitted' | 'improvement_implemented'
 */
function qmsImprovementNotify(PDO $pdo, int $companyId, string $type, string $title, string $link): void
{
    if ($companyId <= 0) {
        return;
    }
    require_once __DIR__ . '/notifications.php';
    qmsNotifyCompanyAdmins($pdo, $companyId, $type, 'İyileştirme Fırsatı', $title, $link);
}

/** Durum etiketi metni (TR). */
function qmsImprovementStatusLabel(string $status): string
{
    return [
        'submitted' => 'Önerildi',
        'under_review' => 'Değerlendirmede',
        'approved' => 'Onaylandı',
        'rejected' => 'Reddedildi',
        'implemented' => 'Uygulandı',
        'closed' => 'Kapandı',
    ][$status] ?? $status;
}

/** Fayda türü etiketi (TR). */
function qmsImprovementBenefitLabel(string $type): string
{
    return [
        'quality' => 'Kalite',
        'cost' => 'Maliyet',
        'safety' => 'Güvenlik',
        'efficiency' => 'Verimlilik',
    ][$type] ?? $type;
}

/** Etki / öncelik etiketi (TR). */
function qmsImprovementLevelLabel(string $level): string
{
    return [
        'low' => 'Düşük',
        'normal' => 'Normal',
        'medium' => 'Orta',
        'high' => 'Yüksek',
    ][$level] ?? $level;
}
