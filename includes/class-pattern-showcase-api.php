<?php
/**
 * Pattern Showcase REST API
 *
 * Provides REST API endpoints for Gutenberg block
 *
 * @package MockupGenerator
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MG_Pattern_Showcase_API {

    /**
     * REST API namespace
     */
    const NAMESPACE = 'mockup-generator/v1';

    /**
     * Register REST API routes
     */
    public static function register_routes() {
        // Get all showcases
        register_rest_route(self::NAMESPACE, '/pattern-showcases', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_showcases'),
            'permission_callback' => array(__CLASS__, 'check_permissions'),
        ));

        // Get single showcase
        register_rest_route(self::NAMESPACE, '/pattern-showcases/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_showcase'),
            'permission_callback' => array(__CLASS__, 'check_permissions'),
            'args' => array(
                'id' => array(
                    'required' => true,
                    'type' => 'string',
                ),
            ),
        ));
    }

    /**
     * Check permissions (allow authenticated users in editor context)
     */
    public static function check_permissions() {
        // Allow in REST API context (for block editor)
        return true;
    }

    /**
     * Get all showcases
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function get_showcases($request) {
        $showcases = MG_Pattern_Showcase_Manager::get_showcases();

        // Simplify data for dropdown
        $simplified = array();
        foreach ($showcases as $showcase) {
            $simplified[] = array(
                'id' => $showcase['id'],
                'name' => $showcase['name'],
                'layout' => $showcase['layout'],
                'mockup_count' => !empty($showcase['mockups']) ? count($showcase['mockups']) : 0,
            );
        }

        return rest_ensure_response(array(
            'showcases' => $showcases,
            'simplified' => $simplified,
        ));
    }

    /**
     * Get single showcase with preview data
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function get_showcase($request) {
        $id = $request->get_param('id');
        $showcase = MG_Pattern_Showcase_Manager::get_showcase($id);

        if (!$showcase) {
            return new WP_Error('showcase_not_found', __('Showcase not found', 'mockup-generator'), array('status' => 404));
        }

        // Add mockup URLs for preview
        $mockup_urls = array();
        if (!empty($showcase['mockups'])) {
            foreach ($showcase['mockups'] as $key => $attachment_id) {
                $mockup_urls[$key] = wp_get_attachment_image_url($attachment_id, 'medium');
            }
        }

        $showcase['mockup_urls'] = $mockup_urls;

        return rest_ensure_response(array(
            'showcase' => $showcase,
        ));
    }
}
