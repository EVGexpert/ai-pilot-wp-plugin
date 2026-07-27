<?php
/**
 * AI Pilot — Comprehensive WordPress Mock Environment
 *
 * Standalone PHP 7.4+ mock of WordPress core APIs used by the
 * AI Pilot plugin. No Composer, no WordPress installation required.
 *
 * Design goals:
 *   - Capture REST route registrations into a registry that tests can inspect
 *   - Provide an in-memory datastore for options, posts, categories, tags,
 *     menus, users and transients
 *   - Faithfully mimic WP_Error / WP_REST_Request / WP_REST_Response / WP_Query
 *   - Track add_action / add_filter callbacks so the test harness can fire
 *     'rest_api_init' and populate the route registry after plugin load
 *
 * Loaded by tests/test-runner.php BEFORE the plugin under test.
 */

if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
if (!defined('AI_PILOT_TEST_MODE')) define('AI_PILOT_TEST_MODE', true);
if (!defined('OBJECT')) define('OBJECT', 'OBJECT');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

// ─── GLOBAL DATA STORES ──────────────────────────────────────────────

$GLOBALS['wp_options']        = [];
$GLOBALS['aipilot_posts']     = [];        // id => object|array
$GLOBALS['aipilot_post_seq']  = 0;
$GLOBALS['aipilot_categories']= [];
$GLOBALS['aipilot_cat_seq']   = 0;
$GLOBALS['aipilot_tags']      = [];
$GLOBALS['aipilot_tag_seq']   = 0;
$GLOBALS['aipilot_menus']     = [];
$GLOBALS['aipilot_users']     = [];
$GLOBALS['aipilot_transients']= [];
$GLOBALS['aipilot_actions']   = [];        // hook => [callbacks]
$GLOBALS['aipilot_filters']   = [];        // hook => [callbacks]
$GLOBALS['aipilot_routes']    = [];        // ns => method => route => args
$GLOBALS['aipilot_themes']    = ['active' => 'twentynineteen'];
$GLOBALS['aipilot_active_plugins'] = [];
$GLOBALS['aipilot_settings_errors'] = [];
$GLOBALS['aipilot_enqueued_scripts'] = [];
$GLOBALS['aipilot_nav_menu_items'] = [];

// ─── HOOK SYSTEM ─────────────────────────────────────────────────────

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['aipilot_actions'][$hook][] = [
        'callback'      => $callback,
        'priority'      => $priority,
        'accepted_args' => $accepted_args,
    ];
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['aipilot_filters'][$hook][] = [
        'callback'      => $callback,
        'priority'      => $priority,
        'accepted_args' => $accepted_args,
    ];
}

function do_action($hook, ...$args) {
    if (empty($GLOBALS['aipilot_actions'][$hook])) return;
    foreach ($GLOBALS['aipilot_actions'][$hook] as $entry) {
        call_user_func_array($entry['callback'], $args);
    }
}

function apply_filters($hook, $value, ...$args) {
    if (empty($GLOBALS['aipilot_filters'][$hook])) return $value;
    foreach ($GLOBALS['aipilot_filters'][$hook] as $entry) {
        $value = call_user_func($entry['callback'], $value, ...$args);
    }
    return $value;
}

function has_action($hook, $callback = false) {
    if (empty($GLOBALS['aipilot_actions'][$hook])) return false;
    if ($callback === false) return count($GLOBALS['aipilot_actions'][$hook]);
    foreach ($GLOBALS['aipilot_actions'][$hook] as $e) {
        if ($e['callback'] === $callback) return true;
    }
    return false;
}

function remove_action($hook, $callback, $priority = 10) {
    if (empty($GLOBALS['aipilot_actions'][$hook])) return false;
    foreach ($GLOBALS['aipilot_actions'][$hook] as $i => $e) {
        if ($e['callback'] === $callback) {
            unset($GLOBALS['aipilot_actions'][$hook][$i]);
            return true;
        }
    }
    return false;
}

// ─── REST ROUTE REGISTRY ─────────────────────────────────────────────

/**
 * Capture every REST route registration.
 *
 * Stores under $GLOBALS['aipilot_routes'][$namespace][$method][$route] = $args.
 * Methods are normalised to uppercase. The route path is kept verbatim,
 * including regex params like '/agent/approve/(?P<id>[a-f0-9-]+)'.
 */
