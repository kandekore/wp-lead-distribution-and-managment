<?php 

if ( ! defined( 'ABSPATH' ) ) exit;    

function process_lead_submission(WP_REST_Request $request) {
    // Extract common data from the request parameters
    $lead_data = [
        'postcode' => strtoupper(sanitize_text_field($request->get_param('postcode'))),
        'registration' => strtoupper(sanitize_text_field($request->get_param('vrg'))),
        'model' => sanitize_text_field($request->get_param('model')),
        'date' => sanitize_text_field($request->get_param('date')),
        'cylinder' => sanitize_text_field($request->get_param('cylinder')),
        'colour' => sanitize_text_field($request->get_param('colour')),
        'keepers' => sanitize_text_field($request->get_param('keepers')),
        'contact' => sanitize_text_field($request->get_param('contact')),
        'email' => sanitize_email($request->get_param('email')),
        'info' => sanitize_textarea_field($request->get_param('info')),
        'fuel' => sanitize_text_field($request->get_param('fuel')),
        'mot' => sanitize_text_field($request->get_param('mot')),
        'trans' => sanitize_text_field($request->get_param('trans')),
        'doors' => intval($request->get_param('doors')),
        'mot_due' => sanitize_text_field($request->get_param('mot_due')),
        'leadid' => sanitize_text_field($request->get_param('leadid')),
        'resend' => sanitize_text_field($request->get_param('resend')),
        'vin' => sanitize_text_field($request->get_param('vin')),
        'milage' => sanitize_text_field($request->get_param('milage')), 
    ];

// Get submission_url and ip_address directly from request parameters
$submission_url = sanitize_url($request->get_param('submission_url'));
$ip_address = sanitize_text_field($request->get_param('ip_address'));

// Decode and parse query string
$decoded_url = html_entity_decode($submission_url);
$query_string = parse_url($decoded_url, PHP_URL_QUERY);
parse_str($query_string, $query_params);

// Get tracking params from request OR fallback to parsed URL
$vt_campaign = sanitize_text_field($request->get_param('vt_campaign') ?: ($query_params['vt_campaign'] ?? ''));
$utm_source  = sanitize_text_field($request->get_param('utm_source') ?: ($query_params['utm_source'] ?? ''));
$vt_keyword  = sanitize_text_field($request->get_param('vt_keyword') ?: ($query_params['vt_keyword'] ?? ''));
$vt_adgroup  = sanitize_text_field($request->get_param('vt_adgroup') ?: ($query_params['vt_adgroup'] ?? ''));
    // Assign all collected and extracted data to lead_data
    $lead_data['submission_url'] = $submission_url;
    $lead_data['ip_address'] = $ip_address;
    $lead_data['vt_campaign'] = $vt_campaign;   // Store directly
    $lead_data['utm_source'] = $utm_source;     // Store directly
    $lead_data['vt_keyword'] = $vt_keyword;     // Store directly
    $lead_data['vt_adgroup'] = $vt_adgroup;     // Store directly
    $lead_data['campaign_id'] = $vt_campaign;   // campaign_id now maps directly to vt_campaign

    // Calculate source domain (logic remains the same, uses the processed submission_url)
    $source_domain = '';
    if (!empty($submission_url)) {
        $parsed_url = parse_url($submission_url);
        if (isset($parsed_url['scheme']) && isset($parsed_url['host'])) {
            $source_domain = $parsed_url['scheme'] . '://' . $parsed_url['host'] . '/';
        }
    }
    $lead_data['source_domain'] = $source_domain;
    $postcode_prefix = substr($lead_data['postcode'], 0, 2);
$eligible_recipients = get_eligible_recipients_for_lead($postcode_prefix, $lead_data['vin'], $lead_data['model']);

    // Deserialize your settings array
    $settings = get_option('master_admin_settings');
    if ($settings) {
        $settings_array = maybe_unserialize($settings);

        $master_admin_function_enabled = $settings_array["master_admin_function_enabled"];
        $minimum_year = $settings_array["minimum_year"];
        $master_admin_email = $settings_array["master_admin_email"];
        $master_admin_user_id = $settings_array["master_admin_user_id"];

        // Assuming $lead_data contains all the necessary fields
        $rootURL = get_site_url();
        $apiEndpoint = "/wp-json/lead-management/v1/submit-lead?";
        $resendParam = "resend=true";

        // Construct the query parameters from $lead_data, excluding 'resend' and adding it at the end
        $queryParams = [];
        foreach ($lead_data as $key => $value) {
            if ($key != 'resend') { // Exclude the resend parameter
                $queryParams[] = $key . "=" . urlencode($value);
            }
        }
        $queryString = implode("&", $queryParams) . "&" . $resendParam;

        // Complete API URL
        $apiURL = $rootURL . $apiEndpoint . $queryString;

        if ($master_admin_function_enabled == "1" && !empty($minimum_year) && intval($lead_data['date']) > intval($minimum_year) &&
            $lead_data['resend'] == "false") {
            // Prepare and send email to Master Admin
            $subject = "New Lead: " . $lead_data['leadid'];

            // Start of the HTML email body
            $body = "<html><body>";
            $body .= "<h3>New Lead Details for Master Admin</h3>";

            // Assuming 'registration' and 'model' are important and should be highlighted
            if (isset($lead_data['registration']) && isset($lead_data['model'])) {
                $body .= "<h4>" . esc_html($lead_data['leadid']) . " - " . esc_html($lead_data['registration']) . " - " . esc_html($lead_data['model']) . "</h4>";
            }

            // Manually display selected meta data
           $meta_keys = [
        'keepers', 'contact', 'email', 'postcode', 'registration', 'model', 'date',
        'cylinder', 'colour', 'doors', 'fuel', 'mot', 'transmission', 'mot_due',
        'vin', 'info', 'milage' 
    ];

            $body .= "<ul style='list-style-type:none;'>";
            foreach ($meta_keys as $key) {
                if (!empty($lead_data[$key])) { // Only display if value is not empty
                    $body .= "<li>" . ucfirst($key) . ": " . esc_html($lead_data[$key]) . "</li>"."%n";
                }
            }
            $body .= "</ul>";
            $body .= "<p>To resend the lead, click <a href='" . esc_url($apiURL) . "'>here</a>.</p>";
            // End of the HTML email body
            $body .= "</body></html>";

            $headers = ['Content-Type: text/html; charset=UTF-8'];

            wp_mail($master_admin_email, $subject, $body, $headers);

            // Assign lead to Master Admin user ID and store the lead
            $lead_id = store_lead($lead_data, $master_admin_user_id);
            assign_lead_to_user($master_admin_user_id, $lead_data, $lead_id);
            if (!is_wp_error($lead_id)) {
                return new WP_REST_Response(['message' => 'Lead sent successfully to Master Admin'], 200);
            } else {
                return new WP_REST_Response(['message' => 'Failed to store lead for Master Admin'], 500);
            }
        }
    } else {
        error_log('Master Admin settings not found or are incorrect.');
    }
    
    // Check if there are eligible recipients
if (empty($eligible_recipients)) {
    $settings = get_option('fallback_settings');
    if ($settings) {
        $settings_array = maybe_unserialize($settings);
        $fallback_user_enabled = !empty($settings_array['fallback_user_enabled']) && $settings_array['fallback_user_enabled'] == "1";
        $fallback_user_email = $settings_array['fallback_user_email'];
        $fallback_user_id = $settings_array['fallback_user_id'];
        $fallback_api_endpoint = $settings_array['fallback_user_api_endpoint'];

        if ($fallback_user_enabled) {
            if ($fallback_api_endpoint) {
                      $api_lead_data = [
                        'postcode' => $lead_data['postcode'],
                        'vrg' => $lead_data['registration'],
                        'model' => $lead_data['model'],
                        'date' => $lead_data['date'],
                        'cylinder' => $lead_data['cylinder'],
                        'colour' => $lead_data['colour'],
                        'keepers' => $lead_data['keepers'],
                        'contact' => $lead_data['contact'],
                        'email' => $lead_data['email'],
                        'info' => $lead_data['info'],
                        'fuel' => $lead_data['fuel'],
                        'mot' => $lead_data['mot'],
                        'trans' => $lead_data['trans'],
                        'doors' => $lead_data['doors'],
                        'mot_due' => $lead_data['mot_due'],
                        'leadid' => $lead_data['leadid'],
                        'resend' => $lead_data['resend'],
                        'vin' => $lead_data['vin'],
                        'submission_url' => $lead_data['submission_url']
                    ];

                    // Construct the GET URL with query parameters
                    $fallback_api_url = add_query_arg($api_lead_data, $fallback_api_endpoint);

                    // Send the lead to the fallback user API endpoint using GET
                    $response = wp_remote_get($fallback_api_url);

                // Debugging: Log before storing lead for API branch
                error_log("Attempting to store lead for Fallback User (API branch): ID=" . $fallback_user_id);
                $lead_id = store_lead($lead_data, $fallback_user_id);
                // Debugging: Log result of store_lead
                error_log("store_lead result (API branch): " . (is_wp_error($lead_id) ? $lead_id->get_error_message() : $lead_id));

                if (!is_wp_error($lead_id)) {
                    $result = assign_lead_to_user($fallback_user_id, $lead_data, $lead_id);
                    // Debugging: Log result of assign_lead_to_user
                    error_log("assign_lead_to_user result (API branch): " . ($result ? 'true' : 'false'));
                    if ($result) {
                        return new WP_REST_Response(['message' => 'Lead sent successfully to Fallback User API and stored'], 200);
                    } else {
                        // More specific error response for assign failure
                        return new WP_REST_Response(['message' => 'Failed to assign lead to Fallback User (API branch)'], 500);
                    }
                } else {
                    // More specific error response for store failure
                    return new WP_REST_Response(['message' => 'Failed to store lead for Fallback User (API branch)'], 500);
                }
            } else { // No fallback API endpoint, but fallback user enabled
                // Debugging: Log before storing lead for Email branch
                error_log("Attempting to store lead for Fallback User (Email branch): ID=" . $fallback_user_id);
                $lead_id = store_lead($lead_data, $fallback_user_id);
                // Debugging: Log result of store_lead
                error_log("store_lead result (Email branch): " . (is_wp_error($lead_id) ? $lead_id->get_error_message() : $lead_id));

                if (!is_wp_error($lead_id)) {
                    $result = assign_lead_to_user($fallback_user_id, $lead_data, $lead_id);
                    // Debugging: Log result of assign_lead_to_user
                    error_log("assign_lead_to_user result (Email branch): " . ($result ? 'true' : 'false'));
                    if ($result) {
                        // ... (existing logic to send email to fallback user) ...
                        if (wp_mail($fallback_user_email, $subject, $body, $headers)) {
                            return new WP_REST_Response(['message' => 'Lead sent successfully to Fallback User and email notification sent.'], 200);
                        } else {
                            // This block implies lead was stored, but email failed
                            return new WP_REST_Response(['message' => 'Lead stored for Fallback User but failed to send email notification.'], 500);
                        }
                    } else {
                        // More specific error response for assign failure
                        return new WP_REST_Response(['message' => 'Failed to assign lead to Fallback User (Email branch)'], 500);
                    }
                } else {
                    // More specific error response for store failure
                    return new WP_REST_Response(['message' => 'Failed to store lead for Fallback User (Email branch)'], 500);
                }
            }
        } else {
            return new WP_REST_Response(['message' => 'No eligible recipients for this postcode and Fallback User is disabled'], 404);
        }
    }
}


    // Randomly pick an eligible recipient from the array
    $random_key = array_rand($eligible_recipients);
    $recipient_id = $eligible_recipients[$random_key];
    $lead_id = store_lead($lead_data, $recipient_id);

    // Ensure lead was successfully stored
    if (is_wp_error($lead_id)) {
        return new WP_REST_Response(['message' => 'Failed to store lead'], 500);
    }

    // Deduct a credit from the chosen recipient and send the lead
    if (deduct_credit_from_user($recipient_id)) {
        assign_lead_to_user($recipient_id, $lead_data, $lead_id);
        send_lead_email_to_user($recipient_id, $lead_data);
        return new WP_REST_Response(['message' => 'Lead sent successfully to ' . $recipient_id], 200);
    } else {
        return new WP_REST_Response(['message' => 'Failed to send lead, user out of credits'], 500);
    }
}

