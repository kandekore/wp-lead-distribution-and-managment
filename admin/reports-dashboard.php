<?php
if (!defined('ABSPATH')) exit;

// ─── CSV Export ───────────────────────────────────────────────────────────────

add_action('admin_post_ld_export_leads', 'lmd_export_leads_csv');

function lmd_export_leads_csv(): void {
    if (!current_user_can('manage_options')) wp_die('Access denied.');
    check_admin_referer('ld_export_leads');

    global $wpdb;

    $period = sanitize_key($_GET['period'] ?? '30days');
    $dates  = lmd_period_dates($period);
    $dw     = lmd_raw_date_where($dates);

    $rows = $wpdb->get_results(
        "SELECT p.ID, p.post_date, p.post_author FROM {$wpdb->posts} p
         WHERE p.post_type='lead' AND p.post_status='publish' {$dw}
         ORDER BY p.post_date DESC",
        ARRAY_A
    );

    // Gather all meta in a single query
    $ids = wp_list_pluck($rows, 'ID');
    $meta_map = [];
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $meta_rows    = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
                 WHERE post_id IN ({$placeholders})
                 AND meta_key IN ('leadid','registration','model','postcode','contact','email','fuel','transmission','date','source_domain','vt_campaign','utm_source','assigned_user')",
                ...$ids
            ),
            ARRAY_A
        );
        foreach ($meta_rows as $m) {
            $meta_map[$m['post_id']][$m['meta_key']] = $m['meta_value'];
        }
    }

    // Stream the CSV
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="leads-' . $period . '-' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Lead ID', 'Registration', 'Model', 'Postcode', 'Contact', 'Email', 'Fuel', 'Transmission', 'Year', 'Source Domain', 'Campaign', 'UTM Source', 'Agent', 'Date']);

    foreach ($rows as $row) {
        $m   = $meta_map[$row['ID']] ?? [];
        $uid = $m['assigned_user'] ?? 0;
        $u   = $uid ? get_userdata((int)$uid) : null;

        fputcsv($out, [
            $m['leadid']        ?? '',
            $m['registration']  ?? '',
            $m['model']         ?? '',
            $m['postcode']      ?? '',
            $m['contact']       ?? '',
            $m['email']         ?? '',
            $m['fuel']          ?? '',
            $m['transmission']  ?? '',
            $m['date']          ?? '',
            $m['source_domain'] ?? '',
            $m['vt_campaign']   ?? '',
            $m['utm_source']    ?? '',
            $u ? $u->display_name : '',
            $row['post_date'],
        ]);
    }

    fclose($out);
    exit;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function lmd_period_dates(string $period): array {
    $ts    = current_time('timestamp');
    $today = date('Y-m-d', $ts);

    $map = [
        'today' => [
            'start'      => "$today 00:00:00",
            'end'        => "$today 23:59:59",
            'prev_start' => date('Y-m-d', strtotime('-1 day', $ts)) . ' 00:00:00',
            'prev_end'   => date('Y-m-d', strtotime('-1 day', $ts)) . ' 23:59:59',
            'label'      => 'Today',
        ],
        '7days' => [
            'start'      => date('Y-m-d', strtotime('-6 days', $ts)) . ' 00:00:00',
            'end'        => "$today 23:59:59",
            'prev_start' => date('Y-m-d', strtotime('-13 days', $ts)) . ' 00:00:00',
            'prev_end'   => date('Y-m-d', strtotime('-7 days', $ts))  . ' 23:59:59',
            'label'      => 'Last 7 Days',
        ],
        '30days' => [
            'start'      => date('Y-m-d', strtotime('-29 days', $ts)) . ' 00:00:00',
            'end'        => "$today 23:59:59",
            'prev_start' => date('Y-m-d', strtotime('-59 days', $ts)) . ' 00:00:00',
            'prev_end'   => date('Y-m-d', strtotime('-30 days', $ts)) . ' 23:59:59',
            'label'      => 'Last 30 Days',
        ],
        '90days' => [
            'start'      => date('Y-m-d', strtotime('-89 days', $ts))  . ' 00:00:00',
            'end'        => "$today 23:59:59",
            'prev_start' => date('Y-m-d', strtotime('-179 days', $ts)) . ' 00:00:00',
            'prev_end'   => date('Y-m-d', strtotime('-90 days', $ts))  . ' 23:59:59',
            'label'      => 'Last 90 Days',
        ],
        'this_month' => [
            'start'      => date('Y-m-01', $ts) . ' 00:00:00',
            'end'        => date('Y-m-t',  $ts) . ' 23:59:59',
            'prev_start' => date('Y-m-01', strtotime('first day of last month', $ts)) . ' 00:00:00',
            'prev_end'   => date('Y-m-t',  strtotime('last day of last month',  $ts)) . ' 23:59:59',
            'label'      => 'This Month',
        ],
        'last_month' => [
            'start'      => date('Y-m-01', strtotime('first day of last month',    $ts)) . ' 00:00:00',
            'end'        => date('Y-m-t',  strtotime('last day of last month',     $ts)) . ' 23:59:59',
            'prev_start' => date('Y-m-01', strtotime('first day of 2 months ago',  $ts)) . ' 00:00:00',
            'prev_end'   => date('Y-m-t',  strtotime('last day of 2 months ago',   $ts)) . ' 23:59:59',
            'label'      => 'Last Month',
        ],
        'all_time' => [
            'start' => null, 'end' => null, 'prev_start' => null, 'prev_end' => null, 'label' => 'All Time',
        ],
    ];

    return $map[$period] ?? $map['30days'];
}