function register_rest_route($namespace, $route, $args) {
    $methods = isset($args['methods']) ? $args['methods'] : 'GET';
    $methods = array_map('strtoupper', (array) $methods);

    foreach ($methods as $m) {
        $GLOBALS['aipilot_routes'][$namespace][$m][$route] = $args;
    }
}

function rest_url($path = '') {
    return 'https://example.test/wp-json/' . ltrim($path, '/');
}

function rest_get_server() {
    return new class {
        public function get_routes() {
            $flat = [];
            foreach ($GLOBALS['aipilot_routes'] as $ns => $by_method) {
                foreach ($by_method as $method => $routes) {
                    foreach (array_keys($routes) as $route) {
                        $flat['/' . $ns . $route] = 1;
                    }
                }
            }
            return $flat;
        }
    };
}

// ─── SITE / BLOG INFO ────────────────────────────────────────────────

function get_bloginfo($key = '') {
    $map = [
        'name'        => 'Mock Blog',
        'description' => 'Just another mock site',
        'url'         => 'https://example.test',
        'wpurl'       => 'https://example.test',
        'version'     => '6.4',
        'language'    => 'en-US',
        'charset'     => 'UTF-8',
        'admin_email' => 'admin@example.test',
    ];
    return isset($map[$key]) ? $map[$key] : '';
}