function get_eligible_recipients_for_lead($postcode_prefix, $lead_vin, $lead_model) {
    $priority_eligible_users = []; // Users who match postcode, credits/role, AND model preference
    $general_eligible_users = [];  // Users who match postcode, credits/role, AND have NO model preference

    $all_users = get_users(); // Fetch all users once

    foreach ($all_users as $user) {
        $user_id = $user->ID;
        $selected_postcode_areas = json_decode(get_user_meta($user_id, 'selected_postcode_areas', true), true);
        $user_credits = (int) get_user_meta($user_id, '_user_credits', true);
        $is_post_pay = in_array('post_pay', $user->roles);
        $lead_reception_enabled = get_user_meta($user_id, 'enable_lead_reception', true) === '1';
        $lead_reception_disabled_for_subscriber = get_user_meta($user_id, 'disable_lead_reception', true) === '1';


        // First, check general eligibility (postcode and credits/reception status)
        $is_postcode_eligible = false;
        if (!empty($selected_postcode_areas)) {
            foreach ($selected_postcode_areas as $region => $codes) {
                foreach ($codes as $code) {
                    $codePattern = str_replace("#", "[0-9]", $code);
                    if (preg_match("/^$codePattern/", $postcode_prefix)) {
                        $is_postcode_eligible = true;
                        break 2; // Matched postcode, break out of inner loops
                    }
                }
            }
        }

        if (!$is_postcode_eligible) continue; // Not eligible by postcode, move to next user

        // Apply credit/role checks for general eligibility
        if ($is_post_pay) {
            if ($lead_reception_enabled === '0') continue; // Post-pay with reception disabled, move to next user
        } elseif (!in_array('post_pay', $user->roles)) { // Pre-pay user
            if ($user_credits <= 0 || $lead_reception_disabled_for_subscriber === '1') continue; // Pre-pay without credits or reception disabled, move to next user
        }


        // Now, check for model preference match for this lead
        $user_car_models_json = get_user_meta($user_id, '_user_car_models', true);
        $user_car_models = json_decode($user_car_models_json, true);

        $user_has_model_preferences_defined = is_array($user_car_models) && !empty($user_car_models);
        $has_matching_model_preference = false; // Flag if the lead's model matches user's preference

        if ($user_has_model_preferences_defined && !empty($lead_model)) {
            foreach ($user_car_models as $allowed_model_prefix) {
                // Case-insensitive 'starts with' check for lead model
                if (strncasecmp($lead_model, $allowed_model_prefix, strlen($allowed_model_prefix)) === 0) {
                    $has_matching_model_preference = true;
                    break; // Found a matching model preference, no need to check further models for this user
                }
            }
        }

        // Categorize user based on whether they have model preferences and if the lead matches
        if ($user_has_model_preferences_defined) {
            // User has specified model preferences. They ONLY get leads matching these.
            if ($has_matching_model_preference) {
                $priority_eligible_users[] = $user_id;
            } else {
                // Lead model does NOT match their preference. Exclude this user entirely for this lead.
                continue; // Skip to the next user in the foreach loop
            }
        } else {
            // User has NOT specified model preferences. They receive ALL models (general recipients).
            $general_eligible_users[] = $user_id;
        }
    }

    // Combine all eligible users (before VIN filtering) for the VIN check
    $all_pre_vin_eligible_users = array_merge($priority_eligible_users, $general_eligible_users);
    $all_pre_vin_eligible_users = array_unique($all_pre_vin_eligible_users); // Ensure uniqueness before VIN filter

    // Apply VIN filtering to get the final list of users who are eligible AND don't own this VIN
    $vin_filtered_eligible_users = filter_out_lead_owners_by_vin($all_pre_vin_eligible_users, $lead_vin);

    // Re-categorize users after VIN filtering and apply lead priority weighting
    $final_priority_recipients = [];
    $final_general_recipients = [];

    foreach ($vin_filtered_eligible_users as $user_id) {
        // Re-check model preference (needed because VIN filter might remove users)
        $user_car_models_json = get_user_meta($user_id, '_user_car_models', true);
        $user_car_models = json_decode($user_car_models_json, true);
        $has_matching_model_preference = false; // Reset flag for re-evaluation
        if (is_array($user_car_models) && !empty($user_car_models) && !empty($lead_model)) {
            foreach ($user_car_models as $allowed_model_prefix) {
                if (strncasecmp($lead_model, $allowed_model_prefix, strlen($allowed_model_prefix)) === 0) {
                    $has_matching_model_preference = true;
                    break;
                }
            }
        }

        $user_lead_priority = get_user_meta($user_id, 'lead_priority', true) === '1';

        // Apply lead priority weighting based on the new prioritization tiers
        if ($has_matching_model_preference) {
            $final_priority_recipients[] = $user_id; // Base entry for model-specific recipient
            if ($user_lead_priority) {
                // Add 3 extra entries for model-specific users with general lead priority
                $final_priority_recipients[] = $user_id;
                $final_priority_recipients[] = $user_id;
                $final_priority_recipients[] = $user_id;
            }
        } else {
            $final_general_recipients[] = $user_id; // Base entry for general recipient
            if ($user_lead_priority) {
                // Add 1 extra entry for general users with general lead priority
                $final_general_recipients[] = $user_id;
            }
        }
    }

    // Prioritize model-specific recipients: If any exist, distribute only among them.
    if (!empty($final_priority_recipients)) {
        return $final_priority_recipients;
    }

    // Otherwise, fallback to general recipients.
    return $final_general_recipients;
}

