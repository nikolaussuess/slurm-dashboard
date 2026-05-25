<?php
namespace view\actions;

use TemplateLoader;

/**
 * Renders the full cluster-usage section including all nodes.
 * Fetches running-job summaries once and distributes them per node.
 * @param \client\Client $dao Client to query the data.
 * @param string $nonce JS nonce that allows to execute JavaScript
 * @param bool $show_users_requested whether the cluster usage view should show individual user's usage or not
 * @return string The usage data as HTML string.
 */
function get_all_nodes_usage(\client\Client $dao, string $nonce = '', bool $show_users_requested = FALSE): string {
    $feature = config('feature_resources_per_user');
    $can_show_users = ($feature === 'all') || ($feature === 'privileged' && \auth\current_user_is_privileged());

    $show_plow_overview = (config('feature_p_low_in_cluster_overview') === TRUE);

    // Fetch jobs when the per-user view is active OR the p_low overview feature needs it
    $need_jobs    = ($can_show_users && $show_users_requested) || $show_plow_overview;
    $running_jobs = $need_jobs ? $dao->get_running_jobs_summary() : [];

    $cluster_totals = [
        'cpus' => 0, 'cpus_alloc' => 0,
        'mem_total' => 0, 'mem_alloc' => 0,
        'gpus_total' => 0, 'gpus_used' => 0,
        'cpus_unusable' => 0, 'mem_unusable' => 0, 'gpus_unusable' => 0,
        'cpus_plow' => 0, 'mem_plow' => 0, 'gpus_plow' => 0,
    ];
    $cluster_user_breakdown = [];
    $node_infos = [];

    foreach ($dao->getNodeList() as $node) {
        $node_data    = $dao->get_node_info($node);
        $user_bkd = $running_jobs ? _build_node_user_breakdown($running_jobs, $node) : [];

        $cpu_total = (int)$node_data['cpus'];
        $cpu_alloc = (int)$node_data['alloc_cpus'];
        $mem_total = (int)$node_data['mem_total'];
        $mem_alloc = (int)$node_data['mem_alloc'];
        $gres      = $node_data['gres'];
        $gres_used = $node_data['gres_used'];
        if ($gres !== '') {
            $gpu_total = (int)preg_replace('/.*:(\d+)(?:\(.*\))?$/', '$1', $gres);
            $gpu_used  = (int)preg_replace('/.*:(\d+)(?:\(.*\))?$/', '$1', $gres_used);
        } else {
            $gpu_total = 0;
            $gpu_used  = 0;
        }

        $cluster_totals['cpus']      += $cpu_total;
        $cluster_totals['mem_total'] += $mem_total;
        $cluster_totals['gpus_total']+= $gpu_total;

        // Derive per-node job totals from the user breakdown (job API).
        // Use the maximum of node-API and job-API values: in some SLURM configurations
        // alloc_cpus/alloc_memory from the node endpoint may lag or return 0 while
        // the jobs endpoint already reports running allocations.
        $job_node_cpus = 0;
        $job_node_mem  = 0;
        $job_node_gpus = 0;
        foreach ($user_bkd as $res) {
            $job_node_cpus += ($res['cpus'] ?? 0) + ($res['cpus_pl'] ?? 0);
            $job_node_mem  += ($res['mem']  ?? 0) + ($res['mem_pl']  ?? 0);
            $job_node_gpus += ($res['gpus'] ?? 0) + ($res['gpus_pl'] ?? 0);
        }
        $cpu_alloc_eff = max($cpu_alloc, $job_node_cpus);
        $mem_alloc_eff = max($mem_alloc, $job_node_mem);
        $gpu_used_eff  = max($gpu_used,  $job_node_gpus);

        $cluster_totals['cpus_alloc'] += $cpu_alloc_eff;
        $cluster_totals['mem_alloc']  += $mem_alloc_eff;
        $cluster_totals['gpus_used']  += $gpu_used_eff;

        // A node is blocked when CPUs or memory are fully allocated - no new job can start there.
        if (
            ($cpu_total > 0 && $cpu_alloc_eff >= $cpu_total) ||
            ($mem_total > 0 && $mem_alloc_eff >= $mem_total)
        ) {
            $cluster_totals['cpus_unusable'] += max(0, $cpu_total - $cpu_alloc_eff);
            $cluster_totals['mem_unusable']  += max(0, $mem_total - $mem_alloc_eff);
            $cluster_totals['gpus_unusable'] += max(0, $gpu_total - $gpu_used_eff);
        }

        // p_low cluster totals (always when jobs data is available, for the overview bar)
        foreach ($user_bkd as $res) {
            $cluster_totals['cpus_plow'] += ($res['cpus_pl'] ?? 0);
            $cluster_totals['mem_plow']  += ($res['mem_pl']  ?? 0);
            $cluster_totals['gpus_plow'] += ($res['gpus_pl'] ?? 0);
        }

        // Per-user breakdown only when the user explicitly requested it
        if ($show_users_requested) {
            foreach ($user_bkd as $user => $res) {
                if ( ! isset($cluster_user_breakdown[$user]) ){
                    $cluster_user_breakdown[$user] = ['cpus' => 0, 'mem' => 0, 'gpus' => 0,
                                                      'cpus_pl' => 0, 'mem_pl' => 0, 'gpus_pl' => 0];
                }
                foreach (['cpus', 'mem', 'gpus', 'cpus_pl', 'mem_pl', 'gpus_pl'] as $k) {
                    $cluster_user_breakdown[$user][$k] += (int)($res[$k] ?? 0);
                }
            }
        }

        // Only expose per-user data in the node card when requested
        $node_infos[] = [$node_data, $show_users_requested ? $user_bkd : []];
    }
    ksort($cluster_user_breakdown);

    $contents = _render_cluster_summary(
        $cluster_totals,
        $cluster_user_breakdown,
        $can_show_users && $show_users_requested,
        $nonce,
        $can_show_users,
        $show_plow_overview
    );

    // Node-level toggle — must navigate to the exact same URLs as the cluster summary checkbox
    if ($can_show_users) {
        $node_toggle = $show_users_requested
            ? '<a href="?action=usage" class="btn btn-sm btn-outline-secondary">Hide users</a>'
            : '<a href="?action=usage&show_users=1" class="btn btn-sm btn-outline-secondary">Show users</a>';
    } else {
        $node_toggle = '';
    }

    foreach ($node_infos as [$node_data, $user_bkd])
        $contents .= get_usage($node_data, $user_bkd, $node_toggle);

    return $contents;
}