function get_site_url($blog_id = null, $path = '', $scheme = null) {
    return 'https://example.test' . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

function site_url($path = '', $scheme = null) {
    return get_site_url(null, $path, $scheme);
}

function home_url($path = '') {
    return get_site_url(null, $path);
}

function admin_url($path = '') {
    return 'https://example.test/wp-admin/' . ltrim($path, '/');
}

function get_permalink($post_id = 0) {
    return 'https://example.test/?p=' . intval($post_id);
}
function get_post_status($post_id = 0) {
    $post = get_post($post_id);
    return $post ? $post->post_status : false;
}

function get_page_template_slug($post_id) {
    return '';
}

// ─── OPTIONS API (in-memory) ─────────────────────────────────────────

function get_option($key, $default = false) {
    if (array_key_exists($key, $GLOBALS['wp_options'])) {
        return $GLOBALS['wp_options'][$key];
    }
    return $default;
}

function update_option($key, $value, $autoload = null) {
    $GLOBALS['wp_options'][$key] = $value;
    return true;
}

function add_option($key, $value = '', $deprecated = '', $autoload = 'yes') {
    if (array_key_exists($key, $GLOBALS['wp_options'])) {
        return false;
    }
    $GLOBALS['wp_options'][$key] = $value;
    return true;
}

function delete_option($key) {
    unset($GLOBALS['wp_options'][$key]);
    return true;
}

// ─── TRANSIENTS (in-memory) ──────────────────────────────────────────

function set_transient($key, $value, $ttl = 0) {
    $GLOBALS['aipilot_transients'][$key] = [
        'value'     => $value,
        'expires'   => $ttl > 0 ? time() + $ttl : PHP_INT_MAX,
    ];
    return true;
}

function get_transient($key) {
    if (!isset($GLOBALS['aipilot_transients'][$key])) return false;
    $t = $GLOBALS['aipilot_transients'][$key];
    if ($t['expires'] < time()) {
        unset($GLOBALS['aipilot_transients'][$key]);
        return false;
    }
    return $t['value'];
}

function delete_transient($key) {
    unset($GLOBALS['aipilot_transients'][$key]);
    return true;
}

function get_site_transient($key) { return get_transient($key); }
function set_site_transient($key, $value, $ttl = 0) { return set_transient($key, $value, $ttl); }
function delete_site_transient($key) { return delete_transient($key); }

// ─── POSTS API (in-memory) ───────────────────────────────────────────

function wp_insert_post($postarr, $wp_error = false) {
    if (!isset($postarr['post_type']))   $postarr['post_type']   = 'post';
    if (!isset($postarr['post_status'])) $postarr['post_status'] = 'draft';
    if (!isset($postarr['post_title']))  $postarr['post_title']  = '';
    if (!isset($postarr['post_content']))$postarr['post_content']= '';
    if (!isset($postarr['post_name']) || $postarr['post_name'] === '') {
        $postarr['post_name'] = sanitize_title($postarr['post_title']);
    }
    if (!isset($postarr['post_date'])) {
        $postarr['post_date']     = current_time('mysql');
        $postarr['post_modified'] = $postarr['post_date'];
    }

    if (isset($postarr['ID']) && $postarr['ID']) {
        $id = intval($postarr['ID']);
        if (!isset($GLOBALS['aipilot_posts'][$id])) {
            return $wp_error
                ? new WP_Error('invalid_post_id', 'Post not found')
                : 0;
        }
        $post = $GLOBALS['aipilot_posts'][$id];
        foreach ($postarr as $k => $v) $post->$k = $v;
        $post->post_modified = current_time('mysql');
        $GLOBALS['aipilot_posts'][$id] = $post;
        return $id;
    }

    $GLOBALS['aipilot_post_seq']++;
    $id = $GLOBALS['aipilot_post_seq'];
    $postarr['ID'] = $id;
    $post = (object) $postarr;
    $GLOBALS['aipilot_posts'][$id] = $post;
    return $id;
}

function wp_update_post($postarr, $wp_error = false) {
    if (!isset($postarr['ID']) || !$postarr['ID']) {
        return $wp_error ? new WP_Error('empty_id', 'ID required') : 0;
    }
    return wp_insert_post($postarr, $wp_error);
}

function wp_delete_post($post_id, $force_delete = false) {
    if (isset($GLOBALS['aipilot_posts'][$post_id])) {
        unset($GLOBALS['aipilot_posts'][$post_id]);
        return (object) ['ID' => $post_id, 'post_status' => 'trash'];
    }
    return null;
}

function get_post($post = null, $output = OBJECT, $filter = 'raw') {
    if ($post === null) return null;
    if ($post instanceof WP_Post) return $post;
    if (is_object($post)) {
        $id = isset($post->ID) ? $post->ID : 0;
    } elseif (is_array($post)) {
        $id = isset($post['ID']) ? $post['ID'] : 0;
    } else {
        $id = intval($post);
    }
    if (!isset($GLOBALS['aipilot_posts'][$id])) return null;
    $p = $GLOBALS['aipilot_posts'][$id];
    return $output === ARRAY_A ? get_object_vars($p) : $p;
}

function get_posts($args = []) {
    if (!is_array($args)) $args = [];
    $defaults = [
        'post_type'      => 'any',
        'post_status'    => 'any',
        'posts_per_page' => 10,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_key'       => '',
        'fields'         => '',
        'paged'          => 1,
        'suppress_filters' => true,
    ];
    $args = wp_parse_args($args, $defaults);

    $results = [];
    foreach ($GLOBALS['aipilot_posts'] as $p) {
        $type_ok   = $args['post_type'] === 'any' || (is_array($args['post_type']) && in_array($p->post_type, $args['post_type'], true)) || $p->post_type === $args['post_type'];
        $status_ok = $args['post_status'] === 'any' || (is_array($args['post_status']) && in_array($p->post_status, $args['post_status'], true)) || $p->post_status === $args['post_status'];
        if (!$type_ok || !$status_ok) continue;

        // meta_key filter — for builder detection
        if (!empty($args['meta_key'])) {
            $metas = isset($GLOBALS['aipilot_post_meta'][$p->ID]) ? $GLOBALS['aipilot_post_meta'][$p->ID] : [];
            if (!array_key_exists($args['meta_key'], $metas)) continue;
        }

        $results[] = $p;
    }

    if ($args['posts_per_page'] !== -1) {
        $offset = ($args['paged'] - 1) * $args['posts_per_page'];
        $results = array_slice($results, $offset, $args['posts_per_page']);
    }

    if ($args['fields'] === 'ids') {
        return array_map(function($p) { return $p->ID; }, $results);
    }

    return $results;
}

function wp_get_post_categories($post_id = 0, $args = []) {
    $fields = isset($args['fields']) ? $args['fields'] : 'all';
    $cats = isset($GLOBALS['aipilot_post_cats'][$post_id]) ? $GLOBALS['aipilot_post_cats'][$post_id] : [];
    if ($fields === 'names') return array_map(function($id) { return isset($GLOBALS['aipilot_categories'][$id]) ? $GLOBALS['aipilot_categories'][$id]->name : ''; }, $cats);
    return $cats;
}

function wp_set_post_categories($post_id, $cat_ids = []) {
    $GLOBALS['aipilot_post_cats'][$post_id] = array_map('intval', (array) $cat_ids);
}

function wp_set_post_tags($post_id, $tags = [], $append = false) {
    $GLOBALS['aipilot_post_tags'][$post_id] = (array) $tags;
    return $GLOBALS['aipilot_post_tags'][$post_id];
}

// ─── CATEGORIES / TAGS / TAXONOMY ────────────────────────────────────

function get_categories($args = []) {
    return array_values($GLOBALS['aipilot_categories']);
}

function get_tags($args = []) {
    return array_values($GLOBALS['aipilot_tags']);
}

function wp_create_category($name, $parent = 0) {
    $GLOBALS['aipilot_cat_seq']++;
    $id = $GLOBALS['aipilot_cat_seq'];
    $GLOBALS['aipilot_categories'][$id] = (object) [
        'term_id' => $id,
        'name'    => $name,
        'slug'    => sanitize_title($name),
        'count'   => 0,
        'parent'  => $parent,
        'taxonomy'=> 'category',
    ];
    return $id;
}

function wp_insert_term($name, $taxonomy, $args = []) {
    if ($taxonomy === 'category') return wp_create_category($name, $args['parent'] ?? 0);
    $GLOBALS['aipilot_tag_seq']++;
    $id = $GLOBALS['aipilot_tag_seq'];
    $GLOBALS['aipilot_tags'][$id] = (object) [
        'term_id' => $id,
        'name'    => $name,
        'slug'    => sanitize_title($name),
        'count'   => 0,
        'taxonomy'=> $taxonomy,
    ];
    return ['term_id' => $id];
}

function wp_update_term($term_id, $taxonomy, $args = []) {
    if ($taxonomy === 'post_tag' && isset($GLOBALS['aipilot_tags'][$term_id])) {
        if (isset($args['name'])) $GLOBALS['aipilot_tags'][$term_id]->name = $args['name'];
        return ['term_id' => $term_id];
    }
    if ($taxonomy === 'category' && isset($GLOBALS['aipilot_categories'][$term_id])) {
        if (isset($args['name'])) $GLOBALS['aipilot_categories'][$term_id]->name = $args['name'];
        return ['term_id' => $term_id];
    }
    return new WP_Error('invalid_term', 'Term not found');
}

function wp_delete_term($term_id, $taxonomy) {
    if ($taxonomy === 'post_tag') unset($GLOBALS['aipilot_tags'][$term_id]);
    elseif ($taxonomy === 'category') unset($GLOBALS['aipilot_categories'][$term_id]);
    return true;
}

// ─── MENUS / USERS / PLUGINS / THEMES ────────────────────────────────

function wp_get_nav_menus() {
    return array_values($GLOBALS['aipilot_menus']);
}

function wp_update_nav_menu_item($menu_id, $menu_item_db_id, $menu_item_data = []) {
    $GLOBALS['aipilot_nav_menu_items'][] = [
        'menu_id'   => $menu_id,
        'item_id'   => $menu_item_db_id,
        'data'      => $menu_item_data,
    ];
    return count($GLOBALS['aipilot_nav_menu_items']);
}

function get_users($args = []) {
    $fields = isset($args['fields']) ? $args['fields'] : 'all';
    $users = array_values($GLOBALS['aipilot_users']);
    if (is_array($fields)) {
        $users = array_map(function($u) use ($fields) {
            $out = new stdClass();
            foreach ($fields as $f) $out->$f = isset($u->$f) ? $u->$f : null;
            return $out;
        }, $users);
    }
    return $users;
}

function get_plugins() {
    return [
        'ai-pilot/ai-pilot-plugin.php' => ['Name' => 'AI Pilot', 'Version' => '2.2.0'],
    ];
}

function is_plugin_active($plugin) {
    return in_array($plugin, $GLOBALS['aipilot_active_plugins'], true);
}

function activate_plugin($plugin) {
    if (empty($plugin)) return new WP_Error('no_plugin', 'Plugin slug required');
    $GLOBALS['aipilot_active_plugins'][] = $plugin;
    return null;
}

function deactivate_plugins($plugins) {
    foreach ((array) $plugins as $p) {
        $k = array_search($p, $GLOBALS['aipilot_active_plugins'], true);
        if ($k !== false) unset($GLOBALS['aipilot_active_plugins'][$k]);
    }
}

function switch_theme($stylesheet) {
    $GLOBALS['aipilot_themes']['active'] = $stylesheet;
    return true;
}

function wp_get_theme() {
    $active = $GLOBALS['aipilot_themes']['active'];
    return new class($active) {
        private $name;
        public function __construct($name) { $this->name = $name; }
        public function get($key) {
            $map = [
                'Name'       => $this->name,
                'Version'    => '1.0.0',
                'Author'     => 'Mock Author',
                'Template'   => $this->name,
                'Stylesheet' => $this->name,
            ];
            return isset($map[$key]) ? $map[$key] : '';
        }
        public function get_template()    { return $this->name; }
        public function get_stylesheet()  { return $this->name; }
        public function get_screenshot()  { return 'https://example.test/screenshot.png'; }
    };
}

function get_theme_mods() {
    return isset($GLOBALS['wp_options']['theme_mods']) ? $GLOBALS['wp_options']['theme_mods'] : [];
}

function set_theme_mod($key, $value) {
    if (!isset($GLOBALS['wp_options']['theme_mods'])) $GLOBALS['wp_options']['theme_mods'] = [];
    $GLOBALS['wp_options']['theme_mods'][$key] = $value;
}

function wp_is_block_theme() { return false; }

// ─── TIME ────────────────────────────────────────────────────────────

function current_time($type) {
    if ($type === 'mysql')      return date('Y-m-d H:i:s');
    if ($type === 'timestamp')  return time();
    return date('Y-m-d H:i:s');
}

// ─── CRYPTO / NONCE / PASSWORD ───────────────────────────────────────

function wp_hash($value) {
    return md5($value . '::aipilot-mock-salt');
}

function wp_salt($scheme = 'auth') {
    return 'aipilot-mock-salt';
}

function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
    $chars  = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    if ($special_chars) $chars .= '!@#$%^&*()-_ []{}<>~`+=,.;:/?|';
    $out = '';
    for ($i = 0; $i < $length; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}

function wp_generate_uuid4() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );
}