// Helper function to filter out users who already own a lead with the same VIN
function filter_out_lead_owners_by_vin($eligible_recipients, $lead_vin) {
    // Get all lead posts that match the given VIN
    $args = [
        'post_type'  => 'lead', // Replace with your lead post type if different
        'meta_query' => [
            [
                'key'   => 'vin',
                'value' => $lead_vin,
                'compare' => '='
            ]
        ],
        'posts_per_page' => -1,
    ];

    $leads = get_posts($args);

    // Collect user IDs who already own a lead with this VIN
    $users_to_remove = [];

    foreach ($leads as $lead) {
        $owner_id = get_post_meta($lead->ID, 'assigned_user', true); // Assuming 'assigned_user' holds the user ID
        if ($owner_id) {
            $users_to_remove[] = (int)$owner_id;
        }
    }

    // Filter out those users from the eligible recipients list
    return array_diff($eligible_recipients, $users_to_remove);
}

function deduct_credit_from_user($user_id) {
    // Check if the user is post-pay
    if (in_array('post_pay', get_userdata($user_id)->roles)) {
        // Post-pay users do not have credits deducted, and no renewal or cancellation is necessary
        return true;
    }

    // Existing logic for pre-pay users
    $credits = get_user_meta($user_id, '_user_credits', true);
    $credits = intval($credits);

    if ($credits > 0) {
        $credits--; // Deduct one credit
        update_user_meta($user_id, '_user_credits', $credits);

        // Check if the credits are now zero or less and handle subscription renewal or cancellation if necessary
        if ($credits <= 0) {
            $renewal_attempted = check_credits_and_renew_subscription($user_id);
            
            if (!$renewal_attempted) {
                cancel_user_subscription($user_id);
                send_credit_depletion_email($user_id);
            }
        } elseif ($credits <= 5) {
            check_credits_and_renew_subscription($user_id); 
        }

        return true; // Successfully deducted credit
    }

    return false; // User had no credits to deduct
}

