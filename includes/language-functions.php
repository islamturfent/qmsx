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

/** Sistemde kullanilan tum ceviri anahtarlari (language.js TR + PHP data-i18n). */
function qmsTranslationKeys(): array
{
    $keys = [];
    $jsPath = __DIR__ . '/../assets/js/language.js';
    if (is_file($jsPath)) {
        $js = (string) file_get_contents($jsPath);
        // translations.tr.key = "..."
        if (preg_match_all('/translations\.tr\.(\w+)\s*=/', $js, $m)) {
            foreach ($m[1] as $k) { $keys[$k] = true; }
        }
        // Object.assign(translations.tr, { key: ... })
        if (preg_match_all('/Object\.assign\(translations\.tr,\s*\{(.*?)\n\s*\}\)/s', $js, $blk)) {
            foreach ($blk[1] as $b) {
                if (preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*:/', $b, $km)) {
                    foreach ($km[1] as $k) { if (strpos($k, 'http') === false) { $keys[$k] = true; } }
                }
            }
        }
    }
    // PHP sayfalarinda kullanilan data-i18n anahtarlari.
    $phpRoot = __DIR__ . '/../';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($phpRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') { continue; }
        $txt = (string) file_get_contents($f->getPathname());
        if (preg_match_all('/data-i18n=\"([A-Za-z_][A-Za-z0-9_]*)\"/', $txt, $m)) {
            foreach ($m[1] as $k) { $keys[$k] = true; }
        }
    }
    $out = array_keys($keys);
    sort($out, SORT_STRING);
    return $out;
}

/** Turkce referans deger (isimlendirme kolayligi icin). */
function qmsTranslationRef(): array
{
    static $ref = null;
    if ($ref !== null) { return $ref; }
    $ref = [];
    $jsPath = __DIR__ . '/../assets/js/language.js';
    if (is_file($jsPath)) {
        $js = (string) file_get_contents($jsPath);
        if (preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*:\s*"([^"]*)"/', $js, $m)) {
            foreach ($m[1] as $i => $k) { $ref[$k] = $m[2][$i]; }
        }
    }
    return $ref;
}