/**
 * Groups running-job summaries by user for a single node. p_low resources tracked separately via _pl suffix.
 * @param array $running_jobs Array of running jobs, as returned by \\Client\\get_running_jobs_summary
 * @param string $node Node name.
 */
function _build_node_user_breakdown(array $running_jobs, string $node): array {
    $breakdown = [];
    foreach ($running_jobs as $job) {
        if ( ! \utils\node_is_in_nodelist($node, $job['nodes_str']) )
            continue;

        $user = $job['user_name'];
        if (!isset($breakdown[$user])) {
            $breakdown[$user] = ['cpus' => 0, 'mem' => 0, 'gpus' => 0,
                                 'cpus_pl' => 0, 'mem_pl' => 0, 'gpus_pl' => 0,
                                 'jobs' => [], 'jobs_pl' => []];
        }
        $job_entry = ['cpus' => $job['cpus_per_node'], 'mem' => $job['mem_per_node'], 'gpus' => $job['gpus_per_node']];
        if ( ($job['partition'] ?? '') === 'p_low' ) {
            $breakdown[$user]['cpus_pl'] += $job['cpus_per_node'];
            $breakdown[$user]['mem_pl']  += $job['mem_per_node'];
            $breakdown[$user]['gpus_pl'] += $job['gpus_per_node'];
            $breakdown[$user]['jobs_pl'][] = $job_entry;
        } else {
            $breakdown[$user]['cpus'] += $job['cpus_per_node'];
            $breakdown[$user]['mem']  += $job['mem_per_node'];
            $breakdown[$user]['gpus'] += $job['gpus_per_node'];
            $breakdown[$user]['jobs'][] = $job_entry;
        }
    }
    ksort($breakdown);
    return $breakdown;
}

/**
 * Renders the cluster-wide resource summary card.
 * Shows stacked progress bars for CPUs, Memory, and GPUs across all nodes.
 * Striped yellow segments mark resources on nodes where all CPUs or memory are already taken.
 *
 * @param array $totals          Keys: cpus, cpus_alloc, mem_total, mem_alloc,
 *                                     gpus_total, gpus_used, cpus_unusable, mem_unusable, gpus_unusable
 * @param array $user_breakdown  [username => ['cpus','mem','gpus','cpus_pl','mem_pl','gpus_pl']]
 * @param bool  $show_users      Whether per-user breakdown is currently active (GET param set)
 * @param string $nonce          CSP nonce for the inline script
 * @param bool  $can_show_users  Whether the current user is allowed to see the per-user breakdown
 */