function cancel_user_subscription($user_id) {
    $subscriptions = wcs_get_users_subscriptions($user_id);

    foreach ($subscriptions as $subscription) {
        if ($subscription->has_status('active')) {
            $subscription->update_status('cancelled');
            error_log("Subscription {$subscription->get_id()} for user {$user_id} has been cancelled due to zero credits.");
            break;
        }
    }
}


function send_credit_depletion_email($user_id) {
    $user_info = get_userdata($user_id);
    $to = $user_info->user_email;

    $subject = "Your Credits Have Been Depleted";
    $body = "<html><body>";
    $body .= "<h3>Your Credits Have Been Depleted</h3>";
    $body .= "<p>Dear " . esc_html($user_info->display_name) . ",</p>";
    $body .= "<p>Your account has run out of credits, and your subscription has been cancelled. Please renew your subscription to continue receiving leads.</p>";
    $body .= "<p>Thank you for using our service.</p>";
    $body .= "</body></html>";

    $headers = ['Content-Type: text/html; charset=UTF-8'];

    if(!wp_mail($to, $subject, $body, $headers)) {
        error_log("Failed to send credit depletion email to user ID {$user_id}");
    }
}

add_filter('woocommerce_email_enabled_customer_completed_renewal_order', 'disable_renewal_email_for_automatic_renewals', 10, 2);

