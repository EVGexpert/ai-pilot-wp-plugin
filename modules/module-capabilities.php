<?php
/**
 * AI Pilot – Capability Profile Module (schema v1.1)
 *
 * Отдаёт статический Capability Profile для AI-оркестратора:
 *   GET /agent/capabilities
 *
 * Структура ответа соответствует контракту prompts-v1.1.0/contracts/
 * site-capabilities.example.json. Профиль описывает:
 *   - intelligence: какие типы чтения поддерживает коннектор
 *   - authoring: режимы авторинга (gutenberg_core / classic_html /
 *     builder_managed / aipilot_blocks) и слои валидации
 *   - extensions: наличие AI Pilot Blocks (без раскрытия списка плагинов)
 *   - agentUi: URL-ы UI-манифеста на стороне оркестратора
 *   - actions: список действий, поддерживаемых /agent execute API
 *
 * Обнаружение:
 *   - wp_is_block_theme() → FSE → gutenberg_core
 *   - classic theme → gutenberg_core (Gutenberg в WP core с 5.0)
 *   - meta _elementor_data / _bricks_page_footer → builder_managed
 *   - AI Pilot Blocks: WP_Block_Type_Registry (aipilot/*) либо REST-роут
 *     /aipilot-blocks/v1/manifest
 *
 * @package AI_Pilot
 * @since   2.1.1
 */

if (!defined('ABSPATH')) {
    exit;
}

// ─── РЕГИСТРАЦИЯ ЭНДПОИНТА ──────────────────────────────────────────

add_action('rest_api_init', function () {
    aipilot_register_route('/agent/capabilities', [
        'methods'             => 'GET',
        'callback'            => 'aipilot_agent_capabilities',
        'permission_callback' => function () {
            return aipilot_verify_token_and_can('site_info');
        },
    ]);
});

// ═══════════════════════════════════════════════════════════════════
//  CAPABILITY PROFILE
// ═══════════════════════════════════════════════════════════════════

/**
 * Сформировать Capability Profile сайта (schema v1.1).
 *
 * @return array
 */
function aipilot_agent_capabilities() {
    $aipilot_blocks = aipilot_capabilities_detect_aipilot_blocks();
    $authoring      = aipilot_capabilities_detect_authoring($aipilot_blocks['available']);

    return [
        'schemaVersion' => '1.1',
        'siteId'        => aipilot_get_site_id(),
        'connector'     => [
            'name'    => 'AI Pilot Remote Site API',
            'version' => AI_PILOT_VERSION,
        ],
        'intelligence' => [
            'structure'    => true,
            'contentList'  => true,
            'contentRead'  => true,
            'health'       => true,
            'diagnostics'  => true,
            'media'        => true,
        ],
        'authoring'  => $authoring,
        'extensions' => [
            'aipilotBlocks' => $aipilot_blocks,
        ],
        'agentUi' => [
            'enabled'       => true,
            'schemaVersion' => '1.0',
            'manifestUrl'   => '/api/chat/ui-manifest',
            'responseUrl'   => '/api/chat/ui/respond',
        ],
        'actions' => [
            'get_posts',
            'get_post',
            'create_post',
            'update_post',
            'get_site_health',
        ],
    ];
}

// ═══════════════════════════════════════════════════════════════════
//  ОБНАРУЖЕНИЕ AI PILOT BLOCKS
//  ВАЖНО: список активных плагинов НЕ раскрывается — только факт
//  наличия зарегистрированных блоков либо REST-роута.
// ═══════════════════════════════════════════════════════════════════

/**
 * Обнаружить AI Pilot Blocks без раскрытия списка плагинов.
 *
 * Признаки доступности:
 *   1. В WP_Block_Type_Registry есть блок с namespace "aipilot/*"
 *   2. Зарегистрирован REST-роут /aipilot-blocks/v1/manifest
 *
 * @return array {
 *     @type bool        $available
 *     @type string|null $version
 *     @type array       $endpoints
 *     @type \stdClass   $hashes     Пустой объект, если не available.
 * }
 */
