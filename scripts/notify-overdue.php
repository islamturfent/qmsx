<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Geciken kayitlar icin otomatik bildirim uretici (cron / zamanlanmis gorev).
 *
 * Kullanim (CLI): php scripts/notify-overdue.php
 * Windows Task Scheduler icin: php -f "C:\xampp\htdocs\qmsx\scripts\notify-overdue.php"
 *
 * Tum sirketlerin geciken kayitlarini tarar ve sorumlu kullaniciya / sirket
 * sistem adminlerine "overdue_*" bildirimleri uretir. Ayni (kullanici, tur,
 * link) icin okunmamis bildirim varsa tekrar uretmez (idempotent).
 *
 * Bu script veri degistirmez; yalnizca notifications tablosuna ekler.
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

// --all: her geciken kayit icin ilgili sirketin TUM aktif kullanicilarina
// (sorumlu ve adminler dahil) da bildirim bas; varsayilan mod yalnizca sorumlu
// kullanici ve/veya sirket adminleridir.
$allUsers = in_array('--all', $argv ?? [], true);

$today = date('Y-m-d');
$stats = [
    'overdue_action' => 0,
    'overdue_nonconformity' => 0,
    'overdue_training' => 0,
    'overdue_calibration' => 0,
    'overdue_finding' => 0,
    'overdue_document_review' => 0,
    'overdue_complaint' => 0,
    'overdue_supplier_eval' => 0,
    'contract_expiring' => 0,
    'contract_renewal_due' => 0,
    'process_review_overdue' => 0,
    'instrument_calibration_overdue' => 0,
    'delivery_rejection' => 0,
];

/** Belirtilen kullaniciya (tur+link) dedupli bildirim ekler. */
$notify = static function (PDO $pdo, int $userId, string $type, string $message, string $link) use (&$stats): void {
    if ($userId <= 0) {
        return;
    }
    $exists = $pdo->prepare(
        'SELECT COUNT(*) FROM notifications
         WHERE user_id = ? AND notification_type = ? AND link_url = ? AND is_read = 0'
    );
    $exists->execute([$userId, $type, $link]);
    if ((int) $exists->fetchColumn() > 0) {
        return;
    }
    qmsNotify($pdo, $userId, $type, 'Geciken kayıt', $message, $link);
    $stats[$type] = ($stats[$type] ?? 0) + 1;
};

/** Sirket sistem adminlerine (kullanici basina) dedupli bildirim ekler. */
$notifyAdmins = static function (PDO $pdo, int $companyId, string $type, string $message, string $link) use ($notify): void {
    if ($companyId <= 0) {
        return;
    }
    $stmt = $pdo->prepare(
        'SELECT users.id FROM users
         INNER JOIN company_admin_assignments ON company_admin_assignments.admin_user_id = users.id
         WHERE company_admin_assignments.company_id = ? AND company_admin_assignments.active = 1 AND users.active = 1'
    );
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
        $notify($pdo, (int) $adminId, $type, $message, $link);
    }
};

/** Sirketin tum aktif kullanicilarina (sorumlu + adminler dahil) dedupli bildirim ekler. */
$notifyCompanyAll = static function (PDO $pdo, int $companyId, string $type, string $message, string $link) use ($notify): void {
    if ($companyId <= 0) {
        return;
    }
    $stmt = $pdo->prepare(
        "SELECT id FROM users WHERE active = 1 AND (company_id = ? OR role = 'super_admin')"
    );
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        $notify($pdo, (int) $uid, $type, $message, $link);
    }
};

// --- Geciken duzeltici faaliyet (sorumlu kullaniciya) ---
$stmt = $pdo->prepare(
    "SELECT ca.id, ca.action_text, ca.due_date, ca.responsible_user_id, n.company_id, co.company_name
     FROM corrective_actions ca
     INNER JOIN nonconformities n ON n.id = ca.nonconformity_id
     INNER JOIN companies co ON co.id = n.company_id
     WHERE ca.active = 1 AND ca.due_date IS NOT NULL AND ca.due_date < ?
       AND ca.status NOT IN ('completed','closed')"
);
$stmt->execute([$today]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $link = 'corrective-action-detail.php?id=' . (int) $r['id'];
    $msg = (string) $r['company_name'] . ' · ' . (string) $r['action_text'] . ' (termin: ' . (string) $r['due_date'] . ')';
    $notifyAdminFallback = true;
    if ((int) $r['responsible_user_id'] > 0) {
        $notify($pdo, (int) $r['responsible_user_id'], 'overdue_action', $msg, $link);
        $notifyAdminFallback = false;
    }
    if ($notifyAdminFallback) {
        $notifyAdmins($pdo, (int) $r['company_id'], 'overdue_action', $msg, $link);
    }
    if ($allUsers) {
        $notifyCompanyAll($pdo, (int) $r['company_id'], 'overdue_action', $msg, $link);
    }
}