function disable_renewal_email_for_automatic_renewals($enabled, $order) {
    if ($order->get_meta('_triggered_by_credits_renewal')) {
        return false;
    }
    return $enabled;
}
function check_credits_and_renew_subscription($user_id) {
    $subscriptions = wcs_get_users_subscriptions($user_id);
    $renewal_attempted = false;

    foreach ($subscriptions as $subscription) {
        if ($subscription->has_status('active') && $subscription->get_meta('_renew_on_credit_depletion') === 'yes') {
            // Check if the early renewal can be initiated
            if ($subscription->can_be_renewed_early()) {
                // Get the renewal order
                $renewal_order = wcs_create_renewal_order($subscription);

                // Set the custom meta to flag it was triggered by credits
                $renewal_order->update_meta_data('_triggered_by_credits_renewal', true);

                // Process the renewal order payment
                $result = WC_Subscriptions_Payment_Gateways::trigger_gateway_renewal_payment_hook($renewal_order);

                if ($result && $renewal_order->get_status() === 'completed') {
                    // Log or notify as needed
                    error_log("Early renewal successful for subscription {$subscription->get_id()} for user {$user_id}.");

                    // Update the subscription dates
                    wcs_update_dates_after_early_renewal($subscription, $renewal_order);

                    $renewal_attempted = true; // Renewal was successful
                    break; // Stop after successfully renewing one subscription
                } else {
                    // Handle failed renewal attempt
                    $renewal_order->update_status('failed');
                    $renewal_order->add_order_note('Early renewal payment failed.');
                    error_log("Early renewal failed for subscription {$subscription->get_id()} for user {$user_id}.");
                }
            }
        }
    }

    return $renewal_attempted; // Return whether a renewal was attempted successfully or not
}
function assign_lead_to_user($user_id, $lead_data, $lead_id) {
    // Example of associating a lead post with a user. Adjust according to your storage method.
    update_post_meta($lead_id, 'assigned_user', $user_id);
    return true;
}