function aipilot_capabilities_detect_aipilot_blocks() {
    $available = false;
    $version   = null;

    // Признак 1: блоки с namespace aipilot/*
    if (class_exists('WP_Block_Type_Registry')) {
        $registry = WP_Block_Type_Registry::get_instance();
        foreach ($registry->get_all_registered() as $block_name => $block_type) {
            if (strpos($block_name, 'aipilot/') === 0) {
                $available = true;
                break;
            }
        }
    }

    // Признак 2: REST-роут /aipilot-blocks/v1/manifest
    if (!$available && function_exists('rest_get_server')) {
        try {
            $routes = rest_get_server()->get_routes();
            foreach (array_keys($routes) as $route) {
                if (strpos($route, '/aipilot-blocks/v1/manifest') !== false) {
                    $available = true;
                    break;
                }
            }
        } catch (Throwable $e) {
            // Fail-safe: не валить профиль при ошибке сервера REST
            error_log('[AI Pilot Capabilities] REST route scan failed: ' . $e->getMessage());
        }
    }

    $endpoints = [
        'manifest' => '/wp-json/aipilot-blocks/v1/manifest',
        'rules'    => '/wp-json/aipilot-blocks/v1/rules',
        'validate' => '/wp-json/aipilot-blocks/v1/validate',
        'audit'    => '/wp-json/aipilot-blocks/v1/audit',
    ];

    if (!$available) {
        return [
            'available' => false,
            'version'   => null,
            'endpoints' => $endpoints,
            'hashes'    => new stdClass(),
        ];
    }

    // Когда AI Pilot Blocks доступен — хеши вычисляются на стороне
    // соответствующего расширения. Здесь возвращаем пустую структуру;
    // расширение может фильтровать значения через хук ниже.
    $hashes = apply_filters('aipilot_capabilities_blocks_hashes', new stdClass());

    return [
        'available' => true,
        'version'   => $version,
        'endpoints' => $endpoints,
        'hashes'    => $hashes,
    ];
}

// ═══════════════════════════════════════════════════════════════════
//  ОБНАРУЖЕНИЕ РЕЖИМА АВТОРИНГА
// ═══════════════════════════════════════════════════════════════════

/**
 * Определить режим авторинга по состоянию сайта.
 *
 * Логика:
 *   - Elementor / Bricks (по presence метаполей) → builder_managed
 *   - Иначе: FSE (wp_is_block_theme) → gutenberg_core
 *   - Иначе: classic theme → gutenberg_core (Gutenberg в core с WP 5.0)
 *   - При доступности AI Pilot Blocks режим aipilot_blocks добавляется
 *     в availableModes и validationLayers.
 *
 * @param bool $aipilot_blocks_available
 * @return array
 */
function aipilot_capabilities_detect_authoring($aipilot_blocks_available) {
    $available_modes   = ['gutenberg_core', 'classic_html'];
    $validation_layers = ['permissions', 'sanitization', 'preconditions', 'wp_block_parse'];

    // Обнаружение билдера (не раскрывает список плагинов — только мета)
    $builder = aipilot_capabilities_detect_builder();

    if ($builder !== null) {
        $default_mode = 'builder_managed';
        if (!in_array('builder_managed', $available_modes, true)) {
            $available_modes[] = 'builder_managed';
        }
    } else {
        // FSE или classic — обе поддерживают gutenberg_core
        $default_mode = 'gutenberg_core';
    }

    if ($aipilot_blocks_available) {
        if (!in_array('aipilot_blocks', $available_modes, true)) {
            $available_modes[] = 'aipilot_blocks';
        }
        if (!in_array('aipilot_blocks', $validation_layers, true)) {
            $validation_layers[] = 'aipilot_blocks';
        }
        // Если AI Pilot Blocks активен и нет стороннего билдера —
        // предпочтительный режим авторинга становится aipilot_blocks.
        if ($builder === null) {
            $default_mode = 'aipilot_blocks';
        }
    }

    return [
        'write'            => true,
        'defaultMode'      => $default_mode,
        'availableModes'   => $available_modes,
        'validationLayers' => $validation_layers,
    ];
}

/**
 * Определить наличие page builder по presence метаполей постов.
 *
 * Возвращает строку-идентификатор билдера ('elementor'|'bricks')
 * или null, если ни один не обнаружен. НЕ перечисляет плагины —
 * только факт существования характерных meta-ключей.
 *
 * @return string|null
 */
function aipilot_capabilities_detect_builder() {
    $builders = [
        'elementor' => '_elementor_data',
        'bricks'    => '_bricks_page_footer',
    ];

    foreach ($builders as $name => $meta_key) {
        $found = get_posts([
            'post_type'        => 'any',
            'post_status'      => 'any',
            'meta_key'         => $meta_key,
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ]);

        if (!empty($found)) {
            return $name;
        }
    }

    return null;
}
