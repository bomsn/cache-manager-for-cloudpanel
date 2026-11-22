<?php
/**
 * Unit checks for the cache layers, without WordPress or a network.
 *
 * Usage: php tests/plugin_unit.php
 *
 * Only WordPress itself is replaced: options live in memory, HTTP requests are
 * recorded and answered from a queue, and the queue table is a small fake. The
 * plugin classes run unchanged, so these checks cover the real request shapes
 * sent to the cache service and to Cloudflare.
 */

namespace {
    use CacheManagerForCloudPanel as P;

    const ABSPATH = __DIR__ . '/';
    const WP_CONTENT_DIR = __DIR__;
    const CACHE_MANAGER_FOR_CLOUDPANEL_ENABLED = true;
    const CACHE_MANAGER_FOR_CLOUDPANEL_PURGE_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    const CACHE_MANAGER_FOR_CLOUDPANEL_PORT = 8081;
    const WEEK_IN_SECONDS = 604800;
    define('CacheManagerForCloudPanel\PLUGIN_FILE', dirname(__DIR__) . '/cache-manager-for-cloudpanel.php');

    final class WP_Post
    {
        public string $post_type = 'product';
    }

    final class WP_Error
    {
        public function __construct(private string $code = '', private string $message = '') {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
    }

    $GLOBALS['test'] = ['options' => [], 'requests' => [], 'responses' => [], 'events' => [], 'actions' => [],
        'ext_cache' => false, 'flush' => static fn (): bool => true, 'hooks' => [], 'filter' => '', 'can' => true, 'plugins' => [], 'autoload' => []];

    function is_wp_error($thing): bool { return $thing instanceof WP_Error; }
    function wp_get_environment_type() { return $GLOBALS['test']['environment'] ?? 'production'; }
    function __($text, $domain = '') { return $text; }
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
    function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
    function _n($single, $plural, $count, $domain = '') { return $count === 1 ? $single : $plural; }
    function is_multisite(): bool { return false; }
    function home_url($path = '') { return 'https://shop.example.com' . $path; }
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
    function set_url_scheme($url, $scheme) { return preg_replace('#^\w+://#', $scheme . '://', $url); }
    function wp_json_encode($data) { return json_encode($data); }
    function apply_filters($tag, $value) { return $value; }
    function do_action($tag) { $GLOBALS['test']['actions'][] = $tag; }
    function doing_action($tag) { return false; }
    function did_action($tag) { return 0; }
    function human_time_diff($from, $to = 0) { return 'a while'; }
    function get_option($name, $default = false) { return $GLOBALS['test']['options'][$name] ?? $default; }
    function update_option($name, $value, $autoload = null) { $GLOBALS['test']['options'][$name] = $value; $GLOBALS['test']['autoload'][$name] = $autoload; return true; }
    function delete_option($name) { unset($GLOBALS['test']['options'][$name]); return true; }
    function wp_next_scheduled($hook) { return false; }
    function wp_unschedule_hook($hook) { $GLOBALS['test']['events'] = array_values(array_filter($GLOBALS['test']['events'], static fn ($e) => $e[0] !== $hook)); }
    function wp_schedule_single_event($time, $hook, $args = []) { $GLOBALS['test']['events'][] = [$hook, $args]; return true; }
    function get_nav_menu_locations() { return []; }
    function wp_get_nav_menu_items($menu) { return []; }
    function wp_using_ext_object_cache() { return $GLOBALS['test']['ext_cache']; }
    function wp_cache_flush() { return ($GLOBALS['test']['flush'])(); }
    function delete_expired_transients($force = false) {}
    function wp_remote_request($url, $args) {
        $GLOBALS['test']['requests'][] = ['url' => $url] + $args;
        return array_shift($GLOBALS['test']['responses']) ?? new WP_Error('http', 'no response queued');
    }
    function wp_remote_retrieve_response_code($response) { return is_array($response) ? $response['code'] : ''; }
    function wp_remote_retrieve_header($response, $name) { return is_array($response) ? ($response['headers'][$name] ?? '') : ''; }
    function wp_remote_retrieve_body($response) { return is_array($response) ? ($response['body'] ?? '') : ''; }
    function esc_html__($text, $domain = '') { return esc_html($text); }
    function esc_url($url) { return htmlspecialchars((string) $url, ENT_QUOTES); }
    function is_admin() { return $GLOBALS['test']['admin'] ?? true; }
    function wp_doing_ajax() { return $GLOBALS['test']['ajax'] ?? false; }
    function wp_doing_cron() { return $GLOBALS['test']['cron'] ?? false; }
    function get_post($id) { return new WP_Post(); }
    function url_to_postid($url) { return $GLOBALS['test']['posts'][$url] ?? 0; }
    function current_user_can($capability) { return $capability === 'manage_options' && $GLOBALS['test']['can']; }
    function add_action($hook, $callback, $priority = 10, $accepted = 1) { $GLOBALS['test']['hooks'][$hook][] = [$callback, $accepted]; return true; }
    function current_filter() { return $GLOBALS['test']['filter']; }
    function add_query_arg($key, $value, $url = '/wp-admin/plugins.php') { return $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . $value; }
    function remove_query_arg($keys, $url)
    {
        [$base, $query] = explode('?', $url, 2) + [1 => ''];
        parse_str($query, $args);
        $args = array_diff_key($args, array_flip((array) $keys));
        return $base . ($args ? '?' . str_replace(['%5B', '%5D'], ['[', ']'], http_build_query($args)) : '');
    }
    function wp_nonce_url($url, $action, $name) { return $url . '&' . $name . '=nonce-' . $action; }
    function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }
    function get_plugins() { return $GLOBALS['test']['plugins']; }
    function wp_get_theme($stylesheet) { return new FakeTheme($stylesheet === 'twentytwentyfive' ? 'Twenty Twenty-Five' : ''); }
    /** Core's list join: "A and B", "A, B, and C". */
    function wp_sprintf_l($pattern, $args)
    {
        $last = array_pop($args);
        return $args ? implode(', ', $args) . (count($args) > 1 ? ', and ' : ' and ') . $last : $last;
    }

    final class FakeTheme
    {
        public function __construct(private string $name) {}
        public function exists(): bool { return $this->name !== ''; }
        public function get($header) { return $this->name; }
    }

