<?php

declare(strict_types=1);

/**
 * Denetim kontrol listesi sabloni modulu yardimcilari.
 *
 * Sirkete bagli sablon kutuphanesi: denetciler standart kontrol maddelerini
 * sablondan bir denetime kopyalayabilir. Kapsam tek kaynaktan gelir
 * (includes/access.php).
 */

require_once __DIR__ . '/access.php';

/**
 * Kapsam icindeki sablonlar (en yeniden eskiye) + madde sayisi.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsChecklistTemplateList(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('templates.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT templates.id, templates.title, templates.description, templates.company_id,
                companies.company_name,
                (SELECT COUNT(*) FROM audit_checklist_template_items i
                  WHERE i.template_id = templates.id AND i.active = 1) AS item_count,
                templates.created_at
         FROM audit_checklist_templates templates
         INNER JOIN companies ON companies.id = templates.company_id
         WHERE templates.active = 1' . $scope['sql'] . '
         ORDER BY templates.id DESC'
    );
    $stmt->execute($scope['params']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kapsam icindeki tek sablon.
 *
 * @return array<string, mixed> Bos dizi: yok veya kapsam disi.
 */
function qmsChecklistTemplateFind(PDO $pdo, int $templateId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('templates.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT templates.*, companies.company_name
         FROM audit_checklist_templates templates
         INNER JOIN companies ON companies.id = templates.company_id
         WHERE templates.id = ? AND templates.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$templateId], $scope['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Bir sablonun aktif maddeleri (sirayla).
 *
 * @return array<int, array<string, mixed>>
 */
function qmsChecklistTemplateItems(PDO $pdo, int $templateId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, item_text, requirement_ref, sort_order
         FROM audit_checklist_template_items
         WHERE template_id = ? AND active = 1
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([$templateId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Bir sablonun maddelerini bir denetime kopyalar (denetim kontrol listesine).
 *
 * Sablon ve denetim kapsam icinde olmali ve ayni sirkete ait olmali; farkli
 * sirketin sablonu baska bir denetime uygulanamaz. Ayni metindeki maddeler
 * tekrarlanmaz (denetim icinde maddde tekstine gore tekil).
 *
 * @param array<string, mixed> $audit Denetim kaydi (id, company_id).
 * @return int Eklenen yeni madde sayisi.
 */
function qmsChecklistTemplateApply(PDO $pdo, int $templateId, array $audit, int $userId, string $role): int
{
    $template = qmsChecklistTemplateFind($pdo, $templateId, $userId, $role);
    $auditId = (int) ($audit['id'] ?? 0);
    $auditCompany = (int) ($audit['company_id'] ?? 0);

    if ($template === [] || $auditId <= 0 || (int) $template['company_id'] !== $auditCompany) {
        return 0;
    }

    $items = qmsChecklistTemplateItems($pdo, $templateId);

    $existing = $pdo->prepare(
        'SELECT item_text FROM audit_checklist_items WHERE audit_id = ? AND active = 1'
    );
    $existing->execute([$auditId]);
    $existingTexts = array_column($existing->fetchAll(PDO::FETCH_ASSOC), 'item_text');

    $insert = $pdo->prepare(
        'INSERT INTO audit_checklist_items (audit_id, item_text, requirement_ref, result_status, active)
         VALUES (?, ?, ?, \'pending\', 1)'
    );

    $applied = 0;
    foreach ($items as $item) {
        if (in_array($item['item_text'], $existingTexts, true)) {
            continue;
        }
        $insert->execute([
            $auditId,
            $item['item_text'],
            $item['requirement_ref'] !== '' ? $item['requirement_ref'] : null,
        ]);
        $existingTexts[] = $item['item_text'];
        $applied++;
    }

    return $applied;
}
