<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * automacao_toggle.php — liga/desliga a automação inteira (botão da barra do topo).
 * Grava a flag global "automacao_pausada"; os crons de postagem/geração checam e param.
 * Aceita POST com CSRF. Pode receber ?estado=1 (pausar) | 0 (religar); sem isso, alterna.
 */

require __DIR__ . '/init.php';
exigir_login();

$voltar = $_SERVER['HTTP_REFERER'] ?? (BASE_URL . '/agendados.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok($_POST['csrf'] ?? null)) {
    header('Location: ' . $voltar);
    exit;
}

if (isset($_POST['estado'])) {
    $novo = $_POST['estado'] === '1' ? '1' : '0';
} else {
    $novo = automacao_pausada() ? '0' : '1';
}
cfg_set('automacao_pausada', $novo);

$_SESSION['flash'] = $novo === '1'
    ? ['Automação PAUSADA. Nada será publicado até você religar.', 'erro']
    : ['Automação RELIGADA. As publicações voltam no ritmo seguro.', 'ok'];

header('Location: ' . $voltar);
exit;
