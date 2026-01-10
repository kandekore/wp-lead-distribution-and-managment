<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Register custom REST API endpoints
add_action('rest_api_init', function () {
    register_rest_route('lead-management/v1', '/submit-lead', array(
        'methods' => 'GET',
        'callback' => 'process_lead_submission',
        'permission_callback' => '__return_true',

    ));
});

