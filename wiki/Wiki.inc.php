<?php

namespace wiki;

require_once __DIR__ . '/WikiDatabase.inc.php';

/**
 * Config keys exposed as {{KEY}} placeholders in wiki content.
 * Each entry: 'KEY' => 'Human-readable description'.
 */
const WIKI_PLACEHOLDERS = [
    'CLUSTER_NAME'     => 'Name of the cluster',
    'ADMIN_NAMES'      => 'Name(s) of the administrators',
    'ADMIN_EMAIL'      => 'E-mail address of the administrators',
    'SLURM_LOGIN_NODE' => 'Hostname of the login node',
    'WIKI_LINK'        => 'Link to an external wiki',
];

/**
 * Validates a wiki page URL.
 * Allowed: lowercase alphanumeric and hyphens, separated by forward slashes.
 * Max length: 128 characters.
 *
 * @param string $url URL string to validate.
 * @return bool TRUE if valid, FALSE otherwise.
 */
function is_valid_wiki_url(string $url): bool {
    if (strlen($url) > 128) {
        return FALSE;
    }
    return (bool)preg_match('#^[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)*$#', $url);
}

/**
 * Replaces {{KEY}} placeholders in wiki HTML content with config values,
 * and {{wiki=url}} with a link to the given wiki page.
 * Called at render time, not at save time.
 *
 * @param string $html Raw wiki HTML with placeholders.
 * @return string Rendered HTML with all placeholders substituted and heading anchors injected.
 */
function render_wiki_content(string $html): string {
    // Config placeholders.
    foreach (WIKI_PLACEHOLDERS as $key => $_desc) {
        $value = \config($key);
        if ($value === \TO_BE_REPLACED) {
            $value = '';
        }
        $html = str_replace('{{' . $key . '}}', htmlspecialchars($value, ENT_QUOTES, 'UTF-8'), $html);
    }

    // {{wiki=url}} → link to wiki page (title from DB if available, URL as fallback).
    $db = WikiDatabase::getInstance();
    $html = preg_replace_callback(
        '/\{\{wiki=([a-z0-9][a-z0-9_\-]*(?:\/[a-z0-9][a-z0-9_\-]*)*)(?:\|([^}]*))?\}\}/',
        function (array $m) use ($db): string {
            $url   = $m[1];
            $page  = $db ? $db->getPage($url) : NULL;
            if (!empty($m[2])) {
                $label = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
            } elseif ($page) {
                $label = htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8');
            } else {
                $label = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            }
            // If no page exists, resolve via alias so the link doesn't dead-end.
            $href = '?action=wiki&amp;url=' . urlencode($url);
            if ($page === NULL && $db !== NULL) {
                $alias = $db->getAlias($url);
                if ($alias !== NULL) {
                    $href = '?action=wiki&amp;url=' . urlencode($alias['target_url']);
                    if ($alias['anchor'] !== '') {
                        $href .= '#' . rawurlencode($alias['anchor']);
                    }
                }
            }
            return '<a href="' . $href . '">' . $label . '</a>';
        },
        $html
    );

    [$html, $toc] = add_heading_anchors($html);
    if (count($toc) >= 3) {
        $html = build_toc($toc) . $html;
    }
    return $html;
}

/**
 * Single-pass function: adds id attributes to h1–h6 elements AND collects TOC data.
 * Using a single pass guarantees that TOC entries always reference the exact IDs
 * that were written into the HTML — consistency is structurally enforced.
 *
 * Slug rule: lowercase, sequences of non-alphanumeric chars → single hyphen, trimmed.
 * Duplicates get a numeric suffix (-2, -3, …).
 * Elements that already carry an id are included in the TOC with their existing id.
 *
 * @param string $html HTML string to process.
 * @return array Two-element array: [0] modified HTML string with id attributes injected,
 *               [1] array of TOC entries, each with 'level' (int), 'id' (string), 'text' (string).
 */
