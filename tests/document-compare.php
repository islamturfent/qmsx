<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'includes/document-compare-functions.php';
$checks = 0;
function dcCheck(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $name); $checks++; echo 'PASS: ' . $name . PHP_EOL; }

// ---- LCS diff: bolunmus satirlar.
$ops = qmsDiffLines(['a', 'b', 'c'], ['a', 'x', 'c']);
dcCheck($ops === [
    ['type' => 'same', 'text' => 'a'],
    ['type' => 'del', 'text' => 'b'],
    ['type' => 'add', 'text' => 'x'],
    ['type' => 'same', 'text' => 'c'],
], 'Diff reports deletion and addition between same lines');

$ops2 = qmsDiffLines(['a', 'b'], ['a', 'b']);
dcCheck(!array_filter($ops2, fn($o) => $o['type'] !== 'same'), 'Identical inputs produce only "same" ops');

$ops3 = qmsDiffLines([], ['z']);
dcCheck($ops3 === [['type' => 'add', 'text' => 'z']], 'Empty old to new is an addition');

$ops4 = qmsDiffLines(['q'], []);
dcCheck($ops4 === [['type' => 'del', 'text' => 'q']], 'Non-empty old to empty is a deletion');

$ops5 = qmsDiffLines([], []);
dcCheck($ops5 === [], 'Both empty yields empty diff');

// ---- Satirlara bolme.
dcCheck(qmsCompareTextToLines("sat1\nsat2\n") === ['sat1', 'sat2', ''], 'Text split into lines keeps trailing empty token');
dcCheck(qmsCompareTextToLines('') === [], 'Empty text yields no lines');

// ---- Govde cikarim: gecici depolama dosyasi olusturup temizliyoruz.
$tmpName = 'compare-tmp-' . bin2hex(random_bytes(6)) . '.html';
$tmpPath = dirname(__DIR__) . '/storage/documents/' . $tmpName;
file_put_contents($tmpPath, '<!doctype html><html><head><title>t</title></head><body><p>Merhaba</p><ul><li>Kalem 1</li></ul></body></html>');
$body = qmsCompareVersionBody($tmpName);
dcCheck(strpos($body['html'], '<p>Merhaba</p>') !== false, 'Body html extracts the inner content');
dcCheck(trim($body['text']) === 'Merhaba Kalem 1', 'Plain text flattens tags into words');
dcCheck(qmsCompareVersionBody('nonexistent-' . $tmpName) === ['html' => '', 'text' => ''], 'Missing file yields empty body');
unlink($tmpPath);

// ---- Cok buyuk girdilerde diff guvenli sekilde bos doner.
$big = array_fill(0, 4000, 'x');
dcCheck(qmsDiffLines($big, ['a']) === [], 'Oversized input returns empty diff instead of memory blowup');

// ---- Diff ozet sayilari.
$s = qmsDiffSummary([['type' => 'same', 'text' => 'a'], ['type' => 'add', 'text' => 'x'], ['type' => 'del', 'text' => 'b']]);
dcCheck($s['same'] === 1 && $s['add'] === 1 && $s['del'] === 1 && $s['changed'] === 2, 'Diff summary counts same/add/del and changed');
dcCheck(qmsDiffSummary([]) === ['same' => 0, 'add' => 0, 'del' => 0, 'changed' => 0], 'Empty diff summary is all zeros');

// ---- Duz metin karsilastirma kucuk/buyuk harf duyarliligi korunur.
dcCheck(qmsCompareTextToLines('a' . chr(10) . 'b') === ['a', 'b'], 'Simple two-line text splits correctly');

echo "\nCompleted $checks document-compare checks.\n";
