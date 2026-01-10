<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * =================================================================================
 * HELPER FUNCTIONS (DEFINED FIRST TO PREVENT CRITICAL ERRORS)
 * =================================================================================
 */

/**
 * Helper function to get the currently active SMS provider URL from the database.
 * @return string The active provider URL (e.g., '@txtlocal.co.uk') or empty string if none is set.
 */
function wc_custom_get_active_sms_provider_url() {
    $options = get_option( 'wc_sms_providers' );
    $active_key = isset( $options['active'] ) ? $options['active'] : '';
    $providers = isset( $options['providers'] ) ? $options['providers'] : array();

    if ( ! empty( $active_key ) && isset( $providers[ $active_key ] ) ) {
        return $providers[ $active_key ]['url'];
    }

    return ''; // Return empty if no active provider is found
}

/**
 * Sends a plain-text SMS message to a user using the dynamically configured provider.
 *
 * @param int    $user_id The ID of the user to send the SMS to.
 * @param string $subject The subject of the SMS (may be used by some gateways).
 * @param string $message The plain-text message to send.
 * @return bool True on success, false on failure.
 */
function send_dynamic_sms_notification( $user_id, $subject, $message ) {
    // Get the dynamically configured SMS provider URL
    $sms_provider_url = wc_custom_get_active_sms_provider_url();

    // Abort if no provider is set up in the settings
    if ( empty( $sms_provider_url ) ) {
        error_log( 'SMS sending failed: No active SMS provider is configured.' );
        return false;
    }

    // Get the user's phone number from their billing details
    $user_phone = get_user_meta( $user_id, 'billing_phone', true );

    // Abort if the user doesn't have a phone number
    if ( empty( $user_phone ) ) {
        error_log( 'SMS sending failed: User ' . $user_id . ' has no phone number.' );
        return false;
    }

    // Construct the final email-to-sms address
    $sms_to = $user_phone . $sms_provider_url;

    // Send the plain-text email
    wp_mail( $sms_to, $subject, $message );

    error_log( 'SMS notification sent to user ' . $user_id . ' via ' . $sms_to );

    return true;
}


/**
 * =================================================================================
 * MAIN PLUGIN FUNCTIONS
 * =================================================================================
 */

add_action('init', 'register_lead_post_type');
function register_lead_post_type() {
    $args = [
        'public' => false,
        'label'  => 'Leads',
        'show_ui' => true,
        'capability_type' => 'post',
        'hierarchical' => false,
        'supports' => ['title', 'editor', 'custom-fields'],
        'menu_icon' => 'dashicons-email',
    ];
    register_post_type('lead', $args);
}


function store_lead($lead_data, $user_id) {
    $user_info = get_userdata($user_id);
    $user_display_name = $user_info ? $user_info->display_name : 'Unknown User';
    // Prepare post data
    $post_data = [
        'post_title'    => wp_strip_all_tags($lead_data['registration'] . ' -  ' . $lead_data['model'] ),
        'post_content'  => wp_json_encode($lead_data),
        'post_status'   => 'publish',
        'post_type'     => 'lead',
        'post_author'   => $user_id,
        'meta_input' => [
            'postcode' => $lead_data['postcode'],
            'registration' => $lead_data['registration'],
            'model' => $lead_data['model'],
            'date' => $lead_data['date'],
            'cylinder' => $lead_data['cylinder'],
            'colour' => $lead_data['colour'],
            'keepers' => $lead_data['keepers'],
            'contact' => $lead_data['contact'],
            'email' => $lead_data['email'],
            'fuel' => $lead_data['fuel'],
            'mot' => $lead_data['mot'],
            'transmission' => $lead_data['trans'],
            'doors' => $lead_data['doors'],
            'mot_due' => $lead_data['mot_due'],
            'leadid' => $lead_data['leadid'],
            'vin' => $lead_data['vin'],
            'resend' => $lead_data['resend'],
            'submission_url' => $lead_data['submission_url'],
            'source_domain' => $lead_data['source_domain'],
            'ip_address' => $lead_data['ip_address'],
            'campaign_id' => $lead_data['campaign_id'], // Campaign ID (from vt_campaign)
            'vt_campaign' => $lead_data['vt_campaign'],   // Store vt_campaign directly
            'utm_source'  => $lead_data['utm_source'],    // Store utm_source directly
            'vt_keyword'  => $lead_data['vt_keyword'],    // Store vt_keyword directly
            'vt_adgroup'  => $lead_data['vt_adgroup'],    // Store vt_adgroup directly
            'milage' => $lead_data['milage'],
        ],
    ];

    $post_id = wp_insert_post($post_data);
    return $post_id;

    if (is_wp_error($post_id)) {
        error_log('Failed to store lead: ' . $post_id->get_error_message());
    }
}

