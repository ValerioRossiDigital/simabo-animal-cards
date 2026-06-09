<?php
/**
 * Simabo — Animal Cards REST endpoint
 * --------------------------------------------------------------------------
 * Exposes the animals' data (name, photo, species, size, sex, birth, status,
 * bio) as clean JSON so the printable-cards page (GitHub Pages) can read it
 * live from the browser. WordPress already sends CORS headers on REST routes,
 * so no extra config is needed.
 *
 * INSTALL: paste this into the site via the "Code Snippets" plugin
 * (Snippets → Add New → Run everywhere → Save & Activate), or append it to the
 * child theme's functions.php. It only ADDS a read-only endpoint — it changes
 * nothing on the public site.
 *
 * TEST after install:
 *   https://simabo.org/wp-json/simabo/v1/animals            (all animals)
 *   https://simabo.org/wp-json/simabo/v1/animals?slug=nancy (one animal)
 *
 * The response includes a temporary "_keys" array listing the available
 * custom-field names — useful to confirm the field mapping below is correct.
 * Once confirmed, the "_keys" line can be removed.
 * --------------------------------------------------------------------------
 */

add_action('rest_api_init', function () {
    register_rest_route('simabo/v1', '/animals', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => 'simabo_animals_endpoint',
    ));
});

/**
 * Build a flat map of every custom field for a post: ACF fields first
 * (real names), then raw post meta as fallback. Covers ACF, JetEngine, Pods,
 * or plain meta — whatever the site uses.
 */
function simabo_collect_fields($post_id) {
    $map = array();

    // Raw post meta (single value each), skipping internal "_" keys.
    foreach (get_post_meta($post_id) as $key => $vals) {
        if (strpos($key, '_') === 0) continue;
        $v = is_array($vals) ? reset($vals) : $vals;
        if ($v !== '' && $v !== null) $map[strtolower($key)] = $v;
    }

    // ACF (if present) — overrides meta with nicely formatted values.
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

/** Return the first non-empty value among the given alias keys. */
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

function simabo_animal_payload($post) {
    $id     = $post->ID;
    $map    = simabo_collect_fields($id);
    $terms  = wp_get_post_terms($id, 'species', array('fields' => 'names'));
    $image  = get_the_post_thumbnail_url($id, 'large');
    if (!$image) $image = get_the_post_thumbnail_url($id, 'full');

    return array(
        'id'      => $id,
        'name'    => html_entity_decode(get_the_title($id), ENT_QUOTES),
        'slug'    => $post->post_name,
        'link'    => get_permalink($id),
        'species' => (!is_wp_error($terms) && $terms) ? $terms[0] : '',
        'image'   => $image ? $image : '',
        'sex'     => simabo_pick($map, array('sex', 'gender', 'sesso')),
        'birth'   => simabo_pick($map, array('birth', 'date_of_birth', 'dob', 'birthday', 'birth_date', 'data_di_nascita', 'nascita')),
        'size'    => simabo_pick($map, array('size', 'taglia')),
        'status'  => simabo_pick($map, array('status', 'stato', 'adoption_status')),
        'bio'     => simabo_pick($map, array('bio', 'biography', 'description', 'story', 'about', 'storia', 'descrizione', 'presentazione')),
        // TEMP: lists available field names so the mapping above can be verified.
        '_keys'   => array_keys($map),
    );
}

function simabo_animals_endpoint(WP_REST_Request $req) {
    $args = array(
        'post_type'      => 'animal',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    );
    $slug = sanitize_title($req->get_param('slug'));
    if ($slug) $args['name'] = $slug;

    $posts = get_posts($args);
    return rest_ensure_response(array_map('simabo_animal_payload', $posts));
}
