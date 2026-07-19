<?php
/**
 * AI Pilot — Test Helpers
 *
 * Bootstrap utilities + assertion library used by RegressionTest.php.
 *
 * Responsibilities:
 *   - resetState() — wipe the in-memory datastores between tests
 *   - loadPlugin() — require the production plugin file and fire
 *                    'rest_api_init' so the route registry is populated
 *   - route*()    — lookup and invoke REST handlers by namespace/method/path
 *   - assert*()   — JUnit-style assertions with descriptive failures
 *   - with*()     — fluent environment controls (token, capability,
 *                    current_user_can override)
 *
 * Depends on tests/wp-mock.php.
 */

class TestHelpers {

    public static $failures = 0;
    public static $passes   = 0;
    public static $current_test = '';
    public static $last_failure_message = '';

    /** Default REST namespace used by the plugin (aipilot_register_route). */
    const PRIMARY_NS  = 'aipilot/v1';
    const LEGACY_NS   = 'openclaw/v1';

    /**
     * Reset all in-memory state between tests.
     *
     * Routes and add_action/add_filter callbacks are deliberately PRESERVED —
     * they are produced by the plugin's static source which is loaded once
     * via require_once. Only mutable datastores (options, posts, etc.) are
     * wiped.
     */
    public static function resetState() {
        $GLOBALS['wp_options']         = [];
        $GLOBALS['aipilot_posts']      = [];
        $GLOBALS['aipilot_post_seq']   = 0;
        $GLOBALS['aipilot_categories'] = [];
        $GLOBALS['aipilot_cat_seq']    = 0;
        $GLOBALS['aipilot_tags']       = [];
        $GLOBALS['aipilot_tag_seq']    = 0;
        $GLOBALS['aipilot_menus']      = [];
        $GLOBALS['aipilot_users']      = [];
        $GLOBALS['aipilot_transients'] = [];
        $GLOBALS['aipilot_settings_errors'] = [];
        $GLOBALS['aipilot_enqueued_scripts'] = [];
        $GLOBALS['aipilot_nav_menu_items']   = [];
        $GLOBALS['aipilot_active_plugins']   = [];

        unset($GLOBALS['aipilot_current_user_can_override']);

        $_SERVER = array_merge($_SERVER, [
            'HTTP_AUTHORIZATION'           => '',
            'REDIRECT_HTTP_AUTHORIZATION'  => '',
            'HTTP_X_AI_PILOT_TOKEN'        => '',
            'HTTP_X_OPENCLAW_TOKEN'        => '',
            'REMOTE_ADDR'                  => '127.0.0.1',
        ]);
    }

    /**
     * Load the plugin under test and fire rest_api_init so routes register.
     */
    public static function loadPlugin() {
        static $loaded = false;
        if ($loaded) return;

        $plugin_file = dirname(__DIR__) . '/ai-pilot-plugin.php';
        if (!file_exists($plugin_file)) {
            throw new RuntimeException("Plugin file not found: $plugin_file");
        }

        require_once $plugin_file;

        // Fire every 'rest_api_init' callback the plugin registered via add_action.
        do_action('rest_api_init');

        $loaded = true;
    }

    // ═════════════════════════════════════════════════════════════════
    //  AUTH / CAPABILITY CONTROLS
    // ═════════════════════════════════════════════════════════════════

    /**
     * Generate a fresh token + hash, mimicking what the admin UI does.
     * Returns the raw token to be used as the X-AI-PILOT-TOKEN header.
     */
    public static function provisionToken() {
        $token = 'test-token-' . wp_generate_password(24, false);
        update_option('aipilot_api_token_hash', wp_hash($token));
        return $token;
    }

    /**
     * Set the X-AI-PILOT-TOKEN header for subsequent requests.
     */
    public static function withToken($token) {
        $_SERVER['HTTP_X_AI_PILOT_TOKEN'] = (string) $token;
        $_SERVER['HTTP_X_OPENCLAW_TOKEN'] = '';
        $_SERVER['HTTP_AUTHORIZATION']    = '';
    }

