<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Denetim programi otomasyonu / hatirlatmalar (cron / zamanlanmis gorev).
 *
 * Kullanim (CLI): php scripts/audit-program-reminders.php [--all]
 *
 * Kurallar (idempotent, okunmamis bildirimi olan (kullanici,tur,link) tekrar
 * uretilmez):
 *   - Aktif programin guncel yilinda hic denetim eklenmemisse sirket
 *     adminlerine "hatirlatma" (audit_program_reminder).
 *   - Gecmis yil programi hala draft/active ise sirket adminlerine "kapanis"
 *     hatirlatmasi (audit_program_due).
 * --all ile ilgili sirketin tum aktif kullanicilarina da gonderilir.
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/access.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

$allUsers = in_array('--all', $argv ?? [], true);
$year = (int) date('Y');
$stats = ['reminder' => 0, 'due' => 0];

$notify = static function (PDO $pdo, int $userId, string $type, string $message, string $link) use (&$stats): void {
    if ($userId <= 0) {
        return;
    }
    $exists = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND notification_type = ? AND link_url = ? AND is_read = 0');
    $exists->execute([$userId, $type, $link]);
    if ((int) $exists->fetchColumn() > 0) {
        return;
    }
    $key = $type === 'audit_program_due' ? 'due' : 'reminder';
    $stats[$key] = ($stats[$key] ?? 0) + 1;
    qmsNotify($pdo, $userId, $type, 'Denetim programı', $message, $link);
};

$notifyAdmins = static function (PDO $pdo, int $companyId, string $type, string $message, string $link) use ($notify): void {
    if ($companyId <= 0) {
        return;
    }
    $stmt = $pdo->prepare(
        'SELECT users.id FROM users INNER JOIN company_admin_assignments ON company_admin_assignments.admin_user_id = users.id
         WHERE company_admin_assignments.company_id = ? AND company_admin_assignments.active = 1 AND users.active = 1'
    );
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
        $notify($pdo, (int) $adminId, $type, $message, $link);
    }
};

$notifyCompanyAll = static function (PDO $pdo, int $companyId, string $type, string $message, string $link) use ($notify): void {
    if ($companyId <= 0) {
        return;
    }
    $stmt = $pdo->prepare("SELECT id FROM users WHERE active = 1 AND (company_id = ? OR role = 'super_admin')");
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        $notify($pdo, (int) $uid, $type, $message, $link);
    }
};

$stmt = $pdo->prepare(
    "SELECT ap.id, ap.title, ap.year, ap.status, ap.company_id, co.company_name
     FROM audit_programs ap INNER JOIN companies co ON co.id = ap.company_id
     WHERE ap.active = 1 AND ap.year <= :year
     ORDER BY ap.year DESC, ap.id DESC"
);
$stmt->execute(['year' => $year]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $link = 'audit-program-detail.php?id=' . (int) $p['id'];

    if ((int) $p['year'] < $year && in_array($p['status'], ['draft', 'active'], true)) {
        $msg = (string) $p['company_name'] . ' · ' . (string) $p['title'] . ' (' . (int) $p['year'] . ') henüz kapatılmadı. Program durumunu gözden geçirin.';
        $notifyAdmins($pdo, (int) $p['company_id'], 'audit_program_due', $msg, $link);
        if ($allUsers) {
            $notifyCompanyAll($pdo, (int) $p['company_id'], 'audit_program_due', $msg, $link);
        }
        continue;
    }

    if ((int) $p['year'] === $year && $p['status'] === 'active') {
        // Guncel yilda eklenmis denetim sayisi.
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_program_audits WHERE program_id = ?');
        $countStmt->execute([(int) $p['id']]);
        $auditCount = (int) $countStmt->fetchColumn();
        if ($auditCount === 0) {
            $msg = (string) $p['company_name'] . ' · ' . (string) $p['title'] . ' için bu yıl henüz denetim planlanmadı.';
            $notifyAdmins($pdo, (int) $p['company_id'], 'audit_program_reminder', $msg, $link);
            if ($allUsers) {
                $notifyCompanyAll($pdo, (int) $p['company_id'], 'audit_program_reminder', $msg, $link);
            }
        }
    }
}

echo 'Audit program reminders generated: ' . array_sum($stats) . PHP_EOL;
foreach ($stats as $k => $v) {
    echo '  ' . $k . ': ' . $v . PHP_EOL;
}