function _render_cluster_summary(array $totals, array $user_breakdown, bool $show_users, string $nonce = '', bool $can_show_users = FALSE, bool $show_plow = FALSE): string {
    $has_gpu  = $totals['gpus_total'] > 0;
    $has_plow = $show_users && (
        array_sum(array_column($user_breakdown, 'cpus_pl')) +
        array_sum(array_column($user_breakdown, 'mem_pl'))  +
        array_sum(array_column($user_breakdown, 'gpus_pl')) > 0
    );

    $html  = '<div class="card mb-3" id="cluster-summary-card">' . "\n";
    $html .= '<div class="card-header d-flex justify-content-between align-items-center">'
           . '<strong>Cluster-wide resource summary</strong>';
    if ($can_show_users) {
        $html .= '<div class="form-check form-switch mb-0">'
               . '<input class="form-check-input" type="checkbox" id="clusterShowUsers"' . ($show_users ? ' checked' : '') . '>'
               . '<label class="form-check-label" for="clusterShowUsers">Show users</label>'
               . '</div>';
    }
    $html .= '</div>' . "\n";

    $html .= '<div class="card-body py-2">' . "\n";
    $html .= '<table class="table table-borderless mb-0">' . "\n";

    $rows = [
        ['label' => 'CPUs',   'used' => $totals['cpus_alloc'], 'total' => $totals['cpus'],
         'unusable' => $totals['cpus_unusable'], 'plow' => $show_plow ? $totals['cpus_plow'] : 0,
         'key' => 'cpus', 'key_pl' => 'cpus_pl', 'suffix' => ' CPUs'],
        ['label' => 'Memory', 'used' => $totals['mem_alloc'],  'total' => $totals['mem_total'],
         'unusable' => $totals['mem_unusable'],  'plow' => $show_plow ? $totals['mem_plow']  : 0,
         'key' => 'mem',  'key_pl' => 'mem_pl',  'suffix' => ' MiB'],
        ['label' => 'GPUs',   'used' => $totals['gpus_used'],  'total' => $totals['gpus_total'],
         'unusable' => $totals['gpus_unusable'], 'plow' => $show_plow ? $totals['gpus_plow'] : 0,
         'key' => 'gpus', 'key_pl' => 'gpus_pl', 'suffix' => ' GPUs'],
    ];
    foreach ($rows as $row) {
        if ((int)$row['total'] <= 0)
            continue;

        $html .= _render_cluster_bar_row_html($row, $user_breakdown, $show_users);
    }

    if ($show_users && !empty($user_breakdown))
        $html .= _render_cluster_legend_row($user_breakdown, $totals['mem_total'] > 0, $has_gpu, $has_plow);

    if (!$show_users && $show_plow && ($totals['cpus_plow'] > 0 || $totals['mem_plow'] > 0 || $totals['gpus_plow'] > 0)) {
        $html .= '<tr><td></td><td><small class="text-muted">'
               . '<span style="display:inline-block;width:14px;height:14px;vertical-align:middle;'
               . 'background:repeating-linear-gradient(45deg,#0d6efd,#0d6efd 4px,#9ec5fe 4px,#9ec5fe 8px);'
               . 'border:1px solid #ccc"></span>'
               . ' Striped: <span class="monospaced">p_low</span> partition jobs'
               . '</small></td></tr>' . "\n";
    }
    if ($totals['cpus_unusable'] > 0 || $totals['mem_unusable'] > 0 || $totals['gpus_unusable'] > 0) {
        $html .= '<tr><td></td><td><small class="text-muted">'
               . '<span style="display:inline-block;width:14px;height:14px;vertical-align:middle;'
               . 'background:repeating-linear-gradient(45deg,#ffc107,#ffc107 4px,#fff3cd 4px,#fff3cd 8px);'
               . 'border:1px solid #ccc"></span>'
               . ' Unusable: free resources on nodes where all CPUs or memory are already allocated'
               . '</small></td></tr>' . "\n";
    }

    $html .= '</table>' . "\n";
    $html .= '</div>' . "\n";
    $html .= '</div>' . "\n";

    if ($can_show_users) {
        $nonce_attr = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';
        $html .= '<script' . $nonce_attr . '>'
               . 'document.getElementById("clusterShowUsers").addEventListener("change",function(){'
               . 'window.location.href="?action=usage&show_users="+(this.checked?"1":"0");'
               . '});'
               . '</script>' . "\n";
    }

    return $html;
}

/**
 * Renders one &lt;tr&gt; for the cluster summary table: label + stacked progress bar + text summary.
 */
