<!-- jQuery -->
<script src="plugins/jquery/jquery.min.js"></script>
<!-- Bootstrap 4 -->
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- overlayScrollbars -->
<script src="plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script>
<!-- AdminLTE App -->
<script src="dist/js/adminlte.min.js"></script>
<!-- Custom JS -->
<script src="custom/custom-js.js?v=4"></script>

<?php
/* Ambient nature FX — modular engine, config from settings table */
if (isset($conn) && function_exists('nature_fx_config')) {
    $__natureFx = nature_fx_config();
    if (!empty($__natureFx['enabled'])) {
        ?>
<script>
  window.CRM_NATURE_FX = <?= json_encode([
      'enabled' => true,
      'frequency' => $__natureFx['frequency'],
      'types' => array_values($__natureFx['types']),
      'assets' => $__natureFx['assets'] ?? [],
  ], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="custom/nature-fx.js?v=15" defer></script>
        <?php
    }
    unset($__natureFx);
}
?>
