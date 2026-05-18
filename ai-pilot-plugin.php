<?php
/**
 * Plugin Name: AI Pilot – Remote Site API
 * Description: REST API для удалённого управления WordPress-сайтами через AI Pilot
 * Version: 1.0.1
 * Author: AI Pilot
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Tested up to: 6.9
 * Text Domain: ai-pilot
 *
 * AI Pilot – Remote Site API
 * @see https://aipilot.com
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AI_PILOT_VERSION', '1.0.1');
define('AI_PILOT_PLUGIN_FILE', __FILE__);
define('AI_PILOT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AI_PILOT_PLUGIN_URL', plugin_dir_url(__FILE__));

// ─── РЕГИСТРАЦИЯ РОУТОВ ─────────────────────────────────────────────

/**
 * Регистрирует роут в нескольких неймспейсах для обратной совместимости
 */
function aipilot_register_route($route, $args) {
    register_rest_route('aipilot/v1', $route, $args);
    // Обратная совместимость со старыми клиентами
    register_rest_route('openclaw/v1', $route, $args);
}

// ─── БАЗОВЫЙ ПРОВЕРКА ПОДКЛЮЧЕНИЯ ──────────────────────────────────

add_action('rest_api_init', function() {
    aipilot_register_route('/ping', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_ping',
        'permission_callback' => '__return_true',
    ]);

    aipilot_register_route('/site', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_get_site',
        'permission_callback' => function() { return aipilot_verify_token_and_can('site_info'); },
    ]);
});

// ─── ПОСТЫ ──────────────────────────────────────────────────────────

add_action('rest_api_init', function() {
    aipilot_register_route('/posts', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_get_posts',
        'permission_callback' => function() { return aipilot_verify_token_and_can('posts_read'); },
    ]);

    aipilot_register_route('/posts', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_create_post',
        'permission_callback' => function() { return aipilot_verify_token_and_can('posts_create'); },
    ]);

    aipilot_register_route('/posts/(?P<id>\d+)', [
        'methods'             => 'PUT',
        'callback'            => 'aipilot_update_post',
        'permission_callback' => function() { return aipilot_verify_token_and_can('posts_update'); },
    ]);

    aipilot_register_route('/posts/(?P<id>\d+)', [
        'methods'             => 'DELETE',
        'callback'            => 'aipilot_delete_post',
        'permission_callback' => function() { return aipilot_verify_token_and_can('posts_delete'); },
    ]);

    aipilot_register_route('/categories', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_get_categories',
        'permission_callback' => function() { return aipilot_verify_token_and_can('categories_read'); },
    ]);

    aipilot_register_route('/categories', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_create_category',
        'permission_callback' => function() { return aipilot_verify_token_and_can('categories_create'); },
    ]);

    aipilot_register_route('/tags', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_get_tags',
        'permission_callback' => function() { return aipilot_verify_token_and_can('tags_read'); },
    ]);

    aipilot_register_route('/tags', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_create_tag',
        'permission_callback' => function() { return aipilot_verify_token_and_can('tags_create'); },
    ]);

    aipilot_register_route('/tags/(?P<id>\d+)', [
        'methods'             => 'PUT',
        'callback'            => 'aipilot_update_tag',
        'permission_callback' => function() { return aipilot_verify_token_and_can('tags_update'); },
    ]);

    aipilot_register_route('/tags/(?P<id>\d+)', [
        'methods'             => 'DELETE',
        'callback'            => 'aipilot_delete_tag',
        'permission_callback' => function() { return aipilot_verify_token_and_can('tags_delete'); },
    ]);
});

// ─── СТРАНИЦЫ ───────────────────────────────────────────────────────

add_action('rest_api_init', function() {
    aipilot_register_route('/pages', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_get_pages',
        'permission_callback' => function() { return aipilot_verify_token_and_can('pages_read'); },
    ]);

    aipilot_register_route('/pages', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_create_page',
        'permission_callback' => function() { return aipilot_verify_token_and_can('pages_create'); },
    ]);

    aipilot_register_route('/pages/(?P<id>\d+)', [
        'methods'             => 'PUT',
        'callback'            => 'aipilot_update_page',
        'permission_callback' => function() { return aipilot_verify_token_and_can('pages_update'); },
    ]);

    aipilot_register_route('/pages/(?P<id>\d+)', [
        'methods'             => 'DELETE',
        'callback'            => 'aipilot_delete_page',
        'permission_callback' => function() { return aipilot_verify_token_and_can('pages_delete'); },
    ]);
});

// ─── ПОЛЬЗОВАТЕЛИ ───────────────────────────────────────────────────

add_action('rest_api_init', function() {
    aipilot_register_route('/users', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_get_users',
        'permission_callback' => function() { return aipilot_verify_token_and_can('users_read'); },
    ]);
});

// ─── ПЛАГИНЫ ────────────────────────────────────────────────────────

add_action('rest_api_init', function() {
    aipilot_register_route('/plugins', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_get_plugins',
        'permission_callback' => function() { return aipilot_verify_token_and_can('plugins_read'); },
    ]);

    aipilot_register_route('/plugins/install', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_install_plugin',
        'permission_callback' => function() { return aipilot_verify_token_and_can('plugins_install'); },
    ]);

    aipilot_register_route('/plugins/upload', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_upload_plugin',
        'permission_callback' => function() { return aipilot_verify_token_and_can('plugins_upload'); },
    ]);

    aipilot_register_route('/plugins/(?P<slug>[^/]+)/activate', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_activate_plugin',
        'permission_callback' => function() { return aipilot_verify_token_and_can('plugins_activate'); },
    ]);

    aipilot_register_route('/plugins/(?P<slug>[^/]+)/deactivate', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_deactivate_plugin',
        'permission_callback' => function() { return aipilot_verify_token_and_can('plugins_deactivate'); },
    ]);

    aipilot_register_route('/plugins/(?P<slug>[^/]+)/update', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_update_plugin',
        'permission_callback' => function() { return aipilot_verify_token_and_can('plugins_update'); },
    ]);

    aipilot_register_route('/plugins/(?P<slug>[^/]+)', [
        'methods'             => 'DELETE',
        'callback'            => 'aipilot_delete_plugin',
        'permission_callback' => function() { return aipilot_verify_token_and_can('plugins_delete'); },
    ]);

    aipilot_register_route('/plugins/search', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_search_plugins',
        'permission_callback' => function() { return aipilot_verify_token_and_can('plugins_search'); },
    ]);
});

// ─── МЕНЮ ───────────────────────────────────────────────────────────