function _render_cluster_bar_row_html(array $res_def, array $user_breakdown, bool $show_users): string {
    $label    = $res_def['label'];
    $used     = (int)$res_def['used'];
    $total    = (int)$res_def['total'];
    $unusable = (int)$res_def['unusable'];
    $key      = $res_def['key'];
    $key_pl   = $res_def['key_pl'];
    $suffix   = $res_def['suffix'];

    $pct_used     = min(100.0, round($used / $total * 100, 1));
    $unusable_cap = max(0, min($total - $used, $unusable));
    $pct_unusable = round($unusable_cap / $total * 100, 1);

    $html  = '<tr><td style="white-space:nowrap;padding-right:12px">'
           . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ':</td>';
    $html .= '<td style="width:99%">';
    $html .= '<div class="progress" style="height:22px">';

    if ($show_users && !empty($user_breakdown)) {
        foreach ($user_breakdown as $user => $res) {
            foreach (['' => $key, '_pl' => $key_pl] as $type_sfx => $k) {
                $val = (int)($res[$k] ?? 0);
                if ($val <= 0)
                    continue;

                $pct    = min(100.0, round($val / $total * 100, 1));
                $user_e = htmlspecialchars($user, ENT_QUOTES, 'UTF-8');
                $is_pl  = ($type_sfx === '_pl');
                $tip    = $user_e . ($is_pl ? ' (p_low)' : '') . ': '
                        . number_format($val, 0, '.', ',') . $suffix . ' (' . $pct . '%)';
                if ($is_pl) {
                    $seg_cls = 'progress-bar progress-bar-striped bg-secondary';
                    $seg_sty = 'width:' . $pct . '%;border-right:2px solid rgba(255,255,255,0.55)';
                    $seg_txt = $pct >= 10 ? $user_e : '';
                } else {
                    $color   = _user_color($user);
                    $seg_cls = 'progress-bar';
                    $seg_sty = 'width:' . $pct . '%;background-color:' . $color['hex']
                             . ';color:' . $color['text'] . ';border-right:2px solid rgba(255,255,255,0.55)';
                    $seg_txt = $pct >= 8 ? $user_e : '';
                }
                $html .= '<div class="' . $seg_cls . '" role="progressbar" style="' . $seg_sty . '"'
                       . ' data-bs-toggle="tooltip" data-bs-trigger="hover focus"'
                       . ' title="' . htmlspecialchars($tip, ENT_QUOTES, 'UTF-8') . '">'
                       . $seg_txt . '</div>';
            }
        }
    } else {
        $plow   = max(0, min((int)($res_def['plow'] ?? 0), $used));
        $normal = $used - $plow;

        if ($normal > 0) {
            $pct_normal = round($normal / $total * 100, 1);
            $tip_n = 'In use: ' . number_format($normal, 0, '.', ',') . $suffix . ' (' . $pct_normal . '%)';
            $html .= '<div class="progress-bar bg-primary" role="progressbar"'
                   . ' style="width:' . $pct_normal . '%"'
                   . ' data-bs-toggle="tooltip" data-bs-trigger="hover focus"'
                   . ' title="' . htmlspecialchars($tip_n, ENT_QUOTES, 'UTF-8') . '">'
                   . $pct_normal . '%</div>';
        }
        if ($plow > 0) {
            $pct_plow = round($plow / $total * 100, 1);
            $tip_p = 'p_low jobs: ' . number_format($plow, 0, '.', ',') . $suffix . ' (' . $pct_plow . '%)';
            $html .= '<div class="progress-bar" role="progressbar"'
                   . ' style="width:' . $pct_plow . '%;background:repeating-linear-gradient(45deg,#0d6efd,#0d6efd 6px,#9ec5fe 6px,#9ec5fe 12px)"'
                   . ' data-bs-toggle="tooltip" data-bs-trigger="hover focus"'
                   . ' title="' . htmlspecialchars($tip_p, ENT_QUOTES, 'UTF-8') . '">'
                   . $pct_plow . '%</div>';
        }
    }

    if ($pct_unusable > 0) {
        $tip_u = 'Unusable (CPU-full nodes): '
               . number_format($unusable_cap, 0, '.', ',') . $suffix . ' (' . $pct_unusable . '%)';
        $html .= '<div class="progress-bar progress-bar-striped bg-warning text-dark" role="progressbar"'
               . ' style="width:' . $pct_unusable . '%"'
               . ' data-bs-toggle="tooltip" data-bs-trigger="hover focus"'
               . ' title="' . htmlspecialchars($tip_u, ENT_QUOTES, 'UTF-8') . '"></div>';
    }

    $html .= '</div>'; // .progress
    $html .= '<small class="text-muted">'
           . number_format($used, 0, '.', ',') . ' / ' . number_format($total, 0, '.', ',')
           . $suffix . ' in use (' . $pct_used . '%)';
    if ($pct_unusable > 0) {
        $html .= ' &mdash; <span class="text-warning fw-bold">'
               . number_format($unusable_cap, 0, '.', ',') . $suffix . ' unusable</span>';
    }
    $html .= '</small>';
    $html .= '</td></tr>' . "\n";

    return $html;
}

