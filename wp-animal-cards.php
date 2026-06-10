<?php
/**
 * Simabo — Animal Cards (printable)
 * --------------------------------------------------------------------------
 * Serves a print-ready A4 card page for the shelter animals, directly on the
 * Simabo site, reading the data server-side (no API call, no CORS).
 *
 *   PAGE :  https://simabo.org/animal-cards   ->  pick an animal, print to PDF
 *   API  :  https://simabo.org/wp-json/simabo/v1/animals   (optional, JSON)
 *
 * It only ADDS a read-only page + endpoint — nothing on the public site
 * changes.
 *
 * INSTALL: paste into the "Code Snippets" plugin (Snippets -> Add New ->
 * *Run everywhere* -> Save & Activate), or append to the child theme's
 * functions.php. The pretty URL /animal-cards is registered on first load;
 * if it 404s, go to Settings -> Permalinks and click Save once.
 * --------------------------------------------------------------------------
 */

/* =========================================================================
 * 1. DATA — build a clean record per animal
 * ====================================================================== */

/** Flat map of a post's custom fields: ACF first, raw meta as fallback. */
function simabo_collect_fields($post_id) {
    $map = array();
    foreach (get_post_meta($post_id) as $key => $vals) {
        if (strpos($key, '_') === 0) continue;
        $v = is_array($vals) ? reset($vals) : $vals;
        if ($v !== '' && $v !== null) $map[strtolower($key)] = $v;
    }
    if (function_exists('get_fields')) {
        $acf = get_fields($post_id);
        if (is_array($acf)) {
            foreach ($acf as $key => $v) {
                if ($v === '' || $v === null || $v === false) continue;
                $map[strtolower($key)] = $v;
            }
        }
    }
    return $map;
}

/** First non-empty value among the given alias keys (tags stripped, entities decoded). */
function simabo_pick($map, $aliases) {
    foreach ($aliases as $a) {
        $a = strtolower($a);
        if (isset($map[$a]) && $map[$a] !== '' && $map[$a] !== null) {
            $v = $map[$a];
            if (is_array($v)) $v = implode(', ', array_filter($v, 'is_scalar'));
            $v = wp_strip_all_tags((string) $v);
            return trim(html_entity_decode($v, ENT_QUOTES, 'UTF-8'));
        }
    }
    return '';
}

/** Comma-joined term names of a taxonomy. */
function simabo_terms($post_id, $taxonomy) {
    $terms = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'names'));
    return (!is_wp_error($terms) && $terms) ? implode(', ', $terms) : '';
}

/** Reduce any birth value ("10/10/2010", "20101010", ...) to the year only. */
function simabo_year($val) {
    return ($val && preg_match('/(?:19|20)\d{2}/', $val, $m)) ? $m[0] : $val;
}

/** Clean a bio: drop stray URLs (e.g. video links) and tidy blank lines. */
function simabo_clean_bio($t) {
    $t = preg_replace('#https?://\S+#i', '', $t);
    $t = preg_replace("/[ \t]+/", ' ', $t);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return trim($t);
}

function simabo_animal_payload($post) {
    $id    = $post->ID;
    $map   = simabo_collect_fields($id);
    $image = get_the_post_thumbnail_url($id, 'large');
    if (!$image) $image = get_the_post_thumbnail_url($id, 'full');

    return array(
        'id'      => $id,
        'name'    => html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'),
        'slug'    => $post->post_name,
        'link'    => get_permalink($id),
        'species' => simabo_terms($id, 'species'),
        'image'   => $image ? $image : '',
        'sex'     => simabo_pick($map, array('sex', 'gender', 'sesso')),
        'birth'   => simabo_year(simabo_pick($map, array('age', 'birth', 'date_of_birth', 'dob', 'birthday', 'data_di_nascita', 'nascita'))),
        'size'    => simabo_pick($map, array('size', 'taglia')),
        'status'  => simabo_terms($id, 'status'),
        'bio'     => simabo_clean_bio(simabo_pick($map, array('story', 'bio', 'biography', 'description', 'about', 'storia', 'descrizione'))),
    );
}

/** All published animals, English only (WPML), alphabetical. */
function simabo_all_animals() {
    $posts = get_posts(array(
        'post_type'      => 'animal',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
        'suppress_filters' => false, // let WPML scope the query to the current language
    ));

    // The site is multilingual (WPML): each animal exists in several languages.
    // Keep only the English version of each.
    $english = array();
    foreach ($posts as $p) {
        $lang = apply_filters('wpml_post_language_details', null, $p->ID);
        if (is_array($lang) && !empty($lang['language_code'])) {
            if ($lang['language_code'] === 'en') $english[] = $p;
        } else {
            $english[] = $p; // WPML unavailable -> keep
        }
    }
    return array_map('simabo_animal_payload', $english);
}