add_action('rest_api_init', function() {
    aipilot_register_route('/menus', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_get_menus',
        'permission_callback' => function() { return aipilot_verify_token_and_can('menus_read'); },
    ]);

    aipilot_register_route('/menus/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_get_menu_items',
        'permission_callback' => function() { return aipilot_verify_token_and_can('menus_read'); },
    ]);

    aipilot_register_route('/menus', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_create_menu',
        'permission_callback' => function() { return aipilot_verify_token_and_can('menus_create'); },
    ]);

    aipilot_register_route('/menus/(?P<id>\d+)', [
        'methods'             => 'PUT',
        'callback'            => 'aipilot_update_menu',
        'permission_callback' => function() { return aipilot_verify_token_and_can('menus_update'); },
    ]);

    aipilot_register_route('/menus/(?P<id>\d+)', [
        'methods'             => 'DELETE',
        'callback'            => 'aipilot_delete_menu',
        'permission_callback' => function() { return aipilot_verify_token_and_can('menus_delete'); },
    ]);

    aipilot_register_route('/menus/(?P<id>\d+)/items', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_add_menu_item',
        'permission_callback' => function() { return aipilot_verify_token_and_can('menus_update'); },
    ]);

    aipilot_register_route('/menus/(?P<id>\d+)/items/(?P<item_id>\d+)', [
        'methods'             => 'DELETE',
        'callback'            => 'aipilot_delete_menu_item',
        'permission_callback' => function() { return aipilot_verify_token_and_can('menus_update'); },
    ]);

    aipilot_register_route('/menus/(?P<id>\d+)/items', [
        'methods'             => 'PUT',
        'callback'            => 'aipilot_reorder_menu_items',
        'permission_callback' => function() { return aipilot_verify_token_and_can('menus_update'); },
    ]);
});

// ─── ТЕМЫ ───────────────────────────────────────────────────────────

add_action('rest_api_init', function() {
    aipilot_register_route('/theme', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_get_active_theme',
        'permission_callback' => function() { return aipilot_verify_token_and_can('themes_read'); },
    ]);

    aipilot_register_route('/themes', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_get_themes',
        'permission_callback' => function() { return aipilot_verify_token_and_can('themes_read'); },
    ]);

    aipilot_register_route('/theme', [
        'methods'             => 'PUT',
        'callback'            => 'aipilot_switch_theme',
        'permission_callback' => function() { return aipilot_verify_token_and_can('themes_switch'); },
    ]);

    aipilot_register_route('/theme/customize', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_get_theme_mods',
        'permission_callback' => function() { return aipilot_verify_token_and_can('themes_read'); },
    ]);

    aipilot_register_route('/theme/customize', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_set_theme_mods',
        'permission_callback' => function() { return aipilot_verify_token_and_can('themes_edit'); },
    ]);
});

// ─── ОПЦИИ / НАСТРОЙКИ САЙТА ───────────────────────────────────────

add_action('rest_api_init', function() {
    aipilot_register_route('/options', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_get_options',
        'permission_callback' => function() { return aipilot_verify_token_and_can('options_read'); },
    ]);

    aipilot_register_route('/options', [
        'methods'             => 'PUT',
        'callback'            => 'aipilot_update_options',
        'permission_callback' => function() { return aipilot_verify_token_and_can('options_write'); },
    ]);

    aipilot_register_route('/self-update', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_self_update',
        'permission_callback' => function() { return aipilot_verify_token_and_can('plugins_update'); },
    ]);
});

// ═══════════════════════════════════════════════════════════════════
//  ХЕНДЛЕРЫ API
// ═══════════════════════════════════════════════════════════════════

/**
 * Ping endpoint — проверка соединения.
 *
 * @return array
 */
function aipilot_ping() {
    return [
        'status'  => 'ok',
        'plugin'  => 'AI Pilot',
        'version' => AI_PILOT_VERSION,
    ];
}

/**
 * Получить информацию о сайте.
 *
 * @return array
 */
function aipilot_get_site() {
    return [
        'name'        => get_bloginfo('name'),
        'description' => get_bloginfo('description'),
        'url'         => get_bloginfo('url'),
        'wp_version'  => get_bloginfo('version'),
        'language'    => get_bloginfo('language'),
        'admin_email' => get_bloginfo('admin_email'),
        'timezone'    => get_option('timezone_string') ?: 'UTC',
    ];
}

// ─── ПОСТЫ ──────────────────────────────────────────────────────────

/**
 * Получить список постов.
 *
 * @param WP_REST_Request $request
 * @return array
 */
function aipilot_get_posts($request) {
    $args = [
        'post_type'      => 'post',
        'post_status'    => $request->get_param('status') ?: 'publish',
        'posts_per_page' => $request->get_param('per_page') ? min((int)$request->get_param('per_page'), 100) : 20,
        'paged'          => $request->get_param('page') ?: 1,
    ];

    if ($request->get_param('search')) {
        $args['s'] = sanitize_text_field($request->get_param('search'));
    }
    if ($request->get_param('category')) {
        $args['cat'] = (int)$request->get_param('category');
    }

    $query = new WP_Query($args);
    $posts = array_map('aipilot_format_post', $query->posts);

    return [
        'posts'        => $posts,
        'total'        => $query->found_posts,
        'total_pages'  => $query->max_num_pages,
        'current_page' => $args['paged'],
    ];
}

