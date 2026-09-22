<?php
$page = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<aside class="main-sidebar sidebar-dark-primary elevation-0">
  <div class="sidebar">
    <div class="crm-sidebar-brand">
      <img src="dist/img/logo01.webp" alt="Logo" class="crm-sidebar-logo">
    </div>
    <nav class="mt-1">
      <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
        <li class="nav-item">
          <a href="dashboard.php" class="nav-link <?= ($page == 'dashboard') ? 'active' : '' ?>">
            <i class="nav-icon fas fa-th-large"></i><p>Dashboard</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="leads.php" class="nav-link <?= in_array($page, ['leads', 'lead_view', 'lead_edit'], true) ? 'active' : '' ?>">
            <i class="nav-icon fas fa-address-book"></i><p>Leads</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="import_excel.php" class="nav-link <?= ($page == 'import_excel') ? 'active' : '' ?>">
            <i class="nav-icon fas fa-cloud-upload-alt"></i><p>Import</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="reports.php" class="nav-link <?= ($page == 'reports') ? 'active' : '' ?>">
            <i class="nav-icon fas fa-chart-pie"></i><p>Reports</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="settings.php" class="nav-link <?= ($page == 'settings') ? 'active' : '' ?>">
            <i class="nav-icon fas fa-cog"></i><p>Settings</p>
          </a>
        </li>
        <li class="nav-item mt-4">
          <a href="logout.php" class="nav-link">
            <i class="nav-icon fas fa-sign-out-alt"></i><p>Logout</p>
          </a>
        </li>
      </ul>
    </nav>
  </div>
</aside>
