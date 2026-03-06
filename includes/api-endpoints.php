<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Register custom REST API endpoints — accepts both GET (legacy) and POST
add_action('rest_api_init', function () {
    register_rest_route('lead-management/v1', '/submit-lead', array(
        'methods'             => 'GET, POST',
        'callback'            => 'queue_lead_submission',
        'permission_callback' => '__return_true',
    ));
});

/**
 * Receives a lead from Gravity Forms (or any webhook), stores it in a background
 * queue via Action Scheduler, and immediately returns 200 OK.
 *
 * This prevents cURL timeout errors (error 28) caused by WordPress doing heavy
 * processing (DB queries, emails, SMS) synchronously inside the request.
 */
function queue_lead_submission(WP_REST_Request $request) {
    $leadid = sanitize_text_field($request->get_param('leadid'));

    // Deduplicate: if this leadid was queued in the last 5 minutes, silently ignore it.
    // Gravity Forms sometimes retries on slow responses — this stops double-processing.
    if (!empty($leadid)) {
        $dedup_key = 'lead_queued_' . md5($leadid);
        if (get_transient($dedup_key)) {
            return new WP_REST_Response(['message' => 'Lead already queued'], 200);
        }
        set_transient($dedup_key, true, 5 * MINUTE_IN_SECONDS);
    }

    // Parse submission_url once here so we can extract tracking param fallbacks
    $submission_url = sanitize_url($request->get_param('submission_url'));
    $url_params     = [];
    $decoded_url    = html_entity_decode($submission_url);
    $query_string   = parse_url($decoded_url, PHP_URL_QUERY);
    if ($query_string) {
        parse_str($query_string, $url_params);
    }

    // Derive source domain
    $source_domain = '';
    if (!empty($submission_url)) {
        $parsed = parse_url($submission_url);
        if (isset($parsed['scheme'], $parsed['host'])) {
            $source_domain = $parsed['scheme'] . '://' . $parsed['host'] . '/';
        }
    }

    // Tracking params: direct request value takes priority, fallback to URL-parsed value
    $vt_campaign = sanitize_text_field($request->get_param('vt_campaign') ?: ($url_params['vt_campaign'] ?? ''));
    $utm_source  = sanitize_text_field($request->get_param('utm_source')  ?: ($url_params['utm_source']  ?? ''));
    $vt_keyword  = sanitize_text_field($request->get_param('vt_keyword')  ?: ($url_params['vt_keyword']  ?? ''));
    $vt_adgroup  = sanitize_text_field($request->get_param('vt_adgroup')  ?: ($url_params['vt_adgroup']  ?? ''));

    $lead_data = [
        'postcode'       => strtoupper(sanitize_text_field($request->get_param('postcode'))),
        'registration'   => strtoupper(sanitize_text_field($request->get_param('vrg'))),
        'model'          => sanitize_text_field($request->get_param('model')),
        'date'           => sanitize_text_field($request->get_param('date')),
        'cylinder'       => sanitize_text_field($request->get_param('cylinder')),
        'colour'         => sanitize_text_field($request->get_param('colour')),
        'keepers'        => sanitize_text_field($request->get_param('keepers')),
        'contact'        => sanitize_text_field($request->get_param('contact')),
        'email'          => sanitize_email($request->get_param('email')),
        'info'           => sanitize_textarea_field($request->get_param('info')),
        'fuel'           => sanitize_text_field($request->get_param('fuel')),
        'mot'            => sanitize_text_field($request->get_param('mot')),
        'trans'          => sanitize_text_field($request->get_param('trans')),
        'doors'          => intval($request->get_param('doors')),
        'mot_due'        => sanitize_text_field($request->get_param('mot_due')),
        'leadid'         => $leadid,
        'resend'         => sanitize_text_field($request->get_param('resend')),
        'vin'            => sanitize_text_field($request->get_param('vin')),
        'milage'         => sanitize_text_field($request->get_param('milage')),
        'submission_url' => $submission_url,
        'ip_address'     => sanitize_text_field($request->get_param('ip_address')),
        'source_domain'  => $source_domain,
        'vt_campaign'    => $vt_campaign,
        'utm_source'     => $utm_source,
        'vt_keyword'     => $vt_keyword,
        'vt_adgroup'     => $vt_adgroup,
        'campaign_id'    => $vt_campaign,
    ];

    // Schedule background processing via Action Scheduler (bundled with WooCommerce).
    // Fires as soon as the next WP-Cron tick (typically within seconds).
    as_enqueue_async_action('process_lead_async', [['lead_data' => $lead_data]], 'lead-distribution');

    return new WP_REST_Response(['message' => 'Lead queued for processing'], 200);
}
