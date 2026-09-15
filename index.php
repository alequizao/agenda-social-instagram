<?php
/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
require __DIR__ . '/init.php';
header('Location: ' . BASE_URL . '/' . (usuario_logado() ? 'dashboard.php' : 'login.php'));
exit;