add_action('restrict_manage_posts', 'custom_lead_filters', 10, 2);
function custom_lead_filters($post_type, $which) {
    if ('lead' !== $post_type) {
        return;
    }

    // Date filter dropdown
    ?>
    <select name="lead_date_filter">
        <option value=""><?php _e('All Dates'); ?></option>
        <option value="today" <?php selected(isset($_GET['lead_date_filter']), 'today'); ?>><?php _e('Today'); ?></option>
        <option value="yesterday" <?php selected(isset($_GET['lead_date_filter']), 'yesterday'); ?>><?php _e('Yesterday'); ?></option>
        <option value="this_week" <?php selected(isset($_GET['lead_date_filter']), 'this_week'); ?>><?php _e('This Week'); ?></option>
        <option value="last_week" <?php selected(isset($_GET['lead_date_filter']), 'last_week'); ?>><?php _e('Last Week'); ?></option>
        <option value="this_month" <?php selected(isset($_GET['lead_date_filter']), 'this_month'); ?>><?php _e('This Month'); ?></option>
        <option value="last_month" <?php selected(isset($_GET['lead_date_filter']), 'last_month'); ?>><?php _e('Last Month'); ?></option>
    </select>
    <?php

    // Assuming 'assigned_user' corresponds to WP user IDs
    wp_dropdown_users([
        'show_option_all' => __('All Agents'),
        'name' => 'assigned_user',
        'selected' => isset($_GET['assigned_user']) ? $_GET['assigned_user'] : '',
    ]);

    // Add postcode search input
    ?>
    <input type="text" name="lead_postcode_search" placeholder="<?php _e('Search Postcode prefix...'); ?>" value="<?php echo isset($_GET['lead_postcode_search']) ? esc_attr($_GET['lead_postcode_search']) : ''; ?>">
    <?php

    // Submit button for the filters
    submit_button(__('Filter'), null, 'filter_action', false);
}

add_action('pre_get_posts', 'filter_leads_by_custom_filters');
function filter_leads_by_custom_filters($query) {
    global $pagenow;

    if (is_admin() && 'edit.php' === $pagenow && 'lead' === $query->query['post_type'] && $query->is_main_query()) {
        $meta_query = [];
        $date_query_args = [];

        $custom_filter_active = !empty($_GET['lead_date_filter']) || !empty($_GET['assigned_user']) || !empty($_GET['lead_postcode_search']);

        if ($custom_filter_active) {
            $query->set('s', '');
            $query->set('m', '');
        }

        if (!empty($_GET['assigned_user'])) {
            $meta_query[] = [
                'key' => 'assigned_user',
                'value' => $_GET['assigned_user'],
                'compare' => '='
            ];
        }

        if (!empty($meta_query)) {
            if (count($meta_query) > 1) {
                $meta_query['relation'] = 'AND';
            }
            $query->set('meta_query', $meta_query);
        }

        if (!empty($_GET['lead_date_filter'])) {
            $start_of_week = get_option('start_of_week', 0);
            $current_day_of_week = (int) wp_date('w');

            switch ($_GET['lead_date_filter']) {
                case 'today':
                    $today_start = wp_date('Y-m-d 00:00:00');
                    $today_end = wp_date('Y-m-d 23:59:59');
                    $date_query_args = ['after' => $today_start, 'before' => $today_end, 'inclusive' => true];
                    break;
                case 'yesterday':
                    $yesterday_start = wp_date('Y-m-d 00:00:00', strtotime('-1 day'));
                    $yesterday_end = wp_date('Y-m-d 23:59:59', strtotime('-1 day'));
                    $date_query_args = ['after' => $yesterday_start, 'before' => $yesterday_end, 'inclusive' => true];
                    break;
                case 'this_week':
                    $days_since_start_of_week = ( $current_day_of_week - $start_of_week + 7 ) % 7;
                    $startOfWeek = wp_date('Y-m-d', strtotime('-' . $days_since_start_of_week . ' days'));
                    $endOfWeek = wp_date('Y-m-d', strtotime($startOfWeek . ' +6 days'));
                    $date_query_args = ['after' => $startOfWeek . ' 00:00:00', 'before' => $endOfWeek . ' 23:59:59', 'inclusive' => true];
                    break;
                case 'last_week':
                    $days_since_start_of_week = ( $current_day_of_week - $start_of_week + 7 ) % 7;
                    $startOfThisWeek = wp_date('Y-m-d', strtotime('-' . $days_since_start_of_week . ' days'));
                    $startOfLastWeek = wp_date('Y-m-d', strtotime($startOfThisWeek . ' -7 days'));
                    $endOfLastWeek = wp_date('Y-m-d', strtotime($startOfThisWeek . ' -1 day'));
                    $date_query_args = ['after' => $startOfLastWeek . ' 00:00:00', 'before' => $endOfLastWeek . ' 23:59:59', 'inclusive' => true];
                    break;
                case 'this_month':
                    $start_of_month = wp_date('Y-m-01');
                    $end_of_month = wp_date('Y-m-t 23:59:59');
                    $date_query_args = ['after' => $start_of_month . ' 00:00:00', 'before' => $end_of_month, 'inclusive' => true];
                    break;
                case 'last_month':
                    $start_of_last_month = wp_date('Y-m-01', strtotime('first day of last month'));
                    $end_of_last_month = wp_date('Y-m-t 23:59:59', strtotime('last day of last month'));
                    $date_query_args = ['after' => $start_of_last_month . ' 00:00:00', 'before' => $end_of_last_month, 'inclusive' => true];
                    break;
            }
            if (!empty($date_query_args)) {
                $query->set('date_query', [$date_query_args]);
            }
        }

        if (isset($query->query_vars['orderby']) && 'postcode' === $query->query_vars['orderby']) {
            $query->set('meta_key', 'postcode');
            $query->set('orderby', 'meta_value');
        }
    }
}