function wp_create_nonce($action = -1) { return 'mocknonce'; }
function wp_verify_nonce($nonce, $action = -1) { return $nonce === 'mocknonce'; }
function check_admin_referer($action = -1, $query_arg = '_wpnonce') { return true; }
function check_ajax_referer($action = -1, $query_arg = false, $die = true) { return true; }
function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true) {
    $html = '<input type="hidden" name="' . $name . '" value="mocknonce">';
    if ($echo) echo $html;
    return $html;
}

// ─── SANITIZATION / ESCAPING (passthrough) ───────────────────────────

function sanitize_text_field($v) { return is_string($v) ? trim($v) : $v; }
function sanitize_textarea_field($v) { return is_string($v) ? trim($v) : $v; }
function sanitize_title($title) { return strtolower(preg_replace('/[^a-z0-9_-]+/i', '-', $title)); }
function sanitize_email($email) { return filter_var($email, FILTER_SANITIZE_EMAIL); }
function sanitize_key($key) { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', $key)); }
function wp_unslash($v) {
    if (is_array($v)) return array_map('wp_unslash', $v);
    return is_string($v) ? stripslashes($v) : $v;
}
function wp_kses_post($v) { return $v; }
function wp_strip_all_tags($v, $remove_breaks = false) { return trim(strip_tags((string) $v)); }
function wp_trim_words($text, $num_words = 55, $more = null) {
    $words = explode(' ', (string) $text);
    return implode(' ', array_slice($words, 0, $num_words));
}

function esc_html($v) { return $v; }
function esc_attr($v) { return $v; }
function esc_textarea($v) { return $v; }
function esc_url($v) { return $v; }
function esc_url_raw($v) { return $v; }
function esc_sql($v) { return $v; }

// ─── INTERNATIONALIZATION (passthrough) ──────────────────────────────

function __($text, $domain = 'default') { return $text; }
function _e($text, $domain = 'default') { echo $text; }
function _x($text, $context, $domain = 'default') { return $text; }
function esc_html__($text, $domain = 'default') { return esc_html(__($text, $domain)); }
function esc_html_e($text, $domain = 'default') { echo esc_html__($text, $domain); }

// ─── MISC WP HELPERS ─────────────────────────────────────────────────

function wp_json_encode($data, $options = 0, $depth = 512) {
    return json_encode($data, $options, $depth);
}

function wp_parse_args($args, $defaults = []) {
    if (is_object($args)) $args = get_object_vars($args);
    if (!is_array($args)) $args = [];
    return array_merge($defaults, $args);
}

function add_query_arg(...$args) {
    if (count($args) === 2) {
        return $args[1] . (strpos($args[1], '?') === false ? '?' : '&') . http_build_query($args[0]);
    }
    if (count($args) === 3) {
        return $args[2] . (strpos($args[2], '?') === false ? '?' : '&') . http_build_query([$args[0] => $args[1]]);
    }
    return '';
}

function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file)  { return 'https://example.test/wp-content/plugins/' . basename(dirname($file)) . '/'; }
function plugin_basename($file) {
    $dir = dirname(dirname($file));
    return basename(dirname($file)) . '/' . basename($file);
}

