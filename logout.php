<?php
require __DIR__ . '/init.php';
logout();
header('Location: ' . BASE_URL . '/login.php');
exit;
