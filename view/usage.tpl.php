<?php
namespace view\actions;

use TemplateLoader;

/**
 * Renders the full cluster-usage section including all nodes.
 * Fetches running-job summaries once and distributes them per node.
 * @param \client\Client $dao Client to query the data.
 * @return string The usage data as HTML string.
 */
function get_all_nodes_usage(\client\Client $dao): string {
    $contents = '';

    if(
        config('feature_resources_per_user') === 'all' ||
        config('feature_resources_per_user') === 'privileged' && \auth\current_user_is_privileged()
    )
        $running_jobs = $dao->get_running_jobs_summary();
    else
        $running_jobs = [];

    foreach ($dao->getNodeList() as $node) {
        $contents .= get_usage(
            $dao->get_node_info($node),
            $running_jobs ? _build_node_user_breakdown($running_jobs, $node) : []
        );
    }
    return $contents;
}

/**
 * Groups running-job summaries by user for a single node. p_low resources tracked separately via _pl suffix.
 * @param array $running_jobs Array of running jobs, as returns by \\Client\\get_running_jobs_summary
 * @param string $node Node name.
 */
function _build_node_user_breakdown(array $running_jobs, string $node): array {
    $breakdown = [];
    foreach ($running_jobs as $job) {
        if (!\utils\node_is_in_nodelist($node, $job['nodes_str'])) continue;
        $user = $job['user_name'];
        if (!isset($breakdown[$user])) {
            $breakdown[$user] = ['cpus' => 0, 'mem' => 0, 'gpus' => 0,
                                 'cpus_pl' => 0, 'mem_pl' => 0, 'gpus_pl' => 0,
                                 'jobs' => [], 'jobs_pl' => []];
        }
        $job_entry = ['cpus' => $job['cpus_per_node'], 'mem' => $job['mem_per_node'], 'gpus' => $job['gpus_per_node']];
        $is_plow = ($job['partition'] ?? '') === 'p_low';
        if ($is_plow) {
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
 * Converts HSL (h: 0–359, s/l: 0–1) to [R, G, B] each 0–255.
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
        ['key' => 'cpus', 'key_pl' => 'cpus_pl', 'total' => $cpu_total, 'show' => $cpu_total > 0, 'label' => 'CPUs by user:',   'suffix' => ' CPUs'],
        ['key' => 'mem',  'key_pl' => 'mem_pl',  'total' => $mem_total, 'show' => $has_mem,       'label' => 'Memory by user:', 'suffix' => ' MiB'],
        ['key' => 'gpus', 'key_pl' => 'gpus_pl', 'total' => $gpu_total, 'show' => $has_gpu,       'label' => 'GPUs by user:',   'suffix' => ' GPUs'],
    ];

    foreach ($resources as $res_def) {
        if (!$res_def['show'])
            continue;
        $total = $res_def['total'];
        $html .= '<tr><td>' . $res_def['label'] . '</td><td>';
        $html .= '<div class="progress" style="height:20px">';

        // Regular (colored) segments — one per individual job, with a white divider between jobs
        foreach ($user_breakdown as $user => $res) {
            $color  = _user_color($user);
            $user_e = htmlspecialchars($user, ENT_QUOTES, 'UTF-8');
            foreach ($res['jobs'] as $job) {
                $val = $job[$res_def['key']] ?? 0;
                if ($val <= 0)
                    continue;
                $pct     = min(100.0, round($val / $total * 100, 1));
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
                $pct     = min(100.0, round($val / $total * 100, 1));
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

    // Regular-jobs legend row
    $regular_badges = '';
    foreach ($user_breakdown as $user => $res) {
        if (($res['cpus'] ?? 0) <= 0 && ($res['mem'] ?? 0) <= 0 && ($res['gpus'] ?? 0) <= 0)
            continue;
        $color  = _user_color($user);
        $user_e = htmlspecialchars($user, ENT_QUOTES, 'UTF-8');
        $details = [];
        if (($res['cpus'] ?? 0) > 0)
            $details[] = number_format($res['cpus'], 0, '.', ',') . ' CPUs';
        if (($res['mem']  ?? 0) > 0 && $has_mem)
            $details[] =  number_format($res['mem'], 0, '.', ',') . ' MiB';
        if (($res['gpus'] ?? 0) > 0 && $has_gpu)
            $details[] = number_format($res['gpus'], 0, '.', ',') . ' GPUs';
        $regular_badges .= '<span class="badge" style="background-color:' . $color['hex'] . ';color:' . $color['text'] . '">'
                         . '<a href="?action=users&user_name=' . $user_e . '" style="color:' . $color['text'] . ';text-decoration:none">' . $user_e . '</a>'
                         . (empty($details) ? '' : ': ' . htmlspecialchars(implode(', ', $details), ENT_QUOTES, 'UTF-8'))
                         . '</span>';
    }
    $html .= '<tr><td>Normal jobs:</td>'
           . '<td><div style="display:flex;flex-wrap:wrap;gap:4px">' . $regular_badges . '</div></td></tr>';

    // p_low-jobs legend row (only when p_low jobs exist)
    if ($has_plow) {
        $plow_badges = '';
        foreach ($user_breakdown as $user => $res) {
            if (($res['cpus_pl'] ?? 0) <= 0 && ($res['mem_pl'] ?? 0) <= 0 && ($res['gpus_pl'] ?? 0) <= 0)
                continue;
            $user_e  = htmlspecialchars($user, ENT_QUOTES, 'UTF-8');
            $details = [];
            if (($res['cpus_pl'] ?? 0) > 0)
                $details[] = number_format($res['cpus_pl'], 0, '.', ',') . ' CPUs';
            if (($res['mem_pl']  ?? 0) > 0 && $has_mem)
                $details[] =  number_format($res['mem_pl'], 0, '.', ',') . ' MiB';
            if (($res['gpus_pl'] ?? 0) > 0 && $has_gpu)
                $details[] = number_format($res['gpus_pl'], 0, '.', ',') . ' GPUs';
            $plow_badges .= '<span class="badge bg-secondary progress-bar-striped text-white" style="background-size:1rem 1rem">'
                          . '<a href="?action=users&user_name=' . $user_e . '" class="text-white" style="text-decoration:none">' . $user_e . '</a>'
                          . (empty($details) ? '' : ': ' . htmlspecialchars(implode(', ', $details), ENT_QUOTES, 'UTF-8'))
                          . '</span>';
        }
        $html .= '<tr><td><span class="monospaced">p_low</span> jobs:</td>'
               . '<td><div style="display:flex;flex-wrap:wrap;gap:4px">' . $plow_badges . '</div></td></tr>';
    }

    return $html;
}

function get_usage(array $data, array $user_breakdown = []) : string {
    $contents = '';

    $templateBuilder = new TemplateLoader("nodeinfo.html");
    $templateBuilder->setParam("NODENAME", htmlspecialchars($data['node_name'], ENT_QUOTES, 'UTF-8'));

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

    $contents .= $templateBuilder->build();

    return $contents;
}