/**
 * Создать пост.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_create_post($request) {
    $post_data = [
        'post_title'   => sanitize_text_field($request->get_param('title')),
        'post_content' => wp_kses_post($request->get_param('content') ?: ''),
        'post_status'  => $request->get_param('status') ?: 'draft',
        'post_type'    => $request->get_param('post_type') ?: 'post',
        'post_excerpt' => sanitize_text_field($request->get_param('excerpt') ?: ''),
    ];

    if ($request->get_param('categories')) {
        $post_data['post_category'] = array_map('intval', (array)$request->get_param('categories'));
    }
    if ($request->get_param('tags_input')) {
        $post_data['tags_input'] = array_map('sanitize_text_field', (array)$request->get_param('tags_input'));
    }
    if ($request->get_param('featured_media')) {
        $post_data['meta_input'] = ['_thumbnail_id' => (int)$request->get_param('featured_media')];
    }

    $post_id = wp_insert_post($post_data, true);

    if (is_wp_error($post_id)) {
        return new WP_Error('post_create_failed', $post_id->get_error_message(), ['status' => 500]);
    }

    return aipilot_format_post(get_post($post_id));
}

/**
 * Обновить пост.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_update_post($request) {
    $id = (int)$request->get_param('id');
    $post = get_post($id);

    if (!$post || $post->post_type !== 'post') {
        return new WP_Error('not_found', 'Post not found', ['status' => 404]);
    }

    $post_data = ['ID' => $id];

    if ($request->get_param('title') !== null) {
        $post_data['post_title'] = sanitize_text_field($request->get_param('title'));
    }
    if ($request->get_param('content') !== null) {
        $post_data['post_content'] = wp_kses_post($request->get_param('content'));
    }
    if ($request->get_param('status') !== null) {
        $post_data['post_status'] = $request->get_param('status');
    }
    if ($request->get_param('excerpt') !== null) {
        $post_data['post_excerpt'] = sanitize_text_field($request->get_param('excerpt'));
    }
    if ($request->get_param('slug') !== null) {
        $post_data['post_name'] = sanitize_title($request->get_param('slug'));
    }

    $result = wp_update_post($post_data, true);

    if (is_wp_error($result)) {
        return new WP_Error('post_update_failed', $result->get_error_message(), ['status' => 500]);
    }

    if ($request->get_param('categories') !== null) {
        wp_set_post_categories($id, array_map('intval', (array)$request->get_param('categories')));
    }
    if ($request->get_param('tags_input') !== null) {
        wp_set_post_tags($id, array_map('sanitize_text_field', (array)$request->get_param('tags_input')));
    }

    return aipilot_format_post(get_post($id));
}

/**
 * Удалить пост.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_delete_post($request) {
    $id = (int)$request->get_param('id');
    $force = $request->get_param('force') ? true : false;

    $deleted = wp_delete_post($id, $force);

    if (!$deleted) {
        return new WP_Error('delete_failed', 'Failed to delete post', ['status' => 500]);
    }

    return ['deleted' => true, 'id' => $id];
}

/**
 * Форматировать пост для ответа API.
 *
 * @param WP_Post|null $post
 * @return array|null
 */
function aipilot_format_post($post) {
    if (!$post) return null;
    return [
        'id'          => $post->ID,
        'title'       => $post->post_title,
        'slug'        => $post->post_name,
        'content'     => $post->post_content,
        'excerpt'     => $post->post_excerpt,
        'status'      => $post->post_status,
        'date'        => $post->post_date,
        'modified'    => $post->post_modified,
        'author'      => (int)$post->post_author,
        'categories'  => wp_get_post_categories($post->ID, ['fields' => 'ids']),
        'tags'        => wp_get_post_tags($post->ID, ['fields' => 'ids']),
        'featured_image' => get_the_post_thumbnail_url($post->ID, 'full'),
        'permalink'   => get_permalink($post->ID),
        'type'        => $post->post_type,
    ];
}

// ─── КАТЕГОРИИ ──────────────────────────────────────────────────────

/**
 * Получить список категорий.
 *
 * @return array
 */
function aipilot_get_categories() {
    $categories = get_categories(['hide_empty' => false]);
    return array_map(function($c) {
        return [
            'id'       => (int)$c->term_id,
            'name'     => $c->name,
            'slug'     => $c->slug,
            'count'    => (int)$c->count,
            'parent'   => (int)$c->parent,
            'description' => $c->description,
        ];
    }, $categories);
}

/**
 * Создать категорию.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_create_category($request) {
    $result = wp_insert_term(
        sanitize_text_field($request->get_param('name')),
        'category',
        [
            'slug'        => sanitize_title($request->get_param('slug') ?: ''),
            'description' => sanitize_text_field($request->get_param('description') ?: ''),
            'parent'      => (int)($request->get_param('parent') ?: 0),
        ]
    );

    if (is_wp_error($result)) {
        return new WP_Error('category_create_failed', $result->get_error_message(), ['status' => 500]);
    }

    return ['id' => $result['term_id'], 'name' => $request->get_param('name')];
}

// ─── ТЕГИ ───────────────────────────────────────────────────────────

/**
 * Получить список тегов.
 *
 * @return array
 */
function aipilot_get_tags() {
    $tags = get_tags(['hide_empty' => false]);
    return array_map(function($t) {
        return [
            'id'    => (int)$t->term_id,
            'name'  => $t->name,
            'slug'  => $t->slug,
            'count' => (int)$t->count,
        ];
    }, $tags);
}

/**
 * Создать тег.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_create_tag($request) {
    $result = wp_insert_term(
        sanitize_text_field($request->get_param('name')),
        'post_tag',
        [
            'slug'        => sanitize_title($request->get_param('slug') ?: ''),
            'description' => sanitize_text_field($request->get_param('description') ?: ''),
        ]
    );

    if (is_wp_error($result)) {
        return new WP_Error('tag_create_failed', $result->get_error_message(), ['status' => 500]);
    }

    return ['id' => $result['term_id'], 'name' => $request->get_param('name')];
}

/**
 * Обновить тег.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_update_tag($request) {
    $result = wp_update_term(
        (int)$request->get_param('id'),
        'post_tag',
        [
            'name' => sanitize_text_field($request->get_param('name')),
            'slug' => sanitize_title($request->get_param('slug') ?: ''),
        ]
    );

    if (is_wp_error($result)) {
        return new WP_Error('tag_update_failed', $result->get_error_message(), ['status' => 500]);
    }

    return ['id' => $result['term_id'], 'name' => $request->get_param('name')];
}

/**
 * Удалить тег.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_delete_tag($request) {
    $result = wp_delete_term((int)$request->get_param('id'), 'post_tag');

    if (!$result) {
        return new WP_Error('delete_failed', 'Failed to delete tag', ['status' => 500]);
    }

    return ['deleted' => true, 'id' => (int)$request->get_param('id')];
}

// ─── СТРАНИЦЫ ───────────────────────────────────────────────────────

/**
 * Получить список страниц.
 *
 * @return array
 */
function aipilot_get_pages() {
    $pages = get_posts([
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    ]);

    return array_map(function($p) {
        return [
            'id'         => $p->ID,
            'title'      => $p->post_title,
            'slug'       => $p->post_name,
            'status'     => $p->post_status,
            'parent'     => (int)$p->post_parent,
            'menu_order' => (int)$p->menu_order,
            'template'   => get_page_template_slug($p->ID),
            'permalink'  => get_permalink($p->ID),
        ];
    }, $pages);
}

