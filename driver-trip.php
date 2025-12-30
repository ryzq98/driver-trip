<?php
/*
Plugin Name: Driver Trip System
Description: Driver trip submission + Logistic Manager portal + Client List (Master Matrix) with locked fields + soft delete
Version: 2.7.1
Author: Your Company
*/

if (!defined('ABSPATH')) exit;

/* =========================================================
   HELPERS
========================================================= */
function dts_user_has_role($role) {
    if (!is_user_logged_in()) return false;
    $u = wp_get_current_user();
    if (!$u || empty($u->roles)) return false;
    return in_array($role, (array)$u->roles, true);
}
function dts_is_driver() { return dts_user_has_role('driver'); }
function dts_is_lm()     { return dts_user_has_role('logistic_manager'); }
function dts_is_admin()  { return is_user_logged_in() && current_user_can('manage_options'); }

function dts_can_submit_trip() {
    return is_user_logged_in() && (dts_is_driver() || dts_is_lm() || dts_is_admin());
}
function dts_is_lm_admin() {
    return is_user_logged_in() && (dts_is_lm() || dts_is_admin());
}
function dts_matrix_is_complete($origin, $dest, $client, $unit) {
    return (trim((string)$origin) !== '' && trim((string)$dest) !== '' && trim((string)$client) !== '' && trim((string)$unit) !== '');
}

/* =========================================================
   (OPTIONAL) reduce "logged-in cached as logged-out" issues
========================================================= */
add_action('send_headers', function () {
    if (is_user_logged_in()) {
        nocache_headers();
    }
});

/* =========================================================
   ACTIVATION: ROLES + TABLES
========================================================= */
register_activation_hook(__FILE__, function () {
    dts_ensure_roles();
    dts_ensure_tables();
});

function dts_ensure_roles() {
    if (!get_role('driver')) add_role('driver', 'Driver', ['read' => true]);
    if (!get_role('logistic_manager')) {
        add_role('logistic_manager', 'Logistic Manager', [
            'read'       => true,
            'edit_posts' => true,
        ]);
    }
}

function dts_ensure_tables() {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    $tTrips  = $wpdb->prefix . 'driver_trips';
    $tMatrix = $wpdb->prefix . 'dts_master_matrix';

    $wpdb->query("CREATE TABLE IF NOT EXISTS $tTrips (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        trip_date DATE NOT NULL,
        origin VARCHAR(191) NOT NULL,
        destination VARCHAR(191) NOT NULL,
        weight DECIMAL(12,2) NOT NULL DEFAULT 0,
        bill_number VARCHAR(191) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY trip_date (trip_date)
    ) $charset;");

    $wpdb->query("CREATE TABLE IF NOT EXISTS $tMatrix (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        origin VARCHAR(191) NOT NULL,
        destination VARCHAR(191) NOT NULL,
        client_name VARCHAR(191) NOT NULL,
        unit_name VARCHAR(191) NOT NULL,
        rate DECIMAL(12,2) NULL DEFAULT NULL,
        is_complete TINYINT(1) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY active (active),
        KEY is_complete (is_complete),
        KEY lookup (origin, destination, client_name, unit_name)
    ) $charset;
");

    // Ensure schema is updated to latest shape
    dbDelta("CREATE TABLE $tTrips (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        trip_date DATE NOT NULL,
        origin VARCHAR(191) NOT NULL,
        destination VARCHAR(191) NOT NULL,
        weight DECIMAL(12,2) NOT NULL DEFAULT 0,
        bill_number VARCHAR(191) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY trip_date (trip_date)
    ) $charset;");

    dbDelta("CREATE TABLE $tMatrix (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        origin VARCHAR(191) NOT NULL,
        destination VARCHAR(191) NOT NULL,
        client_name VARCHAR(191) NOT NULL,
        unit_name VARCHAR(191) NOT NULL,
        rate DECIMAL(12,2) NULL DEFAULT NULL,
        is_complete TINYINT(1) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY active (active),
        KEY is_complete (is_complete),
        KEY lookup (origin, destination, client_name, unit_name)
    ) $charset;");

    // Backfill completeness
    $wpdb->query("UPDATE $tMatrix
                  SET is_complete = CASE
                      WHEN TRIM(origin)<>'' AND TRIM(destination)<>'' AND TRIM(client_name)<>'' AND TRIM(unit_name)<>'' THEN 1
                      ELSE 0
                  END");
}

/* =========================================================
   INIT: Ensure roles exist (safe)
========================================================= */
add_action('init', function () {
    dts_ensure_roles();
}, 1);

/* =========================================================
   SCHEMA SAFETY: drop old UNIQUE index if present, ensure keys
========================================================= */
add_action('init', function () {
    global $wpdb;
    dts_ensure_tables();
    $tMatrix = $wpdb->prefix . 'dts_master_matrix';

    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tMatrix));
    if (!$exists) return;

    $col = $wpdb->get_var("SHOW COLUMNS FROM $tMatrix LIKE 'is_complete'");
    if (!$col) {
        $wpdb->query("ALTER TABLE $tMatrix ADD COLUMN is_complete TINYINT(1) NOT NULL DEFAULT 0 AFTER rate");
        $wpdb->query("ALTER TABLE $tMatrix ADD KEY is_complete (is_complete)");
        $wpdb->query("UPDATE $tMatrix
                      SET is_complete = CASE
                          WHEN TRIM(origin)<>'' AND TRIM(destination)<>'' AND TRIM(client_name)<>'' AND TRIM(unit_name)<>'' THEN 1
                          ELSE 0
                      END");
    }

    $uniq = $wpdb->get_results("SHOW INDEX FROM $tMatrix WHERE Key_name='uniq_row'");
    if (!empty($uniq)) {
        $wpdb->query("ALTER TABLE $tMatrix DROP INDEX uniq_row");
    }

    $lookup = $wpdb->get_results("SHOW INDEX FROM $tMatrix WHERE Key_name='lookup'");
    if (empty($lookup)) {
        $wpdb->query("ALTER TABLE $tMatrix ADD KEY lookup (origin, destination, client_name, unit_name)");
    }
}, 2);

