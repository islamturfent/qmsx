<?php

declare(strict_types=1);

/**
 * Duzeltici faaliyet (CAPA) modulu yardimcilari.
 *
 * Kapsamli kayit okuma, kanit dosyasi yollari, durum zaman damgalari ve bildirim
 * kurallari tek yerde tutulur; sayfalar kendi kopyalarini yazmaz. Kapsam tek
 * kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/notifications.php';

/** Faaliyet durum akisi. */
const QMS_CAPA_STATUSES = ['planned', 'in_progress', 'verification', 'completed', 'closed'];

/** Faaliyet turleri. */
const QMS_CAPA_TYPES = ['corrective', 'preventive'];

/** @return array<string, string> */
function qmsCapaTypeLabels(): array
{
    return [
        'corrective' => 'Düzeltici',
        'preventive' => 'Önleyici',
    ];
}

/** @return array<string, string> */
function qmsCapaTypeI18nKeys(): array
{
    return [
        'corrective' => 'capaTypeCorrectiveLabel',
        'preventive' => 'capaTypePreventiveLabel',
    ];
}

/** @return array<string, string> */
function qmsCapaStatusLabels(): array
{
    return [
        'planned' => 'Planlandı',
        'in_progress' => 'Çalışılıyor',
        'verification' => 'Doğrulama',
        'completed' => 'Tamamlandı',
        'closed' => 'Kapalı',
    ];
}

/** @return array<string, string> */
function qmsCapaStatusI18nKeys(): array
{
    return [
        'planned' => 'actionStatusPlannedLabel',
        'in_progress' => 'actionStatusInProgressLabel',
        'verification' => 'actionStatusVerificationLabel',
        'completed' => 'actionStatusCompletedLabel',
        'closed' => 'actionStatusClosedLabel',
    ];
}

/**
 * Kapsam icindeki tek duzeltici faaliyet.
 *
 * Kayit id ile tek basina okunmaz: kapsam cumlesi sorgunun parcasidir, boylece
 * id degistirilerek baska sirketin faaliyeti acilamaz.
 *
 * @return array<string, mixed> Bos dizi: kayit yok veya kapsam disi.
 */