function checked($checked, $current = true, $echo = true) {
    $result = ((string) $checked === (string) $current) ? ' checked="checked"' : '';
    if ($echo) echo $result;
    return $result;
}

function selected($selected, $current = true, $echo = true) {
    $result = ((string) $selected === (string) $current) ? ' selected="selected"' : '';
    if ($echo) echo $result;
    return $result;
}

function current_user_can($capability) {
    if (isset($GLOBALS['aipilot_current_user_can_override'])) {
        $override = $GLOBALS['aipilot_current_user_can_override'];
        if (is_bool($override)) return $override;
        if (is_callable($override)) return call_user_func($override, $capability);
    }
    return in_array($capability, ['manage_options', 'edit_posts'], true);
}

function is_user_logged_in() { return true; }

function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $in_footer = false) {
    $GLOBALS['aipilot_enqueued_scripts'][$handle] = compact('handle', 'src', 'deps', 'ver', 'in_footer');
    return true;
}

function wp_localize_script($handle, $object_name, $l10n) {
    $GLOBALS['aipilot_enqueued_scripts'][$handle]['localize'][$object_name] = $l10n;
    return true;
}

function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false, $media = 'all') {
    return true;
}

function wp_update_plugins() { return null; }

function wp_get_attachment_url($id) { return 'https://example.test/wp-content/uploads/mock-' . intval($id) . '.png'; }

