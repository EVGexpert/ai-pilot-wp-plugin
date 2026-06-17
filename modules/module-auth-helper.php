<?php
/**
 * AI Pilot — Module Auth Helper
 *
 * JWT token validation, blacklist, and security logging.
 * Provides permission callbacks for modules that use the AIPILOT_Fluent_Auth pattern.
 * Also handles dynamic capability registration for the Fluent Suite.
 */

if (!defined('ABSPATH')) exit;

// ─── CONSTANTS ───────────────────────────────────────────────────────

/** Transient prefix for revoked token IDs */
define('AI_PILOT_REVOKED_PREFIX', 'aipilot_revoked_');

/** Maximum token age in days (tokens older than this are rejected) */
define('AI_PILOT_MAX_TOKEN_AGE_DAYS', 30);

// ─── JWT VALIDATION ──────────────────────────────────────────────────

/**
 * Verify a JWT token with full validation: signature, exp, iat, jti blacklist.
 *
 * Supports HS256 algorithm only (matching auth-api).
 * Falls back gracefully if JWT_SECRET is not configured.
 *
 * @param string $token Raw JWT string
 * @return object|false Decoded payload on success, false on failure
 */
function aipilot_verify_jwt_token($token) {
    // If no JWT secret configured, fall back to simple token hash check
    $jwt_secret = defined('AI_PILOT_JWT_SECRET') ? AI_PILOT_JWT_SECRET : '';
    if (empty($jwt_secret)) {
        return false;
    }

    try {
        // Decode and verify JWT (HS256)
        $decoded = aipilot_jwt_decode($token, $jwt_secret);
        if ($decoded === false) {
            aipilot_log_auth_failure('token_invalid', $token);
            return false;
        }

        $now = time();

        // ✅ Проверка срока действия (exp)
        if (isset($decoded->exp) && $decoded->exp < $now) {
            aipilot_log_auth_failure('token_expired', $token);
            return false;
        }

        // ✅ Проверка issued at — не из будущего (60 сек допуск на clock skew)
        if (isset($decoded->iat) && $decoded->iat > $now + 60) {
            aipilot_log_auth_failure('token_from_future', $token);
            return false;
        }

        // ✅ Проверка максимального возраста токена (не старше 30 дней)
        if (isset($decoded->iat) && ($now - $decoded->iat) > AI_PILOT_MAX_TOKEN_AGE_DAYS * 86400) {
            aipilot_log_auth_failure('token_too_old', $token);
            return false;
        }

        // ✅ Проверка jti (JWT ID) в blacklist
        if (isset($decoded->jti) && aipilot_is_token_revoked($decoded->jti)) {
            aipilot_log_auth_failure('token_revoked', $token);
            return false;
        }

        return $decoded;

    } catch (\Exception $e) {
        aipilot_log_auth_failure('token_invalid', $token);
        return false;
    }
}

/**
 * Decode and verify an HS256 JWT token using native PHP.
 *
 * No external dependencies required.
 *
 * @param string $token  Raw JWT string (header.payload.signature)
 * @param string $secret HMAC secret
 * @return object|false  Decoded payload on success, false on failure
 */
function aipilot_jwt_decode($token, $secret) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }

    list($header_b64, $payload_b64, $signature_b64) = $parts;

    // Verify algorithm is HS256
    $header_json = aipilot_jwt_base64url_decode($header_b64);
    if ($header_json === false) {
        return false;
    }
    $header = json_decode($header_json);
    if (!$header || !isset($header->alg) || $header->alg !== 'HS256') {
        return false;
    }

    // Verify signature
    $expected_signature = aipilot_jwt_base64url_encode(
        hash_hmac('sha256', $header_b64 . '.' . $payload_b64, $secret, true)
    );
    if (!hash_equals($expected_signature, $signature_b64)) {
        return false;
    }

    // Decode payload
    $payload_json = aipilot_jwt_base64url_decode($payload_b64);
    if ($payload_json === false) {
        return false;
    }

    $payload = json_decode($payload_json);
    if ($payload === null) {
        return false;
    }

    return $payload;
}

/**
 * Base64URL decode (RFC 4648).
 *
 * @param string $input Base64URL-encoded string
 * @return string|false Decoded string on success, false on failure
 */
function aipilot_jwt_base64url_decode($input) {
    $remainder = strlen($input) % 4;
    if ($remainder) {
        $input .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($input, '-_', '+/'), true);
}

/**
 * Base64URL encode (RFC 4648).
 *
 * @param string $input Binary string to encode
 * @return string Base64URL-encoded string (no padding)
 */
function aipilot_jwt_base64url_encode($input) {
    return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
}

// ─── TOKEN BLACKLIST (TRANSIENT-BASED) ───────────────────────────────

