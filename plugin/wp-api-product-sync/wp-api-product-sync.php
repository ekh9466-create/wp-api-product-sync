<?php
/**
 * Plugin Name: WP API Product Sync
 * Description: Sync WooCommerce products from a مرجع (WooCommerce REST API).
 * Version: 0.2.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: ekh9466-create
 * License: GPLv2 or later
 * Text Domain: wp-api-product-sync
 */

if (!defined('ABSPATH')) exit;

final class WPAPS_Plugin {
  const OPT_BASE_URL    = 'wpaps_base_url';
  const OPT_CK          = 'wpaps_consumer_key';
  const OPT_CS          = 'wpaps_consumer_secret';
  const OPT_HEALTH_EP   = 'wpaps_health_ep';
  const OPT_PRODUCTS_EP = 'wpaps_products_ep';

  const AJAX_NONCE_ACTION = 'wpaps_ajax';

  // فقط همين ارورها (فارسی) مجاز هستند
  const ERR_SITE_DOWN   = 'سایت مرجع در دسترس نیست';
  const ERR_NET         = 'مشکل در اتصال اینترنت';
  const ERR_BAD_AUTH    = 'عدم برقراری ارتباط درست';

  const ERR_WC_MISSING  = 'ووکامرس روی سایت مقصد نصب نیست';
  const ERR_XFER_FAIL   = 'انتقال ناموفق بود';
  const ERR_SECURITY    = 'مشکل در تنظیمات امنیتی سایت و وجود محدودیت';

  public static function init() {
    add_action('admin_menu', [__CLASS__, 'admin_menu']);
    add_action('admin_post_wpaps_save_settings', [__CLASS__, 'handle_save_settings']);

    add_action('wp_ajax_wpaps_diagnose', [__CLASS__, 'ajax_diagnose']);
    add_action('wp_ajax_wpaps_fetch_products', [__CLASS__, 'ajax_fetch_products']);
    add_action('wp_ajax_wpaps_transfer_products', [__CLASS__, 'ajax_transfer_products']);
  }

  public static function admin_menu() {
    add_menu_page(
      'WP API Product Sync',
      'Product Sync',
      'manage_options',
      'wp-api-product-sync',
      [__CLASS__, 'render_admin_page'],
      'dashicons-update',
      56
    );
  }

  private static function opt($key, $default='') {
    return (string) get_option($key, $default);
  }

  private static function normalize_base_url($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    // حذف slash انتها
    $url = rtrim($url, "/ \t\n\r\0\x0B");
    return esc_url_raw($url);
  }

  private static function normalize_ep($ep, $default) {
    $ep = trim((string)$ep);
    if ($ep === '') $ep = $default;
    if ($ep[0] !== '/') $ep = '/'.$ep;
    return $ep;
  }

