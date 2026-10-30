<?php
require_once __DIR__ . '/includes/menu.php';
require_once __DIR__ . '/includes/auth.php';

// Sair só por POST com token CSRF, para que links externos não encerrem a sessão.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valido()) {
    auth_logout();
}
header('Location: ' . admin_base() . 'login.php');
exit;
