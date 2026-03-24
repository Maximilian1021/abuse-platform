<?php
require_once __DIR__ . '/../app/helpers/auth.php';
authLogout();
header('Location: /login.php');
exit;
