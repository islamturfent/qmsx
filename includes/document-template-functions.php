<?php

declare(strict_types=1);

/**
 * Dokuman sablon kutuphanesi yardimcilari (sirket kapsamli).
 */

require_once __DIR__ . '/access.php';

const QMS_DOCUMENT_TEMPLATE_TYPES = ['procedure', 'policy', 'work_instruction', 'form', 'other'];

/** @return array<string, string> */
function qmsDocumentTemplateTypeLabels(): array
{
    return [
        'procedure' => 'Prosedür',
        'policy' => 'Politika',
        'work_instruction' => 'Çalışma Talimatı',
        'form' => 'Form',
        'other' => 'Diğer',
    ];
}

/** @return array<int, array<string, mixed>> Kapsam icindeki sablonlar. */
function qmsDocumentTemplateList(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('t.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare(
        'SELECT t.* FROM document_templates t WHERE t.active = 1' . $scope['sql'] . ' ORDER BY t.name ASC, t.id ASC'
    );
    $stmt->execute($scope['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<string, mixed> Kapsam icindeki tek sablon. */
function qmsDocumentTemplateFind(PDO $pdo, int $id, int $userId, string $role): array
{
    $scope = qmsCompanyScope('t.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT t.* FROM document_templates t WHERE t.id = ? AND t.active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** @return int|null Yeni sablon id'si; dogrulama basarisizsa null. */
function qmsDocumentTemplateAdd(PDO $pdo, array $data, int $userId, string $role): ?int
{
    $companyId = (int) ($data['company_id'] ?? 0);
    $scope = qmsCompanyScope('companies.id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('SELECT companies.id FROM companies WHERE companies.id = ? AND companies.active = 1' . $scope['sql'] . ' LIMIT 1');
    $stmt->execute(array_merge([$companyId], $scope['params']));
    if (!$stmt->fetchColumn()) {
        return null;
    }

    $name = trim((string) ($data['name'] ?? ''));
    $type = (string) ($data['document_type'] ?? 'procedure');
    if ($name === '' || !in_array($type, QMS_DOCUMENT_TEMPLATE_TYPES, true)) {
        return null;
    }

    $insert = $pdo->prepare(
        'INSERT INTO document_templates (company_id, name, document_type, category, content_html, active, created_by)
         VALUES (?,?,?,?,?,1,?)'
    );
    $insert->execute([
        $companyId,
        mb_substr($name, 0, 160),
        $type,
        trim((string) ($data['category'] ?? '')) !== '' ? mb_substr(trim((string) $data['category']), 0, 120) : null,
        trim((string) ($data['content_html'] ?? '')) !== '' ? (string) $data['content_html'] : null,
        $userId ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Sablonu gunceller (kapsam icinde olmali). */
function qmsDocumentTemplateUpdate(PDO $pdo, int $id, array $data, int $userId, string $role): bool
{
    if (!qmsDocumentTemplateFind($pdo, $id, $userId, $role)) {
        return false;
    }
    $name = trim((string) ($data['name'] ?? ''));
    $type = (string) ($data['document_type'] ?? 'procedure');
    if ($name === '' || !in_array($type, QMS_DOCUMENT_TEMPLATE_TYPES, true)) {
        return false;
    }
    $pdo->prepare('UPDATE document_templates SET name=?, document_type=?, category=?, content_html=? WHERE id=?')
        ->execute([
            mb_substr($name, 0, 160),
            $type,
            trim((string) ($data['category'] ?? '')) !== '' ? mb_substr(trim((string) $data['category']), 0, 120) : null,
            trim((string) ($data['content_html'] ?? '')) !== '' ? (string) $data['content_html'] : null,
            $id,
        ]);
    return true;
}

/** Sablonu siler (aktif=0), kapsam icinde olmali. */
function qmsDocumentTemplateDelete(PDO $pdo, int $id, int $userId, string $role): bool
{
    $scope = qmsCompanyScope('company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
    $stmt = $pdo->prepare('UPDATE document_templates SET active = 0 WHERE id = ?' . $scope['sql']);
    $stmt->execute(array_merge([$id], $scope['params']));
    return $stmt->rowCount() > 0;
}
