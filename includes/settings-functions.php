<?php

declare(strict_types=1);

/**
 * Genel sistem ayarlari (key/value). Varsayilanlar asagida; adminin degistirdigi
 * degerler `system_settings` tablosunda saklanir. `qmsSettings()` varsayilan +
 * DB override birlesimini doner. Uygulama kodu `qmsSetting('key')` ile okur.
 */

require_once __DIR__ . '/access.php';

/** Varsayilan sistem ayarlari. */
const QMS_SETTINGS_DEFAULTS = [
    // A) Genel
    'session_timeout_min' => '60',        // oturum zaman asimi (dakika)
    'default_theme' => 'light',           // light | dark
    'default_lang' => 'tr',               // tr | en
    'page_size' => '25',                  // listelerde varsayilan sayfa limiti
    'upload_max_mb' => '20',              // dosya yukleme limiti (MB)
    // A) / D) teslimat eşiği ve denetim izi
    'delivery_reject_threshold' => '0.05',// teslimat red esigi (0-1)
    'audit_retention_days' => '365',      // denetim izi retansiyon (gun)
    // C) Raporlama gorunum
    'report_company_name' => '',          // Excel/PDF rapor ustbilgisi
    'report_footer' => '',                // Excel/PDF rapor altbilgi notu
    'report_confidential' => '0',         // '1' = gizlilik notu ekle
    // D) E-posta / bildirim - gonderici ad/adres E-posta Ayarlari'nda (mail-settings.php)
    // E) Guvenlik
    'password_min_length' => '8',         // sifre min. uzunluk
    'twofa_required' => '0',              // '1' = 2FA gerekli (kademeli, not)
    // F) Yaklasan vade pencereleri (gun)
    'contract_expiring_days' => '60',     // sozlesme bitis uyarı penceresi
    'instrument_due_days' => '60',        // olcu alett/kalibrasyon yaklasan
    'competency_due_days' => '30',        // yetkinlik vade yaklasan
    'document_review_days' => '30',       // dokuman gozden gecirme penceresi
    'process_review_days' => '30',        // surec gozden gecirme penceresi
    // G) Login guvenligi
    'max_login_attempts' => '5',          // hatali giris kilidi esigi
    'lockout_minutes' => '15',            // kilit suresi (dk)
    // I) Marka / gorunum
    'app_name' => 'QuAmi',
    'login_title' => 'Kalite Yönetim Sistemi',
    // K) Rapor markası (kaydedilmis logo dosya yolu)
    'report_logo' => '',
    // L) Yeni hesap varsayilanlari
    'default_user_lang' => 'tr',
    'default_user_theme' => 'light',
    'default_notifications_enabled' => '1',
    // N) E-posta sablon metni
    'mail_subject_prefix' => '',
    'mail_signature' => '',
    // O) Otomatik bakim / yedek
    'auto_backup_enabled' => '0',
    'auto_backup_interval_hours' => '24',
    'auto_backup_retain' => '7',
    // J) Guvenlik uygulamasi
    'maintenance_mode' => '0',
    'maintenance_message' => 'Sistem bakımdadır. Lütfen daha sonra tekrar deneyiniz.',
    'login_ip_allow' => '',
];

/** @return array<string, string> Birlesmis sistem ayarlari (default + DB override). */
function qmsSettings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $out = QMS_SETTINGS_DEFAULTS;
    try {
        $pdo = qmsSettingsPdo();
        if ($pdo) {
            $stmt = $pdo->query('SELECT `key`, `value` FROM system_settings');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (array_key_exists($row['key'], $out)) {
                    $out[$row['key']] = (string) $row['value'];
                }
            }
        }
    } catch (Throwable $e) {
        // Tablo yoksa (henuz migrate edilmemisse) varsayilanlara don.
    }
    $cache = $out;
    return $out;
}

/** @return string Tek ayarin (override'li) degeri; yoksa varsayilan. */
function qmsSetting(string $key, ?string $default = null): string
{
    $s = qmsSettings();
    if (array_key_exists($key, $s)) {
        return (string) $s[$key];
    }
    return (string) ($default ?? '');
}

/**
 * Yalnizca super/system admin icin: bir ayari kaydeder. $allowedKeys yalnizca
 * bilinen anahtarlari kabul eder (tip guvenligi).
 *
 * @param array<string, string> $values
 * @return array<int,string> Hatali (taninmayan) anahtar listesi.
 */
function qmsSettingsSave(PDO $pdo, array $values, int $userId): array
{
    $valid = array_fill_keys(array_keys(QMS_SETTINGS_DEFAULTS), true);
    $bad = [];
    $upsert = $pdo->prepare(
        'INSERT INTO system_settings (`key`, `value`, updated_by)
         VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_by = VALUES(updated_by)'
    );
    foreach ($values as $k => $v) {
        $key = trim((string) $k);
        if (!isset($valid[$key])) {
            $bad[] = $key;
            continue;
        }
        $upsert->execute([$key, trim((string) $v), $userId ?: null]);
    }
    // Kayittan sonra redirect ile sayfa yeniden yuklenir; per-request cache gecersiz olur.
    return $bad;
}

/** Ayarlar tablosuna baglanacak PDO (baska yerde $pdo yoksa). */
function qmsSettingsPdo(): ?\PDO
{
    global $pdo;
    if (isset($pdo) && $pdo instanceof \PDO) {
        return $pdo;
    }
    return null;
}
