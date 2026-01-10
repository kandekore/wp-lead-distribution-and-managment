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

// // Save the checkbox and message data
// function save_lead__api_resend_meta_box_data($post_id) {
//     // Verify the nonce to ensure the request is valid
//     if (!isset($_POST['lead_resend_meta_box_nonce']) || !wp_verify_nonce($_POST['lead_resend_meta_box_nonce'], 'save_lead_resend_meta_box_data')) {
//         return;
//     }

//     // Ensure it's not an autosave
//     if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
//         return;
//     }

//     // Check the user's permission
//     if (!current_user_can('edit_post', $post_id)) {
//         return;
//     }

//     // Save the checkbox
//     if (isset($_POST['lead_resend'])) {
//         update_post_meta($post_id, '_lead_resend_checked', '1');
//     } else {
//         update_post_meta($post_id, '_lead_resend_checked', '0');
//     }

//     // Save the message
//     if (isset($_POST['lead_resend_message'])) {
//         update_post_meta($post_id, '_lead_resend_message', sanitize_textarea_field($_POST['lead_resend_message']));
//     }
// }
// add_action('save_post', 'save_lead_resend_meta_box_data');

// // // Hook into the post save action to check if resend is triggered
// // function maybe_resend_lead($post_id) {
// //     // Only trigger for lead post type
// //     if (get_post_type($post_id) !== 'lead') {
// //         return;
// //     }

// //     // Check if the resend checkbox is checked
// //     $resend_checked = get_post_meta($post_id, '_lead_resend_checked', true);
// //     if ($resend_checked === '1') {
// //         // Send the lead to the API
// //         handle_resend_lead_action();
// //     }
// // }
// // add_action('save_post', 'maybe_resend_lead');

// // // Register the custom action for the resend lead link
// // add_action('admin_post_resend_lead', 'handle_resend_lead_action');

// // function handle_resend_lead_action() {
// //     // Check for valid nonce and user permissions
// //     if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'resend_lead_nonce')) {
// //         wp_die('Invalid request.');
// //     }

// //     // Get the lead ID from the request
// //     $lead_id = intval($_GET['lead_id']);

// //     // Fetch the lead data (from custom fields)
// //     $lead_data = get_post_meta($lead_id);

// //     if (!empty($lead_data)) {
// //         // Send lead data to the external API
// //         $response = resend_lead_to_api($lead_id, $lead_data);
        
// //         if (is_wp_error($response)) {
// //             wp_die('Failed to resend lead: ' . $response->get_error_message());
// //         } else {
// //             // Log the successful resend
// //             update_post_meta($lead_id, 'last_resend', current_time('mysql'));
// //             log_resend_count($lead_id);
            
// //             // Redirect back to the edit page with a success message
// //             wp_redirect(admin_url('post.php?post=' . $lead_id . '&action=edit&message=lead_resent'));
// //             exit;
// //         }
// //     } else {
// //         wp_die('Lead data not found.');
// //     }
// // }

// // Function to resend lead to the current site's API
// function resend_lead_to_api($lead_id, $lead_data) {
//     // Get the current site's URL dynamically
//     $api_url = home_url('/wp-json/lead-management/v1/submit-lead'); // Your custom endpoint URL

//     // Create a unique lead ID by prefixing the existing one with '2-'
//     $unique_leadid = '2-' . (isset($lead_data['leadid']) ? sanitize_text_field($lead_data['leadid'][0]) : '');

//     // Format the data you need to send to the API
//     $body = [
//         'leadid' => $unique_leadid,
//         'postcode' => isset($lead_data['postcode']) ? strtoupper(sanitize_text_field($lead_data['postcode'][0])) : '',
//         'vrg' => isset($lead_data['registration']) ? strtoupper(sanitize_text_field($lead_data['registration'][0])) : '',
//         'model' => isset($lead_data['model']) ? sanitize_text_field($lead_data['model'][0]) : '',
//         'date' => isset($lead_data['date']) ? sanitize_text_field($lead_data['date'][0]) : '',
//         'cylinder' => isset($lead_data['cylinder']) ? sanitize_text_field($lead_data['cylinder'][0]) : '',
//         'colour' => isset($lead_data['colour']) ? sanitize_text_field($lead_data['colour'][0]) : '',
//         'keepers' => isset($lead_data['keepers']) ? sanitize_text_field($lead_data['keepers'][0]) : '',
//         'contact' => isset($lead_data['contact']) ? sanitize_text_field($lead_data['contact'][0]) : '',
//         'email' => isset($lead_data['email']) ? sanitize_email($lead_data['email'][0]) : '',
//         'info' => isset($lead_data['info']) ? sanitize_textarea_field($lead_data['info'][0]) : '',
//         'fuel' => isset($lead_data['fuel']) ? sanitize_text_field($lead_data['fuel'][0]) : '',
//         'mot' => isset($lead_data['mot']) ? sanitize_text_field($lead_data['mot'][0]) : '',
//         'trans' => isset($lead_data['trans']) ? sanitize_text_field($lead_data['trans'][0]) : '',
//         'doors' => isset($lead_data['doors']) ? intval($lead_data['doors'][0]) : '',
//         'mot_due' => isset($lead_data['mot_due']) ? sanitize_text_field($lead_data['mot_due'][0]) : '',
//         'vin' => isset($lead_data['vin']) ? sanitize_text_field($lead_data['vin'][0]) : '',
//         'resend' => '1'  // Set resend to 1 to indicate this is a resent lead
//     ];

//     // Make the API request using wp_remote_post
//     $response = wp_remote_post($api_url, [
//         'method'    => 'POST',
//         'body'      => json_encode($body),
//         'headers'   => [
//             'Content-Type' => 'application/json',
//         ],
//     ]);

//     // Check if the request was successful
//     if (is_wp_error($response)) {
//         return new WP_Error('api_error', 'Failed to resend the lead');
//     }

//     // Log the resend action
//     log_lead_resend($unique_leadid);

//     return $response;
// }

// // Function to log the lead resend
// function log_lead_resend($leadid) {
//     $log_entry = sprintf("Lead ID %s was resent on %s", $leadid, date('Y-m-d H:i:s'));
//     error_log($log_entry);
// }

// // Log resends by updating the count
// function log_resend_count($lead_id) {
//     $current_count = (int) get_post_meta($lead_id, 'resend_count', true);
//     update_post_meta($lead_id, 'resend_count', $current_count + 1);
// }

// // Display admin notice after successful resend
// add_action('admin_notices', 'display_resend_success_notice');
// function display_resend_success_notice() {
//     if (isset($_GET['message']) && $_GET['message'] === 'lead_resent') {
//         echo '<div class="notice notice-success is-dismissible"><p>Lead has been successfully resent to the API.</p></div>';
//     }
// }

