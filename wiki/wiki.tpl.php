<?php

namespace view\wiki;

require_once __DIR__ . '/Wiki.inc.php';
require_once __DIR__ . '/WikiFiles.inc.php';

/**
 * Builds a breadcrumb HTML string for a wiki URL like "feature/module-nvhpc".
 * Intermediate segments are linked if a wiki page exists for them.
 *
 * @param string $url Wiki page URL to build the breadcrumb for.
 * @return string Bootstrap breadcrumb <nav> HTML element.
 */
function get_breadcrumb(string $url): string {
    $db    = \wiki\WikiDatabase::getInstance();
    $parts = explode('/', $url);
    $html  = '<nav aria-label="breadcrumb"><ol class="breadcrumb">';
    $html .= '<li class="breadcrumb-item"><a href="?action=wiki">Wiki</a></li>';

    $accumulated = '';
    foreach ($parts as $i => $part) {
        $accumulated = $accumulated === '' ? $part : $accumulated . '/' . $part;
        $isLast      = ($i === count($parts) - 1);

        if ($isLast) {
            $html .= '<li class="breadcrumb-item active" aria-current="page">'
                   . htmlspecialchars($part, ENT_QUOTES, 'UTF-8') . '</li>';
        } else {
            $label = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
            if ($db && $db->pageExists($accumulated)) {
                $html .= '<li class="breadcrumb-item"><a href="?action=wiki&amp;url='
                       . urlencode($accumulated) . '">' . $label . '</a></li>';
            } else {
                $html .= '<li class="breadcrumb-item">' . $label . '</li>';
            }
        }
    }

    $html .= '</ol></nav>';
    return $html;
}

/**
 * Renders the wiki overview page listing all pages grouped by top-level segment.
 *
 * @return string HTML listing of all visible wiki pages, plus admin controls for privileged users.
 */
function get_wiki_overview(): string {
    $db      = \wiki\WikiDatabase::getInstance();
    $allRows = $db->getAllPages();
    $isPriv  = \auth\current_user_is_privileged();

    if (empty($allRows)) {
        $html = '<p class="text-muted">No wiki pages yet.</p>';
        if ($isPriv) {
            $html .= '<a href="?action=wiki&amp;do=edit" class="btn btn-primary">Create first page</a>';
        }
        return $html;
    }

    // Collect visible pages.
    $pageMap = []; // url => page row
    foreach ($allRows as $page) {
        if (\wiki\user_can_read($page['visibility'])) {
            $pageMap[$page['url']] = $page;
        }
    }

    if (empty($pageMap)) {
        return '<p class="text-muted">No wiki pages available.</p>';
    }

    // Build parent→children map. For each visible page, register every ancestor
    // segment so intermediate nodes without their own page are still reachable.
    $children = []; // parent_url => [child_url, ...]
    $seen     = []; // dedup guard
    foreach (array_keys($pageMap) as $url) {
        $parts = explode('/', $url);
        for ($depth = 1; $depth <= count($parts); $depth++) {
            $segment = implode('/', array_slice($parts, 0, $depth));
            $parent  = $depth === 1 ? '' : implode('/', array_slice($parts, 0, $depth - 1));
            if (!isset($seen[$parent][$segment])) {
                $seen[$parent][$segment] = TRUE;
                $children[$parent][]     = $segment;
            }
        }
    }

    // Sort children at each level alphabetically.
    foreach ($children as &$c) {
        sort($c);
    }
    unset($c);

    $html = _render_overview_list($pageMap, $children, '', $isPriv);

    if ($isPriv) {
        $html .= '<div class="mt-3"><a href="?action=wiki&amp;do=edit" class="btn btn-primary">New page</a></div>';
        $html .= get_wiki_alias_manager(\auth\get_csrf_token());
    }

    return $html;
}

/**
 * Renders the alias management block (privileged users only).
 * Aliases map virtual URLs (e.g. feature/avx512) to an existing page + optional anchor.
 *
 * @param string $csrfToken CSRF token to embed in the add/delete forms.
 * @return string HTML block with alias table and add form, wrapped in a <details> element.
 */
