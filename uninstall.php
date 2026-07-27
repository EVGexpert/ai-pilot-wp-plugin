<?php
/**
 * AI Pilot uninstall cleanup.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$options = [
    'aipilot_api_token',
    'aipilot_api_token_hash',
    'aipilot_api_capabilities',
    'aipilot_last_token',
    'aipilot_connect_codes',
    'aipilot_connected_site',
    'aipilot_connected_at',
    'aipilot_agent_proposals',
    'aipilot_proposals',
    'aipilot_agent_soul',
    'aipilot_agent_memory',
    'aipilot_agent_structure',
    'aipilot_agent_last_scan',
    'aipilot_gateway_url',
    'aipilot_site_id',
    'lilith_api_token',
    'lilith_api_capabilities',
];

foreach ($options as $option) {
    delete_option($option);
}

delete_transient('aipilot_new_token');


// Remove any database-backed proposal locks left by interrupted requests.
global $wpdb;
if (isset($wpdb) && isset($wpdb->options)) {
    $like = $wpdb->esc_like('aipilot_proposal_lock_') . '%';
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $like
    ));
}
