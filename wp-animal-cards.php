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

/** First non-empty value among the given alias keys. */
function simabo_pick($map, $aliases) {
    foreach ($aliases as $a) {
        $a = strtolower($a);
        if (isset($map[$a]) && $map[$a] !== '' && $map[$a] !== null) {
            $v = $map[$a];
            if (is_array($v)) $v = implode(', ', array_filter($v, 'is_scalar'));
            return trim(wp_strip_all_tags((string) $v));
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

function simabo_animal_payload($post) {
    $id    = $post->ID;
    $map   = simabo_collect_fields($id);
    $image = get_the_post_thumbnail_url($id, 'large');
    if (!$image) $image = get_the_post_thumbnail_url($id, 'full');

    return array(
        'id'      => $id,
        'name'    => html_entity_decode(get_the_title($id), ENT_QUOTES),
        'slug'    => $post->post_name,
        'link'    => get_permalink($id),
        'species' => simabo_terms($id, 'species'),
        'image'   => $image ? $image : '',
        'sex'     => simabo_pick($map, array('sex', 'gender', 'sesso')),
        'birth'   => simabo_year(simabo_pick($map, array('age', 'birth', 'date_of_birth', 'dob', 'birthday', 'data_di_nascita', 'nascita'))),
        'size'    => simabo_pick($map, array('size', 'taglia')),
        'status'  => simabo_terms($id, 'status'),
        'bio'     => simabo_pick($map, array('story', 'bio', 'biography', 'description', 'about', 'storia', 'descrizione')),
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
    // Flush rewrite rules once (bumped version string forces a re-flush).
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
<link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=DM+Sans:ital,wght@0,400;0,500;0,700;1,400&display=swap" rel="stylesheet">
<style>
  :root{--teal:#65BBB6;--name-band:#9ed6a0;--name-text:#3f7d53;--bio-bg:#e8f4ea;--bio-text:#3f7d53;--ink:#2A2B2D;--line:#e3e3e3;}
  *{box-sizing:border-box;}
  html,body{margin:0;padding:0;}
  body{font-family:"DM Sans",system-ui,sans-serif;color:var(--ink);background:#cfe7e5;}
  .toolbar{position:sticky;top:0;z-index:10;display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:14px 20px;background:#fff;border-bottom:1px solid var(--line);box-shadow:0 2px 10px rgba(0,0,0,.06);}
  .toolbar h1{font-size:15px;margin:0 12px 0 0;font-weight:700;letter-spacing:.2px;}
  .toolbar h1 span{color:var(--teal);}
  .toolbar select{font-family:inherit;font-size:14px;padding:8px 12px;border:1px solid #cdd6d6;border-radius:8px;background:#fff;min-width:240px;}
  .toolbar button{font-family:inherit;font-size:14px;font-weight:700;cursor:pointer;padding:9px 18px;border:none;border-radius:8px;color:#fff;background:var(--teal);}
  .toolbar button:disabled{opacity:.5;cursor:default;}
  .toolbar button:not(:disabled):hover{filter:brightness(.95);}
  .toolbar .meta{font-size:12px;color:#7a8585;margin-left:auto;}
  .toolbar .meta.error{color:#c0392b;font-weight:600;}
  .stage{display:flex;justify-content:center;padding:24px 16px 60px;}
  .card{width:210mm;min-height:297mm;background:var(--teal);padding:9mm;display:flex;flex-direction:column;-webkit-print-color-adjust:exact;print-color-adjust:exact;box-shadow:0 8px 30px rgba(0,0,0,.18);}
  .sheet{background:#fff;flex:1;display:flex;flex-direction:column;border:2px solid #fff;overflow:hidden;}
  .logo{background:var(--teal);text-align:center;padding:7mm 0 6mm;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .logo img{height:13mm;width:auto;}
  .photo{height:108mm;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;}
  .photo img{width:100%;height:100%;object-fit:contain;object-position:center;}
  .photo .placeholder{color:#bcbcbc;font-size:14px;}
  .name{background:#fff;text-align:center;padding:5mm 6mm;margin:0;}
  .name span{font-family:"Caveat",cursive;font-size:54px;line-height:1;color:#111;font-weight:700;}
  .details{padding:5mm 7mm 3mm;}
  .row{display:flex;gap:10px;padding:7px 0;border-bottom:1px solid #eef2f2;font-size:15px;}
  .row:last-child{border-bottom:none;}
  .row .k{font-weight:700;min-width:42mm;color:#111;}
  .row .v{color:#111;}
  .bio{background:#fff;color:#111;margin:0;padding:2mm 7mm;font-size:14px;line-height:1.5;flex:0 0 auto;}
  .bio p{margin:0 0 8px;}
  .bio p:last-child{margin-bottom:0;}
  .footer{margin-top:auto;padding:5mm 7mm 6mm;font-size:13px;line-height:1.5;}
  .footer .support{color:#111;font-style:italic;font-weight:500;}
  .footer .email{font-style:italic;}
  .footer a{color:inherit;text-decoration:none;}
  @page{size:A4 portrait;margin:0;}
  @media print{
    body{background:#fff;}
    .toolbar,.stage{padding:0;}
    .toolbar{display:none;}
    .card{box-shadow:none;margin:0;width:210mm;height:297mm;}
    /* hide the WordPress admin bar if logged in */
    #wpadminbar{display:none!important;}
  }
</style>
</head>
<body>
<div class="toolbar">
  <h1>SI<span>MA</span>BO · Animal Cards</h1>
  <select id="picker" aria-label="Choose an animal"></select>
  <button id="printBtn" type="button" disabled>Print / Save PDF</button>
  <span class="meta" id="meta"></span>
</div>
<div class="stage">
  <div class="card" id="card">
    <div class="sheet">
      <div class="logo"><img src="https://simabo.org/wp-content/uploads/2019/08/simabo-logo-white-shadow-1.png" alt="Simabo"></div>
      <div class="photo" id="photo"><span class="placeholder">—</span></div>
      <div class="name"><span id="name">—</span></div>
      <div class="details" id="details"></div>
      <div class="bio" id="bio" hidden></div>
      <div class="footer">
        <div class="support" id="support"></div>
        <div class="email">Send an email to <a href="mailto:info@simabo.org">info@simabo.org</a></div>
      </div>
    </div>
  </div>
</div>
<script>window.SIMABO_ANIMALS = __ANIMALS_JSON__;</script>
<script>
(function(){
  const DATA = (window.SIMABO_ANIMALS || []).slice()
    .sort((a,b)=>(a.name||'').toLowerCase().localeCompare((b.name||'').toLowerCase()));
  const picker=document.getElementById('picker');
  const meta=document.getElementById('meta');
  const printBtn=document.getElementById('printBtn');

  function esc(s){return String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
  function cap(s){return s?s.charAt(0).toUpperCase()+s.slice(1):s;}
  function rowHtml(k,v){return v?`<div class="row"><div class="k">${k}</div><div class="v">${esc(v)}</div></div>`:'';}

  function render(a){
    if(!a) return;
    document.getElementById('name').textContent=a.name||'—';
    const photo=document.getElementById('photo');
    photo.innerHTML=a.image?`<img src="${esc(a.image)}" alt="${esc(a.name)}">`:`<span class="placeholder">No photo</span>`;
    document.getElementById('details').innerHTML=
      rowHtml('Type:',a.species)+rowHtml('Sex:',cap(a.sex))+rowHtml('Date of birth:',a.birth)+
      rowHtml('Size:',a.size)+rowHtml('Status:',a.status);
    const bio=document.getElementById('bio');
    if(a.bio){bio.hidden=false;bio.innerHTML=String(a.bio).split(/\n+/).filter(Boolean).map(p=>`<p>${esc(p)}</p>`).join('');}
    else{bio.hidden=true;bio.innerHTML='';}
    const link=a.link||'';
    document.getElementById('support').innerHTML=link?`Support ${esc(a.name)}: <a href="${esc(link)}">${esc(link)}</a>`:'';
  }

  if(!DATA.length){meta.classList.add('error');meta.textContent='No animals found.';return;}
  DATA.forEach((a,i)=>{const o=document.createElement('option');o.value=i;o.textContent=a.species?`${a.name} · ${a.species}`:a.name;picker.appendChild(o);});
  printBtn.disabled=false;
  meta.textContent=`${DATA.length} animals · live`;
  picker.addEventListener('change',()=>render(DATA[picker.value]));
  printBtn.addEventListener('click',()=>window.print());
  render(DATA[0]);
})();
</script>
</body>
</html>
HTML;
}
