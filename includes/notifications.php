<?php

declare(strict_types=1);

/**
 * Bildirim yardimcilari.
 *
 * Bildirim kayitlari tek bicimde yazilsin diye burada toplanir; sayfalar
 * dogrudan INSERT cumlesi yazmaz.
 */

/**
 * Tek bir kullaniciya bildirim yazar. Gecersiz kullanici id'si sessizce atlanir.
 */
function qmsNotify(PDO $pdo, int $userId, string $type, string $title, string $message, ?string $linkUrl = null): void
{
    if ($userId <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, notification_type, title, message, link_url)
         VALUES (:user_id, :type, :title, :message, :link_url)"
    );
    $stmt->execute([
        'user_id' => $userId,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'link_url' => $linkUrl
    ]);
}

/**
 * Bildirim turleri: ikon ve grup.
 *
 * Arayuz ikonu ve grup etiketini bu haritadan alir; bildirim merkezi tur
 * basina ayri kod yazmaz. Tur eklerken yalnizca bu harita guncellenir.
 *
 * @return array<string, array{icon: string, group: string}>
 */
function qmsNotificationTypes(): array
{
    return [
        'corrective_action_assigned' => ['icon' => 'check', 'group' => 'capa'],
        'corrective_action_verification' => ['icon' => 'approvals', 'group' => 'capa'],
        'corrective_action_closed' => ['icon' => 'checkBadge', 'group' => 'capa'],
        'document_approval_request' => ['icon' => 'approvals', 'group' => 'document'],
        'document_approval_decision' => ['icon' => 'documents', 'group' => 'document'],
        'document_published' => ['icon' => 'documents', 'group' => 'document'],
        'training_planned' => ['icon' => 'training', 'group' => 'training'],
        'training_assigned' => ['icon' => 'training', 'group' => 'training'],
        'training_completed' => ['icon' => 'checkBadge', 'group' => 'training'],
        'supplier_approved' => ['icon' => 'suppliers', 'group' => 'supplier'],
        'supplier_suspended' => ['icon' => 'warning', 'group' => 'supplier'],
        'supplier_evaluation_unacceptable' => ['icon' => 'warning', 'group' => 'supplier'],
        'complaint_assigned' => ['icon' => 'complaints', 'group' => 'complaint'],
        'complaint_critical' => ['icon' => 'warning', 'group' => 'complaint'],
        'complaint_closed' => ['icon' => 'checkBadge', 'group' => 'complaint'],
        'review_completed' => ['icon' => 'reviews', 'group' => 'review'],
        'calibration_failed' => ['icon' => 'warning', 'group' => 'calibration'],
        'overdue_action' => ['icon' => 'alert', 'group' => 'capa'],
        'overdue_nonconformity' => ['icon' => 'alert', 'group' => 'capa'],
        'overdue_training' => ['icon' => 'training', 'group' => 'training'],
        'overdue_calibration' => ['icon' => 'warning', 'group' => 'calibration'],
        'overdue_finding' => ['icon' => 'alert', 'group' => 'capa'],
        'overdue_document_review' => ['icon' => 'documents', 'group' => 'document'],
        'overdue_complaint' => ['icon' => 'complaints', 'group' => 'complaint'],
    ];
}

/**
 * Tek bir bildirimin ikonu ve grubu. Bilinmeyen tur genel gruba duser, boylece
 * eski kayitlar da ikonsuz kalmaz.
 *
 * @return array{icon: string, group: string}
 */
function qmsNotificationMeta(string $type): array
{
    return qmsNotificationTypes()[$type] ?? ['icon' => 'notifications', 'group' => 'general'];
}

/** @return array<string, string> */
function qmsNotificationGroupLabels(): array
{
    return [
        'capa' => 'Düzeltici Faaliyet',
        'document' => 'Doküman',
        'training' => 'Eğitim',
        'supplier' => 'Tedarikçi',
        'complaint' => 'Şikayet',
        'review' => 'Gözden Geçirme',
        'calibration' => 'Kalibrasyon',
        'general' => 'Genel',
    ];
}

/** @return array<string, string> */
function qmsNotificationGroupI18nKeys(): array
{
    return [
        'capa' => 'notificationGroupCapaLabel',
        'document' => 'notificationGroupDocumentLabel',
        'training' => 'notificationGroupTrainingLabel',
        'supplier' => 'notificationGroupSupplierLabel',
        'complaint' => 'notificationGroupComplaintLabel',
        'review' => 'notificationGroupReviewLabel',
        'calibration' => 'notificationGroupCalibrationLabel',
        'general' => 'notificationGroupGeneralLabel',
    ];
}

/**
 * Sirkete atanmis sistem adminlerine bildirim yazar.
 *
 * Super adminler bilincli olarak disarida birakilir: her sirketin her kaydi
 * icin bilgilendirilmeleri gurultu olur.
 *
 * @param int $exceptUserId Kendisine bildirim gitmeyecek kullanici (islem yapan).
 * @return int Bildirim yazilan kullanici sayisi.
 */
function qmsNotifyCompanyAdmins(
    PDO $pdo,
    int $companyId,
    string $type,
    string $title,
    string $message,
    ?string $linkUrl = null,
    int $exceptUserId = 0
): int {
    if ($companyId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT users.id
         FROM users
         INNER JOIN company_admin_assignments ON company_admin_assignments.admin_user_id = users.id
         WHERE company_admin_assignments.company_id = :company_id
           AND company_admin_assignments.active = 1
           AND users.active = 1
           AND users.id <> :except_user_id"
    );
    $stmt->execute(['company_id' => $companyId, 'except_user_id' => $exceptUserId]);

    $count = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
        qmsNotify($pdo, (int) $adminId, $type, $title, $message, $linkUrl);
        $count++;
    }

    return $count;
}

/**
 * Bir sirkette sorumlu olabilecek kullanicilar: sirketin kendi kullanicilari ve
 * o sirkete atanmis sistem adminleri.
 *
 * @return array<int, array{id: int, full_name: string, role: string}>
 */
function qmsCompanyResponsibleOptions(PDO $pdo, int $companyId): array
{
    if ($companyId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT DISTINCT users.id, users.full_name, users.role
         FROM users
         LEFT JOIN company_admin_assignments ON company_admin_assignments.admin_user_id = users.id
         WHERE users.active = 1
           AND (
                (users.role = 'company_user' AND users.company_id = :company_id)
                OR (users.role = 'system_admin' AND company_admin_assignments.company_id = :company_id
                    AND company_admin_assignments.active = 1)
           )
         ORDER BY users.full_name"
    );
    $stmt->execute(['company_id' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
