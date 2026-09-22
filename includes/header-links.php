<!-- Tell the browser to be responsive to screen width -->
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script>
    (function () {
      try {
        if ((localStorage.getItem('crm-theme') || 'dark') === 'light') {
          document.documentElement.classList.add('crm-theme-light');
        }
      } catch (e) {}
    })();
  </script>
  <!-- Font Awesome -->
  <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
  <!-- Theme style -->
  <link rel="stylesheet" href="dist/css/adminlte.min.css">
  <!-- overlayScrollbars -->
  <link rel="stylesheet" href="plugins/overlayScrollbars/css/OverlayScrollbars.min.css">
  <!-- Custom CRM theme (loads Outfit + Cormorant) -->
  <link rel="stylesheet" href="custom/custom-css.css?v=14">
<?php
if (isset($conn) && function_exists('nature_fx_config') && !empty(nature_fx_config()['enabled'])) {
    echo '  <link rel="stylesheet" href="custom/nature-fx.css?v=6">' . "\n";
}
?>
