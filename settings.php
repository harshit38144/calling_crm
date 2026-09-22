<?php
include_once 'connection.php';
require_admin();

$typeOptions = nature_fx_type_options();
$directionOptions = nature_fx_direction_options();
$cfg = nature_fx_config();
$customAssets = $cfg['custom_assets'] ?? nature_fx_custom_assets();

$enabled = get_setting('nature_fx_enabled', '1') === '1';
$frequency = get_setting('nature_fx_frequency', 'medium');
$typesRaw = (string) get_setting('nature_fx_types', 'all');
$selectedTypes = ($typesRaw === 'all' || $typesRaw === '')
    ? array_keys($typeOptions)
    : array_values(array_filter(array_map('trim', explode(',', $typesRaw))));

$msg = flash_msg();
$msgType = flash_type();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Settings | Calling CRM</title>
  <?php include 'includes/header-links.php'; ?>
  <style>
    .nf-custom-card .form-row > [class*='col-'] { margin-bottom: 0.75rem; }
    .nf-custom-list .nf-custom-row {
      display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
      padding: 10px 0; border-bottom: 1px solid #eee;
    }
    .nf-custom-list .nf-custom-row:last-child { border-bottom: 0; }
    .nf-custom-thumb {
      width: 56px; height: 56px; object-fit: contain;
      border: 1px solid #efd7e1; border-radius: 10px; background: #fff; padding: 4px;
    }
    .nf-custom-meta { flex: 1; min-width: 140px; }
    .nf-custom-meta .title { font-weight: 600; font-size: 0.95rem; }
    .nf-custom-meta .sub { font-size: 12px; color: #6c757d; }
    .nf-custom-controls { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .nf-custom-controls select { max-width: 170px; }
    #spritePreviewBox {
      display: none; margin-top: 10px; padding: 10px; border: 1px dashed #ced4da;
      border-radius: 8px; text-align: center; background: #fafbfc;
    }
    #spritePreviewBox img { max-height: 80px; max-width: 160px; object-fit: contain; }
    .nf-manage-grid { display: flex; flex-wrap: wrap; gap: 10px; }
    .nf-manage-item {
      position: relative; width: 72px; padding: 4px 4px 28px; background: #fff;
      border: 1px solid #efd7e1; border-radius: 12px; text-align: center;
    }
    .nf-manage-item.is-disabled { opacity: 0.55; border-style: dashed; }
    .nf-manage-item img {
      width: 56px; height: 56px; object-fit: contain; display: block; margin: 0 auto; cursor: pointer;
    }
    .nf-manage-item .nf-badge-custom {
      position: absolute; top: 2px; left: 2px; font-size: 9px; line-height: 1;
      background: #17a2b8; color: #fff; border-radius: 4px; padding: 2px 4px;
    }
    .nf-manage-actions {
      position: absolute; left: 0; right: 0; bottom: 4px;
      display: flex; justify-content: center; gap: 4px;
    }
    .nf-manage-actions .btn {
      width: 26px; height: 26px; padding: 0; line-height: 26px; border-radius: 6px;
    }
    #editInsectModal .modal-body .preview-wrap {
      text-align: center; margin-bottom: 1rem;
    }
    #editInsectModal .modal-body .preview-wrap img {
      max-width: 120px; max-height: 120px; object-fit: contain;
      border: 1px solid #eee; border-radius: 10px; padding: 6px; background: #fff;
    }
  </style>