  public static function render_admin_page() {
    if (!current_user_can('manage_options')) return;

    $base = self::opt(self::OPT_BASE_URL, '');
    $ck   = self::opt(self::OPT_CK, '');
    $cs   = self::opt(self::OPT_CS, '');
    $hep  = self::opt(self::OPT_HEALTH_EP, '/wp-json/wc/v3/system_status');
    $pep  = self::opt(self::OPT_PRODUCTS_EP, '/wp-json/wc/v3/products');

    $base = self::normalize_base_url($base);
    $hep  = self::normalize_ep($hep, '/wp-json/wc/v3/system_status');
    $pep  = self::normalize_ep($pep, '/wp-json/wc/v3/products');

    $configured = ($base !== '' && $ck !== '' && $cs !== '');

    $ajax_nonce = wp_create_nonce(self::AJAX_NONCE_ACTION);

    echo '<div class="wrap">';
    echo '<div class="wrap">';
    echo '<div class="wpaps-container">';

    echo '<h1>WP API Product Sync</h1>';

    if (isset($_GET['wpaps_saved']) && $_GET['wpaps_saved'] === '1') {
      echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>';
    }

echo '</div>';

    echo '<style>
      .wpaps-container{max-width:980px;margin:0 auto;padding:0 18px}
      .wpaps-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;margin-top:14px}
      .wpaps-row{display:grid;grid-template-columns:180px 1fr;gap:10px;align-items:center;margin:10px 0}
      .wpaps-row label{font-weight:700}
      .wpaps-input{width:100%;max-width:100%}
      .wpaps-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}
      .wpaps-actions .button{min-width:110px}
      .wpaps-status{display:flex;gap:8px;align-items:center}
      .wpaps-badge{display:inline-block;padding:3px 10px;border-radius:999px;background:#1f7a1f;color:#fff;font-size:12px}
      .wpaps-badge-red{background:#b32d2e}
      .wpaps-muted{color:#646970;font-size:12px}
      .wpaps-box-title{font-weight:800;margin:0 0 8px 0}
      .wpaps-msg-ok{color:#1f7a1f;font-weight:700}
      .wpaps-msg-bad{color:#b32d2e;font-weight:700}
      .wpaps-list{margin:0;padding:0 18px 0 0}
      .wpaps-list li{margin:6px 0}
      .wpaps-table-wrap{margin-top:10px;overflow-x:auto;overflow-y:auto;max-height:520px;border:1px solid #dcdcde;border-radius:8px}
      table.wpaps-table{width:100%;border-collapse:collapse;background:#fff;min-width:860px}
      table.wpaps-table th, table.wpaps-table td{border-bottom:1px solid #eee;padding:10px;vertical-align:middle}
      table.wpaps-table th{background:#fafafa;font-weight:800}
      .wpaps-thumb{width:34px;height:34px;object-fit:cover;border-radius:6px;border:1px solid #e5e5e5}
      .wpaps-right{display:flex;justify-content:flex-end}
    
/* wpaps: hide the stray top input (do NOT touch plugin form inputs) */
.wrap > input[type="text"],
.wrap > input[type="search"]{
  display:none !important;
}


/* wpaps: hide the stray top input (do NOT touch plugin form inputs) */
.wrap > input[type="text"],
.wrap > input[type="search"]{
  display: none !important;
}

</style>
<script>
document.addEventListener("DOMContentLoaded", function(){
  var root = document.querySelector(".wrap") || document;
  var tables = root.querySelectorAll("table");
  if(!tables || !tables.length) return;
  var tbl = tables[tables.length-1];
  if(tbl.closest(".wpaps-products-scroll")) return;
  var wrap = document.createElement("div");
  wrap.className = "wpaps-products-scroll";
  tbl.parentNode.insertBefore(wrap, tbl);
  wrap.appendChild(tbl);
});
</script>
';

    echo '<div class="wpaps-container">';

    // کارت تنظیمات
    echo '<div class="wpaps-card">';
    echo '<div class="wpaps-box-title">ربط پلاگین</div>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('wpaps_save_settings', 'wpaps_nonce');
    echo '<input type="hidden" name="action" value="wpaps_save_settings" />';

    echo '<div class="wpaps-row"><label for="wpaps_base_url">آدرس سایت مرجع</label>';
    echo '<input class="regular-text wpaps-input" name="wpaps_base_url" id="wpaps_base_url" type="url" value="' . esc_attr($base) . '" placeholder="https://example.com" required></div>';

    echo '<div class="wpaps-row"><label for="wpaps_ck">Consumer Key</label>';
    echo '<input class="regular-text wpaps-input" name="wpaps_ck" id="wpaps_ck" type="text" value="' . esc_attr($ck) . '" autocomplete="off" required></div>';

    echo '<div class="wpaps-row"><label for="wpaps_cs">Consumer Secret</label>';
    echo '<input class="regular-text wpaps-input" name="wpaps_cs" id="wpaps_cs" type="text" value="' . esc_attr($cs) . '" autocomplete="off" required></div>';

    echo '<div class="wpaps-row"><label for="wpaps_health_ep">Health Endpoint</label>';
    echo '<input class="regular-text wpaps-input" name="wpaps_health_ep" id="wpaps_health_ep" type="text" value="' . esc_attr($hep) . '" placeholder="/wp-json/wc/v3/system_status" required></div>';

    echo '<div class="wpaps-row"><label for="wpaps_products_ep">Products Endpoint</label>';
    echo '<input class="regular-text wpaps-input" name="wpaps_products_ep" id="wpaps_products_ep" type="text" value="' . esc_attr($pep) . '" placeholder="/wp-json/wc/v3/products" required></div>';

    echo '<div class="wpaps-actions">';
      echo '<button type="submit" class="button button-primary">ذخیره</button>';
      echo '<button type="button" id="wpapsBtnDiagnose" class="button" '.($configured?'':'disabled').'>عیب یابی</button>';
      echo '<button type="button" id="wpapsBtnFetch" class="button" '.($configured?'':'disabled').'>استعلام محصول</button>';
      echo '<button type="button" id="wpapsBtnTransfer" class="button button-secondary" disabled>انتقال</button>';

      echo '<div class="wpaps-status">';
        echo '<span>وضعیت:</span> ';
        if ($configured) echo '<span class="wpaps-badge">پیکربندی شده</span>';
        else echo '<span class="wpaps-badge wpaps-badge-red">پیکربندی نشده</span>';
      echo '</div>';
      echo '<span class="wpaps-muted">لیست محصولات بعد از «استعلام محصول» نمایش داده می شود.</span>';
    echo '</div>';

    echo '</form>';
    echo '</div>';

    // کارت ارور اتصال
    echo '<div class="wpaps-card" id="wpapsConnCard">';
      echo '<div class="wpaps-box-title">ارور اتصال</div>';
      echo '<div id="wpapsConnMsg" class="wpaps-muted">برای مشاهده نتیجه، «عیب یابی» را بزن.</div>';
      echo '<ul id="wpapsConnErrors" class="wpaps-list" style="display:none"></ul>';
    echo '</div>';

    // کارت لیست محصولات
    echo '<div class="wpaps-card" id="wpapsProductsCard">';
      echo '<div class="wpaps-box-title">لیست محصولات سایت مرجع</div>';
      echo '<div id="wpapsProductsInfo" class="wpaps-muted">برای نمایش محصولات، «استعلام محصول» را بزن.</div>';
      echo '<div class="wpaps-table-wrap" id="wpapsTableWrap" style="display:none">';
        echo '<table class="wpaps-table" id="wpapsTable">';
          echo '<thead><tr>';
            echo '<th style="width:60px"><input type="checkbox" id="wpapsCheckAll" /></th>';
            echo '<th style="width:70px">عکس</th>';
            echo '<th>نام</th>';
            echo '<th style="width:120px">SKU</th>';
            // ✅ قیمت حذف شد (نمایش)
            echo '<th style="width:120px">وضعیت</th>';
          echo '</tr></thead>';
          echo '<tbody id="wpapsTbody"></tbody>';
        echo '</table>';
      echo '</div>';
    echo '</div>';

    // کارت ارور انتقال
    echo '<div class="wpaps-card" id="wpapsXferCard">';
      echo '<div class="wpaps-box-title">ارور انتقال</div>';
      echo '<div id="wpapsXferMsg" class="wpaps-muted">برای انتقال، ابتدا «استعلام محصول» سپس چند محصول را انتخاب و «انتقال» را بزن.</div>';
      echo '<ul id="wpapsXferErrors" class="wpaps-list" style="display:none"></ul>';
    echo '</div>';

    echo '</div>'; // container

    // JS
    echo '<script>
    (function(){
      const ajaxUrl = "'.esc_js(admin_url('admin-ajax.php')).'";
      const nonce   = "'.esc_js($ajax_nonce).'";

      const btnDiag = document.getElementById("wpapsBtnDiagnose");
      const btnFetch = document.getElementById("wpapsBtnFetch");
      const btnTransfer = document.getElementById("wpapsBtnTransfer");

      const connMsg = document.getElementById("wpapsConnMsg");
      const connErrors = document.getElementById("wpapsConnErrors");

      const productsInfo = document.getElementById("wpapsProductsInfo");
      const tableWrap = document.getElementById("wpapsTableWrap");
      const tbody = document.getElementById("wpapsTbody");
      const checkAll = document.getElementById("wpapsCheckAll");

      const xferMsg = document.getElementById("wpapsXferMsg");
      const xferErrors = document.getElementById("wpapsXferErrors");

      let lastProducts = []; // [{id,name,sku,status,image}]

      function fd(obj){
        const f = new FormData();
        Object.keys(obj).forEach(k => f.append(k, obj[k]));
        return f;
      }

      function showList(ul, items, ok){
        ul.innerHTML = "";
        if (!items || !items.length){
          ul.style.display = "none";
          return;
        }
        ul.style.display = "";
        items.forEach(t=>{
          const li = document.createElement("li");
          li.className = ok ? "wpaps-msg-ok" : "wpaps-msg-bad";
          li.textContent = t;
          ul.appendChild(li);
        });
      }

      function setConnResult(ok, errs){
        connErrors.style.display = "none";
        if (ok){
          connMsg.className = "wpaps-msg-ok";
          connMsg.textContent = "اتصال با موفقیت انجام شد";
          showList(connErrors, [], true);
        } else {
          connMsg.className = "wpaps-muted";
          connMsg.textContent = "مشکل در اتصال";
          showList(connErrors, errs || [], false);
        }
      }

      function setXferResult(ok, msgs){
        xferErrors.style.display = "none";
        if (ok){
          xferMsg.className = "wpaps-msg-ok";
          xferMsg.textContent = "انتقال با موفقیت انجام شد";
          showList(xferErrors, [], true);
        } else {
          xferMsg.className = "wpaps-muted";
          xferMsg.textContent = "مشکل در انتقال";
          showList(xferErrors, msgs || [], false);
        }
      }

      function renderTable(products){
        lastProducts = products || [];
        tbody.innerHTML = "";
        if (!lastProducts.length){
          tableWrap.style.display = "none";
          btnTransfer.disabled = true;
          return;
        }
        tableWrap.style.display = "";
        lastProducts.forEach(p=>{
          const tr = document.createElement("tr");

          const tdC = document.createElement("td");
          const cb = document.createElement("input");
          cb.type = "checkbox";
          cb.className = "wpapsRowCb";
          cb.value = String(p.id);
          cb.addEventListener("change", updateTransferBtn);
          tdC.appendChild(cb);

          const tdImg = document.createElement("td");
          if (p.image){
            const img = document.createElement("img");
            img.src = p.image;
            img.className = "wpaps-thumb";
            tdImg.appendChild(img);
          } else {
            tdImg.textContent = "-";
          }

          const tdName = document.createElement("td");
          tdName.textContent = p.name || "-";

          const tdSku = document.createElement("td");
          tdSku.textContent = p.sku || "-";

          const tdSt = document.createElement("td");
          tdSt.textContent = p.status || "-";

          tr.appendChild(tdC);
          tr.appendChild(tdImg);
          tr.appendChild(tdName);
          tr.appendChild(tdSku);
          tr.appendChild(tdSt);

          tbody.appendChild(tr);
        });
        updateTransferBtn();
      }

      function updateTransferBtn(){
        const cbs = Array.from(document.querySelectorAll(".wpapsRowCb"));
        const any = cbs.some(x=>x.checked);
        btnTransfer.disabled = !any;
      }

      if (checkAll){
        checkAll.addEventListener("change", function(){
          const cbs = Array.from(document.querySelectorAll(".wpapsRowCb"));
          cbs.forEach(x=>{ x.checked = checkAll.checked; });
          updateTransferBtn();
        });
      }

      async function post(action, payload){
        payload = payload || {};
        payload.action = action;
        payload._ajax_nonce = nonce;
        const res = await fetch(ajaxUrl, { method:"POST", body: fd(payload), credentials:"same-origin" });
        return res.json();
      }

      if (btnDiag){
        btnDiag.addEventListener("click", async function(){
          setConnResult(false, []);
          connMsg.className = "wpaps-muted";
          connMsg.textContent = "در حال عیب یابی...";
          try{
            const j = await post("wpaps_diagnose", {});
            if (j && j.success && j.data){
              if (j.data.ok) setConnResult(true, []);
              else setConnResult(false, j.data.errors || []);
            } else {
              setConnResult(false, ["'.esc_js(self::ERR_SITE_DOWN).'"]);
            }
          }catch(e){
            setConnResult(false, ["'.esc_js(self::ERR_NET).'"]);
          }
        });
      }

      if (btnFetch){
        btnFetch.addEventListener("click", async function(){
          productsInfo.className = "wpaps-muted";
          productsInfo.textContent = "در حال دریافت محصولات...";
          renderTable([]);
          try{
            const j = await post("wpaps_fetch_products", {});
            if (j && j.success && j.data){
              if (j.data.ok){
                productsInfo.className = "wpaps-msg-ok";
                productsInfo.textContent = "تعداد محصولات دریافت شده: " + (j.data.count || 0);
                renderTable(j.data.products || []);
              } else {
                productsInfo.className = "wpaps-msg-bad";
                productsInfo.textContent = "مشکل در دریافت محصولات";
                setConnResult(false, j.data.errors || []);
              }
            } else {
              productsInfo.className = "wpaps-msg-bad";
              productsInfo.textContent = "مشکل در دریافت محصولات";
            }
          }catch(e){
            productsInfo.className = "wpaps-msg-bad";
            productsInfo.textContent = "مشکل در دریافت محصولات";
          }
        });
      }

      if (btnTransfer){
        btnTransfer.addEventListener("click", async function(){
          const ids = Array.from(document.querySelectorAll(".wpapsRowCb")).filter(x=>x.checked).map(x=>x.value);
          if (!ids.length) return;

          setXferResult(false, []);
          xferMsg.className = "wpaps-muted";
          xferMsg.textContent = "در حال انتقال...";
          try{
            const j = await post("wpaps_transfer_products", { ids: ids.join(",") });
            if (j && j.success && j.data){
              if (j.data.ok){
                setXferResult(true, []);
                // بعد از انتقال موفق: تیک ها پاک
                Array.from(document.querySelectorAll(".wpapsRowCb")).forEach(x=>{ x.checked=false; });
                if (checkAll) checkAll.checked = false;
                updateTransferBtn();
              } else {
                setXferResult(false, j.data.errors || ["'.esc_js(self::ERR_XFER_FAIL).'"]);
              }
            } else {
              setXferResult(false, ["'.esc_js(self::ERR_XFER_FAIL).'"]);
            }
          }catch(e){
            setXferResult(false, ["'.esc_js(self::ERR_NET).'"]);
          }
        });
      }

    })();
    </script>';
  }

  public static function handle_save_settings() {
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
    if (!isset($_POST['wpaps_nonce']) || !wp_verify_nonce($_POST['wpaps_nonce'], 'wpaps_save_settings')) {
      wp_die('Bad nonce', 400);
    }

    $base = isset($_POST['wpaps_base_url']) ? self::normalize_base_url($_POST['wpaps_base_url']) : '';
    $ck   = isset($_POST['wpaps_ck']) ? sanitize_text_field((string)$_POST['wpaps_ck']) : '';
    $cs   = isset($_POST['wpaps_cs']) ? sanitize_text_field((string)$_POST['wpaps_cs']) : '';

    $hep  = isset($_POST['wpaps_health_ep']) ? self::normalize_ep($_POST['wpaps_health_ep'], '/wp-json/wc/v3/system_status') : '/wp-json/wc/v3/system_status';
    $pep  = isset($_POST['wpaps_products_ep']) ? self::normalize_ep($_POST['wpaps_products_ep'], '/wp-json/wc/v3/products') : '/wp-json/wc/v3/products';

    update_option(self::OPT_BASE_URL, $base, false);
    update_option(self::OPT_CK, $ck, false);
    update_option(self::OPT_CS, $cs, false);
    update_option(self::OPT_HEALTH_EP, $hep, false);
    update_option(self::OPT_PRODUCTS_EP, $pep, false);

    wp_safe_redirect(add_query_arg(['page'=>'wp-api-product-sync','wpaps_saved'=>'1'], admin_url('admin.php')));
    exit;
  }

  private static function remote_get($path, $query = []) {
    $base = self::normalize_base_url(self::opt(self::OPT_BASE_URL, ''));
    $ck   = self::opt(self::OPT_CK, '');
    $cs   = self::opt(self::OPT_CS, '');

    if ($base === '' || $ck === '' || $cs === '') {
      return new WP_Error('wpaps_not_configured', 'not_configured');
    }

    $url = $base . $path;

    // WooCommerce ساده ترین حالت (Query Auth)
    $query = is_array($query) ? $query : [];
    $query['consumer_key'] = $ck;
    $query['consumer_secret'] = $cs;

    $url = add_query_arg($query, $url);

    $args = [
      'timeout' => 20,
      'redirection' => 3,
      'headers' => [
        'Accept' => 'application/json',
      ],
    ];

    return wp_remote_get($url, $args);
  }

  private static function classify_conn_error($resp_or_err) {
    $errs = [];

    if (is_wp_error($resp_or_err)) {
      $msg = (string)$resp_or_err->get_error_message();

      // خیلی ساده و عملی: نت/هاست
      if (stripos($msg, 'Could not resolve host') !== false || stripos($msg, 'resolve') !== false) {
        $errs[] = self::ERR_SITE_DOWN;
      } elseif (stripos($msg, 'timed out') !== false || stripos($msg, 'Timeout') !== false || stripos($msg, 'Failed to connect') !== false) {
        $errs[] = self::ERR_NET;
      } else {
        // پیش فرض: اینترنت/دسترسی
        $errs[] = self::ERR_NET;
      }
      return array_values(array_unique($errs));
    }

    $code = (int) wp_remote_retrieve_response_code($resp_or_err);

    if ($code >= 200 && $code < 300) return [];

    if ($code === 401 || $code === 403) {
      $errs[] = self::ERR_BAD_AUTH;
      return $errs;
    }

    // بقیه: هم سایت مشکل دارد هم auth/مسیر درست نیست (طبق خواسته تو)
    $errs[] = self::ERR_SITE_DOWN;
    $errs[] = self::ERR_BAD_AUTH;
    return array_values(array_unique($errs));
  }

  public static function ajax_diagnose() {
    check_ajax_referer(self::AJAX_NONCE_ACTION);

    if (!current_user_can('manage_options')) {
      wp_send_json_success(['ok'=>false,'errors'=>[self::ERR_SECURITY]]);
    }

    $hep = self::normalize_ep(self::opt(self::OPT_HEALTH_EP, '/wp-json/wc/v3/system_status'), '/wp-json/wc/v3/system_status');
    $resp = self::remote_get($hep, []);

    $errs = self::classify_conn_error($resp);
    if (!$errs) {
      wp_send_json_success(['ok'=>true,'errors'=>[]]);
    }
    wp_send_json_success(['ok'=>false,'errors'=>$errs]);
  }

  public static function ajax_fetch_products() {
    check_ajax_referer(self::AJAX_NONCE_ACTION);

    if (!current_user_can('manage_options')) {
      wp_send_json_success(['ok'=>false,'errors'=>[self::ERR_SECURITY]]);
    }

    $pep = self::normalize_ep(self::opt(self::OPT_PRODUCTS_EP, '/wp-json/wc/v3/products'), '/wp-json/wc/v3/products');

    $all = [];
    $page = 1;
    $per_page = 100;

    while (true) {
      $resp = self::remote_get($pep, [
        'page' => $page,
        'per_page' => $per_page,
      ]);

      $errs = self::classify_conn_error($resp);
      if ($errs) {
        wp_send_json_success(['ok'=>false,'errors'=>$errs]);
      }

      $body = wp_remote_retrieve_body($resp);
      $data = json_decode($body, true);

      if (!is_array($data)) {
        wp_send_json_success(['ok'=>false,'errors'=>[self::ERR_BAD_AUTH]]);
      }

      foreach ($data as $p) {
        if (!is_array($p)) continue;
        $img = '';
        if (!empty($p['images']) && is_array($p['images']) && !empty($p['images'][0]['src'])) {
          $img = (string)$p['images'][0]['src'];
        }
        $all[] = [
          'id' => (int)($p['id'] ?? 0),
          'name' => (string)($p['name'] ?? ''),
          'sku' => (string)($p['sku'] ?? ''),
          // ✅ قیمت حذف شد (نه نمایش، نه ذخیره)
          'status' => (string)($p['status'] ?? ''),
          'image' => $img,
        ];
      }

      if (count($data) < $per_page) break;

      $page++;
      if ($page > 50) break; // سقف امن
    }

    wp_send_json_success(['ok'=>true,'count'=>count($all),'products'=>$all]);
  }

  private static function wc_is_ready() {
    return class_exists('WooCommerce') && class_exists('WC_Product_Simple') && function_exists('wc_get_product');
  }

  private static function local_find_by_remote_id($remote_id) {
    $remote_id = (int)$remote_id;
    if ($remote_id <= 0) return 0;

    $q = new WP_Query([
      'post_type' => 'product',
      'post_status' => 'any',
      'fields' => 'ids',
      'posts_per_page' => 1,
      'meta_query' => [
        [
          'key' => '_wpaps_remote_id',
          'value' => (string)$remote_id,
          'compare' => '=',
        ]
      ]
    ]);
    if (!empty($q->posts[0])) return (int)$q->posts[0];
    return 0;
  }

  private static function remote_get_product($id) {
    $id = (int)$id;
    if ($id <= 0) return new WP_Error('wpaps_bad_id', 'bad_id');

    $pep = self::normalize_ep(self::opt(self::OPT_PRODUCTS_EP, '/wp-json/wc/v3/products'), '/wp-json/wc/v3/products');
    $path = rtrim($pep, '/') . '/' . $id;
    return self::remote_get($path, []);
  }

  /**
   * دانلود عکس و ساخت attachment (برای Featured و Gallery)
   * نسخه نهايي: هم robust، هم خطا را به caller ميدهد.
   */
  private static function wpaps_sideload_image_to_media($url, $post_id, &$err = null) {
    $err = null;
    $url = esc_url_raw((string)$url);
    $post_id = (int)$post_id;

    if ($url === '' || $post_id <= 0) { $err = 'bad_url_or_post'; return 0; }

    // includes لازم
    if (!function_exists('download_url')) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if (!function_exists('media_handle_sideload')) {
      require_once ABSPATH . 'wp-admin/includes/media.php';
    }
    if (!function_exists('wp_read_image_metadata')) {
      require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    // 1) تلاش استاندارد
    $tmp = download_url($url, 25);

    // 2) اگر fail شد: fallback با User-Agent و sslverify=false
    if (is_wp_error($tmp)) {
      $r = wp_remote_get($url, [
        'timeout'     => 25,
        'redirection' => 3,
        'sslverify'   => false,
        'headers'     => [
          'User-Agent' => 'Mozilla/5.0 (WPAPS Product Sync)',
          'Accept'     => 'image/*,*/*;q=0.8',
        ],
      ]);

      if (is_wp_error($r)) {
        $err = 'image_download_failed: ' . $r->get_error_message();
        return 0;
      }

      $code = (int) wp_remote_retrieve_response_code($r);
      if ($code < 200 || $code >= 300) {
        $err = 'image_http_' . $code;
        return 0;
      }

      $body = wp_remote_retrieve_body($r);
      if (!is_string($body) || $body === '') {
        $err = 'image_empty_body';
        return 0;
      }

      $tmp = wp_tempnam($url);
      if (!$tmp) { $err = 'tempfile_failed'; return 0; }

      $ok = file_put_contents($tmp, $body);
      if ($ok === false) { @unlink($tmp); $err = 'temp_write_failed'; return 0; }
    }

    $name = wp_basename(parse_url($url, PHP_URL_PATH));
    if (!$name) $name = 'wpaps-image.jpg';

    $file_array = [
      'name'     => sanitize_file_name($name),
      'tmp_name' => $tmp,
    ];

    $att_id = media_handle_sideload($file_array, $post_id);

    if (is_wp_error($att_id)) {
      @unlink($tmp);
      $err = 'media_handle_failed: ' . $att_id->get_error_message();
      return 0;
    }

    return (int)$att_id;
  }

  /**
   * ست کردن Featured Image + Gallery از remote images[]
   * نسخه نهايي: اگر حتي يک عکس fail شود، علت واقعي را برميگرداند.
   */
  private static function wpaps_apply_images_from_remote(WC_Product $prod, $remote_product, $post_id, &$errors = null) {
    $errors = [];
    if (!is_array($remote_product)) return;

    $imgs = $remote_product['images'] ?? null;
    if (!is_array($imgs) || !$imgs) return;

    $attachment_ids = [];
    $i = 0;

    foreach ($imgs as $img) {
      $i++;
      if (!is_array($img)) continue;
      $src = (string)($img['src'] ?? '');
      if ($src === '') continue;

      $err = null;
      $att_id = self::wpaps_sideload_image_to_media($src, $post_id, $err);

      if ($att_id > 0) {
        $attachment_ids[] = $att_id;
      } else {
        $errors[] = "دانلود عکس #{$i} ناموفق: " . ($err ?: 'unknown_error');
      }
    }

    $attachment_ids = array_values(array_unique(array_filter($attachment_ids)));
    if (!$attachment_ids) return;

    // اولی = Featured
    $prod->set_image_id((int)$attachment_ids[0]);

    // بقيه = Gallery
    if (count($attachment_ids) > 1) {
      $prod->set_gallery_image_ids(array_slice($attachment_ids, 1));
    }
  }

  /**
   * ست کردن Attributes از remote attributes[] به صورت custom attribute (بدون taxonomy)
   */
  private static function wpaps_apply_attributes_from_remote(WC_Product $prod, $remote_product) {
    if (!is_array($remote_product)) return;

    $attrs_in = $remote_product['attributes'] ?? null;
    if (!is_array($attrs_in) || !$attrs_in) return;

    $attrs_out = [];
    $pos = 0;

    foreach ($attrs_in as $a) {
      if (!is_array($a)) continue;

      $name = trim((string)($a['name'] ?? ''));
      $options = $a['options'] ?? [];

      if ($name === '' || !is_array($options) || !$options) continue;

      $opts = [];
      foreach ($options as $opt) {
        $opt = trim((string)$opt);
        if ($opt !== '') $opts[] = $opt;
      }
      $opts = array_values(array_unique($opts));
      if (!$opts) continue;

      $visible = !empty($a['visible']);
      $variation = !empty($a['variation']);

      $attr = new WC_Product_Attribute();
      $attr->set_id(0); // custom
      $attr->set_name($name);
      $attr->set_options($opts);
      $attr->set_position($pos++);
      $attr->set_visible($visible);
      $attr->set_variation($variation);

      $attrs_out[] = $attr;
    }

    if ($attrs_out) {
      $prod->set_attributes($attrs_out);
    }
  }

  private static function create_local_product_from_remote($p) {
    // فقط حداقل هاي لازم (✅ قیمت و دسته‌بندی منتقل نمی‌شود)
    $name  = (string)($p['name'] ?? '');
    $sku   = (string)($p['sku'] ?? '');
    $desc  = (string)($p['description'] ?? '');
    $sdesc = (string)($p['short_description'] ?? '');

    $prod = new WC_Product_Simple();
    $prod->set_name($name !== '' ? $name : 'محصول');
    $prod->set_status('publish');
    if ($sku !== '') $prod->set_sku($sku);
    if ($desc !== '') $prod->set_description(wp_kses_post($desc));
    if ($sdesc !== '') $prod->set_short_description(wp_kses_post($sdesc));
    // ✅ هیچ set_regular_price / set_price نداریم
    // ✅ هیچ set_category_ids / set_category_ids نداریم

    $new_id = $prod->save();

    $new_id = (int)$new_id;
    if ($new_id > 0) {
      $prod2 = wc_get_product($new_id);
      if ($prod2) {
        // Featured + Gallery (با گزارش خطا)
        $img_errs = [];
        self::wpaps_apply_images_from_remote($prod2, $p, $new_id, $img_errs);
        if (!empty($img_errs)) {
          update_post_meta($new_id, '_wpaps_last_image_errors', $img_errs);
        }

        // Attributes
        self::wpaps_apply_attributes_from_remote($prod2, $p);

        $prod2->save();
      }
    }

    return (int)$new_id;
  }

  public static function ajax_transfer_products() {
    check_ajax_referer(self::AJAX_NONCE_ACTION);

    if (!current_user_can('manage_options')) {
      wp_send_json_success(['ok'=>false,'errors'=>[self::ERR_SECURITY]]);
    }

    if (!self::wc_is_ready()) {
      wp_send_json_success(['ok'=>false,'errors'=>[self::ERR_WC_MISSING]]);
    }

    $ids_raw = isset($_POST['ids']) ? (string)$_POST['ids'] : '';
    $ids = array_filter(array_map('intval', preg_split('/\s*,\s*/', $ids_raw)));

    if (!$ids) {
      wp_send_json_success(['ok'=>false,'errors'=>[self::ERR_XFER_FAIL]]);
    }

    $errors = [];
    $imported = 0;
    $skipped = 0;

    foreach ($ids as $rid) {
      if ($rid <= 0) continue;

      $exists = self::local_find_by_remote_id($rid);
      if ($exists) { $skipped++; continue; }

      $resp = self::remote_get_product($rid);
      $conn_errs = self::classify_conn_error($resp);
      if ($conn_errs) {
        $errors = array_merge($errors, $conn_errs);
        continue;
      }

      $body = wp_remote_retrieve_body($resp);
      $p = json_decode($body, true);
      if (!is_array($p) || empty($p['id'])) {
        $errors[] = self::ERR_XFER_FAIL;
        continue;
      }

      $new_id = self::create_local_product_from_remote($p);

      // اگر عکس/گالری fail شد، خطای واقعی را داخل همان "ارور انتقال" نشان بده
      $img_errs = get_post_meta($new_id, '_wpaps_last_image_errors', true);
      if (is_array($img_errs) && !empty($img_errs)) {
        $errors = array_merge($errors, $img_errs);
        delete_post_meta($new_id, '_wpaps_last_image_errors');
      }

      if ($new_id <= 0) {
        $errors[] = self::ERR_XFER_FAIL;
        continue;
      }

      update_post_meta($new_id, '_wpaps_remote_id', (string)(int)$p['id']);
      $imported++;
    }

    $errors = array_values(array_unique($errors));

    if ($imported > 0 && !$errors) {
      wp_send_json_success(['ok'=>true,'imported'=>$imported,'skipped'=>$skipped,'errors'=>[]]);
    }

    if ($errors) {
      wp_send_json_success(['ok'=>false,'imported'=>$imported,'skipped'=>$skipped,'errors'=>$errors]);
    }

    wp_send_json_success(['ok'=>false,'imported'=>$imported,'skipped'=>$skipped,'errors'=>[self::ERR_XFER_FAIL]]);
  }
}

WPAPS_Plugin::init();

/** wpaps: products table scroll + sticky header (no other code touched) */
add_action('admin_head', function () {
  if (!is_admin()) return;
  if (!isset($_GET['page']) || $_GET['page'] !== 'wp-api-product-sync') return;

  echo '<style>
  /* wpaps: products list scroll + sticky header */
  .wpaps-products-scroll{
    max-height:420px;
    overflow:auto;
    border:1px solid #dcdcde;
    border-radius:10px;
    margin-top:12px;
    background:#fff;
  }
  .wpaps-products-scroll table{ margin:0; }
  .wpaps-products-scroll thead th{
    position:sticky;
    top:0;
    background:#fff;
    z-index:3;
  }
  .wpaps-products-scroll thead th:after{
    content:"";
    position:absolute;
    left:0; right:0; bottom:-1px;
    height:1px;
    background:#dcdcde;
  }
  </style>';
});

add_action('admin_footer', function () {
  if (!is_admin()) return;
  if (!isset($_GET['page']) || $_GET['page'] !== 'wp-api-product-sync') return;

  echo '<script>(function(){
    function findProductsTable(){
      var wrap = document.querySelector(".wrap");
      if(!wrap) return null;

      // دنبال عنوان "لیست محصولات سایت مرجع" بگرد
      var nodes = wrap.querySelectorAll("h1,h2,h3,h4,div,p,span,strong");
      var marker = null;
      for (var i=0;i<nodes.length;i++){
        var t = (nodes[i].textContent||\"\").trim();
        if (t.indexOf(\"لیست محصولات سایت مرجع\") !== -1){ marker = nodes[i]; break; }
      }

      if(marker){
        var card = marker.closest ? (marker.closest(\".wpaps-card\") || marker.parentElement) : marker.parentElement;
        if(card){
          var tt = card.querySelector(\"table\");
          if(tt) return tt;
        }
        // fallback: نزدیک ترین table بعد از marker
        var el = marker;
        while(el && el !== wrap){
          var sib = el.nextElementSibling;
          while(sib){
            if(sib.tagName === \"TABLE\") return sib;
            var t2 = sib.querySelector ? sib.querySelector(\"table\") : null;
            if(t2) return t2;
            sib = sib.nextElementSibling;
          }
          el = el.parentElement;
        }
      }

      // آخرین جدول داخل wrap (محصولات معمولا آخرین table است)
      var tables = wrap.querySelectorAll(\"table\");
      return tables.length ? tables[tables.length-1] : null;
    }

    var table = findProductsTable();
    if(!table) return;
    if(table.closest && table.closest(\".wpaps-products-scroll\")) return;

    var box = document.createElement(\"div\");
    box.className = \"wpaps-products-scroll\";
    table.parentNode.insertBefore(box, table);
    box.appendChild(table);
  })();</script>';
});