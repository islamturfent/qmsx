<?php

declare(strict_types=1);

/**
 * Sikayet yonetimi modulu yardimcilari.
 *
 * Durum/onem/kaynak listeleri, alan dogrulama, kapsamli okuma, uygunsuzluk
 * baglama secenekleri ve bildirim kurallari tek yerde tutulur; sayfalar kendi
 * kopyalarini yazmaz. Kapsam tek kaynaktan gelir (includes/access.php).
 */

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/vocabulary.php';

/** Sikayet akisi: yeni -> incelemede -> aksiyon planlandi -> cozuldu -> kapandi. */
const QMS_COMPLAINT_STATUSES = ['new', 'in_review', 'action_planned', 'resolved', 'closed', 'rejected'];

/** Kapatilmamis sayilan durumlar (acik sikayet gostergesi). */
const QMS_COMPLAINT_OPEN_STATUSES = ['new', 'in_review', 'action_planned'];

/** Onem derecesi uygunsuzluklarla ayni sozlugu kullanir (includes/vocabulary.php). */
const QMS_COMPLAINT_SEVERITIES = QMS_SEVERITIES;

/** Sikayet kaynagi. */
const QMS_COMPLAINT_SOURCES = ['customer', 'employee', 'supplier', 'other'];

/** Bildirim kanali. */
const QMS_COMPLAINT_CHANNELS = ['email', 'phone', 'in_person', 'web', 'letter', 'other'];

/** @return array<string, string> */
function qmsComplaintStatusLabels(): array
{
    return [
        'new' => 'Yeni',
        'in_review' => 'İncelemede',
        'action_planned' => 'Aksiyon Planlandı',
        'resolved' => 'Çözüldü',
        'closed' => 'Kapatıldı',
        'rejected' => 'Reddedildi',
    ];
}

/** @return array<string, string> */
function qmsComplaintStatusI18nKeys(): array
{
    return [
        'new' => 'complaintStatusNewLabel',
        'in_review' => 'complaintStatusInReviewLabel',
        'action_planned' => 'complaintStatusActionPlannedLabel',
        'resolved' => 'complaintStatusResolvedLabel',
        'closed' => 'complaintStatusClosedLabel',
        'rejected' => 'complaintStatusRejectedLabel',
    ];
}

/**
 * Onem etiketleri paylasilan sozlukten gelir; sikayet ve uygunsuzluk ekranlari
 * ayni olcuyu farkli adlandirmaz.
 *
 * @return array<string, string>
 */
function qmsComplaintSeverityLabels(): array
{
    return qmsSeverityLabels();
}

/** @return array<string, string> */
function qmsComplaintSeverityI18nKeys(): array
{
    return qmsSeverityI18nKeys();
}

/** @return array<string, string> */
function qmsComplaintSourceLabels(): array
{
    return [
        'customer' => 'Müşteri',
        'employee' => 'Çalışan',
        'supplier' => 'Tedarikçi',
        'other' => 'Diğer',
    ];
}

/** @return array<string, string> */
function qmsComplaintSourceI18nKeys(): array
{
    return [
        'customer' => 'complaintSourceCustomerLabel',
        'employee' => 'complaintSourceEmployeeLabel',
        'supplier' => 'complaintSourceSupplierLabel',
        'other' => 'complaintSourceOtherLabel',
    ];
}

/** @return array<string, string> */
function qmsComplaintChannelLabels(): array
{
    return [
        'email' => 'E-posta',
        'phone' => 'Telefon',
        'in_person' => 'Yüz yüze',
        'web' => 'Web formu',
        'letter' => 'Yazılı',
        'other' => 'Diğer',
    ];
}

/** @return array<string, string> */
function qmsComplaintChannelI18nKeys(): array
{
    return [
        'email' => 'complaintChannelEmailLabel',
        'phone' => 'complaintChannelPhoneLabel',
        'in_person' => 'complaintChannelInPersonLabel',
        'web' => 'complaintChannelWebLabel',
        'letter' => 'complaintChannelLetterLabel',
        'other' => 'complaintChannelOtherLabel',
    ];
}

function qmsComplaintText(mixed $value, int $max): string
{
    return mb_substr(trim((string) $value), 0, $max);
}

/** Gecerli bir tarih (Y-m-d) degilse null. */
function qmsComplaintDate(mixed $value): ?string
{
    $value = trim((string) $value);

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
}

/** Durum acik mi (kapatilmamis mi)? */
function qmsComplaintIsOpen(string $status): bool
{
    return in_array($status, QMS_COMPLAINT_OPEN_STATUSES, true);
}

/**
 * Kapanis tarihi duruma baglidir: "kapandi" ise ilk kapanis tarihi korunur,
 * diger durumlarda temizlenir.
 *
 * @param array<string, mixed> $complaint Mevcut kayit (closed_date).
 */
function qmsComplaintClosedDate(array $complaint, string $status): ?string
{
    if ($status !== 'closed') {
        return null;
    }

    return ($complaint['closed_date'] ?? null) ?: date('Y-m-d');
}