</head>
<body class="hold-transition sidebar-mini sidebar-collapse layout-fixed">
<div class="wrapper">
  <?php include 'includes/top-header.php'; ?>
  <?php include 'includes/sidebar.php'; ?>
  <div class="content-wrapper">
    <?php include 'includes/page-header.php'; ?>
    <section class="content">
      <div class="container-fluid">

        <?php if ($msg): ?>
          <div class="alert alert-<?= htmlspecialchars($msgType === 'error' ? 'danger' : $msgType) ?>">
            <?= htmlspecialchars($msg) ?>
          </div>
        <?php endif; ?>

        <div id="settingsAlert" class="alert" style="display:none;" role="alert"></div>

        <div class="row">
          <div class="col-lg-8">
            <div class="card card-outline card-primary">
              <div class="card-header">
                <h3 class="card-title mb-0"><i class="fas fa-leaf mr-2"></i>Ambient Nature Animations</h3>
              </div>
              <div class="card-body">
                <p class="text-muted mb-4">
                  Subtle butterflies, fireflies, leaves, and birds drift across the admin panel occasionally.
                  They never block clicks and stay low-opacity so work stays uninterrupted.
                </p>

                <form method="post" action="sql/settings_save.php" id="natureFxForm">
                  <input type="hidden" name="save_nature_fx" value="1">

                  <div class="form-group">
                    <div class="custom-control custom-switch">
                      <input type="checkbox" class="custom-control-input" id="nature_fx_enabled"
                             name="nature_fx_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                      <label class="custom-control-label" for="nature_fx_enabled">
                        Enable nature animations
                      </label>
                    </div>
                  </div>

                  <div class="form-group">
                    <label for="nature_fx_frequency">Animation frequency</label>
                    <select name="nature_fx_frequency" id="nature_fx_frequency" class="custom-select" style="max-width:280px;">
                      <option value="low" <?= $frequency === 'low' ? 'selected' : '' ?>>Low — every ~45–90 seconds</option>
                      <option value="medium" <?= $frequency === 'medium' ? 'selected' : '' ?>>Medium — every ~20–50 seconds</option>
                      <option value="high" <?= $frequency === 'high' ? 'selected' : '' ?>>High — every ~10–25 seconds</option>
                    </select>
                    <small class="form-text text-muted">Only one or two animations appear at a time.</small>
                  </div>

                  <div class="form-group">
                    <label class="d-block mb-2">Animation types</label>
                    <div class="custom-control custom-checkbox mb-2">
                      <input type="checkbox" class="custom-control-input" id="types_all"
                             <?= ($typesRaw === 'all' || count($selectedTypes) === count($typeOptions)) ? 'checked' : '' ?>>
                      <label class="custom-control-label" for="types_all"><strong>All types</strong></label>
                    </div>
                    <div class="pl-1" id="typeChecks">
                      <?php foreach ($typeOptions as $key => $label): ?>
                        <div class="custom-control custom-checkbox mb-1">
                          <input type="checkbox" class="custom-control-input nature-type"
                                 id="type_<?= htmlspecialchars($key) ?>"
                                 name="nature_fx_types[]"
                                 value="<?= htmlspecialchars($key) ?>"
                                 <?= in_array($key, $selectedTypes, true) ? 'checked' : '' ?>>
                          <label class="custom-control-label" for="type_<?= htmlspecialchars($key) ?>">
                            <?= htmlspecialchars($label) ?>
                          </label>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>

                  <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Settings
                  </button>
                  <button type="button" class="btn btn-outline-secondary ml-1" id="btnPreviewFx">
                    <i class="fas fa-play"></i> Preview once
                  </button>
                  <button type="button" class="btn btn-outline-primary ml-1" id="btnPreviewAllInsects">
                    <i class="fas fa-bug"></i> Preview all insects
                  </button>
                  <small class="d-block text-muted mt-2">
                    Tip: use Edit / Delete on the insect tiles to the right.
                  </small>
                </form>
              </div>
            </div>

            <div class="card card-outline card-info nf-custom-card" id="custom-sprites">
              <div class="card-header">
                <h3 class="card-title mb-0"><i class="fas fa-image mr-2"></i>Custom GIF &amp; Image Animations</h3>
              </div>
              <div class="card-body">
                <p class="text-muted mb-3">
                  Upload a <strong>GIF</strong> or image (PNG / JPG / WEBP). Set travel direction
                  (<em>from</em> left, <em>to</em> from the right, or <em>random</em>).
                  It will float across every admin page with the nature animations.
                </p>

                <form id="customSpriteForm" enctype="multipart/form-data" method="post" action="sql/nature_fx_asset.php">
                  <input type="hidden" name="action" value="upload">
                  <input type="hidden" name="ajax" value="1">
                  <div class="form-row">
                    <div class="col-md-6">
                      <label for="sprite_file">Image / GIF file</label>
                      <input type="file" name="sprite_file" id="sprite_file" class="form-control-file" required
                             accept=".gif,.png,.jpg,.jpeg,.webp,image/gif,image/png,image/jpeg,image/webp">
                      <div id="spritePreviewBox"><img id="spritePreviewImg" alt="Preview"></div>
                    </div>
                    <div class="col-md-6">
                      <label for="sprite_label">Label (optional)</label>
                      <input type="text" name="sprite_label" id="sprite_label" class="form-control"
                             placeholder="e.g. butterfly gif" maxlength="80">
                    </div>
                    <div class="col-md-4">
                      <label for="sprite_direction">Direction</label>
                      <select name="sprite_direction" id="sprite_direction" class="custom-select">
                        <?php foreach ($directionOptions as $key => $label): ?>
                          <option value="<?= htmlspecialchars($key) ?>" <?= $key === 'random' ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label for="sprite_motion">Motion style</label>
                      <select name="sprite_motion" id="sprite_motion" class="custom-select">
                        <option value="fly" selected>Fly</option>
                        <option value="crawl">Crawl</option>
                        <option value="hop">Hop</option>
                      </select>
                    </div>
                    <div class="col-md-4">
                      <label for="sprite_blend">Background blend</label>
                      <select name="sprite_blend" id="sprite_blend" class="custom-select">
                        <option value="normal" selected>Normal (no blend)</option>
                        <option value="multiply">Multiply (white BG)</option>
                        <option value="screen">Screen (dark BG)</option>
                      </select>
                    </div>
                  </div>
                  <button type="submit" class="btn btn-info mt-2" id="btnAddSprite">
                    <i class="fas fa-plus mr-1"></i> Add animation
                  </button>
                </form>

                <hr>
                <h5 class="mb-3">Your custom sprites</h5>
                <div class="nf-custom-list" id="customSpriteList">
                  <?php if (!$customAssets): ?>
                    <p class="text-muted mb-0" id="customEmptyMsg">No custom GIFs yet. Upload one above.</p>
                  <?php else: ?>
                    <?php foreach ($customAssets as $a): ?>
                      <div class="nf-custom-row" data-id="<?= htmlspecialchars($a['id']) ?>">
                        <img class="nf-custom-thumb" src="<?= htmlspecialchars($a['src']) ?>"
                             alt="<?= htmlspecialchars($a['label'] ?? $a['id']) ?>">
                        <div class="nf-custom-meta">
                          <div class="title"><?= htmlspecialchars($a['label'] ?? $a['id']) ?></div>
                          <div class="sub"><?= htmlspecialchars($a['id']) ?> · <?= htmlspecialchars($a['motion']) ?></div>
                        </div>
                        <div class="nf-custom-controls">
                          <select class="custom-select custom-select-sm js-dir" title="Direction">
                            <?php foreach ($directionOptions as $key => $label): ?>
                              <option value="<?= htmlspecialchars($key) ?>"
                                <?= (($a['direction'] ?? 'random') === $key) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                          <select class="custom-select custom-select-sm js-motion" title="Motion">
                            <?php foreach (['fly' => 'Fly', 'crawl' => 'Crawl', 'hop' => 'Hop'] as $mk => $ml): ?>
                              <option value="<?= $mk ?>" <?= (($a['motion'] ?? 'fly') === $mk) ? 'selected' : '' ?>>
                                <?= $ml ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                          <button type="button" class="btn btn-sm btn-outline-primary js-preview-custom"
                                  title="Preview">
                            <i class="fas fa-play"></i>
                          </button>
                          <button type="button" class="btn btn-sm btn-outline-danger js-delete-custom"
                                  title="Delete">
                            <i class="fas fa-trash"></i>
                          </button>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>How it works</h3>
              </div>
              <div class="card-body">
                <ul class="mb-0 pl-3 text-muted" style="font-size:0.92rem; line-height:1.7;">
                  <li>Runs automatically on <strong>every admin page</strong></li>
                  <li>Built-in insects live in <code>insects/sprites/</code></li>
                  <li>Your uploads go to <code>insects/custom/</code></li>
                  <li><strong>From</strong> = left → right · <strong>To</strong> = right → left · <strong>Random</strong> = either</li>
                  <li><code>pointer-events: none</code> — never blocks UI</li>
                  <li>Respects <em>prefers-reduced-motion</em></li>
                  <li>Keep <em>Insects</em> checked so GIF paths stay active</li>
                </ul>
                <hr>
                <p class="mb-1"><strong>Manage insects</strong></p>
                <p class="mb-2 text-muted small" id="spriteStatsLine">
                  <?= $cfg['enabled'] ? 'On' : 'Off' ?>
                  · <?= htmlspecialchars(ucfirst($cfg['frequency'])) ?>
                  · <?= count($cfg['types']) ?> type(s)
                  · <?= count($cfg['assets'] ?? []) ?> sprite(s)
                  <?php if ($customAssets): ?>
                    · <?= count($customAssets) ?> custom
                  <?php endif; ?>
                </p>
                <div class="nf-manage-grid" id="manageInsectGrid">
                  <?php foreach (($cfg['assets'] ?? []) as $a): ?>
                    <?php
                      $dirLabel = nature_fx_normalize_direction((string) ($a['direction'] ?? 'random'));
                      $isCustom = !empty($a['custom']);
                    ?>
                    <div class="nf-manage-item"
                         data-id="<?= htmlspecialchars($a['id']) ?>"
                         data-custom="<?= $isCustom ? '1' : '0' ?>">
                      <?php if ($isCustom): ?>
                        <span class="nf-badge-custom">custom</span>
                      <?php endif; ?>
                      <img class="js-preview-insect" src="<?= htmlspecialchars($a['src']) ?>"
                           alt="<?= htmlspecialchars($a['label'] ?? $a['id']) ?>"
                           title="Preview · <?= htmlspecialchars(($a['label'] ?? $a['id']) . ' · ' . $a['motion'] . ' · ' . $dirLabel) ?>">
                      <div class="nf-manage-actions">
                        <button type="button" class="btn btn-sm btn-outline-secondary js-edit-insect" title="Edit">
                          <i class="fas fa-pen"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger js-delete-insect" title="<?= $isCustom ? 'Delete' : 'Hide' ?>">
                          <i class="fas fa-trash"></i>
                        </button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>

                <?php $disabledAssets = $cfg['disabled_assets'] ?? []; ?>
                <div id="hiddenInsectsWrap" class="mt-3" style="<?= $disabledAssets ? '' : 'display:none;' ?>">
                  <p class="mb-1 small font-weight-bold text-muted">Hidden insects</p>
                  <div class="nf-manage-grid" id="hiddenInsectGrid">
                    <?php foreach ($disabledAssets as $a): ?>
                      <div class="nf-manage-item is-disabled" data-id="<?= htmlspecialchars($a['id']) ?>">
                        <img src="<?= htmlspecialchars($a['src']) ?>" alt="<?= htmlspecialchars($a['label'] ?? $a['id']) ?>">
                        <div class="nf-manage-actions">
                          <button type="button" class="btn btn-sm btn-outline-success js-restore-insect" title="Restore">
                            <i class="fas fa-undo"></i>
                          </button>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>
    </section>
  </div>
  <?php include 'includes/copyright.php'; ?>