/**
 * Builds the badge HTML for all users in a breakdown (regular or p_low jobs).
 * Returns a concatenated string of <span class="badge"> elements.
 */
function _build_user_badges(array $user_breakdown, bool $is_plow, bool $has_mem, bool $has_gpu): string {
    $sfx  = $is_plow ? '_pl' : '';
    $html = '';
    foreach ($user_breakdown as $user => $res) {
        if (($res['cpus'.$sfx] ?? 0) <= 0 && ($res['mem'.$sfx] ?? 0) <= 0 && ($res['gpus'.$sfx] ?? 0) <= 0)
            continue;
        $user_e = htmlspecialchars($user, ENT_QUOTES, 'UTF-8');
        $det    = [];
        if (($res['cpus'.$sfx] ?? 0) > 0)
            $det[] = number_format($res['cpus'.$sfx], 0, '.', ',') . ' CPUs';
        if (($res['mem'.$sfx]  ?? 0) > 0 && $has_mem)
            $det[] = number_format($res['mem'.$sfx],  0, '.', ',') . ' MiB';
        if (($res['gpus'.$sfx] ?? 0) > 0 && $has_gpu)
            $det[] = number_format($res['gpus'.$sfx], 0, '.', ',') . ' GPUs';
        $det_str = empty($det) ? '' : ': ' . htmlspecialchars(implode(', ', $det), ENT_QUOTES, 'UTF-8');
        if ($is_plow) {
            $html .= '<span class="badge bg-secondary progress-bar-striped text-white" style="background-size:1rem 1rem">'
                   . '<a href="?action=users&user_name=' . $user_e . '" class="text-white" style="text-decoration:none">'
                   . $user_e . '</a>' . $det_str . '</span>';
        } else {
            $color = _user_color($user);
            $html .= '<span class="badge" style="background-color:' . $color['hex'] . ';color:' . $color['text'] . '">'
                   . '<a href="?action=users&user_name=' . $user_e . '" style="color:' . $color['text'] . ';text-decoration:none">'
                   . $user_e . '</a>' . $det_str . '</span>';
        }
    }
    return $html;
}

/**
 * Renders badge legend rows (regular + p_low) for the cluster summary.
 */
function _render_cluster_legend_row(array $user_breakdown, bool $has_mem, bool $has_gpu, bool $has_plow): string {
    $html = '<tr><td style="white-space:nowrap">Users:</td>'
          . '<td><div style="display:flex;flex-wrap:wrap;gap:4px">'
          . _build_user_badges($user_breakdown, FALSE, $has_mem, $has_gpu)
          . '</div></td></tr>' . "\n";
    if ($has_plow) {
        $html .= '<tr><td style="white-space:nowrap"><span class="monospaced">p_low</span>:</td>'
               . '<td><div style="display:flex;flex-wrap:wrap;gap:4px">'
               . _build_user_badges($user_breakdown, TRUE, $has_mem, $has_gpu)
               . '</div></td></tr>' . "\n";
    }
    return $html;
}

/**
 * Converts HSL (h: 0–359, s/l: 0–1) to [R, G, B] each 0–255.
 * Used to give users an individual color (based on their hash(username)).
 */
function _hsl_to_rgb(int $h, float $s, float $l): array {
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60.0, 2) - 1));
    $m = $l - $c / 2;
    if( $h < 60 )
        [$r, $g, $b] = [$c, $x, 0];
    elseif( $h < 120 )
        [$r, $g, $b] = [$x, $c, 0];
    elseif( $h < 180 )
        [$r, $g, $b] = [0,  $c, $x];
    elseif( $h < 240 )
        [$r, $g, $b] = [0,  $x, $c];
    elseif( $h < 300 )
        [$r, $g, $b] = [$x, 0,  $c];
    else
        [$r, $g, $b] = [$c, 0,  $x];
    return [(int)round(($r+$m)*255), (int)round(($g+$m)*255), (int)round(($b+$m)*255)];
}

/**
 * Returns a stable {hex background, CSS text color} for a username.
 * Hue is derived from the md5 hash; saturation/lightness are fixed for vibrant,
 * readable colors. Text color is chosen via WCAG relative-luminance threshold.
 */
