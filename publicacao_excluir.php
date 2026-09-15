<?php
/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
require __DIR__ . '/init.php';
exigir_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok($_POST['csrf'] ?? null)) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$destino = BASE_URL . '/dashboard.php';

if ($id > 0) {
    $st = db()->prepare('SELECT cliente_id FROM publicacoes WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $clienteId = (int) ($st->fetchColumn() ?: 0);

    // remove arquivos das midias
    $mm = db()->prepare('SELECT arquivo FROM publicacao_midia WHERE publicacao_id = ?');
    $mm->execute([$id]);
    foreach ($mm->fetchAll() as $m) {
        if (!empty($m['arquivo']) && is_file(__DIR__ . '/' . $m['arquivo'])) {
            @unlink(__DIR__ . '/' . $m['arquivo']);
        }
    }

    db()->prepare('DELETE FROM publicacoes WHERE id = ?')->execute([$id]);

    if ($clienteId > 0) {
        $destino = BASE_URL . '/cliente.php?id=' . $clienteId;
    }
}

header('Location: ' . $destino);
exit;
