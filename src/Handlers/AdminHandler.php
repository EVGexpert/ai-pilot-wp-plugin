<?php
namespace AIPILOT\RemoteApi\Handlers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Compatibility bridge for the former Settings-page admin handler.
 *
 * The canonical interface now lives in the top-level AI Pilot admin section.
 */
class AdminHandler {
    public static function init() {
        if (!class_exists('\\AIPILOT_Admin')) {
            require_once AI_PILOT_PLUGIN_DIR . 'src/class-admin.php';
        }
        \AIPILOT_Admin::init();
    }
}
