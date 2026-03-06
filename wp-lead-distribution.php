<?php
/**
 * Plugin Name: WordPress Lead Distribution
 * Description: Collects and distributes leads to users on a subscription basis.
 * Version: 5.0.0
 * Author: D.Kandekore
 */

 if ( ! defined( 'ABSPATH' ) ) exit;    

// Define plugin directory path constant
define('LMP_PLUGIN_DIR', plugin_dir_path(__FILE__));

// Include the files

include_once plugin_dir_path(__FILE__) . 'includes/api-endpoints.php';
include_once plugin_dir_path(__FILE__) . 'includes/lead-processing.php';
include_once plugin_dir_path(__FILE__) . 'includes/utility-functions.php';
include_once plugin_dir_path(__FILE__) . 'admin/post-pay-user.php';
include_once plugin_dir_path(__FILE__) . 'includes/load-postcodes.php';
include_once plugin_dir_path(__FILE__) . 'admin/user-signup.php';
include_once plugin_dir_path(__FILE__) . 'admin/user-backend.php';
include_once plugin_dir_path(__FILE__) . 'admin/customer-account-page.php';
include_once plugin_dir_path(__FILE__) . 'products/credits.php';
include_once plugin_dir_path(__FILE__) . 'products/lead-distribution.php';
include_once plugin_dir_path(__FILE__) . 'products/product-meta.php';
include_once plugin_dir_path(__FILE__) . 'admin/admin-pages.php';
include_once plugin_dir_path(__FILE__) . 'admin/reports-dashboard.php';
include_once plugin_dir_path(__FILE__) . 'admin/resend.php';


register_deactivation_hook(__FILE__, 'clear_saved_postcode_data');

register_activation_hook(__FILE__, 'my_plugin_activate');
function my_plugin_activate() {
    $default_postcode_areas = load_postcode_areas_from_json(); // Load default postcodes
    if (!get_option('custom_postcode_areas')) {
        update_option('custom_postcode_areas', wp_json_encode($default_postcode_areas));
    }
}


function clear_saved_postcode_data() {
    delete_option('custom_postcode_areas');
}
function enqueue_my_account_script() {
    if (is_account_page()) {
        wp_enqueue_script('my-account-custom-script', plugins_url('js/my-account-script.js', __FILE__), array('jquery'), '1.0', true);
        
        // Optionally localize script to pass PHP data to JS
        wp_localize_script('my-account-custom-script', 'myAccountVars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            // Other data you might want to pass to your script
        ));
    }
}

add_action('wp_enqueue_scripts', 'enqueue_my_account_script');

add_action( 'woocommerce_subscription_payment_complete', 'wc_custom_credit_disable_scheduled_renewal' );

function wc_custom_credit_disable_scheduled_renewal( $subscription ) {
    // We set the next payment date to an empty string.
    // This effectively "pauses" the subscription's internal clock.
    // $subscription->update_dates( array( 'next_payment' => '' ) );
 $far_future_date = date( 'Y-m-d H:i:s', strtotime( '+10 years' ) );
    
    $subscription->update_dates( array( 'next_payment' => $far_future_date ) );
}