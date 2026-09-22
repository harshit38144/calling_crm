$(document).ready(function () {
  $('body').on('click', '.has-treeview', function () {
    var current = $(this);
    $('.has-treeview').each(function () {
      if ($(this).not(current).hasClass('menu-open')) {
        $(this).removeClass('menu-open').find('.nav-treeview').slideUp();
      }
    });
  });

  /**
   * Desktop: pin/unpin with the same CSS path as hover (keeps sidebar-collapse).
   * Mobile: use AdminLTE overlay (sidebar-open).
   */
  function isMobileSidebar() {
    return window.matchMedia('(max-width: 767.98px)').matches;
  }

  function setPinned(pinned) {
    var $body = $('body');
    if (pinned) {
      // Fully open: remove collapse so labels stay visible without hover
      $body
        .addClass('crm-sidebar-pinned')
        .removeClass('sidebar-collapse sidebar-open sidebar-closed');
    } else {
      // Icon rail: collapse on; hover still expands temporarily
      $body
        .removeClass('crm-sidebar-pinned sidebar-open sidebar-closed')
        .addClass('sidebar-collapse');
    }
    $('#crm-sidebar-toggle').attr('aria-expanded', pinned ? 'true' : 'false');
  }

  $(document).on('click', '#crm-sidebar-toggle', function (e) {
    e.preventDefault();
    var $body = $('body');

    if (isMobileSidebar()) {
      setPinned(false);
      if ($body.hasClass('sidebar-open')) {
        $body.removeClass('sidebar-open').addClass('sidebar-closed');
      } else {
        $body.addClass('sidebar-open').removeClass('sidebar-closed');
      }
      return;
    }

    setPinned(!$body.hasClass('crm-sidebar-pinned'));
  });

  $(document).on('click', '#sidebar-overlay', function () {
    $('body').removeClass('sidebar-open').addClass('sidebar-closed');
  });

  $(window).on('resize', function () {
    if (!isMobileSidebar()) {
      $('body').removeClass('sidebar-open sidebar-closed');
      if (!$('body').hasClass('crm-sidebar-pinned')) {
        $('body').addClass('sidebar-collapse');
      }
    } else {
      setPinned(false);
    }
  });

  /* ===== Light / Dark theme ===== */
  var THEME_KEY = 'crm-theme';

  function getTheme() {
    try {
      return localStorage.getItem(THEME_KEY) || 'dark';
    } catch (e) {
      return 'dark';
    }
  }

  function applyTheme(theme) {
    var isLight = theme === 'light';
    document.documentElement.classList.toggle('crm-theme-light', isLight);
    try {
      localStorage.setItem(THEME_KEY, isLight ? 'light' : 'dark');
    } catch (e) {}
    $('#crm-theme-toggle')
      .attr('aria-pressed', isLight ? 'true' : 'false')
      .attr('title', isLight ? 'Switch to dark mode' : 'Switch to light mode');
  }

  applyTheme(getTheme());

  $(document).on('click', '#crm-theme-toggle', function (e) {
    e.preventDefault();
    applyTheme(getTheme() === 'light' ? 'dark' : 'light');
  });
});