function wp_remote_get($url, $args = []) { return ['body' => '', 'response' => ['code' => 200]]; }
function wp_remote_post($url, $args = []) { return ['body' => '', 'response' => ['code' => 200]]; }
function wp_remote_retrieve_body($resp) { return isset($resp['body']) ? $resp['body'] : ''; }
function is_wp_error($thing) { return ($thing instanceof WP_Error); }

function _deprecated_function($func, $version, $replacement = null) {}

// ─── ADMIN UI STUBS ──────────────────────────────────────────────────

function add_options_page($page_title, $menu_title, $capability, $menu_slug, $callback = '') { return 'settings_page_' . $menu_slug; }
function add_menu_page(...$a) { return 'toplevel_page_mock'; }
function add_submenu_page(...$a) { return 'submenu_mock'; }
function register_setting($option_group, $option_name, $args = []) {}
function add_settings_section($id, $title, $callback, $page) {}
function add_settings_field($id, $title, $callback, $page, $section = 'default', $args = []) {}
function settings_fields($option_group) {}
function do_settings_sections($page) {}
function submit_button($text = '', $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = '') {}

function add_settings_error($setting, $code, $message, $type = 'error') {
    $GLOBALS['aipilot_settings_errors'][] = compact('setting', 'code', 'message', 'type');
}
function settings_errors($setting = '') {
    if ($setting === '') return $GLOBALS['aipilot_settings_errors'];
    return array_values(array_filter($GLOBALS['aipilot_settings_errors'], function($e) use ($setting) { return $e['setting'] === $setting; }));
}
function get_settings_errors($setting = '', $sanitize = false) { return settings_errors($setting); }

// ─── PHP POLYFILLS ───────────────────────────────────────────────────

if (!function_exists('hash_equals')) {
    function hash_equals($a, $b) { return $a === $b; }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('array_key_first')) {
    function array_key_first(array $arr) {
        foreach ($arr as $k => $_) return $k;
        return null;
    }
}

// ─── CORE WP CLASSES ─────────────────────────────────────────────────

class WP_Post {
    public $ID;
    public $post_title;
    public $post_content;
    public $post_status;
    public $post_type;
    public $post_name;
    public $post_date;
    public $post_modified;
    public $post_excerpt;
    public $post_parent;
    public $post_author;
    public $menu_order;
    public function __construct(array $data = []) {
        foreach ($data as $k => $v) $this->$k = $v;
    }
}