/** Returns a prepared SQL fragment "AND p.post_date BETWEEN x AND y", or '' for all_time. */
function lmd_date_where(array $dates): string {
    global $wpdb;
    if (empty($dates['start'])) return '';
    return $wpdb->prepare('AND p.post_date BETWEEN %s AND %s', $dates['start'], $dates['end']);
}

/** Same but without a table alias — for queries on wp_posts directly. */
function lmd_raw_date_where(array $dates): string {
    global $wpdb;
    if (empty($dates['start'])) return '';
    return $wpdb->prepare('AND post_date BETWEEN %s AND %s', $dates['start'], $dates['end']);
}

function lmd_pct_change(int $current, int $prev): string {
    if ($prev === 0) return $current > 0 ? 'New' : '—';
    $pct = round((($current - $prev) / $prev) * 100);
    return ($pct >= 0 ? '+' : '') . $pct . '%';
}

function lmd_kpi_card(string $label, int $value, int $prev, string $icon, string $border_color = '#2271b1'): void {
    $change      = lmd_pct_change($value, $prev);
    $change_cls  = ($value >= $prev) ? 'lmd-pos' : 'lmd-neg';
    echo "
    <div class='lmd-kpi' style='border-top:3px solid {$border_color}'>
        <span class='lmd-kpi-icon'>{$icon}</span>
        <span class='lmd-kpi-num'>" . number_format($value) . "</span>
        <span class='lmd-kpi-lbl'>{$label}</span>
        <span class='lmd-kpi-chg {$change_cls}'>{$change} vs prev period</span>
    </div>";
}

function lmd_no_data(string $msg = 'No data available for this period.'): void {
    echo "<p class='lmd-nodata'>{$msg}</p>";
}

// ─── Tab 1: Overview ─────────────────────────────────────────────────────────

