<?php
namespace AIPILOT\RemoteApi\Handlers;
class SystemHandler {
    public static function register_routes() {
        $ns = \AIPILOT\RemoteApi\Core\Plugin::API_NAMESPACE;
        register_rest_route($ns, '/site', [
            'methods' => 'GET',
            'callback' => [self::class, 'get_site_info'],
            'permission_callback' => function() { return \AIPILOT\RemoteApi\Auth\Guard::verify_token_and_can('site_info'); }
        ]);
    }
    public static function get_site_info() {
        return [
            'name' => get_bloginfo('name'),
            'url' => get_bloginfo('url'),
            'version' => get_bloginfo('version'),
            'api_version' => \AIPILOT\RemoteApi\Core\Plugin::VERSION
        ];
    }
}