/**
 * Создать страницу.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_create_page($request) {
    $post_id = wp_insert_post([
        'post_title'   => sanitize_text_field($request->get_param('title')),
        'post_content' => wp_kses_post($request->get_param('content') ?: ''),
        'post_status'  => $request->get_param('status') ?: 'draft',
        'post_type'    => 'page',
        'post_parent'  => (int)($request->get_param('parent') ?: 0),
        'menu_order'   => (int)($request->get_param('menu_order') ?: 0),
    ], true);

    if (is_wp_error($post_id)) {
        return new WP_Error('page_create_failed', $post_id->get_error_message(), ['status' => 500]);
    }

    return ['id' => $post_id, 'title' => $request->get_param('title')];
}

/**
 * Обновить страницу.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_update_page($request) {
    $id = (int)$request->get_param('id');
    $post = get_post($id);

    if (!$post || $post->post_type !== 'page') {
        return new WP_Error('not_found', 'Page not found', ['status' => 404]);
    }

    $post_data = ['ID' => $id];
    foreach (['title' => 'post_title', 'content' => 'post_content', 'status' => 'post_status', 'slug' => 'post_name'] as $param => $field) {
        if ($request->get_param($param) !== null) {
            $post_data[$field] = $param === 'content' ? wp_kses_post($request->get_param($param)) : sanitize_text_field($request->get_param($param));
        }
    }
    if ($request->get_param('parent') !== null) {
        $post_data['post_parent'] = (int)$request->get_param('parent');
    }
    if ($request->get_param('menu_order') !== null) {
        $post_data['menu_order'] = (int)$request->get_param('menu_order');
    }

    $result = wp_update_post($post_data, true);

    if (is_wp_error($result)) {
        return new WP_Error('page_update_failed', $result->get_error_message(), ['status' => 500]);
    }

    return ['id' => $id, 'title' => get_the_title($id)];
}

/**
 * Удалить страницу.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_delete_page($request) {
    $id = (int)$request->get_param('id');
    $deleted = wp_delete_post($id, true);

    if (!$deleted) {
        return new WP_Error('delete_failed', 'Failed to delete page', ['status' => 500]);
    }

    return ['deleted' => true, 'id' => $id];
}

// ─── ПОЛЬЗОВАТЕЛИ ───────────────────────────────────────────────────

/**
 * Получить список пользователей.
 *
 * @return array
 */
function aipilot_get_users() {
    $users = get_users(['fields' => ['ID', 'user_login', 'display_name', 'user_email', 'user_registered', 'roles']]);
    return array_map(function($u) {
        return [
            'id'          => (int)$u->ID,
            'login'       => $u->user_login,
            'name'        => $u->display_name,
            'email'       => $u->user_email,
            'registered'  => $u->user_registered,
            'roles'       => $u->roles,
        ];
    }, $users);
}

// ─── ПЛАГИНЫ ────────────────────────────────────────────────────────

/**
 * Получить список установленных плагинов.
 *
 * @return array
 */
function aipilot_get_plugins() {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins = get_plugins();
    $active_plugins = get_option('active_plugins', []);

    $plugins = [];
    foreach ($all_plugins as $plugin_file => $data) {
        $plugins[] = [
            'file'          => $plugin_file,
            'name'          => $data['Name'],
            'version'       => $data['Version'],
            'active'        => in_array($plugin_file, $active_plugins),
            'plugin_uri'    => $data['PluginURI'],
            'description'   => $data['Description'],
            'author'        => $data['Author'],
        ];
    }

    return ['plugins' => $plugins, 'total' => count($plugins)];
}

/**
 * Поиск плагинов в репозитории WordPress.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_search_plugins($request) {
    if (!function_exists('plugins_api')) {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    }

    $search = sanitize_text_field($request->get_param('search'));
    $result = plugins_api('query_plugins', [
        'search' => $search,
        'per_page' => $request->get_param('per_page') ?: 20,
    ]);

    if (is_wp_error($result)) {
        return new WP_Error('search_failed', $result->get_error_message(), ['status' => 500]);
    }

    return [
        'plugins' => array_map(function($p) {
            return [
                'name'        => $p->name,
                'slug'        => $p->slug,
                'version'     => $p->version,
                'rating'      => $p->rating,
                'downloads'   => $p->downloaded,
                'description' => $p->short_description,
            ];
        }, $result->plugins),
        'total' => $result->info['results'],
    ];
}

/**
 * Установить плагин из репозитория.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_install_plugin($request) {
    if (!function_exists('plugins_api')) {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    }
    if (!class_exists('WP_Upgrader')) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';

    $slug = sanitize_text_field($request->get_param('slug'));
    $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);

    if (is_wp_error($api)) {
        return new WP_Error('plugin_not_found', $api->get_error_message(), ['status' => 404]);
    }

    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $result = $upgrader->install($api->download_link);

    if (is_wp_error($result)) {
        return new WP_Error('install_failed', $result->get_error_message(), ['status' => 500]);
    }

    return ['installed' => true, 'slug' => $slug, 'name' => $api->name];
}

/**
 * Загрузить плагин через ZIP-файл.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_upload_plugin($request) {
    if (!function_exists('wp_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    if (!class_exists('WP_Upgrader')) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';

    $files = $request->get_file_params();
    if (empty($files['plugin_zip'])) {
        return new WP_Error('no_file', 'No plugin zip file uploaded', ['status' => 400]);
    }

    $file = $files['plugin_zip'];

    if ($file['type'] !== 'application/zip' && substr($file['name'], -4) !== '.zip') {
        return new WP_Error('invalid_file', 'Uploaded file must be a .zip file', ['status' => 400]);
    }

    $upload = wp_handle_upload($file, ['test_form' => false]);

    if (!empty($upload['error'])) {
        return new WP_Error('upload_failed', $upload['error'], ['status' => 500]);
    }

    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $result = $upgrader->install($upload['file']);

    @unlink($upload['file']);

    if (is_wp_error($result)) {
        return new WP_Error('install_failed', $result->get_error_message(), ['status' => 500]);
    }

    return ['installed' => true];
}

/**
 * Активировать плагин.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_activate_plugin($request) {
    if (!function_exists('activate_plugin')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $slug = sanitize_text_field($request->get_param('slug'));

    // Find the plugin file by slug
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $plugin_file = '';
    foreach (get_plugins() as $file => $data) {
        if (strpos($file, $slug . '/') === 0 || strpos($file, $slug . '.php') !== false) {
            $plugin_file = $file;
            break;
        }
    }

    if (empty($plugin_file)) {
        return new WP_Error('not_found', "Plugin '{$slug}' not found", ['status' => 404]);
    }

    $result = activate_plugin($plugin_file);

    if (is_wp_error($result)) {
        return new WP_Error('activation_failed', $result->get_error_message(), ['status' => 500]);
    }

    return ['activated' => true, 'plugin' => $plugin_file];
}

/**
 * Деактивировать плагин.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_deactivate_plugin($request) {
    if (!function_exists('deactivate_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $slug = sanitize_text_field($request->get_param('slug'));

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $plugin_file = '';
    foreach (get_plugins() as $file => $data) {
        if (strpos($file, $slug . '/') === 0 || strpos($file, $slug . '.php') !== false) {
            $plugin_file = $file;
            break;
        }
    }

    if (empty($plugin_file)) {
        return new WP_Error('not_found', "Plugin '{$slug}' not found", ['status' => 404]);
    }

    deactivate_plugins($plugin_file);

    return ['deactivated' => true, 'plugin' => $plugin_file];
}

/**
 * Обновить плагин.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_update_plugin($request) {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $slug = sanitize_text_field($request->get_param('slug'));

    $plugin_file = '';
    foreach (get_plugins() as $file => $data) {
        if (strpos($file, $slug . '/') === 0 || strpos($file, $slug . '.php') !== false) {
            $plugin_file = $file;
            break;
        }
    }

    if (empty($plugin_file)) {
        return new WP_Error('not_found', "Plugin '{$slug}' not found", ['status' => 404]);
    }

    if (!class_exists('WP_Upgrader')) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';

    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $result = $upgrader->upgrade($plugin_file);

    if (is_wp_error($result)) {
        return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
    }

    return ['updated' => true, 'plugin' => $slug];
}

/**
 * Удалить плагин.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_delete_plugin($request) {
    if (!function_exists('delete_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';

    $slug = sanitize_text_field($request->get_param('slug'));

    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $plugin_file = '';
    foreach (get_plugins() as $file => $data) {
        if (strpos($file, $slug . '/') === 0 || strpos($file, $slug . '.php') !== false) {
            $plugin_file = $file;
            break;
        }
    }

    if (empty($plugin_file)) {
        return new WP_Error('not_found', "Plugin '{$slug}' not found", ['status' => 404]);
    }

    $deleted = delete_plugins([$plugin_file]);

    if (is_wp_error($deleted)) {
        return new WP_Error('delete_failed', $deleted->get_error_message(), ['status' => 500]);
    }

    return ['deleted' => true, 'plugin' => $slug];
}

// ─── МЕНЮ ───────────────────────────────────────────────────────────

/**
 * Получить список меню.
 *
 * @return array
 */