function lmd_tab_overview(string $period, array $dates): void {
    global $wpdb;
    $ts   = current_time('timestamp');
    $today = date('Y-m-d', $ts);

    // Period KPI
    $dw   = lmd_raw_date_where($dates);
    $curr = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='lead' AND post_status='publish' {$dw}");

    $prev_dw = empty($dates['prev_start']) ? '' : $wpdb->prepare('AND post_date BETWEEN %s AND %s', $dates['prev_start'], $dates['prev_end']);
    $prev    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='lead' AND post_status='publish' {$prev_dw}");

    // Fixed context KPIs (always today / this week / this month)
    $count_today  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='lead' AND post_status='publish' AND DATE(post_date)=%s", $today));
    $count_yest   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='lead' AND post_status='publish' AND DATE(post_date)=%s", date('Y-m-d', strtotime('-1 day', $ts))));
    $count_month  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='lead' AND post_status='publish' AND post_date >= %s", date('Y-m-01', $ts) . ' 00:00:00'));
    $count_lmonth = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='lead' AND post_status='publish' AND post_date BETWEEN %s AND %s", date('Y-m-01', strtotime('first day of last month', $ts)) . ' 00:00:00', date('Y-m-t', strtotime('last day of last month', $ts)) . ' 23:59:59'));
    $count_all    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='lead' AND post_status='publish'");

    // KPI grid
    echo "<div class='lmd-kpi-grid'>";
    lmd_kpi_card($dates['label'], $curr, $prev, '📊', '#2271b1');
    lmd_kpi_card('Today', $count_today, $count_yest, '📥', '#00a32a');
    lmd_kpi_card('This Month', $count_month, $count_lmonth, '📆', '#dba617');
    echo "<div class='lmd-kpi' style='border-top:3px solid #a2105c'>
        <span class='lmd-kpi-icon'>🏆</span>
        <span class='lmd-kpi-num'>" . number_format($count_all) . "</span>
        <span class='lmd-kpi-lbl'>All Time Total</span>
    </div>";
    echo "</div>";

    // 30-day trend line chart
    $trend_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT DATE(post_date) as d, COUNT(*) as cnt FROM {$wpdb->posts}
         WHERE post_type='lead' AND post_status='publish' AND post_date >= %s
         GROUP BY DATE(post_date) ORDER BY d ASC",
        date('Y-m-d', strtotime('-29 days', $ts)) . ' 00:00:00'
    ));
    $trend_map = [];
    foreach ($trend_rows as $r) $trend_map[$r->d] = (int)$r->cnt;
    $t_labels = $t_values = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days", $ts));
        $t_labels[] = date('M j', strtotime($d));
        $t_values[] = $trend_map[$d] ?? 0;
    }
    $tl = json_encode($t_labels);
    $tv = json_encode($t_values);

    echo "<div class='lmd-box'><h3>30-Day Lead Volume</h3>
        <div class='lmd-chart-wrap'><canvas id='trendChart'></canvas></div>
    </div>
    <script>
    new Chart(document.getElementById('trendChart'),{type:'line',data:{labels:{$tl},datasets:[{label:'Leads',data:{$tv},borderColor:'#2271b1',backgroundColor:'rgba(34,113,177,.1)',fill:true,tension:.35,pointRadius:3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});
    </script>";

    // Top 5 sources + top 5 agents side by side
    $top_src = $wpdb->get_results($wpdb->prepare(
        "SELECT pm.meta_value as val, COUNT(*) as cnt FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id
         WHERE p.post_type='lead' AND p.post_status='publish'
         AND pm.meta_key='source_domain' AND pm.meta_value != '' AND p.post_date >= %s
         GROUP BY pm.meta_value ORDER BY cnt DESC LIMIT 5",
        date('Y-m-01', $ts) . ' 00:00:00'
    ));
    $top_agt = $wpdb->get_results(
        "SELECT pm.meta_value as uid, COUNT(*) as cnt FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id
         WHERE p.post_type='lead' AND p.post_status='publish'
         AND pm.meta_key='assigned_user' AND pm.meta_value != ''
         GROUP BY pm.meta_value ORDER BY cnt DESC LIMIT 5"
    );

    echo "<div class='lmd-two-col'>";

    echo "<div class='lmd-box'><h3>Top Sources This Month</h3>";
    if ($top_src) {
        echo "<table class='widefat striped'><thead><tr><th>Source</th><th>Leads</th></tr></thead><tbody>";
        foreach ($top_src as $r) echo "<tr><td>" . esc_html($r->val) . "</td><td><strong>" . esc_html($r->cnt) . "</strong></td></tr>";
        echo "</tbody></table>";
    } else lmd_no_data();
    echo "</div>";

    echo "<div class='lmd-box'><h3>Top Agents All Time</h3>";
    if ($top_agt) {
        echo "<table class='widefat striped'><thead><tr><th>Agent</th><th>Leads</th></tr></thead><tbody>";
        foreach ($top_agt as $r) {
            $u = get_userdata((int)$r->uid);
            $name = $u ? esc_html($u->display_name) : 'User #' . esc_html($r->uid);
            echo "<tr><td>{$name}</td><td><strong>" . esc_html($r->cnt) . "</strong></td></tr>";
        }
        echo "</tbody></table>";
    } else lmd_no_data();
    echo "</div>";

    echo "</div>"; // .lmd-two-col

    // Export button
    $export_url = wp_nonce_url(
        admin_url('admin-post.php?action=ld_export_leads&period=' . $period),
        'ld_export_leads'
    );
    echo "<p style='margin-top:16px'><a href='" . esc_url($export_url) . "' class='button button-secondary'>&#11015; Export leads as CSV (" . esc_html($dates['label']) . ")</a></p>";
}

// ─── Tab 2: Sources & Campaigns ──────────────────────────────────────────────

function lmd_tab_sources(string $period, array $dates): void {
    global $wpdb;
    $dw = lmd_date_where($dates);

    $src  = $wpdb->get_results("SELECT pm.meta_value as val,COUNT(*) as cnt FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id WHERE p.post_type='lead' AND p.post_status='publish' AND pm.meta_key='source_domain' AND pm.meta_value!='' {$dw} GROUP BY pm.meta_value ORDER BY cnt DESC LIMIT 12");
    $utm  = $wpdb->get_results("SELECT pm.meta_value as val,COUNT(*) as cnt FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id WHERE p.post_type='lead' AND p.post_status='publish' AND pm.meta_key='utm_source' AND pm.meta_value!='' {$dw} GROUP BY pm.meta_value ORDER BY cnt DESC LIMIT 10");
    $camp = $wpdb->get_results("SELECT pm.meta_value as val,COUNT(*) as cnt FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id WHERE p.post_type='lead' AND p.post_status='publish' AND pm.meta_key='vt_campaign' AND pm.meta_value!='' {$dw} GROUP BY pm.meta_value ORDER BY cnt DESC LIMIT 12");
    $kw   = $wpdb->get_results("SELECT pm.meta_value as val,COUNT(*) as cnt FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id WHERE p.post_type='lead' AND p.post_status='publish' AND pm.meta_key='vt_keyword' AND pm.meta_value!='' {$dw} GROUP BY pm.meta_value ORDER BY cnt DESC LIMIT 15");
    $adg  = $wpdb->get_results("SELECT pm.meta_value as val,COUNT(*) as cnt FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id WHERE p.post_type='lead' AND p.post_status='publish' AND pm.meta_key='vt_adgroup' AND pm.meta_value!='' {$dw} GROUP BY pm.meta_value ORDER BY cnt DESC LIMIT 10");

    $colors = json_encode(['#2271b1','#d63638','#00a32a','#dba617','#a2105c','#3582c4','#8c8f94','#72aee6','#b45309','#065f46','#6b21a8','#0e7490']);

    // Row 1: source donut + campaign bar
    $sl = json_encode(array_column($src,  'val') ?: []);
    $sv = json_encode(array_map('intval', array_column($src, 'cnt')) ?: []);
    $cl = json_encode(array_column($camp, 'val') ?: []);
    $cv = json_encode(array_map('intval', array_column($camp,'cnt')) ?: []);

    echo "<div class='lmd-two-col'>
        <div class='lmd-box'><h3>Lead Sources (Domains)</h3><div class='lmd-chart-wrap'><canvas id='srcDonut'></canvas></div></div>
        <div class='lmd-box'><h3>Campaign Performance</h3><div class='lmd-chart-wrap'><canvas id='campBar'></canvas></div></div>
    </div>
    <script>
    (function(){
        const C={$colors};
        new Chart(document.getElementById('srcDonut'),{type:'doughnut',data:{labels:{$sl},datasets:[{data:{$sv},backgroundColor:C}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right'}}}});
        new Chart(document.getElementById('campBar'),{type:'bar',data:{labels:{$cl},datasets:[{label:'Leads',data:{$cv},backgroundColor:'#2271b1'}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true}}}});
    })();
    </script>";

    // Row 2: UTM source bar + ad group bar
    $ul = json_encode(array_column($utm, 'val') ?: []);
    $uv = json_encode(array_map('intval', array_column($utm,'cnt')) ?: []);
    $al = json_encode(array_column($adg, 'val') ?: []);
    $av = json_encode(array_map('intval', array_column($adg,'cnt')) ?: []);

    echo "<div class='lmd-two-col'>
        <div class='lmd-box'><h3>UTM Sources</h3><div class='lmd-chart-wrap'><canvas id='utmBar'></canvas></div></div>
        <div class='lmd-box'><h3>Ad Groups</h3><div class='lmd-chart-wrap'><canvas id='adgBar'></canvas></div></div>
    </div>
    <script>
    (function(){
        new Chart(document.getElementById('utmBar'),{type:'bar',data:{labels:{$ul},datasets:[{label:'Leads',data:{$uv},backgroundColor:'#00a32a'}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true}}}});
        new Chart(document.getElementById('adgBar'),{type:'bar',data:{labels:{$al},datasets:[{label:'Leads',data:{$av},backgroundColor:'#dba617'}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true}}}});
    })();
    </script>";

    // Keyword table
    if ($kw) {
        echo "<div class='lmd-box'><h3>Top Keywords</h3>
            <table class='widefat striped'><thead><tr><th>Keyword</th><th>Leads</th></tr></thead><tbody>";
        foreach ($kw as $r) echo "<tr><td>" . esc_html($r->val) . "</td><td>" . esc_html($r->cnt) . "</td></tr>";
        echo "</tbody></table></div>";
    }

    // Full source table
    if ($src) {
        echo "<div class='lmd-box'><h3>All Sources — Detail Table</h3>
            <table class='widefat striped'><thead><tr><th>Source Domain</th><th>Leads</th></tr></thead><tbody>";
        foreach ($src as $r) echo "<tr><td>" . esc_html($r->val) . "</td><td>" . esc_html($r->cnt) . "</td></tr>";
        echo "</tbody></table></div>";
    }
}

// ─── Tab 3: Agent Performance ─────────────────────────────────────────────────

function lmd_tab_agents(string $period, array $dates): void {
    global $wpdb;
    $dw = lmd_date_where($dates);

    $rows = $wpdb->get_results(
        "SELECT pm.meta_value as uid, COUNT(*) as cnt FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id
         WHERE p.post_type='lead' AND p.post_status='publish'
         AND pm.meta_key='assigned_user' AND pm.meta_value!='' {$dw}
         GROUP BY pm.meta_value ORDER BY cnt DESC"
    );

    if (!$rows) { lmd_no_data(); return; }

    $names = $counts = [];
    $table_rows = [];
    foreach ($rows as $r) {
        $u        = get_userdata((int)$r->uid);
        $name     = $u ? $u->display_name : 'User #' . $r->uid;
        $credits  = (int) get_user_meta((int)$r->uid, '_user_credits', true);
        $is_pp    = $u && in_array('post_pay', (array)$u->roles);
        $names[]  = $name;
        $counts[] = (int)$r->cnt;
        $table_rows[] = ['name' => $name, 'cnt' => (int)$r->cnt, 'credits' => $is_pp ? '<em>Post-pay</em>' : $credits];
    }

    $nl = json_encode($names);
    $nv = json_encode($counts);

    echo "<div class='lmd-box'><h3>Leads per Agent</h3>
        <div class='lmd-chart-wrap' style='height:max(300px," . min(count($rows) * 40, 500) . "px)'><canvas id='agentsBar'></canvas></div>
    </div>
    <script>
    new Chart(document.getElementById('agentsBar'),{type:'bar',data:{labels:{$nl},datasets:[{label:'Leads Received',data:{$nv},backgroundColor:'#2271b1'}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{stepSize:1}}}}});
    </script>";

    echo "<div class='lmd-box'><h3>Agent Detail</h3>
        <table class='widefat striped'>
        <thead><tr><th>Agent</th><th>Leads This Period</th><th>Credits Remaining</th></tr></thead><tbody>";
    foreach ($table_rows as $r) {
        echo "<tr><td>" . esc_html($r['name']) . "</td><td><strong>" . esc_html($r['cnt']) . "</strong></td><td>" . $r['credits'] . "</td></tr>";
    }
    echo "</tbody></table></div>";
}

// ─── Tab 4: Geographic ───────────────────────────────────────────────────────

function lmd_tab_geographic(string $period, array $dates): void {
    global $wpdb;
    $dw = lmd_date_where($dates);

    $rows = $wpdb->get_results(
        "SELECT LEFT(pm.meta_value,2) as area, COUNT(*) as cnt FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id
         WHERE p.post_type='lead' AND p.post_status='publish'
         AND pm.meta_key='postcode' AND pm.meta_value!='' {$dw}
         GROUP BY LEFT(pm.meta_value,2) ORDER BY cnt DESC LIMIT 30"
    );

    if (!$rows) { lmd_no_data(); return; }

    $pl = json_encode(array_column($rows, 'area'));
    $pv = json_encode(array_map('intval', array_column($rows, 'cnt')));

    // Build a colour gradient based on rank
    $max = (int)$rows[0]->cnt;
    $bar_colors = [];
    foreach ($rows as $r) {
        $intensity = $max > 0 ? round(($r->cnt / $max) * 100) : 0;
        $bar_colors[] = "rgba(34,113,177,{$intensity}/100)";
    }

    echo "<div class='lmd-box'><h3>Leads by Postcode Area (Top 30)</h3>
        <div class='lmd-chart-wrap' style='height:max(320px," . min(count($rows) * 36, 600) . "px)'><canvas id='postcodeBar'></canvas></div>
    </div>
    <script>
    new Chart(document.getElementById('postcodeBar'),{type:'bar',data:{labels:{$pl},datasets:[{label:'Leads',data:{$pv},backgroundColor:'#2271b1'}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{stepSize:1}}}}});
    </script>";

    // Full postcode table with bar visualisation
    echo "<div class='lmd-box'><h3>Postcode Area Breakdown</h3>
        <table class='widefat striped'>
        <thead><tr><th>Area</th><th>Leads</th><th style='width:40%'>Share</th></tr></thead><tbody>";
    $total = array_sum(array_column($rows, 'cnt'));
    foreach ($rows as $r) {
        $pct = $total > 0 ? round(($r->cnt / $total) * 100, 1) : 0;
        echo "<tr>
            <td><strong>" . esc_html($r->area) . "</strong></td>
            <td>" . esc_html($r->cnt) . "</td>
            <td><div style='background:#e0e0e0;border-radius:3px;height:14px;width:100%'>
                <div style='background:#2271b1;border-radius:3px;height:14px;width:{$pct}%'></div>
            </div><small>{$pct}%</small></td>
        </tr>";
    }
    echo "</tbody></table></div>";
}

// ─── Tab 5: Vehicle Analysis ─────────────────────────────────────────────────

function lmd_tab_vehicles(string $period, array $dates): void {
    global $wpdb;
    $dw = lmd_date_where($dates);

    $fuel   = $wpdb->get_results("SELECT pm.meta_value as val,COUNT(*) as cnt FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id WHERE p.post_type='lead' AND p.post_status='publish' AND pm.meta_key='fuel' AND pm.meta_value!='' {$dw} GROUP BY pm.meta_value ORDER BY cnt DESC");
    $models = $wpdb->get_results("SELECT pm.meta_value as val,COUNT(*) as cnt FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id WHERE p.post_type='lead' AND p.post_status='publish' AND pm.meta_key='model' AND pm.meta_value!='' {$dw} GROUP BY pm.meta_value ORDER BY cnt DESC LIMIT 20");
    $years  = $wpdb->get_results("SELECT pm.meta_value as val,COUNT(*) as cnt FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id WHERE p.post_type='lead' AND p.post_status='publish' AND pm.meta_key='date' AND pm.meta_value REGEXP '^[0-9]{4}$' {$dw} GROUP BY pm.meta_value ORDER BY pm.meta_value+0 DESC");
    $trans  = $wpdb->get_results("SELECT pm.meta_value as val,COUNT(*) as cnt FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id WHERE p.post_type='lead' AND p.post_status='publish' AND pm.meta_key='transmission' AND pm.meta_value!='' {$dw} GROUP BY pm.meta_value ORDER BY cnt DESC");

    $colors = json_encode(['#2271b1','#d63638','#00a32a','#dba617','#a2105c','#3582c4','#8c8f94','#72aee6','#b45309','#065f46']);

    $fl = json_encode(array_column($fuel,   'val') ?: []);
    $fv = json_encode(array_map('intval', array_column($fuel,   'cnt')) ?: []);
    $tl = json_encode(array_column($trans,  'val') ?: []);
    $tv = json_encode(array_map('intval', array_column($trans,  'cnt')) ?: []);
    $yl = json_encode(array_column($years,  'val') ?: []);
    $yv = json_encode(array_map('intval', array_column($years,  'cnt')) ?: []);
    $ml = json_encode(array_column($models, 'val') ?: []);
    $mv = json_encode(array_map('intval', array_column($models, 'cnt')) ?: []);

    // Row 1: fuel donut + transmission donut
    echo "<div class='lmd-two-col'>
        <div class='lmd-box'><h3>Fuel Type</h3><div class='lmd-chart-wrap'><canvas id='fuelChart'></canvas></div></div>
        <div class='lmd-box'><h3>Transmission</h3><div class='lmd-chart-wrap'><canvas id='transChart'></canvas></div></div>
    </div>";

    // Row 2: vehicle year bar (full width)
    echo "<div class='lmd-box'><h3>Vehicle Year</h3>
        <div class='lmd-chart-wrap' style='height:220px'><canvas id='yearChart'></canvas></div>
    </div>";

    // Row 3: top models horizontal bar
    echo "<div class='lmd-box'><h3>Top Models</h3>
        <div class='lmd-chart-wrap' style='height:max(280px," . min(count($models) * 36, 560) . "px)'><canvas id='modelChart'></canvas></div>
    </div>";

    echo "<script>
    (function(){
        const C={$colors};
        new Chart(document.getElementById('fuelChart'),{type:'doughnut',data:{labels:{$fl},datasets:[{data:{$fv},backgroundColor:C}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right'}}}});
        new Chart(document.getElementById('transChart'),{type:'doughnut',data:{labels:{$tl},datasets:[{data:{$tv},backgroundColor:C}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right'}}}});
        new Chart(document.getElementById('yearChart'),{type:'bar',data:{labels:{$yl},datasets:[{label:'Leads',data:{$yv},backgroundColor:'#dba617'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});
        new Chart(document.getElementById('modelChart'),{type:'bar',data:{labels:{$ml},datasets:[{label:'Leads',data:{$mv},backgroundColor:'#a2105c'}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{stepSize:1}}}}});
    })();
    </script>";
}

// ─── Tab 6: Time Patterns ────────────────────────────────────────────────────

function lmd_tab_time_patterns(string $period, array $dates): void {
    global $wpdb;
    $ts  = current_time('timestamp');
    $rdw = lmd_raw_date_where($dates);

    // Day of week (1=Sun…7=Sat)
    $dow_rows = $wpdb->get_results(
        "SELECT DAYOFWEEK(post_date) as dow, COUNT(*) as cnt FROM {$wpdb->posts}
         WHERE post_type='lead' AND post_status='publish' {$rdw}
         GROUP BY DAYOFWEEK(post_date) ORDER BY dow"
    );
    $dow_counts = array_fill(1, 7, 0);
    foreach ($dow_rows as $r) $dow_counts[(int)$r->dow] = (int)$r->cnt;
    $dow_labels = json_encode(['Sun','Mon','Tue','Wed','Thu','Fri','Sat']);
    $dow_values = json_encode(array_values($dow_counts));
    // Weekend bars grey, weekday bars blue
    $dow_colors = json_encode(['#8c8f94','#2271b1','#2271b1','#2271b1','#2271b1','#2271b1','#8c8f94']);

    // Hour of day
    $hour_rows = $wpdb->get_results(
        "SELECT HOUR(post_date) as hr, COUNT(*) as cnt FROM {$wpdb->posts}
         WHERE post_type='lead' AND post_status='publish' {$rdw}
         GROUP BY HOUR(post_date) ORDER BY hr"
    );
    $hour_vals = array_fill(0, 24, 0);
    foreach ($hour_rows as $r) $hour_vals[(int)$r->hr] = (int)$r->cnt;
    $hour_labels = json_encode(array_map(fn($h) => sprintf('%02d:00', $h), range(0, 23)));
    $hour_values = json_encode(array_values($hour_vals));

    // Monthly trend — last 6 months (always, regardless of period filter)
    $monthly = $wpdb->get_results($wpdb->prepare(
        "SELECT YEAR(post_date) as yr, MONTH(post_date) as mo, COUNT(*) as cnt
         FROM {$wpdb->posts}
         WHERE post_type='lead' AND post_status='publish' AND post_date >= %s
         GROUP BY YEAR(post_date), MONTH(post_date) ORDER BY yr, mo",
        date('Y-m-01', strtotime('-5 months', $ts)) . ' 00:00:00'
    ));
    $m_labels = $m_values = [];
    for ($i = 5; $i >= 0; $i--) {
        $m_labels[] = date('M Y', strtotime("-{$i} months", $ts));
        $m_values[] = 0;
    }
    foreach ($monthly as $r) {
        $lbl = date('M Y', mktime(0, 0, 0, (int)$r->mo, 1, (int)$r->yr));
        $idx = array_search($lbl, $m_labels);
        if ($idx !== false) $m_values[$idx] = (int)$r->cnt;
    }
    $ml = json_encode($m_labels);
    $mv = json_encode($m_values);

    // Row 1: day-of-week + monthly comparison
    echo "<div class='lmd-two-col'>
        <div class='lmd-box'><h3>Leads by Day of Week</h3><div class='lmd-chart-wrap'><canvas id='dowChart'></canvas></div></div>
        <div class='lmd-box'><h3>Monthly Volume (Last 6 Months)</h3><div class='lmd-chart-wrap'><canvas id='monthChart'></canvas></div></div>
    </div>";

    // Row 2: hour of day (full width)
    echo "<div class='lmd-box'><h3>Leads by Hour of Day</h3>
        <div class='lmd-chart-wrap' style='height:220px'><canvas id='hourChart'></canvas></div>
    </div>";

    echo "<script>
    (function(){
        new Chart(document.getElementById('dowChart'),{type:'bar',data:{labels:{$dow_labels},datasets:[{label:'Leads',data:{$dow_values},backgroundColor:{$dow_colors}}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});
        new Chart(document.getElementById('monthChart'),{type:'bar',data:{labels:{$ml},datasets:[{label:'Leads',data:{$mv},backgroundColor:'#00a32a'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});
        new Chart(document.getElementById('hourChart'),{type:'bar',data:{labels:{$hour_labels},datasets:[{label:'Leads',data:{$hour_values},backgroundColor:'#3582c4'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});
    })();
    </script>";

    // Insight table
    $peak_dow_idx = array_search(max($dow_counts), $dow_counts);
    $peak_day     = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][$peak_dow_idx - 1] ?? '—';
    $peak_hr_idx  = array_search(max($hour_vals), $hour_vals);
    $peak_hour    = sprintf('%02d:00–%02d:00', $peak_hr_idx, $peak_hr_idx + 1);

    echo "<div class='lmd-box'><h3>Pattern Insights</h3>
        <table class='widefat'><tbody>
            <tr><th>Busiest day of week</th><td><strong>{$peak_day}</strong></td></tr>
            <tr><th>Busiest hour</th><td><strong>{$peak_hour}</strong></td></tr>
            <tr><th>Total leads (selected period)</th><td><strong>" . number_format(array_sum($dow_counts)) . "</strong></td></tr>
        </tbody></table>
    </div>";
}

// ─── Main render ─────────────────────────────────────────────────────────────

function render_reports_dashboard_page(): void {
    if (!current_user_can('manage_options')) wp_die('Access denied.');

    $valid_periods = ['today','7days','30days','90days','this_month','last_month','all_time'];
    $valid_tabs    = ['overview','sources','agents','geographic','vehicles','time'];

    $period = isset($_GET['period']) ? sanitize_key($_GET['period']) : '30days';
    $tab    = isset($_GET['tab'])    ? sanitize_key($_GET['tab'])    : 'overview';
    if (!in_array($period, $valid_periods, true)) $period = '30days';
    if (!in_array($tab,    $valid_tabs,    true)) $tab    = 'overview';

    $dates    = lmd_period_dates($period);
    $base_url = admin_url('admin.php?page=lead-reports-dashboard');

    // Chart.js — output inline so it's guaranteed available before chart init scripts
    echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.9/dist/chart.umd.min.js"></script>';

    // ── Styles ────────────────────────────────────────────────────────────────
    echo '<style>
.lmd-r { font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
.lmd-r h1 { font-size:1.5rem; margin-bottom:4px; }
.lmd-r .lmd-period-bar { display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin:14px 0 18px; }
.lmd-r .lmd-period-bar strong { color:#50575e; font-size:.85em; }
.lmd-r .lmd-period-bar a { padding:5px 12px; border:1px solid #c3c4c7; border-radius:3px; font-size:.82em; text-decoration:none; color:#2c3338; background:#fff; line-height:1; }
.lmd-r .lmd-period-bar a.lmd-active { background:#2271b1; color:#fff; border-color:#2271b1; font-weight:600; }
.lmd-r .nav-tab-wrapper { border-bottom:1px solid #c3c4c7; margin-bottom:0; }
.lmd-r .nav-tab { font-size:.88em; }
.lmd-r .lmd-kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin:20px 0 24px; }
.lmd-r .lmd-kpi { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:20px 16px; text-align:center; box-shadow:0 1px 2px rgba(0,0,0,.04); }
.lmd-r .lmd-kpi-icon { display:block; font-size:1.6em; margin-bottom:8px; }
.lmd-r .lmd-kpi-num  { display:block; font-size:2.2em; font-weight:700; color:#1d2327; line-height:1; }
.lmd-r .lmd-kpi-lbl  { display:block; font-size:.78em; text-transform:uppercase; letter-spacing:.06em; color:#646970; margin:7px 0 5px; }
.lmd-r .lmd-kpi-chg  { display:block; font-size:.8em; font-weight:600; }
.lmd-r .lmd-pos { color:#00a32a; }
.lmd-r .lmd-neg { color:#d63638; }
.lmd-r .lmd-box { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:20px; margin-bottom:20px; }
.lmd-r .lmd-box h3 { margin:0 0 14px; font-size:.95em; color:#1d2327; border-bottom:1px solid #f0f0f1; padding-bottom:10px; }
.lmd-r .lmd-chart-wrap { position:relative; height:300px; }
.lmd-r .lmd-two-col { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.lmd-r .lmd-nodata { color:#646970; text-align:center; padding:30px 0; font-style:italic; }
.lmd-r .lmd-tab-content { padding-top:20px; }
@media(max-width:1100px){ .lmd-r .lmd-kpi-grid{ grid-template-columns:repeat(2,1fr); } }
@media(max-width:700px){ .lmd-r .lmd-two-col,.lmd-r .lmd-kpi-grid{ grid-template-columns:1fr; } }
</style>';

    echo '<div class="wrap lmd-r">';
    echo '<h1>Lead Performance Reports</h1>';
    echo '<hr class="wp-header-end">';

    // Period selector
    $period_opts = ['today'=>'Today','7days'=>'Last 7 Days','30days'=>'Last 30 Days','90days'=>'Last 90 Days','this_month'=>'This Month','last_month'=>'Last Month','all_time'=>'All Time'];
    echo '<div class="lmd-period-bar"><strong>Period:</strong>';
    foreach ($period_opts as $val => $lbl) {
        $cls = $period === $val ? 'lmd-active' : '';
        $url = esc_url(add_query_arg(['period' => $val, 'tab' => $tab], $base_url));
        echo "<a href='{$url}' class='{$cls}'>{$lbl}</a>";
    }
    echo '</div>';

    // Tab nav
    $tabs = ['overview'=>'Overview','sources'=>'Sources &amp; Campaigns','agents'=>'Agent Performance','geographic'=>'Geographic','vehicles'=>'Vehicle Analysis','time'=>'Time Patterns'];
    echo '<nav class="nav-tab-wrapper">';
    foreach ($tabs as $slug => $lbl) {
        $cls = $tab === $slug ? 'nav-tab nav-tab-active' : 'nav-tab';
        $url = esc_url(add_query_arg(['tab' => $slug, 'period' => $period], $base_url));
        echo "<a href='{$url}' class='{$cls}'>{$lbl}</a>";
    }
    echo '</nav>';

    echo '<div class="lmd-tab-content">';
    switch ($tab) {
        case 'overview':   lmd_tab_overview($period, $dates);   break;
        case 'sources':    lmd_tab_sources($period, $dates);    break;
        case 'agents':     lmd_tab_agents($period, $dates);     break;
        case 'geographic': lmd_tab_geographic($period, $dates); break;
        case 'vehicles':   lmd_tab_vehicles($period, $dates);   break;
        case 'time':       lmd_tab_time_patterns($period, $dates); break;
    }
    echo '</div>';
    echo '</div>'; // .wrap.lmd-r
}
