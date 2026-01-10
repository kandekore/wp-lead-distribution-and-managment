<?php

// function send_lead_to_user($user_id) {
//     $credits = (int) get_user_meta($user_id, '_user_credits', true);

//     if ($credits > 0) {
//         // Proceed with sending the lead
//         // ...

//         // Decrement the user's credits
//         $credits--;
//         update_user_meta($user_id, '_user_credits', $credits);

//         // Optional: Handle when credits run out, such as suspending the subscription
//         if ($credits <= 0) {
//             // Code to suspend the subscription or notify the user
//         }
//     } else {
//         // Insufficient credits, handle accordingly
//     }
// }

// function get_customers_by_region() {
//     $postcode_areas = load_postcode_areas_from_json(); // Load regions and their postcodes
//     $users_by_region = [];

//     $users = get_users();
//     foreach ($users as $user) {
//         // Fetch user's selected postcode areas
//         $selected_postcode_areas = json_decode(get_user_meta($user->ID, 'selected_postcode_areas', true), true);

//         // Check the user's credits
//         $user_credits = (int)get_user_meta($user->ID, '_user_credits', true);

//         if (!empty($selected_postcode_areas) && $user_credits > 0) { // Include credit check here
//             foreach ($selected_postcode_areas as $region => $codes) {
//                 foreach ($postcode_areas as $available_region => $available_codes) {
//                     // If the user-selected region matches available regions and codes
//                     if ($region === $available_region) {
//                         foreach ($codes as $code) {
//                             // Checking for both direct matches and wildcard matches
//                             $codePattern = rtrim($code, "*") . ".*"; // Convert to regex pattern
//                             foreach ($available_codes as $available_code) {
//                                 if (preg_match("/^$codePattern/", $available_code)) {
//                                     // Add user to the corresponding region
//                                     if (!isset($users_by_region[$region])) {
//                                         $users_by_region[$region] = [];
//                                     }
//                                     if (!in_array($user->ID, $users_by_region[$region])) {
//                                         $users_by_region[$region][] = $user->ID;
//                                     }
//                                     break 2; // Break out of both loops since we only need to add the user once
//                                 }
//                             }
//                         }
//                     }
//                 }
//             }
//         }
//     }

//     return $users_by_region;
// }

function get_customers_by_region() {
    $postcode_areas = load_postcode_areas_from_json(); // Load regions and their postcodes
    $users_by_region = [];

    // Fetch all users
    $users = get_users();
    
    foreach ($users as $user) {
        // Fetch user's selected postcode areas
        $selected_postcode_areas = json_decode(get_user_meta($user->ID, 'selected_postcode_areas', true), true);

        // Check the user's role and credits
        $user_credits = (int)get_user_meta($user->ID, '_user_credits', true);
        $is_post_pay = in_array('post_pay', $user->roles);
        $lead_reception_enabled = get_user_meta($user->ID, 'enable_lead_reception', true) === '1';

        // Include the user if they are pre-pay with credits or post-pay with lead reception enabled
        if (!empty($selected_postcode_areas) && 
           (($user_credits > 0) || ($is_post_pay && $lead_reception_enabled))) {
            foreach ($selected_postcode_areas as $region => $codes) {
                foreach ($postcode_areas as $available_region => $available_codes) {
                    // If the user-selected region matches available regions and codes
                    if ($region === $available_region) {
                        foreach ($codes as $code) {
                            // Checking for both direct matches and wildcard matches
                            $codePattern = rtrim($code, "*") . ".*"; // Convert to regex pattern
                            foreach ($available_codes as $available_code) {
                                if (preg_match("/^$codePattern/", $available_code)) {
                                    // Add user to the corresponding region
                                    if (!isset($users_by_region[$region])) {
                                        $users_by_region[$region] = [];
                                    }
                                    if (!in_array($user->ID, $users_by_region[$region])) {
                                        $users_by_region[$region][] = $user->ID;
                                    }
                                    break 2; // Break out of both loops since we only need to add the user once
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    return $users_by_region;
}
// Add Disable Lead Reception option in the user profile page for subscribers
add_action('show_user_profile', 'add_disable_lead_reception_option');
add_action('edit_user_profile', 'add_disable_lead_reception_option');

function add_disable_lead_reception_option($user) {
    // Only show this option for subscribers
    if (in_array('subscriber', $user->roles)) {
        ?>
        <h3><?php _e("Disable Lead Reception", "text-domain"); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="disable_lead_reception"><?php _e("Disable Lead Reception", "text-domain"); ?></label></th>
                <td>
                    <input type="checkbox" name="disable_lead_reception" id="disable_lead_reception" value="1" <?php checked(get_user_meta($user->ID, 'disable_lead_reception', true), '1'); ?> />
                    <label for="disable_lead_reception"><?php _e("Check this box to prevent the user from receiving leads", "text-domain"); ?></label>
                </td>
            </tr>
        </table>
        <?php
    }
}

// Save the Disable Lead Reception option for subscribers
add_action('personal_options_update', 'save_disable_lead_reception_option');
add_action('edit_user_profile_update', 'save_disable_lead_reception_option');

function save_disable_lead_reception_option($user_id) {
    // Only save for subscribers
    $user = get_userdata($user_id);
    if (in_array('subscriber', $user->roles)) {
        update_user_meta($user_id, 'disable_lead_reception', isset($_POST['disable_lead_reception']) ? '1' : '0');
    }
}