/**
 * Revoke a token by its jti (JWT ID).
 * Stores in WordPress transient with TTL matching max token age.
 *
 * @param string $jti JWT ID to revoke
 */
function aipilot_revoke_token($jti) {
    set_transient(
        AI_PILOT_REVOKED_PREFIX . $jti,
        true,
        AI_PILOT_MAX_TOKEN_AGE_DAYS * 86400
    );
}

/**
 * Check if a token's jti has been revoked.
 *
 * @param string $jti JWT ID to check
 * @return bool True if revoked
 */
function aipilot_is_token_revoked($jti) {
    return (bool) get_transient(AI_PILOT_REVOKED_PREFIX . $jti);
}

// ─── AUTH FAILURE LOGGING ────────────────────────────────────────────

/**
 * Log an authentication failure for SIEM / audit purposes.
 *
 * Fires `aipilot_auth_failure` action for external consumers.
 *
 * @param string $reason        Failure reason code (token_expired, token_revoked, etc.)
 * @param string $token_preview Optional raw token for preview (first 20 chars only)
 */
function aipilot_log_auth_failure($reason, $token_preview = '') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $preview = substr($token_preview, 0, 20) . '...';
    error_log("[AI Pilot] Auth failure: {$reason} from {$ip}, token: {$preview}");

    /**
     * Fires when an auth failure occurs.
     *
     * @param string $reason Failure reason code
     * @param string $ip     Client IP address
     */
    do_action('aipilot_auth_failure', $reason, $ip);
}

// ─── PERMISSION CHECKS ───────────────────────────────────────────────

/**
 * Auth helper class for Fluent Suite modules
 *
 * @deprecated 2.3.6 Direct capability checks are now used in routes.
 *             This class is maintained for backward compatibility only.
 *             Use aipilot_verify_token_and_can('capability_name') directly.
 */
class AIPILOT_Fluent_Auth {

    /**
     * Check read permission (legacy)
     * @deprecated 2.3.6 Use aipilot_verify_token_and_can('specific_capability') instead
     */
    public static function check_read() {
        _deprecated_function(__METHOD__, '2.3.6', 'aipilot_verify_token_and_can()');
        return aipilot_verify_token_and_can('fluent_read');
    }

    /**
     * Check write permission (legacy)
     * @deprecated 2.3.6 Use aipilot_verify_token_and_can('specific_capability') instead
     */
    public static function check_write() {
        _deprecated_function(__METHOD__, '2.3.6', 'aipilot_verify_token_and_can()');
        return aipilot_verify_token_and_can('fluent_write');
    }

    /**
     * Check manage permission (legacy)
     * @deprecated 2.3.6 Use aipilot_verify_token_and_can('specific_capability') instead
     */
    public static function check_manage() {
        _deprecated_function(__METHOD__, '2.3.6', 'aipilot_verify_token_and_can()');
        return aipilot_verify_token_and_can('fluent_manage');
    }

    /**
     * Check admin permission (legacy)
     * @deprecated 2.3.6 Use aipilot_verify_token_and_can('specific_capability') instead
     */
    public static function check_admin() {
        _deprecated_function(__METHOD__, '2.3.6', 'aipilot_verify_token_and_can()');
        return aipilot_verify_token_and_can('fluent_admin');
    }

    /**
     * Check specific capability (for granular permissions)
     * This method is NOT deprecated as it provides useful abstraction.
     *
     * @param string $capability The capability to check
     * @return bool
     */
    public static function check_capability($capability) {
        return aipilot_verify_token_and_can($capability);
    }
}

/**
 * Get module capability definitions with labels and groups.
 *
 * Each module should use the 'aipilot_module_capabilities' filter to register.
 *
 * @return array Array of [capability => ['label' => string, 'default' => bool, 'group' => string]]
 */
function aipilot_get_module_capabilities() {
    return apply_filters('aipilot_module_capabilities', []);
}

// Register Fluent Suite capabilities (legacy support)
add_filter('aipilot_default_capabilities', function($caps) {
    $module_caps = aipilot_get_module_capabilities();
    foreach ($module_caps as $cap => $info) {
        $caps[$cap] = $info['default'] ?? false;
    }
    return $caps;
});

// Register Fluent Suite module capabilities (generic fallback)
add_filter('aipilot_module_capabilities', function($caps) {
    return array_merge($caps, [
        'fluent_read' => ['label' => 'Read Fluent Data (All)', 'default' => true, 'group' => 'Fluent Suite'],
        'fluent_write' => ['label' => 'Write Fluent Data (All)', 'default' => false, 'group' => 'Fluent Suite'],
        'fluent_manage' => ['label' => 'Manage Fluent Data (All)', 'default' => false, 'group' => 'Fluent Suite'],
        'fluent_admin' => ['label' => 'Full Fluent Admin Access', 'default' => false, 'group' => 'Fluent Suite'],
    ]);
});
