<?php
/**
 * Plugin Name: WP API Product Sync
 * Description: Sync WooCommerce products from a مرجع (WooCommerce REST API).
 * Version: 0.3.3
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

  const DEFAULT_BASE_URL = 'https://setare-energy.com';

  const FIXED_HEALTH_EP     = '/wp-json/wc/v3/system_status';
  const FIXED_PRODUCTS_EP   = '/wp-json/wc/v3/products';
  const FIXED_CATEGORIES_EP = '/wp-json/wc/v3/products/categories';

  const AJAX_NONCE_ACTION = 'wpaps_ajax';

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
    add_action('wp_ajax_wpaps_fetch_categories', [__CLASS__, 'ajax_fetch_categories']);
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
    $url = rtrim($url, "/ \t\n\r\0\x0B");
    return esc_url_raw($url);
  }

  private static function normalize_ep($ep, $default) {
    $ep = trim((string)$ep);
    if ($ep === '') $ep = $default;
    if ($ep[0] !== '/') $ep = '/'.$ep;
    return $ep;
  }

  private static function get_base_url() {
    $v = self::normalize_base_url(self::opt(self::OPT_BASE_URL, ''));
    if ($v !== '') return $v;
    return self::normalize_base_url(self::DEFAULT_BASE_URL);
  }

  public static function render_admin_page() {
    if (!current_user_can('manage_options')) return;

    $base = self::get_base_url();
    $ajax_nonce = wp_create_nonce(self::AJAX_NONCE_ACTION);
    $products_url = admin_url('edit.php?post_type=product');

    echo '<div class="wrap">';
    echo '<div class="wpaps-container">';
    echo '<h1>آپلود محصولات ستاره</h1>';

    echo '<div class="notice notice-success is-dismissible" id="wpapsSavedNotice" style="display:none;margin:12px 0 0 0;">'
      . '<p>تنظیمات ذخیره شد.</p>'
      . '</div>';

    echo '<div class="wpaps-toast" id="wpapsToast" style="display:none">'
      . '<button type="button" class="wpaps-toast-x" id="wpapsToastX" aria-label="close">×</button>'
      . '<div class="wpaps-toast-title">تبریک !</div>'
      . '<div class="wpaps-toast-desc">محصولات با موفقیت در سایت شما آپلود شدند.</div>'
      . '<a class="button button-primary" href="'.esc_url($products_url).'">رفتن به صفحه محصولات</a>'
      . '</div>';

    // CARD 1: اتصال به سرور
    echo '<div class="wpaps-card" id="wpapsConnCard">';
      echo '<div class="wpaps-card-title">اتصال به سرور</div>';
      echo '<div class="wpaps-card-subtitle">لطفا فیلد ها را پر کرده و سپس دکمه ذخیره و پس از ان دکمه عیب یابی را بزنید</div>';

      echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" target="wpapsSaveFrame" id="wpapsSaveForm">';
        wp_nonce_field('wpaps_save_settings', 'wpaps_nonce');
        echo '<input type="hidden" name="action" value="wpaps_save_settings" />';

        echo '<input type="hidden" name="wpaps_base_url" id="wpaps_base_url" value="'.esc_attr($base).'">';

        echo '<div class="wpaps-grid">';
          echo '<label class="wpaps-label" for="wpaps_ck">Consumer Key</label>';
          echo '<input class="regular-text wpaps-input" name="wpaps_ck" id="wpaps_ck" type="text" value="'.esc_attr(self::opt(self::OPT_CK, '')).'" autocomplete="off" placeholder="" />';

          echo '<label class="wpaps-label" for="wpaps_cs">Consumer Secret</label>';
          echo '<input class="regular-text wpaps-input" name="wpaps_cs" id="wpaps_cs" type="text" value="'.esc_attr(self::opt(self::OPT_CS, '')).'" autocomplete="off" placeholder="" />';
        echo '</div>';

        echo '<div class="wpaps-top-actions">';
          echo '<button type="submit" class="button button-primary" id="wpapsBtnSave">ذخیره</button>';
          echo '<span class="spinner" id="wpapsSpinSave" style="display:none"></span>';

          echo '<button type="button" class="button" id="wpapsBtnDiagnose">عیب یابی</button>';
          echo '<span class="spinner" id="wpapsSpinDiag" style="display:none"></span>';

          echo '<div class="wpaps-status-wrap">'
            . '<span class="wpaps-status-label">وضعیت:</span>'
            . '<span class="wpaps-pill wpaps-pill-gray" id="wpapsConnPill">پیکربندی نشده</span>'
            . '</div>';
        echo '</div>';
      echo '</form>';

      echo '<iframe name="wpapsSaveFrame" id="wpapsSaveFrame" style="display:none;width:0;height:0;border:0"></iframe>';
    echo '</div>';

    // CARD جدا براي نوع محتوا + دکمه تایید
    echo '<div class="wpaps-card" id="wpapsCatCard" style="display:none">';
      echo '<div class="wpaps-card-title">نوع محتوا</div>';
      echo '<div class="wpaps-card-subtitle">لطفا یک دسته بندی را انتخاب کن و تایید بزن</div>';

      echo '<div class="wpaps-catcard">';
        echo '<div class="wpaps-catleft">';
          echo '<div class="wpaps-catrow">';
            echo '<select class="wpaps-catselect2" id="wpapsCatSelect">'
              . '<option value="" disabled selected hidden>انتخاب کن</option>'
              . '</select>';
            echo '<button type="button" class="button button-primary" id="wpapsCatConfirm" disabled>تایید</button>';
            echo '<span class="spinner" id="wpapsSpinConfirm" style="display:none"></span>';
          echo '</div>';
        echo '</div>';
      echo '</div>';
    echo '</div>';

    // CARD جدول محصولات (ثابت)
    echo '<div class="wpaps-card" id="wpapsProductsCard" style="display:none">';
      echo '<div class="wpaps-products-head">'
        . '<div class="wpaps-products-title">لیست محصولات</div>'
        . '<div class="wpaps-card-subtitle" style="margin-top:6px">لطفا محصولات مورد نظر را انتخاب کرده و روی دکمه اپلود بزنید</div>'
        . '<div class="wpaps-products-count" id="wpapsProductsCount" style="display:none">تعداد محصولات : <span id="wpapsProductsCountNum">0</span></div>'
        . '</div>';

      echo '<div class="wpaps-table-wrap" id="wpapsTableWrap">';
        echo '<table class="wpaps-table" id="wpapsTable">';
          echo '<thead><tr>';
            echo '<th class="wpaps-col-check"><input type="checkbox" id="wpapsCheckAll" aria-label="select all" /></th>';
            echo '<th class="wpaps-col-img">عکس</th>';
            echo '<th class="wpaps-col-name">نام</th>';
          echo '</tr></thead>';
          echo '<tbody id="wpapsTbody"></tbody>';
        echo '</table>';
      echo '</div>';

      echo '<div class="wpaps-bottom-actions">'
        . '<button type="button" class="button button-primary" id="wpapsBtnTransfer" disabled>اپلود</button>'
        . '<span class="spinner" id="wpapsSpinUpload" style="display:none"></span>'
        . '<div class="wpaps-status-wrap">'
          . '<span class="wpaps-status-label">وضعیت:</span>'
          . '<span class="wpaps-pill wpaps-pill-red" id="wpapsPickPill">هنوز هیچ محصولی انتخاب نکردی</span>'
          . '</div>'
        . '</div>';
    echo '</div>';

    echo '<style>
      .wpaps-container{max-width:980px;margin:0 auto;padding:0 18px}
      .wpaps-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;margin-top:14px}
      .wpaps-card-title{font-weight:800;margin:0 0 6px 0}
      .wpaps-card-subtitle{color:#50575e;font-size:13px;line-height:1.7;margin:0 0 10px 0}

      .wpaps-grid{display:grid;grid-template-columns:160px 1fr;gap:10px;align-items:center}
      .wpaps-label{font-weight:700}
      .wpaps-input{width:100%;max-width:100%}

      .wpaps-top-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}

      .wpaps-status-wrap{display:flex;gap:8px;align-items:center}
      .wpaps-status-label{color:#1d2327}
      .wpaps-pill{display:inline-block;padding:4px 12px;border-radius:999px;color:#fff;font-size:12px;line-height:1.7}
      .wpaps-pill-gray{background:#7a7a7a}
      .wpaps-pill-green{background:#1f7a1f}
      .wpaps-pill-red{background:#b32d2e}
      .wpaps-pill-orange{background:#d54e21}

      .wpaps-products-head{display:flex;flex-direction:column;gap:4px;margin-bottom:10px}
      .wpaps-products-title{font-weight:800}
      .wpaps-products-count{color:#1f7a1f;font-weight:800}

      .wpaps-table-wrap{overflow-y:auto;max-height:520px;border:1px solid #dcdcde;border-radius:8px}
      table.wpaps-table{width:100%;border-collapse:collapse;background:#fff}
      table.wpaps-table th, table.wpaps-table td{border-bottom:1px solid #eee;padding:10px;vertical-align:middle;text-align:right}
      table.wpaps-table th{background:#fafafa;font-weight:800}
      .wpaps-col-check{width:44px}
      .wpaps-col-img{width:80px}
      .wpaps-thumb{width:34px;height:34px;object-fit:cover;border-radius:6px;border:1px solid #e5e5e5}

      .wpaps-bottom-actions{
        display:flex;
        gap:10px;
        align-items:center;
        justify-content:flex-start;
        direction:rtl;
        width:100%;
        margin-top:12px;
      }

      .wpaps-toast{position:relative;background:#fff;border:1px solid #dcdcde;border-right:4px solid #2ea043;border-radius:10px;padding:16px;margin-top:14px;box-shadow:0 1px 2px rgba(0,0,0,.06)}
      .wpaps-toast-title{font-weight:900;margin-bottom:6px}
      .wpaps-toast-desc{color:#1d2327;margin-bottom:12px}
      .wpaps-toast-x{position:absolute;left:10px;top:8px;border:0;background:transparent;font-size:18px;cursor:pointer;line-height:1}

      #wpapsCatCard{padding:14px 16px}
      .wpaps-catcard{display:flex;align-items:center;gap:14px}
      .wpaps-catleft{display:flex;flex-direction:column;gap:8px;min-width:360px}
      .wpaps-catrow{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
      .wpaps-catselect2{
        height:42px;
        border:2px solid #2271b1;
        border-radius:8px;
        padding:0 12px;
        background:#fff;
        min-width:320px;
      }
      .wpaps-catselect2:focus{outline:none;box-shadow:0 0 0 1px #2271b1}

      .wpaps-top-actions .spinner,
      .wpaps-catrow .spinner,
      .wpaps-bottom-actions .spinner{float:none;margin:0}

      .wrap > input[type="text"],
      .wrap > input[type="search"]{display:none !important;}
    </style>';

    echo '<script>
    (function(){
      const ajaxUrl = "'.esc_js(admin_url('admin-ajax.php')).'";
      const nonce   = "'.esc_js($ajax_nonce).'";

      const productsCard = document.getElementById("wpapsProductsCard");
      const catCard = document.getElementById("wpapsCatCard");

      const btnSave = document.getElementById("wpapsBtnSave");
      const btnDiag = document.getElementById("wpapsBtnDiagnose");
      const catSelect = document.getElementById("wpapsCatSelect");
      const catConfirm = document.getElementById("wpapsCatConfirm");
      const btnTransfer = document.getElementById("wpapsBtnTransfer");
      const connPill = document.getElementById("wpapsConnPill");

      const spinSave = document.getElementById("wpapsSpinSave");
      const spinDiag = document.getElementById("wpapsSpinDiag");
      const spinConfirm = document.getElementById("wpapsSpinConfirm");
      const spinUpload = document.getElementById("wpapsSpinUpload");

      const tbody = document.getElementById("wpapsTbody");
      const checkAll = document.getElementById("wpapsCheckAll");
      const pickPill = document.getElementById("wpapsPickPill");
      const countWrap = document.getElementById("wpapsProductsCount");
      const countNum = document.getElementById("wpapsProductsCountNum");

      const toast = document.getElementById("wpapsToast");
      const toastX = document.getElementById("wpapsToastX");

      const saveForm = document.getElementById("wpapsSaveForm");
      const saveFrame = document.getElementById("wpapsSaveFrame");
      const savedNotice = document.getElementById("wpapsSavedNotice");
      let _saving = false;

      function setSpinner(el, on){
        if (!el) return;
        if (on){
          el.classList.add("is-active");
          el.style.display = "inline-block";
        } else {
          el.classList.remove("is-active");
          el.style.display = "none";
        }
      }

      if (saveForm && saveFrame && savedNotice){
        saveForm.addEventListener("submit", function(){
          _saving = true;
          savedNotice.style.display = "none";
          setSpinner(spinSave, true);
          if (btnSave) btnSave.disabled = true;
        });
        saveFrame.addEventListener("load", function(){
          if (!_saving) return;
          _saving = false;
          savedNotice.style.display = "";
          setSpinner(spinSave, false);
          if (btnSave) btnSave.disabled = false;
          try{ window.scrollTo({top:0, behavior:"smooth"}); }catch(e){ window.scrollTo(0,0); }
        });
      }

      let isTransferring = false;
      let catsLoaded = false;
      let selectedCatId = "";

      function showProductsCard(){ if (productsCard) productsCard.style.display = ""; }
      function hideProductsCard(){ if (productsCard) productsCard.style.display = "none"; }

      function showCatCard(){ if (catCard) catCard.style.display = ""; }
      function hideCatCard(){ if (catCard) catCard.style.display = "none"; }

      function fd(obj){
        const f = new FormData();
        Object.keys(obj).forEach(k => f.append(k, obj[k]));
        return f;
      }

      async function post(action, payload){
        payload = payload || {};
        payload.action = action;
        payload._ajax_nonce = nonce;
        const res = await fetch(ajaxUrl, { method:"POST", body: fd(payload), credentials:"same-origin" });
        return res.json();
      }

      function setConnPill(state, text){
        connPill.classList.remove("wpaps-pill-gray","wpaps-pill-green","wpaps-pill-red");
        if (state === "ok") connPill.classList.add("wpaps-pill-green");
        else if (state === "bad") connPill.classList.add("wpaps-pill-red");
        else connPill.classList.add("wpaps-pill-gray");
        connPill.textContent = text;
      }

      function setPickPill(state, text){
        pickPill.classList.remove("wpaps-pill-red","wpaps-pill-orange","wpaps-pill-green","wpaps-pill-gray");
        if (state === "ok") pickPill.classList.add("wpaps-pill-green");
        else if (state === "orange") pickPill.classList.add("wpaps-pill-orange");
        else if (state === "gray") pickPill.classList.add("wpaps-pill-gray");
        else pickPill.classList.add("wpaps-pill-red");
        pickPill.textContent = text;
      }

      function selectionCount(){
        return Array.from(document.querySelectorAll(".wpapsRowCb")).filter(x=>x.checked).length;
      }

      function updateTransferUI(){
        const n = selectionCount();
        if (isTransferring){
          btnTransfer.disabled = true;
          setPickPill("ok", "در حال انتقال");
          return;
        }
        if (n <= 0){
          btnTransfer.disabled = true;
          setPickPill("bad", "هنوز هیچ محصولی انتخاب نکردی");
        } else {
          btnTransfer.disabled = false;
          setPickPill("orange", n + " محصول انتخاب شده است");
        }
      }

      function renderTable(products){
        const list = products || [];
        tbody.innerHTML = "";

        if (!list.length){
          countWrap.style.display = "";
          countNum.textContent = "0";
          if (checkAll) checkAll.checked = false;
          updateTransferUI();
          return;
        }

        list.forEach(p=>{
          const tr = document.createElement("tr");

          const tdC = document.createElement("td");
          tdC.className = "wpaps-col-check";
          const cb = document.createElement("input");
          cb.type = "checkbox";
          cb.className = "wpapsRowCb";
          cb.value = String(p.id);
          cb.addEventListener("change", function(){
            if (checkAll && !cb.checked) checkAll.checked = false;
            updateTransferUI();
          });
          tdC.appendChild(cb);

          const tdImg = document.createElement("td");
          tdImg.className = "wpaps-col-img";
          if (p.image){
            const img = document.createElement("img");
            img.src = p.image;
            img.className = "wpaps-thumb";
            tdImg.appendChild(img);
          } else {
            tdImg.textContent = "";
          }

          const tdName = document.createElement("td");
          tdName.className = "wpaps-col-name";
          tdName.textContent = p.name || "-";

          tr.appendChild(tdC);
          tr.appendChild(tdImg);
          tr.appendChild(tdName);
          tbody.appendChild(tr);
        });

        updateTransferUI();
      }

      function resetProductsUI(){
        hideProductsCard();
        countWrap.style.display = "none";
        countNum.textContent = "0";
        if (checkAll) checkAll.checked = false;
        tbody.innerHTML = "";
        setPickPill("bad", "هنوز هیچ محصولی انتخاب نکردی");
      }

      function resetCategoryUI(){
        selectedCatId = "";
        if (catConfirm) catConfirm.disabled = true;
        if (catSelect){
          catSelect.innerHTML = "";
          const ph = document.createElement("option");
          ph.value = "";
          ph.textContent = "انتخاب کن";
          ph.disabled = true;
          ph.selected = true;
          ph.hidden = true;
          catSelect.appendChild(ph);
        }
      }

      if (checkAll){
        checkAll.addEventListener("change", function(){
          const cbs = Array.from(document.querySelectorAll(".wpapsRowCb"));
          cbs.forEach(x=>{ x.checked = checkAll.checked; });
          updateTransferUI();
        });
      }

      if (toastX){
        toastX.addEventListener("click", function(){ toast.style.display = "none"; });
      }

      async function loadCategories(){
        if (catsLoaded) return true;
        if (!catSelect) return false;

        try{
          const j = await post("wpaps_fetch_categories", {});
          if (j && j.success && j.data && j.data.ok){
            const cats = j.data.categories || [];

            catSelect.innerHTML = "";
            const ph = document.createElement("option");
            ph.value = "";
            ph.textContent = "انتخاب کن";
            ph.disabled = true;
            ph.selected = true;
            ph.hidden = true;
            catSelect.appendChild(ph);

            cats.forEach(c=>{
              const opt = document.createElement("option");
              opt.value = String(c.id);
              opt.textContent = c.name;
              catSelect.appendChild(opt);
            });

            catsLoaded = true;
            return true;
          }
          return false;
        }catch(e){
          return false;
        }
      }

      if (catSelect){
        catSelect.addEventListener("change", function(){
          const v = String(catSelect.value || "");
          selectedCatId = v;
          resetProductsUI();
          if (catConfirm) catConfirm.disabled = !v;
        });
      }

      async function fetchAndShowProductsForCat(catId){
        toast.style.display = "none";
        showProductsCard();

        if (checkAll) checkAll.checked = false;
        tbody.innerHTML = "";
        countWrap.style.display = "";
        countNum.textContent = "0";
        setPickPill("bad", "هنوز هیچ محصولی انتخاب نکردی");

        try{
          const j = await post("wpaps_fetch_products", { cat_id: String(catId) });
          if (j && j.success && j.data){
            if (j.data.ok){
              const total = (typeof j.data.total_count !== "undefined")
                ? Number(j.data.total_count)
                : Number((j.data.products||[]).length);

              countWrap.style.display = "";
              countNum.textContent = String(isNaN(total) ? 0 : total);

              renderTable(j.data.products || []);
            } else {
              const e = (j.data.errors && j.data.errors[0]) ? j.data.errors[0] : "'.esc_js(self::ERR_BAD_AUTH).'";
              setConnPill("bad", e);
              countWrap.style.display = "";
              countNum.textContent = "0";
              renderTable([]);
            }
          } else {
            setConnPill("bad", "'.esc_js(self::ERR_SITE_DOWN).'" );
            countWrap.style.display = "";
            countNum.textContent = "0";
            renderTable([]);
          }
        }catch(e){
          setConnPill("bad", "'.esc_js(self::ERR_NET).'" );
          countWrap.style.display = "";
          countNum.textContent = "0";
          renderTable([]);
        }
      }

      if (catConfirm){
        catConfirm.addEventListener("click", async function(){
          if (!selectedCatId) return;

          setSpinner(spinConfirm, true);
          catConfirm.disabled = true;
          if (catSelect) catSelect.disabled = true;

          try{
            await fetchAndShowProductsForCat(selectedCatId);
          } finally {
            setSpinner(spinConfirm, false);
            if (catSelect) catSelect.disabled = false;
            catConfirm.disabled = false;
          }
        });
      }

      if (btnDiag){
        btnDiag.addEventListener("click", async function(){
          setConnPill("gray", "پیکربندی نشده");

          hideCatCard();
          resetCategoryUI();
          resetProductsUI();

          setSpinner(spinDiag, true);
          btnDiag.disabled = true;
          if (btnSave) btnSave.disabled = true;

          try{
            const j = await post("wpaps_diagnose", {});
            if (j && j.success && j.data){
              if (j.data.ok){
                setConnPill("ok", "پیکربندی شده");

                showCatCard();
                if (catConfirm) catConfirm.disabled = true;
                selectedCatId = "";

                const ok = await loadCategories();
                if (!ok){
                  hideCatCard();
                  resetCategoryUI();
                }
              } else {
                const e = (j.data.errors && j.data.errors[0]) ? j.data.errors[0] : "'.esc_js(self::ERR_BAD_AUTH).'";
                setConnPill("bad", e);
                hideCatCard();
              }
            } else {
              setConnPill("bad", "'.esc_js(self::ERR_SITE_DOWN).'" );
              hideCatCard();
            }
          } catch (e){
            setConnPill("bad", "'.esc_js(self::ERR_NET).'" );
            hideCatCard();
          } finally {
            setSpinner(spinDiag, false);
            btnDiag.disabled = false;
            if (btnSave) btnSave.disabled = false;
          }
        });
      }

      if (btnTransfer){
        btnTransfer.addEventListener("click", async function(){
          const ids = Array.from(document.querySelectorAll(".wpapsRowCb")).filter(x=>x.checked).map(x=>x.value);
          if (!ids.length){
            setPickPill("bad", "هنوز هیچ محصولی انتخاب نکردی");
            return;
          }

          isTransferring = true;
          updateTransferUI();
          toast.style.display = "none";

          setSpinner(spinUpload, true);

          try{
            const j = await post("wpaps_transfer_products", { ids: ids.join(","), cat_id: selectedCatId });
            if (j && j.success && j.data){
              if (j.data.ok){
                Array.from(document.querySelectorAll(".wpapsRowCb")).forEach(x=>{ x.checked=false; });
                if (checkAll) checkAll.checked = false;
                isTransferring = false;
                updateTransferUI();
                toast.style.display = "";
              } else {
                isTransferring = false;
                const e = (j.data.errors && j.data.errors[0]) ? j.data.errors[0] : "'.esc_js(self::ERR_XFER_FAIL).'";
                setPickPill("bad", e);
                updateTransferUI();
              }
            } else {
              isTransferring = false;
              setPickPill("bad", "'.esc_js(self::ERR_XFER_FAIL).'" );
              updateTransferUI();
            }
          } catch (e){
            isTransferring = false;
            setPickPill("bad", "'.esc_js(self::ERR_NET).'" );
            updateTransferUI();
          } finally {
            setSpinner(spinUpload, false);
          }
        });
      }

      setSpinner(spinSave, false);
      setSpinner(spinDiag, false);
      setSpinner(spinConfirm, false);
      setSpinner(spinUpload, false);

      hideCatCard();
      resetCategoryUI();
      resetProductsUI();
      setConnPill("gray", "پیکربندی نشده");
      if (catConfirm) catConfirm.disabled = true;
    })();
    </script>';

    echo '</div>';
    echo '</div>';
  }

  public static function handle_save_settings() {
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
    if (!isset($_POST['wpaps_nonce']) || !wp_verify_nonce($_POST['wpaps_nonce'], 'wpaps_save_settings')) {
      wp_die('Bad nonce', 400);
    }

    $base = isset($_POST['wpaps_base_url']) ? self::normalize_base_url($_POST['wpaps_base_url']) : '';
    $ck   = isset($_POST['wpaps_ck']) ? sanitize_text_field((string)$_POST['wpaps_ck']) : '';
    $cs   = isset($_POST['wpaps_cs']) ? sanitize_text_field((string)$_POST['wpaps_cs']) : '';

    if ($base !== '') update_option(self::OPT_BASE_URL, $base, false);
    if ($ck !== '') update_option(self::OPT_CK, $ck, false);
    if ($cs !== '') update_option(self::OPT_CS, $cs, false);

    update_option(self::OPT_HEALTH_EP, self::FIXED_HEALTH_EP, false);
    update_option(self::OPT_PRODUCTS_EP, self::FIXED_PRODUCTS_EP, false);

    wp_safe_redirect(add_query_arg(['page'=>'wp-api-product-sync','wpaps_saved'=>'1'], admin_url('admin.php')));
    exit;
  }

  private static function remote_get($path, $query = []) {
    $base = self::get_base_url();
    $ck   = self::opt(self::OPT_CK, '');
    $cs   = self::opt(self::OPT_CS, '');

    if ($base === '' || $ck === '' || $cs === '') {
      return new WP_Error('wpaps_not_configured', 'not_configured');
    }

    $url = $base . $path;

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

      if (stripos($msg, 'Could not resolve host') !== false || stripos($msg, 'resolve') !== false) {
        $errs[] = self::ERR_SITE_DOWN;
      } elseif (stripos($msg, 'timed out') !== false || stripos($msg, 'Timeout') !== false || stripos($msg, 'Failed to connect') !== false) {
        $errs[] = self::ERR_NET;
      } else {
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

    $errs[] = self::ERR_SITE_DOWN;
    $errs[] = self::ERR_BAD_AUTH;
    return array_values(array_unique($errs));
  }

  public static function ajax_diagnose() {
    check_ajax_referer(self::AJAX_NONCE_ACTION);

    if (!current_user_can('manage_options')) {
      wp_send_json_success(['ok'=>false,'errors'=>[self::ERR_SECURITY]]);
    }

    $hep = self::normalize_ep(self::FIXED_HEALTH_EP, self::FIXED_HEALTH_EP);
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

    $cat_id = isset($_POST['cat_id']) ? (int)$_POST['cat_id'] : 0;
    $pep = self::normalize_ep(self::FIXED_PRODUCTS_EP, self::FIXED_PRODUCTS_EP);

    $all = [];
    $per_page = 100;

    $q = [
      'page' => 1,
      'per_page' => $per_page,
    ];
    if ($cat_id > 0) $q['category'] = $cat_id;

    $resp = self::remote_get($pep, $q);

    $errs = self::classify_conn_error($resp);
    if ($errs) {
      wp_send_json_success(['ok'=>false,'errors'=>$errs]);
    }

    $body = wp_remote_retrieve_body($resp);
    $data = json_decode($body, true);

    if (!is_array($data)) {
      wp_send_json_success(['ok'=>false,'errors'=>[self::ERR_BAD_AUTH]]);
    }

    $total = (int) wp_remote_retrieve_header($resp, 'x-wp-total');
    $total_pages = (int) wp_remote_retrieve_header($resp, 'x-wp-totalpages');
    if ($total_pages < 1) $total_pages = 1;

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
        'status' => (string)($p['status'] ?? ''),
        'image' => $img,
      ];
    }

    $max_pages = min($total_pages, 50);
    for ($page = 2; $page <= $max_pages; $page++) {
      $q2 = [
        'page' => $page,
        'per_page' => $per_page,
      ];
      if ($cat_id > 0) $q2['category'] = $cat_id;

      $resp2 = self::remote_get($pep, $q2);
      $errs2 = self::classify_conn_error($resp2);
      if ($errs2) {
        wp_send_json_success(['ok'=>false,'errors'=>$errs2]);
      }

      $body2 = wp_remote_retrieve_body($resp2);
      $data2 = json_decode($body2, true);
      if (!is_array($data2)) {
        wp_send_json_success(['ok'=>false,'errors'=>[self::ERR_BAD_AUTH]]);
      }

      foreach ($data2 as $p) {
        if (!is_array($p)) continue;
        $img = '';
        if (!empty($p['images']) && is_array($p['images']) && !empty($p['images'][0]['src'])) {
          $img = (string)$p['images'][0]['src'];
        }
        $all[] = [
          'id' => (int)($p['id'] ?? 0),
          'name' => (string)($p['name'] ?? ''),
          'sku' => (string)($p['sku'] ?? ''),
          'status' => (string)($p['status'] ?? ''),
          'image' => $img,
        ];
      }

      if (count($data2) < $per_page) break;
    }

    if ($total < 0) $total = 0;

    wp_send_json_success([
      'ok' => true,
      'total_count' => $total,
      'products' => $all
    ]);
  }

  public static function ajax_fetch_categories() {
    check_ajax_referer(self::AJAX_NONCE_ACTION);

    if (!current_user_can('manage_options')) {
      wp_send_json_success(['ok'=>false,'errors'=>[self::ERR_SECURITY]]);
    }

    $cep = self::normalize_ep(self::FIXED_CATEGORIES_EP, self::FIXED_CATEGORIES_EP);

    $all = [];
    $page = 1;
    $per_page = 100;

    while (true) {
      $resp = self::remote_get($cep, [
        'page' => $page,
        'per_page' => $per_page,
        'hide_empty' => 'true',
        'orderby' => 'name',
        'order' => 'asc',
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

      foreach ($data as $t) {
        if (!is_array($t)) continue;
        $cnt = (int)($t['count'] ?? 0);
        if ($cnt <= 0) continue;

        $all[] = [
          'id'   => (int)($t['id'] ?? 0),
          'name' => (string)($t['name'] ?? ''),
          'slug' => (string)($t['slug'] ?? ''),
          'count'=> $cnt,
        ];
      }

      if (count($data) < $per_page) break;

      $page++;
      if ($page > 50) break;
    }

    usort($all, function($a, $b){
      return strcmp((string)$a['name'], (string)$b['name']);
    });

    wp_send_json_success(['ok'=>true,'categories'=>$all]);
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

    $path = rtrim(self::FIXED_PRODUCTS_EP, '/') . '/' . $id;
    return self::remote_get($path, []);
  }

  private static function wpaps_sideload_image_to_media($url, $post_id, &$err = null) {
    $err = null;
    $url = esc_url_raw((string)$url);
    $post_id = (int)$post_id;

    if ($url === '' || $post_id <= 0) { $err = 'bad_url_or_post'; return 0; }

    if (!function_exists('download_url')) require_once ABSPATH . 'wp-admin/includes/file.php';
    if (!function_exists('media_handle_sideload')) require_once ABSPATH . 'wp-admin/includes/media.php';
    if (!function_exists('wp_read_image_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = download_url($url, 25);

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

      if (is_wp_error($r)) { $err = 'image_download_failed: ' . $r->get_error_message(); return 0; }

      $code = (int) wp_remote_retrieve_response_code($r);
      if ($code < 200 || $code >= 300) { $err = 'image_http_' . $code; return 0; }

      $body = wp_remote_retrieve_body($r);
      if (!is_string($body) || $body === '') { $err = 'image_empty_body'; return 0; }

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

  // ✅ alt متن جایگزین هر عکس هم ست میشود
  private static function wpaps_set_attachment_alt($att_id, $alt_text) {
    $att_id = (int)$att_id;
    $alt_text = trim((string)$alt_text);
    if ($att_id <= 0 || $alt_text === '') return;
    update_post_meta($att_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
  }

  private static function wpaps_apply_images_from_remote(WC_Product $prod, $remote_product, $post_id, &$errors = null) {
    $errors = [];
    if (!is_array($remote_product)) return;

    $imgs = $remote_product['images'] ?? null;
    if (!is_array($imgs) || !$imgs) return;

    $attachment_ids = [];
    $product_name_for_alt = (string)($remote_product['name'] ?? '');

    foreach ($imgs as $img) {
      if (!is_array($img)) continue;
      $src = (string)($img['src'] ?? '');
      if ($src === '') continue;

      $err = null;
      $att_id = self::wpaps_sideload_image_to_media($src, $post_id, $err);
      if ($att_id > 0) {
        $attachment_ids[] = $att_id;

        $alt = (string)($img['alt'] ?? '');
        if ($alt === '') $alt = $product_name_for_alt;
        self::wpaps_set_attachment_alt($att_id, $alt);
      }
    }

    $attachment_ids = array_values(array_unique(array_filter($attachment_ids)));
    if (!$attachment_ids) return;

    $prod->set_image_id((int)$attachment_ids[0]);
    if (count($attachment_ids) > 1) $prod->set_gallery_image_ids(array_slice($attachment_ids, 1));
  }

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
      $attr->set_id(0);
      $attr->set_name($name);
      $attr->set_options($opts);
      $attr->set_position($pos++);
      $attr->set_visible($visible);
      $attr->set_variation($variation);

      $attrs_out[] = $attr;
    }

    if ($attrs_out) $prod->set_attributes($attrs_out);
  }

  // ✅ پیوند یکتا (slug/post_name) هم انتقال داده میشود
  private static function wpaps_apply_permalink_slug($post_id, $remote_product) {
    $post_id = (int)$post_id;
    if ($post_id <= 0 || !is_array($remote_product)) return;

    $remote_slug = trim((string)($remote_product['slug'] ?? ''));
    $fallback = trim((string)($remote_product['name'] ?? ''));
    $slug = $remote_slug !== '' ? $remote_slug : $fallback;

    $slug = sanitize_title($slug);
    if ($slug === '') return;

    // unique slug داخل سایت مقصد
    $unique = function_exists('wp_unique_post_slug')
      ? wp_unique_post_slug($slug, $post_id, 'publish', 'product', 0)
      : $slug;

    wp_update_post([
      'ID' => $post_id,
      'post_name' => $unique,
    ]);
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
    $ids = array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', $ids_raw))));
    if (!$ids) wp_send_json_success(['ok'=>false,'errors'=>[self::ERR_XFER_FAIL]]);

    $cat_id = isset($_POST['cat_id']) ? (int)$_POST['cat_id'] : 0;
    if ($cat_id > 0 && !term_exists($cat_id, 'product_cat')) $cat_id = 0;

    $errors = [];

    foreach ($ids as $rid) {
      $resp = self::remote_get_product($rid);
      $errs = self::classify_conn_error($resp);
      if ($errs) { $errors = array_merge($errors, $errs); continue; }

      $body = wp_remote_retrieve_body($resp);
      $rp = json_decode($body, true);
      if (!is_array($rp)) { $errors[] = self::ERR_BAD_AUTH; continue; }

      $name = (string)($rp['name'] ?? '');
      $desc = (string)($rp['description'] ?? '');
      $short = (string)($rp['short_description'] ?? '');
      $sku = (string)($rp['sku'] ?? '');

      $existing_id = self::local_find_by_remote_id($rid);

      if ($existing_id > 0) {
        $prod = wc_get_product($existing_id);
        if (!$prod) { $errors[] = self::ERR_XFER_FAIL; continue; }
      } else {
        $prod = new WC_Product_Simple();
      }

      $prod->set_name($name);
      $prod->set_description($desc);
      $prod->set_short_description($short);
      if ($sku !== '') $prod->set_sku($sku);

      $prod->set_status('publish');
      if ($cat_id > 0) $prod->set_category_ids([$cat_id]);

      $pid = $prod->save();
      if (!$pid) { $errors[] = self::ERR_XFER_FAIL; continue; }

      update_post_meta($pid, '_wpaps_remote_id', (string)$rid);

      // ✅ پیوند یکتا
      self::wpaps_apply_permalink_slug($pid, $rp);

      // ✅ تصاویر + alt
      $img_errors = [];
      self::wpaps_apply_images_from_remote($prod, $rp, $pid, $img_errors);

      self::wpaps_apply_attributes_from_remote($prod, $rp);
      $prod->save();
    }

    $errors = array_values(array_unique(array_filter($errors)));

    if ($errors) {
      $allowed = [self::ERR_SITE_DOWN, self::ERR_NET, self::ERR_BAD_AUTH, self::ERR_WC_MISSING, self::ERR_XFER_FAIL, self::ERR_SECURITY];
      $errors = array_values(array_intersect($errors, $allowed));
      if (!$errors) $errors = [self::ERR_XFER_FAIL];
      wp_send_json_success(['ok'=>false,'errors'=>$errors]);
    }

    wp_send_json_success(['ok'=>true,'errors'=>[]]);
  }
}

WPAPS_Plugin::init();