/**
 * Kapsam icindeki tek sikayet kaydi.
 *
 * Kayit id ile tek basina okunmaz: kapsam cumlesi sorgunun parcasidir, boylece
 * id degistirilerek baska sirketin sikayeti acilamaz.
 *
 * @return array<string, mixed> Bos dizi: kayit yok veya kapsam disi.
 */
function qmsComplaintFind(PDO $pdo, int $complaintId, int $userId, string $role): array
{
    $scope = qmsCompanyScope('complaints.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT complaints.*, companies.company_name,
                nonconformities.title AS nonconformity_title,
                nonconformities.status AS nonconformity_status
         FROM complaints
         INNER JOIN companies ON companies.id = complaints.company_id
         LEFT JOIN nonconformities ON nonconformities.id = complaints.nonconformity_id
         WHERE complaints.id = ? AND complaints.active = 1' . $scope['sql'] . ' LIMIT 1'
    );
    $stmt->execute(array_merge([$complaintId], $scope['params']));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: [];
}

/**
 * Kapsam icindeki sikayet kayitlari (en yeniden eskiye).
 *
 * @return array<int, array<string, mixed>>
 */
function qmsComplaintList(PDO $pdo, int $userId, string $role): array
{
    $scope = qmsCompanyScope('complaints.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));

    $stmt = $pdo->prepare(
        'SELECT complaints.*, companies.company_name,
                (SELECT COUNT(*) FROM corrective_actions
                  WHERE corrective_actions.nonconformity_id = complaints.nonconformity_id
                    AND corrective_actions.active = 1) AS linked_actions
         FROM complaints
         INNER JOIN companies ON companies.id = complaints.company_id
         WHERE complaints.active = 1' . $scope['sql'] . '
         ORDER BY complaints.received_date DESC, complaints.id DESC'
    );
    $stmt->execute($scope['params']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Sikayetin baglanabilecegi uygunsuzluklar: ayni sirketin kayitlari.
 *
 * Uygunsuzluklar bir denetime bagli oldugu icin denetim basligi da getirilir.
 *
 * @return array<int, array<string, mixed>>
 */
function qmsComplaintNonconformityOptions(PDO $pdo, int $companyId): array
{
    if ($companyId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT nonconformities.id, nonconformities.title, nonconformities.status,
                nonconformities.severity, audits.title AS audit_title
         FROM nonconformities
         LEFT JOIN audits ON audits.id = nonconformities.audit_id
         WHERE nonconformities.company_id = :company_id AND nonconformities.active = 1
         ORDER BY nonconformities.id DESC'
    );
    $stmt->execute(['company_id' => $companyId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Sikayet durumu, onemi ve sorumlusu degistiginde gonderilen bildirimler.
 *
 * Kurallar:
 *   - Sikayet yeni bir kullaniciya atanirsa o kullanicaya "atandi" bildirimi.
 *   - Onem "kritik" olursa sirketin atanmis sistem adminlerine haber gider.
 *   - Sikayet kapanirsa sorumlu kullaniciya kapanis bildirimi gider.
 * Kullanici kendi yaptigi islem icin bildirim almaz.
 *
 * @param array{
 *     company_id: int, subject: string, link: string,
 *     previous_status: string, new_status: string,
 *     previous_severity: string, severity: string,
 *     previous_responsible_user_id: int, responsible_user_id: int,
 *     actor_user_id: int
 * } $context
 * @return int Bildirim yazilan kullanici sayisi.
 */
function qmsComplaintNotify(PDO $pdo, array $context): int
{
    $companyId = (int) ($context['company_id'] ?? 0);
    $subject = (string) ($context['subject'] ?? '');
    $link = (string) ($context['link'] ?? '');
    $previousStatus = (string) ($context['previous_status'] ?? '');
    $newStatus = (string) ($context['new_status'] ?? '');
    $previousSeverity = (string) ($context['previous_severity'] ?? '');
    $severity = (string) ($context['severity'] ?? '');
    $previousResponsible = (int) ($context['previous_responsible_user_id'] ?? 0);
    $responsible = (int) ($context['responsible_user_id'] ?? 0);
    $actor = (int) ($context['actor_user_id'] ?? 0);
    $written = 0;

    if ($responsible > 0 && $responsible !== $previousResponsible && $responsible !== $actor) {
        qmsNotify($pdo, $responsible, 'complaint_assigned', 'Size bir şikayet atandı', $subject, $link);
        $written++;
    }

    if ($severity === 'critical' && $previousSeverity !== 'critical') {
        $written += qmsNotifyCompanyAdmins(
            $pdo,
            $companyId,
            'complaint_critical',
            'Kritik şikayet kaydı',
            $subject,
            $link,
            $actor
        );
    }

    if ($newStatus === 'closed' && $previousStatus !== 'closed' && $responsible > 0 && $responsible !== $actor) {
        qmsNotify($pdo, $responsible, 'complaint_closed', 'Şikayet kapatıldı', $subject, $link);
        $written++;
    }

    return $written;
}
