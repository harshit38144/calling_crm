<?php
include_once 'connection.php';
if (!empty($_SESSION['id'])) {
    // optional: log logout
}
session_destroy();
header('Location: index.php');
exit;