function _user_color(string $user): array {
    $hue = hexdec(substr(md5($user), 0, 4)) % 360;
    [$r, $g, $b] = _hsl_to_rgb($hue, 0.65, 0.45);
    $hex = sprintf('#%02x%02x%02x', $r, $g, $b);
    $luminance = 0.2126 * ($r / 255) + 0.7152 * ($g / 255) + 0.0722 * ($b / 255);
    return ['hex' => $hex, 'text' => ($luminance > 0.22 ? '#222' : '#fff')];
}

/**
 * Renders the per-user resource breakdown as table rows with stacked progress bars.
 * p_low partition jobs are shown as gray striped segments and listed separately.
 * Returns an empty string when no jobs are running on the node.
 */
function _render_user_breakdown(array $user_breakdown, int $cpu_total, int $mem_total, int $gpu_total): string {
    if (empty($user_breakdown))
        return '';

    // Check totals across both regular and p_low resources
    $has_mem  = $mem_total > 0 && (
        array_sum(array_column($user_breakdown, 'mem')) +
        array_sum(array_column($user_breakdown, 'mem_pl')) > 0
    );
    $has_gpu  = $gpu_total > 0 && (
        array_sum(array_column($user_breakdown, 'gpus')) +
        array_sum(array_column($user_breakdown, 'gpus_pl')) > 0
    );
    $has_plow = (
        array_sum(array_column($user_breakdown, 'cpus_pl')) +
        array_sum(array_column($user_breakdown, 'mem_pl')) +
        array_sum(array_column($user_breakdown, 'gpus_pl')) > 0
    );

    $html = '<tr><td colspan="2"><strong>Current resource usage per user</strong></td></tr>';

    // One stacked-bar row per resource type
    $resources = [
        ['key' => 'cpus', 'key_pl' => 'cpus_pl', 'total' => $cpu_total, 'show' => $cpu_total > 0,
         'label' => 'CPUs by user:', 'suffix' => ' CPUs',
         'tooltip' => 'CPUs currently allocated to running jobs on this node, broken down per user. Each colored segment represents one individual job.'],
        ['key' => 'mem',  'key_pl' => 'mem_pl',  'total' => $mem_total, 'show' => $has_mem,
         'label' => 'Memory by user:', 'suffix' => ' MiB',
         'tooltip' => 'RAM currently allocated to running jobs on this node, broken down per user. Each colored segment represents one individual job.'],
        ['key' => 'gpus', 'key_pl' => 'gpus_pl', 'total' => $gpu_total, 'show' => $has_gpu,
         'label' => 'GPUs by user:', 'suffix' => ' GPUs',
         'tooltip' => 'GPUs currently allocated to running jobs on this node, broken down per user. Each colored segment represents one individual job.'],
    ];

    foreach ($resources as $res_def) {
        if (!$res_def['show'])
            continue;
        $total = $res_def['total'];
        $label_btn = '<button type="button" class="btn p-0 text-start" data-bs-toggle="tooltip" data-bs-trigger="click focus" data-bs-placement="top"'
                   . ' title="' . htmlspecialchars($res_def['tooltip'], ENT_QUOTES, 'UTF-8') . '">'
                   . '<i title="Click here for more information">&#9432;</i>&nbsp;' . $res_def['label']
                   . '</button>';
        $html .= '<tr><td>' . $label_btn . '</td><td>';
        $html .= '<div class="progress" style="height:20px">';

        // Regular (colored) segments — one per individual job, with a white divider between jobs
        foreach ($user_breakdown as $user => $res) {
            $color  = _user_color($user);
            $user_e = htmlspecialchars($user, ENT_QUOTES, 'UTF-8');
            foreach ($res['jobs'] as $job) {
                $val = $job[$res_def['key']] ?? 0;

                if ($val <= 0)
                    continue;

                $pct   = min(100.0, round($val / $total * 100, 1));
                $val_f = number_format($val, 0, '.', ',');
                $tooltip = $user_e . ': ' . $val_f . $res_def['suffix'] . ' (' . $pct . '%)';
                $html .= '<div class="progress-bar" role="progressbar"'
                       . ' style="width:' . $pct . '%;background-color:' . $color['hex'] . ';color:' . $color['text'] . ';border-right:2px solid rgba(255,255,255,0.55)"'
                       . ' data-bs-toggle="tooltip" data-bs-trigger="hover focus"'
                       . ' title="' . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . '">'
                       . ($pct >= 12 ? $user_e : '') . '</div>';
            }
        }

        // p_low (gray striped) segments — one per individual job
        foreach ($user_breakdown as $user => $res) {
            $user_e = htmlspecialchars($user, ENT_QUOTES, 'UTF-8');
            foreach ($res['jobs_pl'] as $job) {
                $val = $job[$res_def['key']] ?? 0;

                if ($val <= 0)
                    continue;

                $pct   = min(100.0, round($val / $total * 100, 1));
                $val_f = number_format($val, 0, '.', ',');
                $tooltip = $user_e . ' (p_low): ' . $val_f . $res_def['suffix'] . ' (' . $pct . '%)';
                $html .= '<div class="progress-bar progress-bar-striped bg-secondary" role="progressbar"'
                       . ' style="width:' . $pct . '%;border-right:2px solid rgba(255,255,255,0.55)"'
                       . ' data-bs-toggle="tooltip" data-bs-trigger="hover focus"'
                       . ' title="' . htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') . '">'
                       . ($pct >= 12 ? $user_e : '') . '</div>';
            }
        }

        $html .= '</div></td></tr>';
    }

    // Legend rows
    $html .= '<tr><td>'
           . '<button type="button" class="btn p-0 text-start" data-bs-toggle="tooltip" data-bs-trigger="click focus" data-bs-placement="top"'
           . ' title="Users with running jobs on this node and their total resource usage (sum of all their jobs).">'
           . '<i title="Click here for more information">&#9432;</i>&nbsp;Normal jobs:'
           . '</button>'
           . '</td>'
           . '<td><div style="display:flex;flex-wrap:wrap;gap:4px">'
           . _build_user_badges($user_breakdown, FALSE, $has_mem, $has_gpu)
           . '</div></td></tr>';
    if ($has_plow) {
        $html .= '<tr><td>'
               . '<button type="button" class="btn p-0 text-start" data-bs-toggle="tooltip" data-bs-trigger="click focus" data-bs-placement="top"'
               . ' title="Users with running jobs in the low-priority partition (p_low) on this node.">'
               . '<i title="Click here for more information">&#9432;</i>&nbsp;<span class="monospaced">p_low</span> jobs:'
               . '</button>'
               . '</td>'
               . '<td><div style="display:flex;flex-wrap:wrap;gap:4px">'
               . _build_user_badges($user_breakdown, TRUE, $has_mem, $has_gpu)
               . '</div></td></tr>';
    }

    return $html;
}

