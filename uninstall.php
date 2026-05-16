<?php
/**
 * AI Pilot API Uninstall
 *
 * Clean up plugin options on uninstall.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('aipilot_api_token');
delete_option('aipilot_api_token_hash');
delete_option('aipilot_api_capabilities');
delete_transient('aipilot_new_token');

// Also clean up any old options from previous versions
delete_option('lilith_api_token');
delete_option('lilith_api_capabilities');