<?php
$userName = $_SESSION['name'] ?? 'Admin';
$initial = strtoupper(mb_substr(trim($userName), 0, 1));
?>
<nav class="main-header navbar navbar-expand">
  <ul class="navbar-nav align-items-center">
    <li class="nav-item">
      <a class="nav-link" href="#" id="crm-sidebar-toggle" role="button" aria-label="Toggle menu" aria-expanded="false">
        <i class="fas fa-bars"></i>
      </a>
    </li>
    <li class="nav-item d-none d-sm-inline-block">
      <a href="dashboard.php" class="nav-link crm-brand-link">Calling CRM</a>
    </li>
  </ul>
  <ul class="navbar-nav ml-auto align-items-center pr-3">
    <li class="nav-item mr-2">
      <button type="button" class="crm-theme-toggle" id="crm-theme-toggle" aria-label="Toggle light and dark mode" title="Toggle theme">
        <i class="fas fa-sun crm-theme-icon-sun" aria-hidden="true"></i>
        <i class="fas fa-moon crm-theme-icon-moon" aria-hidden="true"></i>
      </button>
    </li>
    <li class="nav-item">
      <span class="crm-user-chip">
        <span class="crm-user-avatar"><?= htmlspecialchars($initial) ?></span>
        <?= htmlspecialchars($userName) ?>
      </span>
    </li>
  </ul>
</nav>
