<?php
// Create or modify Post-Pay role with Customer-like capabilities
add_action('init', function() {
    // Get the WooCommerce Customer role
    $customer_role = get_role('customer');
    
    // Add the Post-Pay role with the same capabilities as Customer
    if (!get_role('post_pay')) {
        add_role('post_pay', __('Post-Pay', 'text-domain'), $customer_role->capabilities);
    } else {
        // If the role already exists, sync its capabilities with Customer
        $post_pay_role = get_role('post_pay');
        foreach ($customer_role->capabilities as $cap => $grant) {
            $post_pay_role->add_cap($cap, $grant);
        }
    }
});
add_action('woocommerce_account_menu_items', function($items) {
    $user = wp_get_current_user();
    if (in_array('post_pay', $user->roles)) {
        // Ensure Post Pay users can access the account page
        return $items;
    }
    return $items;
});
// Add a lead reception toggle option in the user profile page
add_action('show_user_profile', 'add_lead_reception_option');
add_action('edit_user_profile', 'add_lead_reception_option');

function add_lead_reception_option($user) {
    // Only show this option for post-pay users
    if (in_array('post_pay', $user->roles)) {
        ?>
        <h3><?php _e("Post-Pay Lead Reception", "text-domain"); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="enable_lead_reception"><?php _e("Enable Lead Reception", "text-domain"); ?></label></th>
                <td>
                    <input type="checkbox" name="enable_lead_reception" id="enable_lead_reception" value="1" <?php checked(get_user_meta($user->ID, 'enable_lead_reception', true), '1'); ?> />
                    <label for="enable_lead_reception"><?php _e("Enable this user to receive leads", "text-domain"); ?></label>
                </td>
            </tr>
        </table>
        <?php
    }
}

add_action('personal_options_update', 'save_lead_reception_option');
add_action('edit_user_profile_update', 'save_lead_reception_option');

function save_lead_reception_option($user_id) {
    // Only save for post-pay users
    $user = get_userdata($user_id);
    if (in_array('post_pay', $user->roles)) {
        update_user_meta($user_id, 'enable_lead_reception', isset($_POST['enable_lead_reception']) ? '1' : '0');
    }
}
// Restrict Post-Pay users from accessing the WP Admin area
add_action('admin_init', function() {
    $user = wp_get_current_user();

    // Check if the current user has the "post_pay" role and is trying to access wp-admin
    if (in_array('post_pay', (array) $user->roles) && !defined('DOING_AJAX')) {
        // Redirect to the homepage or another page
        wp_redirect(home_url());
        exit;
    }
});

// Disable admin bar for Post-Pay users
add_action('after_setup_theme', function() {
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        if (in_array('post_pay', $user->roles)) {
            show_admin_bar(false); // Disable admin bar
        }
    }
});