function get_wiki_alias_manager(string $csrfToken): string {
    $db      = \wiki\WikiDatabase::getInstance();
    $aliases = $db->getAllAliases();

    $rows = '';
    foreach ($aliases as $alias) {
        $src    = htmlspecialchars($alias['source_url'], ENT_QUOTES, 'UTF-8');
        $tgt    = htmlspecialchars($alias['target_url'], ENT_QUOTES, 'UTF-8');
        $anchor = htmlspecialchars($alias['anchor'],     ENT_QUOTES, 'UTF-8');
        $tgtDisplay = $tgt . ($alias['anchor'] !== '' ? '#' . $anchor : '');
        $tgtHref    = '?action=wiki&amp;url=' . urlencode($alias['target_url'])
                    . ($alias['anchor'] !== '' ? '#' . $anchor : '');
        $rows .= '<tr>'
               . '<td><code>' . $src . '</code></td>'
               . '<td><a href="' . $tgtHref . '">' . $tgtDisplay . '</a></td>'
               . '<td>'
               . '<form method="post" action="?action=wiki" style="display:inline">'
               . '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">'
               . '<input type="hidden" name="do" value="delete_alias">'
               . '<input type="hidden" name="source_url" value="' . $src . '">'
               . '<button type="submit" class="btn btn-sm btn-outline-danger"'
               . ' onclick="return confirm(\'Delete alias ' . $src . '?\')">Delete</button>'
               . '</form>'
               . '</td>'
               . '</tr>';
    }

    $tableOrEmpty = empty($aliases)
        ? '<p class="text-muted mb-2">No aliases defined yet.</p>'
        : '<table class="table table-sm"><thead><tr><th>Source URL</th><th>Target</th><th></th></tr></thead>'
          . '<tbody>' . $rows . '</tbody></table>';

    $urlPat    = '[a-z0-9][a-z0-9_\\-]*(/[a-z0-9][a-z0-9_\\-]*)*';
    $anchorPat = '[a-z0-9_-]*';
    $csrf      = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<details class="mt-4">
  <summary class="fw-bold" style="cursor:pointer">URL Aliases</summary>
  <div class="mt-2">
    <p class="text-muted small">
      An alias maps a virtual URL (e.g. <code>feature/avx512</code>) to a target page with an
      optional anchor (e.g. page <code>features</code>, anchor <code>avx512</code>).
      Auto-links use the alias when no page exists at the source URL.<br>
      Headings on wiki pages receive auto-generated anchors: lowercase, sequences of
      non-alphanumeric characters replaced by a single hyphen
      (e.g. heading <em>NVIDIA H100 GPU</em> &rarr; anchor <code>nvidia-h100-gpu</code>).
    </p>
    {$tableOrEmpty}
    <form method="post" action="?action=wiki" class="row g-2 mt-1">
      <input type="hidden" name="csrf_token" value="{$csrf}">
      <input type="hidden" name="do" value="save_alias">
      <div class="col-auto">
        <input type="text" class="form-control form-control-sm" name="source_url"
               placeholder="Source URL (e.g. feature/avx512)"
               pattern="{$urlPat}" maxlength="128" required>
      </div>
      <div class="col-auto">
        <input type="text" class="form-control form-control-sm" name="target_url"
               placeholder="Target page URL (e.g. features)"
               pattern="{$urlPat}" maxlength="128" required>
      </div>
      <div class="col-auto">
        <input type="text" class="form-control form-control-sm" name="anchor"
               placeholder="Anchor (optional, e.g. avx512)"
               pattern="{$anchorPat}" maxlength="64">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary">Add alias</button>
      </div>
    </form>
  </div>
</details>
HTML;
}

/**
 * Recursively renders one <ul> level of the wiki overview tree.
 * $parent '' means top-level pages (no slash in URL).
 *
 * @param array<string, array> $pageMap  Map of url → page row for all visible pages.
 * @param array<string, array> $children Map of parent_url → list of child URLs.
 * @param string               $parent   URL of the current parent node, or '' for the root level.
 * @param bool                 $isPriv   Whether to render Edit links next to page titles.
 * @return string HTML <ul> element for this level, or empty string if $parent has no children.
 */
