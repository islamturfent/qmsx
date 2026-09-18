<?php
// Called under the same documents row lock used by every revision/workflow writer.
function qmsOfficeAssertUnlocked(PDO $pdo, int $documentId): void
{
    try {
        $stmt = $pdo->prepare('SELECT expires_at FROM office_locks WHERE document_id = ? AND expires_at > ?');
        $stmt->execute([$documentId, time()]);
        if ($stmt->fetchColumn()) throw new RuntimeException('Doküman ofis editöründe açık. Önce ofis düzenlemesini kaydedip kapatın; kilit sürerse süresinin dolmasını bekleyin.');
    } catch (PDOException $e) { if ($e->getCode() !== '42S02') throw $e; }
}