function aipilot_get_menus() {
    $menus = wp_get_nav_menus();
    return array_map(function($m) {
        return [
            'id'    => $m->term_id,
            'name'  => $m->name,
            'slug'  => $m->slug,
            'count' => $m->count,
        ];
    }, $menus);
}

/**
 * Получить пункты меню.
 *
 * @param WP_REST_Request $request
 * @return array
 */
function aipilot_get_menu_items($request) {
    $menu_id = (int)$request->get_param('id');
    $items = wp_get_nav_menu_items($menu_id);

    if (!$items) {
        return [];
    }

    return array_map(function($item) {
        return [
            'id'          => $item->ID,
            'title'       => $item->title,
            'url'         => $item->url,
            'target'      => $item->target,
            'parent'      => (int)$item->menu_item_parent,
            'order'       => (int)$item->menu_order,
            'object'      => $item->object,
            'object_id'   => (int)$item->object_id,
            'type'        => $item->type,
            'classes'     => $item->classes,
        ];
    }, $items);
}

/**
 * Создать меню.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_create_menu($request) {
    $menu_id = wp_create_nav_menu(sanitize_text_field($request->get_param('name')));

    if (is_wp_error($menu_id)) {
        return new WP_Error('menu_create_failed', $menu_id->get_error_message(), ['status' => 500]);
    }

    return ['id' => $menu_id, 'name' => $request->get_param('name')];
}

/**
 * Обновить меню.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_update_menu($request) {
    $result = wp_update_nav_menu_object((int)$request->get_param('id'), [
        'menu-name' => sanitize_text_field($request->get_param('name')),
    ]);

    if (is_wp_error($result)) {
        return new WP_Error('menu_update_failed', $result->get_error_message(), ['status' => 500]);
    }

    return ['updated' => true, 'id' => (int)$request->get_param('id')];
}

/**
 * Удалить меню.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_delete_menu($request) {
    $deleted = wp_delete_nav_menu((int)$request->get_param('id'));

    if (!$deleted || is_wp_error($deleted)) {
        return new WP_Error('delete_failed', 'Failed to delete menu', ['status' => 500]);
    }

    return ['deleted' => true];
}

/**
 * Добавить пункт меню.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_add_menu_item($request) {
    $menu_id = (int)$request->get_param('id');
    $item_data = [
        'menu-item-title'  => sanitize_text_field($request->get_param('title')),
        'menu-item-url'    => esc_url_raw($request->get_param('url') ?: ''),
        'menu-item-type'   => $request->get_param('type') ?: 'custom',
        'menu-item-status' => 'publish',
    ];

    if ($request->get_param('parent')) {
        $item_data['menu-item-parent-id'] = (int)$request->get_param('parent');
    }
    if ($request->get_param('order') !== null) {
        $item_data['menu-item-position'] = (int)$request->get_param('order');
    }
    if ($request->get_param('object_id')) {
        $item_data['menu-item-object-id'] = (int)$request->get_param('object_id');
    }
    if ($request->get_param('object')) {
        $item_data['menu-item-object'] = sanitize_text_field($request->get_param('object'));
    }

    $item_id = wp_update_nav_menu_item($menu_id, 0, $item_data);

    if (is_wp_error($item_id)) {
        return new WP_Error('item_create_failed', $item_id->get_error_message(), ['status' => 500]);
    }

    return ['id' => $item_id];
}

/**
 * Удалить пункт меню.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_delete_menu_item($request) {
    $deleted = wp_delete_post((int)$request->get_param('item_id'), true);

    if (!$deleted) {
        return new WP_Error('delete_failed', 'Failed to delete menu item', ['status' => 500]);
    }

    return ['deleted' => true];
}

/**
 * Пересортировать пункты меню.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_reorder_menu_items($request) {
    $menu_id = (int)$request->get_param('id');
    $items = $request->get_param('items');

    if (!is_array($items)) {
        return new WP_Error('invalid_data', 'Items must be an array of {id, order}', ['status' => 400]);
    }

    foreach ($items as $item) {
        if (isset($item['id']) && isset($item['order'])) {
            update_post_meta((int)$item['id'], '_menu_item_menu_order', (int)$item['order']);
        }
    }

    return ['reordered' => true];
}

// ─── ТЕМЫ ───────────────────────────────────────────────────────────

/**
 * Получить информацию об активной теме.
 *
 * @return array
 */
function aipilot_get_active_theme() {
    $theme = wp_get_theme();
    return [
        'name'        => $theme->get('Name'),
        'version'     => $theme->get('Version'),
        'author'      => $theme->get('Author'),
        'description' => $theme->get('Description'),
        'template'    => $theme->get_template(),
        'stylesheet'  => $theme->get_stylesheet(),
        'screenshot'  => $theme->get_screenshot(),
    ];
}

