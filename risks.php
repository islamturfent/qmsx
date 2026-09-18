<?php
session_start();
if (empty($_SESSION['qms_logged_in'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/risk-functions.php';
$userId = (int) ($_SESSION['qms_user_id'] ?? 0);
$super = ($_SESSION['qms_role'] ?? '') === 'super_admin';
$companyIds = qmsRiskScope($pdo, $userId, $super);
$companies = [];
if ($companyIds) {
    $marks = implode(',', array_fill(0, count($companyIds), '?'));
    $stmt = $pdo->prepare("SELECT id, company_name FROM companies WHERE id IN ($marks) ORDER BY company_name");
    $stmt->execute($companyIds); $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$filters = ['company_id' => (int) ($_GET['company_id'] ?? 0), 'status' => (string) ($_GET['status'] ?? ''),
    'level' => (string) ($_GET['level'] ?? ''), 'category' => qmsRiskText($_GET['category'] ?? '', 100),
    'responsible' => qmsRiskText($_GET['responsible'] ?? '', 150), 'due' => (string) ($_GET['due'] ?? '')];
if ($filters['company_id'] && !in_array($filters['company_id'], $companyIds, true)) { http_response_code(403); exit('Bu şirket için yetkiniz yok.'); }
$risks = [];
if ($companyIds) {
    $marks = implode(',', array_fill(0, count($companyIds), '?'));
    $stmt = $pdo->prepare("SELECT r.*, c.company_name FROM risks r JOIN companies c ON c.id=r.company_id WHERE r.active=1 AND r.company_id IN ($marks) ORDER BY r.created_at DESC");
    $stmt->execute($companyIds); $risks = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$today = date('Y-m-d');
$risks = array_values(array_filter($risks, function ($risk) use ($filters, $today) {
    $score = qmsRiskScore($risk['residual_likelihood'], $risk['residual_impact']) ?? qmsRiskScore($risk['initial_likelihood'], $risk['initial_impact']);
    $overdue = $risk['due_date'] && $risk['due_date'] < $today && $risk['status'] !== 'closed';
    if ($filters['company_id'] && (int) $risk['company_id'] !== $filters['company_id']) return false;
    if ($filters['status'] !== '' && $risk['status'] !== $filters['status']) return false;
    if ($filters['level'] !== '' && qmsRiskLevel($score) !== $filters['level']) return false;
    if ($filters['category'] !== '' && stripos($risk['category'] ?? '', $filters['category']) === false) return false;
    if ($filters['responsible'] !== '' && stripos($risk['responsible_person'] ?? '', $filters['responsible']) === false) return false;
    if ($filters['due'] === 'overdue' && !$overdue) return false;
    return true;
}));
$summary = ['open' => 0, 'critical' => 0, 'overdue' => 0, 'closed' => 0];
foreach ($risks as $risk) {
    $score = qmsRiskScore($risk['residual_likelihood'], $risk['residual_impact']) ?? qmsRiskScore($risk['initial_likelihood'], $risk['initial_impact']);
    if ($risk['status'] === 'closed') $summary['closed']++; else $summary['open']++;
    if (qmsRiskLevel($score) === 'critical') $summary['critical']++;
    if ($risk['due_date'] && $risk['due_date'] < $today && $risk['status'] !== 'closed') $summary['overdue']++;
}
$statusLabels = ['open'=>'Açık','monitoring'=>'İzlemede','treated'=>'Önlem Uygulandı','closed'=>'Kapalı'];
$activeNav = 'risks';
function riskEsc($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>QMS Risk Yönetimi</title><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/risks.css"></head><body class="has-sidebar">
<?php require __DIR__ . '/includes/app-sidebar.php'; ?>
<header class="topbar"><div class="topbar-inner"><div class="page-title-block"><strong data-i18n="riskManagementTitle">Risk Yönetimi</strong><span data-i18n="riskManagementText">Şirket risklerini değerlendirin ve izleyin.</span></div><div class="topbar-actions"><button class="topbar-button" id="languageToggle">EN</button><button class="topbar-button" id="themeToggle" aria-label="Tema değiştir">🌙</button></div></div></header>
<main class="page-container"><section class="page-heading page-heading-actions"><div><span class="section-kicker" data-i18n="riskRegisterKicker">Risk Kayıtları</span><h1 data-i18n="riskManagementTitle">Risk Yönetimi</h1><p data-i18n="riskScaleNotice">Puan = Olasılık × Etki. Eşikler proje varsayımıdır.</p></div><a class="primary-button" href="risk-create.php" data-i18n="newRiskButton">Yeni Risk</a></section>
<?php if (($_GET['risk'] ?? '') === 'created'): ?><div class="form-message success" data-i18n="riskCreatedMessage">Risk kaydı oluşturuldu.</div><?php endif; ?>
<section class="dashboard-grid compact-dashboard-grid"><div class="dashboard-card"><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="openRisksLabel">Açık Riskler</span><strong class="dashboard-card-number"><?= $summary['open'] ?></strong></div></div><div class="dashboard-card"><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="criticalRisksLabel">Kritik Riskler</span><strong class="dashboard-card-number"><?= $summary['critical'] ?></strong></div></div><div class="dashboard-card"><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="overdueRisksLabel">Gecikenler</span><strong class="dashboard-card-number"><?= $summary['overdue'] ?></strong></div></div><div class="dashboard-card"><div class="dashboard-card-content"><span class="dashboard-card-label" data-i18n="closedRisksLabel">Kapalı Riskler</span><strong class="dashboard-card-number"><?= $summary['closed'] ?></strong></div></div></section>
<section class="form-panel"><form method="get" class="filter-grid"><label class="form-field"><span data-i18n="companySelectLabel">Şirket</span><select name="company_id"><option value="0" data-i18n="allCompaniesOption">Tüm şirketler</option><?php foreach($companies as $company): ?><option value="<?= (int)$company['id'] ?>" <?= $filters['company_id']==$company['id']?'selected':'' ?>><?= riskEsc($company['company_name']) ?></option><?php endforeach; ?></select></label><label class="form-field"><span data-i18n="riskStatusLabel">Durum</span><select name="status"><option value="">Tümü</option><?php foreach($statusLabels as $value=>$label): ?><option value="<?= $value ?>" <?= $filters['status']===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label><label class="form-field"><span data-i18n="riskLevelLabel">Seviye</span><select name="level"><option value="">Tümü</option><?php foreach(['low'=>'Düşük','medium'=>'Orta','high'=>'Yüksek','critical'=>'Kritik'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filters['level']===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label><label class="form-field"><span data-i18n="riskCategoryLabel">Kategori</span><input name="category" value="<?= riskEsc($filters['category']) ?>"></label><label class="form-field"><span data-i18n="responsiblePersonLabel">Sorumlu</span><input name="responsible" value="<?= riskEsc($filters['responsible']) ?>"></label><label class="form-field"><span data-i18n="dueFilterLabel">Termin</span><select name="due"><option value="">Tümü</option><option value="overdue" <?= $filters['due']==='overdue'?'selected':'' ?>>Geciken</option></select></label><div class="form-actions"><button class="primary-button" data-i18n="applyFiltersButton">Filtrele</button><a class="secondary-button" href="risks.php" data-i18n="clearFiltersButton">Temizle</a></div></form></section>
<section class="page-section"><div class="record-card-grid"><?php if(!$risks): ?><div class="empty-state" data-i18n="noRisksText">Filtrelere uygun risk bulunamadı.</div><?php endif; ?><?php foreach($risks as $risk): $initial=qmsRiskScore($risk['initial_likelihood'],$risk['initial_impact']); $residual=qmsRiskScore($risk['residual_likelihood'],$risk['residual_impact']); $score=$residual??$initial; $level=qmsRiskLevel($score); ?><a class="record-card risk-card" href="risk-detail.php?id=<?= (int)$risk['id'] ?>"><div class="record-card-topline"><span><?= riskEsc($risk['company_name']) ?></span><span class="risk-level risk-level-<?= $level ?>"><?= qmsRiskLevelLabel($level) ?> · <?= $score ?></span></div><h3><?= riskEsc($risk['title']) ?></h3><p><?= riskEsc($risk['category'] ?: '—') ?> · <?= riskEsc($statusLabels[$risk['status']] ?? $risk['status']) ?></p><p><?= riskEsc($risk['responsible_person'] ?: 'Sorumlu yok') ?><?= $risk['due_date'] ? ' · '.$risk['due_date'] : '' ?></p></a><?php endforeach; ?></div></section></main>
<script src="assets/js/theme.js"></script><script src="assets/js/language.js"></script><script src="assets/js/sidebar.js"></script><script src="assets/js/pwa.js"></script></body></html>
