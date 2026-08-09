<?php
/**
 * AI Pilot – Agent Communication Module
 *
 * Обеспечивает связь между сайтом и AI-агентом:
 * - Полная структура сайта (контекст для агента)
 * - Просмотр и управление контентом (универсальный `/agent`)
 * - Human-in-the-loop: предложение → утверждение → исполнение
 *
 * @package AI_Pilot
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once AI_PILOT_PLUGIN_DIR . 'src/taxonomy-helpers.php';

// ─── РЕГИСТРАЦИЯ ЭНДПОИНТОВ ────────────────────────────────────────

add_action('rest_api_init', function () {
    // Полная структура сайта
    aipilot_register_route('/structure', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_agent_structure',
        'permission_callback' => function () { return aipilot_verify_token_and_can('site_info'); },
    ]);

    // Универсальный эндпоинт агента (читать/создать/изменить/удалить контент)
    aipilot_register_route('/agent', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_agent_execute',
        'permission_callback' => function () { return aipilot_verify_token_and_can('full_access'); },
    ]);

    // Human-in-the-loop: предложить действие
    aipilot_register_route('/agent/propose', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_agent_propose',
        'permission_callback' => function () { return aipilot_verify_token_and_can('full_access'); },
    ]);

    // Список ожидающих предложений
    aipilot_register_route('/agent/pending', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_agent_pending',
        'permission_callback' => function () { return aipilot_verify_token_and_can('site_info'); },
    ]);

    // Утвердить предложение
    aipilot_register_route('/agent/approve/(?P<id>\d+)', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_agent_approve',
        'permission_callback' => function () { return aipilot_verify_token_and_can('full_access'); },
    ]);

    // Отклонить предложение
    aipilot_register_route('/agent/reject/(?P<id>\d+)', [
        'methods'             => 'POST',
        'callback'            => 'aipilot_agent_reject',
        'permission_callback' => function () { return aipilot_verify_token_and_can('full_access'); },
    ]);

    // Детали одного предложения
    aipilot_register_route('/agent/proposal/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_agent_get_proposal',
        'permission_callback' => function () { return aipilot_verify_token_and_can('site_info'); },
    ]);

    // Статистика сайта (быстрый обзор)
    aipilot_register_route('/overview', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_agent_overview',
        'permission_callback' => function () { return aipilot_verify_token_and_can('site_info'); },
    ]);
});

// ═══════════════════════════════════════════════════════════════════
//  1. ПОЛНАЯ СТРУКТУРА САЙТА
//     Возвращает всё, что нужно агенту для понимания сайта
// ═══════════════════════════════════════════════════════════════════

/**
 * Полная структура сайта для контекста AI-агента.
 *
 * @return array
 */
function aipilot_agent_structure() {
    // ─── БАЗОВАЯ ИНФОРМАЦИЯ ───────────────────────────────────
    $posts = get_posts([
        'post_type'      => 'post',
        'post_status'    => ['publish', 'draft', 'pending'],
        'posts_per_page' => 100,
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ]);

    $pages = get_posts([
        'post_type'      => 'page',
        'post_status'    => ['publish', 'draft'],
        'posts_per_page' => 100,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    ]);

    $categories = get_categories(['hide_empty' => false]);
    $tags       = get_tags(['hide_empty' => false]);
    $theme      = wp_get_theme();
    $active_plugins_slugs = get_option('active_plugins', []);

    // ─── МЕНЮ ────────────────────────────────────────────────
    $menu_data = [];
    foreach (wp_get_nav_menus() as $menu) {
        $items = wp_get_nav_menu_items($menu->term_id);
        $menu_data[] = [
            'id'    => $menu->term_id,
            'name'  => $menu->name,
            'count' => $menu->count,
            'items' => $items ? array_map(function ($item) {
                return [
                    'id'     => $item->ID,
                    'title'  => $item->title,
                    'url'    => $item->url,
                    'parent' => (int) $item->menu_item_parent,
                    'order'  => (int) $item->menu_order,
                ];
            }, $items) : [],
        ];
    }

    // ─── АНАЛИЗ ТЕМАТИКИ САЙТА ──────────────────────────────
    $analysis = aipilot_analyze_site_profile($posts, $categories, $active_plugins_slugs);

    // ─── СТИЛИ И ВНЕШНИЙ ВИД ────────────────────────────────
    $styles = aipilot_extract_theme_styles();

    // ─── ТИПЫ КОНТЕНТА ───────────────────────────────────────
    $post_types = [];
    foreach (get_post_types(['public' => true], 'objects') as $pt) {
        if ($pt->name === 'attachment') continue;
        $counts = wp_count_posts($pt->name);
        $post_types[$pt->name] = [
            'label'     => $pt->label,
            'total'     => ($counts->publish ?? 0) + ($counts->draft ?? 0) + ($counts->pending ?? 0),
            'published' => (int) ($counts->publish ?? 0),
            'draft'     => (int) ($counts->draft ?? 0),
            'supports'  => get_all_post_type_supports($pt->name) ?: [],
        ];
    }

    // ─── КОММЕНТАРИИ (последние) ────────────────────────────
    $recent_comments = get_comments([
        'number' => 20,
        'status' => 'approve',
    ]);

    $comment_sentiment = aipilot_analyze_comments($recent_comments);

    // ─── СБОРКА ──────────────────────────────────────────────
    return [
        'site' => [
            'name'        => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url'         => get_bloginfo('url'),
            'language'    => get_bloginfo('language'),
            'wp_version'  => get_bloginfo('version'),
            'timezone'    => get_option('timezone_string') ?: 'UTC',
            'tagline'     => get_bloginfo('description'),
            'admin_email' => get_bloginfo('admin_email'),
        ],
        'analysis' => $analysis,
        'appearance' => [
            'theme'  => [
                'name'        => $theme->get('Name'),
                'version'     => $theme->get('Version'),
                'author'      => $theme->get('Author'),
                'description' => $theme->get('Description'),
                'template'    => $theme->get_template(),
                'stylesheet'  => $theme->get_stylesheet(),
                'screenshot'  => $theme->get_screenshot(),
                'tags'        => $theme->get('Tags'),
            ],
            'styles' => $styles,
            'menus'  => $menu_data,
            'homepage' => [
                'is_front_page' => (bool) get_option('show_on_front') === 'page',
                'front_page_id' => (int) get_option('page_on_front'),
                'posts_page_id' => (int) get_option('page_for_posts'),
            ],
        ],
        'content' => [
            'posts'        => array_map('aipilot_format_structure_post', $posts),
            'pages'        => array_map('aipilot_format_structure_page', $pages),
            'categories'   => array_map(function ($c) {
                return ['id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug, 'count' => $c->count, 'parent' => $c->parent];
            }, $categories),
            'tags'         => array_map(function ($t) {
                return ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count];
            }, $tags),
            'post_types'   => $post_types,
            'comments'     => [
                'total'     => (int) wp_count_comments()->approved,
                'recent'    => array_map(function ($c) {
                    return [
                        'id'      => $c->comment_ID,
                        'author'  => $c->comment_author,
                        'post_id' => $c->comment_post_ID,
                        'date'    => $c->comment_date,
                        'content' => wp_trim_words($c->comment_content, 30),
                    ];
                }, $recent_comments),
                'sentiment' => $comment_sentiment,
            ],
        ],
        'plugins' => [
            'active_count' => count($active_plugins_slugs),
            'active'       => $active_plugins_slugs,
        ],
        'system' => [
            'php_version' => PHP_VERSION,
            'multisite'   => is_multisite(),
            'uploads_url' => wp_upload_dir()['baseurl'],
            'users_total' => count_users()['total_users'],
        ],
        'config' => [
            'site_id'     => aipilot_get_site_id(),
            'api_url'     => rest_url('aipilot/v1'),
            'plugin_ver'  => AI_PILOT_VERSION,
        ],
        'timestamp' => current_time('c'),
    ];
}

