<?php
/*
Plugin Name: Fast Site Search Chatbot
Plugin URI: https://github.com/Finland93/FastSiteSearchChatbot
Description: No-AI chatbot that answers from your site content via a private JSON dataset and an inline MiniSearch-compatible engine. Auto widget, smart daily cron (rebuild only on change, rotate filename daily), exclude rules with UI pickers, hardened security, and server/client rate limiting.
Version: 1.9.2
Author: Finland93
Author URI: https://github.com/Finland93
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: fast-site-search-chatbot
Tags: search, chatbot, local-search, site-search, faq, no-ai, privacy, instant-search, wp-search, lightweight
*/

if (!defined('ABSPATH')) exit;

class FSSC_Plugin {
  const DS_DIR     = 'fssc-dataset';
  const OPT_FILE   = 'fssc_dataset_file';
  const OPT_SIG    = 'fssc_content_sig';
  const CRON_HOOK  = 'fssc_daily_rebuild_event';

  // server rate limits
  const RL_PER_MIN  = 12;
  const RL_PER_HOUR = 200;
  const RL_MIN_TTL  = 60;
  const RL_HOUR_TTL = 3600;

  public function __construct() {
    // Settings & Admin
    add_action('admin_init', [$this, 'register_settings']);
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

    // Frontend
    add_action('wp_head',   [$this, 'print_inline_css']);
    add_action('wp_footer', [$this, 'render_widget_footer']);
    add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);

    // REST + AJAX
    add_action('rest_api_init', [$this, 'rest_routes']);
    add_action('wp_ajax_fssc_build_dataset', [$this, 'ajax_build_dataset']);

    // Cron: smart rebuild + rotate
    add_action(self::CRON_HOOK, [$this, 'cron_smart_rebuild_rotate']);

