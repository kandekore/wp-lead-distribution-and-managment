<?php

if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------------------------------------
// TABLE CREATION
// ---------------------------------------------------------------------------

/**
 * Creates the audit log table on plugin activation.
 * Safe to call multiple times — dbDelta only alters if the schema changed.
 */
function lmd_create_audit_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'ld_audit';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table_name} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_type  VARCHAR(64)     NOT NULL DEFAULT '',
        lead_id     VARCHAR(64)     NOT NULL DEFAULT '',
        user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        message     TEXT            NOT NULL,
        context     LONGTEXT                 DEFAULT NULL,
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_event_type (event_type),
        KEY idx_lead_id    (lead_id),
        KEY idx_created_at (created_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

// ---------------------------------------------------------------------------
// WRITE A ROW
// ---------------------------------------------------------------------------

/**
 * Inserts a single audit event into the log table.
 *
 * @param string $event_type  Short machine-readable slug, e.g. 'lead_assigned'.
 * @param string $lead_id     The external lead ID (from the lead_data array).
 * @param int    $user_id     WordPress user ID that received or acted on the lead (0 = none).
 * @param string $message     Human-readable description of what happened.
 * @param array  $context     Optional key/value pairs stored as JSON for debugging.
 */
function lmd_audit( $event_type, $lead_id, $user_id, $message, $context = [] ) {
    global $wpdb;

    $wpdb->insert(
        $wpdb->prefix . 'ld_audit',
        [
            'event_type' => sanitize_key( $event_type ),
            'lead_id'    => sanitize_text_field( $lead_id ),
            'user_id'    => (int) $user_id,
            'message'    => sanitize_text_field( $message ),
            'context'    => ! empty( $context ) ? wp_json_encode( $context ) : null,
            'created_at' => current_time( 'mysql', true ), // UTC
        ],
        [ '%s', '%s', '%d', '%s', '%s', '%s' ]
    );
}

// ---------------------------------------------------------------------------
// ADMIN PAGE
// ---------------------------------------------------------------------------

/**
 * Renders the Audit Log admin page (paginated, filterable by event type).
 */
function render_audit_log_page() {
    global $wpdb;

    $table      = $wpdb->prefix . 'ld_audit';
    $per_page   = 50;
    $page       = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $offset     = ( $page - 1 ) * $per_page;
    $event_type = isset( $_GET['event_type'] ) ? sanitize_key( $_GET['event_type'] ) : '';

    // Build WHERE
    $where  = '';
    $values = [];
    if ( $event_type ) {
        $where    = 'WHERE event_type = %s';
        $values[] = $event_type;
    }

    // Count for pagination
    $total = (int) $wpdb->get_var(
        $values
            ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $values )
            : "SELECT COUNT(*) FROM {$table}"
    );

    $total_pages = ceil( $total / $per_page );

    // Fetch rows
    $query = "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
    array_push( $values, $per_page, $offset );
    $rows = $wpdb->get_results( $wpdb->prepare( $query, $values ) );

    // Distinct event types for the filter dropdown
    $event_types = $wpdb->get_col( "SELECT DISTINCT event_type FROM {$table} ORDER BY event_type ASC" );

    // Event type labels
    $labels = [
        'duplicate_dropped' => 'Duplicate Dropped',
        'no_recipients'     => 'No Recipients',
        'master_admin'      => 'Master Admin',
        'fallback_assigned' => 'Fallback Assigned',
        'lead_assigned'     => 'Lead Assigned',
    ];

    ?>
    <div class="wrap">
        <h1>Lead Audit Log</h1>

        <form method="get" style="margin-bottom:16px;">
            <input type="hidden" name="page" value="lead-audit-log">
            <select name="event_type">
                <option value="">All Events</option>
                <?php foreach ( $event_types as $et ) : ?>
                    <option value="<?php echo esc_attr( $et ); ?>" <?php selected( $event_type, $et ); ?>>
                        <?php echo esc_html( $labels[ $et ] ?? ucwords( str_replace( '_', ' ', $et ) ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button( 'Filter', 'secondary', '', false ); ?>
            <?php if ( $event_type ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=lead-audit-log' ) ); ?>" class="button">Clear</a>
            <?php endif; ?>
        </form>

        <p style="color:#666;">
            Showing <?php echo number_format( $total ); ?> event<?php echo $total !== 1 ? 's' : ''; ?>
            <?php if ( $event_type ) echo '&nbsp;&mdash;&nbsp;filtered by <strong>' . esc_html( $labels[ $event_type ] ?? $event_type ) . '</strong>'; ?>
        </p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:160px;">Date (UTC)</th>
                    <th style="width:160px;">Event</th>
                    <th style="width:120px;">Lead ID</th>
                    <th style="width:150px;">User</th>
                    <th>Message</th>
                    <th style="width:80px;">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="6">No audit events found.</td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) :
                        $user = $row->user_id ? get_userdata( $row->user_id ) : null;
                        $ctx  = $row->context ? json_decode( $row->context, true ) : [];
                    ?>
                    <tr>
                        <td><?php echo esc_html( $row->created_at ); ?></td>
                        <td>
                            <span class="lmd-badge lmd-badge-<?php echo esc_attr( $row->event_type ); ?>">
                                <?php echo esc_html( $labels[ $row->event_type ] ?? ucwords( str_replace( '_', ' ', $row->event_type ) ) ); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html( $row->lead_id ); ?></td>
                        <td>
                            <?php
                            if ( $user ) {
                                echo '<a href="' . esc_url( get_edit_user_link( $row->user_id ) ) . '">' . esc_html( $user->display_name ) . '</a>';
                            } elseif ( $row->user_id ) {
                                echo 'User #' . (int) $row->user_id;
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html( $row->message ); ?></td>
                        <td>
                            <?php if ( ! empty( $ctx ) ) : ?>
                                <details>
                                    <summary>View</summary>
                                    <pre style="font-size:11px;white-space:pre-wrap;"><?php echo esc_html( wp_json_encode( $ctx, JSON_PRETTY_PRINT ) ); ?></pre>
                                </details>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) :
            $base_url = admin_url( 'admin.php?page=lead-audit-log' . ( $event_type ? '&event_type=' . $event_type : '' ) );
        ?>
        <div class="tablenav bottom" style="margin-top:12px;">
            <div class="tablenav-pages">
                <?php if ( $page > 1 ) : ?>
                    <a class="button" href="<?php echo esc_url( $base_url . '&paged=' . ( $page - 1 ) ); ?>">&laquo; Previous</a>
                <?php endif; ?>
                <span style="margin:0 8px;">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                <?php if ( $page < $total_pages ) : ?>
                    <a class="button" href="<?php echo esc_url( $base_url . '&paged=' . ( $page + 1 ) ); ?>">Next &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <style>
        .lmd-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
        }
        .lmd-badge-lead_assigned     { background: #d4edda; color: #155724; }
        .lmd-badge-duplicate_dropped { background: #fff3cd; color: #856404; }
        .lmd-badge-no_recipients     { background: #f8d7da; color: #721c24; }
        .lmd-badge-master_admin      { background: #d1ecf1; color: #0c5460; }
        .lmd-badge-fallback_assigned { background: #e2d9f3; color: #432874; }
    </style>
    <?php
}