function send_lead_email_to_user($user_id, $lead_data) {
    // =================================================================
    // 1. SEND THE MAIN HTML EMAIL TO THE USER'S INBOX
    // =================================================================
    $user_info = get_userdata($user_id);
    $to = $user_info->user_email;
    $subject = "New Lead: " . $lead_data['leadid'];
    $headers = ['Content-Type: text/html; charset=UTF-8'];

    // Define the keys to be included in the communications
    $meta_keys = [
         'leadid', 'keepers', 'contact', 'email', 'postcode', 'registration', 'model', 'date',
        'cylinder', 'colour', 'doors', 'fuel', 'mot', 'transmission', 'mot_due', 'vin'
    ];

    // Build the rich HTML email body for the main email
    $body = "<html><body><h3>New Lead Details</h3>";
    if (isset($lead_data['registration']) && isset($lead_data['model'])) {
        $body .= "<h4>". esc_html($lead_data['leadid']) . " - ". esc_html($lead_data['registration']) . " - " . esc_html($lead_data['model']) . "</h4>";
    }
    $body .= "<ul style='list-style-type:none;'>";
    foreach ($meta_keys as $key) {
        if (!empty($lead_data[$key])) {
            $value = esc_html($lead_data[$key]);
            // --- VIN TWEAK ---
            if ($key === 'vin' && strlen($value) > 4) {
                $value = '...' . substr($value, -4);
            }
            // --- END TWEAK ---
            $body .= "<li>" . ucfirst($key) . ": " . $value . "</li>";
        }
    }
    $body .= "</ul></body></html>";

    // Send the primary email to the user
    wp_mail($to, $subject, $body, $headers);

    // =================================================================
    // 2. SEND THE SEPARATE PLAIN TEXT SMS NOTIFICATION
    // =================================================================
    $sms_provider_url = wc_custom_get_active_sms_provider_url();

    if (!empty($sms_provider_url)) {
        $user_phone = get_user_meta($user_id, 'billing_phone', true);

        if (!empty($user_phone)) {
            $sms_to = $user_phone . $sms_provider_url;
            $sms_subject = "New Lead: " . $lead_data['registration'];

            // --- DYNAMICALLY BUILD THE PLAIN TEXT SMS BODY ---
            $sms_body_parts = [];
            foreach ($meta_keys as $key) {
                if (!empty($lead_data[$key])) {
                    $value = $lead_data[$key];
                    // --- VIN TWEAK ---
                    if ($key === 'vin' && strlen($value) > 4) {
                        $value = '...' . substr($value, -4);
                    }
                    // --- END TWEAK ---
                    $sms_body_parts[] = ucfirst($key) . ": " . $value;
                }
            }
            $sms_body = implode("\n", $sms_body_parts);

            // Send the plain text email to the SMS gateway
            wp_mail($sms_to, $sms_subject, $sms_body);

            error_log('SMS notification for lead ' . $lead_data['leadid'] . ' sent to: ' . $sms_to);
        } else {
            error_log('SMS notice for lead ' . $lead_data['leadid'] . ' failed: User ' . $user_id . ' has no phone number.');
        }
    } else {
         error_log('SMS notice for lead ' . $lead_data['leadid'] . ' failed: No active SMS provider is configured.');
    }

    return true;
}

