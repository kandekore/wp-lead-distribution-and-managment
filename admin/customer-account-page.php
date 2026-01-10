<?php

function add_areas_endpoint() {
    add_rewrite_endpoint('areas', EP_ROOT | EP_PAGES);
}

add_action('init', 'add_areas_endpoint');

// Ensure WooCommerce knows about the new endpoint to prevent 404 errors
function areas_endpoint_query_vars($vars) {
    $vars[] = 'areas';
    return $vars;
}

add_filter('query_vars', 'areas_endpoint_query_vars', 0);

// Add the new endpoint into the My Account menu
function add_areas_link_my_account($items) {
    // Insert the new tab before the logout tab
    $logout = $items['customer-logout'];
    unset($items['customer-logout']);
    $items['areas'] = __('Areas', 'text-domain');
    $items['customer-logout'] = $logout;
    
    return $items;
}

add_filter('woocommerce_account_menu_items', 'add_areas_link_my_account');

function areas_endpoint_content() {
    $user_id = get_current_user_id();
    $selected_postcode_areas_json = get_user_meta($user_id, 'selected_postcode_areas', true);
    $selected_postcode_areas = json_decode($selected_postcode_areas_json, true);

    echo '<h3>' . __('Your Selected Postcode Areas', 'text-domain') . '</h3>';
    
    // Check if the decoded JSON is an array
    if (is_array($selected_postcode_areas) && !empty($selected_postcode_areas)) {
        foreach ($selected_postcode_areas as $region => $codes) {
            // Ensure $codes is definitely an array to avoid implode error
            if (is_array($codes)) {
                echo '<p><strong>' . esc_html($region) . ':</strong> ' . esc_html(implode(', ', $codes)) . '</p>';
            }
        }
    } else {
        echo '<p>' . __('No postcode areas selected.', 'text-domain') . '</p>';
    }
    
    
}

add_action('woocommerce_account_areas_endpoint', 'areas_endpoint_content');


add_action('woocommerce_account_dashboard', 'display_user_credit_balance_and_renewal_info');

function display_user_credit_balance_and_renewal_info() {
    $user_id = get_current_user_id();
    $credits = (int) get_user_meta($user_id, '_user_credits', true); // Casting to ensure we have an integer value

    // Display the credit balance
    echo '<div class="user-credits-info">';
    echo '<h3>' . __('Your Credits', 'text-domain') . '</h3>';
    printf('<p>' . __('You currently have %s credits.', 'text-domain') . '</p>', esc_html($credits));

    // Explain the renewal condition
    echo '<p>' . __('Your subscription will automatically renew when your credits drop to 5 or below.', 'text-domain') . '</p>';
    echo '</div>';
}

function enqueue_account_page_styles() {
    if (is_account_page()) {
        // Assuming you have a custom CSS file
        wp_enqueue_style('my-account-custom-style', get_template_directory_uri() . '/css/my-account.css');
        
        // Or directly adding inline styles
        $custom_css = "
            .user-credits-info {
                background-color: #f7f7f7;
                padding: 20px;
                margin-bottom: 20px;
            }
            .user-credits-info h3 {
                color: #333;
            }
            ul.user-leads-list {
                list-style: none;
                padding-left: 0px;
            }
            /* General table styles */
            .user-leads-list {
                width: 100%;
                border-collapse: collapse;
                background-color: #f9f9f9;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                font-family: Arial, sans-serif;
            }
            
            /* Header row and cells */
            .user-leads-list .header {
                background-color: #007bff!important;
                color: #ffffff;
            }
            
            .user-leads-list th.theader {
                padding: 10px;
                border-bottom: 2px solid #ffffff;
                text-align: left;
            }
            
            /* Table rows and cells styling */
            .user-leads-list .trow:nth-child(odd) {
                background-color: #ffffff;
            }
            
            .user-leads-list .trow:nth-child(even) {
                background-color: #f2f2f2;
            }
            
            .user-leads-list td.tcell {
                padding: 8px;
                border-bottom: 1px solid #dddddd;
            }
            
            /* Button styling within the table */
            .user-leads-list .butn .button {
                display: inline-block;
                padding: 5px 10px;
                background-color: #28a745;
                color: #ffffff;
                text-decoration: none;
                border-radius: 3px;
                font-size: 14px;
                transition: background-color 0.2s ease-in-out;
            }
            
            .user-leads-list .butn .button:hover {
                background-color: #218838;
            }
            
            /* Style for when no leads are found */
            .no-leads-found {
                color: #dc3545;
                font-family: Arial, sans-serif;
                padding: 10px;
            }
            
        ";
        wp_add_inline_style('woocommerce-general', $custom_css);
    }
}
add_action('wp_enqueue_scripts', 'enqueue_account_page_styles');