function qmsCorrectiveActionFind(PDO $pdo, int $actionId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('nonconformities.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT corrective_actions.*, nonconformities.title AS nonconformity_title,
                nonconformities.company_id, companies.company_name
         FROM corrective_actions
         INNER JOIN nonconformities ON nonconformities.id = corrective_actions.nonconformity_id
         INNER JOIN companies ON companies.id = nonconformities.company_id
         WHERE corrective_actions.id = ? AND corrective_actions.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$actionId], $scope['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Kapsam icindeki tek uygunsuzluk (faaliyet olusturma ekrani kullanir).
 *
 * @return array<string, mixed>
 */
function qmsNonconformityFind(PDO $pdo, int $nonconformityId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('nonconformities.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT nonconformities.id, nonconformities.company_id, nonconformities.title,
                companies.company_name
         FROM nonconformities
         INNER JOIN companies ON companies.id = nonconformities.company_id
         WHERE nonconformities.id = ? AND nonconformities.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$nonconformityId], $scope['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Kapsam icindeki tek kanit dosyasi (indirme ucu kullanir).
 *
 * @return array<string, mixed>
 */
function qmsCapaEvidenceFind(PDO $pdo, int $evidenceId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('nonconformities.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT corrective_action_evidence.*, corrective_actions.nonconformity_id,
                nonconformities.company_id
         FROM corrective_action_evidence
         INNER JOIN corrective_actions ON corrective_actions.id = corrective_action_evidence.corrective_action_id
         INNER JOIN nonconformities ON nonconformities.id = corrective_actions.nonconformity_id
         WHERE corrective_action_evidence.id = ?
           AND corrective_action_evidence.active = 1
           AND corrective_actions.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$evidenceId], $scope['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Bir faaliyetin aktif kanit dosyalari.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsCapaEvidenceList(PDO $pdo, int $actionId): array
{
    $stmt = $pdo->prepare(
        'SELECT corrective_action_evidence.*, users.full_name AS uploaded_by_name
         FROM corrective_action_evidence
         LEFT JOIN users ON users.id = corrective_action_evidence.uploaded_by
         WHERE corrective_action_evidence.corrective_action_id = :action_id
           AND corrective_action_evidence.active = 1
         ORDER BY corrective_action_evidence.id DESC'
    );
    $stmt->execute(['action_id' => $actionId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kanit dosyasinin disk yolu.
 *
 * Dosya adi basename ile temizlenir: veritabani degeri bozulsa bile depo
 * klasorunun disina yazilamaz/okunamaz.
 */
function qmsCapaEvidencePath(string $storedFileName): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'storage'
        . DIRECTORY_SEPARATOR . 'evidence' . DIRECTORY_SEPARATOR . basename($storedFileName);
}

/**
 * Durum gecisinin zaman damgalari.
 *
 * Tamamlanma/kapanis tarihleri duruma baglidir ve ilk gecis tarihi korunur;
 * durum geri alinirsa temizlenir.
 *
 * @param array<string, mixed> $action Mevcut kayit (completed_at, closed_at).
 * @return array{completed_at: ?string, closed_at: ?string}
 */
function qmsCapaStatusTimestamps(array $action, string $status): array
{
    $completedAt = in_array($status, ['completed', 'closed'], true)
        ? (($action['completed_at'] ?? null) ?: date('Y-m-d H:i:s'))
        : null;
    $closedAt = $status === 'closed'
        ? (($action['closed_at'] ?? null) ?: date('Y-m-d H:i:s'))
        : null;

    return ['completed_at' => $completedAt, 'closed_at' => $closedAt];
}

/**
 * Durum ve sorumlu degisiminde gonderilen CAPA bildirimleri.
 *
 * Kurallar:
 *   - Faaliyet yeni bir kullaniciya atanirsa o kullaniciya "atandi" bildirimi.
 *   - Durum dogrulamaya gecerse sirketin atanmis sistem adminlerine istek gider.
 *   - Durum kapanirsa sorumlu kullaniciya kapanis bildirimi gider.
 * Kullanici kendi yaptigi islem icin bildirim almaz.
 *
 * @param array{
 *     company_id: int, action_text: string, link: string,
 *     previous_status: string, new_status: string,
 *     previous_responsible_user_id: int, responsible_user_id: int,
 *     actor_user_id: int
 * } $context
 */
function qmsCapaNotifyStatusChange(PDO $pdo, array $context): void
{
    $newResponsible = (int) ($context['responsible_user_id'] ?? 0);
    $previousResponsible = (int) ($context['previous_responsible_user_id'] ?? 0);
    $actor = (int) ($context['actor_user_id'] ?? 0);
    $previousStatus = (string) ($context['previous_status'] ?? '');
    $newStatus = (string) ($context['new_status'] ?? '');
    $actionText = (string) ($context['action_text'] ?? '');
    $link = (string) ($context['link'] ?? '');
    $companyId = (int) ($context['company_id'] ?? 0);

    if ($newResponsible > 0 && $newResponsible !== $previousResponsible && $newResponsible !== $actor) {
        qmsNotify($pdo, $newResponsible, 'corrective_action_assigned', 'Size bir düzeltici faaliyet atandı', $actionText, $link);
    }

    if ($newStatus === 'verification' && $previousStatus !== 'verification') {
        qmsNotifyCompanyAdmins($pdo, $companyId, 'corrective_action_verification', 'Doğrulama bekleyen düzeltici faaliyet', $actionText, $link, $actor);
    }

    if ($newStatus === 'closed' && $previousStatus !== 'closed' && $newResponsible > 0 && $newResponsible !== $actor) {
        qmsNotify($pdo, $newResponsible, 'corrective_action_closed', 'Düzeltici faaliyet kapandı', $actionText, $link);
    }
}
