<?php
require __DIR__ . '/inc/bootstrap.php';
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
