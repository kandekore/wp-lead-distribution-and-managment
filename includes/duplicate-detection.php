<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Checks whether an incoming lead is a duplicate of one already stored in the
 * last 24 hours.
 *
 * A lead is considered a duplicate when EITHER of the following is true for a
 * lead stored within the previous 24 hours:
 *   1. Same contact phone number AND same postcode area (first 2 chars).
 *   2. Same email address AND same postcode area.
 *
 * The check is intentionally skipped for resent leads (resend flag is truthy).
 *
 * @param array $lead_data Fully-sanitised lead data from queue_lead_submission().
 * @return bool True if the lead is a duplicate (should be dropped), false otherwise.
 */
function lmd_is_duplicate_lead( array $lead_data ) {
    // Never block resent leads — they are intentional re-distributions.
    if ( ! empty( $lead_data['resend'] ) && $lead_data['resend'] !== 'false' ) {
        return false;
    }

    global $wpdb;

    $postcode_prefix = strtoupper( substr( $lead_data['postcode'], 0, 2 ) );
    $contact         = $lead_data['contact'] ?? '';
    $email           = $lead_data['email']   ?? '';
    $since           = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );

    // Check by contact phone number + postcode area.
    if ( ! empty( $contact ) ) {
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm_contact
                     ON pm_contact.post_id = p.ID
                     AND pm_contact.meta_key = 'contact'
                     AND pm_contact.meta_value = %s
                 INNER JOIN {$wpdb->postmeta} pm_postcode
                     ON pm_postcode.post_id = p.ID
                     AND pm_postcode.meta_key = 'postcode'
                     AND LEFT(pm_postcode.meta_value, 2) = %s
                 WHERE p.post_type   = 'lead'
                   AND p.post_status = 'publish'
                   AND p.post_date  >= %s",
                $contact,
                $postcode_prefix,
                $since
            )
        );

        if ( $count > 0 ) {
            error_log( "lmd_is_duplicate_lead: duplicate by contact ({$contact}) in postcode area {$postcode_prefix} — dropping lead {$lead_data['leadid']}" );
            return true;
        }
    }

    // Check by email address + postcode area.
    if ( ! empty( $email ) ) {
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm_email
                     ON pm_email.post_id = p.ID
                     AND pm_email.meta_key = 'email'
                     AND pm_email.meta_value = %s
                 INNER JOIN {$wpdb->postmeta} pm_postcode
                     ON pm_postcode.post_id = p.ID
                     AND pm_postcode.meta_key = 'postcode'
                     AND LEFT(pm_postcode.meta_value, 2) = %s
                 WHERE p.post_type   = 'lead'
                   AND p.post_status = 'publish'
                   AND p.post_date  >= %s",
                $email,
                $postcode_prefix,
                $since
            )
        );

        if ( $count > 0 ) {
            error_log( "lmd_is_duplicate_lead: duplicate by email ({$email}) in postcode area {$postcode_prefix} — dropping lead {$lead_data['leadid']}" );
            return true;
        }
    }

    return false;
}