// --- Geciken uygunsuzluk (sirket adminlerine) ---
$stmt = $pdo->prepare(
    "SELECT nonconformities.id, nonconformities.title, nonconformities.company_id, nonconformities.due_date, companies.company_name
     FROM nonconformities INNER JOIN companies ON companies.id = nonconformities.company_id
     WHERE nonconformities.active = 1 AND nonconformities.due_date IS NOT NULL
       AND nonconformities.due_date < ? AND nonconformities.status NOT IN ('closed')"
);
$stmt->execute([$today]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $link = 'nonconformity-detail.php?id=' . (int) $r['id'];
    $msg = (string) $r['company_name'] . ' · ' . (string) $r['title'] . ' (termin: ' . (string) $r['due_date'] . ')';
    $notifyAdmins($pdo, (int) $r['company_id'], 'overdue_nonconformity', $msg, $link);
    if ($allUsers) {
        $notifyCompanyAll($pdo, (int) $r['company_id'], 'overdue_nonconformity', $msg, $link);
    }
}

// --- Geciken egitim (sirket adminlerine) ---
$stmt = $pdo->prepare(
    "SELECT trainings.id, trainings.title, trainings.company_id, trainings.planned_date, companies.company_name
     FROM trainings INNER JOIN companies ON companies.id = trainings.company_id
     WHERE trainings.active = 1 AND trainings.planned_date IS NOT NULL
       AND trainings.planned_date < ? AND trainings.status NOT IN ('completed','cancelled')"
);
$stmt->execute([$today]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $link = 'trainings.php';
    $msg = (string) $r['company_name'] . ' · ' . (string) $r['title'] . ' (planlanan: ' . (string) $r['planned_date'] . ')';
    $notifyAdmins($pdo, (int) $r['company_id'], 'overdue_training', $msg, $link);
    if ($allUsers) {
        $notifyCompanyAll($pdo, (int) $r['company_id'], 'overdue_training', $msg, $link);
    }
}

// --- Kalibrasyonu secik ekipman (sorumlu kullaniciya, yoksa adminlere) ---
$stmt = $pdo->prepare(
    "SELECT equipment.id, equipment.name, equipment.responsible_user_id, equipment.company_id,
            equipment.next_calibration_date, companies.company_name
     FROM equipment INNER JOIN companies ON companies.id = equipment.company_id
     WHERE equipment.active = 1 AND equipment.next_calibration_date IS NOT NULL
       AND equipment.next_calibration_date < ? AND equipment.status NOT IN ('out_of_service')"
);
$stmt->execute([$today]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $link = 'equipment.php';
    $msg = (string) $r['company_name'] . ' · ' . (string) $r['name'] . ' (kalibrasyon: ' . (string) $r['next_calibration_date'] . ')';
    if ((int) $r['responsible_user_id'] > 0) {
        $notify($pdo, (int) $r['responsible_user_id'], 'overdue_calibration', $msg, $link);
    } else {
        $notifyAdmins($pdo, (int) $r['company_id'], 'overdue_calibration', $msg, $link);
    }
    if ($allUsers) {
        $notifyCompanyAll($pdo, (int) $r['company_id'], 'overdue_calibration', $msg, $link);
    }
}

// --- Acik dis denetim bulgusu (sirket adminlerine) ---
$stmt = $pdo->prepare(
    "SELECT eaf.id, eaf.finding_text, eaf.due_date, ea.company_id, companies.company_name
     FROM external_audit_findings eaf
     INNER JOIN external_audits ea ON ea.id = eaf.external_audit_id
     INNER JOIN companies ON companies.id = ea.company_id
     WHERE eaf.active = 1 AND eaf.due_date IS NOT NULL AND eaf.due_date < ? AND eaf.status NOT IN ('closed')"
);
$stmt->execute([$today]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $link = 'external-audits.php';
    $msg = (string) $r['company_name'] . ' · ' . (string) $r['finding_text'] . ' (termin: ' . (string) $r['due_date'] . ')';
    $notifyAdmins($pdo, (int) $r['company_id'], 'overdue_finding', $msg, $link);
    if ($allUsers) {
        $notifyCompanyAll($pdo, (int) $r['company_id'], 'overdue_finding', $msg, $link);
    }
}

