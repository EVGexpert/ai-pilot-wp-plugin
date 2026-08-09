<?php
/**
 * AI Pilot — Regression Test Suite
 *
 * Covers every public REST endpoint, the capability system, the option
 * allowlist and the connect-code lifecycle. Runs without a live WordPress;
 * depends only on tests/wp-mock.php + tests/TestHelpers.php.
 *
 * Each test_*() method:
 *   - resets state via TestHelpers::resetState()
 *   - asserts one behaviour of the plugin under test
 *   - throws AssertionError on failure
 *
 * The runner (tests/test-runner.php) discovers and invokes every test_*
 * method via reflection.
 */
class RegressionTest {

    /** List of every agent endpoint expected from the spec. */
    const AGENT_ENDPOINTS = [
        ['POST', '/agent/connect-code'],
        ['GET',  '/agent/connection-status'],
        ['GET',  '/agent/verify-code'],
        ['GET',  '/agent/context'],
        ['GET',  '/agent/scan'],
        ['GET',  '/agent/memory'],
        ['POST', '/agent/memory'],
        ['GET',  '/agent/soul'],
        ['PUT',  '/agent/soul'],
        ['POST', '/agent/propose'],
        ['GET',  '/agent/pending'],
        ['POST', '/agent/approve/(?P<id>[a-f0-9-]+)'],
        ['POST', '/agent/reject/(?P<id>[a-f0-9-]+)'],
        ['POST', '/agent/action'],
    ];

    /** 21 options whitelisted by aipilot_update_options() (INV-002). */
    const ALLOWLIST_OPTIONS = [
        'blogname', 'blogdescription', 'site_icon', 'timezone_string',
        'date_format', 'time_format', 'start_of_week', 'WPLANG',
        'posts_per_page', 'posts_per_rss', 'rss_use_excerpt',
        'comment_moderation', 'comment_registration', 'close_comments_for_old_posts',
        'show_on_front', 'page_on_front', 'page_for_posts',
        'category_base', 'tag_base',
        'upload_path', 'upload_url_path',
    ];

    /** Critical options that must NEVER appear in the allowlist. */
    const FORBIDDEN_OPTIONS = [
        'siteurl', 'home', 'admin_email', 'blog_public',
        'users_can_register', 'default_role', 'stylesheet',
        'active_plugins', 'template', 'site_id',
    ];

    // ═════════════════════════════════════════════════════════════════
    //  GROUP 1: ROUTE STRUCTURE (Definition of Done: "every endpoint has a test")
    // ═════════════════════════════════════════════════════════════════

