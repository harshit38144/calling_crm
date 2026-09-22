<?php
include_once __DIR__ . '/../connection.php';

if (isset($_POST['adminlogin'])) {
    $name = trim($_POST['name'] ?? '');
    $pass = trim($_POST['pass'] ?? '');

    $stmt = $conn->prepare(
        'SELECT * FROM users WHERE username = ? AND status = ? LIMIT 1'
    );
    $active = 'active';
    $stmt->bind_param('ss', $name, $active);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $ok = ($row['password'] === $pass) || password_verify($pass, $row['password']);
        if ($ok) {
            $_SESSION['id'] = $row['id'];
            $_SESSION['name'] = $row['name'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['email'] = $row['email'];
            $_SESSION['role'] = $row['role'];

            $uid = (int) $row['id'];
            $conn->query("UPDATE users SET last_login_at = NOW() WHERE id = {$uid}");

            header('Location: dashboard.php');
            exit;
        }
    }

    $_SESSION['msg'] = 'Invalid username or password!';
    header('Location: index.php');
    exit;
}
