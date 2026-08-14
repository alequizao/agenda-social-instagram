<?php
require __DIR__ . '/init.php';
exigir_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok($_POST['csrf'] ?? null)) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);

if ($id > 0) {
    // apaga arquivos de midia das publicacoes do cliente
    $mm = db()->prepare(
        'SELECT m.arquivo FROM ' . DB_PREFIX . 'publicacao_midia m
           JOIN ' . DB_PREFIX . 'publicacoes p ON p.id = m.publicacao_id
          WHERE p.cliente_id = ?'
    );
    $mm->execute([$id]);
    foreach ($mm->fetchAll() as $m) {
        if (!empty($m['arquivo']) && is_file(__DIR__ . '/' . $m['arquivo'])) {
            @unlink(__DIR__ . '/' . $m['arquivo']);
        }
    }

    // apaga capas dos reels
    $cp = db()->prepare('SELECT capa_arquivo FROM ' . DB_PREFIX . 'publicacoes WHERE cliente_id = ? AND capa_arquivo IS NOT NULL');
    $cp->execute([$id]);
    foreach ($cp->fetchAll() as $r) {
        if (!empty($r['capa_arquivo']) && is_file(__DIR__ . '/' . $r['capa_arquivo'])) {
            @unlink(__DIR__ . '/' . $r['capa_arquivo']);
        }
    }

    // foto do cliente
    $fc = db()->prepare('SELECT foto_perfil FROM ' . DB_PREFIX . 'clientes WHERE id = ? LIMIT 1');
    $fc->execute([$id]);
    $foto = (string) ($fc->fetchColumn() ?: '');
    if ($foto !== '' && is_file(__DIR__ . '/' . $foto)) {
        @unlink(__DIR__ . '/' . $foto);
    }

    // remove cliente (FK ON DELETE CASCADE limpa publicacoes e midias)
    db()->prepare('DELETE FROM ' . DB_PREFIX . 'clientes WHERE id = ?')->execute([$id]);
}

header('Location: ' . BASE_URL . '/dashboard.php');
exit;
