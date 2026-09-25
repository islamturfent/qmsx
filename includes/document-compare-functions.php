<?php

declare(strict_types=1);

/**
 * Dokuman versiyon karsilastirma yardimcilari.
 *
 * Salt-okunur yardimcilar; veri degisikligi yapmaz. Kapsam yonetimi cagiran
 * sayfada yapilir.
 */

/**
 * Depolanan revizyon dosyasinin govde HTML'ini ve duz metnini doner.
 *
 * @return array{html: string, text: string}
 */
function qmsCompareVersionBody(string $storedFileName): array
{
    $path = dirname(__DIR__) . '/storage/documents/' . basename($storedFileName);
    if (!is_file($path)) {
        return ['html' => '', 'text' => ''];
    }
    $content = (string) file_get_contents($path);
    $html = '';
    if (preg_match('~<body[^>]*>(.*?)</body>~is', $content, $m)) {
        $html = $m[1];
    }
    // Blok kapanis etiketlerinden sonra satir sonu ekle (metin yapisini korur),
    // sonra tum etiketleri kaldir ve bosluklari tek space'e indir.
    $lineBreaked = (string) preg_replace('~</(p|div|br|li|h[1-6]|tr|blockquote)>~i', "\n", $html);
    $stripped = trim(strip_tags($lineBreaked));
    $text = trim((string) preg_replace('~[ \t\xC2\xA0]+~u', ' ', (string) preg_replace('~\s+~u', ' ', $stripped)));
    return ['html' => $html, 'text' => $text];
}

/**
 * Basit satir tabanli LCS diff.
 *
 * @param string[] $a Eski metin satirlari
 * @param string[] $b Yeni metin satirlari
 * @return array<int, array{type: string, text: string}> 'same'|'add'|'del'
 */
function qmsDiffLines(array $a, array $b): array
{
    $n = count($a);
    $m = count($b);
    $MAX = 3000;
    if ($n > $MAX || $m > $MAX) {
        return []; // cok buyuk; metin karsilastirmasini atla
    }
    $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $dp[$i][$j] = ($a[$i] === $b[$j])
                ? $dp[$i + 1][$j + 1] + 1
                : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
        }
    }
    $out = [];
    $i = 0;
    $j = 0;
    while ($i < $n && $j < $m) {
        if ($a[$i] === $b[$j]) {
            $out[] = ['type' => 'same', 'text' => $a[$i]];
            $i++;
            $j++;
        } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
            $out[] = ['type' => 'del', 'text' => $a[$i]];
            $i++;
        } else {
            $out[] = ['type' => 'add', 'text' => $b[$j]];
            $j++;
        }
    }
    while ($i < $n) {
        $out[] = ['type' => 'del', 'text' => $a[$i]];
        $i++;
    }
    while ($j < $m) {
        $out[] = ['type' => 'add', 'text' => $b[$j]];
        $j++;
    }
    return $out;
}

/**
 * Govde HTML'ini duz metin satirlarina cevirir (karsilastirma icin).
 *
 * @return string[]
 */
function qmsCompareTextToLines(string $text): array
{
    if ($text === '') {
        return [];
    }
    return preg_split('~\n~', $text);
}