/**
 * Получить список всех установленных тем.
 *
 * @return array
 */
function aipilot_get_themes() {
    $themes = wp_get_themes();
    return array_map(function($t) {
        return [
            'name'       => $t->get('Name'),
            'version'    => $t->get('Version'),
            'author'     => $t->get('Author'),
            'stylesheet' => $t->get_stylesheet(),
            'active'     => $t->get_stylesheet() === get_stylesheet(),
        ];
    }, $themes);
}

/**
 * Переключить тему.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_switch_theme($request) {
    $stylesheet = sanitize_text_field($request->get_param('stylesheet'));
    $theme = wp_get_theme($stylesheet);

    if (!$theme->exists()) {
        return new WP_Error('theme_not_found', "Theme '{$stylesheet}' not found", ['status' => 404]);
    }

    switch_theme($stylesheet);

    return ['active' => true, 'theme' => $theme->get('Name')];
}

/**
 * Получить настройки темы (theme mods).
 *
 * @return array
 */
function aipilot_get_theme_mods() {
    return get_theme_mods();
}

/**
 * Установить настройки темы (theme mods).
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_set_theme_mods($request) {
    $mods = $request->get_param('mods');

    if (!is_array($mods)) {
        return new WP_Error('invalid_data', 'mods must be an object', ['status' => 400]);
    }

    foreach ($mods as $key => $value) {
        set_theme_mod(sanitize_text_field($key), $value);
    }

    return ['updated' => true];
}

// ─── ОПЦИИ ──────────────────────────────────────────────────────────

/**
 * Получить опции сайта.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_get_options($request) {
    $keys = $request->get_param('keys');

    if (is_string($keys)) {
        $keys = array_map('trim', explode(',', $keys));
    }

    if (is_array($keys) && !empty($keys)) {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = get_option(sanitize_text_field($key));
        }
        return $result;
    }

    return new WP_Error('missing_keys', 'Specify option keys as comma-separated or array', ['status' => 400]);
}

/**
 * Обновить опции сайта.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_update_options($request) {
    $options = $request->get_param('options');

    if (!is_array($options) || empty($options)) {
        return new WP_Error('invalid_data', 'options must be a non-empty object', ['status' => 400]);
    }

    foreach ($options as $key => $value) {
        update_option(sanitize_text_field($key), $value);
    }

    return ['updated' => true];
}

// ─── САМООБНОВЛЕНИЕ ─────────────────────────────────────────────────

/**
 * Самообновление плагина AI Pilot.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error|WP_REST_Response
 */
function aipilot_self_update($request) {
    $plugin_file = plugin_basename(AI_PILOT_PLUGIN_FILE);
    wp_update_plugins();

    $update_plugins = get_site_transient('update_plugins');
    if (!isset($update_plugins->response[$plugin_file])) {
        return new WP_REST_Response([
            'status'  => 'up_to_date',
            'message' => 'AI Pilot is already up to date',
            'version' => AI_PILOT_VERSION,
        ], 200);
    }

    if (!class_exists('WP_Upgrader')) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';

    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $result   = $upgrader->upgrade($plugin_file);

    if (is_wp_error($result)) {
        return new WP_Error('update_failed', $result->get_error_message(), ['status' => 500]);
    }

    return [
        'status'  => 'updated',
        'message' => 'AI Pilot updated successfully',
        'version' => AI_PILOT_VERSION,
    ];
}

// ═══════════════════════════════════════════════════════════════════
//  АУТЕНТИФИКАЦИЯ
// ═══════════════════════════════════════════════════════════════════

/**
 * Проверить API-токен из заголовка запроса.
 *
 * @return true|WP_Error
 */
function aipilot_verify_token() {
    $header = '';

    // Проверяем новый заголовок
    if (isset($_SERVER['HTTP_X_AI_PILOT_TOKEN'])) {
        $header = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_AI_PILOT_TOKEN']));
    }

    // Обратная совместимость
    if (empty($header) && isset($_SERVER['HTTP_X_OPENCLAW_TOKEN'])) {
        $header = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_OPENCLAW_TOKEN']));
    }

    if (empty($header)) {
        return new WP_Error('auth_required', 'Missing API token', ['status' => 401]);
    }

    $stored_hash = get_option('aipilot_api_token_hash', '');
    if (empty($stored_hash)) {
        return new WP_Error('auth_not_configured', 'API token not configured. Visit Settings > AI Pilot.', ['status' => 401]);
    }

    if (wp_hash($header) !== $stored_hash) {
        return new WP_Error('auth_invalid', 'Invalid API token', ['status' => 403]);
    }

    return true;
}

/**
 * Проверить токен и наличие capability.
 *
 * @param string $capability
 * @return true|WP_Error
 */
function aipilot_verify_token_and_can($capability) {
    $token_check = aipilot_verify_token();
    if (is_wp_error($token_check)) {
        return $token_check;
    }

    if (!aipilot_can($capability)) {
        return new WP_Error(
            'capability_denied',
            "API capability '{$capability}' is disabled",
            ['status' => 403]
        );
    }

    return true;
}

// ═══════════════════════════════════════════════════════════════════
//  СИСТЕМА ПРАВ (CAPABILITIES)
// ═══════════════════════════════════════════════════════════════════

/**
 * Получить список всех доступных capabilities.
 *
 * @return string[]
 */
function aipilot_get_core_capabilities() {
    return [
        'site_info',
        'posts_read', 'posts_create', 'posts_update', 'posts_delete',
        'categories_read', 'categories_create',
        'tags_read', 'tags_create', 'tags_update', 'tags_delete',
        'pages_read', 'pages_create', 'pages_update', 'pages_delete',
        'users_read',
        'plugins_read', 'plugins_install', 'plugins_upload',
        'plugins_activate', 'plugins_deactivate',
        'plugins_update', 'plugins_delete', 'plugins_search',
        'menus_read', 'menus_create', 'menus_update', 'menus_delete',
        'themes_read', 'themes_switch', 'themes_edit',
        'options_read', 'options_write',
        'full_access',
    ];
}

/**
 * Получить default-настройки прав (включены только чтение).
 *
 * @return array<string, bool>
 */
function aipilot_get_default_capabilities() {
    $defaults = [];
    foreach (aipilot_get_core_capabilities() as $cap) {
        $defaults[$cap] = in_array($cap, [
            'site_info', 'posts_read',
            'categories_read', 'tags_read',
            'pages_read', 'users_read',
            'plugins_read', 'themes_read',
            'menus_read', 'options_read',
        ]);
    }
    return apply_filters('aipilot_default_capabilities', $defaults);
}

