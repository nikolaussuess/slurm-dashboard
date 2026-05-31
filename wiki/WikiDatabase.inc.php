<?php

namespace wiki;

class WikiDatabase {

    private static ?WikiDatabase $instance = NULL;
    private \PDO $pdo;

    private const SCHEMA_VERSION = 1;

    public const VISIBILITY_PUBLIC     = 'public';
    public const VISIBILITY_USERS      = 'users';
    public const VISIBILITY_PRIVILEGED = 'privileged';
    public const VISIBILITY_ADMIN      = 'admin';

    public const VALID_VISIBILITIES = [
        self::VISIBILITY_PUBLIC,
        self::VISIBILITY_USERS,
        self::VISIBILITY_PRIVILEGED,
        self::VISIBILITY_ADMIN,
    ];

    /**
     * @param string $path Absolute or project-relative path to the SQLite database file.
     * @throws \exceptions\ConfigurationError If the pdo_sqlite extension is not loaded.
     */
    private function __construct(string $path) {
        if (!extension_loaded('pdo_sqlite')) {
            throw new \exceptions\ConfigurationError(
                'Required PHP extension missing: pdo_sqlite',
                'extension_loaded("pdo_sqlite") returned false',
                'The wiki feature requires <kbd>php-sqlite3</kbd> (pdo_sqlite). '
                . 'Please install it (e.g. <kbd>apt install php-sqlite3</kbd>) and restart your web server, '
                . 'or unset the <kbd>FEATURE_WIKI_DB</kbd> environment variable to disable the wiki.'
            );
        }
        if (!str_starts_with($path, '/')) {
            $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . $path;
        }
        $this->pdo = new \PDO('sqlite:' . $path);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->migrate();
    }

    /**
     * Returns the singleton instance, creating it on first call.
     *
     * @return static|null The WikiDatabase instance, or NULL if the wiki is not enabled.
     */
    public static function getInstance(): ?WikiDatabase {
        if (self::$instance !== NULL) {
            return self::$instance;
        }
        if (!self::isEnabled()) {
            return NULL;
        }
        self::$instance = new self(\config('FEATURE_WIKI_DB'));
        return self::$instance;
    }

    /**
     * Checks whether the wiki feature is configured.
     *
     * @return bool TRUE if FEATURE_WIKI_DB is set to a non-empty value, FALSE otherwise.
     */
    public static function isEnabled(): bool {
        $path = \config('FEATURE_WIKI_DB');
        return $path !== \TO_BE_REPLACED && $path !== '';
    }

    /**
     * Runs all pending schema migrations.
     */
    private function migrate(): void {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS TBL_META (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )");

        $stmt    = $this->pdo->query("SELECT value FROM TBL_META WHERE key = 'schema_version'");
        $row     = $stmt->fetch(\PDO::FETCH_ASSOC);
        $version = $row ? (int)$row['value'] : 0;

        if ($version < 1) {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS TBL_PAGES (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                url         TEXT    UNIQUE NOT NULL,
                title       TEXT    NOT NULL,
                content     TEXT    NOT NULL DEFAULT '',
                visibility  TEXT    NOT NULL DEFAULT 'users',
                show_in_nav INTEGER NOT NULL DEFAULT 0, -- 0 = hidden; >0 = position in nav dropdown
                created_at  INTEGER NOT NULL,
                updated_at  INTEGER NOT NULL,
                created_by  TEXT    NOT NULL DEFAULT '',
                updated_by  TEXT    NOT NULL DEFAULT ''
            )");
            // Maps a virtual URL (e.g. feature/avx512) to a target page + optional anchor.
            // Used by auto-links when no page exists at the source URL.
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS TBL_ALIASES (
                source_url  TEXT PRIMARY KEY,
                target_url  TEXT NOT NULL,
                anchor      TEXT NOT NULL DEFAULT ''
            )");
            // Files uploaded through the wiki and associated with a page.
            // stored_name is a 32-char hex UUID used as the on-disk filename (no extension).
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS TBL_FILES (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                page_url     TEXT    NOT NULL,
                filename     TEXT    NOT NULL,
                stored_name  TEXT    UNIQUE NOT NULL,
                mime_type    TEXT    NOT NULL,
                file_size    INTEGER NOT NULL,
                uploaded_at  INTEGER NOT NULL,
                uploaded_by  TEXT    NOT NULL DEFAULT ''
            )");
            $this->pdo->exec("INSERT OR REPLACE INTO TBL_META (key, value) VALUES ('schema_version', '1')");
        }
    }

