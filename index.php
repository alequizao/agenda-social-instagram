<?php
require __DIR__ . '/init.php';
header('Location: ' . BASE_URL . '/' . (usuario_logado() ? 'dashboard.php' : 'login.php'));
exit;
