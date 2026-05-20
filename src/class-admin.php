<?php
/**
 * AI Pilot – Admin Page (Settings API)
 *
 * @package AI_Pilot
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin settings page using the WordPress Settings API.
 */
class AIPILOT_Admin {

    /**
     * Hook into WordPress admin.
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * Register the settings page under Settings.
     */
    public static function add_menu_page() {
        add_options_page(
            'AI Pilot',
            'AI Pilot',
            'manage_options',
            'ai-pilot-settings',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Register settings using the Settings API.
     */
    public static function register_settings() {
        // Token actions processed early
        self::handle_token_actions();
        self::handle_connect_actions();
        self::handle_soul_actions();
        self::handle_disconnect();
        self::handle_connect_callback();
    }

    /**
     * Enqueue admin JS for the settings page.
     */
    public static function enqueue_assets($hook) {
        if ('settings_page_ai-pilot-settings' !== $hook) {
            return;
        }
        wp_enqueue_script(
            'aipilot-admin',
            plugin_dir_url(dirname(__FILE__)) . 'admin/admin.js',
            [],
            AI_PILOT_VERSION,
            true
        );
        wp_localize_script('aipilot-admin', 'AIPilotAdmin', [
            'restUrl'   => rest_url('aipilot/v1/agent/connect-code'),
            'siteUrl'   => get_site_url(),
            'token'     => get_option('aipilot_last_token', ''),
            'connected' => (bool) get_option('aipilot_connected_site', ''),
            'connectUrl' => 'https://chat.pilotsite.ru/connect',
        ]);
    }

    // ─── HANDLERS ────────────────────────────────────────────────

    private static function handle_token_actions() {
        if (!isset($_POST['aipilot_generate']) || !check_admin_referer('aipilot_settings')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $token      = wp_generate_password(64, false);
        $token_hash = wp_hash($token);

        update_option('aipilot_api_token_hash', $token_hash);
        update_option('aipilot_last_token', $token);

        add_settings_error('aipilot', 'token_generated',
            sprintf(
                /* translators: %s: the new API token */
                __('New token: <code>%s</code> — copy it now!', 'ai-pilot'),
                esc_html($token)
            ),
            'warning'
        );
    }

    private static function handle_connect_actions() {
        if (!isset($_POST['aipilot_save_gateway']) || !check_admin_referer('aipilot_settings')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!empty($_POST['aipilot_gateway_url'])) {
            update_option('aipilot_gateway_url', esc_url_raw(wp_unslash($_POST['aipilot_gateway_url'])));
            add_settings_error('aipilot', 'gateway_saved', __('Gateway URL saved.', 'ai-pilot'), 'success');
        }
    }

    private static function handle_soul_actions() {
        if (!isset($_POST['aipilot_save_soul']) || !check_admin_referer('aipilot_settings')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $soul = [
            'tone_of_voice' => sanitize_textarea_field(wp_unslash($_POST['aipilot_tone_of_voice'] ?? '')),
            'rules'         => array_filter(array_map('sanitize_text_field', explode("\n", wp_unslash($_POST['aipilot_rules'] ?? '')))),
            'description'   => sanitize_text_field(wp_unslash($_POST['aipilot_description'] ?? '')),
            'updated_at'    => current_time('mysql'),
        ];
        update_option('aipilot_agent_soul', wp_json_encode($soul));
        add_settings_error('aipilot', 'soul_saved', __('✅ Tone of Voice saved.', 'ai-pilot'), 'success');
    }

    private static function handle_disconnect() {
        if (!isset($_POST['aipilot_disconnect']) || !check_admin_referer('aipilot_connect')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        delete_option('aipilot_connected_site');
        delete_option('aipilot_connected_at');
        add_settings_error('aipilot', 'disconnected', __('🔌 Site disconnected from AI Pilot.', 'ai-pilot'), 'info');
    }

    private static function handle_connect_callback() {
        if (!isset($_GET['connected']) || '1' !== $_GET['connected']) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        update_option('aipilot_connected_site', get_site_url());
        update_option('aipilot_connected_at', current_time('mysql'));
        add_settings_error('aipilot', 'connected', __('✅ Site connected to AI Pilot!', 'ai-pilot'), 'success');
    }

    // ─── RENDER ──────────────────────────────────────────────────

    /**
     * Render the full settings page.
     */
    public static function render_page() {
        $soul          = aipilot_agent_get_soul_data();
        $connected_site = get_option('aipilot_connected_site', '');
        $connected_at   = get_option('aipilot_connected_at', '');
        $caps           = aipilot_get_capabilities();
        $token_hash     = get_option('aipilot_api_token_hash', '—');
        $gateway_url    = get_option('aipilot_gateway_url', 'https://pilotsite.ru');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('AI Pilot – Remote Site API', 'ai-pilot'); ?></h1>
            <p><?php esc_html_e('REST API для удалённого управления сайтом через AI Pilot.', 'ai-pilot'); ?></p>

            <?php settings_errors('aipilot'); ?>
            <hr>

            <!-- ═══ API TOKEN ═══ -->
            <h2><?php esc_html_e('API Token', 'ai-pilot'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('aipilot_settings'); ?>
                <p>
                    <button type="submit" name="aipilot_generate" class="button button-primary">
                        <?php esc_html_e('Generate New Token', 'ai-pilot'); ?>
                    </button>
                </p>
            </form>
            <p>
                <?php esc_html_e('Current token hash:', 'ai-pilot'); ?>
                <code><?php echo esc_html(substr($token_hash, 0, 20)); ?>…</code>
            </p>
            <hr>

            <!-- ═══ CONNECT ═══ -->
            <h2><?php esc_html_e('🔗 Подключение к AI Pilot', 'ai-pilot'); ?></h2>
            <p><?php esc_html_e('Подключите этот сайт к вашему аккаунту AI Pilot для управления через чат.', 'ai-pilot'); ?></p>

            <?php if ($connected_site): ?>
            <div class="notice notice-success" style="border-left-color:#46b450;">
                <p>✅ <?php esc_html_e('Сайт подключён к', 'ai-pilot'); ?> <strong><?php echo esc_html($connected_site); ?></strong></p>
                <p style="font-size:0.85rem;color:#666;">
                    <?php esc_html_e('Подключён:', 'ai-pilot'); ?> <?php echo esc_html($connected_at); ?>
                </p>
            </div>
            <?php endif; ?>

            <p>
                <button type="button" class="button button-primary" id="aipilot-connect-btn"
                        style="background:#7837df;border-color:#7837df;color:#fff">
                    <?php echo $connected_site ? '🔄 ' . esc_html__('Переподключить', 'ai-pilot') : '🔗 ' . esc_html__('Подключить к AI Pilot', 'ai-pilot'); ?>
                </button>

                <?php if ($connected_site): ?>
                <form method="post" style="display:inline">
                    <?php wp_nonce_field('aipilot_connect'); ?>
                    <button type="submit" name="aipilot_disconnect" class="button" style="color:#d32f2f">
                        🔌 <?php esc_html_e('Отключить', 'ai-pilot'); ?>
                    </button>
                </form>
                <?php endif; ?>
            </p>
            <hr>

            <!-- ═══ PERMISSIONS ═══ -->
            <h2><?php esc_html_e('Permissions', 'ai-pilot'); ?></h2>
            <?php self::render_capabilities($caps); ?>
            <hr>

            <!-- ═══ TONE OF VOICE ═══ -->
            <h2><?php esc_html_e('🤖 Tone of Voice (для субагента)', 'ai-pilot'); ?></h2>
            <p><?php esc_html_e('Настройте, как AI-помощник будет общаться от имени вашего сайта.', 'ai-pilot'); ?></p>
            <form method="post">
                <?php wp_nonce_field('aipilot_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="tone_of_voice"><?php esc_html_e('Tone of Voice', 'ai-pilot'); ?></label></th>
                        <td>
                            <input type="text" id="tone_of_voice" name="aipilot_tone_of_voice"
                                   value="<?php echo esc_attr($soul['tone_of_voice'] ?? ''); ?>"
                                   class="regular-text" placeholder="<?php esc_attr_e('Дружелюбный и профессиональный', 'ai-pilot'); ?>">
                            <p class="description"><?php esc_html_e('Как AI должен общаться (стиль, тон)', 'ai-pilot'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="description"><?php esc_html_e('Описание сайта', 'ai-pilot'); ?></label></th>
                        <td>
                            <input type="text" id="description" name="aipilot_description"
                                   value="<?php echo esc_attr($soul['description'] ?? ''); ?>"
                                   class="regular-text" placeholder="<?php esc_attr_e('Чем занимается сайт', 'ai-pilot'); ?>">
                            <p class="description"><?php esc_html_e('Короткое описание тематики сайта', 'ai-pilot'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rules"><?php esc_html_e('Правила', 'ai-pilot'); ?></label></th>
                        <td>
                            <textarea id="rules" name="aipilot_rules" rows="5" class="large-text"><?php
                                echo esc_textarea(implode("\n", $soul['rules'] ?? []));
                            ?></textarea>
                            <p class="description"><?php esc_html_e('Каждое правило на новой строке', 'ai-pilot'); ?></p>
                        </td>
                    </tr>
                </table>
                <p><button type="submit" name="aipilot_save_soul" class="button button-primary"><?php esc_html_e('Save Tone of Voice', 'ai-pilot'); ?></button></p>
            </form>
            <hr>

            <!-- ═══ GATEWAY ═══ -->
            <h2><?php esc_html_e('AI Pilot Gateway', 'ai-pilot'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('aipilot_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="gateway_url"><?php esc_html_e('Gateway URL', 'ai-pilot'); ?></label></th>
                        <td>
                            <input type="url" id="gateway_url" name="aipilot_gateway_url"
                                   value="<?php echo esc_url($gateway_url); ?>"
                                   class="regular-text" placeholder="https://pilotsite.ru">
                            <p class="description"><?php esc_html_e('URL сервера AI Pilot (по умолчанию pilotsite.ru)', 'ai-pilot'); ?></p>
                        </td>
                    </tr>
                </table>
                <p><button type="submit" name="aipilot_save_gateway" class="button"><?php esc_html_e('Save Gateway Settings', 'ai-pilot'); ?></button></p>
            </form>
            <hr>

            <!-- ═══ API USAGE ═══ -->
            <h2><?php esc_html_e('API Usage', 'ai-pilot'); ?></h2>
            <p><?php esc_html_e('Make requests with the header:', 'ai-pilot'); ?></p>
            <pre style="background:#f1f1f1;padding:10px;">X-AI-Pilot-Token: your-token-here</pre>
            <p><?php esc_html_e('Base URL:', 'ai-pilot'); ?> <code><?php echo esc_url(rest_url('aipilot/v1')); ?></code></p>
            <?php self::render_endpoint_list(); ?>
        </div>
        <?php
    }

    // ─── RENDER HELPERS ──────────────────────────────────────────

    /**
     * Render the permissions checkboxes.
     */
    private static function render_capabilities($caps) {
        $cap_groups = [
            'Site'       => ['site_info'],
            'Posts'      => ['posts_read', 'posts_create', 'posts_update', 'posts_delete'],
            'Categories' => ['categories_read', 'categories_create'],
            'Tags'       => ['tags_read', 'tags_create', 'tags_update', 'tags_delete'],
            'Pages'      => ['pages_read', 'pages_create', 'pages_update', 'pages_delete'],
            'Users'      => ['users_read'],
            'Plugins'    => ['plugins_read', 'plugins_install', 'plugins_upload', 'plugins_activate', 'plugins_deactivate', 'plugins_update', 'plugins_delete', 'plugins_search'],
            'Menus'      => ['menus_read', 'menus_create', 'menus_update', 'menus_delete'],
            'Themes'     => ['themes_read', 'themes_switch', 'themes_edit'],
            'Options'    => ['options_read', 'options_write'],
            'Agent'      => ['full_access'],
        ];
        $cap_labels = [
            'site_info'         => 'View site info',
            'posts_read'        => 'Read posts',
            'posts_create'      => 'Create posts',
            'posts_update'      => 'Update posts',
            'posts_delete'      => 'Delete posts',
            'categories_read'   => 'Read categories',
            'categories_create' => 'Create categories',
            'tags_read'         => 'Read tags',
            'tags_create'       => 'Create tags',
            'tags_update'       => 'Update tags',
            'tags_delete'       => 'Delete tags',
            'pages_read'        => 'Read pages',
            'pages_create'      => 'Create pages',
            'pages_update'      => 'Update pages',
            'pages_delete'      => 'Delete pages',
            'users_read'        => 'Read users',
            'plugins_read'      => 'Read plugins',
            'plugins_install'   => 'Install plugins',
            'plugins_upload'    => 'Upload plugins',
            'plugins_activate'  => 'Activate plugins',
            'plugins_deactivate' => 'Deactivate plugins',
            'plugins_update'    => 'Update plugins',
            'plugins_delete'    => 'Delete plugins',
            'plugins_search'    => 'Search plugins',
            'menus_read'        => 'Read menus',
            'menus_create'      => 'Create menus',
            'menus_update'      => 'Update menus',
            'menus_delete'      => 'Delete menus',
            'themes_read'       => 'Read themes',
            'themes_switch'     => 'Switch themes',
            'themes_edit'       => 'Edit theme mods',
            'options_read'      => 'Read options',
            'options_write'     => 'Write options',
            'full_access'       => 'Full agent access (all actions)',
        ];
        ?>
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
                        <input type="checkbox" id="cap_<?php echo esc_attr($cap); ?>"
                               name="cap_<?php echo esc_attr($cap); ?>" value="1"
                               <?php checked(!empty($caps[$cap])); ?>>
                        <code><?php echo esc_html($cap); ?></code>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endforeach; ?>
            <p>
                <button type="submit" name="aipilot_save_caps" class="button button-primary">
                    <?php esc_html_e('Save Permissions', 'ai-pilot'); ?>
                </button>
                <button type="submit" name="aipilot_reset_caps" class="button"
                        onclick="return confirm('<?php esc_attr_e('Reset all permissions to defaults?', 'ai-pilot'); ?>')">
                    <?php esc_html_e('Reset to Defaults', 'ai-pilot'); ?>
                </button>
            </p>
        </form>
        <?php
    }

    /**
     * Render endpoint reference list.
     */
    private static function render_endpoint_list() {
        ?>
        <h3><?php esc_html_e('Endpoints', 'ai-pilot'); ?></h3>
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
        </ul>
        <?php
    }
}