    /**
     * Set the legacy X-OPENCLAW-TOKEN header instead.
     */
    public static function withLegacyToken($token) {
        $_SERVER['HTTP_X_OPENCLAW_TOKEN'] = (string) $token;
        $_SERVER['HTTP_X_AI_PILOT_TOKEN'] = '';
        $_SERVER['HTTP_AUTHORIZATION']    = '';
    }

    /**
     * Clear all auth headers — request will be unauthenticated.
     */
    public static function withNoToken() {
        $_SERVER['HTTP_X_AI_PILOT_TOKEN'] = '';
        $_SERVER['HTTP_X_OPENCLAW_TOKEN'] = '';
        $_SERVER['HTTP_AUTHORIZATION']    = '';
    }

    /**
     * Set a Bearer JWT for the Authorization header.
     */
    public static function withBearer($jwt) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt;
        $_SERVER['HTTP_X_AI_PILOT_TOKEN'] = '';
        $_SERVER['HTTP_X_OPENCLAW_TOKEN'] = '';
    }

    /**
     * Override capability check result.
     */
    public static function withCapabilities(array $caps) {
        update_option('aipilot_api_capabilities', $caps);
    }

    /**
     * Enable a single capability.
     */
    public static function enableCapability($cap) {
        $saved = get_option('aipilot_api_capabilities', []);
        $saved[$cap] = true;
        update_option('aipilot_api_capabilities', $saved);
    }

    /**
     * Disable a single capability (does not unset, sets false).
     */
    public static function disableCapability($cap) {
        $saved = get_option('aipilot_api_capabilities', []);
        $saved[$cap] = false;
        update_option('aipilot_api_capabilities', $saved);
    }

    /**
     * Override current_user_can() return value (bool or callable).
     */
    public static function overrideCurrentUserCan($value) {
        $GLOBALS['aipilot_current_user_can_override'] = $value;
    }

    // ═════════════════════════════════════════════════════════════════
    //  ROUTE REGISTRY ACCESS
    // ═════════════════════════════════════════════════════════════════

    /**
     * Get the full route registry for inspection.
     */
    public static function getAllRoutes() {
        return $GLOBALS['aipilot_routes'];
    }

    /**
     * Does a route exist for the given method+path in the primary namespace?
     */
    public static function routeExists($method, $path, $ns = self::PRIMARY_NS) {
        $method = strtoupper($method);
        return isset($GLOBALS['aipilot_routes'][$ns][$method][$path]);
    }

    /**
     * Get the route args (callback, permission_callback, methods).
     */
    public static function getRoute($method, $path, $ns = self::PRIMARY_NS) {
        $method = strtoupper($method);
        if (!isset($GLOBALS['aipilot_routes'][$ns][$method][$path])) {
            return null;
        }
        return $GLOBALS['aipilot_routes'][$ns][$method][$path];
    }

    /**
     * Get all registered paths for a given HTTP method.
     */
    public static function getPathsForMethod($method, $ns = self::PRIMARY_NS) {
        $method = strtoupper($method);
        if (!isset($GLOBALS['aipilot_routes'][$ns][$method])) return [];
        return array_keys($GLOBALS['aipilot_routes'][$ns][$method]);
    }

    // ═════════════════════════════════════════════════════════════════
    //  ROUTE INVOCATION
    // ═════════════════════════════════════════════════════════════════

    /**
     * Invoke a route handler by exact path.
     *
     * Returns the raw result from the callback (array, WP_Error, WP_REST_Response).
     *
     * @param string $method
     * @param string $path   Exact registered path, e.g. '/agent/context'
     * @param array  $params Request params
     * @param array  $headers
     * @return mixed
     */
    public static function invokeRoute($method, $path, array $params = [], array $headers = []) {
        $route = self::getRoute($method, $path);
        if ($route === null) {
            throw new RuntimeException("Route not found: {$method} {$path}");
        }
        $request = new WP_REST_Request($method, $path);
        foreach ($params as $k => $v) $request->set_param($k, $v);
        foreach ($headers as $k => $v) $request->set_header($k, $v);
        return call_user_func($route['callback'], $request);
    }

    /**
     * Invoke a route handler whose path contains regex params
     * (e.g. '/agent/approve/(?P<id>[a-f0-9-]+)').
     *
     * @param string $method
     * @param string $pathPattern Registered pattern
     * @param array  $paramValues Values to inject (e.g. ['id' => 'abc'])
     * @param array  $extraParams Additional request params
     * @return mixed
     */
    public static function invokeParamRoute($method, $pathPattern, array $paramValues = [], array $extraParams = []) {
        $route = self::getRoute($method, $pathPattern);
        if ($route === null) {
            throw new RuntimeException("Param route not found: {$method} {$pathPattern}");
        }
        $request = new WP_REST_Request($method, $pathPattern);
        foreach ($paramValues as $k => $v) $request->set_param($k, $v);
        foreach ($extraParams as $k => $v) $request->set_param($k, $v);
        return call_user_func($route['callback'], $request);
    }

    /**
     * Invoke the permission_callback for a route (returns true or WP_Error).
     */
    public static function invokePermission($method, $path) {
        $route = self::getRoute($method, $path);
        if ($route === null) {
            throw new RuntimeException("Route not found: {$method} {$path}");
        }
        if (!isset($route['permission_callback'])) return true;
        if ($route['permission_callback'] === '__return_true') return true;
        return call_user_func($route['permission_callback']);
    }

    /**
     * Invoke the permission_callback for a parametrised route.
     */
    public static function invokeParamPermission($method, $pathPattern, array $paramValues = []) {
        $route = self::getRoute($method, $pathPattern);
        if ($route === null) {
            throw new RuntimeException("Param route not found: {$method} {$pathPattern}");
        }
        if (!isset($route['permission_callback'])) return true;
        if ($route['permission_callback'] === '__return_true') return true;
        $request = new WP_REST_Request($method, $pathPattern);
        foreach ($paramValues as $k => $v) $request->set_param($k, $v);
        return call_user_func($route['permission_callback'], $request);
    }

    // ═════════════════════════════════════════════════════════════════
    //  CONNECT-CODE CONVENIENCE
    // ═════════════════════════════════════════════════════════════════

    /**
     * Generate a connect code via the production handler.
     * Returns the 8-char code.
     */
    public static function generateConnectCode() {
        $result = self::invokeRoute('POST', '/agent/connect-code');
        return isset($result['code']) ? $result['code'] : null;
    }

    /**
     * Generate a connect code and return the API token it carries.
     */
    public static function generateConnectToken() {
        $result   = self::invokeRoute('POST', '/agent/connect-code');
        $verify   = self::invokeRoute('GET', '/agent/verify-code', ['code' => $result['code']]);
        return isset($verify['token']) ? $verify['token'] : null;
    }

    // ═════════════════════════════════════════════════════════════════
    //  ASSERTIONS
    // ═════════════════════════════════════════════════════════════════

    public static function pass() {
        self::$passes++;
    }

    public static function fail($message) {
        self::$failures++;
        self::$last_failure_message = $message;
        throw new AssertionError($message);
    }

    public static function assertTrue($value, $message = '') {
        if ($value !== true) self::fail($message ?: "Expected TRUE, got: " . var_export($value, true));
        self::pass();
    }

    public static function assertFalse($value, $message = '') {
        if ($value !== false) self::fail($message ?: "Expected FALSE, got: " . var_export($value, true));
        self::pass();
    }

    public static function assertNull($value, $message = '') {
        if ($value !== null) self::fail($message ?: "Expected NULL, got: " . var_export($value, true));
        self::pass();
    }

    public static function assertNotNull($value, $message = '') {
        if ($value === null) self::fail($message ?: "Expected non-NULL, got NULL");
        self::pass();
    }

    public static function assertEqual($expected, $actual, $message = '') {
        if ($expected !== $actual) {
            self::fail($message ?: "Expected " . var_export($expected, true) . " !== " . var_export($actual, true));
        }
        self::pass();
    }

    public static function assertNotEqual($unexpected, $actual, $message = '') {
        if ($unexpected === $actual) {
            self::fail($message ?: "Values unexpectedly equal: " . var_export($actual, true));
        }
        self::pass();
    }

    public static function assertEqualIgnoreCase($expected, $actual, $message = '') {
        if (strtolower($expected) !== strtolower($actual)) {
            self::fail($message ?: "Expected " . var_export($expected, true) . " (case-insensitive) !== " . var_export($actual, true));
        }
        self::pass();
    }

    public static function assertInArray($needle, $haystack, $message = '') {
        if (!in_array($needle, $haystack, true)) {
            self::fail($message ?: "Expected " . var_export($needle, true) . " in " . var_export($haystack, true));
        }
        self::pass();
    }

    public static function assertNotInArray($needle, $haystack, $message = '') {
        if (in_array($needle, $haystack, true)) {
            self::fail($message ?: "Unexpected " . var_export($needle, true) . " in " . var_export($haystack, true));
        }
        self::pass();
    }

    public static function assertArrayHasKey($key, $array, $message = '') {
        if (!is_array($array) || !array_key_exists($key, $array)) {
            self::fail($message ?: "Expected key '{$key}' missing from array");
        }
        self::pass();
    }

    public static function assertArrayNotHasKey($key, $array, $message = '') {
        if (is_array($array) && array_key_exists($key, $array)) {
            self::fail($message ?: "Unexpected key '{$key}' present in array");
        }
        self::pass();
    }

    public static function assertCount($expected, $countable, $message = '') {
        $actual = is_countable($countable) ? count($countable) : 0;
        if ($expected !== $actual) {
            self::fail($message ?: "Expected count {$expected}, got {$actual}");
        }
        self::pass();
    }

    public static function assertGreaterThan($expected, $actual, $message = '') {
        if (!($actual > $expected)) {
            self::fail($message ?: "Expected {$actual} > {$expected}");
        }
        self::pass();
    }

    public static function assertGreaterThanOrEqual($expected, $actual, $message = '') {
        if (!($actual >= $expected)) {
            self::fail($message ?: "Expected {$actual} >= {$expected}");
        }
        self::pass();
    }

    public static function assertInstanceOf($expected, $actual, $message = '') {
        if (!($actual instanceof $expected)) {
            self::fail($message ?: "Expected instance of {$expected}, got " . (is_object($actual) ? get_class($actual) : gettype($actual)));
        }
        self::pass();
    }

    public static function assertNotInstanceOf($expected, $actual, $message = '') {
        if ($actual instanceof $expected) {
            self::fail($message ?: "Unexpected instance of {$expected}");
        }
        self::pass();
    }

    public static function assertWPError($actual, $expected_code = null, $message = '') {
        if (!($actual instanceof WP_Error)) {
            self::fail($message ?: "Expected WP_Error, got " . (is_object($actual) ? get_class($actual) : gettype($actual)));
        }
        if ($expected_code !== null) {
            self::assertEqual($expected_code, $actual->get_error_code(), $message ?: "Wrong error code");
        }
        self::pass();
    }

    public static function assertNotWPError($actual, $message = '') {
        if ($actual instanceof WP_Error) {
            self::fail($message ?: "Unexpected WP_Error: " . $actual->get_error_code() . ' — ' . $actual->get_error_message());
        }
        self::pass();
    }

    public static function assertRegExp($pattern, $subject, $message = '') {
        if (!preg_match($pattern, $subject)) {
            self::fail($message ?: "Subject '{$subject}' does not match pattern '{$pattern}'");
        }
        self::pass();
    }

    public static function assertStringContains($needle, $haystack, $message = '') {
        if (strpos((string)$haystack, (string)$needle) === false) {
            self::fail($message ?: "Expected '{$needle}' in '{$haystack}'");
        }
        self::pass();
    }

    public static function assertStringStartsWith($prefix, $subject, $message = '') {
        if (strncmp($subject, $prefix, strlen($prefix)) !== 0) {
            self::fail($message ?: "Expected '{$subject}' to start with '{$prefix}'");
        }
        self::pass();
    }
}

// AssertionError polyfill for PHP < 7.0 (best-effort)
if (!class_exists('AssertionError')) {
    class AssertionError extends Exception {}
}
