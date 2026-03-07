<?php

if ( ! defined( 'ABSPATH' ) ) exit;    

// Add meta box to lead post type
function add_api_resend_meta_box() {
    if (post_type_exists('lead')) {
        add_meta_box(
            'lead_duplicate_meta_box',
            'Duplicate Lead',
            'render_api_resend_meta_box',
            'lead',
            'side', // Display this meta box on the side
            'default'
        );
    } else {
        error_log('Lead post type does not exist.');
    }
}
add_action('add_meta_boxes', 'add_api_resend_meta_box');

// Render the meta box
function render_api_resend_meta_box($post) {
    $lead_data = get_post_meta($post->ID);

    // Create a URL with the lead data to pass to the API
    $api_url = home_url('/wp-json/lead-management/v1/submit-lead');

    $params = [
        'leadid' => '2-' . (isset($lead_data['leadid'][0]) ? esc_attr($lead_data['leadid'][0]) : ''),
        'postcode' => isset($lead_data['postcode'][0]) ? esc_attr($lead_data['postcode'][0]) : '',
        'vrg' => isset($lead_data['registration'][0]) ? esc_attr($lead_data['registration'][0]) : '',
        'model' => isset($lead_data['model'][0]) ? esc_attr($lead_data['model'][0]) : '',
        'date' => isset($lead_data['date'][0]) ? esc_attr($lead_data['date'][0]) : '',
        'cylinder' => isset($lead_data['cylinder'][0]) ? esc_attr($lead_data['cylinder'][0]) : '',
        'colour' => isset($lead_data['colour'][0]) ? esc_attr($lead_data['colour'][0]) : '',
        'keepers' => isset($lead_data['keepers'][0]) ? esc_attr($lead_data['keepers'][0]) : '',
        'contact' => isset($lead_data['contact'][0]) ? esc_attr($lead_data['contact'][0]) : '',
        'email' => isset($lead_data['email'][0]) ? esc_attr($lead_data['email'][0]) : '',
        'info' => isset($lead_data['info'][0]) ? esc_attr($lead_data['info'][0]) : '',
        'fuel' => isset($lead_data['fuel'][0]) ? esc_attr($lead_data['fuel'][0]) : '',
        'mot' => isset($lead_data['mot'][0]) ? esc_attr($lead_data['mot'][0]) : '',
        'trans' => isset($lead_data['trans'][0]) ? esc_attr($lead_data['trans'][0]) : '',
        'doors' => isset($lead_data['doors'][0]) ? intval($lead_data['doors'][0]) : '',
        'mot_due' => isset($lead_data['mot_due'][0]) ? esc_attr($lead_data['mot_due'][0]) : '',
        'vin' => isset($lead_data['vin'][0]) ? esc_attr($lead_data['vin'][0]) : '',
        'resend' => '1'
    ];

    // Generate the URL with the query parameters
    $query_url = add_query_arg($params, $api_url);

    // Display the button
    echo '<p><a href="' . esc_url($query_url) . '" target="_blank" class="button-primary">Resend Lead to API</a></p>';

}
