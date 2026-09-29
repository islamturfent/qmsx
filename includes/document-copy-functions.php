<?php

declare(strict_types=1);

/**
 * Doküman dağıtım kontrolü modulu yardimcilari.
 *
 * Dokümanlara dagitilan kontrollü kopyaların kaydi. Kapsam tek kaynaktan
 * gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';

/** Kopya durumlari. */
const QMS_COPY_STATUSES = ['distributed', 'returned', 'obsolete'];

/** @return array<string, string> */
function qmsCopyStatusLabels(): array
{
    return ['distributed' => 'Dağıtıldı', 'returned' => 'İade Edildi', 'obsolete' => 'Geçersiz'];
}

/** @return array<string, string> */
function qmsCopyStatusI18nKeys(): array
{
    return ['distributed' => 'copyStatusDistributedLabel', 'returned' => 'copyStatusReturnedLabel', 'obsolete' => 'copyStatusObsoleteLabel'];
}

/**
 * Kapsam icindeki kopyalar (en yeniden eskiye) + istege bagli durum filteri.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsDocumentCopyList(PDO $pdo, int $userId, string $role, string $status = ''): array
{
    $scope = qmsCompanyScope('c.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $sql = 'SELECT c.*, companies.company_name, documents.document_code, documents.title AS document_title
            FROM document_copies c
            INNER JOIN companies ON companies.id = c.company_id
            INNER JOIN documents ON documents.id = c.document_id
            WHERE c.active = 1' . $scope['sql'];
    $params = $scope['params'];

    if ($status !== '' && in_array($status, QMS_COPY_STATUSES, true)) {
        $sql .= ' AND c.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY c.distributed_on DESC, c.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kapsam icindeki tek kopya.
 *
 * @return array<string, mixed> Bos dizi: yok veya kapsam disi.
 */
function qmsDocumentCopyFind(PDO $pdo, int $copyId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('c.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare(
        'SELECT c.*, companies.company_name, documents.document_code, documents.title AS document_title
         FROM document_copies c
         INNER JOIN companies ON companies.id = c.company_id
         INNER JOIN documents ON documents.id = c.document_id
         WHERE c.id = ? AND c.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$copyId], $scope['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Yeni kontrollü kopya kaydeder. Doküman kapsam icinde olmali; ayni doküman
 * icin kopya numarasi tekrar olmamali.
 *
 * @param array{
 *     document_id: int, copy_no: string, recipient_name: string, location: string,
 *     distributed_on: string, notes: string
 * } $data
 * @return int|null Yeni kopya id'si; dogrulama basarisizsa null.
 */
function qmsDocumentCopyAdd(PDO $pdo, array $data, int $userId, string $role): ?int
{
    $documentId = (int) ($data['document_id'] ?? 0);
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

    $copyNo = trim((string) ($data['copy_no'] ?? ''));
    $recipient = trim((string) ($data['recipient_name'] ?? ''));
    $distributedOn = trim((string) ($data['distributed_on'] ?? ''));
    if ($copyNo === '' || $recipient === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $distributedOn) !== 1) {
        return null;
    }

    // Ayni dokumanda kopya numarasi tekil.
    $dup = $pdo->prepare('SELECT COUNT(*) FROM document_copies WHERE document_id = ? AND copy_no = ? AND active = 1');
    $dup->execute([$documentId, $copyNo]);
    if ((int) $dup->fetchColumn() > 0) {
        return null;
    }

    $insert = $pdo->prepare(
        'INSERT INTO document_copies
            (company_id, document_id, copy_no, recipient_name, location, status, distributed_on, notes, active)
         VALUES (?,?,?,?,?,\'distributed\',?,?,1)'
    );
    $insert->execute([
        $companyId,
        $documentId,
        mb_substr($copyNo, 0, 40),
        mb_substr($recipient, 0, 180),
        trim((string) ($data['location'] ?? '')) !== '' ? mb_substr(trim((string) $data['location']), 0, 180) : null,
        $distributedOn,
        trim((string) ($data['notes'] ?? '')) !== '' ? mb_substr(trim((string) $data['notes']), 0, 4000) : null,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Bir kopyanin durumunu gunceller (iade / gecersiz). Iade tarihi iade edilince
 * yazilir, diger durumlarda temizlenir.
 */
function qmsDocumentCopyUpdateStatus(PDO $pdo, int $copyId, string $status, ?string $returnDate, int $userId, string $role): bool
{
    if (!in_array($status, QMS_COPY_STATUSES, true)) {
        return false;
    }
    $copy = qmsDocumentCopyFind($pdo, $copyId, $userId, $role);
    if ($copy === []) {
        return false;
    }

    $returnedOn = $status === 'returned' ? ($returnDate ?: date('Y-m-d')) : null;
    $stmt = $pdo->prepare('UPDATE document_copies SET status = ?, returned_on = ? WHERE id = ?');
    $stmt->execute([$status, $returnedOn, $copyId]);
    return true;
}

/**
 * Bir kontrollu kopyanin teslim alindigini / imzalandigini isaretler.
 * `received_confirmed=1`, `received_on`, `signed_by` yazilir.
 */
function qmsDocumentCopyConfirm(PDO $pdo, int $copyId, string $signedBy, int $userId, string $role): bool
{
    $copy = qmsDocumentCopyFind($pdo, $copyId, $userId, $role);
    if ($copy === []) {
        return false;
    }
    $signedBy = trim($signedBy) !== '' ? mb_substr(trim($signedBy), 0, 180) : null;
    $stmt = $pdo->prepare('UPDATE document_copies SET received_confirmed = 1, received_on = ?, signed_by = ? WHERE id = ?');
    $stmt->execute([date('Y-m-d'), $signedBy, $copyId]);
    return true;
}
