<?php

declare(strict_types=1);

/**
 * Moduller arasi paylasilan sozlukler.
 *
 * Ayni olcu birden fazla modulde gecerse (ornegin uygunsuzluk ve sikayet
 * onem derecesi) tek kaynak burasidir; sayfalar kendi dizilerini yazmaz.
 */

/** Onem derecesi: uygunsuzluk, duzeltici faaliyet ve sikayet ayni olcuyu kullanir. */
const QMS_SEVERITIES = ['minor', 'major', 'critical'];

/** @return array<string, string> */
function qmsSeverityLabels(): array
{
    return [
        'minor' => 'Minör',
        'major' => 'Majör',
        'critical' => 'Kritik',
    ];
}

/** @return array<string, string> */
function qmsSeverityI18nKeys(): array
{
    return [
        'minor' => 'severityMinorLabel',
        'major' => 'severityMajorLabel',
        'critical' => 'severityCriticalLabel',
    ];
}
