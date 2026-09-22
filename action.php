<?php
include_once 'connection.php';
include_once 'sql/login.php';

// Fallback if no handler matched
if (!empty($_POST)) {
    $_SESSION['msg'] = 'No action handled. Please try again.';
}
$ref = $_SERVER['HTTP_REFERER'] ?? 'index.php';
header('Location: ' . $ref);
exit;
