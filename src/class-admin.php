<?php
/**
 * AI Pilot – modern WordPress admin interface.
 *
 * @package AI_Pilot
 * @since   2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AIPILOT_Admin {

    const MENU_SLUG = 'ai-pilot';
    private static $initialized = false;

    public static function init() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        add_action('admin_menu', [__CLASS__, 'add_menu_pages']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * Add AI Pilot as a top-level WordPress admin section.
     */
    public static function add_menu_pages() {
        add_menu_page(
            __('AI Pilot', 'ai-pilot'),
            __('AI Pilot', 'ai-pilot'),
            'manage_options',
            self::MENU_SLUG,
            [__CLASS__, 'render_dashboard'],
            'dashicons-superhero-alt',
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('AI Pilot — Подключение', 'ai-pilot'),
            __('Подключение', 'ai-pilot'),
            'manage_options',
            self::MENU_SLUG,
            [__CLASS__, 'render_dashboard']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('AI Pilot — Ассистент', 'ai-pilot'),
            __('Ассистент', 'ai-pilot'),
            'manage_options',
            'ai-pilot-assistant',
            [__CLASS__, 'render_assistant']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('AI Pilot — Доступы', 'ai-pilot'),
            __('Доступы', 'ai-pilot'),
            'manage_options',
            'ai-pilot-permissions',
            [__CLASS__, 'render_permissions']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('AI Pilot — Система', 'ai-pilot'),
            __('Система', 'ai-pilot'),
            'manage_options',
            'ai-pilot-system',
            [__CLASS__, 'render_system']
        );
    }

    /**
     * Process settings forms before output.
     */
    public static function register_settings() {
        self::redirect_legacy_settings_url();
        self::handle_gateway_actions();
        self::handle_caps_actions();
        self::handle_soul_actions();
        self::handle_disconnect();
        self::handle_connect_callback();
    }

    /**
     * Load assets only on AI Pilot admin pages.
     */
    public static function enqueue_assets($hook) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (strpos($page, 'ai-pilot') !== 0) {
            return;
        }

        wp_enqueue_style(
            'aipilot-admin',
            AI_PILOT_PLUGIN_URL . 'admin/admin.css',
            [],
            AI_PILOT_VERSION
        );

        wp_enqueue_script(
            'aipilot-admin',
            AI_PILOT_PLUGIN_URL . 'admin/admin.js',
            [],
            AI_PILOT_VERSION,
            true
        );

        wp_localize_script('aipilot-admin', 'AIPilotAdmin', [
            'connectCodeUrl' => rest_url('aipilot/v1/agent/connect-code'),
            'statusUrl'      => rest_url('aipilot/v1/agent/connection-status'),
            'siteUrl'        => get_site_url(),
            'siteName'       => get_bloginfo('name'),
            'restNonce'      => wp_create_nonce('wp_rest'),
            'connected'      => self::is_connected(),
            'connectedAt'    => get_option('aipilot_connected_at', ''),
            'returnUrl'      => admin_url('admin.php?page=' . self::MENU_SLUG . '&connected=1'),
            'chatUrl'        => 'https://chat.pilotsite.ru',
            'strings'        => [
                'preparing'       => __('Готовим безопасное подключение…', 'ai-pilot'),
                'waiting'         => __('Завершите вход в открывшемся окне', 'ai-pilot'),
                'connected'       => __('Сайт подключён. Всё готово!', 'ai-pilot'),
                'popupBlocked'    => __('Браузер заблокировал окно авторизации. Откройте его вручную.', 'ai-pilot'),
                'expired'         => __('Время подключения истекло. Нажмите кнопку ещё раз.', 'ai-pilot'),
                'genericError'    => __('Не удалось подключить сайт. Попробуйте ещё раз.', 'ai-pilot'),
                'reopen'          => __('Открыть окно авторизации', 'ai-pilot'),
                'seconds'         => __('сек.', 'ai-pilot'),
            ],
        ]);
    }

    // ─── Form handlers ───────────────────────────────────────────

    private static function handle_gateway_actions() {
        if (!isset($_POST['aipilot_save_gateway']) || !check_admin_referer('aipilot_settings')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $url = isset($_POST['aipilot_gateway_url'])
            ? esc_url_raw(wp_unslash($_POST['aipilot_gateway_url']))
            : '';

        if ($url !== '') {
            update_option('aipilot_gateway_url', $url);
            add_settings_error('aipilot', 'gateway_saved', __('Адрес AI Pilot сохранён.', 'ai-pilot'), 'success');
        }
    }

    private static function handle_caps_actions() {
        if (!isset($_POST['aipilot_save_caps']) && !isset($_POST['aipilot_reset_caps'])) {
            return;
        }
        if (!check_admin_referer('aipilot_settings')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['aipilot_reset_caps'])) {
            delete_option('aipilot_api_capabilities');
            add_settings_error('aipilot', 'caps_reset', __('Доступы возвращены к значениям по умолчанию.', 'ai-pilot'), 'success');
            return;
        }

        if (!isset($_POST['aipilot_save_caps'])) {
            return;
        }

        $caps = [];
        foreach (array_keys(aipilot_get_default_capabilities()) as $cap) {
            $caps[$cap] = !empty($_POST['cap_' . $cap]);
        }
        update_option('aipilot_api_capabilities', $caps);
        add_settings_error('aipilot', 'caps_saved', __('Доступы сохранены.', 'ai-pilot'), 'success');
    }

    private static function handle_soul_actions() {
        if (!isset($_POST['aipilot_save_soul']) || !check_admin_referer('aipilot_settings')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $rules_raw = isset($_POST['aipilot_rules']) ? wp_unslash($_POST['aipilot_rules']) : '';
        $rules = array_values(array_filter(array_map('sanitize_text_field', preg_split('/\R/', $rules_raw))));

        $soul = [
            'tone_of_voice' => sanitize_text_field(wp_unslash($_POST['aipilot_tone_of_voice'] ?? '')),
            'rules'         => $rules,
            'description'   => sanitize_textarea_field(wp_unslash($_POST['aipilot_description'] ?? '')),
            'updated_at'    => current_time('mysql'),
        ];

        update_option('aipilot_agent_soul', wp_json_encode($soul));
        add_settings_error('aipilot', 'soul_saved', __('Настройки ассистента сохранены.', 'ai-pilot'), 'success');
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
        delete_option('aipilot_api_token_hash');
        delete_option('aipilot_last_token');
        delete_option('aipilot_connect_codes');

        add_settings_error('aipilot', 'disconnected', __('Сайт отключён от AI Pilot, ключ доступа отозван.', 'ai-pilot'), 'success');
    }

    /**
     * Backward-compatible callback for older connect pages that redirect to WP.
     */
    private static function handle_connect_callback() {
        if (!isset($_GET['connected']) || '1' !== $_GET['connected'] || !current_user_can('manage_options')) {
            return;
        }

        if (get_option('aipilot_api_token_hash', '') !== '') {
            update_option('aipilot_connected_site', get_site_url());
            if (!get_option('aipilot_connected_at', '')) {
                update_option('aipilot_connected_at', current_time('mysql'));
            }
            add_settings_error('aipilot', 'connected', __('Сайт подключён к AI Pilot.', 'ai-pilot'), 'success');
        }
    }

    private static function redirect_legacy_settings_url() {
        if (!isset($_GET['page']) || 'ai-pilot-settings' !== $_GET['page']) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
            exit;
        }
    }

    // ─── Render shell ────────────────────────────────────────────

    private static function is_connected() {
        return get_option('aipilot_connected_site', '') !== '' && get_option('aipilot_api_token_hash', '') !== '';
    }

    private static function render_header($active) {
        $items = [
            'connection'  => [admin_url('admin.php?page=' . self::MENU_SLUG), __('Подключение', 'ai-pilot')],
            'assistant'   => [admin_url('admin.php?page=ai-pilot-assistant'), __('Ассистент', 'ai-pilot')],
            'permissions' => [admin_url('admin.php?page=ai-pilot-permissions'), __('Доступы', 'ai-pilot')],
            'system'      => [admin_url('admin.php?page=ai-pilot-system'), __('Система', 'ai-pilot')],
        ];
        ?>
        <div class="aipilot-admin">
            <header class="aipilot-header">
                <div class="aipilot-brand">
                    <span class="aipilot-brand__mark" aria-hidden="true">AI</span>
                    <div>
                        <h1>AI Pilot</h1>
                        <p><?php esc_html_e('Управляйте сайтом через диалог с AI', 'ai-pilot'); ?></p>
                    </div>
                </div>
                <span class="aipilot-version">v<?php echo esc_html(AI_PILOT_VERSION); ?></span>
            </header>

            <nav class="aipilot-nav" aria-label="AI Pilot">
                <?php foreach ($items as $key => $item): ?>
                    <a href="<?php echo esc_url($item[0]); ?>" class="<?php echo $active === $key ? 'is-active' : ''; ?>">
                        <?php echo esc_html($item[1]); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php settings_errors('aipilot'); ?>
        <?php
    }

    private static function render_footer() {
        ?>
            <footer class="aipilot-footer">
                <span><?php esc_html_e('AI Pilot работает только после вашего подтверждения действий.', 'ai-pilot'); ?></span>
                <a href="https://chat.pilotsite.ru" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Открыть AI Pilot', 'ai-pilot'); ?></a>
            </footer>
        </div>
        <?php
    }

    public static function render_dashboard() {
        self::render_header('connection');

        $connected = self::is_connected();
        $connected_at = get_option('aipilot_connected_at', '');
        ?>
        <main class="aipilot-main">
            <?php if ($connected): ?>
                <section class="aipilot-hero aipilot-hero--connected" id="aipilot-connected-panel">
                    <div class="aipilot-hero__copy">
                        <span class="aipilot-status-pill"><i></i><?php esc_html_e('Подключено', 'ai-pilot'); ?></span>
                        <h2><?php esc_html_e('Сайт готов к работе с AI Pilot', 'ai-pilot'); ?></h2>
                        <p><?php esc_html_e('Теперь можно создавать материалы, анализировать сайт и применять изменения через чат.', 'ai-pilot'); ?></p>
                        <div class="aipilot-hero__actions">
                            <a class="aipilot-button aipilot-button--primary" href="https://chat.pilotsite.ru" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Открыть AI Pilot', 'ai-pilot'); ?>
                                <span aria-hidden="true">↗</span>
                            </a>
                            <button type="button" class="aipilot-button aipilot-button--secondary" id="aipilot-connect-btn">
                                <?php esc_html_e('Переподключить', 'ai-pilot'); ?>
                            </button>
                        </div>
                    </div>
                    <div class="aipilot-connection-summary">
                        <div class="aipilot-summary-icon">✓</div>
                        <dl>
                            <div><dt><?php esc_html_e('Сайт', 'ai-pilot'); ?></dt><dd><?php echo esc_html(parse_url(get_site_url(), PHP_URL_HOST)); ?></dd></div>
                            <div><dt><?php esc_html_e('Подключён', 'ai-pilot'); ?></dt><dd><?php echo esc_html($connected_at ?: __('только что', 'ai-pilot')); ?></dd></div>
                            <div><dt><?php esc_html_e('Версия плагина', 'ai-pilot'); ?></dt><dd><?php echo esc_html(AI_PILOT_VERSION); ?></dd></div>
                        </dl>
                    </div>
                </section>
            <?php else: ?>
                <section class="aipilot-hero" id="aipilot-connect-panel">
                    <div class="aipilot-hero__copy">
                        <span class="aipilot-eyebrow"><?php esc_html_e('Подключение займёт меньше минуты', 'ai-pilot'); ?></span>
                        <h2><?php esc_html_e('Подключите сайт к AI Pilot в один клик', 'ai-pilot'); ?></h2>
                        <p><?php esc_html_e('Нажмите кнопку, войдите в аккаунт — окно закроется автоматически, а сайт появится в AI Pilot.', 'ai-pilot'); ?></p>
                        <button type="button" class="aipilot-button aipilot-button--primary aipilot-button--large" id="aipilot-connect-btn">
                            <span class="aipilot-button__icon" aria-hidden="true">→</span>
                            <?php esc_html_e('Подключить AI Pilot', 'ai-pilot'); ?>
                        </button>
                        <p class="aipilot-security-note"><span aria-hidden="true">🔒</span><?php esc_html_e('Одноразовый код действует 5 минут. Пароли WordPress не передаются.', 'ai-pilot'); ?></p>
                    </div>
                    <div class="aipilot-onboarding-steps">
                        <div><span>1</span><strong><?php esc_html_e('Нажмите кнопку', 'ai-pilot'); ?></strong><p><?php esc_html_e('Мы создадим одноразовое безопасное подключение.', 'ai-pilot'); ?></p></div>
                        <div><span>2</span><strong><?php esc_html_e('Войдите в AI Pilot', 'ai-pilot'); ?></strong><p><?php esc_html_e('Авторизация откроется в небольшом окне.', 'ai-pilot'); ?></p></div>
                        <div><span>3</span><strong><?php esc_html_e('Готово', 'ai-pilot'); ?></strong><p><?php esc_html_e('Окно закроется, сайт станет доступен в чате.', 'ai-pilot'); ?></p></div>
                    </div>
                </section>
            <?php endif; ?>

            <section class="aipilot-connect-progress" id="aipilot-connect-progress" hidden aria-live="polite">
                <div class="aipilot-spinner" aria-hidden="true"></div>
                <div class="aipilot-connect-progress__body">
                    <strong id="aipilot-connect-status"><?php esc_html_e('Готовим подключение…', 'ai-pilot'); ?></strong>
                    <p id="aipilot-connect-help"><?php esc_html_e('Не закрывайте эту страницу.', 'ai-pilot'); ?></p>
                    <div class="aipilot-code-row" id="aipilot-code-row" hidden>
                        <span><?php esc_html_e('Код подключения', 'ai-pilot'); ?></span>
                        <code id="aipilot-connect-code">—</code>
                        <small><span id="aipilot-connect-countdown">300</span> <?php esc_html_e('сек.', 'ai-pilot'); ?></small>
                    </div>
                    <a href="#" id="aipilot-reopen-popup" class="aipilot-text-link" hidden><?php esc_html_e('Открыть окно авторизации', 'ai-pilot'); ?></a>
                </div>
            </section>

            <section class="aipilot-feature-grid">
                <article><span>✦</span><h3><?php esc_html_e('Работа с контентом', 'ai-pilot'); ?></h3><p><?php esc_html_e('Черновики, страницы, SEO и массовые изменения — после вашего подтверждения.', 'ai-pilot'); ?></p></article>
                <article><span>◎</span><h3><?php esc_html_e('Понимание сайта', 'ai-pilot'); ?></h3><p><?php esc_html_e('AI Pilot видит структуру, тему, плагины и состояние WordPress.', 'ai-pilot'); ?></p></article>
                <article><span>✓</span><h3><?php esc_html_e('Контроль действий', 'ai-pilot'); ?></h3><p><?php esc_html_e('Опасные изменения сначала показываются как предложение на подтверждение.', 'ai-pilot'); ?></p></article>
            </section>

            <?php if ($connected): ?>
                <section class="aipilot-card aipilot-card--compact">
                    <div>
                        <h3><?php esc_html_e('Управление подключением', 'ai-pilot'); ?></h3>
                        <p><?php esc_html_e('Отключение немедленно отзывает ключ доступа к этому сайту.', 'ai-pilot'); ?></p>
                    </div>
                    <form method="post" onsubmit="return confirm('<?php echo esc_attr(__('Отключить сайт и отозвать ключ доступа?', 'ai-pilot')); ?>');">
                        <?php wp_nonce_field('aipilot_connect'); ?>
                        <button type="submit" name="aipilot_disconnect" class="aipilot-button aipilot-button--danger"><?php esc_html_e('Отключить сайт', 'ai-pilot'); ?></button>
                    </form>
                </section>
            <?php endif; ?>
        </main>
        <?php
        self::render_footer();
    }

    public static function render_assistant() {
        self::render_header('assistant');
        $soul = aipilot_agent_get_soul_data();
        ?>
        <main class="aipilot-main aipilot-main--narrow">
            <section class="aipilot-page-intro">
                <span class="aipilot-eyebrow"><?php esc_html_e('Поведение ассистента', 'ai-pilot'); ?></span>
                <h2><?php esc_html_e('Как AI Pilot должен понимать ваш сайт', 'ai-pilot'); ?></h2>
                <p><?php esc_html_e('Эти настройки помогают ассистенту писать в нужном стиле и учитывать правила бренда.', 'ai-pilot'); ?></p>
            </section>

            <form method="post" class="aipilot-card aipilot-form">
                <?php wp_nonce_field('aipilot_settings'); ?>
                <label class="aipilot-field">
                    <span><?php esc_html_e('О сайте', 'ai-pilot'); ?></span>
                    <textarea name="aipilot_description" rows="4" placeholder="<?php echo esc_attr(__('Например: сервис для автоматизации маркетинга малого бизнеса', 'ai-pilot')); ?>"><?php echo esc_textarea($soul['description'] ?? ''); ?></textarea>
                    <small><?php esc_html_e('Коротко опишите продукт, аудиторию и основную пользу.', 'ai-pilot'); ?></small>
                </label>

                <label class="aipilot-field">
                    <span><?php esc_html_e('Тон общения', 'ai-pilot'); ?></span>
                    <input type="text" name="aipilot_tone_of_voice" value="<?php echo esc_attr($soul['tone_of_voice'] ?? ''); ?>" placeholder="<?php echo esc_attr(__('Дружелюбный, уверенный и профессиональный', 'ai-pilot')); ?>">
                </label>

                <label class="aipilot-field">
                    <span><?php esc_html_e('Правила', 'ai-pilot'); ?></span>
                    <textarea name="aipilot_rules" rows="7" placeholder="<?php echo esc_attr(__('Каждое правило с новой строки', 'ai-pilot')); ?>"><?php echo esc_textarea(implode("\n", $soul['rules'] ?? [])); ?></textarea>
                    <small><?php esc_html_e('Например: обращаться на «Вы»; не использовать англицизмы; добавлять CTA в конце.', 'ai-pilot'); ?></small>
                </label>

                <div class="aipilot-form-actions">
                    <button type="submit" name="aipilot_save_soul" class="aipilot-button aipilot-button--primary"><?php esc_html_e('Сохранить настройки', 'ai-pilot'); ?></button>
                </div>
            </form>
        </main>
        <?php
        self::render_footer();
    }

    public static function render_permissions() {
        self::render_header('permissions');
        ?>
        <main class="aipilot-main">
            <section class="aipilot-page-intro">
                <span class="aipilot-eyebrow"><?php esc_html_e('Контроль доступа', 'ai-pilot'); ?></span>
                <h2><?php esc_html_e('Разрешения AI Pilot', 'ai-pilot'); ?></h2>
                <p><?php esc_html_e('Оставьте только те возможности, которые действительно нужны. Изменения сайта по-прежнему требуют подтверждения.', 'ai-pilot'); ?></p>
            </section>
            <?php self::render_capabilities(aipilot_get_capabilities()); ?>
        </main>
        <?php
        self::render_footer();
    }

    public static function render_system() {
        self::render_header('system');
        $gateway_url = get_option('aipilot_gateway_url', 'https://pilotsite.ru');
        ?>
        <main class="aipilot-main aipilot-main--narrow">
            <section class="aipilot-page-intro">
                <span class="aipilot-eyebrow"><?php esc_html_e('Технические параметры', 'ai-pilot'); ?></span>
                <h2><?php esc_html_e('Система и диагностика', 'ai-pilot'); ?></h2>
                <p><?php esc_html_e('Служебные настройки для администратора сайта.', 'ai-pilot'); ?></p>
            </section>

            <section class="aipilot-card aipilot-system-status">
                <div><span><?php esc_html_e('Плагин', 'ai-pilot'); ?></span><strong>AI Pilot — Remote Site API</strong></div>
                <div><span><?php esc_html_e('Версия', 'ai-pilot'); ?></span><strong><?php echo esc_html(AI_PILOT_VERSION); ?></strong></div>
                <div><span><?php esc_html_e('REST API', 'ai-pilot'); ?></span><strong><?php echo esc_html(rest_url('aipilot/v1')); ?></strong></div>
                <div><span><?php esc_html_e('Подключение', 'ai-pilot'); ?></span><strong class="<?php echo self::is_connected() ? 'is-good' : ''; ?>"><?php echo self::is_connected() ? esc_html__('активно', 'ai-pilot') : esc_html__('не настроено', 'ai-pilot'); ?></strong></div>
            </section>

            <form method="post" class="aipilot-card aipilot-form">
                <?php wp_nonce_field('aipilot_settings'); ?>
                <label class="aipilot-field">
                    <span><?php esc_html_e('Gateway URL', 'ai-pilot'); ?></span>
                    <input type="url" name="aipilot_gateway_url" value="<?php echo esc_attr($gateway_url); ?>" placeholder="https://pilotsite.ru">
                    <small><?php esc_html_e('Меняйте только при переносе AI Pilot на другой сервер.', 'ai-pilot'); ?></small>
                </label>
                <div class="aipilot-form-actions">
                    <button type="submit" name="aipilot_save_gateway" class="aipilot-button aipilot-button--secondary"><?php esc_html_e('Сохранить', 'ai-pilot'); ?></button>
                </div>
            </form>

            <details class="aipilot-card aipilot-details">
                <summary><?php esc_html_e('Справочник REST API', 'ai-pilot'); ?></summary>
                <?php self::render_endpoint_list(); ?>
            </details>
        </main>
        <?php
        self::render_footer();
    }

    // ─── Render helpers ──────────────────────────────────────────

    private static function render_capabilities($caps) {
        $groups = [
            __('Контент', 'ai-pilot') => [
                'posts_read' => __('Читать записи', 'ai-pilot'),
                'posts_create' => __('Создавать записи', 'ai-pilot'),
                'posts_update' => __('Изменять записи', 'ai-pilot'),
                'posts_delete' => __('Удалять записи', 'ai-pilot'),
                'pages_read' => __('Читать страницы', 'ai-pilot'),
                'pages_create' => __('Создавать страницы', 'ai-pilot'),
                'pages_update' => __('Изменять страницы', 'ai-pilot'),
                'pages_delete' => __('Удалять страницы', 'ai-pilot'),
            ],
            __('Структура сайта', 'ai-pilot') => [
                'site_info' => __('Сведения о сайте', 'ai-pilot'),
                'categories_read' => __('Читать категории', 'ai-pilot'),
                'categories_create' => __('Создавать категории', 'ai-pilot'),
                'tags_read' => __('Читать теги', 'ai-pilot'),
                'tags_create' => __('Создавать теги', 'ai-pilot'),
                'tags_update' => __('Изменять теги', 'ai-pilot'),
                'tags_delete' => __('Удалять теги', 'ai-pilot'),
                'menus_read' => __('Читать меню', 'ai-pilot'),
                'menus_create' => __('Создавать меню', 'ai-pilot'),
                'menus_update' => __('Изменять меню', 'ai-pilot'),
                'menus_delete' => __('Удалять меню', 'ai-pilot'),
            ],
            __('Система', 'ai-pilot') => [
                'users_read' => __('Читать список пользователей', 'ai-pilot'),
                'plugins_read' => __('Читать список плагинов', 'ai-pilot'),
                'plugins_install' => __('Устанавливать плагины', 'ai-pilot'),
                'plugins_upload' => __('Загружать плагины', 'ai-pilot'),
                'plugins_activate' => __('Активировать плагины', 'ai-pilot'),
                'plugins_deactivate' => __('Отключать плагины', 'ai-pilot'),
                'plugins_update' => __('Обновлять плагины', 'ai-pilot'),
                'plugins_delete' => __('Удалять плагины', 'ai-pilot'),
                'plugins_search' => __('Искать плагины', 'ai-pilot'),
                'themes_read' => __('Читать сведения о теме', 'ai-pilot'),
                'themes_switch' => __('Менять тему', 'ai-pilot'),
                'themes_edit' => __('Изменять настройки темы', 'ai-pilot'),
                'options_read' => __('Читать настройки WordPress', 'ai-pilot'),
                'options_write' => __('Изменять настройки WordPress', 'ai-pilot'),
            ],
        ];
        ?>
        <form method="post" class="aipilot-permissions-form">
            <?php wp_nonce_field('aipilot_settings'); ?>
            <div class="aipilot-permission-callout">
                <label class="aipilot-master-toggle">
                    <span><strong><?php esc_html_e('Управление через AI Pilot', 'ai-pilot'); ?></strong><small><?php esc_html_e('Нужно для предложений, подтверждений и выполнения действий.', 'ai-pilot'); ?></small></span>
                    <input type="checkbox" name="cap_full_access" value="1" <?php checked(!empty($caps['full_access'])); ?>>
                    <i aria-hidden="true"></i>
                </label>
            </div>

            <div class="aipilot-permission-grid">
                <?php foreach ($groups as $group => $items): ?>
                    <section class="aipilot-card aipilot-permission-group">
                        <h3><?php echo esc_html($group); ?></h3>
                        <?php foreach ($items as $cap => $label): ?>
                            <label class="aipilot-toggle-row">
                                <span><?php echo esc_html($label); ?><code><?php echo esc_html($cap); ?></code></span>
                                <input type="checkbox" name="cap_<?php echo esc_attr($cap); ?>" value="1" <?php checked(!empty($caps[$cap])); ?>>
                                <i aria-hidden="true"></i>
                            </label>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="aipilot-form-actions">
                <button type="submit" name="aipilot_save_caps" class="aipilot-button aipilot-button--primary"><?php esc_html_e('Сохранить доступы', 'ai-pilot'); ?></button>
                <button type="submit" name="aipilot_reset_caps" class="aipilot-button aipilot-button--secondary" onclick="return confirm('<?php echo esc_attr(__('Вернуть доступы по умолчанию?', 'ai-pilot')); ?>');"><?php esc_html_e('Сбросить', 'ai-pilot'); ?></button>
            </div>
        </form>
        <?php
    }

    private static function render_endpoint_list() {
        $items = [
            'GET /aipilot/v1/ping',
            'GET /aipilot/v1/site',
            'GET/POST /aipilot/v1/posts',
            'GET/POST /aipilot/v1/pages',
            'POST /aipilot/v1/agent/propose',
            'POST /aipilot/v1/agent/approve/{id}',
            'POST /aipilot/v1/agent/reject/{id}',
            'POST /aipilot/v1/agent/action',
        ];
        echo '<ul class="aipilot-endpoints">';
        foreach ($items as $item) {
            echo '<li><code>' . esc_html($item) . '</code></li>';
        }
        echo '</ul>';
    }
}
