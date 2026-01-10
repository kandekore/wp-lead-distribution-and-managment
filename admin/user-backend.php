<?php
// Enqueue necessary scripts and styles for the accordion
function enqueue_custom_admin_scripts($hook) {
    if ('user-edit.php' !== $hook && 'profile.php' !== $hook && 'post.php' !== $hook && 'edit.php' !== $hook) {
        return;
    }

    wp_enqueue_script('jquery-ui-accordion');
    wp_enqueue_style('jquery-ui-style', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');

    $custom_css = "
        .custom-accordion .ui-accordion-header {
            background-color: #0073aa;
            color: #ffffff;
            font-weight: bold;
            padding: 10px 15px;
            border-top: 1px solid #ffffff;
        }
        .custom-accordion .ui-accordion-header.ui-state-active {
            background-color: #005177;
        }
        .custom-accordion .ui-accordion-header.ui-state-default {
            background-color: #0073aa;
        }
        .custom-accordion .ui-accordion-content {
            background-color: #f1f1f1;
            border: 1px solid #ddd;
            padding: 15px;
            margin-bottom: 10px;
        }";
    wp_add_inline_style('jquery-ui-style', $custom_css);
}
add_action('admin_enqueue_scripts', 'enqueue_custom_admin_scripts');

add_action('show_user_profile', 'add_user_car_models_field');
add_action('edit_user_profile', 'add_user_car_models_field');

function add_user_car_models_field($user) {
    // Only show this section to users with 'edit_users' capability (typically Administrators)
    if (!current_user_can('edit_users')) {
        return;
    }

    $saved_models_json = get_user_meta($user->ID, '_user_car_models', true);
    $saved_models_array = json_decode($saved_models_json, true);
    // Convert array to newline-separated string for textarea
    $models_text = is_array($saved_models_array) ? implode("\n", $saved_models_array) : '';
    ?>
    <h3><?php _e("Specific Car Models for Lead Reception", "text-domain"); ?></h3>
    <table class="form-table">
        <tr>
            <th><label for="user_car_models"><?php _e("Allowed Car Models (one per line)", "text-domain"); ?></label></th>
            <td>
                <textarea name="user_car_models" id="user_car_models" rows="5" cols="30" class="large-text code"><?php echo esc_textarea($models_text); ?></textarea>
                <p class="description"><?php _e("Enter car models this user should exclusively receive leads for. Leads must match selected postcodes AND start with one of these models (case-insensitive). Enter one model prefix per line (e.g., BMW, Mercedes C Class, Ford Focus). If empty, this user receives all models matching their postcodes.", "text-domain"); ?></p>
            </td>
        </tr>
    </table>
    <?php
}

// Save Car Models field from the user profile page
add_action('personal_options_update', 'save_user_car_models_field');
add_action('edit_user_profile_update', 'save_user_car_models_field');

function save_user_car_models_field($user_id) {
    // Only process if user has 'edit_users' capability
    if (!current_user_can('edit_users', $user_id)) {
        return;
    }

    if (isset($_POST['user_car_models'])) {
        $models_input = sanitize_textarea_field($_POST['user_car_models']);
        // Convert newline-separated string back to array, filter out empty lines and trim whitespace
        $models_array = array_filter(array_map('trim', explode("\n", $models_input)));
        // Store as JSON for easier retrieval
        update_user_meta($user_id, '_user_car_models', wp_json_encode($models_array));
    } else {
        // If the field is not present in POST (e.g., if it's empty and not submitted), save an empty array
        update_user_meta($user_id, '_user_car_models', wp_json_encode([]));
    }
}

// Displaying selected postcode areas in order admin
function display_order_postcode_selections_in_admin($order) {
    $selected_postcode_areas = json_decode(get_post_meta($order->get_id(), 'selected_postcode_areas', true), true);
    echo '<div class="admincontainer">';
    echo '<div class="Selected-Postcode-Areas"><h3>' . __('Selected Postcode Areas:') . '</h3><div>';
    if (!empty($selected_postcode_areas)) {
        foreach ($selected_postcode_areas as $region => $codes) {
            echo '<p><strong>' . esc_html($region) . ':</strong> ' . esc_html(implode(', ', $codes)) . '</p>';
        }
    }
    echo '</div></div>';
}
add_action('woocommerce_admin_order_data_after_billing_address', 'display_order_postcode_selections_in_admin', 10, 1);

// Display user's selected postcode areas in profile
function display_user_postcode_selections($user) {
    $selected_postcode_areas = json_decode(get_user_meta($user->ID, 'selected_postcode_areas', true), true);
    echo '<div class="selected-postcodes"><h3>' . __('Selected Postcode Areas') . '</h3><div>';
    if (!empty($selected_postcode_areas)) {
        foreach ($selected_postcode_areas as $region => $codes) {
            echo '<p><strong>' . esc_html($region) . ':</strong> ' . esc_html(implode(', ', $codes)) . '</p>';
        }
    } else {
        echo '<p>' . __('No postcode areas selected.') . '</p>';
    }
    echo '</div></div>';
}
add_action('show_user_profile', 'display_user_postcode_selections', 1);
add_action('edit_user_profile', 'display_user_postcode_selections', 1);

// Admin edit user postcode selections
// Admin edit user postcode selections
function admin_edit_user_postcode_selections($user) {
    // Load all available postcodes from JSON, ensuring full list is displayed
    $all_postcode_areas = load_postcode_areas_from_json();
    
    // Load user's selected postcodes
    $selected_postcode_areas = json_decode(get_user_meta($user->ID, 'selected_postcode_areas', true), true);

    echo '<h3>' . __('Edit User Postcode Areas') . '</h3>';
    echo '<table class="form-table">';
    
    foreach ($all_postcode_areas as $region => $codes) {
        echo '<tr><th>' . esc_html($region) . '</th><td>';
        
        foreach ($codes as $code) {
            // Check if this postcode is already selected for the user
            $checked = !empty($selected_postcode_areas[$region]) && in_array($code, $selected_postcode_areas[$region]) ? ' checked="checked"' : '';
            echo '<label><input type="checkbox" name="postcode_areas[' . esc_attr($region) . '][]" value="' . esc_attr($code) . '"' . $checked . '> ' . esc_html($code) . '</label><br>';
        }
        
        echo '</td></tr>';
    }
    echo '</table>';
}

add_action('show_user_profile', 'admin_edit_user_postcode_selections');
add_action('edit_user_profile', 'admin_edit_user_postcode_selections');

// Render user credits management section
add_action('show_user_profile', 'render_user_credits_profile_page', 1);
add_action('edit_user_profile', 'render_user_credits_profile_page', 1);

function render_user_credits_profile_page($user) {
    // Fetch user's credits
    $current_credits = get_user_meta($user->ID, '_user_credits', true);

    ?>
    <h3><?php _e('User Credits Management', 'text-domain'); ?></h3>
    <table class="form-table">
        <tr>
            <th><label for="user_credits"><?php _e('Credits', 'text-domain'); ?></label></th>
            <td>
                <input type="number" id="user_credits" name="user_credits" value="<?php echo esc_attr($current_credits); ?>" readonly>
                <p class="description"><?php _e('Current credits for the user.', 'text-domain'); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="update_credits"><?php _e('Update Credits', 'text-domain'); ?></label></th>
            <td>
                <!-- Change input field to buttons -->
                <button type="button" class="button" onclick="updateCredits('+')">+10 Credits</button>
                <button type="button" class="button" onclick="updateCredits('-')">-10 Credits</button>
                <p class="description"><?php _e('Click the buttons to add or subtract credits.', 'text-domain'); ?></p>
            </td>
        </tr>
    </table>

    <?php wp_nonce_field('update_user_credits_nonce', 'user_credits_nonce'); ?>
    <input type="hidden" name="user_id" value="<?php echo esc_attr($user->ID); ?>">
    <!-- Add JavaScript function to handle credit update -->
    <script>
        function updateCredits(action) {
            var currentCredits = parseInt(document.getElementById('user_credits').value);
            var updateValue = action === '+' ? 10 : -10;
            var newCredits = currentCredits + updateValue;
            document.getElementById('user_credits').value = newCredits;
        }
    </script>
    <?php
}

// Handle form submission to update credits
add_action('personal_options_update', 'update_user_credits');
add_action('edit_user_profile_update', 'update_user_credits');

function update_user_credits($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    // Check if the request is valid
    if (isset($_POST['user_credits'], $_POST['user_credits_nonce'])) {
        // Verify nonce
        if (!wp_verify_nonce($_POST['user_credits_nonce'], 'update_user_credits_nonce')) {
            return false;
        }

        $new_credits = intval($_POST['user_credits']);
        // Update user meta with new credits
        update_user_meta($user_id, '_user_credits', $new_credits);
    }
}

// Save admin edited user postcode selections
function save_admin_edited_user_postcode_selections($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    error_log('Saving postcode areas for user ID: ' . $user_id); // Debugging log
    error_log('POST data: ' . print_r($_POST, true)); // Log the entire $_POST array

    if (isset($_POST['postcode_areas'])) {
        $sanitized_areas = array();
        foreach ($_POST['postcode_areas'] as $region => $codes) {
            $sanitized_areas[$region] = array_map('sanitize_text_field', $codes);
        }
        update_user_meta($user_id, 'selected_postcode_areas', json_encode($sanitized_areas));

        // Debugging log
        error_log('Saved postcode areas: ' . json_encode($sanitized_areas));
    } else {
        error_log('No postcode areas found in the request.');
    }
}
add_action('personal_options_update', 'save_admin_edited_user_postcode_selections');
add_action('edit_user_profile_update', 'save_admin_edited_user_postcode_selections');

// Initialize the accordion feature
function my_admin_footer_scripts() {
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.custom-accordion').accordion({
                collapsible: true,
                heightStyle: 'content'
            });
        });
    </script>
    <?php

    // Check if we're on the user edit page
    global $pagenow;
    if ( ! in_array( $pagenow, [ 'user-edit.php', 'profile.php' ], true ) ) {
        return;
    }

    ?>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Initialize custom accordion sections
            $('.custom-accordion').accordion({
                collapsible: true,
                heightStyle: 'content',
                active: false
            });

            // Target default WordPress sections - Adjust selectors as needed
            var sections = ['Personal Options', 'Name', 'Contact Info', 'About the user', 'Account Management', 'Customer billing address', 'Customer shipping address'];
            sections.forEach(function(section) {
                var header = $('h2:contains("' + section + '")');
                var table = header.next('table');

                // Wrap in div for accordion if not already done
                if (!header.parent().hasClass('wp-default-accordion')) {
                    header.add(table).wrapAll('<div class="wp-default-accordion"></div>');
                }
            });

            // Initialize accordion for the default WordPress sections
            $('.wp-default-accordion').accordion({
                collapsible: true,
                heightStyle: 'content',
                active: false // Start all sections collapsed
            });
        });
    </script>
    <?php
}
add_action('admin_footer', 'my_admin_footer_scripts');
?>