/* =========================================================================
 * 2. OPTIONAL REST ENDPOINT — /wp-json/simabo/v1/animals
 * ====================================================================== */

add_action('rest_api_init', function () {
    register_rest_route('simabo/v1', '/animals', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function (WP_REST_Request $req) {
            $slug = sanitize_title($req->get_param('slug'));
            if ($slug) {
                $p = get_posts(array('post_type' => 'animal', 'name' => $slug, 'posts_per_page' => 1));
                return rest_ensure_response($p ? array(simabo_animal_payload($p[0])) : array());
            }
            return rest_ensure_response(simabo_all_animals());
        },
    ));
});

/* =========================================================================
 * 3. PRINT PAGE — /animal-cards
 * ====================================================================== */

add_action('init', function () {
    add_rewrite_rule('^animal-cards/?$', 'index.php?animal_cards=1', 'top');
    if (get_option('simabo_cards_rw') !== '1') {
        flush_rewrite_rules();
        update_option('simabo_cards_rw', '1');
    }
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'animal_cards';
    return $vars;
});

add_action('template_redirect', function () {
    if (!get_query_var('animal_cards')) return;
    $json = wp_json_encode(simabo_all_animals());   // slashes escaped -> safe inline
    header('Content-Type: text/html; charset=utf-8');
    echo str_replace('__ANIMALS_JSON__', $json, simabo_cards_template());
    exit;
});