    // Activate/Deactivate
    register_activation_hook(__FILE__,  [__CLASS__, 'on_activate']);
    register_deactivation_hook(__FILE__,[__CLASS__, 'on_deactivate']);
  }

  /* ---------------- Activation / Deactivation ---------------- */

  public static function on_activate() {
    if (!wp_next_scheduled(self::CRON_HOOK)) {
      wp_schedule_event(time() + 60, 'daily', self::CRON_HOOK);
    }
  }
  public static function on_deactivate() {
    $ts = wp_next_scheduled(self::CRON_HOOK);
    if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
  }

  /* ---------------- Settings ---------------- */

  public function register_settings() {
    // position + results
    register_setting('fssc_settings', 'fssc_position', [
      'type'=>'string','default'=>'right',
      'sanitize_callback'=>fn($v)=> in_array($v,['left','right'],true)?$v:'right'
    ]);
    register_setting('fssc_settings','fssc_topk',[
      'type'=>'integer','default'=>5,'sanitize_callback'=>'absint'
    ]);

    // helper sanitizer (array of ids -> csv)
    $sanitize_ids = function($v){
      if (is_array($v)) {
        $ids = array_map('intval', $v);
        $ids = array_filter($ids, fn($x)=>$x>0);
        return implode(',', $ids);
      }
      return preg_replace('/[^0-9, ]/','',(string)$v);
    };

    // exclude pickers
    register_setting('fssc_settings', 'fssc_exclude_ids',  ['type'=>'string','default'=>'','sanitize_callback'=>$sanitize_ids]);
    register_setting('fssc_settings', 'fssc_exclude_cats', ['type'=>'string','default'=>'','sanitize_callback'=>$sanitize_ids]);
    register_setting('fssc_settings', 'fssc_exclude_tags', ['type'=>'string','default'=>'','sanitize_callback'=>$sanitize_ids]);

    // disable widget on pages
    register_setting('fssc_settings','fssc_disable_pages',['type'=>'string','default'=>'','sanitize_callback'=>$sanitize_ids]);

    // launcher colors (WP color picker)
    register_setting('fssc_settings','fssc_color_bg',['type'=>'string','default'=>'#111827','sanitize_callback'=>'sanitize_hex_color']);
    register_setting('fssc_settings','fssc_color_fg',['type'=>'string','default'=>'#ffffff','sanitize_callback'=>'sanitize_hex_color']);

    if (!get_option(self::OPT_FILE)) add_option(self::OPT_FILE, '');
    if (!get_option(self::OPT_SIG))  add_option(self::OPT_SIG,  '');
  }

  public function admin_menu() {
    add_menu_page('Fast Site Search Chatbot','Fast Chatbot','manage_options','fssc',[$this,'admin_page'],'dashicons-format-chat',82);
  }

  public function admin_assets($hook) {
    if ($hook !== 'toplevel_page_fssc') return;

    // color picker
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');

    // printable handle + inline JS
    wp_register_script('fssc-admin', false, [], '1.0.3', true);
    wp_enqueue_script('fssc-admin');

    $ajax  = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('fssc_build');

    $inline = <<<JS
(function(){
  // init color pickers
  jQuery(function($){ $('.fssc-color').wpColorPicker(); });

  // reusable checkbox filter + sync
  function bindCheckboxGroup(groupId, hiddenId, filterId){
    const box = document.getElementById(groupId);
    const hidden = document.getElementById(hiddenId);
    const filter = document.getElementById(filterId);
    if(!box || !hidden) return;

    function syncHidden(){
      const ids = Array.from(box.querySelectorAll('input[type="checkbox"]:checked')).map(el=>el.value);
      hidden.value = ids.join(',');
    }
    box.addEventListener('change', syncHidden);

    if (filter) {
      filter.addEventListener('input', function(){
        const q = this.value.toLowerCase().trim();
        box.querySelectorAll('label').forEach(lab=>{
          const txt = lab.getAttribute('data-text') || lab.textContent || '';
          lab.style.display = txt.toLowerCase().includes(q) ? '' : 'none';
        });
      });
    }
    // initial sync in case of pre-checked
    syncHidden();
  }

  // bind all groups
  bindCheckboxGroup('fssc-cats-box','fssc_exclude_cats','fssc-cats-filter');
  bindCheckboxGroup('fssc-tags-box','fssc_exclude_tags','fssc-tags-filter');
  bindCheckboxGroup('fssc-posts-box','fssc_exclude_ids','fssc-posts-filter');
  bindCheckboxGroup('fssc-pages-box','fssc_disable_pages','fssc-pages-filter');

  // dataset build button
  const btn = document.getElementById('fssc-build');
  const out = document.getElementById('fssc-output');
  const spinner = document.getElementById('fssc-spinner');
  const timeLbl = document.getElementById('fssc-elapsed');
  if(!btn||!out||!spinner||!timeLbl) return;

  let timer = null, start = 0;
  function startTimer(){ start=Date.now(); timeLbl.textContent='0.0s';
    if (timer) clearInterval(timer);
    timer=setInterval(()=>{ timeLbl.textContent=((Date.now()-start)/1000).toFixed(1)+'s'; },100);
  }
  function stopTimer(){ if(timer){ clearInterval(timer); timer=null; } }

  btn.addEventListener('click', async function(){
    btn.disabled = true; out.textContent = 'Building dataset…';
    spinner.style.display = 'inline-block'; startTimer();
    try{
      const fd = new FormData();
      fd.append('action','fssc_build_dataset');
      fd.append('_fssc_nonce','{$nonce}');
      const res = await fetch('{$ajax}',{method:'POST',body:fd,credentials:'same-origin'});
      const data = await res.json();
      if (data && data.success) {
        out.innerHTML = '✅ Dataset built (no rotation). ' +
          'Docs: <strong>'+ (data.data.count||0) +'</strong> • ' +
          'Size: '+ (data.data.size||0) +' bytes • ' +
          'Updated: '+ (data.data.mtime||'') +' • ' +
          'Filename: '+ (data.data.file||'') +
          ' • Time: ' + timeLbl.textContent;
      } else {
        out.textContent = (data && data.data && data.data.message) ? data.data.message : 'Build failed.';
      }
    } catch(e) {
      out.textContent='Error building dataset.';
    } finally {
      stopTimer(); spinner.style.display='none'; btn.disabled=false;
    }
  });
})();
JS;
    wp_add_inline_script('fssc-admin', $inline);
  }

  public function admin_page() {
    if (!current_user_can('manage_options')) return;

    $info  = $this->dataset_info();
    $pos   = get_option('fssc_position', 'right');
    $topk  = (int)get_option('fssc_topk', 5);
    $ids_s = get_option('fssc_exclude_ids', '');
    $cats_s= get_option('fssc_exclude_cats', '');
    $tags_s= get_option('fssc_exclude_tags', '');
    $file  = get_option(self::OPT_FILE, '');
    $bg    = get_option('fssc_color_bg', '#111827');
    $fg    = get_option('fssc_color_fg', '#ffffff');
    $disable_s = get_option('fssc_disable_pages','');

    $sel_ids  = $this->ids_from_csv($ids_s);
    $sel_cats = $this->ids_from_csv($cats_s);
    $sel_tags = $this->ids_from_csv($tags_s);
    $sel_dis  = $this->ids_from_csv($disable_s);

    $cats  = get_categories(['hide_empty'=>false]);
    $tags  = get_tags(['hide_empty'=>false]);
    $posts = get_posts(['post_type'=>['post','page'],'post_status'=>'publish','numberposts'=>500,'orderby'=>'date','order'=>'DESC']);
    $pages = get_pages(['sort_order'=>'DESC','sort_column'=>'post_date','post_status'=>'publish']);
    ?>
    <div class="wrap">
      <h1>Fast Site Search Chatbot</h1>

      <style>
        .fssc-admin-grid { display:grid; grid-template-columns: 240px 1fr; gap:18px; align-items:start; }
        .fssc-help { color:#6b7280; font-size:12px; margin-top:4px; }
        .codebox { background:#f8fafc; border:1px solid #e5e7eb; padding:10px; border-radius:8px; font-family:ui-monospace, SFMono-Regular, Menlo, monospace; white-space:pre-wrap; }
        .chk-wrap { border:1px solid #e5e7eb; border-radius:8px; padding:10px; max-height:260px; overflow:auto; background:#fff; }
        .chk-wrap label { display:block; padding:4px 6px; border-radius:6px; }
        .chk-wrap label:hover { background:#f8fafc; }
        .chk-filter { width:100%; max-width:420px; margin-bottom:8px; }
        #fssc-spinner { display:none; width:14px; height:14px; border:2px solid #cbd5e1; border-top-color:#111827; border-radius:50%; animation:fssc-spin 0.9s linear infinite; vertical-align:middle; margin-right:8px; }
        @keyframes fssc-spin { to { transform: rotate(360deg); } }
      </style>

      <form method="post" action="options.php">
        <?php settings_fields('fssc_settings'); ?>

        <div class="fssc-admin-grid">
          <label for="fssc_position"><strong>Widget position</strong></label>
          <div>
            <select id="fssc_position" name="fssc_position">
              <option value="right" <?php selected($pos,'right'); ?>>Right</option>
              <option value="left"  <?php selected($pos,'left');  ?>>Left</option>
            </select>
          </div>

          <label for="fssc_topk"><strong>Results to show</strong></label>
          <div>
            <input type="number" id="fssc_topk" name="fssc_topk" min="1" max="10" value="<?php echo (int)$topk; ?>" />
            <div class="fssc-help">How many article links to return per query.</div>
          </div>

          <label><strong>Launcher colors</strong></label>
          <div>
            <input type="text" class="fssc-color" id="fssc_color_bg" name="fssc_color_bg" value="<?php echo esc_attr($bg); ?>" />
            <label for="fssc_color_bg">Background</label>
            &nbsp;&nbsp;
            <input type="text" class="fssc-color" id="fssc_color_fg" name="fssc_color_fg" value="<?php echo esc_attr($fg); ?>" />
            <label for="fssc_color_fg">Icon</label>
          </div>

          <!-- Exclude categories -->
          <label for="fssc-cats-filter"><strong>Exclude categories</strong></label>
          <div>
            <input type="text" id="fssc-cats-filter" class="chk-filter" placeholder="Filter categories..." />
            <div id="fssc-cats-box" class="chk-wrap">
              <?php foreach ($cats as $c): ?>
                <label data-text="<?php echo esc_attr($c->name); ?>">
                  <input type="checkbox" value="<?php echo intval($c->term_id); ?>" <?php checked(in_array($c->term_id, $sel_cats, true)); ?> />
                  <?php echo esc_html($c->name); ?> (ID: <?php echo intval($c->term_id); ?>)
                </label>
              <?php endforeach; ?>
            </div>
            <input type="hidden" id="fssc_exclude_cats" name="fssc_exclude_cats" value="<?php echo esc_attr($cats_s); ?>" />
            <div class="fssc-help">Checked categories will be excluded from the dataset.</div>
          </div>

          <!-- Exclude tags -->
          <label for="fssc-tags-filter"><strong>Exclude tags</strong></label>
          <div>
            <input type="text" id="fssc-tags-filter" class="chk-filter" placeholder="Filter tags..." />
            <div id="fssc-tags-box" class="chk-wrap">
              <?php foreach ($tags as $t): ?>
                <label data-text="<?php echo esc_attr($t->name); ?>">
                  <input type="checkbox" value="<?php echo intval($t->term_id); ?>" <?php checked(in_array($t->term_id, $sel_tags, true)); ?> />
                  <?php echo esc_html($t->name); ?> (ID: <?php echo intval($t->term_id); ?>)
                </label>
              <?php endforeach; ?>
            </div>
            <input type="hidden" id="fssc_exclude_tags" name="fssc_exclude_tags" value="<?php echo esc_attr($tags_s); ?>" />
            <div class="fssc-help">Checked tags will be excluded from the dataset.</div>
          </div>

          <!-- Exclude specific posts/pages -->
          <label for="fssc-posts-filter"><strong>Exclude specific posts/pages</strong></label>
          <div>
            <input type="text" id="fssc-posts-filter" class="chk-filter" placeholder="Filter posts/pages..." />
            <div id="fssc-posts-box" class="chk-wrap">
              <?php foreach ($posts as $p): $nm = get_the_title($p); ?>
                <label data-text="<?php echo esc_attr('['.get_post_type($p).'] '.$nm); ?>">
                  <input type="checkbox" value="<?php echo intval($p->ID); ?>" <?php checked(in_array($p->ID, $sel_ids, true)); ?> />
                  [<?php echo esc_html(get_post_type($p)); ?>] <?php echo esc_html($nm); ?> (ID: <?php echo intval($p->ID); ?>)
                </label>
              <?php endforeach; ?>
            </div>
            <input type="hidden" id="fssc_exclude_ids" name="fssc_exclude_ids" value="<?php echo esc_attr($ids_s); ?>" />
            <div class="fssc-help">Checked posts/pages will be excluded from the dataset.</div>
          </div>

          <!-- Disable widget on pages -->
          <label for="fssc-pages-filter"><strong>Disable widget on pages</strong></label>
          <div>
            <input type="text" id="fssc-pages-filter" class="chk-filter" placeholder="Filter pages..." />
            <div id="fssc-pages-box" class="chk-wrap">
              <?php foreach ($pages as $pg): $nm = get_the_title($pg); ?>
                <label data-text="<?php echo esc_attr($nm); ?>">
                  <input type="checkbox" value="<?php echo intval($pg->ID); ?>" <?php checked(in_array($pg->ID, $sel_dis, true)); ?> />
                  <?php echo esc_html($nm); ?> (ID: <?php echo intval($pg->ID); ?>)
                </label>
              <?php endforeach; ?>
            </div>
            <input type="hidden" id="fssc_disable_pages" name="fssc_disable_pages" value="<?php echo esc_attr($disable_s); ?>" />
            <div class="fssc-help">Checked pages will not show the chat widget (e.g., checkout/landing).</div>
          </div>
        </div>

        <?php submit_button('Save Settings'); ?>
      </form>

      <hr>

      <p>
        <span id="fssc-spinner" aria-hidden="true"></span>
        <button class="button button-primary" id="fssc-build">Build Dataset</button>
        <span style="margin-left:10px;">Elapsed: <strong id="fssc-elapsed">0.0s</strong></span>
      </p>
      <div id="fssc-output">
        <?php if ($info): ?>
          ✅ Current dataset — Docs: <strong><?php echo intval($info['count']); ?></strong>,
          Size: <?php echo intval($info['size']); ?> bytes,
          Updated: <?php echo esc_html($info['mtime']); ?>,
          Filename: <?php echo esc_html($file ?: 'n/a'); ?>
        <?php else: ?>
          No dataset yet.
        <?php endif; ?>
      </div>

      <hr>
      <h2>Server rules (optional, extra hardening)</h2>
      <p class="fssc-help">Access to the dataset is already nonce + origin protected. For additional blocking at the web server:</p>
      <p><strong>Apache (.htaccess)</strong></p>
      <div class="codebox"># already written by plugin, for reference
Require all denied
Deny from all</div>

      <p><strong>Nginx / OpenLiteSpeed</strong></p>
      <div class="codebox"># Block direct access to dataset JSON files
location ~* /wp-content/uploads/fssc-dataset/.*\.json$ {
  deny all;
}</div>

      <p class="fssc-help">Add the Nginx block inside your site/server config and reload the server.</p>
    </div>
    <?php
  }

  /* ---------------- Frontend rendering ---------------- */

  public function frontend_assets() {
    wp_register_script(
      'fssc-chatbot',
      plugins_url('assets/chatbot.js', __FILE__),
      [],
      '1.9.2',
      true
    );
  }

  public function print_inline_css() {
    if (is_admin()) return;
    $pos  = get_option('fssc_position', 'right');
    $left = ($pos === 'left');
    $bg = get_option('fssc_color_bg', '#111827');
    $fg = get_option('fssc_color_fg', '#ffffff');
    ?>
<style id="fssc-inline-css">
  .fssc-root {
    position: fixed; <?php echo $left ? 'left:22px;' : 'right:22px;'; ?> bottom:22px;
    z-index: 99999;
    font-family: system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
  }
  .fssc-launcher {
    width: 56px; height: 56px; border-radius: 50%;
    background: <?php echo esc_html($bg); ?>; color: <?php echo esc_html($fg); ?>;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 12px 30px rgba(0,0,0,.18);
    cursor: pointer; border: 0; outline: none;
    transition: transform .12s ease, filter .12s ease;
  }
  .fssc-launcher:hover { filter: brightness(1.05); }
  .fssc-launcher:active { transform: scale(0.98); }
  .fssc-launcher:focus-visible { box-shadow: 0 0 0 3px rgba(37,99,235,.45); }
  .fssc-launcher.hidden { display: none; }
  .fssc-icon { width: 22px; height: 22px; display: inline-block; }

  .fssc-card {
    width: 360px; max-width: 92vw;
    background: #fff; border: 1px solid #e5e7eb; border-radius: 16px;
    box-shadow: 0 10px 25px rgba(0,0,0,.08);
    overflow: hidden; display: none;
  }
  .fssc-card.open { display: block; }

  .fssc-header {
    padding: 12px 14px; font-weight: 600; border-bottom: 1px solid #f1f5f9;
    display: flex; align-items: center; gap: 8px; justify-content: space-between;
  }
  .fssc-title { display: flex; align-items: center; gap: 8px; }
  .fssc-close {
    width: 40px; height: 40px; border-radius: 10px;
    border: 1px solid #e5e7eb; background: #fff; color: #111827;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    font-size: 22px; line-height: 1;
    transition: background-color .12s ease, transform .12s ease;
  }
  .fssc-close:hover { background: #f8fafc; }
  .fssc-close:active { transform: scale(0.98); }
  .fssc-close:focus-visible { box-shadow: 0 0 0 3px rgba(37,99,235,.35); outline: none; }

  .fssc-body { padding: 14px; max-height: 380px; overflow: auto; }
  .fssc-msg { margin: 10px 0; }
  .fssc-msg.user { text-align: right; }
  .fssc-bubble { display: inline-block; padding: 10px 12px; border-radius: 16px; line-height: 1.35; }
  .fssc-bubble.user { background: #eef2ff; }
  .fssc-bubble.bot  { background: #f8fafc; }

  .fssc-input { display: flex; gap: 8px; padding: 10px; border-top: 1px solid #f1f5f9; }
  .fssc-input input { flex: 1; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 10px; }
  .fssc-input input:focus { outline: none; box-shadow: 0 0 0 3px rgba(37,99,235,.25); }
  .fssc-input button { padding: 10px 14px; border: 0; border-radius: 10px; background: #111827; color: #fff; cursor: pointer; }
  .fssc-input button:hover { filter: brightness(1.05); }
  .fssc-footer { font-size: 12px; color: #6b7280; padding: 0 14px 12px; }

  .fssc-reslist { margin: 8px 0 0 18px; padding: 0; }
  .fssc-reslist li { margin: 6px 0; }
  .fssc-reslist a { color: #2563eb; text-decoration: none; }
  .fssc-reslist a:hover { text-decoration: underline; }

  @media (max-width: 480px) { .fssc-card { width: 94vw; } }
</style>
<?php
  }

  public function render_widget_footer() {
    if (is_admin()) return;

    // Disable on selected pages
    $disable = $this->ids_from_csv(get_option('fssc_disable_pages',''));
    if (!empty($disable) && is_page($disable)) return;

    $engine = $this->inline_minisearch_compat();
    wp_add_inline_script('fssc-chatbot', $engine, 'before');

    wp_enqueue_script('fssc-chatbot');
    $nonce = is_user_logged_in() ? wp_create_nonce('wp_rest') : wp_create_nonce('wp_rest_public');
    $title = get_bloginfo('name') . ' – Assistant';
    wp_add_inline_script('fssc-chatbot', 'window.FSSC='.wp_json_encode([
      'title'      => $title,
      'datasetUrl' => esc_url_raw( rest_url('fssc/v1/dataset') ),
      'nonce'      => $nonce,
      'clientMaxPerMinute' => self::RL_PER_MIN,
      'clientCooldownMs'   => 1200,
      'topK'               => (int)get_option('fssc_topk',5)
    ]).';', 'before');

    echo '<div id="fssc-root" class="fssc-root" aria-live="polite"></div>';
  }

  private function inline_minisearch_compat() {
    return <<<JS
(function(global){
  function tokenize(s){ return (s||'').toLowerCase().replace(/[^\\p{L}\\p{N}\\s]/gu,' ').split(/\\s+/).filter(Boolean); }
  function uniq(a){ return Array.from(new Set(a)); }
  function levenshtein(a,b){const m=a.length,n=b.length;if(!m)return n;if(!n)return m;const d=new Array(n+1);for(let j=0;j<=n;j++)d[j]=j;for(let i=1;i<=m;i++){let p=d[0],c;d[0]=i;for(let j=1;j<=n;j++){c=d[j];d[j]=a[i-1]===b[j-1]?p:1+Math.min(p,d[j-1],d[j]);p=c;}}return d[n];}
  function idf(N,df){ return Math.log(1 + (N / (1 + df))); }
  function MiniSearch(opts){ this.fields=(opts&&opts.fields)||['title','text']; this.storeFields=(opts&&opts.storeFields)||['id','title','url','text','date','type']; this.searchOptions=(opts&&opts.searchOptions)||{}; this.docs=[]; this.index={}; this.docMap={}; this.N=0; }
  MiniSearch.prototype.addAll=function(docs){ this.docs=docs.slice(); this.N=this.docs.length; const idx=this.index={}; const dm=this.docMap={};
    for(const d of docs){ const st={}; for(const k of this.storeFields){ st[k]=d[k]; } dm[d.id]=st; let toks=[]; for(const f of this.fields){ toks=toks.concat(tokenize(d[f])); }
      const counts={}; for(const t of toks){ counts[t]=(counts[t]||0)+1; }
      for(const [term,tf] of Object.entries(counts)){ if(!idx[term]) idx[term]={df:0,postings:{}}; const e=idx[term]; if(!e.postings[d.id]){ e.df++; e.postings[d.id]=0; } e.postings[d.id]+=tf; }
    }
  };
  MiniSearch.prototype._matchTerms=function(qTerms,opts){ const idx=this.index; const expanded=[]; const allowF=opts&&(opts.fuzzy===true||typeof opts.fuzzy==='number'); const maxF=typeof opts.fuzzy==='number'?opts.fuzzy:1; const allowP=opts&&opts.prefix;
    for(const qt of qTerms){ const set=new Set(); if(idx[qt]) set.add(qt);
      for(const term in idx){ if(term===qt) continue; if(allowP && term.startsWith(qt)) set.add(term); else if(allowF && Math.abs(term.length-qt.length)<=2 && levenshtein(term,qt)<=maxF) set.add(term); }
      expanded.push(set.size?Array.from(set):[qt]);
    } return expanded; };
  MiniSearch.prototype.search=function(query,opts){ opts=opts||{}; const combine=(opts.combineWith||'AND').toUpperCase(); const boost=(this.searchOptions&&this.searchOptions.boost)||{}; const qTerms=uniq(tokenize(query)); if(!qTerms.length) return [];
    const exp=this._matchTerms(qTerms,Object.assign({},this.searchOptions,opts)); const scores={}; const hits={};
    for(const variants of exp){ const seen=new Set(); for(const term of variants){ const e=this.index[term]; if(!e) continue; const w=idf(this.N,e.df);
        for(const [idStr,tf] of Object.entries(e.postings)){ const id=+idStr; let b=1; const doc=this.docMap[id]; if(doc && doc.title && doc.title.toLowerCase().includes(term)) b+=(boost.title||0);
          const add=(tf*w)*b; scores[id]=(scores[id]||0)+add; seen.add(id);
        } }
      for(const id of seen){ hits[id]=(hits[id]||0)+1; }
    }
    const needed=qTerms.length; let docs=Object.keys(scores).map(Number); if(combine==='AND'){ docs=docs.filter(id=>(hits[id]||0)>=needed); }
    docs.sort((a,b)=>scores[b]-scores[a]); return docs.map(id=>Object.assign({},this.docMap[id],{_score:scores[id]}));
  };
  global.MiniSearch=MiniSearch;
})(window);
JS;
  }

  /* ---------------- Dataset build & storage ---------------- */

  private function uploads_dir_secure() {
    $up = wp_upload_dir(null, false);
    $base = trailingslashit($up['basedir']) . self::DS_DIR;
    if (!file_exists($base)) wp_mkdir_p($base);
    $ht  = $base . '/.htaccess';
    if (!file_exists($ht))  @file_put_contents($ht, "Require all denied\nDeny from all");
    $idx = $base . '/index.html';
    if (!file_exists($idx)) @file_put_contents($idx, "");
    return $base;
  }

  private function dataset_filename_current() {
    $file = get_option(self::OPT_FILE, '');
    if (!$file) {
      $file = $this->generate_new_filename();
      update_option(self::OPT_FILE, $file, false);
    }
    return $file;
  }

  private function dataset_path_current() {
    return trailingslashit($this->uploads_dir_secure()) . $this->dataset_filename_current();
  }

  private function generate_new_filename() {
    $rand = wp_generate_password(32, false, false);
    return 'dataset-' . $rand . '.json';
  }

  private function purge_old_datasets($keepFilename = '') {
    $dir = $this->uploads_dir_secure();
    $items = @scandir($dir);
    if (!is_array($items)) return;
    foreach ($items as $f) {
      if ($f === '.' || $f === '..') continue;
      if ($f === '.htaccess' || $f === 'index.html') continue;
      if ($keepFilename && $f === $keepFilename) continue;
      if (substr($f, -5) === '.json') {
        @unlink($dir . '/' . $f);
      }
    }
  }

  private function ids_from_csv($csv) {
    if (!$csv) return [];
    $parts = array_filter(array_map('trim', explode(',', $csv)));
    return array_map('intval', $parts);
  }

  private function clean_text($html) {
    $html = strip_shortcodes($html);
    $html = preg_replace('#<(script|style)[^>]*>.*?</\\1>#is', ' ', $html);
    $html = preg_replace('#<img[^>]*>#i', ' ', $html);
    $text = wp_strip_all_tags($html);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
  }

  private function excerpt($text, $max = 1000) {
    return (mb_strlen($text) <= $max) ? $text : (mb_substr($text, 0, $max) . '…');
  }

  private function current_content_signature() {
    $cfg = implode('|', [
      get_option('fssc_exclude_ids',''),
      get_option('fssc_exclude_cats',''),
      get_option('fssc_exclude_tags',''),
    ]);

    $q = new WP_Query([
      'post_type'      => ['post','page'],
      'post_status'    => 'publish',
      'posts_per_page' => -1,
      'fields'         => 'ids',
      'no_found_rows'  => true,
      'orderby'        => 'modified',
      'order'          => 'DESC',
    ]);
    $ids = $q->posts;
    $count = count($ids);
    $last_mod = 0;
    $id_hash_input = [];

    foreach ($ids as $id) {
      $id_hash_input[] = $id;
      $t = get_post_modified_time('U', true, $id);
      if ($t && $t > $last_mod) $last_mod = $t;
    }

    $id_hash = md5(implode(',', $id_hash_input));
    return sha1($count . '|' . $last_mod . '|' . $id_hash . '|' . $cfg);
  }

  public function ajax_build_dataset() {
    if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Forbidden']);
    if (empty($_POST['_fssc_nonce']) || !wp_verify_nonce($_POST['_fssc_nonce'], 'fssc_build')) {
      wp_send_json_error(['message'=>'Invalid nonce']);
    }
    $result = $this->build_dataset(false);
    if (!empty($result['count']) || isset($result['size'])) {
      update_option(self::OPT_SIG, $this->current_content_signature(), false);
      wp_send_json_success($result);
    } else {
      wp_send_json_error(['message'=> isset($result['message'])?$result['message']:'Build failed']);
    }
  }

  public function cron_smart_rebuild_rotate() {
    $currentSig = $this->current_content_signature();
    $storedSig  = get_option(self::OPT_SIG, '');

    if ($storedSig !== $currentSig) {
      $meta = $this->build_dataset(true);
      if (!empty($meta['count']) || isset($meta['size'])) {
        update_option(self::OPT_SIG, $currentSig, false);
      }
    } else {
      $this->rotate_filename_without_rebuild();
    }
  }

  private function rotate_filename_without_rebuild() {
    $dir = $this->uploads_dir_secure();
    $oldPath = $this->dataset_path_current();

    if (!file_exists($oldPath)) {
      $this->build_dataset(true);
      return;
    }

    $newFile = $this->generate_new_filename();
    $newPath = trailingslashit($dir) . $newFile;

    @copy($oldPath, $newPath);
    @chmod($newPath, 0600);
    update_option(self::OPT_FILE, $newFile, false);
    $this->purge_old_datasets($newFile);
  }

  public function build_dataset($rotateFilename = false) {
    $exclude_ids  = $this->ids_from_csv(get_option('fssc_exclude_ids',''));
    $exclude_cats = $this->ids_from_csv(get_option('fssc_exclude_cats',''));
    $exclude_tags = $this->ids_from_csv(get_option('fssc_exclude_tags',''));

    $q_args = [
      'post_type'      => ['post','page'],
      'post_status'    => 'publish',
      'posts_per_page' => -1,
      'fields'         => 'ids',
      'no_found_rows'  => true,
      'orderby'        => 'date',
      'order'          => 'DESC',
    ];
    if (!empty($exclude_ids)) $q_args['post__not_in'] = $exclude_ids;

    $q = new WP_Query($q_args);
    $ids = $q->posts;

    if (!empty($exclude_cats) || !empty($exclude_tags)) {
      $ids = array_filter($ids, function($id) use ($exclude_cats, $exclude_tags) {
        if (get_post_type($id) !== 'post') return true;
        if (!empty($exclude_cats)) {
          $pc = wp_get_post_categories($id);
          if (array_intersect($pc, $exclude_cats)) return false;
        }
        if (!empty($exclude_tags)) {
          $pt = wp_get_post_tags($id, ['fields'=>'ids']);
          if (array_intersect($pt, $exclude_tags)) return false;
        }
        return true;
      });
    }

    $docs = [];
    foreach ($ids as $id) {
      $clean = $this->clean_text(get_post_field('post_content', $id));
      $docs[] = [
        'id'    => $id,
        'title' => html_entity_decode(get_the_title($id)),
        'url'   => get_permalink($id),
        'date'  => get_post_time('c', false, $id),
        'type'  => get_post_type($id),
        'text'  => $this->excerpt($clean, 1000),
      ];
    }

    $payload = [
      'generated_at' => gmdate('c'),
      'count'        => count($docs),
      'docs'         => array_values($docs),
    ];

    if ($rotateFilename) {
      $newFile = $this->generate_new_filename();
      update_option(self::OPT_FILE, $newFile, false);
      $this->purge_old_datasets($newFile);
      $path = trailingslashit($this->uploads_dir_secure()) . $newFile;
    } else {
      $path = $this->dataset_path_current();
    }

    $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $ok   = @file_put_contents($path, $json);
    if ($ok === false) return ['success'=>false,'message'=>'Failed to write dataset file.'];
    @chmod($path, 0600);

    $stat = @stat($path);
    return [
      'count' => count($docs),
      'size'  => $stat ? intval($stat['size']) : strlen($json),
      'mtime' => gmdate('c', $stat ? intval($stat['mtime']) : time()),
      'file'  => basename($path),
    ];
  }

  private function dataset_info() {
    $path = $this->dataset_path_current();
    if (!file_exists($path)) return null;
    $raw = @file_get_contents($path);
    if (!$raw) return null;
    $data = json_decode($raw, true);
    if (!$data) return null;
    $stat = @stat($path);
    return [
      'count' => intval($data['count'] ?? 0),
      'size'  => $stat ? intval($stat['size']) : strlen($raw),
      'mtime' => $stat ? gmdate('c', intval($stat['mtime'])) : '',
    ];
  }

  /* ---------------- REST (nonce, origin + server rate-limit) ---------------- */

  public function rest_routes() {
    register_rest_route('fssc/v1', '/dataset', [
      'methods'  => 'GET',
      'callback' => [$this, 'rest_dataset'],
      'permission_callback' => fn()=> true,
    ]);
  }

  private function check_rate_limit() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $keyMin  = 'fssc_rl_min_'  . md5($ip);
    $keyHour = 'fssc_rl_hour_' . md5($ip);

    $minCount  = (int)get_transient($keyMin);
    $hourCount = (int)get_transient($keyHour);

    if ($minCount  >= self::RL_PER_MIN)  return false;
    if ($hourCount >= self::RL_PER_HOUR) return false;

    set_transient($keyMin,  $minCount + 1,  self::RL_MIN_TTL);
    set_transient($keyHour, $hourCount + 1, self::RL_HOUR_TTL);
    return true;
  }

  public function rest_dataset(\WP_REST_Request $req) {
    $nonce = $req->get_header('x-wp-nonce');
    $action = is_user_logged_in() ? 'wp_rest' : 'wp_rest_public';
    if (!wp_verify_nonce($nonce, $action)) {
      return new WP_Error('forbidden','Invalid nonce', ['status'=>403]);
    }

    $homeHost = wp_parse_url(home_url(), PHP_URL_HOST);
    $okOrigin = true;
    $origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    foreach ([$origin, $referer] as $h) {
      if ($h) {
        $host = wp_parse_url($h, PHP_URL_HOST);
        if ($host && $host !== $homeHost) { $okOrigin = false; break; }
      }
    }
    if (!$okOrigin) return new WP_Error('forbidden','Cross-origin blocked', ['status'=>403]);

    if (!$this->check_rate_limit()) {
      return new WP_Error('too_many','Rate limit exceeded', ['status'=>429]);
    }

    $path = $this->dataset_path_current();
    if (!file_exists($path)) return new WP_Error('not_found','Dataset not built', ['status'=>404]);
    $raw = @file_get_contents($path);
    if (!$raw) return new WP_Error('server_error','Unable to read dataset', ['status'=>500]);

    $response = new WP_REST_Response(json_decode($raw, true));
    $response->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=300');
    $response->header('X-Content-Type-Options', 'nosniff');
    return $response;
  }
}

new FSSC_Plugin();