add_filter('posts_where', 'lead_postcode_search_where', 10, 2);
function lead_postcode_search_where($where, $query) {
    global $wpdb;

    if (is_admin() && $query->is_main_query() && isset($query->query_vars['post_type']) && $query->query_vars['post_type'] === 'lead') {
        if (!empty($_GET['lead_postcode_search'])) {
            $search_term = sanitize_text_field($_GET['lead_postcode_search']);
            $escaped_search_term = $wpdb->esc_like($search_term);
            $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = {$wpdb->posts}.ID AND meta_key = 'postcode' AND meta_value LIKE '{$escaped_search_term}%')";
        }
    }
    return $where;
}

function apply_date_filter(&$query, $filter_value) {
    $date_query = [];
    $start_of_week = get_option('start_of_week', 0);
    $current_day_of_week = date('w');

    switch ($filter_value) {
        case 'today':
            $date_query = ['year' => date('Y'), 'month' => date('m'), 'day' => date('d')];
            break;
        case 'yesterday':
            $yesterday = strtotime('-1 day');
            $date_query = ['year' => date('Y', $yesterday), 'month' => date('m', $yesterday), 'day' => date('d', $yesterday)];
            break;
        case 'this_week':
            $days_since_start_of_week = ( $current_day_of_week - $start_of_week + 7 ) % 7;
            $startOfWeek = date('Y-m-d', strtotime('-' . $days_since_start_of_week . ' days'));
            $endOfWeek = date('Y-m-d', strtotime($startOfWeek . ' +6 days'));
            $date_query = ['after' => $startOfWeek, 'before' => $endOfWeek, 'inclusive' => true];
            break;
        case 'last_week':
            $days_since_start_of_week = ( $current_day_of_week - $start_of_week + 7 ) % 7;
            $startOfThisWeek = date('Y-m-d', strtotime('-' . $days_since_start_of_week . ' days'));
            $startOfLastWeek = date('Y-m-d', strtotime($startOfThisWeek . ' -7 days'));
            $endOfLastWeek = date('Y-m-d', strtotime($startOfThisWeek . ' -1 day'));
            $date_query = ['after' => $startOfLastWeek, 'before' => $endOfLastWeek, 'inclusive' => true];
            break;
    }

    if ($date_query) {
        $query->set('date_query', [$date_query]);
    }
}

function enqueue_admin_scripts() {
    global $pagenow, $typenow;

    if ( $pagenow == 'edit.php' && $typenow == 'lead' ) {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $("select[name='m'] option[value='0']").text('By Months');
                $("#filter_action").hide();
            });
        </script>
        <?php
    }
}
add_action('admin_footer', 'enqueue_admin_scripts');

add_filter('manage_lead_posts_columns', 'add_custom_lead_columns');
function add_custom_lead_columns($columns) {
    $columns['leadid'] = __('Lead ID');
    $columns['postcode'] = __('Postcode');
    $columns['vin'] = __('VIN');
    $columns['post_author'] = __('Agent');
    return $columns;
}

