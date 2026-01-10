<?php

add_action('woocommerce_after_order_notes', 'add_custom_postcode_selection_to_checkout');
function add_custom_postcode_selection_to_checkout($checkout) {
    // Fetch saved postcode areas from options as the default set
    $saved_postcode_areas = json_decode(get_option('custom_postcode_areas'), true);

    // If the user is logged in, try to get their previously selected postcodes from user meta
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $user_selected_postcode_areas = json_decode(get_user_meta($user_id, 'selected_postcode_areas', true), true);
        // If there are selections, use them instead of the default
        if (!empty($user_selected_postcode_areas)) {
            $saved_postcode_areas = $user_selected_postcode_areas;
        }
    }

    if (!is_array($saved_postcode_areas) || empty($saved_postcode_areas)) {
        echo '<p>No postcode areas available for selection.</p>';
        return;
    }

    echo '<div id="custom_postcode_selection"><h3>' . __('Select Postcode Areas') . '</h3>';

    foreach ($saved_postcode_areas as $region => $codes) {
        echo "<h4>" . esc_html($region) . "</h4>";
        echo "<label><input type='checkbox' class='select-all' data-region='" . esc_attr($region) . "'> " . __('Select All in ') . esc_html($region) . "</label><br>";
    
        foreach ($codes as $code) {
            $field_id = 'postcode_area_' . sanitize_title($code); // ID for individual checkboxes
            $field_name = 'postcode_areas[' . esc_attr($region) . '][]'; // Name attribute structured for array input
            
            // Check if this code should be checked based on user's saved selections
            $is_checked = in_array($code, $user_selected_postcode_areas[$region] ?? []) ? 'checked' : '';

            echo '<label for="' . esc_attr($field_id) . '" class="checkbox ' . esc_attr($region) . '">';
            // Add data-region attribute to each checkbox for accurate JS targeting and check if it should be prepopulated
            echo '<input type="checkbox" id="' . esc_attr($field_id) . '" name="' . $field_name . '" value="' . esc_attr($code) . '" class="postcode-area-checkbox ' . esc_attr($region) . '" data-region="' . esc_attr($region) . '" ' . $is_checked . '> ';
            echo esc_html($code);
            echo '</label><br>';
        }
    }
    echo '</div>';
}


// add_action('woocommerce_checkout_update_order_meta', 'save_custom_postcode_selection');
add_action('woocommerce_checkout_update_order_meta', 'save_custom_postcode_selection');
function save_custom_postcode_selection($order_id) {
    // Directly access $_POST data for selected postcode areas
    if (isset($_POST['postcode_areas']) && is_array($_POST['postcode_areas'])) {
        $selected_areas = [];
        
        // Iterate over submitted postcode area selections
        foreach ($_POST['postcode_areas'] as $region => $codes) {
            if (is_array($codes)) { // Ensure $codes is an array
                foreach ($codes as $code) {
                    $selected_areas[$region][] = sanitize_text_field($code);
                }
            }
        }
        
        // Save sanitized postcode areas to order meta
        update_post_meta($order_id, 'selected_postcode_areas', json_encode($selected_areas));
        
        // Get the customer ID associated with the order
        $order = wc_get_order($order_id);
        $customer_id = $order->get_customer_id();
        
        // Only proceed if a customer ID is found
        if ($customer_id) {
            // Additionally, save to user meta
            update_user_meta($customer_id, 'selected_postcode_areas', json_encode($selected_areas));
        }
    }
}



add_action('wp_footer', 'postcode_selection_scripts');
function postcode_selection_scripts() {
    if (is_checkout()) {
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.select-all').forEach(function(selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            let region = this.getAttribute('data-region');
            let checkboxes = document.querySelectorAll('.postcode-area-checkbox[data-region="' + region + '"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = selectAllCheckbox.checked;
            });
        });
    });
});
</script>
<?php
    }
}

add_action('woocommerce_after_order_notes', 'add_reset_postcodes_button_checkout');
function add_reset_postcodes_button_checkout() {
    // Check if the user is logged in to show the reset button
    if (is_user_logged_in()) {
        // The URL to which the request is sent, adding a nonce for security
        $reset_url = wp_nonce_url(add_query_arg('reset_postcodes', 'true', wc_get_checkout_url()), 'reset_postcodes_action', 'reset_postcodes_nonce');
        echo '<br><a href="' . esc_url($reset_url) . '" class="button" id="reset_postcode_selections">' . __('Reset Postcode Selections') . '</a>';
    }
}

add_action('init', 'handle_reset_postcode_selections_checkout');
function handle_reset_postcode_selections_checkout() {
    // Only proceed if the reset_postcodes parameter is present, the nonce is valid, and the user is logged in
    if (isset($_GET['reset_postcodes'], $_GET['reset_postcodes_nonce']) && wp_verify_nonce($_GET['reset_postcodes_nonce'], 'reset_postcodes_action') && is_user_logged_in()) {
        $user_id = get_current_user_id();
        // Clear the saved postcode selections by updating the user meta with an empty value or default selection
        update_user_meta($user_id, 'selected_postcode_areas', json_encode(array()));
        // Optionally, add a notice to inform the user
        wc_add_notice(__('Your postcode selections have been reset. Please select new postcodes.'), 'notice');
        // Redirect to remove the query parameter and avoid resetting again on refresh
        wp_redirect(remove_query_arg(['reset_postcodes', 'reset_postcodes_nonce'], wc_get_checkout_url()));
        exit;
    }
}
