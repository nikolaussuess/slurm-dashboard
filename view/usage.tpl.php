<?php
namespace view\actions;

use TemplateLoader;

function get_usage(array $data) : string {
    $contents = '';

    $templateBuilder = new TemplateLoader("nodeinfo.html");
    $templateBuilder->setParam("NODENAME", $data['node_name']);

    $templateBuilder->setParam("CPU_PERCENTAGE", $data["alloc_cpus"]/$data["cpus"]*100);
    $templateBuilder->setParam("CPU_USED", $data["alloc_cpus"]);
    $templateBuilder->setParam("CPU_TOTAL", $data["cpus"]);

    // mem_total is the full memory *that is assigned to Slurm*, not the full memory of the node.
    // mem_free, however, is the sum of free memory.
    // Thus, mem_total-mem_free can be negative if and only if in slurm.conf the node does not have the
    // full RAM memory for RealMemory=. In order to avoid confusions, we set the minimum to 0.
    $templateBuilder->setParam("MEM_PERCENTAGE", max(0, ($data["mem_total"]-$data["mem_free"])/$data["mem_total"]*100));
    $templateBuilder->setParam("MEM_USED", max(0,$data["mem_total"] - $data["mem_free"]));
    $templateBuilder->setParam("MEM_TOTAL", $data["mem_total"]);
    $templateBuilder->setParam("ALLOC_MEM_PERCENTAGE", ($data["mem_alloc"])/$data["mem_total"]*100);
    $templateBuilder->setParam("ALLOC_MEM", $data["mem_alloc"]);

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
        $gpus_percentage = (int)$gpus_used / (int)$gpus * 100;
    }
    $templateBuilder->setParam("GPU_PERCENTAGE", $gpus_percentage);
    $templateBuilder->setParam("GPU_USED", $gpus_used);
    $templateBuilder->setParam("GPU_TOTAL", $gpus);

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
        $feature_str .= '<span class="feature">' . $feature . '</span> ';
    }
    $templateBuilder->setParam("FEATURES", $feature_str);

    $feature_str = "";
    foreach ($data["active_features"] as $feature){
        $feature_str .= '<span class="feature">' . $feature . '</span> ';
    }
    $templateBuilder->setParam("ACTIVE_FEATURES", $feature_str);

    $templateBuilder->setParam("ADDRESS", $data["address"]);
    $templateBuilder->setParam("HOSTNAME", $data["hostname"]);
    $templateBuilder->setParam("OPERATING_SYSTEM", $data["operating_system"] ?? '');
    $templateBuilder->setParam("OWNER", $data["owner"] ?? '');
    $templateBuilder->setParam("TRES", $data["tres"] ?? '');
    $templateBuilder->setParam("TRES_USED", $data["tres_used"] ?? '');
    $templateBuilder->setParam("BOOT_TIME", $data["boot_time"] ?? '');
    $templateBuilder->setParam("LAST_BUSY", $data["last_busy"] ?? '');
    $templateBuilder->setParam("PARTITIONS", count($data["partitions"]) > 0 ? '<li><span class="monospaced">' . implode('</li><li><span class="monospaced">', $data["partitions"]) . '</span></li>' : '');
    $templateBuilder->setParam("RESERVATION", $data["reservation"] ?? '');
    $templateBuilder->setParam("SLURM_VERSION", $data["slurm_version"] ?? '');

    $contents .= $templateBuilder->build();

    return $contents;
}