    /** Just enough of $wpdb for InvalidationQueue's single "site" row. */
    final class FakeWpdb
    {
        public string $prefix = 'wp_';
        public ?object $row = null;
        public bool $failInsert = false;
        public function prepare($query, ...$args) { return vsprintf(str_replace('%s', "'%s'", $query), $args); }
        public function get_row($query) { return $this->row && $this->row->next_attempt <= time() ? $this->row : null; }
        public function get_var($query) { return $this->row ? 1 : 0; }
        public function query($query)
        {
            $verb = strtok(ltrim($query), ' ');
            if ($verb === 'INSERT' && $this->failInsert) {
                return false;
            }
            if ($verb === 'INSERT') {
                $this->row = (object) ['tag' => 'site', 'revision' => ($this->row->revision ?? 0) + 1,
                    'attempts' => $this->row->attempts ?? 0, 'next_attempt' => 0];
            } elseif ($verb === 'DELETE') {
                $this->row = null;
            } elseif ($verb === 'UPDATE' && $this->row) {
                $this->row->attempts++;
                $this->row->next_attempt = time() + 60;
            }
            return 1;
        }
    }

    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'CacheManagerForCloudPanel\\')) {
            require_once dirname(__DIR__) . '/src/' . substr($class, 26) . '.php';
        }
    });

    $failures = 0;
    function check(bool $condition, string $label): void
    {
        global $failures;
        echo ($condition ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
        $failures += $condition ? 0 : 1;
    }

    /** Runs the callbacks registered on $hook as WordPress does, with current_filter() set to it. */
    function run_hook(string $hook, ...$args): void
    {
        $GLOBALS['test']['filter'] = $hook;
        foreach ($GLOBALS['test']['hooks'][$hook] ?? [] as [$callback, $accepted]) {
            $callback(...array_slice($args, 0, $accepted));
        }
        $GLOBALS['test']['filter'] = '';
    }

    function rendered(P\ChangeNotice $notice): string
    {
        ob_start();
        $notice->render();
        return (string) ob_get_clean();
    }

    function respond(int $code, array $headers = [], array|string $body = ''): array
    {
        return ['code' => $code, 'headers' => $headers, 'body' => is_array($body) ? json_encode($body) : $body];
    }

    function reset_state(): void
    {
        $GLOBALS['test'] = ['options' => [], 'requests' => [], 'responses' => [], 'events' => [], 'actions' => [],
            'ext_cache' => false, 'flush' => static fn (): bool => true, 'hooks' => [], 'filter' => '', 'can' => true, 'plugins' => [], 'autoload' => []];
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    /**
     * The page cache candidates the checks run against, in production's order: by
     * default the Companion (switched on by the constants above), then CloudPanel's
     * Varnish without a settings file, so results never depend on the machine running them.
     *
     * @param list<P\PageCacheServer>|null $servers
     */
    function configuration(?array $servers = null): P\Configuration
    {
        return new P\Configuration($servers ?? [
            new P\CompanionServer('shop.example.com'),
            new P\CloudPanelVarnish('shop.example.com', __DIR__ . '/fixtures/no-such-settings.json'),
        ]);
    }

    /** CloudPanel's Varnish, reading settings written to a temporary settings.json. */
    function cloudpanel(array|string $settings): P\CloudPanelVarnish
    {
        $file = tempnam(sys_get_temp_dir(), 'cmcp');
        file_put_contents($file, is_array($settings) ? json_encode($settings) : $settings);
        return new P\CloudPanelVarnish('shop.example.com', $file);
    }

    const CLOUDPANEL_SETTINGS = ['enabled' => true, 'server' => '127.0.0.1:6081', 'cacheTagPrefix' => '96b1', 'cacheLifetime' => '604800'];

    /**
     * @param list<P\PageCacheServer>|null $servers
     * @return array{0: P\CacheLayers, 1: P\PageCache, 2: P\Cloudflare}
     */
    function build(?array $servers = null): array
    {
        $configuration = configuration($servers);
        $settings = new P\Settings();
        $queue = new P\InvalidationQueue($configuration);
        $page = new P\PageCache($configuration, $queue, new P\CacheWarmer($configuration, $settings));
        $cloudflare = new P\Cloudflare($configuration, $settings);
        return [new P\CacheLayers($configuration, $settings, $page, $cloudflare, new P\ObjectCache(), new P\WordPressCaches($queue)), $page, $cloudflare];
    }

    // Page cache: the request target sent to the cache service.
    $host = 'shop.example.com';
    check(P\CompanionServer::requestTarget('https://shop.example.com/shop/?utm_source=x&color=red&gclid=1', $host) === '/shop/?color=red', 'campaign parameters are stripped, others kept');
    check(P\CompanionServer::requestTarget('https://shop.example.com/?utm_medium=email', $host) === '/', 'a query of only campaign parameters is dropped');
    check(P\CompanionServer::requestTarget('https://shop.example.com', $host) === '/', 'an empty path becomes /');
    check(P\CompanionServer::requestTarget('https://shop.example.com/café/a b', $host) === '/caf%C3%A9/a%20b', 'non-ASCII bytes and spaces are percent-encoded');
    check(is_wp_error(P\CompanionServer::requestTarget('https://other.example.com/', $host)), 'another host is refused');
    $injected = P\CompanionServer::requestTarget("https://shop.example.com/a\r\nX-Injected: 1", $host);
    check(is_wp_error($injected) || !preg_match('/[\r\n]/', $injected), 'CR/LF never reaches the header');
    check(is_wp_error(P\CompanionServer::requestTarget('https://shop.example.com/' . str_repeat('a', 2048), $host)), 'targets over 2048 characters are refused');

    reset_state();
    [$layers, $page] = build();
    $GLOBALS['test']['responses'] = [respond(200, ['x-cache-manager-for-cloudpanel-policy' => 'url-ban-v1'])];
    $result = $page->clearUrl('https://shop.example.com/shop/');
    $sent = $GLOBALS['test']['requests'][0];
    check($result->ok() && str_contains($result->message, 'Cleared this page'), 'url-ban-v1 answer clears only the page');
    check($sent['url'] === 'http://127.0.0.1:8081/' && $sent['method'] === 'PURGE', 'single-page ban goes to the local cache service as PURGE /');
    check($sent['headers'] === ['Host' => $host, 'X-Cache-Manager-For-CloudPanel-Key' => CACHE_MANAGER_FOR_CLOUDPANEL_PURGE_KEY,
        'X-Cache-Manager-For-CloudPanel-Url' => '/shop/'], 'single-page ban sends Host, Key and Url headers');
    check(($GLOBALS['test']['events'][0][1][0] ?? []) === ['https://shop.example.com/shop/'], 'the cleared page is queued for warming');

    reset_state();
    [, $page] = build();
    $GLOBALS['test']['responses'] = [respond(200, ['x-cache-manager-for-cloudpanel-policy' => 'site-revision-v1'])];
    $result = $page->clearUrl('https://shop.example.com/shop/');
    check($result->ok() && str_contains($result->message, 'whole site') && count($GLOBALS['test']['requests']) === 1,
        'site-revision-v1 answer is reported as a whole-site clear without a second request');

    reset_state();
    [, $page] = build();
    $GLOBALS['test']['responses'] = [respond(400), respond(200, ['x-cache-manager-for-cloudpanel-policy' => 'site-revision-v1'])];
    $result = $page->clearUrl('https://shop.example.com/shop/');
    $fallback = $GLOBALS['test']['requests'][1] ?? [];
    check($result->ok() && str_contains($result->message, 'HTTP 400') && str_contains($result->message, 'whole site'),
        'an unexpected answer falls back to a whole-site clear and says why');
    check(!isset($fallback['headers']['X-Cache-Manager-For-CloudPanel-Url']), 'the fallback is the plain site-revision request');

    reset_state();
    [, $page] = build();
    $GLOBALS['test']['responses'] = [new WP_Error('http', 'refused'), new WP_Error('http', 'refused')];
    $result = $page->clearUrl('https://shop.example.com/shop/');
    check($result->status === P\Result::ERROR && $GLOBALS['wpdb']->row !== null && get_option('cache_manager_for_cloudpanel_error'),
        'when the service is down the clear fails and stays queued for retry');
    $GLOBALS['test']['responses'] = [respond(200, ['x-cache-manager-for-cloudpanel-policy' => 'site-revision-v1'])];
    $GLOBALS['wpdb']->row->next_attempt = time() + 600;
    check($page->clear()->ok() && $GLOBALS['wpdb']->row === null && !get_option('cache_manager_for_cloudpanel_error'),
        'a manual clear skips the retry delay, and its confirmation clears the failure');

    reset_state();
    [, $page] = build();
    $GLOBALS['wpdb']->failInsert = true;
    $result = $page->clear();
    check($result->status === P\Result::ERROR && !$GLOBALS['test']['requests'] && str_contains($result->message, 'could not be recorded'),
        'a clear the database cannot record fails without sending, and says it will not be retried');

    // Connection test: the OPTIONS check, which never purges.
    reset_state();
    [, $page] = build();
    $GLOBALS['test']['responses'] = [respond(200, ['x-cache-manager-for-cloudpanel-policy' => 'url-ban-v1'])];
    $result = $page->test();
    $sent = $GLOBALS['test']['requests'][0] ?? [];
    check($result->ok() && str_contains($result->message, 'Nothing was cleared'), 'a 200 check confirms the key and Varnish');
    check(count($GLOBALS['test']['requests']) === 1 && $sent['method'] === 'OPTIONS' && $sent['url'] === 'http://127.0.0.1:8081/',
        'the test sends one OPTIONS / to the cache service and no PURGE');
    check($sent['headers'] === ['Host' => $host, 'X-Cache-Manager-For-CloudPanel-Key' => CACHE_MANAGER_FOR_CLOUDPANEL_PURGE_KEY] && !isset($sent['body']),
        'the check sends only Host and Key, with no Url or Tag header and no body');
    check($GLOBALS['wpdb']->row === null && !$GLOBALS['test']['events'], 'the test records no clear and queues no warm-up');
    $answers = [
        'a 200 without a known policy is not taken as success' => [respond(200), 'did not answer as expected (HTTP 200)'],
        'a 403 reports a rejected key or unknown host' => [respond(403), 'rejected the purge key'],
        'a 400 reports a malformed check' => [respond(400), 'malformed'],
        'a 503 reports that Varnish is unreachable' => [respond(503), 'cannot reach Varnish'],
        'a 501 asks for a Companion update' => [respond(501), 'update the Companion'],
        'a connection error is reported with its cause' => [new WP_Error('http', 'Connection refused'), 'Connection refused'],
    ];
    foreach ($answers as $label => [$response, $expected]) {
        $GLOBALS['test']['responses'] = [$response];
        $result = $page->test();
        check($result->status === P\Result::ERROR && str_contains($result->message, $expected), $label);
    }

    // CloudPanel's own Varnish, without the Companion: read from its settings.json.
    reset_state();
    [$layers, $page] = build([cloudpanel(CLOUDPANEL_SETTINGS)]);
    $GLOBALS['test']['responses'] = [respond(200)];
    $result = $page->clear();
    $sent = $GLOBALS['test']['requests'][0] ?? [];
    check($result->ok() && $GLOBALS['wpdb']->row === null && count($GLOBALS['test']['requests']) === 1,
        "CloudPanel's Varnish alone runs the page cache and confirms a whole-site clear");
    check($sent['url'] === 'http://127.0.0.1:6081/' && $sent['method'] === 'PURGE'
        && $sent['headers'] === ['Host' => $host, 'X-Cache-Tags' => '96b1'],
        "a whole-site clear sends CloudPanel's own request: PURGE to its server with the site's tag prefix");
    $status = $page->status();
    check($status['tone'] === 'good' && $status['details']['Cache server'] === 'CloudPanel Varnish' && $layers->overview([
        'page_cache' => $status])['tone'] === 'good', 'the status card names CloudPanel Varnish and reads as ready');

    reset_state();
    [, $page] = build([cloudpanel(CLOUDPANEL_SETTINGS)]);
    $GLOBALS['test']['responses'] = [respond(405)];
    $result = $page->clear();
    check($result->status === P\Result::ERROR && $GLOBALS['wpdb']->row !== null && str_contains($page->attention(), 'queued'),
        'a refused purge (HTTP 405) fails, stays queued for retry, and the page cache asks for attention');

    reset_state();
    $GLOBALS['test']['posts']['https://shop.example.com/a-post/'] = 12;
    [, $page] = build([cloudpanel(CLOUDPANEL_SETTINGS)]);
    $GLOBALS['test']['responses'] = [respond(200), respond(200)];
    $single = $page->clearUrl('https://shop.example.com/a-post/');
    $other = $page->clearUrl('https://shop.example.com/category/news/');
    [$postPurge, $sitePurge] = $GLOBALS['test']['requests'];
    check($single->ok() && str_contains($single->message, 'Cleared this page') && $postPurge['headers']['X-Cache-Tags'] === '96b1-post-12',
        "a page that shows a post is cleared through the post's tag");
    check($other->ok() && str_contains($other->message, 'whole site') && $sitePurge['headers']['X-Cache-Tags'] === '96b1',
        'any other address clears the whole site');

    reset_state();
    [, $page] = build([cloudpanel(CLOUDPANEL_SETTINGS)]);
    $GLOBALS['test']['responses'] = [respond(200)];
    $result = $page->test();
    check($result->ok() && str_contains($result->message, 'Nothing was cleared')
        && $GLOBALS['test']['requests'][0]['headers']['X-Cache-Tags'] === '96b1-cache-manager-for-cloudpanel-test'
        && $GLOBALS['wpdb']->row === null, 'the connection test purges a tag no page carries');

    reset_state();
    $settings = [
        'switched off in CloudPanel is not a page cache' => [['enabled' => false] + CLOUDPANEL_SETTINGS, false, ''],
        'an unreadable settings file is reported, not taken as switched off' => ['{not json', true, 'could not be read'],
        'a settings file without a host:port server is reported' => [['server' => 'http://127.0.0.1'] + CLOUDPANEL_SETTINGS, true, 'no valid server'],
        'a tag prefix that is not plain characters is reported' => [['cacheTagPrefix' => 'a.*'] + CLOUDPANEL_SETTINGS, true, 'no valid cache tag prefix'],
    ];
    foreach ($settings as $label => [$content, $switchedOn, $error]) {
        $configuration = configuration([cloudpanel($content)]);
        check($configuration->switchedOn() === $switchedOn && str_contains($configuration->error(), $error)
            && $configuration->enabled() === false, $label);
    }
    check(!configuration([new P\CloudPanelVarnish($host, __DIR__ . '/fixtures/no-such-settings.json')])->switchedOn(),
        'a site without CloudPanel settings has no CloudPanel page cache');

    // A Companion site whose CloudPanel settings still say Varnish is on: installing the
    // Companion replaced CloudPanel's Varnish, so the Companion alone manages the cache.
    $companionSite = static fn (): array => [new P\CompanionServer('shop.example.com'), cloudpanel(CLOUDPANEL_SETTINGS)];
    reset_state();
    [, $page] = build($companionSite());
    $GLOBALS['test']['responses'] = [respond(200, ['x-cache-manager-for-cloudpanel-policy' => 'site-revision-v1'])];
    $result = $page->clear();
    check($result->ok() && $GLOBALS['wpdb']->row === null && array_column($GLOBALS['test']['requests'], 'url') === ['http://127.0.0.1:8081/'],
        "with the Companion set up, clears go only to the Companion, never as tag purges its policy refuses");
    check($page->status()['details']['Cache server'] === 'CloudPanel Companion', 'the status card names the Companion as the cache server');
    $GLOBALS['test']['responses'] = [respond(200, ['x-cache-manager-for-cloudpanel-policy' => 'url-ban-v1'])];
    $page->test();
    check(($GLOBALS['test']['requests'][1]['method'] ?? '') === 'OPTIONS' && count($GLOBALS['test']['requests']) === 2,
        'the connection test checks only the Companion');
    check(configuration([new P\CompanionServer('shop.example.com'), cloudpanel(['cacheTagPrefix' => ''] + CLOUDPANEL_SETTINGS)])->error() === '',
        "CloudPanel's leftover settings cannot put a Companion site's page cache in error");

    // Cloudflare.
    check(P\Cloudflare::zoneCandidates('shop.example.co.uk') === ['shop.example.co.uk', 'example.co.uk', 'co.uk'], 'zone candidates run from most specific to two labels');
    check(P\Cloudflare::urlVariants('https://shop.example.com/a/') === ['https://shop.example.com/a/', 'http://shop.example.com/a/'], 'single-page purge covers both schemes');

    reset_state();
    [, , $cloudflare] = build();
    check($cloudflare->unavailable() !== '', 'without a token or the Cloudflare plugin, Cloudflare is skipped');

    reset_state();
    update_option(P\Cloudflare::TOKEN_OPTION, 'tokenabcdefghijklmnopqrstuv1234');
    $GLOBALS['test']['environment'] = 'staging';
    [$layers, , $cloudflare] = build();
    $entries = $layers->clearAll()->entries();
    $cloudflareEntry = array_values(array_filter($entries, static fn ($e) => $e['label'] === 'Cloudflare'))[0];
    check(str_contains($cloudflare->unavailable(), 'staging') && $cloudflareEntry['status'] === 'skipped' &&
        !array_filter($GLOBALS['test']['requests'], static fn ($r) => str_contains($r['url'], 'cloudflare.com')),
        'on a staging copy Cloudflare is skipped with its reason, and no request reaches Cloudflare');
    check($cloudflare->status()['tone'] === 'neutral', 'the Cloudflare card reads as off on a staging copy, not as a problem');

    reset_state();
    update_option(P\Cloudflare::TOKEN_OPTION, 'tokenabcdefghijklmnopqrstuv1234');
    [, , $cloudflare] = build();
    $GLOBALS['test']['responses'] = [
        respond(200, [], ['success' => true, 'result' => []]),
        respond(200, [], ['success' => true, 'result' => [['id' => 'zone123', 'name' => 'example.com']]]),
        respond(200, [], ['success' => true, 'result' => ['id' => 'zone123']]),
    ];
    $result = $cloudflare->clear();
    [$first, $second, $purge] = $GLOBALS['test']['requests'];
    check(str_ends_with($first['url'], 'zones?name=shop.example.com') && str_ends_with($second['url'], 'zones?name=example.com'), 'zone lookup tries the host, then its parent');
    check($purge['method'] === 'POST' && str_ends_with($purge['url'], 'zones/zone123/purge_cache') && $purge['body'] === '{"purge_everything":true}',
        'purge everything posts purge_everything to the zone');
    check($purge['headers']['Authorization'] === 'Bearer tokenabcdefghijklmnopqrstuv1234', 'the saved token is sent as a bearer token');
    check($result->ok() && $result->message === 'Purged everything in example.com.', 'purge result names the zone');

    $GLOBALS['test']['requests'] = [];
    $GLOBALS['test']['responses'] = [respond(200, [], ['success' => true, 'result' => []])];
    $cloudflare->clearUrl('https://shop.example.com/a/');
    check(count($GLOBALS['test']['requests']) === 1 && json_decode($GLOBALS['test']['requests'][0]['body'], true) ===
        ['files' => ['https://shop.example.com/a/', 'http://shop.example.com/a/']], 'the stored zone is reused for a single-page purge');

    $GLOBALS['test']['responses'] = [respond(403, [], ['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]])];
    $result = $cloudflare->clear();
    check($result->status === P\Result::ERROR && $result->message === 'Cloudflare said: Authentication error (10000)', "Cloudflare's own error message is shown");

    $GLOBALS['test']['responses'] = [new WP_Error('blocked', 'This destination is not enabled.')];
    check($cloudflare->clear()->message === 'Could not reach Cloudflare: This destination is not enabled.', 'a blocked connection is reported, not fatal');

    $details = implode(' ', $cloudflare->status()['details']);
    check(str_contains($details, 'ending in 1234') && !str_contains($details, 'tokenabcdefghij'), 'status shows only the last four token characters');

    reset_state();
    require __DIR__ . '/fixtures/cloudflare-plugin.php';
    update_option('cloudflare_api_key', str_repeat('a1', 18) . 'f');
    update_option('cloudflare_api_email', 'owner@example.com');
    [, , $cloudflare] = build();
    $GLOBALS['test']['responses'] = [
        respond(200, [], ['success' => true, 'result' => [['id' => 'zone9', 'name' => 'shop.example.com']]]),
        respond(200, [], ['success' => true, 'result' => []]),
    ];
    $cloudflare->clear();
    $headers = $GLOBALS['test']['requests'][1]['headers'] ?? [];
    check(($headers['X-Auth-Email'] ?? '') === 'owner@example.com' && isset($headers['X-Auth-Key']) && !isset($headers['Authorization']),
        "without a token, the Cloudflare plugin's Global API Key sign-in is used");

    reset_state();
    update_option(P\Cloudflare::TOKEN_OPTION, 'tokenabcdefghijklmnopqrstuv1234');
    [, , $cloudflare] = build();
    $GLOBALS['test']['responses'] = [
        respond(200, [], ['success' => true, 'result' => [['id' => 'zone1', 'name' => 'shop.example.com']]]),
        respond(200, [], ['success' => true, 'result' => []]),
    ];
    $cloudflare->clear();
    // A token changed outside the settings form, as when the wp-config.php constant is edited.
    update_option(P\Cloudflare::TOKEN_OPTION, 'othertokenabcdefghijklmnopq5678');
    [, , $cloudflare] = build();
    $GLOBALS['test']['requests'] = [];
    $GLOBALS['test']['responses'] = [
        respond(200, [], ['success' => true, 'result' => [['id' => 'zone2', 'name' => 'shop.example.com']]]),
        respond(200, [], ['success' => true, 'result' => []]),
    ];
    $cloudflare->clear();
    check(count($GLOBALS['test']['requests']) === 2 && str_ends_with($GLOBALS['test']['requests'][1]['url'], 'zones/zone2/purge_cache'),
        'a zone found with other credentials is looked up again, not reused');
    check(!str_contains(serialize(get_option(P\Cloudflare::ZONE_OPTION)), 'othertoken'), 'the stored zone does not contain the token');

    // Registry and reports.
    reset_state();
    $GLOBALS['test']['ext_cache'] = true;
    $GLOBALS['test']['flush'] = static function (): bool { throw new RuntimeException('Redis went away'); };
    [$layers] = build();
    $GLOBALS['test']['responses'] = [respond(200, ['x-cache-manager-for-cloudpanel-policy' => 'site-revision-v1'])];
    $entries = array_column($layers->clearAll()->entries(), 'status', 'label');
    check($entries === ['WordPress' => 'success', 'Object cache' => 'error', 'Page cache' => 'success', 'Cloudflare' => 'skipped'],
        'a layer that throws fails alone; the others still run, in clearing order');
    check(in_array('cache_manager_for_cloudpanel_last_cleared', array_keys($GLOBALS['test']['options']), true), 'successful clears are timestamped');
    check(($layers->status('wordpress')['cleared'] ?? 0) > 0 && !array_key_exists('cleared', $layers->status('cloudflare')),
        'every available layer shows when it was last cleared; an unavailable one does not');
    check($layers->offers('transients') && !$layers->offers('clear-all') && !$layers->offers('unknown'), 'actions are looked up in the layers that offer them');
    check($layers->perform('object-cache')->entries()[0]['status'] === 'error', 'a layer action reports through the same runner');

    $report = new P\Report('t');
    $report->add('a', P\Result::success('x'));
    $report->add('b', P\Result::error('y'));
    check($report->tone() === 'warning', 'success plus failure reads as a warning');
    $report = new P\Report('t');
    $report->add('a', P\Result::skipped('x'));
    check($report->tone() === 'warning' && !$report->succeeded(), 'nothing cleared reads as a warning');
    $report = new P\Report('Clear all caches');
    $report->add('WordPress', P\Result::success('Removed expired transients.'));
    $report->add('Page cache', P\Result::success('Cleared the whole site.'));
    $report->add('Object cache', P\Result::error('Refused <b>.'));
    $report->add('Cloudflare', P\Result::skipped('Not connected.'));
    check($report->summary() === '2 succeeded, 1 skipped, 1 failed' && $report->toArray()['summary'] === $report->summary(),
        'the summary counts each outcome, successes first');

    // Result notices: one summary line, then a row per cache, each with its state icon.
    $html = P\Notices::markup($report->toArray());
    check(str_contains($html, '<strong>Clear all caches</strong><span class="cmcp-notice__counts">: 2 succeeded, 1 skipped, 1 failed</span>'),
        'a notice for several caches leads with the action and its counts');
    check(substr_count($html, '<li class="cmcp-result') === 4 && substr_count($html, '<svg') === 5,
        'the summary and every result row carry an icon');
    check(str_contains($html, 'cmcp-result--error"><svg class="cmcp-icon cmcp-icon--error"') && str_contains($html, 'Failed: Refused &lt;b&gt;.')
        && str_contains($html, 'Skipped: Not connected.'), 'failed and skipped rows say so in words and escape the message');
    $single = new P\Report('Clear page cache');
    $single->add('Page cache', P\Result::success('Cleared the whole site.'));
    check(!str_contains(P\Notices::markup($single->toArray()), 'cmcp-notice__counts'), 'a single-cache notice has no counts');

    // Icons.
    reset_state();
    [$layers, $page] = build();
    foreach ($layers->layers() as $id => $layer) {
        check(P\Icons::exists($layer->icon()), "the $id layer's icon exists");
    }
    foreach (['good', 'warning', 'neutral', 'success', 'skipped', 'error'] as $state) {
        $icon = P\Icons::state($state);
        check(str_contains($icon, 'class="cmcp-icon cmcp-icon--' . $state . '"') && preg_match('/<(path|circle) /', $icon) === 1,
            "the $state state has its own drawn icon");
    }
    $svg = P\Icons::svg('clock');
    check(str_contains($svg, 'aria-hidden="true"') && str_contains($svg, 'focusable="false"') && P\Icons::svg('unknown') === '',
        'icons are hidden from assistive technology, and an unknown name draws nothing');

    // Settings page overview.
    $statuses = [];
    foreach ($layers->layers() as $id => $layer) {
        $statuses[$id] = $layers->status($id);
    }
    $overview = $layers->overview($statuses);
    check($overview['tone'] === 'warning' && $overview['title'] === 'Page cache needs attention'
        && str_contains($overview['text'], 'clear the page cache automatically'), 'a bypassed page cache leads the overview');
    $statuses['page_cache']['tone'] = 'good';
    $overview = $layers->overview($statuses);
    check($overview['tone'] === 'good' && $overview['title'] === '2 of 4 caches are ready', 'otherwise the overview counts the ready caches');
    $statuses['object_cache']['tone'] = 'warning';
    $statuses['page_cache']['tone'] = 'warning';
    check($layers->overview($statuses)['title'] === 'Page cache, Object cache need attention', 'every cache that needs attention is named');

    // The warm-up result is a meter: a count the settings page draws as a bar.
    update_option(P\CacheWarmer::LAST_OPTION, ['time' => time() - 60, 'warmed' => 3, 'total' => 5]);
    $meter = $page->status()['meter'] ?? [];
    check(($meter['value'] ?? null) === 3 && ($meter['max'] ?? null) === 5 && ($meter['text'] ?? '') === '3 of 5 pages, a while ago',
        'the last warm-up is reported as 3 of 5 pages');

    // Warming.
    reset_state();
    $warmer = new P\CacheWarmer(configuration(), new P\Settings());
    $warmer->schedule(['https://shop.example.com/a/', 'https://other.example.com/x', '/b/', 'https://shop.example.com/a/#top', 'https://shop.example.com/q?x=1']);
    check(($GLOBALS['test']['events'][0][1][0] ?? []) === ['https://shop.example.com/a/', 'https://shop.example.com/b/'],
        'warming keeps distinct same-host URLs without a query');
    update_option(P\Settings::OPTION, ['warm' => false]);
    $GLOBALS['test']['events'] = [];
    check((new P\CacheWarmer(configuration(), new P\Settings()))->schedule() === 0 && !$GLOBALS['test']['events'], 'warming can be switched off');
    update_option(P\Settings::OPTION, ['warm_limit' => '500']);
    check((new P\Settings())->warmLimit() === P\Settings::WARM_LIMIT_MAXIMUM && P\Settings::boundedWarmLimit('x') === P\Settings::WARM_LIMIT_DEFAULT,
        'the warming limit is bounded by one rule');

    // Metadata writes: reading the site (view counters) never clears; an edit does.
    $metadata = static function (string $method, bool $admin = false, bool $ajax = false, bool $cron = false): bool {
        reset_state();
        $GLOBALS['test'] += ['admin' => $admin, 'ajax' => $ajax, 'cron' => $cron];
        if ($method !== '') {
            $_SERVER['REQUEST_METHOD'] = $method;
        }
        (new P\ContentInvalidation(new P\InvalidationQueue(configuration())))->metadata(1, 42, '_eael_post_view_count');
        unset($_SERVER['REQUEST_METHOD']);
        return $GLOBALS['wpdb']->row !== null;
    };
    check(!$metadata('GET') && !$metadata('HEAD'), 'a metadata write while a visitor views a page does not clear the page cache');
    check(!$metadata('GET', false, true) && !$metadata('GET', true, true),
        'a metadata write during a background read (the cart refresh, admin-ajax) does not clear the page cache');
    check($metadata('POST') && $metadata('POST', true) && $metadata('POST', true, true),
        'a metadata write from a form, an admin save or an AJAX save still clears the page cache');
    check($metadata('GET', true) && $metadata('GET', false, false, true) && $metadata(''),
        'a metadata write from an admin screen, a scheduled task or the command line still clears the page cache');
    $termMetadata = static function (string $method): bool {
        reset_state();
        $GLOBALS['test']['admin'] = false;
        $_SERVER['REQUEST_METHOD'] = $method;
        (new P\ContentInvalidation(new P\InvalidationQueue(configuration())))->register();
        run_hook('updated_term_meta', 1, 7, 'category_icon', '<svg/>');
        unset($_SERVER['REQUEST_METHOD']);
        return $GLOBALS['wpdb']->row !== null;
    };
    check($termMetadata('POST') && !$termMetadata('GET'),
        'a term meta save (a category icon) clears the page cache; a term meta write while a visitor reads a page does not');

    // Change notice: plugin, theme and WordPress changes, gathered into one site-wide record.
    $setup = static function (?array $servers = null): array {
        reset_state();
        [$layers, $page] = build($servers);
        // Registered as in the plugin, so the notice's claim is checked against the real clearing hooks.
        (new P\ContentInvalidation(new P\InvalidationQueue(configuration($servers))))->register();
        $notice = new P\ChangeNotice($page);
        $notice->register();
        $GLOBALS['test']['plugins'] = [
            'woocommerce/woocommerce.php' => ['Name' => 'WooCommerce'],
            'elementor/elementor.php' => ['Name' => 'Elementor'],
            'akismet/akismet.php' => ['Name' => 'Akismet <b>Anti-spam</b>'],
            'seo/seo.php' => ['Name' => 'SEO'],
            'hello.php' => ['Name' => 'Hello Dolly'],
        ];
        return [$notice, $layers];
    };
    $updated = (object) ['result' => ['destination_name' => 'elementor']];
    $elementor = ['type' => 'plugin', 'action' => 'update', 'plugin' => 'elementor/elementor.php'];

    [$notice, $layers] = $setup();
    run_hook('upgrader_process_complete', (object) ['result' => false], ['type' => 'plugin', 'action' => 'update', 'bulk' => true,
        'plugins' => ['woocommerce/woocommerce.php', 'elementor/elementor.php', 'akismet/akismet.php', 'seo/seo.php', 'gone/gone.php']]);
    run_hook('upgrader_process_complete', $updated, $elementor);
    run_hook('activated_plugin', 'hello.php', false);
    $record = get_option(P\ChangeNotice::OPTION);
    check(array_keys($record['changes']) === ['plugin_updated', 'plugin_activated'] && $record['changes']['plugin_updated']['count'] === 5
        && $record['changes']['plugin_activated']['count'] === 1, 'a bulk update and an activation make one record, each plugin counted once');
    check($GLOBALS['test']['autoload'][P\ChangeNotice::OPTION] === false, 'the record is a single option that is not autoloaded');
    $html = rendered($notice);
    check(str_contains($html, '<strong>The WooCommerce, Elementor, Akismet &lt;b&gt;Anti-spam&lt;/b&gt;, and 2 more plugins were updated. The Hello Dolly plugin was activated.</strong> '
        . 'The page cache was cleared automatically. If anything still looks out of date, clear all caches.') && $GLOBALS['wpdb']->row !== null,
        'the notice names three plugins, counts the rest, escapes names, and says the page cache was cleared when the same hooks cleared it');
    check(str_contains($html, 'class="button button-primary" href="/wp-admin/plugins.php?cmcp-action=clear-all&amp;cmcp-nonce=nonce-cache_manager_for_cloudpanel_clear-all"')
        && str_contains($html, 'href="/wp-admin/plugins.php?cmcp-action=dismiss-changes&amp;cmcp-nonce=nonce-cache_manager_for_cloudpanel_dismiss-changes"')
        && !str_contains($html, 'is-dismissible'), 'Clear all caches and Dismiss are signed actions, with no script-only close button');
    $GLOBALS['test']['can'] = false;
    $hidden = rendered($notice);
    $GLOBALS['test']['can'] = true;
    check($hidden === '', 'the notice is shown only to users who can clear caches');
    run_hook('upgrader_process_complete', (object) ['result' => new WP_Error('x', 'failed')], ['type' => 'theme', 'action' => 'update', 'theme' => 'twentytwentyfive']);
    check(!isset(get_option(P\ChangeNotice::OPTION)['changes']['theme_updated']), 'a failed single update is not reported');

    $GLOBALS['test']['ext_cache'] = true;
    $GLOBALS['test']['flush'] = static function (): bool { throw new RuntimeException('Redis went away'); };
    $GLOBALS['test']['responses'] = [respond(200, ['x-cache-manager-for-cloudpanel-policy' => 'site-revision-v1'])];
    $layers->clearAll();
    $kept = get_option(P\ChangeNotice::OPTION) !== false;
    $GLOBALS['test']['flush'] = static fn (): bool => true;
    $GLOBALS['test']['responses'] = [respond(200, ['x-cache-manager-for-cloudpanel-policy' => 'site-revision-v1'])];
    $report = $layers->clearAll();
    check(!$kept && $report->tone() === 'success' && get_option(P\ChangeNotice::OPTION) === false && rendered($notice) === '',
        'Clear all ends the notice, also when a cache fails (its own report lists the failure)');

    run_hook('activated_plugin', 'hello.php', false);
    $actions = new P\Actions($layers, configuration());
    $dismiss = (new ReflectionMethod($actions, 'operation'))->invoke($actions, 'dismiss-changes');
    check(get_option(P\ChangeNotice::OPTION) !== false && $dismiss() === null && get_option(P\ChangeNotice::OPTION) === false,
        'Dismiss removes the record and adds no result notice');
    run_hook('switch_theme', 'Twenty Twenty-Five', null, null);
    check(array_keys(get_option(P\ChangeNotice::OPTION)['changes']) === ['theme_switched']
        && str_contains(rendered($notice), '<strong>The active theme is now Twenty Twenty-Five.</strong> The page cache was cleared automatically.'),
        'a change after a dismissal starts a fresh record');

    $record = get_option(P\ChangeNotice::OPTION);
    $record['time'] = time() - WEEK_IN_SECONDS + 60;
    update_option(P\ChangeNotice::OPTION, $record, false);
    $recent = rendered($notice) !== '';
    $record['time'] = time() - WEEK_IN_SECONDS - 1;
    update_option(P\ChangeNotice::OPTION, $record, false);
    check($recent && rendered($notice) === '' && get_option(P\ChangeNotice::OPTION) === false, 'the record expires a week after the last change');

    [$notice] = $setup();
    $self = plugin_basename(P\PLUGIN_FILE);
    run_hook('deactivated_plugin', $self, false);
    run_hook('delete_plugin', $self);
    run_hook('deleted_plugin', $self, true);
    check(get_option(P\ChangeNotice::OPTION) === false, "this plugin's own deactivation and deletion are not reported");

    [$notice] = $setup();
    run_hook('delete_plugin', 'seo/seo.php');
    run_hook('delete_plugin', 'hello.php');
    $GLOBALS['test']['plugins'] = [];
    run_hook('deleted_plugin', 'seo/seo.php', true);
    run_hook('deleted_plugin', 'hello.php', false);
    $html = rendered($notice);
    check(str_contains($html, '<strong>The SEO plugin was deleted.</strong> If anything looks out of date, clear all caches.') && !str_contains($html, 'Hello')
        && $GLOBALS['wpdb']->row === null, 'a deletion, which does not clear the page cache, makes no claim that it did; a failed one is not reported');
    run_hook('activated_plugin', 'woocommerce/woocommerce.php', false);
    check(!str_contains(rendered($notice), 'The page cache was cleared'), 'one change that left the page cache alone keeps the whole notice neutral');

    [$notice] = $setup([]);
    run_hook('upgrader_process_complete', $updated, $elementor);
    check(str_contains(rendered($notice), '<strong>The Elementor plugin was updated.</strong> If anything looks out of date, clear all caches.'),
        'with the page cache off, the notice does not claim it was cleared');

    [$notice] = $setup();
    run_hook('upgrader_process_complete', (object) [], ['type' => 'core', 'action' => 'update']);
    run_hook('upgrader_process_complete', (object) [], ['type' => 'translation', 'action' => 'update']);
    $failed = get_option(P\ChangeNotice::OPTION);
    run_hook('_core_updated_successfully', '7.2');
    run_hook('upgrader_process_complete', (object) [], ['type' => 'core', 'action' => 'update']);
    run_hook('upgrader_process_complete', (object) ['result' => ['destination_name' => 'query-monitor'], 'new_plugin_data' => ['Name' => 'Query Monitor']],
        ['type' => 'plugin', 'action' => 'install']);
    run_hook('upgrader_process_complete', (object) ['result' => ['destination_name' => 'twentytwentyfive'], 'new_theme_data' => ['Name' => 'Twenty Twenty-Five']],
        ['type' => 'theme', 'action' => 'install']);
    check($failed === false && str_contains(rendered($notice), '<strong>The Query Monitor plugin was installed. The Twenty Twenty-Five theme was installed. '
        . 'WordPress was updated to 7.2.</strong> The page cache was cleared automatically.'),
        'installs and a successful WordPress update are reported; translations and failed core updates are not');

    // Actions return to the page they were run on, but never to a URL that performs an operation.
    $returns = [
        'an update of one plugin returns to Plugins' => ['/wp-admin/update.php?action=upgrade-plugin&plugin=a%2Fa.php&_wpnonce=n&cmcp-action=clear-all&cmcp-nonce=m', '/wp-admin/plugins.php'],
        'a bulk plugin update returns to Plugins' => ['/wp-admin/update.php?action=update-selected&plugins=a%2Fa.php%2Cb%2Fb.php&_wpnonce=n', '/wp-admin/plugins.php'],
        'a plugin install returns to Plugins' => ['/wp-admin/update.php?action=install-plugin&plugin=akismet&_wpnonce=n', '/wp-admin/plugins.php'],
        'a theme update returns to Themes' => ['/wp-admin/update.php?action=upgrade-theme&theme=twentytwentyfive&_wpnonce=n', '/wp-admin/themes.php'],
        'a theme install returns to Themes' => ['/wp-admin/update.php?action=install-theme&theme=astra&_wpnonce=n', '/wp-admin/themes.php'],
        'update.php without an action returns to Updates' => ['/wp-admin/update.php', '/wp-admin/update-core.php'],
        'a core update screen drops its action' => ['/wp-admin/update-core.php?action=do-core-upgrade&locale=en_US&upgrade=7.2&_wpnonce=n', '/wp-admin/update-core.php'],
        'a plugins screen drops a single action, keeping its filter' => ['/wp-admin/plugins.php?action=activate&plugin=a%2Fa.php&plugin_status=all&_wpnonce=n', '/wp-admin/plugins.php?plugin_status=all'],
        'a plugins screen drops a bulk action' => ['/wp-admin/plugins.php?action=delete-selected&verify-delete=1&checked[]=a%2Fa.php&checked[]=b%2Fb.php&action2=-1&_wpnonce=n&_wp_http_referer=x', '/wp-admin/plugins.php'],
        'a themes screen drops its action' => ['/wp-admin/themes.php?action=activate&stylesheet=astra&theme=astra&_wpnonce=n', '/wp-admin/themes.php?stylesheet=astra'],
        'a plugin install screen drops its action, keeping its tab' => ['/wp-admin/plugin-install.php?tab=upload&action=upload-plugin&_wpnonce=n', '/wp-admin/plugin-install.php?tab=upload'],
        'a theme install screen drops its action' => ['/wp-admin/theme-install.php?action=install-theme&theme=astra', '/wp-admin/theme-install.php'],
        'another screen keeps its arguments, so the editor stays open' => ['/wp-admin/post.php?post=12&action=edit&cmcp-action=clear-all&cmcp-nonce=m', '/wp-admin/post.php?post=12&action=edit'],
    ];
    foreach ($returns as $label => [$from, $expected]) {
        $returned = P\Actions::returnUrl($from);
        check($returned === $expected, $label . ($returned === $expected ? '' : " (got $returned)"));
    }

    // Uninstall removes every option, scheduled hook and table the plugin names,
    // a check rather than a reminder because the names are spelled out twice.
    $uninstall = file_get_contents(dirname(__DIR__) . '/uninstall.php');
    $sources = implode('', array_map('file_get_contents', glob(dirname(__DIR__) . '/src/*.php')));
    preg_match_all("/const [A-Z_]*(?:OPTION|HOOK|TABLE) = '([a-z_]+)'/", $sources, $names);
    $missing = array_filter($names[1], static fn (string $name): bool => !str_contains($uninstall, $name));
    check(count($names[1]) >= 10 && in_array(P\ChangeNotice::OPTION, $names[1], true) && !$missing, 'uninstall.php removes every stored option, scheduled hook and table' .
        ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''));

    echo $failures === 0 ? 'All checks passed.' . PHP_EOL : $failures . ' check(s) failed.' . PHP_EOL;
    exit($failures === 0 ? 0 : 1);
}