//CUSTOMER LEDS VIEW

function add_leads_endpoint() {
    add_rewrite_endpoint('leads', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('lead-details', EP_ROOT | EP_PAGES);
}

add_action('init', 'add_leads_endpoint');

function leads_endpoint_query_vars($vars) {
    $vars[] = 'leads';
    $vars[] = 'lead-details';
    return $vars;
}

add_filter('query_vars', 'leads_endpoint_query_vars', 0);

function add_leads_link_my_account($items) {
    $logout = isset($items['customer-logout']) ? $items['customer-logout'] : null;
    if($logout) {
        unset($items['customer-logout']);
        $items['leads'] = __('Your Leads', 'text-domain');
        $items['customer-logout'] = $logout;
    }
    return $items;
}

add_filter('woocommerce_account_menu_items', 'add_leads_link_my_account');

function leads_endpoint_content() {
    // Form for filtering leads by date
    echo '<form method="get" class="leads-date-filter">';
    echo '<select name="date_filter">';
    echo '<option value="all">All Dates</option>';
    echo '<option value="today">Today</option>';
    echo '<option value="yesterday">Yesterday</option>';
    echo '<option value="this_week">This Week</option>';
    echo '<option value="last_week">Last Week</option>';
    echo '<option value="last_2_weeks">Last 2 Weeks</option>';
    echo '<option value="last_3_weeks">Last 3 Weeks</option>';
    echo '<option value="last_4_weeks">Last 4 Weeks</option>';
    echo '</select>';
    echo '<button type="submit">Filter</button>';
    echo '</form>';

    // Now, retrieve the selected date filter if one was submitted
    $date_filter = isset($_GET['date_filter']) ? $_GET['date_filter'] : 'all';

    // Fetch and display the leads according to the selected date filter
    display_filtered_leads($date_filter);
}

add_action('woocommerce_account_leads_endpoint', 'leads_endpoint_content');

function display_filtered_leads($date_filter) {
    $user_id = get_current_user_id();
    $args = [
        'post_type' => 'lead',
        'posts_per_page' => -1, // Adjust as needed
        'author' => $user_id,
        // Initialize 'date_query' to ensure it's always set
        'date_query' => []
    ];

    // Adapted switch statement from 'apply_date_filter'
    switch ($date_filter) {
        case 'today':
            $args['date_query'][] = ['year' => date('Y'), 'month' => date('m'), 'day' => date('d')];
            break;
        case 'yesterday':
            $yesterday = strtotime('-1 day');
            $args['date_query'][] = ['year' => date('Y', $yesterday), 'month' => date('m', $yesterday), 'day' => date('d', $yesterday)];
            break;
        case 'this_week':
            $startOfWeek = date('Y-m-d', strtotime('this week'));
            $endOfWeek = date('Y-m-d', strtotime('this week +6 days'));
            $args['date_query'][] = ['after' => $startOfWeek, 'before' => $endOfWeek, 'inclusive' => true];
            break;
        case 'last_week':
            $startOfLastWeek = date('Y-m-d', strtotime('last week'));
            $endOfLastWeek = date('Y-m-d', strtotime('last week +6 days'));
            $args['date_query'][] = ['after' => $startOfLastWeek, 'before' => $endOfLastWeek, 'inclusive' => true];
            break;
        case 'last_2_weeks':
            $startOfLast2Weeks = date('Y-m-d', strtotime('-2 weeks'));
            $endOfLastWeek = date('Y-m-d', strtotime('last week +6 days'));
            $args['date_query'][] = ['after' => $startOfLast2Weeks, 'before' => $endOfLastWeek, 'inclusive' => true];
            break;
        case 'last_3_weeks':
            $startOfLast3Weeks = date('Y-m-d', strtotime('-3 weeks'));
            $endOfLastWeek = date('Y-m-d', strtotime('last week +6 days'));
            $args['date_query'][] = ['after' => $startOfLast3Weeks, 'before' => $endOfLastWeek, 'inclusive' => true];
            break;
        case 'last_4_weeks':
            $startOfLast4Weeks = date('Y-m-d', strtotime('-4 weeks'));
            $endOfLastWeek = date('Y-m-d', strtotime('last week +6 days'));
            $args['date_query'][] = ['after' => $startOfLast4Weeks, 'before' => $endOfLastWeek, 'inclusive' => true];
            break;
        // Ensure there's a case for 'all' or default to not apply any date query filter
        case 'all':
        default:
            // No date query needed
            break;
    }

    $leads_query = new WP_Query($args);

    if ($leads_query->have_posts()) {
        // Start the table and add a header row for the column titles
        echo '<table class="user-leads-list">';
        echo '<thead><tr class="header trow"><th class="theader">Lead ID</th><th class="theader">Lead Info</th><th class="theader">Date Received</th><th class="theader">More</th></tr></thead>';
        echo '<tbody>';
        while ($leads_query->have_posts()) {
            $leads_query->the_post();
            $lead_id = get_the_ID();
            $lead_title = get_the_title();
            $lead_time = get_the_date('F j, Y, g:i a'); // Adjust the format as needed
            $leadid_meta = get_post_meta($lead_id, 'leadid', true);
            // For each lead, create a row in the table
            echo '<tr class="trow">';
            echo '<td class="tcell">' . esc_html($leadid_meta) . '</td>';
            // Column for Lead Info (title)
            echo '<td class="tcell">' . esc_html($lead_title) . '</td>';
            // Column for Date Received
            echo '<td class="tcell">' . esc_html($lead_time) . '</td>';
            // Column for 'More' button
            // Note: 'More' button links to lead details. The esc_url() function is used for security to escape the URL properly.
            echo '<td class="butn tcell"><a href="' . esc_url( home_url('/my-account/lead-details/?lead_id=' . $lead_id) ) . '" class="button">More</a></td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
    }else {
        echo '<p class="no-leads-found">' . __('No leads found for the selected period.', 'text-domain') . '</p>';
    }
    wp_reset_postdata();
}


// --- REPLACE WITH THIS UPDATED FUNCTION ---

function lead_details_endpoint_content() {
    $lead_id = isset($_GET['lead_id']) ? intval($_GET['lead_id']) : 0;

    if ($lead_id) {
        $post = get_post($lead_id);
        if ($post && $post->post_type === 'lead' && $post->post_author == get_current_user_id()) {
            echo '<h3>' . esc_html(get_the_title($lead_id)) . '</h3>';

            $meta_keys = [
                'keepers', 'contact', 'email','postcode', 'registration', 'model', 'date', 'cylinder', 'colour', 'doors',
                'fuel', 'mot', 'transmission', 'mot_due', 'vin'
            ];
            $leadid_meta = get_post_meta($lead_id, 'leadid', true);
            echo '<h3>ID:' . esc_html($leadid_meta) . '</h3>';
            echo '<h4>Lead Details:</h4>';
            echo '<ul style="list-style-type:none;">';
            foreach ($meta_keys as $key) {
                $value = get_post_meta($lead_id, $key, true);
                if (!empty($value)) {
                    // --- VIN TWEAK ---
                    if ($key === 'vin' && strlen($value) > 4) {
                        $value = '...' . substr($value, -4);
                    }
                    // --- END TWEAK ---
                    echo '<li>' . ucfirst(esc_html($key) ). ': ' . esc_html($value) . '</li>';
                }
            }
            echo '</ul>';

        } else {
            echo '<p>' . __('You do not have permission to view this lead.', 'text-domain') . '</p>';
        }
    } else {
        echo '<p>' . __('No lead ID provided.', 'text-domain') . '</p>';
    }
}

add_action('woocommerce_account_lead-details_endpoint', 'lead_details_endpoint_content');