class WP_Error {
    public $errors = [];
    public $error_data = [];
    public function __construct($code = '', $message = '', $data = '') {
        if ($code !== '') {
            $this->errors[$code][] = $message;
            if ($data !== '') $this->error_data[$code][] = $data;
        }
    }
    public function get_error_code() {
        return empty($this->errors) ? '' : array_key_first($this->errors);
    }
    public function get_error_codes() { return array_keys($this->errors); }
    public function get_error_message($code = '') {
        if ($code === '') $code = $this->get_error_code();
        return isset($this->errors[$code][0]) ? $this->errors[$code][0] : '';
    }
    public function get_error_messages($code = '') {
        if ($code === '') $code = $this->get_error_code();
        return isset($this->errors[$code]) ? $this->errors[$code] : [];
    }
    public function get_error_data($code = '') {
        if ($code === '') $code = $this->get_error_code();
        return isset($this->error_data[$code]) ? $this->error_data[$code][0] : '';
    }
    public function add($code, $message, $data = '') {
        $this->errors[$code][] = $message;
        if ($data !== '') $this->error_data[$code][] = $data;
    }
    public function add_data($data, $code = '') {
        if ($code === '') $code = $this->get_error_code();
        $this->error_data[$code][] = $data;
    }
}

class WP_REST_Response {
    public $data;
    public $status;
    public $headers = [];
    public function __construct($data = null, $status = 200, $headers = []) {
        $this->data    = $data;
        $this->status  = $status;
        $this->headers = $headers;
    }
}

class WP_REST_Request {
    public $method   = 'GET';
    public $params   = [];
    public $headers  = [];
    public $body     = '';
    public $url      = '';
    public $route    = '';
    public function __construct($method = 'GET', $route = '', $attrs = []) {
        $this->method = strtoupper($method);
        $this->route  = $route;
    }
    public function get_method() { return $this->method; }
    public function get_route() { return $this->route; }
    public function get_param($key, $default = null) {
        return array_key_exists($key, $this->params) ? $this->params[$key] : $default;
    }
    public function get_params() { return $this->params; }
    public function set_param($key, $value) { $this->params[$key] = $value; }
    public function has_param($key) { return array_key_exists($key, $this->params); }
    public function get_header($key) {
        $key = strtolower($key);
        return isset($this->headers[$key]) ? $this->headers[$key] : '';
    }
    public function get_headers() { return $this->headers; }
    public function set_header($key, $value) { $this->headers[strtolower($key)] = $value; }
    public function get_body() { return $this->body; }
    public function set_body($body) { $this->body = $body; }
    public function get_json_params() { return json_decode($this->body, true) ?: []; }
}

class WP_Query {
    public $posts      = [];
    public $found_posts = 0;
    public $max_num_pages = 1;
    public $post_count = 0;
    public $query_vars = [];
    public function __construct($args = '') {
        $this->query_vars = wp_parse_args($args);
        $this->posts      = get_posts($args);
        $this->post_count = count($this->posts);
        $per_page         = isset($this->query_vars['posts_per_page']) ? $this->query_vars['posts_per_page'] : 10;
        if ($per_page === -1) $per_page = max(1, $this->post_count);
        $this->found_posts  = count($GLOBALS['aipilot_posts']);
        $this->max_num_pages = $per_page > 0 ? (int) ceil($this->found_posts / $per_page) : 1;
    }
    public function have_posts() { return !empty($this->posts); }
    public function the_post() { next($this->posts); }
}

// ─── BACKWARD COMPAT: deprecated classes referenced in module-auth-helper ─
if (!class_exists('WP_Upgrader')) {
    class WP_Upgrader {}
}
if (!class_exists('Automatic_Upgrader_Skin')) {
    class Automatic_Upgrader_Skin {}
}
if (!class_exists('Plugin_Upgrader')) {
    class Plugin_Upgrader {
        public function upgrade($plugin) { return new WP_Error('mock', 'No-op in tests'); }
    }
}
if (!class_exists('WP_Block_Type_Registry')) {
    class WP_Block_Type_Registry {
        private static $instance;
        public static function get_instance() {
            if (!self::$instance) self::$instance = new self();
            return self::$instance;
        }
        public function get_all_registered() { return []; }
    }
}

// NOTE: error_log() is a PHP built-in — we deliberately do NOT override it.
// Production code in modules/module-auth-helper.php and elsewhere calls it
// for auth-failure logging; in the test environment those messages simply go
// to PHP's default error log target (stderr or php_errors.log).