// --- Gozden gecirilecek dokuman (sirket adminlerine) ---
$stmt = $pdo->prepare(
    "SELECT documents.id, documents.title, documents.document_code, documents.company_id, documents.review_date, companies.company_name
     FROM documents INNER JOIN companies ON companies.id = documents.company_id
     WHERE documents.active = 1 AND documents.review_date IS NOT NULL
       AND documents.review_date < ? AND documents.status NOT IN ('archived')"
);
$stmt->execute([$today]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $link = 'document-detail.php?id=' . (int) $r['id'];
    $msg = (string) $r['company_name'] . ' · ' . (string) $r['document_code'] . ' ' . (string) $r['title'] . ' (gözden geçirme: ' . (string) $r['review_date'] . ')';
    $notifyAdmins($pdo, (int) $r['company_id'], 'overdue_document_review', $msg, $link);
    if ($allUsers) {
        $notifyCompanyAll($pdo, (int) $r['company_id'], 'overdue_document_review', $msg, $link);
    }
}

// --- Termini gecen sikayet (sorumlu kullaniciya, yoksa adminlere) ---
$stmt = $pdo->prepare(
    "SELECT complaints.id, complaints.subject, complaints.responsible_user_id, complaints.company_id,
            complaints.due_date, companies.company_name
     FROM complaints INNER JOIN companies ON companies.id = complaints.company_id
     WHERE complaints.active = 1 AND complaints.due_date IS NOT NULL
       AND complaints.due_date < ? AND complaints.status NOT IN ('closed','rejected')"
);
$stmt->execute([$today]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $link = 'complaint-detail.php?id=' . (int) $r['id'];
    $msg = (string) $r['company_name'] . ' · ' . (string) $r['subject'] . ' (termin: ' . (string) $r['due_date'] . ')';
    if ((int) $r['responsible_user_id'] > 0) {
        $notify($pdo, (int) $r['responsible_user_id'], 'overdue_complaint', $msg, $link);
    } else {
        $notifyAdmins($pdo, (int) $r['company_id'], 'overdue_complaint', $msg, $link);
    }
    if ($allUsers) {
        $notifyCompanyAll($pdo, (int) $r['company_id'], 'overdue_complaint', $msg, $link);
    }
}

// --- Vadesi gecen tedarikci degerlendirme (sirket adminlerine) ---
$stmt = $pdo->prepare(
    "SELECT s.id, s.cycle_label, s.due_date, s.company_id, sp.name AS supplier_name
     FROM supplier_evaluation_schedule s
     INNER JOIN suppliers sp ON sp.id = s.supplier_id
     WHERE s.active = 1 AND s.status = 'planned' AND s.due_date IS NOT NULL AND s.due_date < ?"
);
$stmt->execute([$today]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $link = 'supplier-evaluations.php';
    $msg = (string) $r['supplier_name'] . ' · ' . (string) $r['cycle_label'] . ' (termin: ' . (string) $r['due_date'] . ')';
    $notifyAdmins($pdo, (int) $r['company_id'], 'overdue_supplier_eval', $msg, $link);
    if ($allUsers) {
        $notifyCompanyAll($pdo, (int) $r['company_id'], 'overdue_supplier_eval', $msg, $link);
    }
}

// --- Suresi yaklasan aktif sozlesmeler (sirket adminlerine) ---
$stmt = $pdo->prepare(
    "SELECT c.id, c.contract_name, c.end_date, c.company_id
     FROM contracts c
     WHERE c.active = 1 AND c.status = 'active' AND c.end_date IS NOT NULL
       AND c.end_date BETWEEN ? AND DATE_ADD(?, INTERVAL 60 DAY)"
);
$stmt->execute([$today, $today]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $link = 'contracts.php';
    $msg = (string) $r['contract_name'] . ' (bitiş: ' . (string) $r['end_date'] . ')';
    $notifyAdmins($pdo, (int) $r['company_id'], 'contract_expiring', $msg, $link);
    if ($allUsers) {
        $notifyCompanyAll($pdo, (int) $r['company_id'], 'contract_expiring', $msg, $link);
    }
}