add_action('manage_lead_posts_custom_column', 'custom_lead_column_content', 10, 2);
function custom_lead_column_content($column_name, $post_id) {
    switch ($column_name) {
        case 'leadid':
            echo get_post_meta($post_id, 'leadid', true);
            break;
        case 'postcode':
            echo get_post_meta($post_id, 'postcode', true);
            break;
        case 'vin':
            echo get_post_meta($post_id, 'vin', true);
            break;
        case 'post_author':
            $author_id = get_post_field('post_author', $post_id);
            $author = get_user_by('id', $author_id);
            echo $author ? $author->user_login : __('Unknown');
            break;
    }
}

add_filter('posts_search', 'search_lead_id_in_admin', 10, 2);
function search_lead_id_in_admin($search, $wp_query) {
    global $wpdb;
    if (!is_admin() || !$wp_query->is_search || !isset($wp_query->query['post_type']) || 'lead' != $wp_query->query['post_type']) {
        return $search;
    }

    $search_terms = $wpdb->_escape($wp_query->query_vars['s']);
    if (empty($search_terms)) return $search;

    $search = " AND ($wpdb->posts.post_title LIKE '%$search_terms%' OR $wpdb->posts.post_content LIKE '%$search_terms%' OR EXISTS (SELECT * FROM $wpdb->postmeta WHERE post_id = $wpdb->posts.ID AND meta_key = 'leadid' AND meta_value LIKE '%$search_terms%'))";
    return $search;
}

add_action('add_meta_boxes', 'add_lead_resend_meta_box');
function add_lead_resend_meta_box() {
    add_meta_box('lead_resend_meta_box', 'Resend Lead', 'render_lead_resend_meta_box', 'lead', 'side', 'default');
}

function render_lead_resend_meta_box($post) {
    $resend_message = get_post_meta($post->ID, '_lead_resend_message', true);
    $resend_checked = get_post_meta($post->ID, '_lead_resend_checked', true);
    $resend_count = (int)get_post_meta($post->ID, '_lead_resend_count', true);
    ?>
    <label for="lead_resend"><input type="checkbox" name="lead_resend" id="lead_resend" value="1" <?php checked($resend_checked, '1'); ?>> Resend this lead</label>
    <br><br>
    <label for="lead_resend_message">Resend Message</label><br>
    <textarea name="lead_resend_message" id="lead_resend_message" rows="4" style="width:100%;"><?php echo esc_textarea($resend_message); ?></textarea>
    <br><br>
    <?php if ($resend_count > 0) : ?>
        <p><strong><?php echo esc_html($resend_count); ?></strong> <?php echo _n('resend', 'resends', $resend_count, 'text-domain'); ?> have been made for this lead.</p>
    <?php endif; ?>
    <?php
    wp_nonce_field('save_lead_resend_meta_box_data', 'lead_resend_meta_box_nonce');
}

add_action('save_post', 'save_lead_resend_meta_box_data');
function save_lead_resend_meta_box_data($post_id) {
    if (!isset($_POST['lead_resend_meta_box_nonce']) || !wp_verify_nonce($_POST['lead_resend_meta_box_nonce'], 'save_lead_resend_meta_box_data') || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) {
        return;
    }
    update_post_meta($post_id, '_lead_resend_checked', isset($_POST['lead_resend']) ? '1' : '0');
    if (isset($_POST['lead_resend_message'])) {
        update_post_meta($post_id, '_lead_resend_message', sanitize_textarea_field($_POST['lead_resend_message']));
    }
}

add_action('save_post', 'maybe_resend_lead');
function maybe_resend_lead($post_id) {
    if (get_post_type($post_id) !== 'lead' || get_post_meta($post_id, '_lead_resend_checked', true) !== '1') {
        return;
    }

    $lead_owner_id = get_post_field('post_author', $post_id);
    $resend_message = get_post_meta($post_id, '_lead_resend_message', true);

    $lead_data = [
        'leadid' => get_post_meta($post_id, 'leadid', true),
        'registration' => get_post_meta($post_id, 'registration', true),
        'model' => get_post_meta($post_id, 'model', true),
        'keepers' => get_post_meta($post_id, 'keepers', true),
        'contact' => get_post_meta($post_id, 'contact', true),
        'resend_message' => $resend_message,
    ];

    if (resend_lead_email_to_user($lead_owner_id, $lead_data)) {
        update_post_meta($post_id, '_lead_resent', '1');
        update_post_meta($post_id, '_lead_resend_checked', '0');
        $resend_count = (int)get_post_meta($post_id, '_lead_resend_count', true);
        update_post_meta($post_id, '_lead_resend_count', $resend_count + 1);
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('The lead has been successfully resent via email and SMS.', 'text-domain') . '</p></div>';
        });
    } else {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error is-dismissible"><p>' . __('Failed to resend the lead.', 'text-domain') . '</p></div>';
        });
    }
}