function _render_overview_list(array $pageMap, array $children, string $parent, bool $isPriv): string {
    if (empty($children[$parent])) {
        return '';
    }

    $html = '<ul>';
    foreach ($children[$parent] as $url) {
        $html .= '<li>';
        if (isset($pageMap[$url])) {
            $href  = '?action=wiki&amp;url=' . urlencode($url);
            $label = htmlspecialchars($pageMap[$url]['title'], ENT_QUOTES, 'UTF-8');
            $html .= '<a href="' . $href . '">' . $label . '</a>';
            if ($isPriv) {
                $html .= ' <a href="' . $href . '&amp;do=edit" class="btn btn-sm btn-outline-secondary ms-2">Edit</a>';
            }
        } else {
            // Intermediate segment without its own page — show as plain label.
            $segment = ucfirst(str_replace('-', ' ', basename($url)));
            $html .= '<span class="text-muted">' . htmlspecialchars($segment, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $html .= _render_overview_list($pageMap, $children, $url, $isPriv);
        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
}

/**
 * Renders a single wiki page by URL.
 *
 * @param string $url         Wiki page URL to render.
 * @param string $cspNonce    Optional CSP nonce added to inline <script> tags.
 * @param string $sidebarHtml Optional HTML prepended inside the wiki-content wrapper (e.g. a node info card).
 * @return array Three-element array: [0] HTTP status code (int), [1] page title (string), [2] rendered HTML (string).
 */
function get_wiki_page(string $url, string $cspNonce = '', string $sidebarHtml = ''): array {
    $db   = \wiki\WikiDatabase::getInstance();
    $page = $db->getPage($url);

    if ($page === NULL) {
        if (\auth\current_user_is_privileged()) {
            $html = '<p class="text-muted">This page does not exist yet.</p>'
                  . '<a href="?action=wiki&amp;url=' . urlencode($url) . '&amp;do=edit"'
                  . ' class="btn btn-primary">Create this page</a>';
            return [404, '404 – Page not found', get_breadcrumb($url) . $html];
        }
        return [404, '404 – Page not found', '<p>404 Not Found.</p>'];
    }

    if (!\wiki\user_can_read($page['visibility'])) {
        return [403, '403 – Forbidden', '<p>403 Forbidden.</p>'];
    }

    $editBtn = '';
    if (\auth\current_user_is_privileged()) {
        $editBtn = '<div class="mb-3">'
                 . '<a href="?action=wiki&amp;url=' . urlencode($url) . '&amp;do=edit"'
                 . ' class="btn btn-sm btn-outline-secondary">Edit</a>'
                 . ' <a href="?action=wiki&amp;url=' . urlencode($url) . '&amp;do=files"'
                 . ' class="btn btn-sm btn-outline-secondary ms-1">Files</a>'
                 . '</div>';
    }

    $nonceAttr = $cspNonce !== '' ? ' nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') . '"' : '';
    $hljs = <<<HTML
<link rel="stylesheet" href="/lib/highlight/github.min.css">
<script src="/lib/highlight/highlight.min.js"></script>
<script src="/lib/highlight/highlightjs-line-numbers.min.js"></script>
<style>
table.hljs-ln td.hljs-ln-numbers { padding: 0 16px 0 8px !important; text-align: right; vertical-align: top; user-select: none; border-right: 1px solid #ddd; }
table.hljs-ln td.hljs-ln-numbers .hljs-ln-n::before { color: #bbb; }
table.hljs-ln td.hljs-ln-code { padding: 0 8px 0 16px !important; }
</style>
<style>
.wiki-content .wiki-heading-anchor { margin-left: .4em; opacity: 0; font-size: .8em; text-decoration: none; color: #6c757d; transition: opacity .15s; }
.wiki-content h1:hover .wiki-heading-anchor,
.wiki-content h2:hover .wiki-heading-anchor,
.wiki-content h3:hover .wiki-heading-anchor,
.wiki-content h4:hover .wiki-heading-anchor,
.wiki-content h5:hover .wiki-heading-anchor,
.wiki-content h6:hover .wiki-heading-anchor { opacity: 1; }
</style>
<script{$nonceAttr}>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll(
        '.wiki-content h1[id], .wiki-content h2[id], .wiki-content h3[id],' +
        '.wiki-content h4[id], .wiki-content h5[id], .wiki-content h6[id]'
    ).forEach(function (h) {
        var a = document.createElement('a');
        a.href = '#' + h.id;
        a.className = 'wiki-heading-anchor';
        a.textContent = '#';
        h.appendChild(a);
    });

    document.querySelectorAll('.wiki-content pre').forEach(function (pre) {
        // Quill 2.x outputs <pre data-language="..."> without an inner <code>.
        // Wrap all child nodes in <code> so highlight.js can process them.
        var code = pre.querySelector('code');
        if (!code) {
            code = document.createElement('code');
            while (pre.firstChild) {
                code.appendChild(pre.firstChild);
            }
            pre.appendChild(code);
        }
        var lang = pre.getAttribute('data-language');
        if (lang && lang !== 'plaintext') {
            code.classList.add('language-' + lang);
        }
        hljs.highlightElement(code);
        hljs.lineNumbersBlock(code);
    });
});
</script>
HTML;

    $childPages = \wiki\WikiDatabase::getInstance()->getChildPages($url, \wiki\allowed_visibilities());
    $childBlock  = '';
    if (!empty($childPages)) {
        $childBlock = '<div class="wiki-subpages mt-4"><strong>Subpages:</strong><ul class="mt-1">';
        foreach ($childPages as $child) {
            $childHref   = '?action=wiki&amp;url=' . urlencode($child['url']);
            $childLabel  = htmlspecialchars($child['title'], ENT_QUOTES, 'UTF-8');
            $childBlock .= '<li><a href="' . $childHref . '">' . $childLabel . '</a></li>';
        }
        $childBlock .= '</ul></div>';
    }

    $attachmentsBlock = '';
    $pageFiles = $db->getFilesForPage($url);
    if (!empty($pageFiles)) {
        $attachmentsBlock = '<div class="wiki-attachments mt-3"><strong>Attachments:</strong>'
                          . '<ul class="mt-1 mb-0">';
        foreach ($pageFiles as $f) {
            $fileUrl  = '/get_file.php?id=' . urlencode($f['stored_name']);
            $fname_e  = htmlspecialchars($f['filename'], ENT_QUOTES, 'UTF-8');
            $fsize    = \wiki\format_file_size((int)$f['file_size']);
            $attachmentsBlock .= '<li><a href="' . $fileUrl . '">' . $fname_e . '</a>'
                               . ' <small class="text-muted">(' . $fsize . ')</small></li>';
        }
        $attachmentsBlock .= '</ul></div>';
    }

    $updatedAt  = date('Y-m-d H:i', (int)$page['updated_at']);
    $updatedBy  = $page['updated_by'] ?? '';
    $lastEdited = '<div class="text-muted small mt-3 wiki-last-edited">Last edited: ' . $updatedAt
                . ($updatedBy !== '' ? ' by ' . htmlspecialchars($updatedBy, ENT_QUOTES, 'UTF-8') : '')
                . '</div>';

    $html = get_breadcrumb($url)
          . $editBtn
          . '<div class="wiki-content">' . $sidebarHtml . \wiki\render_wiki_content($page['content']) . '</div>'
          . '<div style="clear:both"></div>'
          . $childBlock
          . $attachmentsBlock
          . $lastEdited
          . $hljs;

    return [200, htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8'), $html];
}

/**
 * Renders a summary card with node hardware info (CPUs, RAM, GPUs, partitions, features).
 * Partition and feature names are auto-linked to their wiki pages if one exists.
 * Intended to be passed as $sidebarHtml to get_wiki_page() for node/* pages.
 *
 * @param array $node_data Slurm node data array from slurmrestd.
 * @return string HTML card element floating to the right, or empty string if no relevant data is present.
 */
function get_node_slurm_block(array $node_data): string {
    $partitions = array_filter($node_data['partitions']       ?? []);
    $features   = array_filter($node_data['features']         ?? []);
    $active     = array_filter($node_data['active_features']  ?? []);

    if (empty($partitions) && empty($features) && empty($active)
        && empty($node_data['cpus']) && empty($node_data['mem_total'])) {
        return '';
    }

    $rows = [];

    // CPUs
    if (!empty($node_data['cpus'])) {
        $rows[] = ['CPUs', (int)$node_data['cpus']];
    }

    // RAM: mem_total is in MiB
    if (!empty($node_data['mem_total'])) {
        $mib = (int)$node_data['mem_total'];
        $rows[] = ['RAM', $mib >= 1024 ? round($mib / 1024) . ' GiB' : $mib . ' MiB'];
    }

    // GPUs: parse comma-separated GRES string for "gpu:..." entries
    $gres_str = $node_data['gres'] ?? '';
    if ($gres_str !== '') {
        $gpu_lines = [];
        foreach (explode(',', $gres_str) as $entry) {
            $entry = trim(preg_replace('/\(.*\)/', '', $entry)); // strip "(IDX:...)"
            $parts = explode(':', $entry);
            if (($parts[0] ?? '') !== 'gpu') continue;
            // formats: gpu:count  or  gpu:type:count
            if (count($parts) === 2) {
                $gpu_lines[] = (int)$parts[1] . '×';
            } elseif (count($parts) >= 3) {
                $gpu_lines[] = (int)$parts[2] . '× ' . htmlspecialchars($parts[1], ENT_QUOTES, 'UTF-8');
            }
        }
        if (!empty($gpu_lines)) {
            $items = implode('', array_map(fn($l) => '<li>' . $l . '</li>', $gpu_lines));
            $rows[] = ['GPUs', '<ul class="mb-0 ps-3">' . $items . '</ul>'];
        }
    }

    if (!empty($partitions)) {
        $items = '';
        foreach ($partitions as $p) {
            $inner  = '<span class="monospaced">' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</span>';
            $items .= '<li>' . \utils\auto_link_partition($inner, $p) . '</li>';
        }
        $rows[] = ['Partitions', '<ul class="mb-0 ps-3">' . $items . '</ul>'];
    }

    if (!empty($features)) {
        $items = '';
        foreach ($features as $f) {
            $inner  = '<span class="feature">' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '</span>';
            $items .= '<li>' . \utils\auto_link_feature($inner, $f) . '</li>';
        }
        $rows[] = ['Features', '<ul class="mb-0 ps-3">' . $items . '</ul>'];
    }

    if (!empty($active) && $active !== $features) {
        $items = '';
        foreach ($active as $f) {
            $inner  = '<span class="feature">' . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . '</span>';
            $items .= '<li>' . \utils\auto_link_feature($inner, $f) . '</li>';
        }
        $rows[] = ['Active features', '<ul class="mb-0 ps-3">' . $items . '</ul>'];
    }

    $html  = '<div class="card wiki-node-slurm-info float-end ms-3 mb-3" style="min-width:220px;max-width:420px">';
    $html .= '<div class="card-header p-2 fw-bold">Short info</div>';
    $html .= '<div class="card-body p-2"><table class="mb-0" style="border-collapse:collapse">';
    foreach ($rows as [$label, $value]) {
        $html .= '<tr>'
               . '<td class="pe-3 fw-bold align-top text-nowrap">' . $label . '</td>'
               . '<td class="align-top">' . $value . '</td>'
               . '</tr>';
    }
    $html .= '</table></div></div>';
    return $html;
}

/**
 * Renders the edit/create form for a wiki page.
 *
 * @param string $url       Wiki page URL to edit, or empty string when creating a new page.
 * @param string $csrfToken CSRF token to embed in the form.
 * @param string $cspNonce  CSP nonce added to inline <script> tags.
 * @return string Full HTML of the edit form, including the Quill editor and image picker modal.
 */
function get_wiki_edit_form(string $url, string $csrfToken, string $cspNonce): string {
    $db    = \wiki\WikiDatabase::getInstance();
    $page  = $url !== '' ? $db->getPage($url) : NULL;
    $isNew = ($page === NULL);

    $title      = $isNew ? '' : htmlspecialchars($page['title'],   ENT_QUOTES, 'UTF-8');
    $content    = $isNew ? '' : htmlspecialchars($page['content'], ENT_QUOTES, 'UTF-8');
    $visibility = $isNew ? \wiki\WikiDatabase::VISIBILITY_USERS : $page['visibility'];
    $showInNav  = $isNew ? 0 : (int)$page['show_in_nav'];

    $visibilityOptions = '';
    foreach (\wiki\WikiDatabase::VALID_VISIBILITIES as $v) {
        $selected           = ($v === $visibility) ? ' selected' : '';
        $visibilityOptions .= '<option value="' . $v . '"' . $selected . '>'
                            . ucfirst($v) . '</option>';
    }

    $placeholderRows = '';
    foreach (\wiki\WIKI_PLACEHOLDERS as $key => $desc) {
        $value           = \config($key);
        $displayValue    = ($value === \TO_BE_REPLACED || $value === '')
                         ? '<em class="text-muted">not configured</em>'
                         : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $placeholderRows .= '<tr>'
                          . '<td><code class="wiki-placeholder-insert" style="cursor:pointer" title="Click to insert">{{' . $key . '}}</code></td>'
                          . '<td>' . $desc . '</td>'
                          . '<td>' . $displayValue . '</td>'
                          . '</tr>';
    }
    $placeholderRows .= '<tr>'
                      . '<td><code class="wiki-placeholder-insert" style="cursor:pointer" title="Click to insert">{{wiki=url}}</code></td>'
                      . '<td>Link to another wiki page (title as label). Optionally: <code>{{wiki=url|Display text}}</code></td>'
                      . '<td><em class="text-muted">—</em></td>'
                      . '</tr>';

    $deleteButton = '';
    $renameHint   = '';
    if (!$isNew) {
        $deleteButton = '<button type="submit" name="do" value="delete"'
                      . ' class="btn btn-danger ms-2"'
                      . ' onclick="return confirm(\'Delete this page?\')">Delete</button>';
        $renameHint = '<div class="mb-2 form-check" id="wiki-rename-alias-wrap" style="display:none">'
                    . '<input type="checkbox" class="form-check-input" id="wiki-rename-alias"'
                    . ' name="rename_alias" value="1" checked>'
                    . '<label class="form-check-label" for="wiki-rename-alias">'
                    . 'Create alias from old URL to new URL</label>'
                    . '</div>';
    }

    $breadcrumb = $url !== '' ? get_breadcrumb($url) : '';

    // Build image picker modal content from files already uploaded to this page.
    $imgPickerGrid = '';
    if (!$isNew) {
        $imgItems = '';
        foreach ($db->getFilesForPage($url) as $f) {
            if (!str_starts_with($f['mime_type'], 'image/')) continue;
            $fUrl  = '/get_file.php?id=' . urlencode($f['stored_name']);
            $fName = htmlspecialchars($f['filename'], ENT_QUOTES, 'UTF-8');
            $fUrlE = htmlspecialchars($fUrl, ENT_QUOTES, 'UTF-8');
            $imgItems .= '<div class="col-auto wiki-img-pick" style="cursor:pointer;max-width:120px"'
                       . ' data-url="' . $fUrlE . '" title="' . $fName . '">'
                       . '<img src="' . $fUrlE . '" style="max-width:112px;max-height:80px;object-fit:contain">'
                       . '<div class="small text-truncate mt-1">' . $fName . '</div>'
                       . '</div>';
        }
        $filesHref     = '?action=wiki&amp;url=' . urlencode($url) . '&amp;do=files';
        $imgPickerGrid = $imgItems !== ''
            ? '<div class="row g-2">' . $imgItems . '</div>'
            : '<p class="text-muted mb-0">No images uploaded yet.'
            . ' <a href="' . $filesHref . '" target="_blank">Upload files</a> first.</p>';
    } else {
        $imgPickerGrid = '<p class="text-muted mb-0">Save the page first, then upload images via the Files page.</p>';
    }

    $html = <<<HTML
{$breadcrumb}
<div class="modal fade" id="wiki-image-picker-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0">Insert image</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">{$imgPickerGrid}</div>
    </div>
  </div>
</div>
<form method="post" action="?action=wiki" id="wiki-edit-form">
  <input type="hidden" name="csrf_token" value="{$csrfToken}">
  <input type="hidden" name="original_url" value="{$url}">

  <div class="mb-3">
    <label for="wiki-url" class="form-label">URL <small class="text-muted">(e.g. <code>feature/module-nvhpc</code>)</small></label>
    <input type="text" class="form-control" id="wiki-url" name="url"
           value="{$url}" pattern="[a-z0-9][a-z0-9_\\-]*(/[a-z0-9][a-z0-9_\\-]*)*" maxlength="128" required>
  </div>

  {$renameHint}

  <div class="mb-3">
    <label for="wiki-title" class="form-label">Title</label>
    <input type="text" class="form-control" id="wiki-title" name="title" value="{$title}" maxlength="255" required>
  </div>

  <div class="mb-3">
    <label for="wiki-visibility" class="form-label">Visibility</label>
    <select class="form-select" id="wiki-visibility" name="visibility">
      {$visibilityOptions}
    </select>
  </div>

  <div class="mb-3">
    <label for="wiki-show-in-nav" class="form-label">
      Position in navigation dropdown
      <small class="text-muted">(0 = not shown; 1, 2, 3 … = sort order)</small>
    </label>
    <input type="number" class="form-control" id="wiki-show-in-nav" name="show_in_nav"
           value="{$showInNav}" min="0" style="max-width: 120px">
  </div>

  <div class="mb-2 d-flex gap-2 align-items-center">
    <label class="form-label mb-0">Content</label>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="wiki-toggle-editor">Switch to raw HTML</button>
  </div>

  <div id="wiki-editor-wysiwyg">
    <div id="quill-editor" style="min-height: 300px;"></div>
  </div>
  <div id="wiki-editor-raw" style="display:none">
    <textarea id="wiki-raw-textarea" class="form-control" rows="20" style="font-family:monospace"></textarea>
  </div>
  <input type="hidden" name="content" id="wiki-content-hidden" value="{$content}">

  <details class="mt-3 mb-3">
    <summary class="text-muted" style="cursor:pointer">Available placeholders</summary>
    <table class="table table-sm mt-2">
      <thead><tr><th>Placeholder</th><th>Description</th><th>Current value</th></tr></thead>
      <tbody>{$placeholderRows}</tbody>
    </table>
    <small class="text-muted">Click a placeholder to insert it at the cursor position.</small>
  </details>

  <div class="mt-3">
    <button type="submit" name="do" value="save" class="btn btn-primary">Save</button>
    {$deleteButton}
    <a href="?action=wiki" class="btn btn-secondary ms-2">Cancel</a>
  </div>
</form>

<link rel="stylesheet" href="/lib/quill/quill.snow.css">
<script src="/lib/quill/quill.min.js"></script>
<script nonce="{$cspNonce}">
(function () {
    // Extend the built-in Image blot to preserve style, title, width, and height
    // attributes. Quill's default Image blot only whitelists src/alt/width/height
    // and silently drops style, which would break manually sized/floated images.
    const ImageBlot = Quill.import('formats/image');
    const EXTRA_IMG_ATTRS = ['style', 'title', 'width', 'height'];
    class WikiImage extends ImageBlot {
        static formats(domNode) {
            const fmt = {};
            EXTRA_IMG_ATTRS.forEach(function (attr) {
                if (domNode.hasAttribute(attr)) fmt[attr] = domNode.getAttribute(attr);
            });
            return fmt;
        }
        format(name, value) {
            if (EXTRA_IMG_ATTRS.includes(name)) {
                if (value) { this.domNode.setAttribute(name, value); }
                else       { this.domNode.removeAttribute(name); }
            } else {
                super.format(name, value);
            }
        }
    }
    Quill.register('formats/image', WikiImage, true);

    const quill = new Quill('#quill-editor', {
        theme: 'snow',
        modules: {
            toolbar: {
                container: [
                    [{ header: [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike', 'code'],
                    ['blockquote', 'code-block'],
                    [{ list: 'ordered' }, { list: 'bullet' }],
                    ['link', 'image'],
                    ['clean']
                ]
            }
        }
    });

    // Language selector for code blocks.
    const LANGUAGES = [
        { value: '',           label: '— Language —' },
        { value: 'bash',       label: 'Bash / Shell' },
        { value: 'python',     label: 'Python' },
        { value: 'php',        label: 'PHP' },
        { value: 'javascript', label: 'JavaScript' },
        { value: 'c',          label: 'C / C++' },
        { value: 'sql',        label: 'SQL' },
        { value: 'yaml',       label: 'YAML' },
        { value: 'plaintext',  label: 'Plain text' },
    ];

    const langSelect = document.createElement('select');
    langSelect.id = 'wiki-code-lang';
    langSelect.className = 'form-select form-select-sm d-inline-block ms-2';
    langSelect.style.cssText = 'width:auto;display:none!important';
    langSelect.title = 'Language of the code block at cursor';
    LANGUAGES.forEach(function (l) {
        const opt = document.createElement('option');
        opt.value = l.value;
        opt.textContent = l.label;
        langSelect.appendChild(opt);
    });
    // Append after the Quill toolbar.
    document.getElementById('wiki-editor-wysiwyg').appendChild(langSelect);

    langSelect.addEventListener('change', function () {
        quill.format('code-block', this.value || true);
        quill.focus();
    });

    quill.on('selection-change', function (range) {
        if (!range) return;
        const format = quill.getFormat(range);
        const inCodeBlock = !!format['code-block'];
        langSelect.style.setProperty('display', inCodeBlock ? 'inline-block' : 'none', 'important');
        if (inCodeBlock) {
            const lang = typeof format['code-block'] === 'string' ? format['code-block'] : '';
            langSelect.value = lang;
        }
    });

    // Load saved content into Quill.
    const savedHtml = document.getElementById('wiki-content-hidden').value;
    if (savedHtml) {
        const delta = quill.clipboard.convert({ html: savedHtml });
        quill.setContents(delta, 'silent');
    }

    let rawMode = false;

    document.getElementById('wiki-toggle-editor').addEventListener('click', function () {
        if (!rawMode) {
            // WYSIWYG → Raw
            document.getElementById('wiki-editor-wysiwyg').style.display = 'none';
            document.getElementById('wiki-editor-raw').style.display     = '';
            // Quill 2.x getSemanticHTML() emits U+00A0 (non-breaking space) instead of
            // regular spaces. Normalise both the raw character and the &nbsp; entity form
            // so saved content stays clean and renders correctly everywhere.
            document.getElementById('wiki-raw-textarea').value           = quill.getSemanticHTML().replaceAll(' ', ' ').replaceAll('&nbsp;', ' ');
            this.textContent = 'Switch to WYSIWYG';
        } else {
            // Raw → WYSIWYG
            const rawHtml = document.getElementById('wiki-raw-textarea').value;
            const delta   = quill.clipboard.convert({ html: rawHtml });
            quill.setContents(delta, 'silent');
            document.getElementById('wiki-editor-raw').style.display     = 'none';
            document.getElementById('wiki-editor-wysiwyg').style.display = '';
            this.textContent = 'Switch to raw HTML';
        }
        rawMode = !rawMode;
    });

    // Sync content to hidden input before submit.
    document.getElementById('wiki-edit-form').addEventListener('submit', function () {
        if (rawMode) {
            document.getElementById('wiki-content-hidden').value = document.getElementById('wiki-raw-textarea').value;
        } else {
            // Same U+00A0 normalisation as above.
            document.getElementById('wiki-content-hidden').value = quill.getSemanticHTML().replaceAll(' ', ' ').replaceAll('&nbsp;', ' ');
        }
    });

    // Click-to-insert placeholders.
    document.querySelectorAll('.wiki-placeholder-insert').forEach(function (el) {
        el.addEventListener('click', function () {
            const placeholder = this.textContent;
            if (rawMode) {
                const ta  = document.getElementById('wiki-raw-textarea');
                const pos = ta.selectionStart;
                ta.value  = ta.value.slice(0, pos) + placeholder + ta.value.slice(ta.selectionEnd);
                ta.selectionStart = ta.selectionEnd = pos + placeholder.length;
                ta.focus();
            } else {
                const range = quill.getSelection(true);
                if (range) {
                    quill.insertText(range.index, placeholder);
                } else {
                    quill.insertText(quill.getLength() - 1, placeholder);
                }
            }
        });
    });

    // Replace the default base64 image handler with a picker for uploaded files.
    var imgPickerModal = document.getElementById('wiki-image-picker-modal');
    var imgPickerBody  = imgPickerModal.querySelector('.modal-body');
    var imgPickerPageUrl = document.querySelector('input[name="original_url"]').value;

    quill.getModule('toolbar').addHandler('image', function () {
        bootstrap.Modal.getOrCreateInstance(imgPickerModal).show();
    });

    // Refresh file list from server each time the modal opens so newly uploaded
    // images appear without a full page reload.
    imgPickerModal.addEventListener('show.bs.modal', function () {
        if (!imgPickerPageUrl) return;
        fetch('?action=wiki&url=' + encodeURIComponent(imgPickerPageUrl) + '&do=image_picker_json')
            .then(function (r) { return r.json(); })
            .then(function (images) {
                if (images.length === 0) {
                    var filesHref = '?action=wiki&url=' + encodeURIComponent(imgPickerPageUrl) + '&do=files';
                    imgPickerBody.innerHTML = '<p class="text-muted mb-0">No images uploaded yet.'
                        + ' <a href="' + filesHref + '" target="_blank">Upload files</a> first.</p>';
                    return;
                }
                var esc = function (s) {
                    return s.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
                };
                var html = '<div class="row g-2">';
                images.forEach(function (img) {
                    html += '<div class="col-auto wiki-img-pick" style="cursor:pointer;max-width:120px"'
                          + ' data-url="' + esc(img.url) + '" title="' + esc(img.name) + '">'
                          + '<img src="' + esc(img.url) + '" style="max-width:112px;max-height:80px;object-fit:contain">'
                          + '<div class="small text-truncate mt-1">' + esc(img.name) + '</div>'
                          + '</div>';
                });
                html += '</div>';
                imgPickerBody.innerHTML = html;
            })
            .catch(function () {}); // silently ignore network errors
    });

    imgPickerModal.addEventListener('click', function (e) {
        var item = e.target.closest('.wiki-img-pick');
        if (!item) return;
        var range = quill.getSelection(true);
        quill.insertEmbed(range ? range.index : quill.getLength() - 1, 'image', item.dataset.url, 'user');
        bootstrap.Modal.getInstance(imgPickerModal).hide();
    });

    // Show the "create alias" checkbox only when the URL field has been changed.
    (function () {
        var urlInput    = document.getElementById('wiki-url');
        var aliasWrap   = document.getElementById('wiki-rename-alias-wrap');
        if (!urlInput || !aliasWrap) return;
        var originalUrl = urlInput.defaultValue;
        urlInput.addEventListener('input', function () {
            aliasWrap.style.display = this.value !== originalUrl ? '' : 'none';
        });
    })();
})();
</script>
HTML;

    return $html;
}

/**
 * Returns the wiki nav dropdown <li> for the Bootstrap navbar.
 *
 * @return string HTML <li> dropdown element for the navbar, or empty string if the wiki has
 *                no pages yet and the current user does not have permission to create them.
 */
function get_wiki_nav_item(): string {
    $db       = \wiki\WikiDatabase::getInstance();
    $navItems = $db->getNavItems();
    $hasPages = $db->countAllPages() > 0;
    $isPriv   = \auth\current_user_is_privileged();

    if (!$hasPages && !$isPriv) {
        return '';
    }

    $html  = '<li class="nav-item dropdown">';
    $html .= '<a class="nav-link dropdown-toggle" href="?action=wiki" id="wikiNavDropdown" role="button"'
           . ' data-bs-toggle="dropdown" aria-expanded="false">Wiki</a>';
    $html .= '<ul class="dropdown-menu" aria-labelledby="wikiNavDropdown">';

    foreach ($navItems as $item) {
        $href  = '?action=wiki&amp;url=' . urlencode($item['url']);
        $label = htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8');
        $html .= '<li><a class="dropdown-item" href="' . $href . '">' . $label . '</a></li>';
    }

    if ($hasPages) {
        $html .= '<li><hr class="dropdown-divider"></li>';
    }
    $html .= '<li><a class="dropdown-item" href="?action=wiki">All pages</a></li>';

    if ($isPriv) {
        $html .= '<li><a class="dropdown-item" href="?action=wiki&amp;do=edit">New page</a></li>';
    }

    $html .= '</ul></li>';
    return $html;
}

/**
 * Renders the file management page for a wiki page (privileged users only).
 * Shows the list of attached files and an upload form.
 *
 * @param string $url       Wiki page URL whose files should be managed.
 * @param string $csrfToken CSRF token to embed in the upload and delete forms.
 * @return string Full HTML of the file list and upload form.
 */
function get_wiki_files_page(string $url, string $csrfToken): string {
    $db    = \wiki\WikiDatabase::getInstance();
    $files = $db->getFilesForPage($url);
    $url_e = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $csrf  = htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8');

    $rows = '';
    foreach ($files as $f) {
        $fileUrl    = '/get_file.php?id=' . urlencode($f['stored_name']);
        $fname_e    = htmlspecialchars($f['filename'],    ENT_QUOTES, 'UTF-8');
        $fmime_e    = htmlspecialchars($f['mime_type'],   ENT_QUOTES, 'UTF-8');
        $stored_e   = htmlspecialchars($f['stored_name'], ENT_QUOTES, 'UTF-8');
        $fsize      = \wiki\format_file_size((int)$f['file_size']);
        $uploadedAt = date('Y-m-d H:i', (int)$f['uploaded_at']);
        $isImage    = str_starts_with($f['mime_type'], 'image/');
        $preview    = $isImage
            ? '<img src="' . $fileUrl . '" style="max-height:32px;max-width:64px;vertical-align:middle" class="me-1">'
            : '';
        $rows .= '<tr>'
               . '<td>' . $preview . '<a href="' . $fileUrl . '">' . $fname_e . '</a></td>'
               . '<td>' . $fsize . '</td>'
               . '<td>' . $fmime_e . '</td>'
               . '<td>' . $uploadedAt . '</td>'
               . '<td><code class="small user-select-all">/get_file.php?id=' . $stored_e . '</code></td>'
               . '<td>'
               . '<form method="post" action="?action=wiki" style="display:inline">'
               . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
               . '<input type="hidden" name="do" value="delete_file">'
               . '<input type="hidden" name="stored_name" value="' . $stored_e . '">'
               . '<input type="hidden" name="page_url" value="' . $url_e . '">'
               . '<button type="submit" class="btn btn-sm btn-outline-danger"'
               . ' onclick="return confirm(\'Delete ' . $fname_e . '?\')">Delete</button>'
               . '</form>'
               . '</td>'
               . '</tr>';
    }

    $table = empty($files)
        ? '<p class="text-muted">No files attached to this page yet.</p>'
        : '<table class="table table-sm align-middle">'
          . '<thead><tr><th>File</th><th>Size</th><th>Type</th><th>Uploaded</th><th>URL</th><th></th></tr></thead>'
          . '<tbody>' . $rows . '</tbody></table>';

    return get_breadcrumb($url)
         . '<h3>Files: <a href="?action=wiki&amp;url=' . urlencode($url) . '">' . $url_e . '</a></h3>'
         . $table
         . '<form method="post" action="?action=wiki" enctype="multipart/form-data" class="mt-3 row g-2 align-items-end">'
         . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
         . '<input type="hidden" name="do" value="upload_file">'
         . '<input type="hidden" name="page_url" value="' . $url_e . '">'
         . '<div class="col-auto"><label class="form-label mb-1">Upload file</label>'
         . '<input type="file" class="form-control" name="file" required></div>'
         . '<div class="col-auto"><button type="submit" class="btn btn-primary">Upload</button></div>'
         . '</form>'
         . '<div class="mt-3"><a href="?action=wiki&amp;url=' . urlencode($url) . '" class="btn btn-secondary">Back to page</a></div>';
}