// --- Yenileme tarihi yaklasan sozlesmeler (sirket adminlerine) ---
$stmt = $pdo->prepare(
    "SELECT c.id, c.contract_name, c.renewal_date, c.company_id
     FROM contracts c
     WHERE c.active = 1 AND c.status = 'active' AND c.renewal_date IS NOT NULL
       AND c.renewal_date BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY)"
);
$stmt->execute([$today, $today]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $link = 'contracts.php';
    $msg = (string) $r['contract_name'] . ' (yenileme: ' . (string) $r['renewal_date'] . ')';
    $notifyAdmins($pdo, (int) $r['company_id'], 'contract_renewal_due', $msg, $link);
    if ($allUsers) {
        $notifyCompanyAll($pdo, (int) $r['company_id'], 'contract_renewal_due', $msg, $link);
    }
}

// --- Gözden gecirilmesi gecen prosesler (sirket adminlerine) ---
$stmt = $pdo->prepare(
    "SELECT p.id, p.process_name, p.review_date, p.company_id
     FROM processes p
     WHERE p.active = 1 AND p.review_date IS NOT NULL AND p.review_date < ?"
);
$stmt->execute([$today]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $link = 'processes.php';
    $msg = (string) $r['process_name'] . ' (gözden geçirme: ' . (string) $r['review_date'] . ')';
    $notifyAdmins($pdo, (int) $r['company_id'], 'process_review_overdue', $msg, $link);
    if ($allUsers) {
        $notifyCompanyAll($pdo, (int) $r['company_id'], 'process_review_overdue', $msg, $link);
    }
}

// --- Kalibrasyonu gecen olcu aletleri (sirket adminlerine) ---
$stmt = $pdo->prepare(
    "SELECT i.id, i.name, i.next_calibration_date, i.company_id
     FROM instruments i
     WHERE i.active = 1 AND i.status = 'active' AND i.next_calibration_date IS NOT NULL AND i.next_calibration_date < ?"
);
$stmt->execute([$today]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $link = 'instruments.php';
    $msg = (string) $r['name'] . ' (kalibrasyon: ' . (string) $r['next_calibration_date'] . ')';
    $notifyAdmins($pdo, (int) $r['company_id'], 'instrument_calibration_overdue', $msg, $link);
    if ($allUsers) {
        $notifyCompanyAll($pdo, (int) $r['company_id'], 'instrument_calibration_overdue', $msg, $link);
    }
}

// --- Teslimat red esigi asilan kayitlar (sirket adminlerine) ---
// Varsayilan esik %5'tir; QMS_DELIVERY_REJECT_THRESHOLD ortam degiskeni ile
// ayarlanabilir (0-1 arasi). Teste izin vermek icin ayrica --threshold=<0-1>
// argumani da kabul edilir (arguman ortam degerinden onceliklidir).
$deliveryRejectThreshold = 0.05;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--threshold=([0-9]+(?:\.[0-9]+)?)$/', $arg, $m)) {
        $deliveryRejectThreshold = max(0.01, min(0.99, (float) $m[1]));
        break;
    }
}
if ($deliveryRejectThreshold > 0) {
    $stmt = $pdo->prepare(
        "SELECT d.id, d.customer_name, d.period, d.quantity_delivered, d.quantity_rejected, d.company_id, co.company_name
         FROM delivery_performance d INNER JOIN companies co ON co.id = d.company_id
         WHERE d.active = 1 AND d.quantity_delivered > 0"
    );
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qtyDelivered = (int) $r['quantity_delivered'];
        $qtyRejected = (int) $r['quantity_rejected'];
        if ($qtyDelivered <= 0) {
            continue;
        }
        $rejectRate = $qtyRejected / $qtyDelivered;
        if ($rejectRate < $deliveryRejectThreshold) {
            continue;
        }
        $link = 'delivery-performance.php?period=' . urlencode((string) $r['period']);
        $msg = (string) $r['company_name'] . ' · ' . (string) $r['customer_name'] . ' (' . (string) $r['period'] . ') red oranı %'
            . round($rejectRate * 100, 1) . ' (eşik %' . round($deliveryRejectThreshold * 100, 1) . ')';
        $notifyAdmins($pdo, (int) $r['company_id'], 'delivery_rejection', $msg, $link);
        if ($allUsers) {
            $notifyCompanyAll($pdo, (int) $r['company_id'], 'delivery_rejection', $msg, $link);
        }
    }
}

$total = array_sum($stats);
echo 'Overdue notifications generated: ' . $total . PHP_EOL;
foreach ($stats as $k => $v) {
    if ($v > 0) {
        echo '  ' . $k . ': ' . $v . PHP_EOL;
    }
}
