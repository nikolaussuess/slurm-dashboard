<?php

namespace client\utils;

/**
 * Transitively resolves dependencies and prints them as HTML, with links to the respective jobs.
 */
class DependencyResolver {

    function __construct(\client\Client $dao) {
        $this->dao = $dao;
    }

    public function get_job(string $jobid): ?array {
        return $this->dao->get_job($jobid);
    }

    /**
     * Parse Slurm dependency string into structured form.
     * Handles both AND (,) and OR (?) operators.
     */
    private function parseSlurmDependency(string $depString): array {
        $depString = trim($depString);
        if ($depString === "") return ["and" => []];

        $andParts = explode(',', $depString);
        $andParsed = [];

        foreach ($andParts as $andPart) {
            $orParts = explode('?', $andPart);
            if (count($orParts) > 1) {
                $orParsed = [];
                foreach ($orParts as $orPart) {
                    $orParsed[] = $this->parseSingleDependency(trim($orPart));
                }
                $andParsed[] = ["or" => $orParsed];
            } else {
                $andParsed[] = $this->parseSingleDependency(trim($andPart));
            }
        }

        return ["and" => $andParsed];
    }

    private function parseSingleDependency(string $part): array
    {
        $tokens = explode(':', $part);
        $type = strtolower(array_shift($tokens));
        $jobids = [];

        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '') continue;

            $fulfilled = null;

            // Check for fulfillment annotation in parentheses
            if (preg_match('/^(\d+)\((fulfilled|unfulfilled)\)$/i', $tok, $m)) {
                $jid = $m[1];
                $fulfilled = strtolower($m[2]) === 'fulfilled';
            } else {
                // Remove any stray parentheses
                $jid = preg_replace('/\([^)]*\)/', '', $tok);
            }

            $jid = trim($jid);
            if ($jid === '') continue;

            $jobids[] = [
                "jobid" => (string)$jid,
                "fulfilled" => $fulfilled, // may be null
            ];
        }

        return [
            "type" => $type,
            "jobids" => $jobids
        ];
    }

    /**
     * Recursively collects all transitive dependencies.
     * Adds `fulfilled` property based on annotation or job state.
     */
    public function getTransitiveDependencies(string $jobid, array &$visited = []): array
    {
        if (isset($visited[$jobid])) return [];
        $visited[$jobid] = true;

        $job = $this->get_job($jobid);
        if (!$job) return [];

        $depString = $job['dependency'] ?? '';
        if ($depString === "") return [];

        $parsed = $this->parseSlurmDependency($depString);
        $results = [];

        foreach ($parsed["and"] as $depGroup) {
            $depItems = isset($depGroup["or"]) ? $depGroup["or"] : [$depGroup];
            foreach ($depItems as $dep) {
                foreach ($dep["jobids"] as $jidEntry) {
                    $jid = $jidEntry["jobid"];
                    $depJob = $this->get_job($jid);
                    $state = $depJob["job_state"] ?? "UNKNOWN";

                    // Determine fulfillment
                    $fulfilled = $jidEntry["fulfilled"];
                    if ($fulfilled === null) {
                        // Heuristic: COMPLETED = fulfilled, anything else = unfulfilled
                        $fulfilled = strtoupper($state) === "COMPLETED";
                    }

                    $results[] = [
                        "type" => $dep["type"],
                        "jobid" => $jid,
                        "job_state" => $state,
                        "fulfilled" => $fulfilled
                    ];

                    // Recurse into transitive dependencies
                    $results = array_merge($results, $this->getTransitiveDependencies($jid, $visited));
                }
            }
        }

        // Deduplicate by jobid
        $unique = [];
        foreach ($results as $r) {
            $unique[$r['jobid']] = $r;
        }

        return array_values($unique);
    }

    /**
     * Render HTML list including fulfillment
     */
    public function renderDependencyListHTML(string $jobid): string
    {
        $deps = $this->getTransitiveDependencies($jobid);
        if (empty($deps)) {
            return "Job {$jobid} has no dependencies or all dependencies are already satisfied.";
        }

        $html = "Job {$jobid} depends on:\n<ul>\n";
        foreach ($deps as $d) {
            $jobLink = sprintf(
                '<a href="/?action=job&job_id=%s">Job %s</a>',
                htmlspecialchars($d['jobid']),
                htmlspecialchars($d['jobid'])
            );

            $statusLabel = $d['fulfilled'] ? "✅ Fulfilled" : "❌ Unfulfilled";
            $jobState = is_array($d['job_state']) ? implode(',', $d['job_state']) : (string)$d['job_state'];

            $html .= sprintf(
                "  <li>%s, current state %s, %s (type: %s)</li>\n",
                $jobLink,
                htmlspecialchars($jobState),
                $statusLabel,
                htmlspecialchars($d['type'])
            );
        }
        $html .= "</ul>";

        return $html;
    }
}