/** The self-contained A4 card page. __ANIMALS_JSON__ is replaced with data. */
function simabo_cards_template() {
    return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Simabo · Animal Cards</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,400;0,700;0,900;1,400&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js" integrity="sha384-8FWZA6BGMXhsfO+BLtrJK0We6gg5o1JyO8xQm6peWDEUs17ACA5ziE/NIAkl9z2k" crossorigin="anonymous"></script>
<style>
  :root{
    --teal:#5FB6B1; --teal-deep:#2f8a84; --teal-ink:#1f5f5b;
    --gold:#FFC022; --coral:#FF5C5C;
    --ink:#1d2b29; --muted:#5d716e;
    --mist:#eef7f6; --paper:#ffffff;
  }
  *{box-sizing:border-box;}
  html,body{margin:0;padding:0;}
  body{font-family:"Roboto",system-ui,sans-serif;color:var(--ink);background:#d7ecea;}

  /* ---- Toolbar (screen only) ---- */
  .toolbar{position:sticky;top:0;z-index:10;display:flex;gap:12px;align-items:center;flex-wrap:wrap;
    padding:14px 22px;background:#fff;border-bottom:1px solid #e3eeed;box-shadow:0 2px 14px rgba(0,0,0,.05);}
  .toolbar h1{font-size:14px;margin:0 10px 0 0;font-weight:700;letter-spacing:.3px;text-transform:uppercase;}
  .toolbar h1 span{color:var(--teal-deep);}
  .toolbar select{font-family:inherit;font-size:14px;padding:9px 13px;border:1px solid #cdddda;border-radius:10px;background:#fff;min-width:250px;}
  .toolbar button{font-family:inherit;font-size:14px;font-weight:700;cursor:pointer;padding:10px 20px;border:none;border-radius:10px;color:#fff;background:var(--teal-deep);box-shadow:0 3px 10px rgba(47,138,132,.3);}
  .toolbar button:disabled{opacity:.5;cursor:default;box-shadow:none;}
  .toolbar button:not(:disabled):hover{filter:brightness(1.05);}
  .toolbar .meta{font-size:12px;color:#7c918e;margin-left:auto;}
  .toolbar .meta.error{color:#c0392b;font-weight:600;}

  .stage{display:flex;justify-content:center;padding:28px 16px 70px;}

  /* ====== A4 CARD ====== */
  .page{width:210mm;height:297mm;background:var(--teal);padding:6mm;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;box-shadow:0 18px 50px rgba(31,95,91,.28);}
  .card{background:var(--teal);height:100%;overflow:hidden;
    display:flex;flex-direction:column;position:relative;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .inner{flex:1 1 auto;min-height:0;display:flex;flex-direction:column;
    background:var(--paper);border-radius:2mm;overflow:hidden;}

  /* brand bar */
  .brand{background:var(--teal);display:flex;align-items:center;justify-content:space-between;
    padding:0 8mm 3.5mm;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .brand img{height:11mm;width:auto;}
  .pill{font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;
    background:var(--gold);color:#fff;padding:5px 14px;border-radius:999px;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;}

  /* portrait */
  .portrait{background:var(--mist);flex:0 0 auto;height:112mm;display:flex;align-items:center;justify-content:center;
    padding:7mm;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .portrait img{max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain;
    border-radius:2.5mm;box-shadow:0 10px 26px rgba(31,95,91,.18);}
  .portrait .ph{color:#aac6c3;font-size:14px;letter-spacing:.5px;}

  /* identity */
  .ident{padding:7mm 8mm 3mm;}
  .name{font-family:"Lato",sans-serif;font-weight:900;
    font-size:44px;line-height:1;letter-spacing:-.5px;color:var(--ink);margin:0;}
  .rule{width:54px;height:4px;background:var(--gold);border-radius:3px;margin:4mm 0 0;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;}

  .meta{display:grid;grid-template-columns:1fr 1fr;gap:0 8mm;margin:5mm 0 0;}
  .meta .cell{padding:3mm 0;border-top:1px solid #e7efee;}
  .meta dt{font-size:10.5px;font-weight:700;letter-spacing:1.3px;text-transform:uppercase;color:var(--teal-deep);margin:0 0 1.5mm;}
  .meta dd{margin:0;font-size:16px;font-weight:600;color:var(--ink);}

  /* story */
  .story{padding:4mm 8mm 16mm;flex:1 1 auto;font-size:13.5px;line-height:1.62;color:#33403e;}
  .story p{margin:0 0 9px;}
  .story p:last-child{margin-bottom:0;}

  /* footer CTA */
  .cta{margin-top:auto;background:var(--teal);color:#fff;display:flex;align-items:center;
    position:relative;padding:7mm 44mm 5mm 0;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .cta .url{font-size:13px;color:rgba(255,255,255,.92);letter-spacing:.2px;word-break:break-all;}
  .cta a{color:inherit;text-decoration:none;}
  .qr{position:absolute;right:8mm;bottom:100%;transform:translateY(50%);z-index:2;
    width:30mm;height:30mm;background:#fff;border:1px solid rgba(0,0,0,.10);border-radius:2mm;padding:2mm;
    box-shadow:0 3px 12px rgba(31,95,91,.35);display:flex;align-items:center;justify-content:center;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .qr svg{width:100%;height:100%;display:block;}

  /* ---- Print ---- */
  @page{size:A4 portrait;margin:0;}
  @media print{
    body{background:#fff;}
    .toolbar{display:none;}
    .stage{padding:0;}
    .page{box-shadow:none;margin:0;width:210mm;height:297mm;}
    .portrait img{box-shadow:none;border:1px solid #e4efee;}
    .qr{box-shadow:none;}
    #wpadminbar{display:none!important;}
  }
</style>
</head>
<body>
<div class="toolbar">
  <h1>Si<span>ma</span>bo · Animal Cards</h1>
  <select id="statusFilter" aria-label="Filter by status"></select>
  <select id="picker" aria-label="Choose an animal"></select>
  <button id="printBtn" type="button" disabled>Print / Save PDF</button>
  <button id="qrBtn" type="button" disabled>Download QR</button>
  <span class="meta" id="meta"></span>
</div>

<div class="stage">
  <div class="page">
    <div class="card">
      <header class="brand">
        <img src="https://simabo.org/wp-content/uploads/2019/08/simabo-logo-white-shadow-1.png" alt="Simabo">
        <span class="pill" id="species" hidden></span>
      </header>
      <div class="inner">
        <div class="portrait" id="portrait"><span class="ph">—</span></div>
        <section class="ident">
          <h1 class="name" id="name">—</h1>
          <div class="rule"></div>
          <dl class="meta" id="meta-grid"></dl>
        </section>
        <section class="story" id="story"></section>
      </div>
      <footer class="cta">
        <a class="url" id="url" href="#"></a>
        <div class="qr" id="qr" aria-hidden="true" hidden></div>
      </footer>
    </div>
  </div>
</div>

<script>window.SIMABO_ANIMALS = __ANIMALS_JSON__;</script>
<script>
(function(){
  const DATA = (window.SIMABO_ANIMALS || []).slice()
    .sort((a,b)=>(a.name||'').toLowerCase().localeCompare((b.name||'').toLowerCase()));
  const picker=document.getElementById('picker');
  const statusFilter=document.getElementById('statusFilter');
  const meta=document.getElementById('meta');
  const printBtn=document.getElementById('printBtn');
  const qrBtn=document.getElementById('qrBtn');
  let VIEW=DATA, current=null;

  function esc(s){return String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
  function cap(s){return s?s.charAt(0).toUpperCase()+s.slice(1):s;}
  function cell(label,v){return v?`<div class="cell"><dt>${label}</dt><dd>${esc(cap(v))}</dd></div>`:'';}
  function statusesOf(a){return a.status?String(a.status).split(/,\s*/).filter(Boolean):[];}

  function render(a){
    if(!a) return;
    current=a;
    document.getElementById('name').textContent=a.name||'—';

    const sp=document.getElementById('species');
    if(a.species){sp.hidden=false;sp.textContent=a.species;} else sp.hidden=true;

    const portrait=document.getElementById('portrait');
    portrait.innerHTML=a.image?`<img src="${esc(a.image)}" alt="${esc(a.name)}">`:`<span class="ph">No photo</span>`;

    document.getElementById('meta-grid').innerHTML=
      cell('Sex',a.sex)+cell('Born',a.birth)+cell('Size',a.size)+cell('Status',a.status);

    const story=document.getElementById('story');
    story.innerHTML=a.bio?String(a.bio).split(/\n+/).filter(Boolean).map(p=>`<p>${esc(p)}</p>`).join(''):'';

    const url=document.getElementById('url');
    if(a.link){url.href=a.link;url.textContent=a.link;}
    else{url.textContent='';url.removeAttribute('href');}

    const qrBox=document.getElementById('qr');
    if(a.link && window.qrcode){
      const qr=qrcode(0,'M'); qr.addData(a.link); qr.make();
      qrBox.innerHTML=qr.createSvgTag({cellSize:2,margin:0}); qrBox.hidden=false;
    } else { qrBox.innerHTML=''; qrBox.hidden=true; }
    if(qrBtn) qrBtn.disabled=!(a.link && window.qrcode);

    fit();
  }

  // Build a high-res PNG of the current animal's QR and download it.
  function downloadQR(){
    if(!current || !current.link || !window.qrcode) return;
    const qr=qrcode(0,'M'); qr.addData(current.link); qr.make();
    const n=qr.getModuleCount(), margin=4, scale=Math.ceil(600/(n+margin*2));
    const size=(n+margin*2)*scale;
    const cv=document.createElement('canvas'); cv.width=cv.height=size;
    const ctx=cv.getContext('2d');
    ctx.fillStyle='#fff'; ctx.fillRect(0,0,size,size);
    ctx.fillStyle='#000';
    for(let r=0;r<n;r++)for(let c=0;c<n;c++)
      if(qr.isDark(r,c)) ctx.fillRect((c+margin)*scale,(r+margin)*scale,scale,scale);
    cv.toBlob(b=>{
      const a=document.createElement('a');
      a.href=URL.createObjectURL(b);
      a.download='simabo-qr-'+(current.slug||'animal')+'.png';
      a.click(); URL.revokeObjectURL(a.href);
    },'image/png');
  }

  // Shrink the story text just enough to keep the whole card on one A4 page.
  function fit(){
    const box=document.querySelector('.inner');
    const story=document.getElementById('story');
    let size=13.5; story.style.fontSize=size+'px';
    let guard=0;
    while(box.scrollHeight>box.clientHeight+1 && size>9 && guard<50){
      size-=0.5; story.style.fontSize=size+'px'; guard++;
    }
  }

  function fillStatusFilter(){
    const set=new Set(); let hasNone=false;
    DATA.forEach(a=>{const s=statusesOf(a); s.length?s.forEach(x=>set.add(x)):hasNone=true;});
    let opts='<option value="all">All statuses</option>';
    [...set].sort((a,b)=>a.localeCompare(b)).forEach(s=>opts+=`<option value="${esc(s)}">${esc(s)}</option>`);
    if(hasNone) opts+='<option value="__none__">(no status)</option>';
    statusFilter.innerHTML=opts;
  }

  function fillPicker(){
    picker.innerHTML='';
    VIEW.forEach((a,i)=>{const o=document.createElement('option');o.value=i;o.textContent=a.species?`${a.name} · ${a.species}`:a.name;picker.appendChild(o);});
  }

  function applyFilter(){
    const f=statusFilter.value;
    VIEW = f==='all' ? DATA
      : DATA.filter(a=>{const s=statusesOf(a); return f==='__none__'?s.length===0:s.includes(f);});
    fillPicker();
    meta.textContent=`${VIEW.length} of ${DATA.length} animals · live`;
    if(VIEW.length) render(VIEW[0]);
  }

  if(!DATA.length){meta.classList.add('error');meta.textContent='No animals found.';return;}
  fillStatusFilter();
  applyFilter();
  printBtn.disabled=false;
  statusFilter.addEventListener('change',applyFilter);
  picker.addEventListener('change',()=>render(VIEW[picker.value]));
  printBtn.addEventListener('click',()=>window.print());
  qrBtn.addEventListener('click',downloadQR);
  if(document.fonts&&document.fonts.ready){document.fonts.ready.then(fit);}
  window.addEventListener('beforeprint',fit);
})();
</script>
</body>
</html>
HTML;
}