/**
 * Получить текущие настройки прав (сохранённые + default).
 *
 * @return array<string, bool>
 */
function aipilot_get_capabilities() {
    $defaults = aipilot_get_default_capabilities();
    $saved    = get_option('aipilot_api_capabilities', []);
    return wp_parse_args($saved, $defaults);
}

/**
 * Проверить, включена ли указанная capability.
 *
 * @param string $capability
 * @return bool
 */
function aipilot_can($capability) {
    if (!is_string($capability) || empty(trim($capability))) {
        return false;
    }
    $caps = aipilot_get_capabilities();
    return !empty($caps[$capability]);
}

// ═══════════════════════════════════════════════════════════════════
//  СТРАНИЦА НАСТРОЕК В АДМИНКЕ
// ═══════════════════════════════════════════════════════════════════

add_action('admin_menu', function() {
    add_options_page(
        'AI Pilot',
        'AI Pilot',
        'manage_options',
        'ai-pilot-settings',
        'aipilot_admin_page'
    );
});

function aipilot_admin_page() {
    $new_token = null;

    // Генерация нового токена
    if (isset($_POST['aipilot_generate']) && check_admin_referer('aipilot_settings')) {
        $token      = wp_generate_password(64, false);
        $token_hash = wp_hash($token);

        update_option('aipilot_api_token_hash', $token_hash);
        delete_option('aipilot_api_token');

        $new_token = $token;
    }

    // Сохранение прав
    if (isset($_POST['aipilot_save_caps']) && check_admin_referer('aipilot_settings')) {
        $caps = [];
        foreach (aipilot_get_core_capabilities() as $cap) {
            $caps[$cap] = !empty($_POST["cap_{$cap}"]);
        }
        update_option('aipilot_api_capabilities', $caps);
        echo '<div class="notice notice-success"><p>Permissions saved.</p></div>';
    }

    // Сброс прав
    if (isset($_POST['aipilot_reset_caps']) && check_admin_referer('aipilot_settings')) {
        delete_option('aipilot_api_capabilities');
        echo '<div class="notice notice-info"><p>Permissions reset to defaults.</p></div>';
    }

    // Сохранение gateway URL
    if (isset($_POST['aipilot_save_gateway']) && check_admin_referer('aipilot_settings')) {
        if (!empty($_POST['aipilot_gateway_url'])) {
            update_option('aipilot_gateway_url', esc_url_raw($_POST['aipilot_gateway_url']));
            echo '<div class="notice notice-success"><p>Gateway URL saved.</p></div>';
        }
    }

    $caps = aipilot_get_capabilities();

    // Группировка для отображения
    $cap_groups = [
        'Site'      => ['site_info'],
        'Posts'     => ['posts_read', 'posts_create', 'posts_update', 'posts_delete'],
        'Categories' => ['categories_read', 'categories_create'],
        'Tags'      => ['tags_read', 'tags_create', 'tags_update', 'tags_delete'],
        'Pages'     => ['pages_read', 'pages_create', 'pages_update', 'pages_delete'],
        'Users'     => ['users_read'],
        'Plugins'   => ['plugins_read', 'plugins_install', 'plugins_upload', 'plugins_activate', 'plugins_deactivate', 'plugins_update', 'plugins_delete', 'plugins_search'],
        'Menus'     => ['menus_read', 'menus_create', 'menus_update', 'menus_delete'],
        'Themes'    => ['themes_read', 'themes_switch', 'themes_edit'],
        'Options'   => ['options_read', 'options_write'],
        'Agent'     => ['full_access'],
    ];

    $cap_labels = [
        'site_info'       => 'View site info',
        'posts_read'      => 'Read posts',
        'posts_create'    => 'Create posts',
        'posts_update'    => 'Update posts',
        'posts_delete'    => 'Delete posts',
        'categories_read' => 'Read categories',
        'categories_create' => 'Create categories',
        'tags_read'       => 'Read tags',
        'tags_create'     => 'Create tags',
        'tags_update'     => 'Update tags',
        'tags_delete'     => 'Delete tags',
        'pages_read'      => 'Read pages',
        'pages_create'    => 'Create pages',
        'pages_update'    => 'Update pages',
        'pages_delete'    => 'Delete pages',
        'users_read'      => 'Read users',
        'plugins_read'    => 'Read plugins',
        'plugins_install' => 'Install plugins',
        'plugins_upload'  => 'Upload plugins',
        'plugins_activate'  => 'Activate plugins',
        'plugins_deactivate' => 'Deactivate plugins',
        'plugins_update'  => 'Update plugins',
        'plugins_delete'  => 'Delete plugins',
        'plugins_search'  => 'Search plugins',
        'menus_read'      => 'Read menus',
        'menus_create'    => 'Create menus',
        'menus_update'    => 'Update menus',
        'menus_delete'    => 'Delete menus',
        'themes_read'     => 'Read themes',
        'themes_switch'   => 'Switch themes',
        'themes_edit'     => 'Edit theme mods',
        'options_read'    => 'Read options',
        'options_write'   => 'Write options',
        'full_access'     => 'Full agent access (all actions)',
    ];
    ?>
    <div class="wrap">
        <h1>AI Pilot – Remote Site API</h1>
        <p>REST API для удалённого управления сайтом через AI Pilot.</p>

        <hr>

        <h2>API Token</h2>
        <form method="post">
            <?php wp_nonce_field('aipilot_settings'); ?>
            <p>
                <button type="submit" name="aipilot_generate" class="button button-primary">
                    Generate New Token
                </button>
            </p>
        </form>

        <?php if ($new_token): ?>
        <div class="notice notice-warning" style="background:#fff3cd;border-left-color:#ffb900;">
            <p><strong>⚠️ New token generated! Copy it now — it won't be shown again.</strong></p>
            <pre style="background:#f1f1f1;padding:15px;font-size:16px;overflow:auto;"><?php echo esc_html($new_token); ?></pre>
        </div>
        <?php endif; ?>

        <p><strong>Current token hash:</strong> <code><?php echo esc_html(substr(get_option('aipilot_api_token_hash', '—'), 0, 20)); ?>…</code></p>

        <hr>

        <h2>🔗 Подключение к AI Pilot</h2>
        <p>Подключите этот сайт к вашему аккаунту AI Pilot для управления через чат.</p>

        <?php
        $connected_site = get_option('aipilot_connected_site', '');
        $connected_at   = get_option('aipilot_connected_at', '');
        ?>

        <?php if ($connected_site): ?>
        <div class="notice notice-success" style="background:#edfaef;border-left-color:#46b450;">
            <p>✅ Сайт подключён к <strong><?php echo esc_html($connected_site); ?></strong></p>
            <p style="font-size:0.85rem;color:#666;">Подключён: <?php echo esc_html($connected_at); ?></p>
        </div>
        <?php endif; ?>

        <div style="margin-top:10px">
            <p>
                <button
                    type="button"
                    class="button button-primary"
                    style="background:#7837df;border-color:#7837df;color:#fff"
                    onclick="window.open(
                        '<?php echo esc_js(add_query_arg([
                            'site' => urlencode(get_site_url()),
                            'token' => '',
                            'redirect' => urlencode(admin_url('options-general.php?page=ai-pilot-settings&connected=1')),
                            'session' => 'agent:main:main'
                        ], 'https://chat.pilotsite.ru/connect')); ?>',
                        'aipilot-auth',
                        'width=480,height=640,scrollbars=yes'
                    ); return false;"
                >
                    <?php echo $connected_site ? '🔄 Переподключить' : '🔗 Подключить к AI Pilot'; ?>
                </button>
                <?php if ($connected_site): ?>
                <form method="post" style="display:inline">
                    <?php wp_nonce_field('aipilot_connect'); ?>
                    <button type="submit" name="aipilot_disconnect" class="button" style="color:#d32f2f">
                        🔌 Отключить
                    </button>
                </form>
                <?php endif; ?>
            </p>
        </div>

        // Обработка отключения
        if (isset($_POST['aipilot_disconnect']) && check_admin_referer('aipilot_connect')) {
            delete_option('aipilot_connected_site');
            delete_option('aipilot_connected_at');
            echo '<div class="notice notice-info"><p>🔌 Сайт отключён от AI Pilot.</p></div>';
        }

        // Обработка callback (через API, не через редирект)
        if (isset($_GET['connected']) && $_GET['connected'] === '1') {
            update_option('aipilot_connected_site', get_site_url());
            update_option('aipilot_connected_at', current_time('mysql'));
            echo '<div class="notice notice-success"><p>✅ Сайт успешно подключён!</p></div>';
        }
        ?>

        <hr>

        <h2>Permissions</h2>
        <form method="post">
            <?php wp_nonce_field('aipilot_settings'); ?>

            <?php foreach ($cap_groups as $group => $group_caps): ?>
            <h3><?php echo esc_html($group); ?></h3>
            <table class="form-table" style="margin-top:0">
                <tbody>
                <?php foreach ($group_caps as $cap): ?>
                <tr>
                    <th scope="row" style="width:250px">
                        <label for="cap_<?php echo esc_attr($cap); ?>">
                            <?php echo esc_html($cap_labels[$cap] ?? $cap); ?>
                        </label>
                    </th>
                    <td>
                        <input type="checkbox"
                               id="cap_<?php echo esc_attr($cap); ?>"
                               name="cap_<?php echo esc_attr($cap); ?>"
                               value="1" <?php checked(!empty($caps[$cap])); ?>>
                        <code><?php echo esc_html($cap); ?></code>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endforeach; ?>

            <p>
                <button type="submit" name="aipilot_save_caps" class="button button-primary">
                    Save Permissions
                </button>
                <button type="submit" name="aipilot_reset_caps" class="button" onclick="return confirm('Reset all permissions to defaults?')">
                    Reset to Defaults
                </button>
            </p>
        </form>

        <hr>

        <h2>AI Pilot Gateway</h2>
        <form method="post">
            <?php wp_nonce_field('aipilot_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="gateway_url">Gateway URL</label></th>
                    <td>
                        <input type="url" id="gateway_url" name="aipilot_gateway_url"
                               value="<?php echo esc_url(get_option('aipilot_gateway_url', 'https://pilotsite.ru')); ?>"
                               class="regular-text" placeholder="https://pilotsite.ru">
                        <p class="description">URL сервера AI Pilot (по умолчанию pilotsite.ru)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Site ID</th>
                    <td>
                        <code><?php echo esc_html(function_exists('aipilot_get_site_id') ? aipilot_get_site_id() : '—'); ?></code>
                        <p class="description">Уникальный идентификатор сайта в системе AI Pilot</p>
                    </td>
                </tr>
            </table>
            <p><button type="submit" name="aipilot_save_gateway" class="button">Save Gateway Settings</button></p>
        </form>

        <hr>

        <h2>API Usage</h2>
        <p>Make requests with the header:</p>
        <pre style="background:#f1f1f1;padding:10px;">X-AI-Pilot-Token: your-token-here</pre>
        <p>Base URL: <code><?php echo esc_url(rest_url('aipilot/v1')); ?></code></p>

        <h3>Endpoints</h3>
        <ul>
            <li><code>GET /aipilot/v1/ping</code> — connection test</li>
            <li><code>GET /aipilot/v1/site</code> — site info</li>
            <li><code>GET/POST /aipilot/v1/posts</code> — list/create posts</li>
            <li><code>PUT/DELETE /aipilot/v1/posts/{id}</code> — update/delete post</li>
            <li><code>GET/POST /aipilot/v1/pages</code> — list/create pages</li>
            <li><code>GET/POST /aipilot/v1/categories</code> — list/create categories</li>
            <li><code>GET/POST /aipilot/v1/tags</code> — list/create tags</li>
            <li><code>GET /aipilot/v1/users</code> — list users</li>
            <li><code>GET /aipilot/v1/plugins</code> — list plugins</li>
            <li><code>POST /aipilot/v1/plugins/install</code> — install plugin</li>
            <li><code>GET/POST /aipilot/v1/menus</code> — list/create menus</li>
            <li><code>GET /aipilot/v1/theme</code> — get active theme</li>
            <li><code>GET/PUT /aipilot/v1/options</code> — read/write options</li>
            <li><hr></li>
            <li><strong>🤖 AI Agent</strong></li>
            <li><code>GET /aipilot/v1/structure</code> — full site structure for agent context</li>
            <li><code>GET /aipilot/v1/overview</code> — quick site overview</li>
            <li><code>POST /aipilot/v1/agent</code> — execute agent actions</li>
            <li><code>POST /aipilot/v1/agent/propose</code> — propose an action (human-in-the-loop)</li>
            <li><code>GET /aipilot/v1/agent/pending</code> — list pending proposals</li>
            <li><code>POST /aipilot/v1/agent/approve/{id}</code> — approve a proposal</li>
            <li><code>POST /aipilot/v1/agent/reject/{id}</code> — reject a proposal</li>
        </ul>
        <p><em>Подробнее: <code>docs/</code></em></p>
    </div>
    <?php
}