    /**
     * Fetches a single page by its URL.
     *
     * @param string $url Page URL to look up.
     * @return array<string, mixed>|null Full page row, or NULL if no page exists at $url.
     */
    public function getPage(string $url): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM TBL_PAGES WHERE url = :url");
        $stmt->bindValue(':url', $url);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: NULL;
    }

    /**
     * Checks whether a page exists at the given URL.
     *
     * @param string $url Page URL to check.
     * @return bool TRUE if a page exists, FALSE otherwise.
     */
    public function pageExists(string $url): bool {
        $stmt = $this->pdo->prepare("SELECT 1 FROM TBL_PAGES WHERE url = :url");
        $stmt->bindValue(':url', $url);
        $stmt->execute();
        return $stmt->fetch() !== FALSE;
    }

    /**
     * Inserts or updates a page (upsert on url).
     * created_at and created_by are only written on initial insert.
     *
     * @param string $url        Page URL (unique identifier).
     * @param string $title      Page title.
     * @param string $content    HTML content of the page.
     * @param string $visibility One of the VISIBILITY_* constants.
     * @param int    $showInNav  0 = hidden; positive value = position in the nav dropdown.
     * @param string $user       Username of the editor.
     */
    public function savePage(string $url, string $title, string $content, string $visibility, int $showInNav, string $user): void {
        $now  = time();
        $stmt = $this->pdo->prepare("
            INSERT INTO TBL_PAGES (url, title, content, visibility, show_in_nav, created_at, updated_at, created_by, updated_by)
            VALUES (:url, :title, :content, :visibility, :show_in_nav, :created_at, :updated_at, :created_by, :updated_by)
            ON CONFLICT(url) DO UPDATE SET
                title       = excluded.title,
                content     = excluded.content,
                visibility  = excluded.visibility,
                show_in_nav = excluded.show_in_nav,
                updated_at  = excluded.updated_at,
                updated_by  = excluded.updated_by
        ");
        $stmt->bindValue(':url',         $url);
        $stmt->bindValue(':title',       $title);
        $stmt->bindValue(':content',     $content);
        $stmt->bindValue(':visibility',  $visibility);
        $stmt->bindValue(':show_in_nav', $showInNav, \PDO::PARAM_INT);
        $stmt->bindValue(':created_at',  $now, \PDO::PARAM_INT);
        $stmt->bindValue(':updated_at',  $now, \PDO::PARAM_INT);
        $stmt->bindValue(':created_by',  $user);
        $stmt->bindValue(':updated_by',  $user);
        $stmt->execute();
    }

    /**
     * Deletes the page at the given URL.
     *
     * @param string $url Page URL to delete.
     * @return bool TRUE if a row was deleted, FALSE if no page existed at $url.
     */
    public function deletePage(string $url): bool {
        $stmt = $this->pdo->prepare("DELETE FROM TBL_PAGES WHERE url = :url");
        $stmt->bindValue(':url', $url);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /**
     * Returns all pages ordered by URL.
     *
     * @return array<int, array<string, mixed>> All page rows (without content), ordered by url.
     */
    public function getAllPages(): array {
        $stmt = $this->pdo->query(
            "SELECT id, url, title, visibility, created_at, updated_at, created_by, updated_by
             FROM TBL_PAGES ORDER BY url"
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Returns the total number of pages in the database.
     *
     * @return int Total page count.
     */
    public function countAllPages(): int {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM TBL_PAGES")->fetchColumn();
    }

    /**
     * Returns direct children of $parentUrl visible to the current user, ordered by title.
     * E.g. for 'node': returns 'node/foo', 'node/bar' but not 'node/foo/sub'.
     * $allowedVisibilities must be a non-empty subset of VALID_VISIBILITIES.
     *
     * @param string   $parentUrl           Parent URL prefix (e.g. 'node').
     * @param string[] $allowedVisibilities Visibility values the caller is allowed to read.
     * @return array<int, array{url: string, title: string}> Direct child pages ordered by title.
     */
    public function getChildPages(string $parentUrl, array $allowedVisibilities): array {
        $placeholders = implode(',', array_fill(0, count($allowedVisibilities), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT url, title FROM TBL_PAGES
             WHERE url LIKE ? AND url NOT LIKE ?
               AND visibility IN ($placeholders)
             ORDER BY title"
        );
        $params = array_merge([$parentUrl . '/%', $parentUrl . '/%/%'], array_values($allowedVisibilities));
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Fetches the alias record for $sourceUrl.
     *
     * @param string $sourceUrl Virtual URL to look up.
     * @return array{target_url: string, anchor: string}|null Alias record, or NULL if none exists.
     */
    public function getAlias(string $sourceUrl): ?array {
        $stmt = $this->pdo->prepare("SELECT target_url, anchor FROM TBL_ALIASES WHERE source_url = :url");
        $stmt->bindValue(':url', $sourceUrl);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: NULL;
    }

    /**
     * Inserts or updates an alias (upsert on source_url).
     *
     * @param string $sourceUrl Virtual URL that should resolve to $targetUrl.
     * @param string $targetUrl URL of the target page.
     * @param string $anchor    Heading anchor on the target page, or empty string for none.
     */
    public function saveAlias(string $sourceUrl, string $targetUrl, string $anchor): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO TBL_ALIASES (source_url, target_url, anchor)
            VALUES (:source, :target, :anchor)
            ON CONFLICT(source_url) DO UPDATE SET
                target_url = excluded.target_url,
                anchor     = excluded.anchor
        ");
        $stmt->bindValue(':source', $sourceUrl);
        $stmt->bindValue(':target', $targetUrl);
        $stmt->bindValue(':anchor', $anchor);
        $stmt->execute();
    }

    /**
     * Deletes the alias for $sourceUrl.
     *
     * @param string $sourceUrl Virtual URL whose alias should be removed.
     * @return bool TRUE if a row was deleted, FALSE if no alias existed for $sourceUrl.
     */
    public function deleteAlias(string $sourceUrl): bool {
        $stmt = $this->pdo->prepare("DELETE FROM TBL_ALIASES WHERE source_url = :url");
        $stmt->bindValue(':url', $sourceUrl);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /**
     * Records an uploaded file in the database.
     *
     * @param string $pageUrl     URL of the wiki page the file is attached to.
     * @param string $filename    Original filename as provided by the uploader.
     * @param string $storedName  32-char hex on-disk filename (no extension).
     * @param string $mimeType    Server-detected MIME type of the file.
     * @param int    $fileSize    File size in bytes.
     * @param string $user        Username of the uploader.
     */
    public function saveFile(string $pageUrl, string $filename, string $storedName, string $mimeType, int $fileSize, string $user): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO TBL_FILES (page_url, filename, stored_name, mime_type, file_size, uploaded_at, uploaded_by)
            VALUES (:page_url, :filename, :stored_name, :mime_type, :file_size, :uploaded_at, :uploaded_by)
        ");
        $stmt->bindValue(':page_url',    $pageUrl);
        $stmt->bindValue(':filename',    $filename);
        $stmt->bindValue(':stored_name', $storedName);
        $stmt->bindValue(':mime_type',   $mimeType);
        $stmt->bindValue(':file_size',   $fileSize,  \PDO::PARAM_INT);
        $stmt->bindValue(':uploaded_at', time(),     \PDO::PARAM_INT);
        $stmt->bindValue(':uploaded_by', $user);
        $stmt->execute();
    }

    /**
     * Fetches a file record by its stored name.
     *
     * @param string $storedName 32-char hex identifier of the file.
     * @return array<string, mixed>|null Full file row, or NULL if not found.
     */
    public function getFile(string $storedName): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM TBL_FILES WHERE stored_name = :s");
        $stmt->bindValue(':s', $storedName);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: NULL;
    }

    /**
     * Returns all files attached to a page, ordered by filename.
     *
     * @param string $pageUrl URL of the wiki page whose files should be listed.
     * @return array<int, array<string, mixed>> File rows ordered by filename.
     */
    public function getFilesForPage(string $pageUrl): array {
        $stmt = $this->pdo->prepare("SELECT * FROM TBL_FILES WHERE page_url = :p ORDER BY filename");
        $stmt->bindValue(':p', $pageUrl);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Deletes a file record by its stored name.
     * Does not remove the file from disk; the caller is responsible for that.
     *
     * @param string $storedName 32-char hex identifier of the file to delete.
     * @return bool TRUE if a row was deleted, FALSE if no record existed for $storedName.
     */
    public function deleteFile(string $storedName): bool {
        $stmt = $this->pdo->prepare("DELETE FROM TBL_FILES WHERE stored_name = :s");
        $stmt->bindValue(':s', $storedName);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /**
     * Returns all aliases ordered by source_url.
     *
     * @return array<int, array{source_url: string, target_url: string, anchor: string}>
     */
    public function getAllAliases(): array {
        return $this->pdo->query(
            "SELECT source_url, target_url, anchor FROM TBL_ALIASES ORDER BY source_url"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Returns pages shown in the nav dropdown, ordered by their nav position.
     *
     * @return array<int, array{url: string, title: string}> Pages with show_in_nav > 0, ordered ascending.
     */
    public function getNavItems(): array {
        $stmt = $this->pdo->query(
            "SELECT url, title FROM TBL_PAGES WHERE show_in_nav > 0 ORDER BY show_in_nav ASC"
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