function add_heading_anchors(string $html): array {
    $seen = [];
    $toc  = [];
    $result = preg_replace_callback(
        '/<(h[1-6])(\s[^>]*)?>(.+?)<\/\1>/si',
        function (array $m) use (&$seen, &$toc): string {
            $tag     = $m[1];
            $attrs   = $m[2] ?? '';
            $content = $m[3];
            $level   = (int)$tag[1];
            $text    = strip_tags($content);

            if (preg_match('/\bid\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]*))/i', $attrs, $idm)) {
                // Already has an id — keep the element unchanged, but include in TOC.
                $existingId = $idm[1] !== '' ? $idm[1] : ($idm[2] !== '' ? $idm[2] : $idm[3]);
                $toc[] = ['level' => $level, 'id' => $existingId, 'text' => $text];
                return $m[0];
            }

            $slug = trim(
                preg_replace('/-+/', '-',
                    preg_replace('/[^a-z0-9]+/', '-', strtolower($text))
                ), '-'
            );
            if ($slug === '') return $m[0];
            $id = $slug;
            $n  = 2;
            while (isset($seen[$id])) {
                $id = $slug . '-' . $n++;
            }
            $seen[$id] = TRUE;
            $toc[] = ['level' => $level, 'id' => $id, 'text' => $text];
            return '<' . $tag . $attrs . ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">'
                 . $content . '</' . $tag . '>';
        },
        $html
    );
    return [$result ?? $html, $toc];
}

/**
 * Renders the table of contents card from TOC entries produced by add_heading_anchors().
 * Indentation is relative to the minimum heading level present.
 *
 * @param array $toc Array of TOC entries as returned by add_heading_anchors().
 * @return string HTML nav card element containing an ordered list of anchor links.
 */
function build_toc(array $toc): string {
    $minLevel = min(array_column($toc, 'level'));
    $items    = '';
    foreach ($toc as $entry) {
        $indent  = ($entry['level'] - $minLevel) * 1.2;
        $style   = $indent > 0 ? ' style="margin-left:' . $indent . 'em"' : '';
        $items  .= '<li' . $style . '>'
                 . '<a href="#' . htmlspecialchars($entry['id'], ENT_QUOTES, 'UTF-8') . '">'
                 . htmlspecialchars($entry['text'], ENT_QUOTES, 'UTF-8')
                 . '</a></li>';
    }
    return '<nav class="card wiki-toc float-start me-3 mb-3" style="min-width:160px;max-width:260px">'
         . '<div class="card-header p-2 fw-bold">Contents</div>'
         . '<div class="card-body p-2"><ol class="mb-0 ps-3">' . $items . '</ol></div>'
         . '</nav>';
}


/**
 * Checks whether the current user may read a page with the given visibility.
 * Does not assume the user is logged in.
 *
 * @param string $visibility One of the WikiDatabase::VISIBILITY_* constants.
 * @return bool TRUE if the current user is allowed to read the page, FALSE otherwise.
 */
function user_can_read(string $visibility): bool {
    switch ($visibility) {
        case WikiDatabase::VISIBILITY_PUBLIC:
            return TRUE;
        case WikiDatabase::VISIBILITY_USERS:
            return isset($_SESSION['USER']);
        case WikiDatabase::VISIBILITY_PRIVILEGED:
            return isset($_SESSION['USER']) && \auth\current_user_is_privileged();
        case WikiDatabase::VISIBILITY_ADMIN:
            return isset($_SESSION['USER']) && \auth\current_user_is_admin();
        default:
            return FALSE;
    }
}

/**
 * Returns the visibility values the current user is allowed to read.
 *
 * @return string[] Subset of WikiDatabase::VALID_VISIBILITIES the current user may access.
 */
function allowed_visibilities(): array {
    if (\auth\current_user_is_admin()) {
        return WikiDatabase::VALID_VISIBILITIES;
    }
    if (\auth\current_user_is_privileged()) {
        return [WikiDatabase::VISIBILITY_PUBLIC, WikiDatabase::VISIBILITY_USERS, WikiDatabase::VISIBILITY_PRIVILEGED];
    }
    if (isset($_SESSION['USER'])) {
        return [WikiDatabase::VISIBILITY_PUBLIC, WikiDatabase::VISIBILITY_USERS];
    }
    return [WikiDatabase::VISIBILITY_PUBLIC];
}