</div>

<!-- Edit insect modal -->
<div class="modal fade" id="editInsectModal" tabindex="-1" role="dialog" aria-labelledby="editInsectModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <form id="editInsectForm">
        <div class="modal-header">
          <h5 class="modal-title" id="editInsectModalLabel">Edit insect</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="edit_sprite_id" name="sprite_id" value="">
          <div class="preview-wrap">
            <img id="edit_sprite_preview" src="" alt="">
          </div>
          <div class="form-group">
            <label for="edit_sprite_label">Label</label>
            <input type="text" class="form-control" id="edit_sprite_label" name="sprite_label" maxlength="80">
          </div>
          <div class="form-group">
            <label for="edit_sprite_direction">Direction</label>
            <select class="custom-select" id="edit_sprite_direction" name="sprite_direction">
              <?php foreach ($directionOptions as $key => $label): ?>
                <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="edit_sprite_motion">Motion</label>
            <select class="custom-select" id="edit_sprite_motion" name="sprite_motion">
              <option value="fly">Fly</option>
              <option value="crawl">Crawl</option>
              <option value="hop">Hop</option>
            </select>
          </div>
          <div class="form-group mb-0">
            <label for="edit_sprite_blend">Background blend</label>
            <select class="custom-select" id="edit_sprite_blend" name="sprite_blend">
              <option value="normal">Normal (no blend)</option>
              <option value="multiply">Multiply (white BG)</option>
              <option value="screen">Screen (dark BG)</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-outline-primary" id="btnEditPreview">
            <i class="fas fa-play"></i> Preview
          </button>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Save
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include 'includes/footer-links.php'; ?>
<script>
(function () {
  var all = document.getElementById('types_all');
  var checks = document.querySelectorAll('.nature-type');
  var spriteAssets = <?= json_encode($cfg['assets'] ?? [], JSON_UNESCAPED_SLASHES) ?>;
  var disabledAssets = <?= json_encode($cfg['disabled_assets'] ?? [], JSON_UNESCAPED_SLASHES) ?>;
  var $alert = $('#settingsAlert');

  function showAlert(type, message) {
    $alert
      .removeClass('alert-success alert-danger alert-warning alert-info')
      .addClass('alert-' + type)
      .text(message)
      .show();
    $('html, body').animate({ scrollTop: Math.max(0, $alert.offset().top - 80) }, 200);
  }

  function syncFromAll() {
    var on = all.checked;
    checks.forEach(function (c) { c.checked = on; });
  }
  function syncToAll() {
    var n = 0;
    checks.forEach(function (c) { if (c.checked) n++; });
    all.checked = n === checks.length;
  }

  function ensureFx(thenFn) {
    if (window.CRMNatureFx) {
      window.CRMNatureFx.assets = spriteAssets;
      if (window.CRMNatureFx.kinds.indexOf('insect') === -1 && spriteAssets.length) {
        window.CRMNatureFx.kinds.push('insect');
      }
      window.CRMNatureFx.ensureLayer();
      thenFn(window.CRMNatureFx);
      return;
    }
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'custom/nature-fx.css?v=6';
    document.head.appendChild(link);
    window.CRM_NATURE_FX = {
      enabled: true,
      frequency: document.getElementById('nature_fx_frequency').value || 'medium',
      types: ['insects'],
      assets: spriteAssets
    };
    var s = document.createElement('script');
    s.src = 'custom/nature-fx.js?v=15';
    s.onload = function () {
      if (window.CRMNatureFx) {
        window.CRMNatureFx.init(window.CRM_NATURE_FX);
        thenFn(window.CRMNatureFx);
      }
    };
    document.body.appendChild(s);
  }

  function findAsset(id) {
    var i;
    for (i = 0; i < spriteAssets.length; i++) {
      if (spriteAssets[i].id === id) return spriteAssets[i];
    }
    for (i = 0; i < disabledAssets.length; i++) {
      if (disabledAssets[i].id === id) return disabledAssets[i];
    }
    return null;
  }

  function previewAsset(asset) {
    if (!asset) return;
    ensureFx(function (fx) {
      fx.showPreviewBanner('Preview · ' + (asset.label || asset.id) + ' · ' + (asset.direction || 'random'));
      fx.spawnInsect(asset, { preview: true, lane: 0, total: 1 });
      setTimeout(function () { fx.hidePreviewBanner(); }, 16000);
    });
  }

  function openEditModal(asset) {
    if (!asset) return;
    $('#edit_sprite_id').val(asset.id);
    $('#edit_sprite_label').val(asset.label || asset.id);
    $('#edit_sprite_direction').val(asset.direction || 'random');
    $('#edit_sprite_motion').val(asset.motion || 'fly');
    $('#edit_sprite_blend').val(asset.blend || (asset.custom ? 'normal' : 'multiply'));
    $('#edit_sprite_preview').attr('src', asset.src);
    $('#editInsectModalLabel').text('Edit · ' + (asset.label || asset.id));
    $('#editInsectModal').modal('show');
  }

  function syncCustomRow(asset) {
    var $row = $('#customSpriteList .nf-custom-row[data-id="' + asset.id + '"]');
    if (!$row.length) return;
    $row.find('.title').text(asset.label || asset.id);
    $row.find('.sub').text(asset.id + ' · ' + (asset.motion || 'fly'));
    $row.find('.js-dir').val(asset.direction || 'random');
    $row.find('.js-motion').val(asset.motion || 'fly');
  }

  all.addEventListener('change', syncFromAll);
  checks.forEach(function (c) { c.addEventListener('change', syncToAll); });

  document.getElementById('btnPreviewFx').addEventListener('click', function () {
    ensureFx(function (fx) { fx.spawnBurst(); });
  });

  document.getElementById('btnPreviewAllInsects').addEventListener('click', function () {
    ensureFx(function (fx) { fx.previewAllInsects(); });
  });

  /* Manage grid: preview / edit / delete */
  $('#manageInsectGrid').on('click', '.js-preview-insect', function () {
    previewAsset(findAsset($(this).closest('.nf-manage-item').data('id')));
  });

  $('#manageInsectGrid').on('click', '.js-edit-insect', function () {
    openEditModal(findAsset($(this).closest('.nf-manage-item').data('id')));
  });

  $('#manageInsectGrid').on('click', '.js-delete-insect', function () {
    var $item = $(this).closest('.nf-manage-item');
    var id = $item.data('id');
    var isCustom = String($item.data('custom')) === '1';
    var msg = isCustom
      ? 'Permanently delete this custom GIF/image?'
      : 'Hide this built-in insect? You can restore it later.';
    if (!id || !window.confirm(msg)) return;

    $.ajax({
      url: 'sql/nature_fx_asset.php',
      method: 'POST',
      dataType: 'json',
      data: { action: 'delete', ajax: 1, sprite_id: id },
      success: function (res) {
        if (!(res && res.success)) {
          showAlert('danger', (res && res.message) ? res.message : 'Delete failed.');
          return;
        }
        var asset = findAsset(id);
        $item.fadeOut(150, function () { $item.remove(); });
        spriteAssets = spriteAssets.filter(function (a) { return a.id !== id; });

        if (isCustom) {
          $('#customSpriteList .nf-custom-row[data-id="' + id + '"]').remove();
          if (!$('#customSpriteList .nf-custom-row').length) {
            $('#customSpriteList').html('<p class="text-muted mb-0" id="customEmptyMsg">No custom GIFs yet. Upload one above.</p>');
          }
        } else if (asset) {
          asset.disabled = true;
          disabledAssets.push(asset);
          $('#hiddenInsectsWrap').show();
          $('#hiddenInsectGrid').append(
            '<div class="nf-manage-item is-disabled" data-id="' + id + '">' +
              '<img src="' + $('<div>').text(asset.src).html() + '" alt="">' +
              '<div class="nf-manage-actions">' +
                '<button type="button" class="btn btn-sm btn-outline-success js-restore-insect" title="Restore"><i class="fas fa-undo"></i></button>' +
              '</div>' +
            '</div>'
          );
        }
        showAlert('success', res.message || 'Done.');
      },
      error: function () {
        showAlert('danger', 'Could not delete insect.');
      }
    });
  });

  $('#hiddenInsectGrid').on('click', '.js-restore-insect', function () {
    var $item = $(this).closest('.nf-manage-item');
    var id = $item.data('id');
    if (!id) return;
    $.ajax({
      url: 'sql/nature_fx_asset.php',
      method: 'POST',
      dataType: 'json',
      data: { action: 'restore', ajax: 1, sprite_id: id },
      success: function (res) {
        if (!(res && res.success)) {
          showAlert('danger', (res && res.message) ? res.message : 'Restore failed.');
          return;
        }
        showAlert('success', res.message || 'Restored.');
        setTimeout(function () { window.location.reload(); }, 500);
      },
      error: function () {
        showAlert('danger', 'Could not restore insect.');
      }
    });
  });

  $('#btnEditPreview').on('click', function () {
    var id = $('#edit_sprite_id').val();
    var asset = findAsset(id);
    if (!asset) return;
    var draft = Object.assign({}, asset, {
      label: $('#edit_sprite_label').val() || asset.label,
      direction: $('#edit_sprite_direction').val(),
      motion: $('#edit_sprite_motion').val(),
      blend: $('#edit_sprite_blend').val()
    });
    previewAsset(draft);
  });

  $('#editInsectForm').on('submit', function (e) {
    e.preventDefault();
    var id = $('#edit_sprite_id').val();
    if (!id) return;
    $.ajax({
      url: 'sql/nature_fx_asset.php',
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'update',
        ajax: 1,
        sprite_id: id,
        sprite_label: $('#edit_sprite_label').val(),
        sprite_direction: $('#edit_sprite_direction').val(),
        sprite_motion: $('#edit_sprite_motion').val(),
        sprite_blend: $('#edit_sprite_blend').val()
      },
      success: function (res) {
        if (!(res && res.success)) {
          showAlert('danger', (res && res.message) ? res.message : 'Update failed.');
          return;
        }
        var asset = findAsset(id);
        if (asset) {
          asset.label = $('#edit_sprite_label').val() || asset.label;
          asset.direction = $('#edit_sprite_direction').val();
          asset.motion = $('#edit_sprite_motion').val();
          asset.blend = $('#edit_sprite_blend').val();
          syncCustomRow(asset);
          var $tile = $('#manageInsectGrid .nf-manage-item[data-id="' + id + '"]');
          $tile.find('img').attr('title', 'Preview · ' + asset.label + ' · ' + asset.motion + ' · ' + asset.direction);
        }
        $('#editInsectModal').modal('hide');
        showAlert('success', res.message || 'Updated.');
      },
      error: function () {
        showAlert('danger', 'Could not update insect.');
      }
    });
  });

  /* Local file preview */
  $('#sprite_file').on('change', function () {
    var f = this.files && this.files[0];
    if (!f) {
      $('#spritePreviewBox').hide();
      return;
    }
    var url = URL.createObjectURL(f);
    $('#spritePreviewImg').attr('src', url);
    $('#spritePreviewBox').show();
    if (!$('#sprite_label').val()) {
      $('#sprite_label').val(f.name.replace(/\.[^.]+$/, ''));
    }
  });

  /* Upload custom sprite */
  $('#customSpriteForm').on('submit', function (e) {
    e.preventDefault();
    var $btn = $('#btnAddSprite').prop('disabled', true);
    var fd = new FormData(this);
    $.ajax({
      url: 'sql/nature_fx_asset.php',
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      dataType: 'json',
      success: function (res) {
        if (res && res.success) {
          showAlert('success', res.message || 'Added.');
          setTimeout(function () { window.location.href = 'settings.php#custom-sprites'; }, 600);
        } else {
          showAlert('danger', (res && res.message) ? res.message : 'Upload failed.');
          $btn.prop('disabled', false);
        }
      },
      error: function (xhr) {
        var msg = 'Upload failed.';
        try {
          var res = JSON.parse(xhr.responseText);
          if (res && res.message) msg = res.message;
        } catch (err) {}
        showAlert('danger', msg);
        $btn.prop('disabled', false);
      }
    });
  });

  /* Inline custom list update / delete (kept for convenience) */
  $('#customSpriteList').on('change', '.js-dir, .js-motion', function () {
    var $row = $(this).closest('.nf-custom-row');
    var id = $row.data('id');
    if (!id) return;
    $.ajax({
      url: 'sql/nature_fx_asset.php',
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'update',
        ajax: 1,
        sprite_id: id,
        sprite_direction: $row.find('.js-dir').val(),
        sprite_motion: $row.find('.js-motion').val()
      },
      success: function (res) {
        if (res && res.success) {
          var asset = findAsset(id);
          if (asset) {
            asset.direction = $row.find('.js-dir').val();
            asset.motion = $row.find('.js-motion').val();
          }
          showAlert('success', res.message || 'Updated.');
        } else {
          showAlert('danger', (res && res.message) ? res.message : 'Update failed.');
        }
      },
      error: function () {
        showAlert('danger', 'Could not update sprite.');
      }
    });
  });

  $('#customSpriteList').on('click', '.js-preview-custom', function () {
    previewAsset(findAsset($(this).closest('.nf-custom-row').data('id')));
  });

  $('#customSpriteList').on('click', '.js-delete-custom', function () {
    var $row = $(this).closest('.nf-custom-row');
    var id = $row.data('id');
    if (!id || !window.confirm('Remove this GIF/image animation?')) return;
    $.ajax({
      url: 'sql/nature_fx_asset.php',
      method: 'POST',
      dataType: 'json',
      data: { action: 'delete', ajax: 1, sprite_id: id },
      success: function (res) {
        if (res && res.success) {
          $row.slideUp(150, function () {
            $row.remove();
            if (!$('#customSpriteList .nf-custom-row').length) {
              $('#customSpriteList').html('<p class="text-muted mb-0" id="customEmptyMsg">No custom GIFs yet. Upload one above.</p>');
            }
          });
          $('#manageInsectGrid .nf-manage-item[data-id="' + id + '"]').remove();
          spriteAssets = spriteAssets.filter(function (a) { return a.id !== id; });
          showAlert('success', res.message || 'Removed.');
        } else {
          showAlert('danger', (res && res.message) ? res.message : 'Delete failed.');
        }
      },
      error: function () {
        showAlert('danger', 'Could not delete sprite.');
      }
    });
  });

  if (window.location.hash === '#custom-sprites') {
    setTimeout(function () {
      var el = document.getElementById('custom-sprites');
      if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 200);
  }
})();
</script>
</body>
</html>
