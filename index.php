<?php
include_once 'connection.php';

$msg = flash_msg();
if ($msg !== '') {
    echo "<script>alert(" . json_encode($msg) . ")</script>";
}

$role = $_SESSION['role'] ?? '';
if (!empty($_SESSION['id']) && in_array((string) $role, ['admin', 'manager', 'agent', '1'], true)) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Calling CRM — Login</title>
  <?php include 'includes/header-links.php'; ?>
</head>
<body class="hold-transition login-page">
  <div class="login-box">
    <div class="card">
      <div class="card-body">
        <div class="login-mark">C</div>
        <div class="login-logo text-center">
          <a href="#"><b>Calling</b> CRM</a>
        </div>
        <p class="login-sub">A calm space to manage leads &amp; calls</p>
        <div class="text-center mb-3">
          <button type="button" class="crm-theme-toggle" id="crm-theme-toggle" aria-label="Toggle light and dark mode" title="Toggle theme">
            <i class="fas fa-sun crm-theme-icon-sun" aria-hidden="true"></i>
            <i class="fas fa-moon crm-theme-icon-moon" aria-hidden="true"></i>
          </button>
        </div>
        <form action="action.php" method="post">
          <label for="username">Username</label>
          <div class="input-group mb-3">
            <input type="text" name="name" id="username" class="form-control" placeholder="Enter username" required>
            <div class="input-group-append">
              <div class="input-group-text"><span class="fas fa-user"></span></div>
            </div>
          </div>
          <label for="password">Password</label>
          <div class="input-group mb-3">
            <input type="password" name="pass" id="password" class="form-control" placeholder="Enter password" required>
            <div class="input-group-append">
              <div class="input-group-text"><span class="fas fa-lock"></span></div>
            </div>
          </div>
          <button type="submit" name="adminlogin" class="btn btn-block text-white">
            Sign in <i class="fas fa-arrow-right ml-1"></i>
          </button>
          <div class="frm-footer text-center">© <?= date('Y') ?> Calling CRM</div>
        </form>
      </div>
    </div>
  </div>
  <?php include 'includes/footer-links.php'; ?>
</body>
</html>