// ═══════════════════════════════════════════════════════════════════
//  ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ АНАЛИЗА
// ═══════════════════════════════════════════════════════════════════

/**
 * Анализ профиля сайта: тематика, стиль общения, тип сайта
 */
function aipilot_analyze_site_profile($posts, $categories, $active_plugins) {
    $profile = [
        'site_type' => aipilot_detect_site_type($active_plugins),
        'topics'    => [],
        'tone'      => aipilot_analyze_tone_from_content($posts),
        'posting'   => aipilot_analyze_posting_patterns($posts),
        'audience'  => [],
    ];

    // Определяем тематику по названиям категорий
    $topic_keywords = [];
    foreach ($categories as $cat) {
        // Категории первого уровня — это основные разделы
        if ($cat->parent === 0 && $cat->name !== 'Uncategorized') {
            $profile['topics'][] = [
                'name'  => $cat->name,
                'slug'  => $cat->slug,
                'count' => $cat->count,
            ];
        }
    }

    // Определяем аудиторию по типу сайта
    $profile['audience'] = aipilot_infer_audience($profile['site_type'], $active_plugins);

    return $profile;
}

/**
 * Определение типа сайта по активным плагинам
 */
function aipilot_detect_site_type($plugins) {
    $plugin_names = implode(' ', $plugins);
    $type_scores = [
        'blog'      => ['score' => 1, 'reason' => 'Standard WordPress site'],
        'shop'      => ['score' => 0, 'reason' => ''],
        'business'  => ['score' => 0, 'reason' => ''],
        'portfolio' => ['score' => 0, 'reason' => ''],
        'forum'     => ['score' => 0, 'reason' => ''],
        'membership' => ['score' => 0, 'reason' => ''],
        'learning'  => ['score' => 0, 'reason' => ''],
        'landing'   => ['score' => 0, 'reason' => ''],
    ];

    if (preg_match('/woocommerce/i', $plugin_names)) {
        $type_scores['shop'] = ['score' => 10, 'reason' => 'WooCommerce detected'];
    }
    if (preg_match('/bbpress|wpforo|dwqa|anspress/i', $plugin_names)) {
        $type_scores['forum'] = ['score' => 8, 'reason' => 'Forum plugin detected'];
    }
    if (preg_match('/elementor|beaver|divi|wpbakery|visualcomposer/i', $plugin_names)) {
        $type_scores['business']['score'] += 2;
    }
    if (preg_match('/learndash|lifterlms|tutor|sensei/i', $plugin_names)) {
        $type_scores['learning'] = ['score' => 9, 'reason' => 'LMS plugin detected'];
    }
    if (preg_match('/memberpress|paid-memberships|restrict|ultimate-member/i', $plugin_names)) {
        $type_scores['membership'] = ['score' => 8, 'reason' => 'Membership plugin detected'];
    }
    if (preg_match('/jetpack|cache|seo|wordfence|akismet|contact.form/i', $plugin_names)) {
        $type_scores['blog']['score'] += 2;
        $type_scores['business']['score'] += 1;
    }
    if (preg_match('/easy.digital|edd|ecwid|wp.simple.pay/i', $plugin_names)) {
        $type_scores['shop']['score'] = max($type_scores['shop']['score'], 7);
        $type_scores['shop']['reason'] = 'Digital store plugin detected';
    }

    // Сортируем по score (убывание) и берём лучший
    uasort($type_scores, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    $best_type = array_keys($type_scores)[0];
    $best_data = $type_scores[$best_type];
    $best_data['type_name'] = $best_type;

    return $best_data;
}

/**
 * Анализ тональности и стиля общения по заголовкам и контенту
 */
function aipilot_analyze_tone_from_content($posts) {
    if (empty($posts)) {
        return [
            'style'  => 'unknown',
            'detail' => 'No published posts to analyze',
            'signals' => [],
        ];
    }

    $total_chars     = 0;
    $total_words     = 0;
    $exclamation     = 0;
    $question        = 0;
    $emoji_titles    = 0;
    $long_titles     = 0;
    $short_titles    = 0;
    $caps_titles     = 0;
    $formal_patterns = 0;
    $informal_words  = 0;

    $informal_markers = ['как', 'почему', 'секрет', 'топ', 'лучший', 'простой', 'крутой', 'лайфхак',
                         'how to', 'why', 'secret', 'top', 'best', 'easy', 'simple', 'hack', 'guide',
                         'совет', 'способы', 'бесплатно', 'пошаговый'];

    foreach ($posts as $post) {
        if ($post->post_status !== 'publish') continue;

        $title   = $post->post_title;
        $content = $post->post_content;

        $total_chars += strlen($title) + strlen($content);
        $total_words += str_word_count($title) + str_word_count(strip_tags($content));

        if (substr(trim($title), -1) === '!') $exclamation++;
        if (substr(trim($title), -1) === '?') $question++;

        // Эмодзи в заголовке
        if (preg_match('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}]/u', $title)) {
            $emoji_titles++;
        }

        $title_len = strlen($title);
        if ($title_len > 60) $long_titles++;
        if ($title_len > 0 && $title_len < 20) $short_titles++;
        if (strtoupper($title) === $title && $title_len > 10) $caps_titles++;

        // Формальные маркеры (нумерация, даты, двоеточие)
        if (preg_match('/^\d+[\.\)\:]|^[A-Z][a-z]+ \d/', $title)) $formal_patterns++;

        // Неформальные слова
        $lower = mb_strtolower($title);
        foreach ($informal_markers as $w) {
            if (mb_strpos($lower, $w) !== false) {
                $informal_words++;
                break;
            }
        }
    }

    $total_analyzed = max(1, count($posts));
    $informal_ratio = $informal_words / $total_analyzed;
    $emoji_ratio    = $emoji_titles / $total_analyzed;
    $excl_ratio     = $exclamation / $total_analyzed;

    // Определяем стиль
    if ($informal_ratio > 0.3 || $emoji_ratio > 0.2 || $excl_ratio > 0.15) {
        $style  = 'casual';
        $detail = 'Conversational, uses informal language and engaging titles';
    } elseif ($formal_patterns > $total_analyzed * 0.3) {
        $style  = 'formal';
        $detail = 'Professional, structured titles with formal language';
    } else {
        $style  = 'balanced';
        $detail = 'Mix of formal and informal, neutral tone';
    }

    return [
        'style'  => $style,
        'detail' => $detail,
        'signals' => [
            'analyzed_posts' => $total_analyzed,
            'avg_title_len'  => $total_analyzed > 0 ? round(strlen(implode('', array_column($posts, 'post_title'))) / $total_analyzed) : 0,
            'informal_titles' => $informal_words,
            'emoji_titles'    => $emoji_titles,
            'question_titles' => $question,
            'exclamation_titles' => $exclamation,
            'formal_patterns' => $formal_patterns,
        ],
    ];
}

/**
 * Анализ паттернов публикаций
 */
function aipilot_analyze_posting_patterns($posts) {
    if (empty($posts)) {
        return ['frequency' => 'unknown', 'avg_post_length' => 0, 'total_published' => 0];
    }

    $published = array_filter($posts, function ($p) {
        return $p->post_status === 'publish';
    });

    $total_published = count($published);
    if ($total_published === 0) {
        return ['frequency' => 'no_published', 'avg_post_length' => 0, 'total_published' => 0];
    }

    // Средняя длина
    $total_length = 0;
    $dates        = [];
    foreach ($published as $p) {
        $total_length += strlen(strip_tags($p->post_content));
        $dates[] = substr($p->post_date, 0, 7); // YYYY-MM
    }

    $avg_length = (int) ($total_length / $total_published);

    // Частота публикаций
    $months = array_unique($dates);
    $months_count = count($months);
    $per_month = $months_count > 0 ? round($total_published / $months_count, 1) : 0;

    if ($per_month >= 10) {
        $frequency = 'very_high';
        $freq_desc = "~{$per_month} posts per month";
    } elseif ($per_month >= 4) {
        $frequency = 'high';
        $freq_desc = "~{$per_month} posts per month";
    } elseif ($per_month >= 1) {
        $frequency = 'moderate';
        $freq_desc = "~{$per_month} posts per month";
    } else {
        $frequency = 'low';
        $freq_desc = 'Less than 1 post per month';
    }

    // Длина: короткий/средний/длинный контент
    if ($avg_length > 5000) {
        $content_type = 'long_form';
    } elseif ($avg_length > 1500) {
        $content_type = 'standard';
    } else {
        $content_type = 'short';
    }

    return [
        'frequency'       => $frequency,
        'frequency_desc'  => $freq_desc,
        'total_published' => $total_published,
        'months_active'   => $months_count,
        'per_month'       => $per_month,
        'avg_post_length' => $avg_length,
        'content_type'    => $content_type,
    ];
}

/**
 * Определение аудитории
 */
function aipilot_infer_audience($site_type, $plugins) {
    $type = $site_type['type_name'] ?? 'blog';
    $plugin_names = implode(' ', $plugins);

    $audience = [
        'type'   => 'general',
        'detail' => 'General audience',
    ];

    $audience_map = [
        'shop'       => ['type' => 'customers', 'detail' => 'Online shoppers and customers'],
        'forum'      => ['type' => 'community', 'detail' => 'Community members and hobbyists'],
        'learning'   => ['type' => 'students', 'detail' => 'Students and learners'],
        'membership' => ['type' => 'members', 'detail' => 'Paid members and subscribers'],
        'portfolio'  => ['type' => 'clients', 'detail' => 'Potential clients and employers'],
    ];

    if (isset($audience_map[$type])) {
        $audience = $audience_map[$type];
    }

    // Уточняем по плагинам
    if (preg_match('/woocommerce/i', $plugin_names)) {
        $audience['detail'] .= ' (e-commerce)';
    }
    if (preg_match('/b2b|wholesale/i', $plugin_names)) {
        $audience['detail'] .= ', B2B';
    }

    return $audience;
}

/**
 * Анализ комментариев (активность, тональность)
 */
function aipilot_analyze_comments($comments) {
    $analysis = [
        'activity'     => 'none',
        'total_recent' => count($comments),
    ];

    if (empty($comments)) {
        $analysis['activity'] = 'none';
        $analysis['detail']   = 'No recent comments';
        return $analysis;
    }

    $count = count($comments);
    if ($count > 50) {
        $analysis['activity'] = 'high';
        $analysis['detail']   = 'Active community with frequent comments';
    } elseif ($count > 10) {
        $analysis['activity'] = 'moderate';
        $analysis['detail']   = 'Some reader engagement';
    } else {
        $analysis['activity'] = 'low';
        $analysis['detail']   = 'Few recent comments';
    }

    return $analysis;
}

/**
 * Извлечение стилей темы
 */
function aipilot_extract_theme_styles() {
    $styles = [
        'colors'      => [],
        'fonts'       => [],
        'logo'        => null,
        'favicon'     => null,
        'custom_css'  => null,
        'has_block_theme' => function_exists('wp_is_block_theme') && wp_is_block_theme(),
    ];

    // Логотип
    $custom_logo_id = get_theme_mod('custom_logo');
    if ($custom_logo_id) {
        $logo = wp_get_attachment_image_src($custom_logo_id, 'full');
        if ($logo) {
            $styles['logo'] = [
                'url'   => $logo[0],
                'width' => $logo[1],
                'height' => $logo[2],
            ];
        }
    }

    // Favicon
    $site_icon_id = get_option('site_icon');
    if ($site_icon_id) {
        $favicon = wp_get_attachment_image_src($site_icon_id, [32, 32]);
        if ($favicon) {
            $styles['favicon'] = $favicon[0];
        }
    }

    // Global styles (block themes)
    if ($styles['has_block_theme']) {
        $global_styles = wp_get_global_styles();
        if (!empty($global_styles['color'])) {
            $styles['colors'] = $global_styles['color'];
        }
        if (!empty($global_styles['typography'])) {
            $styles['fonts'] = $global_styles['typography'];
        }
    } else {
        // Classic theme: из theme mods
        $color_scheme = [
            'background_color' => get_theme_mod('background_color') ?: get_background_color(),
            'header_color'     => get_theme_mod('header_textcolor'),
            'link_color'       => get_theme_mod('link_color'),
        ];
        $styles['colors'] = array_filter($color_scheme);
    }

    // Кастомный CSS
    $custom_css = wp_get_custom_css();
    if (!empty($custom_css)) {
        $styles['custom_css'] = [
            'size_bytes' => strlen($custom_css),
            'preview'    => substr($custom_css, 0, 500),
        ];
    }

    return $styles;
}

/**
 * Форматировать пост для структуры.
 *
 * @param WP_Post $p
 * @return array
 */
function aipilot_format_structure_post($p) {
    $content_preview = strip_tags($p->post_content);
    return [
        'id'             => $p->ID,
        'title'          => $p->post_title,
        'slug'           => $p->post_name,
        'status'         => $p->post_status,
        'date'           => $p->post_date,
        'modified'       => $p->post_modified,
        'author'         => (int) $p->post_author,
        'categories'     => wp_get_post_categories($p->ID, ['fields' => 'names']),
        'tags'           => wp_get_post_tags($p->ID, ['fields' => 'names']),
        'excerpt'        => $p->post_excerpt,
        'content_length' => strlen($content_preview),
        'content_preview' => mb_substr($content_preview, 0, 200),
        'permalink'      => get_permalink($p->ID),
        'featured_image' => get_the_post_thumbnail_url($p->ID, 'full'),
    ];
}

/**
 * Форматировать страницу для структуры.
 *
 * @param WP_Post $p
 * @return array
 */
function aipilot_format_structure_page($p) {
    return [
        'id'         => $p->ID,
        'title'      => $p->post_title,
        'slug'       => $p->post_name,
        'status'     => $p->post_status,
        'parent'     => (int) $p->post_parent,
        'menu_order' => (int) $p->menu_order,
        'permalink'  => get_permalink($p->ID),
    ];
}

// ═══════════════════════════════════════════════════════════════════
//  2. БЫСТРЫЙ ОБЗОР (для дашборда)
// ═══════════════════════════════════════════════════════════════════

/**
 * Быстрый обзор сайта (для дашборда).
 *
 * @return array
 */
function aipilot_agent_overview() {
    $posts_count   = wp_count_posts('post');
    $pages_count   = wp_count_posts('page');
    $active_plugins = get_option('active_plugins', []);

    // Последние комментарии
    $recent_comments = get_comments([
        'number' => 5,
        'status' => 'approve',
    ]);

    return [
        'content' => [
            'posts'       => [
                'published' => (int) ($posts_count->publish ?? 0),
                'drafts'    => (int) ($posts_count->draft ?? 0),
            ],
            'pages'       => (int) ($pages_count->publish ?? 0),
            'categories'  => (int) wp_count_terms('category'),
            'tags'        => (int) wp_count_terms('post_tag'),
            'media'       => (int) wp_count_posts('attachment')->inherit ?? 0,
        ],
        'system' => [
            'wp_version'      => get_bloginfo('version'),
            'php_version'     => PHP_VERSION,
            'plugins_active'  => count($active_plugins),
            'plugins_total'   => count(get_plugins()),
            'theme'           => wp_get_theme()->get('Name'),
            'users'           => count_users()['total_users'],
        ],
        'recent' => [
            'comments' => array_map(function ($c) {
                return [
                    'id'      => $c->comment_ID,
                    'author'  => $c->comment_author,
                    'post_id' => $c->comment_post_ID,
                    'date'    => $c->comment_date,
                    'content' => wp_trim_words($c->comment_content, 10),
                ];
            }, $recent_comments),
        ],
    ];
}

// ═══════════════════════════════════════════════════════════════════
//  3. УНИВЕРСАЛЬНЫЙ ЭНДПОИНТ АГЕНТА
//     action: get_posts | create_post | update_post | delete_post |
//             get_pages | create_page | get_menus | get_plugins |
//             get_theme | get_options | update_options
// ═══════════════════════════════════════════════════════════════════

/**
 * Получить маппинг действий агента на функции и необходимые права.
 *
 * @return array<string, array{func: string, cap: string}> Action map.
 */
function aipilot_get_agent_action_map() {
    return [
        // Посты
        'get_posts'       => ['func' => 'aipilot_agent_get_posts',       'cap' => 'posts_read'],
        'get_post'        => ['func' => 'aipilot_agent_get_post',        'cap' => 'posts_read'],
        'create_post'     => ['func' => 'aipilot_agent_create_post',     'cap' => 'posts_create'],
        'update_post'     => ['func' => 'aipilot_agent_update_post',     'cap' => 'posts_update'],
        'delete_post'     => ['func' => 'aipilot_agent_delete_post',     'cap' => 'posts_delete'],
        // Страницы
        'get_pages'       => ['func' => 'aipilot_agent_get_pages',       'cap' => 'pages_read'],
        'create_page'     => ['func' => 'aipilot_agent_create_page',     'cap' => 'pages_create'],
        'update_page'     => ['func' => 'aipilot_agent_update_page',     'cap' => 'pages_update'],
        'delete_page'     => ['func' => 'aipilot_agent_delete_page',     'cap' => 'pages_delete'],
        // Категории и теги
        'get_categories'  => ['func' => 'aipilot_agent_get_categories',  'cap' => 'categories_read'],
        'create_category' => ['func' => 'aipilot_agent_create_category', 'cap' => 'categories_create'],
        'get_tags'        => ['func' => 'aipilot_agent_get_tags',        'cap' => 'tags_read'],
        'create_tag'      => ['func' => 'aipilot_agent_create_tag',      'cap' => 'tags_create'],
        // Меню
        'get_menus'       => ['func' => 'aipilot_agent_get_menus',       'cap' => 'menus_read'],
        // Темы
        'get_theme'       => ['func' => 'aipilot_agent_get_theme',       'cap' => 'themes_read'],
        // Плагины
        'get_plugins'     => ['func' => 'aipilot_agent_get_plugins',     'cap' => 'plugins_read'],
        // Опции
        'get_options'     => ['func' => 'aipilot_agent_get_options',     'cap' => 'options_read'],
        'update_options'  => ['func' => 'aipilot_agent_update_options',  'cap' => 'options_write'],
        // Поиск
        'search'          => ['func' => 'aipilot_agent_search',          'cap' => 'posts_read'],
    ];
}

/**
 * Универсальный эндпоинт агента.
 *
 * @param WP_REST_Request $request
 * @return array|WP_Error
 */
function aipilot_agent_execute($request) {
    $action = $request->get_param('action');
    $params = $request->get_param('params') ?: [];

    if (empty($action)) {
        return new WP_Error('missing_action', 'Parameter "action" is required', ['status' => 400]);
    }

    $action_map = aipilot_get_agent_action_map();

    if (!isset($action_map[$action])) {
        return new WP_Error('unknown_action', "Unknown action: {$action}", ['status' => 400]);
    }

    $mapping = $action_map[$action];

    // Проверка прав
    if (!aipilot_can($mapping['cap'])) {
        return new WP_Error('capability_denied', "Capability '{$mapping['cap']}' required", ['status' => 403]);
    }

    // Простой запрос через WP_Query (get_posts)
    if ($action === 'get_posts') {
        $args = [
            'post_type'      => 'post',
            'post_status'    => !empty($params['status']) ? $params['status'] : ['publish', 'draft', 'pending'],
            'posts_per_page' => !empty($params['per_page']) ? min((int) $params['per_page'], 100) : 20,
            'paged'          => !empty($params['page']) ? (int) $params['page'] : 1,
            'orderby'        => !empty($params['orderby']) ? $params['orderby'] : 'date',
            'order'          => !empty($params['order']) ? $params['order'] : 'DESC',
        ];
        if (!empty($params['search'])) {
            $args['s'] = sanitize_text_field($params['search']);
        }
        if (!empty($params['category'])) {
            $args['cat'] = (int) $params['category'];
        }
        if (!empty($params['tag'])) {
            $args['tag'] = sanitize_text_field($params['tag']);
        }

        $query  = new WP_Query($args);
        $result = [
            'items'        => array_map('aipilot_format_structure_post', $query->posts),
            'total'        => $query->found_posts,
            'total_pages'  => $query->max_num_pages,
            'current_page' => $args['paged'],
        ];

        do_action('aipilot_agent_executed', $action, $args, $result);
        return $result;
    }

    // Вызов специализированной функции
    $func = $mapping['func'];
    $result = $func($params);

    do_action('aipilot_agent_executed', $action, $params, $result);
    return $result;
}

// Специализированные обработчики для /agent

/**
 * Получить один пост (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_get_post($params) {
    $id = !empty($params['id']) ? (int) $params['id'] : 0;
    $post = get_post($id);
    if (!$post || $post->post_type !== 'post') {
        return ['error' => 'Post not found'];
    }
    return aipilot_format_structure_post($post);
}

/**
 * Создать пост (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_create_post($params) {
    $post_data = [
        'post_title'   => sanitize_text_field($params['title'] ?? ''),
        'post_content' => wp_kses_post($params['content'] ?? ''),
        'post_status'  => $params['status'] ?? 'draft',
        'post_type'    => 'post',
        'post_excerpt' => sanitize_text_field($params['excerpt'] ?? ''),
    ];

    if (!empty($params['slug'])) {
        $post_data['post_name'] = sanitize_title($params['slug']);
    }

    $post_id = wp_insert_post($post_data, true);
    if (is_wp_error($post_id)) {
        return ['error' => $post_id->get_error_message()];
    }

    $term_result = aipilot_apply_post_terms($post_id, $params, false);

    if (!empty($params['featured_media'])) {
        update_post_meta($post_id, '_thumbnail_id', (int) $params['featured_media']);
    }

    $post = get_post($post_id);
    $result = [
        'success'      => true,
        'id'           => $post_id,
        'post_id'      => $post_id,
        'status'       => get_post_status($post_id),
        'post'         => aipilot_format_structure_post($post),
        'category_ids' => $term_result['category_ids'],
        'tag_names'    => $term_result['tag_names'],
        'tag_ids'      => $term_result['tag_ids'],
    ];
    if (!empty($term_result['errors'])) {
        $result['term_warnings'] = $term_result['errors'];
    }

    return $result;
}

/**
 * Обновить пост (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_update_post($params) {
    $id = !empty($params['id']) ? (int) $params['id'] : 0;
    $post = get_post($id);
    if (!$post || $post->post_type !== 'post') {
        return ['error' => 'Post not found'];
    }

    $post_data = ['ID' => $id];
    foreach (['title' => 'post_title', 'content' => 'post_content', 'status' => 'post_status', 'excerpt' => 'post_excerpt', 'slug' => 'post_name'] as $param => $field) {
        if (isset($params[$param])) {
            $post_data[$field] = $param === 'content' ? wp_kses_post($params[$param]) : sanitize_text_field($params[$param]);
        }
    }

    $result = wp_update_post($post_data, true);
    if (is_wp_error($result)) {
        return ['error' => $result->get_error_message()];
    }

    $term_result = aipilot_apply_post_terms($id, $params, true);

    $response = [
        'success'      => true,
        'id'           => $id,
        'post_id'      => $id,
        'status'       => get_post_status($id),
        'post'         => aipilot_format_structure_post(get_post($id)),
        'category_ids' => $term_result['category_ids'],
        'tag_names'    => $term_result['tag_names'],
        'tag_ids'      => $term_result['tag_ids'],
    ];
    if (!empty($term_result['errors'])) {
        $response['term_warnings'] = $term_result['errors'];
    }

    return $response;
}

/**
 * Удалить пост (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_delete_post($params) {
    $id = !empty($params['id']) ? (int) $params['id'] : 0;
    $force = !empty($params['force']);

    $deleted = wp_delete_post($id, $force);
    if (!$deleted) {
        return ['error' => 'Failed to delete post'];
    }

    return ['success' => true, 'deleted_id' => $id];
}

/**
 * Получить страницы (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_get_pages($params) {
    $args = [
        'post_type'      => 'page',
        'post_status'    => ['publish', 'draft'],
        'posts_per_page' => !empty($params['per_page']) ? min((int) $params['per_page'], 100) : 100,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    ];

    $pages = get_posts($args);
    return ['items' => array_map('aipilot_format_structure_page', $pages)];
}

/**
 * Создать страницу (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_create_page($params) {
    $post_id = wp_insert_post([
        'post_title'   => sanitize_text_field($params['title'] ?? ''),
        'post_content' => wp_kses_post($params['content'] ?? ''),
        'post_status'  => $params['status'] ?? 'draft',
        'post_type'    => 'page',
        'post_parent'  => !empty($params['parent']) ? (int) $params['parent'] : 0,
        'menu_order'   => !empty($params['menu_order']) ? (int) $params['menu_order'] : 0,
    ], true);

    if (is_wp_error($post_id)) {
        return ['error' => $post_id->get_error_message()];
    }

    return ['success' => true, 'id' => $post_id];
}

/**
 * Обновить страницу (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_update_page($params) {
    $id = !empty($params['id']) ? (int) $params['id'] : 0;
    $post = get_post($id);
    if (!$post || $post->post_type !== 'page') {
        return ['error' => 'Page not found'];
    }

    $post_data = ['ID' => $id];
    foreach (['title' => 'post_title', 'content' => 'post_content', 'status' => 'post_status', 'slug' => 'post_name'] as $param => $field) {
        if (isset($params[$param])) {
            $post_data[$field] = $param === 'content' ? wp_kses_post($params[$param]) : sanitize_text_field($params[$param]);
        }
    }
    if (isset($params['parent'])) {
        $post_data['post_parent'] = (int) $params['parent'];
    }
    if (isset($params['menu_order'])) {
        $post_data['menu_order'] = (int) $params['menu_order'];
    }

    $result = wp_update_post($post_data, true);
    if (is_wp_error($result)) {
        return ['error' => $result->get_error_message()];
    }

    return ['success' => true, 'id' => $id];
}

/**
 * Удалить страницу (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_delete_page($params) {
    $id = !empty($params['id']) ? (int) $params['id'] : 0;
    $deleted = wp_delete_post($id, true);
    if (!$deleted) {
        return ['error' => 'Failed to delete page'];
    }
    return ['success' => true, 'deleted_id' => $id];
}

/**
 * Получить категории (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_get_categories($params) {
    $categories = get_categories(['hide_empty' => false]);
    return [
        'items' => array_map(function ($c) {
            return [
                'id'   => $c->term_id,
                'name' => $c->name,
                'slug' => $c->slug,
                'count' => $c->count,
            ];
        }, $categories),
    ];
}

/**
 * Создать категорию (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_create_category($params) {
    $result = wp_insert_term(
        sanitize_text_field($params['name'] ?? ''),
        'category',
        [
            'slug'        => sanitize_title($params['slug'] ?? ''),
            'description' => sanitize_text_field($params['description'] ?? ''),
            'parent'      => !empty($params['parent']) ? (int) $params['parent'] : 0,
        ]
    );

    if (is_wp_error($result)) {
        return ['error' => $result->get_error_message()];
    }

    return ['success' => true, 'id' => $result['term_id'], 'name' => $params['name']];
}

/**
 * Получить теги (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_get_tags($params) {
    $tags = get_tags(['hide_empty' => false]);
    return [
        'items' => array_map(function ($t) {
            return ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count];
        }, $tags),
    ];
}

/**
 * Создать тег (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_create_tag($params) {
    $result = wp_insert_term(
        sanitize_text_field($params['name'] ?? ''),
        'post_tag',
        [
            'slug'        => sanitize_title($params['slug'] ?? ''),
            'description' => sanitize_text_field($params['description'] ?? ''),
        ]
    );

    if (is_wp_error($result)) {
        return ['error' => $result->get_error_message()];
    }

    return ['success' => true, 'id' => $result['term_id']];
}

/**
 * Получить меню (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_get_menus($params) {
    $menus = wp_get_nav_menus();
    $items = [];
    foreach ($menus as $menu) {
        $menu_items = wp_get_nav_menu_items($menu->term_id);
        $items[] = [
            'id'    => $menu->term_id,
            'name'  => $menu->name,
            'count' => $menu->count,
        ];
    }
    return ['items' => $items];
}

/**
 * Получить тему (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_get_theme($params) {
    $theme = wp_get_theme();
    return [
        'name'        => $theme->get('Name'),
        'version'     => $theme->get('Version'),
        'author'      => $theme->get('Author'),
        'description' => $theme->get('Description'),
        'stylesheet'  => $theme->get_stylesheet(),
        'screenshot'  => $theme->get_screenshot(),
    ];
}

/**
 * Получить плагины (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_get_plugins($params) {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all_plugins = get_plugins();
    $active = get_option('active_plugins', []);

    $items = [];
    foreach ($all_plugins as $file => $data) {
        $items[] = [
            'file'    => $file,
            'name'    => $data['Name'],
            'version' => $data['Version'],
            'active'  => in_array($file, $active),
        ];
    }

    return ['items' => $items, 'active_count' => count($active)];
}

/**
 * Получить опции (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_get_options($params) {
    $keys = $params['keys'] ?? [];

    if (!is_array($keys) && is_string($keys)) {
        $keys = array_map('trim', explode(',', $keys));
    }

    if (empty($keys)) {
        // Ключевые опции по умолчанию
        $keys = ['blogname', 'blogdescription', 'siteurl', 'home', 'admin_email',
            'timezone_string', 'date_format', 'time_format', 'posts_per_page',
            'active_plugins', 'template', 'stylesheet', 'WPLANG', 'users_can_register'];
    }

    $result = [];
    foreach ($keys as $key) {
        $result[$key] = get_option(sanitize_text_field($key));
    }

    return ['options' => $result];
}

/**
 * Обновить опции (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_update_options($params) {
    $options = $params['options'] ?? [];
    if (empty($options)) {
        return ['error' => 'No options provided'];
    }

    foreach ($options as $key => $value) {
        update_option(sanitize_text_field($key), $value);
    }

    return ['success' => true];
}

/**
 * Поиск по сайту (для /agent).
 *
 * @param array $params
 * @return array
 */
function aipilot_agent_search($params) {
    $search = sanitize_text_field($params['search'] ?? '');
    $type   = $params['type'] ?? 'post';

    if (empty($search)) {
        return ['error' => 'Search query is required'];
    }

    $args = [
        's'              => $search,
        'posts_per_page' => !empty($params['per_page']) ? min((int) $params['per_page'], 50) : 20,
        'post_type'      => in_array($type, ['post', 'page', 'any']) ? $type : 'post',
        'post_status'    => 'any',
    ];

    $query = new WP_Query($args);
    return [
        'items' => array_map('aipilot_format_structure_post', $query->posts),
        'total' => $query->found_posts,
    ];
}

// ═══════════════════════════════════════════════════════════════════
//  4. HUMAN-IN-THE-LOOP: ПРЕДЛОЖЕНИЕ → ПРЕВЬЮ → УТВЕРЖДЕНИЕ → ИСПОЛНЕНИЕ
// ═══════════════════════════════════════════════════════════════════

/**
 * Создать предложение
 */
if (!function_exists('aipilot_agent_propose')) {
    function aipilot_agent_propose($request) {
    $action  = $request->get_param('action');
    $params  = $request->get_param('params') ?: [];
    $summary = $request->get_param('summary') ?: '';

    if (empty($action)) {
        return new WP_Error('missing_action', 'Parameter "action" is required', ['status' => 400]);
    }
    if (empty($summary)) {
        return new WP_Error('missing_summary', 'Parameter "summary" is required (describe what will happen)', ['status' => 400]);
    }

    $proposals = aipilot_get_proposals('all');
    $id = count($proposals) > 0 ? max(array_column($proposals, 'id')) + 1 : 1;

    // Генерируем детальное превью
    $preview = aipilot_generate_proposal_preview($action, $params);

    $proposal = [
        'id'          => $id,
        'action'      => sanitize_text_field($action),
        'params'      => $params,                     // Параметры для исполнения
        'summary'     => sanitize_text_field($summary), // Короткое описание от агента
        'preview'     => $preview,                      // Детальное превью для клиента
        'diff'        => $preview['diff'] ?? null,      // Что меняется (old → new)
        'status'      => 'pending',
        'created_at'  => current_time('c'),
        'executed_at' => null,
        'result'      => null,
    ];

    $proposals[] = $proposal;
    aipilot_save_proposals($proposals);

    do_action('aipilot_proposal_created', $proposal);

    return [
        'proposal' => $proposal,
    ];
    }
}

/**
 * Сгенерировать детальное превью для клиента.
 * Возвращает массив с полями, которые клиент видит в чате.
 */
/**
 * Сгенерировать детальное превью для клиента.
 *
 * @param string $action
 * @param array  $params
 * @return array
 */
function aipilot_generate_proposal_preview($action, $params) {
    $preview = [
        'emoji'    => '🔄',
        'title'    => $action,
        'summary'  => '',
        'details'  => [],
        'content'  => null,   // Полный контент (для create/update)
        'old'      => null,   // Старое значение (для update)
        'new'      => null,   // Новое значение (для create/update)
        'diff'     => null,   // Изменения
    ];

    switch ($action) {
        case 'create_post':
            $preview['emoji'] = '📝';
            $preview['title'] = 'Новый пост';
            $preview['summary'] = $params['title'] ?? 'Untitled';
            $preview['content'] = wp_kses_post($params['content'] ?? '');
            $preview['new'] = [
                'title'      => $params['title'] ?? '',
                'content'    => $params['content'] ?? '',
                'excerpt'    => $params['excerpt'] ?? '',
                'status'     => $params['status'] ?? 'draft',
                'categories' => $params['categories'] ?? [],
                'tags'       => $params['tags'] ?? [],
            ];
            break;

        case 'update_post':
            $id = (int) ($params['id'] ?? 0);
            $post = get_post($id);
            $old_title = $post ? $post->post_title : 'Unknown';
            $new_title = $params['title'] ?? $old_title;

            $preview['emoji'] = '✏️';
            $preview['title'] = "Обновление поста #{$id}";
            $preview['summary'] = "«{$old_title}» → «{$new_title}»";
            $preview['old'] = $post ? [
                'title'   => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $post->post_excerpt,
                'status'  => $post->post_status,
            ] : null;
            $preview['new'] = [
                'title'   => $new_title,
                'content' => $params['content'] ?? ($post ? $post->post_content : ''),
                'excerpt' => $params['excerpt'] ?? ($post ? $post->post_excerpt : ''),
                'status'  => $params['status'] ?? ($post ? $post->post_status : 'draft'),
            ];
            break;

        case 'delete_post':
            $id = (int) ($params['id'] ?? 0);
            $t = get_the_title($id) ?: "Post #{$id}";
            $preview['emoji'] = '🗑️';
            $preview['title'] = "Удаление поста #{$id}";
            $preview['summary'] = "Будет удалён: «{$t}»";
            $preview['old'] = ['id' => $id, 'title' => $t];
            $preview['new'] = null;
            $preview['diff'] = 'delete';
            break;

        case 'create_page':
            $preview['emoji'] = '📄';
            $preview['title'] = 'Новая страница';
            $preview['summary'] = $params['title'] ?? '';
            $preview['new'] = [
                'title'     => $params['title'] ?? '',
                'content'   => $params['content'] ?? '',
                'status'    => $params['status'] ?? 'draft',
                'parent'    => $params['parent'] ?? 0,
            ];
            break;

        case 'update_page':
            $id = (int) ($params['id'] ?? 0);
            $page = get_post($id);
            $old_name = $page ? $page->post_title : "Page #{$id}";
            $preview['emoji'] = '✏️';
            $preview['title'] = "Обновление страницы #{$id}";
            $preview['summary'] = "«{$old_name}»";
            $preview['old'] = $page ? [
                'title'   => $page->post_title,
                'content' => $page->post_content,
                'status'  => $page->post_status,
            ] : null;
            $preview['new'] = [
                'title'   => $params['title'] ?? $old_name,
                'content' => $params['content'] ?? ($page ? $page->post_content : ''),
                'status'  => $params['status'] ?? ($page ? $page->post_status : 'draft'),
            ];
            break;

        case 'delete_page':
            $id = (int) ($params['id'] ?? 0);
            $preview['emoji'] = '🗑️';
            $preview['title'] = "Удаление страницы #{$id}";
            $preview['summary'] = "«" . (get_the_title($id) ?: "Page #{$id}") . "»";
            $preview['diff'] = 'delete';
            break;

        case 'create_category':
            $preview['emoji'] = '🏷️';
            $preview['title'] = 'Новая категория';
            $preview['summary'] = $params['name'] ?? '';
            $preview['new'] = ['name' => $params['name'] ?? '', 'slug' => $params['slug'] ?? ''];
            break;

        case 'switch_theme':
            $new_theme = wp_get_theme($params['stylesheet'] ?? '');
            $old_theme = wp_get_theme();
            $preview['emoji'] = '🎨';
            $preview['title'] = 'Смена темы';
            $preview['summary'] = "{$old_theme->get('Name')} → {$new_theme->get('Name')}";
            $preview['old'] = ['name' => $old_theme->get('Name'), 'author' => $old_theme->get('Author')];
            $preview['new'] = ['name' => $new_theme->get('Name'), 'author' => $new_theme->get('Author')];
            $preview['diff'] = 'theme_switch';
            break;

        case 'install_plugin':
            $preview['emoji'] = '📦';
            $preview['title'] = 'Установка плагина';
            $preview['summary'] = $params['slug'] ?? '';
            break;

        case 'update_options':
            $opts = $params['options'] ?? [];
            $changes = [];
            foreach ($opts as $key => $value) {
                $old = get_option($key);
                $changes[] = [
                    'option' => $key,
                    'old'    => $old,
                    'new'    => $value,
                ];
            }
            $preview['emoji'] = '⚙️';
            $preview['title'] = 'Изменение настроек';
            $preview['summary'] = count($changes) . ' параметров';
            $preview['details'] = $changes;
            $preview['diff'] = 'options';
            break;

        default:
            $preview['summary'] = $action . ': ' . wp_json_encode($params);
            break;
    }

    return $preview;
}

/**
 * Получить все предложения
 */
function aipilot_get_proposals($status = 'pending') {
    $stored = get_option('aipilot_proposals', []);
    if (!is_array($stored)) {
        $stored = [];
    }

    if ($status === 'all') {
        return $stored;
    }

    return array_values(array_filter($stored, function ($p) use ($status) {
        return ($p['status'] ?? 'pending') === $status;
    }));
}

/**
 * Сохранить предложения
 */
function aipilot_save_proposals($proposals) {
    $proposals = array_slice($proposals, -100);
    update_option('aipilot_proposals', $proposals);
}

// Список ожидающих предложений
if (!function_exists('aipilot_agent_pending')) {
    /**
     * Список ожидающих предложений (human-in-the-loop).
     *
     * @return array
     */
    function aipilot_agent_pending() {
        return [
            'pending'  => aipilot_get_proposals('pending'),
            'approved' => aipilot_get_proposals('approved'),
            'rejected' => aipilot_get_proposals('rejected'),
            'total_pending' => count(aipilot_get_proposals('pending')),
        ];
    }
}

/**
 * Получить детали одного предложения (для чата)
 */
function aipilot_agent_get_proposal($request) {
    $id = (int) $request->get_param('id');
    $proposals = aipilot_get_proposals('all');

    foreach ($proposals as $p) {
        if ($p['id'] === $id) {
            return ['proposal' => $p];
        }
    }

    return new WP_Error('not_found', "Proposal #{$id} not found", ['status' => 404]);
}

// Утвердить предложение
if (!function_exists('aipilot_agent_approve')) {
    /**
     * Утвердить предложение.
     *
     * @param WP_REST_Request $request
     * @return array|WP_Error
     */
    function aipilot_agent_approve($request) {
        $id = (int) $request->get_param('id');
        $proposals = aipilot_get_proposals('all');

        $found = false;
        foreach ($proposals as &$proposal) {
            if ($proposal['id'] === $id) {
                if ($proposal['status'] !== 'pending') {
                    return new WP_Error('invalid_status', "Proposal #{$id} is already {$proposal['status']}", ['status' => 400]);
                }

                $proposal['status'] = 'approved';
                $proposal['executed_at'] = current_time('c');
                $found = true;

                // Исполняем действие
                $execute_result = aipilot_execute_proposal($proposal);
                $proposal['result'] = $execute_result;
                break;
            }
        }

        if (!$found) {
            return new WP_Error('not_found', "Proposal #{$id} not found", ['status' => 404]);
        }

        aipilot_save_proposals($proposals);
        do_action('aipilot_proposal_approved', $proposal);

        return [
            'approved' => true,
            'proposal' => $proposal,
        ];
    }
}

// Отклонить предложение
if (!function_exists('aipilot_agent_reject')) {
    /**
     * Отклонить предложение.
     *
     * @param WP_REST_Request $request
     * @return array|WP_Error
     */
    function aipilot_agent_reject($request) {
        $id = (int) $request->get_param('id');
        $reason = $request->get_param('reason') ? sanitize_text_field($request->get_param('reason')) : 'Rejected by user';

        $proposals = aipilot_get_proposals('all');
        $found = false;
        foreach ($proposals as &$proposal) {
            if ($proposal['id'] === $id) {
                if ($proposal['status'] !== 'pending') {
                    return new WP_Error('invalid_status', "Proposal #{$id} is already {$proposal['status']}", ['status' => 400]);
                }
                $proposal['status'] = 'rejected';
                $proposal['reason'] = $reason;
                $found = true;
                break;
            }
        }

        if (!$found) {
            return new WP_Error('not_found', "Proposal #{$id} not found", ['status' => 404]);
        }

        aipilot_save_proposals($proposals);
        do_action('aipilot_proposal_rejected', $proposal);

        return ['rejected' => true, 'reason' => $reason];
    }
}

/**
 * Исполнить утверждённое предложение.
 * Вызывает нужную функцию напрямую, без WP_REST_Request.
 */
/**
 * Исполнить утверждённое предложение.
 *
 * @param array $proposal
 * @return array
 */
function aipilot_execute_proposal($proposal) {
    $action = $proposal['action'];
    $params = $proposal['params'];

    $action_map = aipilot_get_agent_action_map();

    if (!isset($action_map[$action])) {
        return ['error' => "Unknown action: {$action}"];
    }

    $func = $action_map[$action]['func'];
    $result = call_user_func($func, $params);

    if (is_array($result) && isset($result['error'])) {
        return $result;
    }

    return $result;
}

// ═══════════════════════════════════════════════════════════════════
//  5. КОНФИГУРАЦИЯ: ГАТЕЙВЕЙ И РЕГИСТРАЦИЯ
// ═══════════════════════════════════════════════════════════════════

if (!function_exists('aipilot_get_site_id')) {
    /**
     * Уникальный ID сайта в системе AI Pilot
     */
    function aipilot_get_site_id() {
        $site_id = get_option('aipilot_site_id', '');
        if (empty($site_id)) {
            $site_id = 'wp_' . substr(wp_hash(home_url() . get_bloginfo('name')), 0, 12);
            update_option('aipilot_site_id', $site_id);
        }
        return $site_id;
    }
}

/**
 * URL gateway (pilotsite.ru)
 */
function aipilot_get_gateway_url() {
    return get_option('aipilot_gateway_url', 'https://pilotsite.ru');
}

/**
 * Получить конфигурацию для агента (что агент должен знать о сайте)
 */
function aipilot_get_agent_config() {
    return [
        'site_id'   => aipilot_get_site_id(),
        'site_name' => get_bloginfo('name'),
        'site_url'  => get_bloginfo('url'),
        'api_url'   => rest_url('aipilot/v1'),
        'plugin_ver' => AI_PILOT_VERSION,
    ];
}