add_action('profile_update', 'update_user_postcode_queues', 10, 2);
function update_user_postcode_queues($user_id, $old_user_data) {
    $selected_postcode_areas = json_decode(get_user_meta($user_id, 'selected_postcode_areas', true), true);
    if (empty($selected_postcode_areas)) return;

    foreach ($selected_postcode_areas as $region => $codes) {
        foreach ($codes as $code) {
            $postcode_prefix = substr($code, 0, 2);
            $queue_key = "recipients_queue_{$postcode_prefix}";
            $queue = get_option($queue_key, []);

            // If not already in queue, add user ID
            if (!in_array($user_id, $queue)) {
                $queue[] = $user_id;
                update_option($queue_key, $queue);
            }
        }
    }
}

function process_lead_submission_with_lock(WP_REST_Request $request) {
    $lock_key = 'process_lead_lock';
    $lock_timeout = 10; // Lock timeout in seconds

    // Attempt to acquire lock
    if (get_transient($lock_key)) {
        return new WP_REST_Response(['message' => 'System is busy, please try again'], 429);
    }

    set_transient($lock_key, true, $lock_timeout);

    // [Process lead submission logic goes here]

    // Release lock
    delete_transient($lock_key);

    return new WP_REST_Response(['message' => 'Lead processed successfully'], 200);
}