function get_usage(array $data, array $user_breakdown = [], string $show_users_toggle = '') : string {
    $contents = '';

    $templateBuilder = new TemplateLoader("nodeinfo.html");
    $nodename_e = htmlspecialchars($data['node_name'], ENT_QUOTES, 'UTF-8');
    $templateBuilder->setParam("NODENAME", $nodename_e);

    if (\auth\current_user_is_admin()) {
        $node_state_actions =
            '<li><a class="dropdown-item" href="?action=node-set-state&nodename=' . $nodename_e . '&state=resume">Resume node</a></li>' .
            '<li><a class="dropdown-item" href="?action=node-set-state&nodename=' . $nodename_e . '&state=drain">Drain node</a></li>';
    } else {
        $node_state_actions =
            '<li><a class="dropdown-item disabled" aria-disabled="true" tabindex="-1">Resume node</a></li>' .
            '<li><a class="dropdown-item disabled" aria-disabled="true" tabindex="-1">Drain node</a></li>';
    }
    $templateBuilder->setParam("NODE_STATE_ACTIONS", $node_state_actions);

    $templateBuilder->setParam("CPU_PERCENTAGE", number_format($data["cpus"] > 0 ? $data["alloc_cpus"]/$data["cpus"]*100 : 0, 2));
    $templateBuilder->setParam("CPU_USED", $data["alloc_cpus"]);
    $templateBuilder->setParam("CPU_TOTAL", $data["cpus"]);

    // mem_total is the full memory *that is assigned to Slurm*, not the full memory of the node.
    // mem_free, however, is the sum of free memory.
    // Thus, mem_total-mem_free can be negative if and only if in slurm.conf the node does not have the
    // full RAM memory for RealMemory=. In order to avoid confusions, we set the minimum to 0.
    $templateBuilder->setParam("MEM_PERCENTAGE", number_format($data["mem_total"] > 0 ? max(0, ($data["mem_total"]-$data["mem_free"])/$data["mem_total"]*100) : 0, 2));
    $templateBuilder->setParam("MEM_USED", number_format(max(0,$data["mem_total"] - $data["mem_free"]), 0, '.', ','));
    $templateBuilder->setParam("MEM_TOTAL", number_format($data["mem_total"], 0, '.', ','));
    $templateBuilder->setParam("ALLOC_MEM_PERCENTAGE", number_format($data["mem_total"] > 0 ? $data["mem_alloc"]/$data["mem_total"]*100 : 0, 2));
    $templateBuilder->setParam("ALLOC_MEM", number_format($data["mem_alloc"], 0, '.', ','));

    $gres = $data["gres"];
    $gres_used = $data["gres_used"];
    if($gres == ""){
        $gpus = 0;
        $gpus_used = 0;
        $gpus_percentage = 0;
    }
    else {

        $gpus = preg_replace('/.*:(\d+)(?:\(.*\))?$/', '$1', $gres);
        $gpus_used = preg_replace('/.*:(\d+)(?:\(.*\))?$/', '$1', $gres_used);
        // For debugging
        //echo "GPUs='$gpus', gpus_used='$gpus_used', gres='$gres', gres_used='$gres_used'";
        $gpus_percentage = (int)$gpus > 0 ? (int)$gpus_used / (int)$gpus * 100 : 0;
    }
    $templateBuilder->setParam("GPU_PERCENTAGE", number_format($gpus_percentage, 2));
    $templateBuilder->setParam("GPU_USED", $gpus_used);
    $templateBuilder->setParam("GPU_TOTAL", $gpus);

    $templateBuilder->setParam("USER_RESOURCE_BREAKDOWN", _render_user_breakdown(
        $user_breakdown,
        (int)$data['cpus'],
        (int)$data['mem_total'],
        (int)$gpus
    ));

    $templateBuilder->setParam("STATE", implode(", ", $data["state"]));
    $state_color = "#f9c98f"; # orange
    if(
        in_array("IDLE", $data["state"]) ||
        in_array("MIX", $data["state"]) ||
        in_array("MIXED", $data["state"]) ||
        in_array("ALLOC", $data["state"]) ||
        in_array("ALLOCATED", $data["state"])
    ){
        $state_color = "#c1dead"; # green
    }
    elseif (
        in_array("DOWN", $data["state"]) ||
        in_array("DRAIN", $data["state"]) ||
        in_array("DRAINED", $data["state"]) ||
        in_array("DRAINING", $data["state"]) ||
        in_array("FAIL", $data["state"])
    ) {
        $state_color = "#deadae"; # Red
    }
    $templateBuilder->setParam("STATE_COLOR", $state_color);

    $templateBuilder->setParam("ARCHITECTURE", $data["architecture"] ?? '');
    $templateBuilder->setParam("BOARDS", $data["boards"] ?? '');

    $feature_str = "";
    foreach ($data["features"] as $feature){
        $feature_str .= '<span class="feature">' . htmlspecialchars($feature, ENT_QUOTES, 'UTF-8') . '</span> ';
    }
    $templateBuilder->setParam("FEATURES", $feature_str);

    $feature_str = "";
    foreach ($data["active_features"] as $feature){
        $feature_str .= '<span class="feature">' . htmlspecialchars($feature, ENT_QUOTES, 'UTF-8') . '</span> ';
    }
    $templateBuilder->setParam("ACTIVE_FEATURES", $feature_str);

    $templateBuilder->setParam("ADDRESS", $data["address"]);
    $templateBuilder->setParam("HOSTNAME", htmlspecialchars($data["hostname"], ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("OPERATING_SYSTEM", htmlspecialchars($data["operating_system"] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("OWNER", htmlspecialchars($data["owner"] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("TRES", htmlspecialchars($data["tres"] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("TRES_USED", htmlspecialchars($data["tres_used"] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("BOOT_TIME", $data["boot_time"] ?? '');
    $templateBuilder->setParam("LAST_BUSY", $data["last_busy"] ?? '');
    $escaped_partitions = array_map(fn($p) => htmlspecialchars($p, ENT_QUOTES, 'UTF-8'), $data["partitions"]);
    $templateBuilder->setParam("PARTITIONS", count($escaped_partitions) > 0 ? '<li><span class="monospaced">' . implode('</span></li><li><span class="monospaced">', $escaped_partitions) . '</span></li>' : '');
    $templateBuilder->setParam("RESERVATION", htmlspecialchars($data["reservation"] ?? '', ENT_QUOTES, 'UTF-8'));
    $templateBuilder->setParam("SLURM_VERSION", $data["slurm_version"] ?? '');
    $templateBuilder->setParam("SHOW_USERS_TOGGLE", $show_users_toggle);

    $contents .= $templateBuilder->build();

    return $contents;
}