    public function test_01_connect_code_route_registered() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::assertTrue(TestHelpers::routeExists('POST', '/agent/connect-code'));
        // Also exists in legacy namespace
        TestHelpers::assertTrue(TestHelpers::routeExists('POST', '/agent/connect-code', TestHelpers::LEGACY_NS));
    }

    public function test_02_verify_code_route_registered() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::assertTrue(TestHelpers::routeExists('GET', '/agent/verify-code'));
    }

    public function test_03_context_route_registered() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::assertTrue(TestHelpers::routeExists('GET', '/agent/context'));
    }

    public function test_04_scan_route_registered() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::assertTrue(TestHelpers::routeExists('GET', '/agent/scan'));
    }

    public function test_05_memory_get_post_routes_registered() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::assertTrue(TestHelpers::routeExists('GET',  '/agent/memory'));
        TestHelpers::assertTrue(TestHelpers::routeExists('POST', '/agent/memory'));
    }

    public function test_06_soul_get_put_routes_registered() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::assertTrue(TestHelpers::routeExists('GET', '/agent/soul'));
        TestHelpers::assertTrue(TestHelpers::routeExists('PUT', '/agent/soul'));
    }

    public function test_07_propose_route_registered() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::assertTrue(TestHelpers::routeExists('POST', '/agent/propose'));
    }

    public function test_08_pending_route_registered() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::assertTrue(TestHelpers::routeExists('GET', '/agent/pending'));
    }

    public function test_09_approve_route_pattern_registered() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::assertTrue(
            TestHelpers::routeExists('POST', '/agent/approve/(?P<id>[a-f0-9-]+)'),
            'approve route pattern must match registration in ai-pilot-plugin.php'
        );
    }

    public function test_10_reject_route_pattern_registered() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::assertTrue(
            TestHelpers::routeExists('POST', '/agent/reject/(?P<id>[a-f0-9-]+)')
        );
    }

    public function test_11_action_route_registered() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::assertTrue(TestHelpers::routeExists('POST', '/agent/action'));
    }

    public function test_12_all_agent_endpoints_registered() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        foreach (self::AGENT_ENDPOINTS as $i => $route) {
            list($method, $path) = $route;
            if (!TestHelpers::routeExists($method, $path)) {
                TestHelpers::fail("Endpoint {$method} {$path} (index {$i}) not registered");
            }
        }
        TestHelpers::pass();
    }

    public function test_13_connect_code_route_requires_admin() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::assertTrue(TestHelpers::invokePermission('POST', '/agent/connect-code'));

        TestHelpers::overrideCurrentUserCan(false);
        $denied = TestHelpers::invokePermission('POST', '/agent/connect-code');
        TestHelpers::assertWPError($denied, 'aipilot_admin_required');
    }

    public function test_14_protected_route_denies_without_token() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::withNoToken();
        $result = TestHelpers::invokePermission('GET', '/agent/context');
        TestHelpers::assertWPError($result, 'auth_required');
    }

    // ═════════════════════════════════════════════════════════════════
    //  GROUP 2: CAPABILITY SYSTEM
    // ═════════════════════════════════════════════════════════════════

    public function test_15_aipilot_get_capabilities_returns_defaults() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $caps = aipilot_get_capabilities();
        TestHelpers::assertArrayHasKey('site_info', $caps);
        TestHelpers::assertArrayHasKey('full_access', $caps);
        // Read-only capabilities default to TRUE
        TestHelpers::assertTrue($caps['site_info']);
        TestHelpers::assertTrue($caps['posts_read']);
        // Write capabilities default to FALSE
        TestHelpers::assertFalse($caps['full_access']);
        TestHelpers::assertFalse($caps['posts_create']);
    }

    public function test_16_aipilot_can_returns_true_for_enabled_capability() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::enableCapability('posts_read');
        TestHelpers::assertTrue(aipilot_can('posts_read'));
    }

    public function test_17_aipilot_can_returns_false_for_disabled_capability() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        // posts_create defaults to false
        TestHelpers::assertFalse(aipilot_can('posts_create'));
    }

    public function test_18_aipilot_can_rejects_invalid_input() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::assertFalse(aipilot_can(''));
        TestHelpers::assertFalse(aipilot_can('   '));
        TestHelpers::assertFalse(aipilot_can(123));
        TestHelpers::assertFalse(aipilot_can([]));
        TestHelpers::assertFalse(aipilot_can('nonexistent_capability_name'));
    }

    public function test_19_aipilot_verify_token_and_can_requires_token() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::withNoToken();
        $result = aipilot_verify_token_and_can('site_info');
        TestHelpers::assertWPError($result, 'auth_required');
    }

    public function test_20_aipilot_verify_token_and_can_rejects_invalid_token() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::provisionToken();
        TestHelpers::withToken('wrong-token-value');
        $result = aipilot_verify_token_and_can('site_info');
        TestHelpers::assertWPError($result, 'auth_invalid');
    }

    public function test_21_aipilot_verify_token_and_can_rejects_missing_capability() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $token = TestHelpers::provisionToken();
        TestHelpers::withToken($token);
        // full_access defaults to false
        $result = aipilot_verify_token_and_can('full_access');
        TestHelpers::assertWPError($result, 'capability_denied');
    }

    public function test_22_aipilot_verify_token_and_can_passes_when_valid_and_enabled() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $token = TestHelpers::provisionToken();
        TestHelpers::withToken($token);
        // site_info defaults to true
        $result = aipilot_verify_token_and_can('site_info');
        TestHelpers::assertTrue($result);
    }

    public function test_23_aipilot_verify_token_and_can_supports_legacy_header() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $token = TestHelpers::provisionToken();
        TestHelpers::withLegacyToken($token);
        $result = aipilot_verify_token_and_can('site_info');
        TestHelpers::assertTrue($result);
    }

    public function test_24_aipilot_verify_token_and_can_rejects_when_no_token_configured() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        // Don't provision a token — no hash in DB
        TestHelpers::withToken('whatever');
        $result = aipilot_verify_token_and_can('site_info');
        TestHelpers::assertWPError($result, 'auth_not_configured');
    }

    // ═════════════════════════════════════════════════════════════════
    //  GROUP 3: CONNECT CODE LIFECYCLE (INV-007: single use)
    // ═════════════════════════════════════════════════════════════════

    public function test_25_connect_code_generates_8_char_code_with_300s_ttl() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('POST', '/agent/connect-code');
        TestHelpers::assertArrayHasKey('code', $result);
        TestHelpers::assertEqual(8, strlen($result['code']), 'Connect code must be exactly 8 chars (spec US-001)');
        TestHelpers::assertEqual(300, $result['expires_in']);
        TestHelpers::assertArrayHasKey('connect_url', $result);
    }

    public function test_26_connect_code_keeps_token_provisional_until_verify() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('POST', '/agent/connect-code');
        TestHelpers::assertEqual('', get_option('aipilot_api_token_hash', ''));
        TestHelpers::assertEqual('', get_option('aipilot_last_token', ''));
        $codes = get_option('aipilot_connect_codes', []);
        TestHelpers::assertArrayHasKey($result['code'], $codes);
        $entry = $codes[$result['code']];
        TestHelpers::assertFalse($entry['used']);
        TestHelpers::assertTrue(!empty($entry['token']));
        TestHelpers::assertGreaterThanOrEqual(time(), $entry['expires'] - 200);
    }

    public function test_27_verify_code_returns_token_and_site_info_on_success() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $code = TestHelpers::generateConnectCode();
        $result = TestHelpers::invokeRoute('GET', '/agent/verify-code', ['code' => $code]);
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertTrue($result['verified']);
        TestHelpers::assertArrayHasKey('token', $result);
        TestHelpers::assertArrayHasKey('site_url', $result);
        TestHelpers::assertArrayHasKey('site_name', $result);
        TestHelpers::assertTrue(get_option('aipilot_api_token_hash', '') !== '');
        TestHelpers::assertEqual(get_site_url(), get_option('aipilot_connected_site', ''));
        TestHelpers::assertTrue(get_option('aipilot_connected_at', '') !== '');
        TestHelpers::assertTrue(aipilot_get_capabilities()['full_access']);
        $codes = get_option('aipilot_connect_codes', []);
        TestHelpers::assertFalse(isset($codes[$code]['token']));
    }

    public function test_28_verify_code_rejects_missing_code() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('GET', '/agent/verify-code');
        TestHelpers::assertWPError($result, 'missing_code');
    }

    public function test_29_verify_code_rejects_invalid_code() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('GET', '/agent/verify-code', ['code' => 'BOGUS123']);
        TestHelpers::assertWPError($result, 'invalid_code');
    }

    public function test_30_verify_code_enforces_single_use() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $code = TestHelpers::generateConnectCode();
        // First verify succeeds
        $first = TestHelpers::invokeRoute('GET', '/agent/verify-code', ['code' => $code]);
        TestHelpers::assertTrue($first['verified']);
        // Second verify must fail with 'expired_code' (INV-007: single use)
        $second = TestHelpers::invokeRoute('GET', '/agent/verify-code', ['code' => $code]);
        TestHelpers::assertWPError($second, 'expired_code');
    }

    public function test_31_verify_code_rejects_expired_code() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        // Generate, then mutate expiry to past, then verify
        $code = TestHelpers::generateConnectCode();
        $codes = get_option('aipilot_connect_codes', []);
        $codes[$code]['expires'] = time() - 1;
        update_option('aipilot_connect_codes', $codes);
        $result = TestHelpers::invokeRoute('GET', '/agent/verify-code', ['code' => $code]);
        TestHelpers::assertWPError($result, 'expired_code');
    }

    // ═════════════════════════════════════════════════════════════════
    //  GROUP 4: AGENT ENDPOINT BEHAVIOUR
    // ═════════════════════════════════════════════════════════════════

    public function test_32_context_returns_site_soul_memory_structure() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('GET', '/agent/context');
        TestHelpers::assertNotWPError($result);
        foreach (['site', 'soul', 'memory', 'structure', 'scanned_at'] as $key) {
            TestHelpers::assertArrayHasKey($key, $result, "context must include '{$key}'");
        }
    }

    public function test_33_scan_returns_full_structure_and_persists() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('GET', '/agent/scan');
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertTrue($result['scanned']);
        TestHelpers::assertArrayHasKey('structure', $result);
        TestHelpers::assertArrayHasKey('content', $result['structure']);
        // Persists scan timestamp
        TestHelpers::assertTrue(get_option('aipilot_agent_last_scan') !== '');
        TestHelpers::assertTrue(get_option('aipilot_agent_structure') !== '');
    }

    public function test_34_memory_get_returns_array_and_count() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('GET', '/agent/memory');
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertArrayHasKey('memory', $result);
        TestHelpers::assertArrayHasKey('total', $result);
        TestHelpers::assertEqual(0, $result['total']);
    }

    public function test_35_memory_post_appends_entry() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('POST', '/agent/memory', [
            'action'  => 'test_action',
            'summary' => 'a summary',
            'agent'   => 'tester',
        ]);
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertTrue($result['saved']);
        TestHelpers::assertArrayHasKey('entry', $result);
        TestHelpers::assertEqual('test_action', $result['entry']['action']);

        // Now GET should reflect new count
        $after = TestHelpers::invokeRoute('GET', '/agent/memory');
        TestHelpers::assertEqual(1, $after['total']);
    }

    public function test_36_memory_post_caps_at_100_entries() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        for ($i = 0; $i < 105; $i++) {
            TestHelpers::invokeRoute('POST', '/agent/memory', [
                'action'  => "act_{$i}",
                'summary' => "summary {$i}",
            ]);
        }
        $result = TestHelpers::invokeRoute('GET', '/agent/memory');
        TestHelpers::assertEqual(100, $result['total'], 'Memory must be capped at 100 entries');
    }

    public function test_37_soul_get_returns_tone_of_voice_object() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('GET', '/agent/soul');
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertArrayHasKey('soul', $result);
        TestHelpers::assertArrayHasKey('tone_of_voice', $result['soul']);
        TestHelpers::assertArrayHasKey('rules', $result['soul']);
    }

    public function test_38_soul_put_updates_and_persists() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('PUT', '/agent/soul', [
            'tone_of_voice' => 'Bold and witty',
            'rules'         => ['No emojis', 'Be concise'],
            'description'   => 'A test blog',
        ]);
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertTrue($result['saved']);
        TestHelpers::assertEqual('Bold and witty', $result['soul']['tone_of_voice']);

        // Verify persistence
        $saved = json_decode(get_option('aipilot_agent_soul', ''), true);
        TestHelpers::assertEqual('Bold and witty', $saved['tone_of_voice']);
    }

    // ═════════════════════════════════════════════════════════════════
    //  GROUP 5: PROPOSAL FLOW (INV-003: human-in-the-loop)
    // ═════════════════════════════════════════════════════════════════

    public function test_39_propose_creates_pending_proposal_with_uuid() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('POST', '/agent/propose', [
            'action'      => 'update_post',
            'description' => 'Edit about page',
            'params'      => ['post_id' => 1],
            'diff'        => '- old + new',
        ]);
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertArrayHasKey('proposal', $result);
        $p = $result['proposal'];
        TestHelpers::assertEqual('pending', $p['status']);
        TestHelpers::assertEqual('update_post', $p['action']);
        // id must be a UUID4 (8-4-4-4-12 hex)
        TestHelpers::assertRegExp('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $p['id']);
        TestHelpers::assertEqual(1, $result['pending']);
    }

    public function test_40_pending_lists_only_pending_proposals() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        // Create three proposals
        $a = TestHelpers::invokeRoute('POST', '/agent/propose', ['action' => 'a']);
        $b = TestHelpers::invokeRoute('POST', '/agent/propose', ['action' => 'b']);
        $c = TestHelpers::invokeRoute('POST', '/agent/propose', ['action' => 'c']);
        // Reject one
        TestHelpers::invokeParamRoute('POST', '/agent/reject/(?P<id>[a-f0-9-]+)', ['id' => $b['proposal']['id']]);

        $result = TestHelpers::invokeRoute('GET', '/agent/pending');
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertEqual(2, $result['total']);
    }

    public function test_41_approve_executes_create_post_and_returns_result() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $created = TestHelpers::invokeRoute('POST', '/agent/propose', [
            'action' => 'create_post',
            'params' => ['title' => 'Approved draft', 'content' => '<p>Body</p>', 'status' => 'draft'],
        ]);
        $id = $created['proposal']['id'];

        $result = TestHelpers::invokeParamRoute('POST', '/agent/approve/(?P<id>[a-f0-9-]+)', ['id' => $id]);
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertTrue($result['approved']);
        TestHelpers::assertEqual('completed', $result['status']);
        TestHelpers::assertEqual('completed', $result['proposal']['status']);
        TestHelpers::assertTrue($result['proposal']['result']['success']);
        TestHelpers::assertGreaterThan(0, $result['proposal']['result']['id']);
        $post = get_post($result['proposal']['result']['id']);
        TestHelpers::assertEqual('Approved draft', $post->post_title);
        TestHelpers::assertEqual('<p>Body</p>', $post->post_content);
        TestHelpers::assertEqual('draft', $post->post_status);
    }

    public function test_42_reject_marks_proposal_rejected() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $created = TestHelpers::invokeRoute('POST', '/agent/propose', ['action' => 'update_post']);
        $id = $created['proposal']['id'];

        $result = TestHelpers::invokeParamRoute('POST', '/agent/reject/(?P<id>[a-f0-9-]+)', ['id' => $id]);
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertEqual('rejected', $result['status']);
        TestHelpers::assertEqual('rejected', $result['proposal']['status']);
    }

    public function test_43_approve_unknown_id_returns_404() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeParamRoute('POST', '/agent/approve/(?P<id>[a-f0-9-]+)', ['id' => '00000000-0000-0000-0000-000000000000']);
        TestHelpers::assertWPError($result, 'not_found');
    }

    public function test_44_reject_unknown_id_returns_404() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeParamRoute('POST', '/agent/reject/(?P<id>[a-f0-9-]+)', ['id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff']);
        TestHelpers::assertWPError($result, 'not_found');
    }

    // ═════════════════════════════════════════════════════════════════
    //  GROUP 6: ACTION EXECUTION (POST /agent/action)
    // ═════════════════════════════════════════════════════════════════

    public function test_45_action_update_post_modifies_existing_post() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $post_id = wp_insert_post([
            'post_title'   => 'Original',
            'post_content' => 'old',
            'post_status'  => 'publish',
            'post_type'    => 'post',
        ]);

        $result = TestHelpers::invokeRoute('POST', '/agent/action', [
            'action' => 'update_post',
            'params' => ['post_id' => $post_id, 'data' => ['post_title' => 'Updated']],
        ]);
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertTrue($result['done']);
        TestHelpers::assertEqual($post_id, $result['post_id']);
        $after = get_post($post_id);
        TestHelpers::assertEqual('Updated', $after->post_title);
    }

    public function test_46_action_update_post_returns_404_for_missing_post() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('POST', '/agent/action', [
            'action' => 'update_post',
            'params' => ['post_id' => 99999],
        ]);
        TestHelpers::assertWPError($result, 'not_found');
    }

    public function test_47_action_create_post_creates_new_post() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('POST', '/agent/action', [
            'action' => 'create_post',
            'params' => [
                'title'   => 'New Post',
                'content' => '<p>body</p>',
                'status'  => 'publish',
                'type'    => 'post',
            ],
        ]);
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertTrue($result['done']);
        TestHelpers::assertGreaterThan(0, $result['post_id']);
        $post = get_post($result['post_id']);
        TestHelpers::assertEqual('New Post', $post->post_title);
        TestHelpers::assertEqual('publish', $post->post_status);
    }

    public function test_48_action_create_post_rejects_invalid_status() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('POST', '/agent/action', [
            'action' => 'create_post',
            'params' => ['title' => 'X', 'content' => 'Y', 'status' => 'invalid'],
        ]);
        TestHelpers::assertWPError($result, 'aipilot_invalid_status');
    }

    public function test_49_action_update_option_writes_option() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('POST', '/agent/action', [
            'action' => 'update_option',
            'params' => ['option' => 'blogname', 'value' => 'My New Blog'],
        ]);
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertTrue($result['done']);
        TestHelpers::assertEqual('My New Blog', get_option('blogname'));
    }

    public function test_50_action_update_option_requires_option_name() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('POST', '/agent/action', [
            'action' => 'update_option',
            'params' => ['value' => 'x'],
        ]);
        TestHelpers::assertWPError($result, 'missing_param');
    }

    public function test_51_action_unknown_returns_400() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('POST', '/agent/action', [
            'action' => 'rm_rf_slash',
            'params' => [],
        ]);
        TestHelpers::assertWPError($result, 'unknown_action');
    }

    public function test_52_action_missing_action_param_returns_unknown_action() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('POST', '/agent/action', []);
        // Empty action falls through to default case
        TestHelpers::assertWPError($result, 'unknown_action');
    }

    public function test_53_action_switch_theme_calls_switch_theme() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('POST', '/agent/action', [
            'action' => 'switch_theme',
            'params' => ['theme' => 'twentynineteen'],
        ]);
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertEqual('twentynineteen', $result['theme']);
    }

    // ═════════════════════════════════════════════════════════════════
    //  GROUP 7: ALLOWLIST OF OPTIONS (INV-002)
    // ═════════════════════════════════════════════════════════════════

    public function test_54_options_put_accepts_allowed_option() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        // PUT /options — bypass permission_callback by calling handler directly
        $route = TestHelpers::getRoute('PUT', '/options');
        $req = new WP_REST_Request('PUT', '/options');
        $req->set_param('options', ['blogname' => 'Allowed Title']);
        $result = call_user_func($route['callback'], $req);
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertTrue($result['updated']);
        TestHelpers::assertEqual('Allowed Title', get_option('blogname'));
    }

    public function test_55_options_put_rejects_forbidden_option_siteurl() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $route = TestHelpers::getRoute('PUT', '/options');
        $req = new WP_REST_Request('PUT', '/options');
        $req->set_param('options', ['siteurl' => 'https://evil.example.com']);
        $result = call_user_func($route['callback'], $req);
        TestHelpers::assertWPError($result, 'option_not_allowed');
    }

    public function test_56_options_put_rejects_admin_email() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $route = TestHelpers::getRoute('PUT', '/options');
        $req = new WP_REST_Request('PUT', '/options');
        $req->set_param('options', ['admin_email' => 'attacker@evil.com']);
        $result = call_user_func($route['callback'], $req);
        TestHelpers::assertWPError($result, 'option_not_allowed');
    }

    public function test_57_options_put_rejects_empty_payload() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $route = TestHelpers::getRoute('PUT', '/options');
        $req = new WP_REST_Request('PUT', '/options');
        $req->set_param('options', []);
        $result = call_user_func($route['callback'], $req);
        TestHelpers::assertWPError($result, 'invalid_data');
    }

    public function test_58_options_put_rejects_non_array_payload() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $route = TestHelpers::getRoute('PUT', '/options');
        $req = new WP_REST_Request('PUT', '/options');
        $req->set_param('options', 'string-not-array');
        $result = call_user_func($route['callback'], $req);
        TestHelpers::assertWPError($result, 'invalid_data');
    }

    public function test_59_every_allowlist_option_is_accepted() {
        TestHelpers::loadPlugin();
        $route = TestHelpers::getRoute('PUT', '/options');
        TestHelpers::assertNotNull($route, 'PUT /options must be registered');

        foreach (self::ALLOWLIST_OPTIONS as $opt) {
            // Fresh state per option so rejections don't leak
            TestHelpers::resetState();
            $route = TestHelpers::getRoute('PUT', '/options');
            $req = new WP_REST_Request('PUT', '/options');
            $req->set_param('options', [$opt => 'value-' . $opt]);
            $result = call_user_func($route['callback'], $req);
            if ($result instanceof WP_Error) {
                TestHelpers::fail("Allowlist option '{$opt}' was rejected: " . $result->get_error_code());
            }
            TestHelpers::assertEqual('value-' . $opt, get_option($opt), "Option '{$opt}' was not persisted");
        }
        TestHelpers::pass();
    }

    public function test_60_every_forbidden_option_is_rejected() {
        TestHelpers::loadPlugin();
        $route = TestHelpers::getRoute('PUT', '/options');
        TestHelpers::assertNotNull($route, 'PUT /options must be registered');

        foreach (self::FORBIDDEN_OPTIONS as $opt) {
            TestHelpers::resetState();
            $route = TestHelpers::getRoute('PUT', '/options');
            $req = new WP_REST_Request('PUT', '/options');
            $req->set_param('options', [$opt => 'attacker-value']);
            $result = call_user_func($route['callback'], $req);
            if (!($result instanceof WP_Error) || $result->get_error_code() !== 'option_not_allowed') {
                TestHelpers::fail("Forbidden option '{$opt}' must be rejected (got: " . var_export($result, true) . ")");
            }
        }
        TestHelpers::pass();
    }

    public function test_61_options_get_returns_requested_keys() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        update_option('blogname', 'My Blog');
        update_option('blogdescription', 'Just a blog');
        $route = TestHelpers::getRoute('GET', '/options');
        $req = new WP_REST_Request('GET', '/options');
        $req->set_param('keys', 'blogname,blogdescription');
        $result = call_user_func($route['callback'], $req);
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertEqual('My Blog', $result['blogname']);
        TestHelpers::assertEqual('Just a blog', $result['blogdescription']);
    }

    // ═════════════════════════════════════════════════════════════════
    //  GROUP 8: INVARIANT CHECKS (INV-001, INV-004, INV-005)
    // ═════════════════════════════════════════════════════════════════

    public function test_62_connect_code_is_single_use_per_invocation() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        // Two distinct codes must yield two distinct tokens
        $code1 = TestHelpers::generateConnectCode();
        $code2 = TestHelpers::generateConnectCode();
        TestHelpers::assertNotEqual($code1, $code2);

        $verify1 = TestHelpers::invokeRoute('GET', '/agent/verify-code', ['code' => $code1]);
        // Note: $code2 is still unused — but $code1 is now consumed.
        // Cannot re-verify $code1
        $re = TestHelpers::invokeRoute('GET', '/agent/verify-code', ['code' => $code1]);
        TestHelpers::assertWPError($re);
    }

    public function test_63_aipilot_register_route_registers_in_both_namespaces() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        // Pick a representative endpoint and confirm both namespaces are populated
        TestHelpers::assertTrue(TestHelpers::routeExists('GET', '/agent/context', 'aipilot/v1'));
        TestHelpers::assertTrue(TestHelpers::routeExists('GET', '/agent/context', 'openclaw/v1'));
    }

    public function test_64_aipilot_ping_returns_version() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $result = TestHelpers::invokeRoute('GET', '/ping');
        TestHelpers::assertNotWPError($result);
        TestHelpers::assertEqual('ok', $result['status']);
        TestHelpers::assertEqual(AI_PILOT_VERSION, $result['version']);
    }

    public function test_65_aipilot_get_site_id_is_stable() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $id1 = aipilot_get_site_id();
        $id2 = aipilot_get_site_id();
        TestHelpers::assertEqual($id1, $id2);
        // Must persist between calls
        TestHelpers::assertEqual($id1, get_option('aipilot_site_id'));
        // Must be a 32-char md5 hash
        TestHelpers::assertRegExp('/^[0-9a-f]{32}$/', $id1);
    }

    public function test_66_get_capabilities_supports_filter() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        // The auth-helper module registers module caps via 'aipilot_default_capabilities'.
        // We can simulate by directly enabling a capability
        TestHelpers::enableCapability('posts_create');
        $caps = aipilot_get_capabilities();
        TestHelpers::assertTrue($caps['posts_create']);
    }

    public function test_67_legacy_namespace_alias_works_for_action() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::assertTrue(TestHelpers::routeExists('POST', '/agent/action', 'openclaw/v1'));
        TestHelpers::assertTrue(TestHelpers::routeExists('POST', '/agent/propose', 'openclaw/v1'));
        TestHelpers::assertTrue(TestHelpers::routeExists('GET',  '/agent/scan', 'openclaw/v1'));
    }

    public function test_68_aipilot_can_handles_filter_extension() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        // Save a custom cap directly
        TestHelpers::withCapabilities(['fluent_read' => true, 'fluent_write' => false]);
        TestHelpers::assertTrue(aipilot_can('fluent_read'));
        TestHelpers::assertFalse(aipilot_can('fluent_write'));
    }

    public function test_69_action_endpoint_requires_full_access_capability() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $token = TestHelpers::provisionToken();
        TestHelpers::withToken($token);
        // full_access defaults to false — must be denied
        $result = TestHelpers::invokePermission('POST', '/agent/action');
        TestHelpers::assertWPError($result, 'capability_denied');

        // Grant and retry
        TestHelpers::enableCapability('full_access');
        $result = TestHelpers::invokePermission('POST', '/agent/action');
        TestHelpers::assertTrue($result);
    }

    public function test_70_scan_endpoint_requires_full_access() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $token = TestHelpers::provisionToken();
        TestHelpers::withToken($token);
        // site_info defaults true, full_access defaults false
        $result = TestHelpers::invokePermission('GET', '/agent/scan');
        TestHelpers::assertWPError($result, 'capability_denied');

        TestHelpers::enableCapability('full_access');
        $result = TestHelpers::invokePermission('GET', '/agent/scan');
        TestHelpers::assertTrue($result);
    }

    public function test_71_connection_status_route_registered() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        TestHelpers::assertTrue(TestHelpers::routeExists('GET', '/agent/connection-status'));
    }

    public function test_72_connection_status_becomes_connected_after_verify() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $code = TestHelpers::generateConnectCode();
        $before = TestHelpers::invokeRoute('GET', '/agent/connection-status', ['code' => $code]);
        TestHelpers::assertFalse($before['connected']);
        TestHelpers::invokeRoute('GET', '/agent/verify-code', ['code' => $code]);
        $after = TestHelpers::invokeRoute('GET', '/agent/connection-status', ['code' => $code]);
        TestHelpers::assertTrue($after['connected']);
        TestHelpers::assertTrue($after['code_used']);
    }

    public function test_76_reconnect_status_waits_for_current_code() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();

        // Simulate a site that is already connected with an existing token.
        update_option('aipilot_connected_site', 'https://example.com');
        update_option('aipilot_connected_at', '2026-07-27 10:00:00');
        update_option('aipilot_api_token_hash', 'existing-hash');

        $code = TestHelpers::generateConnectCode();
        $before = TestHelpers::invokeRoute('GET', '/agent/connection-status', ['code' => $code]);
        TestHelpers::assertFalse($before['connected']);
        TestHelpers::assertFalse($before['code_used']);

        TestHelpers::invokeRoute('GET', '/agent/verify-code', ['code' => $code]);
        $after = TestHelpers::invokeRoute('GET', '/agent/connection-status', ['code' => $code]);
        TestHelpers::assertTrue($after['connected']);
        TestHelpers::assertTrue($after['code_used']);
    }

    public function test_73_proposal_accepts_nested_target_patch_contract() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $created = TestHelpers::invokeRoute('POST', '/agent/propose', [
            'action' => 'create_post',
            'summary' => 'Nested contract',
            'params' => [
                'target' => ['title' => 'Nested title', 'content' => '<p>Nested body</p>'],
                'patch' => ['status' => 'private'],
            ],
        ]);
        $params = $created['proposal']['params'];
        TestHelpers::assertEqual('Nested title', $params['title']);
        TestHelpers::assertEqual('<p>Nested body</p>', $params['content']);
        TestHelpers::assertEqual('private', $params['status']);
    }

    public function test_74_repeated_approve_is_idempotent() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $created = TestHelpers::invokeRoute('POST', '/agent/propose', [
            'action' => 'create_post',
            'params' => ['title' => 'Once', 'content' => 'Only once', 'status' => 'draft'],
        ]);
        $id = $created['proposal']['id'];
        $first = TestHelpers::invokeParamRoute('POST', '/agent/approve/(?P<id>[a-f0-9-]+)', ['id' => $id]);
        $countAfterFirst = count($GLOBALS['aipilot_posts']);
        $second = TestHelpers::invokeParamRoute('POST', '/agent/approve/(?P<id>[a-f0-9-]+)', ['id' => $id]);
        TestHelpers::assertEqual($countAfterFirst, count($GLOBALS['aipilot_posts']));
        TestHelpers::assertEqual($first['proposal']['result']['id'], $second['proposal']['result']['id']);
    }

    public function test_75_create_post_requires_title_and_content() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();
        $noTitle = TestHelpers::invokeRoute('POST', '/agent/action', [
            'action' => 'create_post',
            'params' => ['content' => 'Body'],
        ]);
        TestHelpers::assertWPError($noTitle, 'aipilot_title_required');

        $noContent = TestHelpers::invokeRoute('POST', '/agent/action', [
            'action' => 'create_post',
            'params' => ['title' => 'Title'],
        ]);
        TestHelpers::assertWPError($noContent, 'aipilot_content_required');
    }


    public function test_77_proposal_lock_blocks_concurrent_approve() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();

        $created = TestHelpers::invokeRoute('POST', '/agent/propose', [
            'action' => 'create_post',
            'params' => [
                'title' => 'Locked proposal',
                'content' => '<p>Must not execute while locked.</p>',
                'status' => 'draft',
            ],
        ]);
        $id = $created['proposal']['id'];
        add_option('aipilot_proposal_lock_' . md5($id), time(), '', false);

        $result = TestHelpers::invokeParamRoute('POST', '/agent/approve/(?P<id>[a-f0-9-]+)', ['id' => $id]);
        TestHelpers::assertWPError($result, 'proposal_processing');
        TestHelpers::assertEqual(0, count($GLOBALS['aipilot_posts']));
    }


    public function test_78_create_post_accepts_singular_category_and_tag_fields() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();

        $result = TestHelpers::invokeRoute('POST', '/agent/action', [
            'action' => 'create_post',
            'params' => [
                'title' => 'Taxonomy singular',
                'content' => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
                'status' => 'draft',
                'category' => 'SEO',
                'tag' => 'AI Pilot, Automation',
            ],
        ]);

        TestHelpers::assertNotWPError($result);
        TestHelpers::assertTrue($result['done']);
        $post_id = $result['post_id'];

        TestHelpers::assertEqual(1, count($GLOBALS['aipilot_post_cats'][$post_id]));
        $category_id = $GLOBALS['aipilot_post_cats'][$post_id][0];
        TestHelpers::assertEqual('SEO', $GLOBALS['aipilot_categories'][$category_id]->name);
        TestHelpers::assertEqual(['AI Pilot', 'Automation'], $GLOBALS['aipilot_post_tags'][$post_id]);
        TestHelpers::assertEqual([$category_id], $result['category_ids']);
        TestHelpers::assertEqual(['AI Pilot', 'Automation'], $result['tag_names']);
    }

    public function test_79_create_post_accepts_mixed_category_and_tag_ids_names() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();

        $category_id = wp_create_category('Existing category');
        $tag_result = wp_insert_term('Existing tag', 'post_tag');
        $tag_id = $tag_result['term_id'];

        $result = TestHelpers::invokeRoute('POST', '/agent/action', [
            'action' => 'create_post',
            'params' => [
                'title' => 'Mixed terms',
                'content' => '<p>Body</p>',
                'status' => 'draft',
                'categories' => [$category_id, 'New category'],
                'tags' => [$tag_id, 'New tag'],
            ],
        ]);

        TestHelpers::assertNotWPError($result);
        $post_id = $result['post_id'];
        TestHelpers::assertEqual(2, count($GLOBALS['aipilot_post_cats'][$post_id]));
        TestHelpers::assertEqual(['Existing tag', 'New tag'], $GLOBALS['aipilot_post_tags'][$post_id]);
        TestHelpers::assertEqual(2, count($result['category_ids']));
        TestHelpers::assertEqual(['Existing tag', 'New tag'], $result['tag_names']);
        TestHelpers::assertEqual(2, count($result['tag_ids']));
    }

    public function test_80_create_post_accepts_comma_separated_term_strings() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();

        $result = TestHelpers::invokeRoute('POST', '/agent/action', [
            'action' => 'create_post',
            'params' => [
                'title' => 'Comma separated',
                'content' => '<p>Body</p>',
                'categories' => 'Новости, Кейсы',
                'tags' => 'AI, WordPress; Automation',
            ],
        ]);

        TestHelpers::assertNotWPError($result);
        $post_id = $result['post_id'];
        TestHelpers::assertEqual(2, count($GLOBALS['aipilot_post_cats'][$post_id]));
        TestHelpers::assertEqual(['AI', 'WordPress', 'Automation'], $GLOBALS['aipilot_post_tags'][$post_id]);
    }

    public function test_81_update_post_applies_and_clears_terms() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();

        $post_id = wp_insert_post([
            'post_title' => 'Update terms',
            'post_content' => '<p>Body</p>',
            'post_status' => 'draft',
            'post_type' => 'post',
        ]);

        $updated = TestHelpers::invokeRoute('POST', '/agent/action', [
            'action' => 'update_post',
            'params' => [
                'post_id' => $post_id,
                'data' => [
                    'post_title' => 'Updated terms',
                    'category' => 'Updates',
                    'tags' => 'One, Two',
                ],
            ],
        ]);

        TestHelpers::assertNotWPError($updated);
        TestHelpers::assertEqual(1, count($GLOBALS['aipilot_post_cats'][$post_id]));
        TestHelpers::assertEqual(['One', 'Two'], $GLOBALS['aipilot_post_tags'][$post_id]);

        $cleared = TestHelpers::invokeRoute('POST', '/agent/action', [
            'action' => 'update_post',
            'params' => [
                'post_id' => $post_id,
                'data' => [
                    'categories' => [],
                    'tags' => [],
                ],
            ],
        ]);

        TestHelpers::assertNotWPError($cleared);
        TestHelpers::assertEqual([], $GLOBALS['aipilot_post_cats'][$post_id]);
        TestHelpers::assertEqual([], $GLOBALS['aipilot_post_tags'][$post_id]);
    }

    public function test_82_approved_proposal_applies_terms_once() {
        TestHelpers::resetState();
        TestHelpers::loadPlugin();

        $created = TestHelpers::invokeRoute('POST', '/agent/propose', [
            'action' => 'create_post',
            'params' => [
                'target' => [
                    'title' => 'Approve terms',
                    'content' => '<p>Body</p>',
                ],
                'patch' => [
                    'status' => 'draft',
                    'category' => 'Content',
                    'tags' => ['AI Pilot', 'WordPress'],
                ],
            ],
        ]);

        $id = $created['proposal']['id'];
        $first = TestHelpers::invokeParamRoute(
            'POST',
            '/agent/approve/(?P<id>[a-f0-9-]+)',
            ['id' => $id]
        );
        $post_count = count($GLOBALS['aipilot_posts']);
        $second = TestHelpers::invokeParamRoute(
            'POST',
            '/agent/approve/(?P<id>[a-f0-9-]+)',
            ['id' => $id]
        );

        TestHelpers::assertNotWPError($first);
        TestHelpers::assertTrue($first['approved']);
        TestHelpers::assertEqual(1, count($first['proposal']['result']['category_ids']));
        TestHelpers::assertEqual(['AI Pilot', 'WordPress'], $first['proposal']['result']['tag_names']);
        TestHelpers::assertEqual($post_count, count($GLOBALS['aipilot_posts']));
        TestHelpers::assertEqual(
            $first['proposal']['result']['id'],
            $second['proposal']['result']['id']
        );
    }

}