function resend_lead_email_to_user($user_id, $lead_data) {
    error_log(print_r($lead_data, true));
    $user_info = get_userdata($user_id);
    $to = $user_info->user_email;
    $subject = "Resend Lead: " . $lead_data['leadid'];
    $keepers = isset($lead_data['keepers']) ? $lead_data['keepers'] : get_post_meta($lead_data['ID'], 'keepers', true);
    $contact = isset($lead_data['contact']) ? $lead_data['contact'] : get_post_meta($lead_data['ID'], 'contact', true);

    // 1. SEND THE MAIN HTML EMAIL
    $body = "<html><body><h3>Customer Callback or Message Regarding Lead " . esc_html($lead_data['leadid']) . "</h3>";
    if (isset($lead_data['registration']) && isset($lead_data['model'])) {
        $body .= "<h4>". esc_html($lead_data['registration']) . " - " . esc_html($lead_data['model']) . "</h4>";
    }
    if ($keepers) $body .= "<p><strong>Name:</strong> " . esc_html($keepers) . "</p>";
    if ($contact) $body .= "<p><strong>Contact:</strong> " . esc_html($contact) . "</p>";
    if (isset($lead_data['resend_message']) && !empty($lead_data['resend_message'])) {
        $body .= "<p><strong>Message:</strong> " . esc_html($lead_data['resend_message']) . "</p>";
    }
    $body .= "</body></html>";
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $email_sent = wp_mail($to, $subject, $body, $headers);

    // 2. SEND THE SEPARATE PLAIN TEXT SMS
    $sms_subject = "Message on Lead: " . $lead_data['registration'];
    $sms_body = "Message on Lead " . $lead_data['leadid'] . "\n";
    $sms_body .= "Name: " . $keepers . "\n";
    $sms_body .= "Contact: " . $contact . "\n";
    if (isset($lead_data['resend_message']) && !empty($lead_data['resend_message'])) {
        $sms_body .= "Message: " . $lead_data['resend_message'];
    }
    send_dynamic_sms_notification($user_id, $sms_subject, $sms_body);

    return $email_sent;
}

add_action('show_user_profile', 'add_lead_priority_checkbox');
add_action('edit_user_profile', 'add_lead_priority_checkbox');
function add_lead_priority_checkbox($user) {
    ?>
    <h3>Lead Reception Priority</h3>
    <table class="form-table">
        <tr>
            <th><label for="lead_priority">Increase Lead Reception Probability</label></th>
            <td>
                <input type="checkbox" name="lead_priority" id="lead_priority" value="1" <?php checked(get_user_meta($user->ID, 'lead_priority', true), '1'); ?> />
                <span class="description">Check this box to increase the probability of receiving leads.</span>
            </td>
        </tr>
    </table>
    <?php
}

add_action('personal_options_update', 'save_lead_priority_checkbox');
add_action('edit_user_profile_update', 'save_lead_priority_checkbox');
function save_lead_priority_checkbox($user_id) {
    if (current_user_can('edit_user', $user_id)) {
        update_user_meta($user_id, 'lead_priority', isset($_POST['lead_priority']) ? '1' : '0');
    }
}

add_shortcode( 'my_account_link', 'my_account_link_shortcode' );
function my_account_link_shortcode() {
    if (is_user_logged_in()) {
        return '<div class="my-account-link" style="text-align: center;"><a href="/my-account" class="button">My Account</a></div>';
    }
    return '';
}

add_action('template_redirect', 'force_redirect_to_checkout');
function force_redirect_to_checkout() {
    if (isset($_GET['redirect_to']) && $_GET['redirect_to'] === 'checkout') {
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }
}

add_action('woocommerce_before_checkout_form', 'woocommerce_output_all_notices', 10);

add_action('woocommerce_add_to_cart', 'apply_coupon_code_from_url', 10, 6);
function apply_coupon_code_from_url($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
    if (isset($_GET['coupon_code'])) {
        $coupon_code = sanitize_text_field($_GET['coupon_code']);
        if (!WC()->cart->has_discount($coupon_code)) {
            WC()->cart->apply_coupon($coupon_code);
            wc_clear_notices();
            wc_add_notice(sprintf('Coupon code "%s" has been applied to your order.', esc_html($coupon_code)), 'success');
        }
    }
}

add_filter('manage_edit_lead_sortable_columns', 'make_postcode_column_sortable');
function make_postcode_column_sortable($columns) {
    $columns['postcode'] = 'postcode';
    return $columns;
}