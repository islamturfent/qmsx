<?php

declare(strict_types=1);

/**
 * Dil yonetimi yardimcilari. Diller ve ceviriler `languages` / `translations`
 * tablolarinda; tr/en cevirileri language.js'te sabittir, ek diller icin
 * DB'den ceviri alinir.
 */

require_once __DIR__ . '/access.php';

/** @return array<int, array{code:string,name:string,native_name:string,is_active:int,sort_order:int}> */
function qmsLanguages(PDO $pdo, bool $activeOnly = false): array
{
    $sql = 'SELECT code, name, native_name, is_active, sort_order FROM languages'
        . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order ASC, name ASC';
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<string,string> Belirtilen dilin DB cevirileri (key => value). */
function qmsLanguageTranslations(PDO $pdo, string $lang): array
{
    $stmt = $pdo->prepare('SELECT `key`, `value` FROM translations WHERE lang = ?');
    $stmt->execute([$lang]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['key']] = (string) $row['value'];
    }
    return $out;
}

/** Yeni dil ekler (zaten varsa gunceller). */
function qmsLanguageUpsert(PDO $pdo, string $code, string $name, string $nativeName): bool
{
    $code = strtolower(trim($code));
    $name = mb_substr(trim($name), 0, 80);
    $nativeName = mb_substr(trim($nativeName), 0, 80);
    if ($code === '' || !preg_match('/^[a-z]{2,8}(-[a-zA-Z0-9]{2,8})?$/', $code) || $name === '') {
        return false;
    }
    $max = $pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM languages')->fetchColumn();
    $stmt = $pdo->prepare(
        'INSERT INTO languages (code, name, native_name, is_active, sort_order)
         VALUES (?,?,?,1,?)
         ON DUPLICATE KEY UPDATE name=VALUES(name), native_name=VALUES(native_name)'
    );
    $stmt->execute([$code, $name, $nativeName !== '' ? $nativeName : $name, (int) $max]);
    return true;
}

/** Ceviri satirimini (lang,key) kaydeder. */
function qmsTranslationSave(PDO $pdo, string $lang, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO translations (lang, `key`, `value`) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)'
    );
    $stmt->execute([$lang, mb_substr(trim($key), 0, 120), trim($value)]);
}

/** Secili dilin anahtar seti (TR sabitinden kopyalanir, cikti ceviri olarak). */
function qmsLanguageEditorSource(PDO $pdo): array
{
    // Cevirilecek anahtar listesi: language.js'teki tr anahtarlarndan bir taslak.
    // Basit ve guvenli yontem: dil editoru, kullanicinin girdigi anahtarlara
    // guvenir; bu fonksiyon yalnizca ornek olarak birkac temel anahtari dondurur.
    return [
        'dashboardLinkLabel' => '',
        'sidebarOverviewLabel' => '',
        'loginTitle' => '',
        'loginText' => '',
        'saveButton' => '',
        'cancelButton' => '',
        'editButton' => '',
        'deleteButton' => '',
    ];
}
