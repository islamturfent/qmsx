<?php

declare(strict_types=1);

/**
 * CSRF yardimcilari - tek kaynak.
 *
 * Kullanim:
 *   require_once __DIR__ . '/includes/csrf.php';
 *   $csrfToken = qmsCsrfToken('capa');                  // sayfa basinda bir kez
 *   qmsCsrfVerify('capa', $_POST['csrf'] ?? null);      // POST isleyen her ucta
 *   echo qmsCsrfField('capa');                          // her formun icinde
 *
 * Kapsam (scope) adi sayfa veya modul bazinda verilir; boylece bir sayfada
 * uretilen token baska bir sayfada gecerli olmaz.
 */

function qmsCsrfToken(string $scope = 'default'): string
{
    $key = 'qms_csrf_' . $scope;

    if (!isset($_SESSION[$key]) || !is_string($_SESSION[$key]) || $_SESSION[$key] === '') {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }

    return $_SESSION[$key];
}

function qmsCsrfVerify(string $scope, mixed $provided): void
{
    $key = 'qms_csrf_' . $scope;
    $expected = isset($_SESSION[$key]) && is_string($_SESSION[$key]) ? $_SESSION[$key] : '';

    if ($expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
        http_response_code(403);
        exit('Geçersiz istek.');
    }
}

/**
 * Formlarin icine yazilacak gizli alan.
 */
function qmsCsrfField(string $scope = 'default'): string
{
    return '<input type="hidden" name="csrf" value="'
        . htmlspecialchars(qmsCsrfToken($scope), ENT_QUOTES, 'UTF-8')
        . '">';
}