/* =========================================================
   UI: STYLES
========================================================= */
add_action('wp_head', function () {
    if (!is_user_logged_in()) return;
    if (!(dts_is_driver() || dts_is_lm() || dts_is_admin())) return;

    echo '<style>
      .dts-wrap { max-width: 1100px; margin: 0 auto; padding: 16px; }
      .dts-card { background:#fff; border:1px solid #eee; border-radius:12px; padding:16px; box-shadow:0 4px 14px rgba(0,0,0,.04); }
      .dts-title { margin: 0 0 12px; font-size: 1.2rem; font-weight: 700; }
      .dts-muted { opacity: .75; }

      .dts-form p { margin: 0 0 14px; }
      .dts-form label { display:block; font-weight:600; margin-bottom:6px; }
      .dts-form input[type="text"],
      .dts-form input[type="number"],
      .dts-form input[type="date"],
      .dts-form select {
        width:100%;
        padding:12px 12px;
        border:1px solid #dcdcdc;
        border-radius:10px;
        font-size:16px;
        line-height:1.2;
        box-sizing:border-box;
      }
      .dts-btn, .dts-form input[type="submit"] {
        display:inline-block;
        padding:12px 16px;
        border-radius:10px;
        border:0;
        background:#111;
        color:#fff;
        font-weight:600;
        cursor:pointer;
      }

      .dts-table { width:100%; border-collapse:collapse; }
      .dts-table th, .dts-table td { padding:10px 8px; border-bottom:1px solid #eee; text-align:left; vertical-align:top; }
      .dts-table th { font-weight:700; background:#fafafa; }

      .dts-grid-wrap { overflow:auto; }
      .dts-grid { width:100%; border-collapse:collapse; min-width: 900px; table-layout:fixed; }
      .dts-grid th, .dts-grid td { border:1px solid #000; padding:10px; vertical-align:middle; overflow:hidden; text-overflow:ellipsis; }
      .dts-grid thead th { font-weight:700; text-align:center; }
      .dts-grid .center { text-align:center; }
      .dts-plus { font-size:22px; font-weight:700; cursor:pointer; user-select:none; }

      .dts-cell-input{
        width:100%;
        border:0;
        background:transparent;
        padding:6px 8px;
        box-sizing:border-box;
        font-size:16px;
      }
      .dts-cell-input:focus{
        outline:2px solid #111;
        border-radius:6px;
      }

      /* Center Units and Rates */
      .dts-grid td:nth-child(5), .dts-grid th:nth-child(5) { text-align:center; }
      .dts-grid td:nth-child(6), .dts-grid th:nth-child(6) { text-align:center; }

      .dts-grid .numcell { position:relative; text-align:center; }
      .dts-grid .row-actions {
        position:absolute; top:50%; right:8px; transform:translateY(-50%);
        display:none;
      }
      .dts-grid .numcell:hover .row-actions { display:block; }
      .dts-grid .mini-btn {
        border:1px solid #ddd; background:#fff; border-radius:8px;
        padding:6px 10px; cursor:pointer; font-weight:600;
      }

      @media (max-width: 768px) {
        .dts-wrap { padding: 12px; }
      }
    </style>';
});

/* =========================================================
   FRONTEND JS (CRITICAL FIX: no inline script in shortcode)
   - prevents &#038; encoding and "Invalid or unexpected token"
========================================================= */
add_action('wp_enqueue_scripts', function () {
    if (!is_user_logged_in()) return;

    // register empty handle
    wp_register_script('dts-frontend', '', [], '2.7.1', true);
    wp_enqueue_script('dts-frontend');

    $ajax = admin_url('admin-ajax.php');

    $js = <<<JS
(function(){
  function postForm(ajaxUrl, params){
    var fd = new FormData();
    for (var k in params) fd.append(k, params[k]);
    return fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd })
      .then(function(r){ return r.text(); })
      .then(function(txt){
        try { return JSON.parse(txt); }
        catch(e){ return { success:false, data:{ message: txt } }; }
      });
  }

  function initTripForms(){
    var forms = document.querySelectorAll('[data-dts-trip-form="1"]');
    forms.forEach(function(root){
      var sel = root.querySelector('select[data-dts-master="1"]');
      var o = root.querySelector('input[data-dts-origin="1"]');
      var d = root.querySelector('input[data-dts-destination="1"]');
      if (!sel || !o || !d) return;

      function sync(){
        var opt = sel.options[sel.selectedIndex];
        if (!opt) return;
        o.value = opt.getAttribute('data-origin') || '';
        d.value = opt.getAttribute('data-destination') || '';
      }
      sel.addEventListener('change', sync);
      sync();
    });
  }

  function initClientLists(){
    var grids = document.querySelectorAll('table[data-dts-clientlist="1"]');
    grids.forEach(function(grid){
      var ajaxUrl = grid.getAttribute('data-ajax') || '';
      var nonce = grid.getAttribute('data-nonce') || '';
      var bodyId = grid.getAttribute('data-body') || '';
      var plusId = grid.getAttribute('data-plus') || '';

      var body = document.getElementById(bodyId);
      var plusRow = document.getElementById(plusId);
      if (!ajaxUrl || !nonce || !body || !plusRow) return;

      function renumber(){
        var nums = body.querySelectorAll('tr[data-id] .row-num');
        var i=1;
        nums.forEach(function(n){ n.textContent = i++; });
      }

      function allEmpty(tr){
        var inputs = tr.querySelectorAll('input');
        for (var i=0; i<inputs.length; i++){
          if ((inputs[i].value || '').trim() !== '') return false;
        }
        return true;
      }

      function requiredFilled(tr){
        var origin = (tr.querySelector('input[data-col="origin"]').value || '').trim();
        var destination = (tr.querySelector('input[data-col="destination"]').value || '').trim();
        var client_name = (tr.querySelector('input[data-col="client_name"]').value || '').trim();
        var unit_name = (tr.querySelector('input[data-col="unit_name"]').value || '').trim();
        return (!!origin && !!destination && !!client_name && !!unit_name);
      }

      function showDraftRow(){
        if (body.querySelector('tr.dts-draft')) return;

        var emptyRow = body.querySelector('.dts-empty-row');
        if (emptyRow) emptyRow.remove();

        var nextNum = (body.querySelectorAll('tr[data-id]').length || 0) + 1;

        var tr = document.createElement('tr');
        tr.className = 'dts-draft';
        tr.innerHTML =
          '<td class="numcell"><span class="row-num">'+ nextNum +'</span></td>'+
          '<td><input class="dts-cell-input dts-draft-input" data-col="origin" type="text" placeholder="Origin"></td>'+
          '<td><input class="dts-cell-input dts-draft-input" data-col="destination" type="text" placeholder="Destination"></td>'+
          '<td><input class="dts-cell-input dts-draft-input" data-col="client_name" type="text" placeholder="Client"></td>'+
          '<td><input class="dts-cell-input dts-draft-input" data-col="unit_name" type="text" placeholder="Unit" style="text-align:center;"></td>'+
          '<td><input class="dts-cell-input dts-draft-input" data-col="rate" type="number" step="0.01" placeholder="(blank)" style="text-align:center;"></td>';

        body.insertBefore(tr, plusRow);

        var first = tr.querySelector('input[data-col="origin"]');
        if (first) first.focus();

        var cancelIfEmpty = function(e){
          if (!body.contains(tr)) { document.removeEventListener('mousedown', cancelIfEmpty, true); return; }
          if (tr.contains(e.target)) return;
          if (allEmpty(tr)){
            tr.remove();
            document.removeEventListener('mousedown', cancelIfEmpty, true);

            if (!body.querySelector('tr[data-id]')){
              var emp = document.createElement('tr');
              emp.className = 'dts-empty-row';
              emp.innerHTML = '<td></td><td></td><td></td><td></td><td></td><td></td>';
              body.insertBefore(emp, plusRow);
            }
          }
        };
        document.addEventListener('mousedown', cancelIfEmpty, true);

        var creating = false;

        function lockRowToSaved(tr, d){
          tr.classList.remove('dts-draft');
          tr.setAttribute('data-id', d.id);

          tr.innerHTML =
            '<td class="numcell">'+
              '<span class="row-num"></span>'+
              '<span class="row-actions"><button class="mini-btn dts-del" type="button" title="Delete">Del</button></span>'+
            '</td>'+
            '<td><input class="dts-cell-input" data-col="origin" type="text" value="'+ d.origin +'" readonly></td>'+
            '<td><input class="dts-cell-input" data-col="destination" type="text" value="'+ d.destination +'" readonly></td>'+
            '<td><input class="dts-cell-input" data-col="client_name" type="text" value="'+ d.client_name +'" readonly></td>'+
            '<td><input class="dts-cell-input" data-col="unit_name" type="text" value="'+ d.unit_name +'" readonly style="text-align:center;"></td>'+
            '<td><input class="dts-cell-input dts-edit" data-col="rate" type="number" step="0.01" value="'+ (d.rate||'') +'" placeholder="(blank)" style="text-align:center;"></td>';

          renumber();
        }

        function tryCreate(){
          if (creating) return;
          if (!body.contains(tr)) return;
          if (!requiredFilled(tr)) return;

          creating = true;

          var origin = (tr.querySelector('input[data-col="origin"]').value || '').trim();
          var destination = (tr.querySelector('input[data-col="destination"]').value || '').trim();
          var client_toggle = (tr.querySelector('input[data-col="client_name"]').value || '').trim();
          var unit_toggle = (tr.querySelector('input[data-col="unit_name"]').value || '').trim();
          var rate = (tr.querySelector('input[data-col="rate"]').value || '').trim();

          postForm(ajaxUrl, {
            action:'dts_create_master_row',
            nonce: nonce,
            origin: origin,
            destination: destination,
            client_name: client_toggle,
            unit_name: unit_toggle,
            rate: rate
          }).then(function(out){
            if (!out || !out.success){
              creating = false;
              alert((out && out.data && out.data.message) ? out.data.message : 'Failed to create row.');
              return;
            }
            lockRowToSaved(tr, out.data);
          });
        }

        tr.addEventListener('keydown', function(e){
          if (e.target.tagName !== 'INPUT') return;
          if (e.key === 'Enter'){
            e.preventDefault();
            tryCreate();
            e.target.blur();
          }
          if (e.key === 'Escape' && allEmpty(tr)) tr.remove();
        });

        tr.addEventListener('focusout', function(){
          setTimeout(function(){ tryCreate(); }, 0);
        });
      }

      plusRow.addEventListener('click', showDraftRow);

      function saveRate(tr, input){
        var id = tr.getAttribute('data-id');
        var val = (input.value || '').trim();

        postForm(ajaxUrl, { action:'dts_update_master_rate', nonce: nonce, id: id, rate: val })
          .then(function(out){
            if (!out || !out.success){
              alert((out && out.data && out.data.message) ? out.data.message : 'Failed to save rate.');
              return;
            }
            input.value = out.data.rate || '';
          });
      }

      body.addEventListener('keydown', function(e){
        var input = e.target;
        if (!input.classList.contains('dts-edit')) return;
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var tr = input.closest('tr[data-id]');
        saveRate(tr, input);
        input.blur();
      });

      body.addEventListener('blur', function(e){
        var input = e.target;
        if (!input.classList.contains('dts-edit')) return;
        var tr = input.closest('tr[data-id]');
        saveRate(tr, input);
      }, true);

      body.addEventListener('click', function(e){
        if (!e.target.classList.contains('dts-del')) return;
        var tr = e.target.closest('tr[data-id]');
        if (!tr) return;
        if (!confirm('Delete this row?')) return;

        var id = tr.getAttribute('data-id');
        postForm(ajaxUrl, { action:'dts_delete_master_row', nonce: nonce, id: id })
          .then(function(out){
            if (!out || !out.success){
              alert((out && out.data && out.data.message) ? out.data.message : 'Failed to delete.');
              return;
            }
            tr.remove();
            renumber();

            if (!body.querySelector('tr[data-id]')){
              var emp = document.createElement('tr');
              emp.className = 'dts-empty-row';
              emp.innerHTML = '<td></td><td></td><td></td><td></td><td></td><td></td>';
              body.insertBefore(emp, plusRow);
            }
          });
      });

      renumber();
      // console.log('[DTS] Client List JS bound OK', bodyId);
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    initTripForms();
    initClientLists();
  });
})();
JS;

    wp_add_inline_script('dts-frontend', $js, 'after');
});

/* =========================================================
   SHORTCODE: USER MENU  [user_menu]
========================================================= */
add_shortcode('user_menu', function () {
    if (!is_user_logged_in()) return '<a href="' . esc_url(wp_login_url()) . '">Login</a>';

    $user = wp_get_current_user();
    $name = trim($user->first_name . ' ' . $user->last_name);
    if (!$name) $name = $user->user_login;

    return '
    <style>
      .user-menu-wrapper { position:relative; display:inline-block; font-weight:600; }
      .user-menu-wrapper details summary { list-style:none; cursor:pointer; }
      .user-menu-wrapper details summary::-webkit-details-marker { display:none; }
      .user-menu-wrapper details summary::after { content:"▼"; font-size:.7em; margin-left:4px; }
      .user-menu-dropdown {
        position:absolute; right:0; margin-top:6px;
        background:#fff; border:1px solid #ddd;
        padding:8px 12px; border-radius:6px;
        box-shadow:0 4px 10px rgba(0,0,0,.08);
        white-space:nowrap; z-index:9999;
      }
      .user-menu-dropdown a { text-decoration:none; color:#000; display:block; }
    </style>

    <div class="user-menu-wrapper">
      <details>
        <summary>' . esc_html($name) . '</summary>
        <div class="user-menu-dropdown">
          <a href="' . esc_url(wp_logout_url(home_url())) . '">Logout</a>
        </div>
      </details>
    </div>';
});

/* =========================================================
   SAVE TRIP DATA (POST)
========================================================= */
add_action('template_redirect', function () {
    if (!is_user_logged_in()) return;
    if (empty($_POST['save_trip'])) return;
    if (!dts_can_submit_trip()) return;

    if (empty($_POST['dts_nonce']) || !wp_verify_nonce($_POST['dts_nonce'], 'dts_save_trip')) {
        wp_die('Security check failed.');
    }

    global $wpdb;
    dts_ensure_tables();
    $tTrips  = $wpdb->prefix . 'driver_trips';
    $tMatrix = $wpdb->prefix . 'dts_master_matrix';

    $master_id = (int)($_POST['master_id'] ?? 0);
    if ($master_id <= 0) {
        wp_die('Please select a Client List row before submitting.');
    }

    $master = $wpdb->get_row($wpdb->prepare(
        "SELECT origin, destination FROM $tMatrix WHERE id=%d AND active=1 AND is_complete=1",
        $master_id
    ));

    if (!$master) {
        wp_die('Invalid or inactive Client List selection.');
    }

    $ok = $wpdb->insert($tTrips, [
        'user_id'     => get_current_user_id(),
        'trip_date'   => sanitize_text_field($_POST['trip_date']),
        'origin'      => sanitize_text_field($master->origin),
        'destination' => sanitize_text_field($master->destination),
        'weight'      => floatval($_POST['weight']),
        'bill_number' => sanitize_text_field($_POST['bill_number']),
    ], ['%d','%s','%s','%s','%f','%s']);

    if ($ok === false) {
        wp_die('DB error saving trip: ' . esc_html($wpdb->last_error));
    }

    wp_safe_redirect(remove_query_arg([]));
    exit;
});

/* =========================================================
   SHORTCODE: LM TRIPS VIEW  [lm_trips]
========================================================= */
add_shortcode('lm_trips', function () {
    if (!is_user_logged_in() || !(dts_is_lm() || dts_is_admin())) {
        return '<div class="dts-wrap"><div class="dts-card"><p>Access denied.</p></div></div>';
    }

    global $wpdb;
    $tTrips = $wpdb->prefix . 'driver_trips';
    $rows = $wpdb->get_results("SELECT * FROM $tTrips ORDER BY trip_date DESC, id DESC LIMIT 500");

    $out = '<div class="dts-wrap"><div class="dts-card">';
    $out .= '<h2 class="dts-title">All Driver Trips</h2>';
    $out .= '<p class="dts-muted">Showing latest 500 records.</p>';

    if (!$rows) {
        $out .= '<p>No trips recorded yet.</p></div></div>';
        return $out;
    }

    $out .= '<table class="dts-table"><thead><tr>
              <th>Date</th><th>Driver</th><th>Origin</th><th>Destination</th><th>Weight</th><th>Bill No</th>
            </tr></thead><tbody>';

    foreach ($rows as $r) {
        $u = get_user_by('id', (int)$r->user_id);
        $driver = $u ? $u->display_name : 'Unknown';

        $out .= '<tr>
          <td data-label="Date">' . esc_html($r->trip_date) . '</td>
          <td data-label="Driver">' . esc_html($driver) . '</td>
          <td data-label="Origin">' . esc_html($r->origin) . '</td>
          <td data-label="Destination">' . esc_html($r->destination) . '</td>
          <td data-label="Weight">' . esc_html($r->weight) . '</td>
          <td data-label="Bill No">' . esc_html($r->bill_number) . '</td>
        </tr>';
    }

    $out .= '</tbody></table></div></div>';
    return $out;
});

/* =========================================================
   SHORTCODE: DRIVER TRIP FORM  [driver_trip_form]
   (Removed inline JS; uses enqueued JS above)
========================================================= */
add_shortcode('driver_trip_form', function () {
    if (!is_user_logged_in()) {
        return '<div class="dts-wrap"><div class="dts-card"><p>Please login to submit a trip.</p></div></div>';
    }
    if (!dts_can_submit_trip()) {
        return '<div class="dts-wrap"><div class="dts-card"><p>Access denied.</p></div></div>';
    }

    global $wpdb;
    dts_ensure_tables();
    $tMatrix = $wpdb->prefix . 'dts_master_matrix';

    $options = $wpdb->get_results("SELECT id, origin, destination, client_name, unit_name
                                  FROM $tMatrix
                                  WHERE active=1 AND is_complete=1
                                  ORDER BY origin ASC, destination ASC, client_name ASC, unit_name ASC");

    $today = date('Y-m-d');

    ob_start(); ?>
    <div class="dts-wrap" data-dts-trip-form="1">
      <div class="dts-card">
        <h2 class="dts-title">Submit Trip</h2>

        <form class="dts-form" method="post">
          <?php wp_nonce_field('dts_save_trip', 'dts_nonce'); ?>

          <p><label>Date</label>
          <input type="date" name="trip_date" value="<?php echo esc_attr($today); ?>" required></p>

          <p><label>Client List Selection (Route + Client + Unit)</label>
            <select name="master_id" id="dts_master_id" data-dts-master="1" required>
              <option value="">Select...</option>
              <?php foreach ($options as $o): ?>
                <option value="<?php echo esc_attr($o->id); ?>"
                        data-origin="<?php echo esc_attr($o->origin); ?>"
                        data-destination="<?php echo esc_attr($o->destination); ?>">
                  <?php echo esc_html($o->origin . ' → ' . $o->destination . ' | ' . $o->client_name . ' | ' . $o->unit_name); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (!$options): ?>
              <div class="dts-muted" style="margin-top:6px;">No client list rows yet. Logistic Manager must add them in Client List first.</div>
            <?php endif; ?>
          </p>

          <p><label>Origin</label>
          <input type="text" name="origin" id="dts_origin" data-dts-origin="1" readonly required></p>

          <p><label>Destination</label>
          <input type="text" name="destination" id="dts_destination" data-dts-destination="1" readonly required></p>

          <p><label>Weight / Trip</label>
          <input type="number" step="0.01" name="weight" required></p>

          <p><label>Bill Number</label>
          <input type="text" name="bill_number" required></p>

          <p><input type="submit" class="dts-btn" name="save_trip" value="Submit Trip"></p>
        </form>
      </div>
    </div>
    <?php
    return ob_get_clean();
});

/* =========================================================
   SHORTCODE: CLIENT LIST  [dts_client_list]
   (Removed inline JS; uses enqueued JS above)
========================================================= */
add_shortcode('dts_client_list', function () {
    if (!dts_is_lm_admin()) {
        return '<div class="dts-wrap"><div class="dts-card"><p>Access denied.</p></div></div>';
    }

    global $wpdb;
    dts_ensure_tables();
    $tMatrix = $wpdb->prefix . 'dts_master_matrix';

    $rows = $wpdb->get_results("SELECT * FROM $tMatrix WHERE active=1 ORDER BY origin ASC, destination ASC, client_name ASC, unit_name ASC, id DESC");

    $nonce = wp_create_nonce('dts_client_list');
    $ajax  = admin_url('admin-ajax.php');

    $uid = 'dts_' . wp_generate_uuid4();
    $bodyId = $uid . '_body';
    $plusId = $uid . '_plus';

    ob_start(); ?>
    <div class="dts-wrap">
      <div class="dts-card">
        <h2 class="dts-title">Client List</h2>
        <p class="dts-muted">
          Click + to add a row. Fill Origin, Destination, Client, Unit (required). Rate optional.
          After save, Routes/Client/Unit are locked; only Rate remains editable (Enter or click away).
          Delete is soft-delete (keeps old trip records).
        </p>

        <div class="dts-grid-wrap">
          <table class="dts-grid"
                 id="<?php echo esc_attr($uid); ?>_grid"
                 data-dts-clientlist="1"
                 data-ajax="<?php echo esc_attr($ajax); ?>"
                 data-nonce="<?php echo esc_attr($nonce); ?>"
                 data-body="<?php echo esc_attr($bodyId); ?>"
                 data-plus="<?php echo esc_attr($plusId); ?>">
            <colgroup>
              <col style="width:6.6667%;">
              <col style="width:19.0476%;">
              <col style="width:19.0476%;">
              <col style="width:28.5714%;">
              <col style="width:13.3333%;">
              <col style="width:13.3333%;">
            </colgroup>

            <thead>
              <tr>
                <th rowspan="2">#</th>
                <th colspan="2">Routes</th>
                <th rowspan="2">Clients</th>
                <th rowspan="2">Units</th>
                <th rowspan="2">Rates</th>
              </tr>
              <tr>
                <th>Origin</th>
                <th>Destination</th>
              </tr>
            </thead>

            <tbody id="<?php echo esc_attr($bodyId); ?>">
              <?php if ($rows): ?>
                <?php $i=1; foreach ($rows as $r): ?>
                  <tr data-id="<?php echo esc_attr($r->id); ?>">
                    <td class="numcell">
                      <span class="row-num"><?php echo esc_html($i++); ?></span>
                      <span class="row-actions">
                        <button class="mini-btn dts-del" type="button" title="Delete">Del</button>
                      </span>
                    </td>

                    <td><input class="dts-cell-input" data-col="origin" type="text" value="<?php echo esc_attr($r->origin); ?>" readonly></td>
                    <td><input class="dts-cell-input" data-col="destination" type="text" value="<?php echo esc_attr($r->destination); ?>" readonly></td>
                    <td><input class="dts-cell-input" data-col="client_name" type="text" value="<?php echo esc_attr($r->client_name); ?>" readonly></td>
                    <td><input class="dts-cell-input" data-col="unit_name" type="text" value="<?php echo esc_attr($r->unit_name); ?>" readonly style="text-align:center;"></td>

                    <td>
                      <input class="dts-cell-input dts-edit" data-col="rate" type="number" step="0.01"
                             value="<?php echo is_null($r->rate) ? '' : esc_attr(number_format((float)$r->rate, 2, '.', '')); ?>"
                             placeholder="(blank)" style="text-align:center;">
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr class="dts-empty-row"><td></td><td></td><td></td><td></td><td></td><td></td></tr>
              <?php endif; ?>

              <tr id="<?php echo esc_attr($plusId); ?>">
                <td class="center dts-plus" title="Add">+</td>
                <td></td><td></td><td></td><td></td><td></td>
              </tr>
            </tbody>
          </table>
        </div>

      </div>
    </div>
    <?php
    return ob_get_clean();
});

/* =========================================================
   AJAX: Ping (for testing)
========================================================= */
add_action('wp_ajax_dts_ping', function () {
    wp_send_json_success(['pong' => true, 'user' => get_current_user_id()]);
});

/* =========================================================
   AJAX: Create / Update Rate / Delete (Client List)
========================================================= */
add_action('wp_ajax_dts_create_master_row', function () {
    if (!dts_is_lm_admin()) wp_send_json_error(['message' => 'Access denied'], 403);
    check_ajax_referer('dts_client_list', 'nonce');

    global $wpdb;
    dts_ensure_tables();
    $tMatrix = $wpdb->prefix . 'dts_master_matrix';

    $origin = sanitize_text_field($_POST['origin'] ?? '');
    $dest   = sanitize_text_field($_POST['destination'] ?? '');
    $client = sanitize_text_field($_POST['client_name'] ?? '');
    $unit   = sanitize_text_field($_POST['unit_name'] ?? '');
    $rate_raw = trim((string)($_POST['rate'] ?? ''));

    if (!dts_matrix_is_complete($origin, $dest, $client, $unit)) {
        wp_send_json_error(['message' => 'Please fill Origin, Destination, Client, and Unit before saving.'], 400);
    }

    // If identical active row exists, reuse it
    $existing_id = (int)$wpdb->get_var($wpdb->prepare("
        SELECT id FROM $tMatrix
        WHERE active=1 AND is_complete=1
          AND origin=%s AND destination=%s AND client_name=%s AND unit_name=%s
        LIMIT 1
    ", $origin, $dest, $client, $unit));

    if ($existing_id > 0) {
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tMatrix WHERE id=%d", $existing_id));
        wp_send_json_success([
            'id' => (int)$row->id,
            'origin' => esc_html($row->origin),
            'destination' => esc_html($row->destination),
            'client_name' => esc_html($row->client_name),
            'unit_name' => esc_html($row->unit_name),
            'rate' => is_null($row->rate) ? '' : number_format((float)$row->rate, 2, '.', ''),
        ]);
    }

    // Insert with NULL-safe rate
    if ($rate_raw === '') {
        $ok = $wpdb->query($wpdb->prepare("
            INSERT INTO $tMatrix (origin, destination, client_name, unit_name, rate, is_complete, active)
            VALUES (%s, %s, %s, %s, NULL, 1, 1)
        ", $origin, $dest, $client, $unit));
    } else {
        $rate = floatval($rate_raw);
        $ok = $wpdb->query($wpdb->prepare("
            INSERT INTO $tMatrix (origin, destination, client_name, unit_name, rate, is_complete, active)
            VALUES (%s, %s, %s, %s, %f, 1, 1)
        ", $origin, $dest, $client, $unit, $rate));
    }

    if ($ok === false) {
        wp_send_json_error(['message' => 'DB insert failed: ' . $wpdb->last_error], 500);
    }

    $id = (int)$wpdb->insert_id;
    if ($id <= 0) {
        wp_send_json_error(['message' => 'Insert returned no ID.'], 500);
    }

    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tMatrix WHERE id=%d", $id));
    if (!$row) wp_send_json_error(['message' => 'Row not found after insert.'], 500);

    wp_send_json_success([
        'id' => (int)$row->id,
        'origin' => esc_html($row->origin),
        'destination' => esc_html($row->destination),
        'client_name' => esc_html($row->client_name),
        'unit_name' => esc_html($row->unit_name),
        'rate' => is_null($row->rate) ? '' : number_format((float)$row->rate, 2, '.', ''),
    ]);
});

add_action('wp_ajax_dts_update_master_rate', function () {
    if (!dts_is_lm_admin()) wp_send_json_error(['message' => 'Access denied'], 403);
    check_ajax_referer('dts_client_list', 'nonce');

    global $wpdb;
    dts_ensure_tables();
    $tMatrix = $wpdb->prefix . 'dts_master_matrix';

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) wp_send_json_error(['message' => 'Invalid ID'], 400);

    $rate_raw = trim((string)($_POST['rate'] ?? ''));

    $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM $tMatrix WHERE id=%d", $id));
    if ($exists === 0) wp_send_json_error(['message' => 'Row not found.'], 404);

    if ($rate_raw === '') {
        $ok = $wpdb->query($wpdb->prepare("UPDATE $tMatrix SET rate=NULL WHERE id=%d", $id));
        if ($ok === false) wp_send_json_error(['message' => 'DB error: ' . $wpdb->last_error], 500);
        wp_send_json_success(['rate' => '']);
    } else {
        $rate = floatval($rate_raw);
        $ok = $wpdb->query($wpdb->prepare("UPDATE $tMatrix SET rate=%f WHERE id=%d", $rate, $id));
        if ($ok === false) wp_send_json_error(['message' => 'DB error: ' . $wpdb->last_error], 500);
        wp_send_json_success(['rate' => number_format((float)$rate, 2, '.', '')]);
    }
});

add_action('wp_ajax_dts_delete_master_row', function () {
    if (!dts_is_lm_admin()) wp_send_json_error(['message' => 'Access denied'], 403);
    check_ajax_referer('dts_client_list', 'nonce');

    global $wpdb;
    dts_ensure_tables();
    $tMatrix = $wpdb->prefix . 'dts_master_matrix';
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) wp_send_json_error(['message' => 'Invalid ID'], 400);

    // Soft delete
    $ok = $wpdb->update($tMatrix, ['active' => 0], ['id' => $id], ['%d'], ['%d']);
    if ($ok === false) {
        wp_send_json_error(['message' => 'DB error: ' . $wpdb->last_error], 500);
    }

    // If update affected 0 rows, verify if it exists anyway
    if ($ok === 0) {
        $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM $tMatrix WHERE id=%d", $id));
        if ($exists === 0) wp_send_json_error(['message' => 'Row not found.'], 404);
    }

    wp_send_json_success(['id' => $id]);
});

/* =========================================================
   ACCESS CONTROL: KEEP DRIVER/LM OUT OF WP-ADMIN
========================================================= */
add_action('admin_init', function () {
    if (dts_is_driver() && is_admin() && !defined('DOING_AJAX')) {
        wp_safe_redirect(home_url());
        exit;
    }
});
add_action('admin_init', function () {
    if (dts_is_lm() && is_admin() && !defined('DOING_AJAX')) {
        wp_safe_redirect(home_url('/lm-dashboard/'));
        exit;
    }
});
add_action('after_setup_theme', function () {
    if (dts_is_driver() || dts_is_lm()) show_admin_bar(false);
});
