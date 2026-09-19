<?php

require_once __DIR__ . '/access.php';

const QMS_RISK_STATUSES = ['open', 'monitoring', 'treated', 'closed'];

/**
 * Kullanicinin risk modulunde gorebildigi sirketler.
 *
 * Kapsam tek kaynaktan gelir (includes/access.php): sistem admini atandigi
 * sirketler, sirket kullanicisi kendi sirketi, denetci atandigi denetimlerin
 * sirketleri.
 */
function qmsRiskScope(PDO $pdo, int $userId, bool $super): array
{
    if ($super) {
        return array_map('intval', $pdo->query('SELECT id FROM companies WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN));
    }

    $role = (string) ($_SESSION['qms_role'] ?? '');
    if (!in_array($role, ['system_admin', 'company_user', 'auditor'], true)) {
        $role = 'system_admin';
    }

    return qmsVisibleCompanyIds($pdo, $userId, $role) ?? [];
}

function qmsRiskScore(?int $likelihood, ?int $impact): ?int
{
    return $likelihood && $impact ? $likelihood * $impact : null;
}

function qmsRiskLevel(?int $score): string
{
    if ($score === null) return 'unrated';
    if ($score <= 4) return 'low';
    if ($score <= 9) return 'medium';
    if ($score <= 16) return 'high';
    return 'critical';
}

function qmsRiskValue(mixed $value, bool $optional = false): ?int
{
    if ($optional && ($value === null || $value === '')) return null;
    $filtered = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
    return $filtered === false ? null : (int) $filtered;
}

function qmsRiskText(mixed $value, int $max): string
{
    return mb_substr(trim((string) $value), 0, $max);
}

function qmsRiskCsrf(): string
{
    $_SESSION['risk_csrf'] ??= bin2hex(random_bytes(32));
    return $_SESSION['risk_csrf'];
}

function qmsRiskVerifyCsrf(mixed $value): void
{
    if (!is_string($value) || !hash_equals(qmsRiskCsrf(), $value)) {
        http_response_code(403);
        throw new RuntimeException('Oturum doğrulanamadı. Sayfayı yenileyin.');
    }
}

function qmsRiskFind(PDO $pdo, int $id, int $userId, bool $super, bool $lock = false): array
{
    $sql = 'SELECT r.*, c.company_name FROM risks r JOIN companies c ON c.id = r.company_id WHERE r.id = ? AND r.active = 1 AND c.active = 1';
    $params = [$id];
    if (!$super) {
        $role = (string) ($_SESSION['qms_role'] ?? '');
        if (!in_array($role, ['system_admin', 'company_user', 'auditor'], true)) {
            $role = 'system_admin';
        }
        $scope = qmsCompanyScope('r.company_id', qmsVisibleCompanyIds($pdo, $userId, $role));
        $sql .= $scope['sql'];
        $params = array_merge($params, $scope['params']);
    }
    $stmt = $pdo->prepare($sql . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function qmsRiskValidate(array $input, array $allowedCompanyIds, bool $creating): array
{
    $data = [
        'company_id' => (int) ($input['company_id'] ?? 0),
        'title' => qmsRiskText($input['title'] ?? '', 255),
        'category' => qmsRiskText($input['category'] ?? '', 100),
        'responsible_person' => qmsRiskText($input['responsible_person'] ?? '', 150),
        'due_date' => trim((string) ($input['due_date'] ?? '')),
        'status' => (string) ($input['status'] ?? 'open'),
        'description' => qmsRiskText($input['description'] ?? '', 20000),
        'existing_controls' => qmsRiskText($input['existing_controls'] ?? '', 20000),
        'treatment_plan' => qmsRiskText($input['treatment_plan'] ?? '', 20000),
        'initial_likelihood' => qmsRiskValue($input['initial_likelihood'] ?? null),
        'initial_impact' => qmsRiskValue($input['initial_impact'] ?? null),
        'residual_likelihood' => qmsRiskValue($input['residual_likelihood'] ?? null, true),
        'residual_impact' => qmsRiskValue($input['residual_impact'] ?? null, true),
        'note' => qmsRiskText($input['note'] ?? '', 10000)
    ];
    if ($creating && !in_array($data['company_id'], $allowedCompanyIds, true)) throw new RuntimeException('Geçerli ve yetkili olduğunuz bir şirket seçin.');
    if ($data['title'] === '' || $data['description'] === '') throw new RuntimeException('Risk tanımı ve açıklaması zorunludur.');
    if ($data['initial_likelihood'] === null || $data['initial_impact'] === null) throw new RuntimeException('Başlangıç olasılık ve etki değerleri 1–5 arasında olmalıdır.');
    if (($data['residual_likelihood'] === null) !== ($data['residual_impact'] === null)) throw new RuntimeException('Önlem sonrası olasılık ve etki birlikte girilmelidir.');
    if (!in_array($data['status'], QMS_RISK_STATUSES, true)) throw new RuntimeException('Geçerli bir risk durumu seçin.');
    if ($data['due_date'] !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $data['due_date']);
        if (!$date || $date->format('Y-m-d') !== $data['due_date']) throw new RuntimeException('Geçerli bir termin tarihi girin.');
    }
    return $data;
}

function qmsRiskLevelLabel(string $level): string
{
    return ['low' => 'Düşük', 'medium' => 'Orta', 'high' => 'Yüksek', 'critical' => 'Kritik', 'unrated' => 'Değerlendirilmedi'][$level] ?? $level;
}
