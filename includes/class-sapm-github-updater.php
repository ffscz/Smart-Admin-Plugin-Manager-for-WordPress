<?php
/**
 * SAPM GitHub Updater
 *
 * Secure update mechanism from GitHub Releases with package integrity checks.
 *
 * @package SmartAdminPluginManager
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SAPM_GitHub_Updater {

    /** @var self|null */
    private static $instance = null;

    private const PLUGIN_FILE = 'smart-admin-plugin-manager/smart-admin-plugin-manager.php';
    private const PLUGIN_SLUG = 'smart-admin-plugin-manager';
    private const DEFAULT_REPO = 'ffscz/smart-admin-plugin-manager';

    private const UPDATE_TRANSIENT_KEY = 'sapm_github_updater_release_meta';
    private const LAST_GOOD_OPTION_KEY = 'sapm_github_updater_last_good_meta';
    private const HTTP_CACHE_OPTION_KEY = 'sapm_github_updater_http_cache';
    private const FETCH_LOCK_KEY = 'sapm_github_updater_fetch_lock';

    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;
    private const FETCH_LOCK_TTL = MINUTE_IN_SECONDS;

    private const DEFAULT_ZIP_ASSET = 'smart-admin-plugin-manager.zip';
    private const DEFAULT_HASH_ASSET = 'smart-admin-plugin-manager.sha256';

    private const DEFAULT_REQUIRES_WP = '6.0';
    private const DEFAULT_REQUIRES_PHP = '7.4';

    /**
     * Get singleton instance.
     */
    private static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize updater hooks.
     */
    public static function init(): void {
        $instance = self::get_instance();
        $instance->register_hooks();
    }

    /**
     * Register WordPress hooks.
     */
    private function register_hooks(): void {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update_offer']);
        add_filter('plugins_api', [$this, 'handle_plugins_api'], 10, 3);
        add_filter('upgrader_pre_download', [$this, 'verify_and_download_package'], 10, 4);
        add_filter('upgrader_source_selection', [$this, 'validate_source_structure'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'clear_cache_after_upgrade'], 10, 2);
    }

    /**
     * Add update object to plugin update transient.
     *
     * @param mixed $transient Update transient object.
     * @return mixed
     */
    public function inject_update_offer($transient) {
        if (!is_object($transient) || empty($transient->checked) || !is_array($transient->checked)) {
            return $transient;
        }

        if (!isset($transient->checked[self::PLUGIN_FILE])) {
            return $transient;
        }

        $metadata = $this->get_release_metadata();
        if (!is_array($metadata)) {
            return $transient;
        }

        $current_version = (string) $transient->checked[self::PLUGIN_FILE];
        $new_version = (string) ($metadata['version'] ?? '');
        if ($new_version === '' || version_compare($current_version, $new_version, '>=')) {
            return $transient;
        }

        $update = new stdClass();
        $update->id = $metadata['repo_url'];
        $update->slug = self::PLUGIN_SLUG;
        $update->plugin = self::PLUGIN_FILE;
        $update->new_version = $new_version;
        $update->package = $metadata['package_url'];
        $update->url = $metadata['repo_url'];
        $update->tested = $metadata['tested'];
        $update->requires = $metadata['requires'];
        $update->requires_php = $metadata['requires_php'];

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }

        $transient->response[self::PLUGIN_FILE] = $update;

        return $transient;
    }

    /**
     * Provide custom plugin info for "View details" modal.
     *
     * @param mixed        $result Existing result.
     * @param string       $action API action.
     * @param object|mixed $args API args.
     * @return mixed
     */
    public function handle_plugins_api($result, string $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args)) {
            return $result;
        }

        $slug = isset($args->slug) ? (string) $args->slug : '';
        if ($slug !== self::PLUGIN_SLUG && $slug !== self::PLUGIN_FILE) {
            return $result;
        }

        $metadata = $this->get_release_metadata();
        if (!is_array($metadata)) {
            return $result;
        }

        $info = new stdClass();
        $info->name = 'Smart Admin Plugin Manager';
        $info->slug = self::PLUGIN_SLUG;
        $info->version = $metadata['version'];
        $info->author = '<a href="https://ffs.cz" target="_blank" rel="noopener noreferrer">FFS.cz</a>';
        $info->homepage = $metadata['repo_url'];
        $info->requires = $metadata['requires'];
        $info->tested = $metadata['tested'];
        $info->requires_php = $metadata['requires_php'];
        $info->last_updated = $metadata['last_updated'];
        $info->download_link = $metadata['package_url'];
        $info->sections = [
            'description' => 'Official GitHub release updates for Smart Admin Plugin Manager.',
            'changelog' => $metadata['changelog_html'],
        ];

        return $info;
    }

    /**
     * Download package ourselves and verify SHA256 before upgrade continues.
     *
     * @param mixed  $reply Existing reply.
     * @param string $package Package URL.
     * @param mixed  $upgrader Upgrader instance.
     * @param array  $hook_extra Hook context.
     * @return mixed
     */
    public function verify_and_download_package($reply, string $package, $upgrader, array $hook_extra = []) {
        if ($reply !== false) {
            return $reply;
        }

        if (!$this->is_target_plugin_upgrade($hook_extra)) {
            return $reply;
        }

        $metadata = $this->get_release_metadata();
        if (!is_array($metadata)) {
            return new WP_Error('sapm_updater_metadata_missing', 'Unable to load validated update metadata.');
        }

        $expected_hash = strtolower((string) ($metadata['expected_sha256'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $expected_hash)) {
            return new WP_Error('sapm_updater_hash_missing', 'Update blocked: package SHA256 hash is missing or invalid.');
        }

        $allowed_package = (string) ($metadata['package_url'] ?? '');
        if ($allowed_package === '' || !hash_equals($allowed_package, $package)) {
            return new WP_Error('sapm_updater_package_mismatch', 'Update blocked: unexpected package URL.');
        }

        if (!$this->is_allowed_package_url($package)) {
            return new WP_Error('sapm_updater_host_not_allowed', 'Update blocked: package host is not trusted.');
        }

        $tmp_file = wp_tempnam(self::PLUGIN_SLUG . '.zip');
        if (!$tmp_file) {
            return new WP_Error('sapm_updater_temp_file_failed', 'Update blocked: unable to create temporary file.');
        }

        $response = wp_safe_remote_get($package, array_merge($this->build_http_args(), [
            'timeout' => 300,
            'stream' => true,
            'filename' => $tmp_file,
        ]));

        if (is_wp_error($response)) {
            @unlink($tmp_file);
            return $response;
        }

        if (wp_remote_retrieve_response_code($response) !== 200 || !file_exists($tmp_file)) {
            @unlink($tmp_file);
            return new WP_Error('sapm_updater_download_failed', 'Update blocked: package download failed.');
        }

        $actual_hash = strtolower((string) hash_file('sha256', $tmp_file));
        if (!hash_equals($expected_hash, $actual_hash)) {
            @unlink($tmp_file);
            return new WP_Error('sapm_updater_hash_mismatch', 'Update blocked: package integrity check failed (SHA256 mismatch).');
        }

        return $tmp_file;
    }

    /**
     * Validate extracted source structure.
     *
     * @param string|WP_Error $source Source directory.
     * @param string          $remote_source Remote extraction dir.
     * @param mixed           $upgrader Upgrader instance.
     * @param array           $hook_extra Hook context.
     * @return string|WP_Error
     */
    public function validate_source_structure($source, string $remote_source, $upgrader, array $hook_extra = []) {
        if (is_wp_error($source) || !$this->is_target_plugin_upgrade($hook_extra)) {
            return $source;
        }

        $source_basename = basename((string) $source);
        if ($source_basename !== self::PLUGIN_SLUG) {
            return new WP_Error(
                'sapm_updater_invalid_structure',
                'Update blocked: ZIP must contain root folder "smart-admin-plugin-manager".'
            );
        }

        $main_file = trailingslashit((string) $source) . 'smart-admin-plugin-manager.php';
        if (!file_exists($main_file)) {
            return new WP_Error(
                'sapm_updater_missing_main_file',
                'Update blocked: package is missing smart-admin-plugin-manager.php.'
            );
        }

        return $source;
    }

    /**
     * Purge cached metadata after successful plugin update process.
     */
    public function clear_cache_after_upgrade($upgrader, array $hook_extra): void {
        if (!$this->is_target_plugin_upgrade($hook_extra)) {
            return;
        }

        delete_site_transient(self::UPDATE_TRANSIENT_KEY);
    }

    /**
     * Load validated release metadata from override/cache/remote.
     */
    private function get_release_metadata(): ?array {
        $override = apply_filters('sapm_github_updater_override_metadata', null);
        if (is_array($override)) {
            return $this->validate_metadata_schema($override) ? $override : null;
        }

        $cached = get_site_transient(self::UPDATE_TRANSIENT_KEY);
        if (is_array($cached) && $this->validate_metadata_schema($cached)) {
            return $cached;
        }

        if (get_site_transient(self::FETCH_LOCK_KEY)) {
            $last_good = get_option(self::LAST_GOOD_OPTION_KEY, null);
            return (is_array($last_good) && $this->validate_metadata_schema($last_good)) ? $last_good : null;
        }

        set_site_transient(self::FETCH_LOCK_KEY, 1, self::FETCH_LOCK_TTL);
        $fetched = $this->fetch_remote_release_metadata();
        delete_site_transient(self::FETCH_LOCK_KEY);

        if (!is_array($fetched)) {
            $last_good = get_option(self::LAST_GOOD_OPTION_KEY, null);
            return (is_array($last_good) && $this->validate_metadata_schema($last_good)) ? $last_good : null;
        }

        set_site_transient(self::UPDATE_TRANSIENT_KEY, $fetched, self::CACHE_TTL);
        update_option(self::LAST_GOOD_OPTION_KEY, $fetched, false);

        return $fetched;
    }

    /**
     * Fetch metadata from GitHub Releases API.
     */
    private function fetch_remote_release_metadata(): ?array {
        $repo = $this->get_repository();
        if ($repo === '') {
            return null;
        }

        $endpoint = sprintf('https://api.github.com/repos/%s/releases/latest', rawurlencode($repo));
        $endpoint = str_replace('%2F', '/', $endpoint);

        $http_cache = get_option(self::HTTP_CACHE_OPTION_KEY, []);
        $headers = [];
        if (is_array($http_cache)) {
            if (!empty($http_cache['etag'])) {
                $headers['If-None-Match'] = (string) $http_cache['etag'];
            }
            if (!empty($http_cache['last_modified'])) {
                $headers['If-Modified-Since'] = (string) $http_cache['last_modified'];
            }
        }

        $base_http_args = $this->build_http_args();
        $response = wp_safe_remote_get($endpoint, array_merge($base_http_args, [
            'headers' => array_merge($base_http_args['headers'], $headers),
        ]));

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 304) {
            $last_good = get_option(self::LAST_GOOD_OPTION_KEY, null);
            return (is_array($last_good) && $this->validate_metadata_schema($last_good)) ? $last_good : null;
        }

        if ($code !== 200) {
            return null;
        }

        $release = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($release) || empty($release['tag_name']) || empty($release['assets']) || !is_array($release['assets'])) {
            return null;
        }

        if (!empty($release['draft']) || !empty($release['prerelease'])) {
            return null;
        }

        $version = $this->normalize_version((string) $release['tag_name']);
        if ($version === '') {
            return null;
        }

        $zip_asset_name = (string) apply_filters('sapm_github_updater_zip_asset_name', self::DEFAULT_ZIP_ASSET);
        $hash_asset_name = (string) apply_filters('sapm_github_updater_hash_asset_name', self::DEFAULT_HASH_ASSET);

        $zip_url = '';
        $hash_url = '';

        foreach ($release['assets'] as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $asset_name = isset($asset['name']) ? (string) $asset['name'] : '';
            $asset_url = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';

            if ($asset_name === '' || $asset_url === '') {
                continue;
            }

            if ($asset_name === $zip_asset_name) {
                $zip_url = $asset_url;
            }

            if ($asset_name === $hash_asset_name) {
                $hash_url = $asset_url;
            }
        }

        if ($zip_url === '' || !$this->is_allowed_package_url($zip_url)) {
            return null;
        }

        if ($hash_url === '' || !$this->is_allowed_package_url($hash_url)) {
            return null;
        }

        $expected_hash = $this->fetch_sha256_from_asset($hash_url);
        if ($expected_hash === '') {
            return null;
        }

        $repo_url = 'https://github.com/' . $repo;
        $release_body = isset($release['body']) ? (string) $release['body'] : '';
        if ($release_body === '') {
            $release_body = 'No changelog provided in this release.';
        }

        $published_timestamp = isset($release['published_at']) ? strtotime((string) $release['published_at']) : false;
        if ($published_timestamp === false) {
            $published_timestamp = time();
        }

        $tested = (string) apply_filters('sapm_github_updater_tested_wp', get_bloginfo('version'));

        $metadata = [
            'version' => $version,
            'package_url' => $zip_url,
            'expected_sha256' => $expected_hash,
            'repo_url' => $repo_url,
            'requires' => self::DEFAULT_REQUIRES_WP,
            'tested' => $tested,
            'requires_php' => self::DEFAULT_REQUIRES_PHP,
            'last_updated' => gmdate('Y-m-d', $published_timestamp),
            'changelog_html' => nl2br(esc_html($release_body)),
        ];

        $this->persist_http_cache_headers($response);

        return $this->validate_metadata_schema($metadata) ? $metadata : null;
    }

    /**
     * Parse SHA256 from .sha256 release asset.
     */
    private function fetch_sha256_from_asset(string $asset_url): string {
        $response = wp_safe_remote_get($asset_url, $this->build_http_args());
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }

        $body = (string) wp_remote_retrieve_body($response);
        if (preg_match('/\b([a-fA-F0-9]{64})\b/', $body, $matches) !== 1) {
            return '';
        }

        return strtolower($matches[1]);
    }

    /**
     * Save ETag/Last-Modified cache validators.
     */
    private function persist_http_cache_headers(array $response): void {
        $etag = wp_remote_retrieve_header($response, 'etag');
        $last_modified = wp_remote_retrieve_header($response, 'last-modified');

        $payload = [];
        if (is_string($etag) && $etag !== '') {
            $payload['etag'] = $etag;
        }
        if (is_string($last_modified) && $last_modified !== '') {
            $payload['last_modified'] = $last_modified;
        }

        if (!empty($payload)) {
            update_option(self::HTTP_CACHE_OPTION_KEY, $payload, false);
        }
    }

    /**
     * Validate metadata schema.
     */
    private function validate_metadata_schema(array $metadata): bool {
        $required_string_keys = [
            'version',
            'package_url',
            'expected_sha256',
            'repo_url',
            'requires',
            'tested',
            'requires_php',
            'last_updated',
            'changelog_html',
        ];

        foreach ($required_string_keys as $key) {
            if (!isset($metadata[$key]) || !is_string($metadata[$key]) || $metadata[$key] === '') {
                return false;
            }
        }

        if (!$this->is_allowed_package_url($metadata['package_url'])) {
            return false;
        }

        if (!preg_match('/^[a-f0-9]{64}$/', strtolower($metadata['expected_sha256']))) {
            return false;
        }

        return version_compare($metadata['version'], '0.0.1', '>=');
    }

    /**
     * Determine if current upgrader operation targets this plugin.
     */
    private function is_target_plugin_upgrade(array $hook_extra): bool {
        if (($hook_extra['type'] ?? '') !== 'plugin') {
            return false;
        }

        if (isset($hook_extra['plugin']) && $hook_extra['plugin'] === self::PLUGIN_FILE) {
            return true;
        }

        if (!empty($hook_extra['plugins']) && is_array($hook_extra['plugins'])) {
            return in_array(self::PLUGIN_FILE, $hook_extra['plugins'], true);
        }

        return false;
    }

    /**
     * Get normalized repository identifier (owner/repo).
     */
    private function get_repository(): string {
        $repo = defined('SAPM_GITHUB_UPDATER_REPO')
            ? (string) SAPM_GITHUB_UPDATER_REPO
            : self::DEFAULT_REPO;

        $repo = (string) apply_filters('sapm_github_updater_repo', $repo);
        $repo = trim($repo);

        return preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo) ? $repo : '';
    }

    /**
     * Normalize Git tag to semantic plugin version.
     */
    private function normalize_version(string $tag): string {
        $tag = ltrim(trim($tag), "vV");
        if ($tag === '') {
            return '';
        }

        return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $tag) ? $tag : '';
    }

    /**
     * Build hardened HTTP args.
     */
    private function build_http_args(): array {
        global $wp_version;

        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => sprintf('SAPM-Updater/%s; %s; WordPress/%s', SAPM_VERSION, home_url('/'), $wp_version),
        ];

        $token = defined('SAPM_GITHUB_TOKEN') ? trim((string) SAPM_GITHUB_TOKEN) : '';
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return [
            'timeout' => 15,
            'redirection' => 3,
            'reject_unsafe_urls' => true,
            'sslverify' => true,
            'headers' => $headers,
        ];
    }

    /**
     * Validate package URL against strict host allowlist.
     */
    private function is_allowed_package_url(string $url): bool {
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        $allowed_hosts = (array) apply_filters('sapm_github_updater_allowed_hosts', [
            'github.com',
            'api.github.com',
            'objects.githubusercontent.com',
            'github-releases.githubusercontent.com',
            'codeload.github.com',
            'raw.githubusercontent.com',
        ]);

        foreach ($allowed_hosts as $allowed_host) {
            $allowed_host = strtolower((string) $allowed_host);
            if ($allowed_host === '') {
                continue;
            }

            if ($host === $allowed_host || substr($host, -strlen('.' . $allowed_host)) === '.' . $allowed_host) {
                return true;
            }
        }

        return false;
    }
}

add_action('plugins_loaded', ['SAPM_GitHub_Updater', 'init'], 5);
