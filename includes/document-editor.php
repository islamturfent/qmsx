<?php
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/office/locks.php';

function qmsEditorHtml(string $html): string
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $allowed = ['p', 'div', 'br', 'strong', 'b', 'em', 'i', 'u', 'h1', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'a'];
    $render = function (DOMNode $node) use (&$render, $allowed): string {
        if ($node instanceof DOMText) return htmlspecialchars($node->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (!($node instanceof DOMElement)) return '';
        $tag = strtolower($node->tagName);
        if (in_array($tag, ['head', 'title', 'meta', 'link', 'script', 'style', 'iframe', 'object', 'svg', 'math', 'template', 'form', 'input', 'button', 'textarea', 'select'], true)) return '';
        $content = '';
        foreach ($node->childNodes as $child) $content .= $render($child);
        if (!in_array($tag, $allowed, true)) return $content;
        if ($tag === 'br') return '<br>';
        $attributes = '';
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            $allowedHref = preg_match('~^(https?://|mailto:|/|#)~i', $href) === 1;
            if ($allowedHref) $attributes .= ' href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            $title = trim($node->getAttribute('title'));
            if ($title !== '') $attributes .= ' title="' . htmlspecialchars(mb_substr($title, 0, 255), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            if ($node->getAttribute('target') === '_blank') $attributes .= ' target="_blank" rel="noopener noreferrer"';
        }
        if (in_array($tag, ['th', 'td'], true)) {
            foreach (['colspan', 'rowspan'] as $name) {
                $value = (int) $node->getAttribute($name);
                if ($value >= 2 && $value <= 20) $attributes .= ' ' . $name . '="' . $value . '"';
            }
            if ($tag === 'th' && in_array($node->getAttribute('scope'), ['row', 'col'], true)) {
                $attributes .= ' scope="' . $node->getAttribute('scope') . '"';
            }
        }
        return '<' . $tag . $attributes . '>' . $content . '</' . $tag . '>';
    };
    $result = '';
    foreach ($dom->getElementsByTagName('body')->item(0)->childNodes as $node) $result .= $render($node);
    return $result;
}

function qmsEditorDocument(PDO $pdo, int $id, int $userId, bool $super, bool $lock = false): array
{
    $sql = 'SELECT d.*, c.company_name FROM documents d JOIN companies c ON c.id = d.company_id WHERE d.id = ? AND d.active = 1';
    $params = [$id];
    if (!$super) {
        $scope = qmsCompanyScope('d.company_id', qmsVisibleCompanyIds($pdo, $userId, qmsScopedRole()));
        $sql .= $scope['sql'];
        $params = array_merge($params, $scope['params']);
    }
    $stmt = $pdo->prepare($sql . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function qmsEditorLatest(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM document_versions WHERE document_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function qmsSaveEditorRevision(PDO $pdo, int $id, int $userId, bool $super, int $baseId, string $revision, string $html, string $note): void
{
    $revision = trim($revision);
    if (strlen($html) > 1024 * 1024) throw new RuntimeException('İçerik 1 MB sınırını aşıyor.');
    $html = qmsEditorHtml($html);
    if ($revision === '' || mb_strlen($revision) > 30) throw new RuntimeException('Revizyon numarası 1–30 karakter olmalıdır.');
    if (trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'), " \t\n\r\0\x0B\xC2\xA0") === '') throw new RuntimeException('Doküman içeriği boş olamaz.');
    if (strlen($html) > 1024 * 1024 || strlen($note) > 10000) throw new RuntimeException('İçerik veya revizyon notu çok uzun.');
    $path = null;
    try {
        $pdo->beginTransaction();
        $doc = qmsEditorDocument($pdo, $id, $userId, $super, true);
        if (!$doc) throw new RuntimeException('Dokümana erişim yetkiniz yok.');
        qmsOfficeAssertUnlocked($pdo, $id);
        if (!in_array($doc['status'], ['draft', 'approved', 'published'], true)) throw new RuntimeException('İncelemedeki veya arşivlenmiş doküman düzenlenemez.');
        $latest = qmsEditorLatest($pdo, $id);
        if ((int) ($latest['id'] ?? 0) !== $baseId) throw new RuntimeException('Yeni bir revizyon kaydedilmiş. İçeriğinizi kopyalayıp sayfayı yenileyin.');
        $pending = $pdo->prepare("SELECT COUNT(*) FROM document_approvals WHERE document_id = ? AND decision = 'pending'");
        $pending->execute([$id]);
        if ($pending->fetchColumn()) throw new RuntimeException('Bekleyen onay tamamlanmadan düzenleme yapılamaz.');
        $name = bin2hex(random_bytes(20)) . '.html';
        $path = dirname(__DIR__) . '/storage/documents/' . $name;
        $file = '<!doctype html><html lang="tr"><head><meta charset="UTF-8"><title>QMS</title></head><body>' . $html . '</body></html>';
        if (file_put_contents($path, $file, LOCK_EX) !== strlen($file)) throw new RuntimeException('Doküman dosyası kaydedilemedi.');
        $stmt = $pdo->prepare('INSERT INTO document_versions (document_id, revision_number, original_file_name, stored_file_name, mime_type, file_size, change_note, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$id, $revision, 'document-' . $id . '.html', $name, 'text/html', strlen($file), trim($note) ?: null, $userId]);
        $pdo->prepare("UPDATE documents SET current_revision = ?, status = 'draft' WHERE id = ?")->execute([$revision, $id]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($path && is_file($path)) unlink($path);
        if ($error instanceof PDOException) throw new RuntimeException($error->getCode() === '23000' ? 'Bu revizyon numarası daha önce kullanılmış.' : 'Revizyon kaydedilemedi.');
        throw $error;
    }
}
