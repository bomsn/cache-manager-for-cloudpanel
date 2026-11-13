<?php
namespace CacheManagerForCloudPanel;

/**
 * Cloudflare's edge cache, cleared on request only.
 *
 * Content changes are not pushed to Cloudflare from here: the Cloudflare plugin
 * already purges the URLs a post change affects, and public HTML is given a
 * short edge lifetime (s-maxage=300) by PublicPage.
 *
 * Credentials, in order: the CACHE_MANAGER_FOR_CLOUDPANEL_CLOUDFLARE_TOKEN
 * constant, the token saved in Settings > Cache, then the Cloudflare plugin's
 * sign-in.
 */
final class Cloudflare implements UrlLayer
{
    public const TOKEN_OPTION = 'cache_manager_for_cloudpanel_cloudflare_token';
    public const ZONE_OPTION = 'cache_manager_for_cloudpanel_cloudflare_zone';
    private const TOKEN_CONSTANT = 'CACHE_MANAGER_FOR_CLOUDPANEL_CLOUDFLARE_TOKEN';

    /** Resolved client; false once resolved to "not connected". */
    private CloudflareApi|false|null $api = null;

    public function __construct(private Configuration $configuration, private Settings $settings) {}

    public function label(): string
    {
        return 'Cloudflare';
    }

    public function icon(): string
    {
        return 'cloud';
    }

    public function actions(): array
    {
        return ['cloudflare' => __('Purge Cloudflare cache', 'cache-manager-for-cloudpanel')];
    }

    public function perform(string $action): Result
    {
        return $this->clear();
    }

    public static function tokenFromConstant(): bool
    {
        return defined(self::TOKEN_CONSTANT) && is_string(constant(self::TOKEN_CONSTANT)) &&
            constant(self::TOKEN_CONSTANT) !== '';
    }

    public static function token(): string
    {
        return self::tokenFromConstant() ? constant(self::TOKEN_CONSTANT) : (string) get_option(self::TOKEN_OPTION, '');
    }

    /** Saved with autoload off, so the token is read only when Cloudflare is used. */
    public static function saveToken(string $token): void
    {
        update_option(self::TOKEN_OPTION, $token, false);
    }

    public static function deleteToken(): void
    {
        delete_option(self::TOKEN_OPTION);
        delete_option(self::ZONE_OPTION);
    }

    public function api(): ?CloudflareApi
    {
        if ($this->api === null) {
            $token = self::token();
            $this->api = ($token !== '' ? CloudflareApi::withToken($token) : CloudflareApi::fromCloudflarePlugin()) ?? false;
        }
        return $this->api ?: null;
    }

    public function unavailable(): string
    {
        return $this->api() ? '' : __('Not connected. Add an API token in Settings > Cache, or sign in to the Cloudflare plugin.', 'cache-manager-for-cloudpanel');
    }

    public function status(): array
    {
        $api = $this->api();
        if (!$api) {
            return ['state' => __('Not connected', 'cache-manager-for-cloudpanel'), 'tone' => 'neutral', 'details' => []];
        }
        $zone = $this->storedZone();
        return [
            'state' => $zone ? __('Connected', 'cache-manager-for-cloudpanel') : __('Not tested', 'cache-manager-for-cloudpanel'),
            'tone' => $zone ? 'good' : 'neutral',
            'details' => [
                __('Signed in with', 'cache-manager-for-cloudpanel') => $this->methodLabel($api),
                __('Zone', 'cache-manager-for-cloudpanel') => $zone
                    ? ($zone['name'] !== '' ? $zone['name'] : $zone['id'])
                    : __('Found on first use, or with Test connections', 'cache-manager-for-cloudpanel'),
            ],
        ];
    }

    /** Purges the whole zone, which includes any other sites that share it. */
    public function clear(): Result
    {
        $zone = $this->zone();
        if (is_wp_error($zone)) {
            return Result::error($zone->get_error_message());
        }
        $purged = $this->api()->request('POST', 'zones/' . $zone['id'] . '/purge_cache', [], ['purge_everything' => true]);
        if (is_wp_error($purged)) {
            return Result::error($purged->get_error_message());
        }
        return Result::success(sprintf(
            /* translators: %s: Cloudflare zone name, e.g. example.com */
            __('Purged everything in %s.', 'cache-manager-for-cloudpanel'),
            $zone['name'] !== '' ? $zone['name'] : $zone['id']
        ));
    }

    public function clearUrl(string $url): Result
    {
        $zone = $this->zone();
        if (is_wp_error($zone)) {
            return Result::error($zone->get_error_message());
        }
        $purged = $this->api()->request('POST', 'zones/' . $zone['id'] . '/purge_cache', [], ['files' => self::urlVariants($url)]);
        if (is_wp_error($purged)) {
            return Result::error($purged->get_error_message());
        }
        return Result::success(__('Purged this page.', 'cache-manager-for-cloudpanel'));
    }

    /**
     * Cloudflare keys its cache by scheme, so a page reachable over both http and
     * https can be held twice.
     *
     * @return list<string>
     */
    public static function urlVariants(string $url): array
    {
        return array_values(array_unique([set_url_scheme($url, 'https'), set_url_scheme($url, 'http')]));
    }

    /**
     * Confirm the credentials and the zone. The zone lookup is the real test;
     * token verification runs only to explain a failed lookup, because tokens
     * owned by an account are not accepted by the user verification endpoint.
     */
    public function test(): Result
    {
        $api = $this->api();
        $zone = $this->zone(true);
        if (!is_wp_error($zone) && $zone['name'] !== '') {
            return Result::success(sprintf(
                /* translators: 1: zone name, 2: how the plugin signs in, e.g. "API token ending in abcd" */
                __('Connected to %1$s (%2$s).', 'cache-manager-for-cloudpanel'),
                $zone['name'],
                $this->methodLabel($api)
            ));
        }
        if ($api->method === CloudflareApi::METHOD_TOKEN) {
            $token = $api->request('GET', 'user/tokens/verify');
            if (is_wp_error($token)) {
                return Result::error($token->get_error_message());
            }
            if (($token['status'] ?? '') !== 'active') {
                return Result::error(sprintf(
                    /* translators: %s: token status reported by Cloudflare, e.g. "disabled" */
                    __('The API token is %s.', 'cache-manager-for-cloudpanel'),
                    (string) ($token['status'] ?? __('not active', 'cache-manager-for-cloudpanel'))
                ));
            }
        }
        if (is_wp_error($zone)) {
            return Result::error($zone->get_error_message());
        }
        return Result::success(sprintf(
            /* translators: %s: Cloudflare zone ID */
            __('Signed in. Using zone ID %s. These credentials cannot read the zone\'s name, but purging does not need it.', 'cache-manager-for-cloudpanel'),
            $zone['id']
        ));
    }

    /**
     * The zone to purge: the manual Zone ID when set, otherwise the zone found for
     * the site's host. Stored, so a purge normally costs one API request.
     *
     * @return array{id: string, name: string}|\WP_Error
     */
    private function zone(bool $refresh = false): array|\WP_Error
    {
        $api = $this->api();
        if (!$api) {
            return new \WP_Error('cache_manager_for_cloudpanel_cloudflare', $this->unavailable());
        }
        $stored = $refresh ? null : $this->storedZone();
        if ($stored) {
            return $stored;
        }
        $manual = (string) $this->settings->get('cloudflare_zone');
        if ($manual !== '') {
            // A manual ID is used as given. Its name is only for display and needs
            // Zone Read access, so a failed lookup is not an error here.
            $details = $api->request('GET', 'zones/' . $manual);
            $zone = ['id' => $manual, 'name' => is_array($details) ? (string) ($details['name'] ?? '') : ''];
        } else {
            $zone = $this->detect($api);
            if (is_wp_error($zone)) {
                return $zone;
            }
        }
        update_option(self::ZONE_OPTION, $zone + ['for' => $this->fingerprint($api)], false);
        return $zone;
    }

    /** @return array{id: string, name: string}|null */
    private function storedZone(): ?array
    {
        $api = $this->api();
        $stored = get_option(self::ZONE_OPTION);
        if (!$api || !is_array($stored) || ($stored['for'] ?? '') !== $this->fingerprint($api) || empty($stored['id'])) {
            return null;
        }
        return ['id' => (string) $stored['id'], 'name' => (string) ($stored['name'] ?? '')];
    }

    /**
     * What a stored zone was found for. Different credentials (wherever they come
     * from), Zone ID or site host invalidate it, so a token for another account
     * never purges a zone it was not found with.
     */
    private function fingerprint(CloudflareApi $api): string
    {
        return $api->identity() . '|' . $this->settings->get('cloudflare_zone') . '|' . $this->configuration->host();
    }

    /** @return array{id: string, name: string}|\WP_Error */
    private function detect(CloudflareApi $api): array|\WP_Error
    {
        $host = $this->configuration->host();
        foreach (self::zoneCandidates($host) as $name) {
            $zones = $api->request('GET', 'zones', ['name' => $name]);
            if (is_wp_error($zones)) {
                return $zones;
            }
            foreach ($zones as $zone) {
                if (is_array($zone) && strtolower((string) ($zone['name'] ?? '')) === $name && !empty($zone['id'])) {
                    return ['id' => (string) $zone['id'], 'name' => $name];
                }
            }
        }
        return new \WP_Error('cache_manager_for_cloudpanel_cloudflare', sprintf(
            /* translators: %s: site host name */
            __('No Cloudflare zone for %s is visible to these credentials. Give the token Zone Read access, or enter the Zone ID in Settings > Cache.', 'cache-manager-for-cloudpanel'),
            $host
        ));
    }

    /**
     * Possible zone names for a host, most specific first: shop.example.co.uk
     * gives shop.example.co.uk, example.co.uk, co.uk. A zone is usually the
     * registrable domain but can be a delegated subdomain, and without a public
     * suffix list the right one is found by asking.
     *
     * @return list<string>
     */
    public static function zoneCandidates(string $host): array
    {
        $labels = explode('.', strtolower($host));
        $candidates = [];
        for ($index = 0; $index <= count($labels) - 2; $index++) {
            $candidates[] = implode('.', array_slice($labels, $index));
        }
        return $candidates;
    }

    private function methodLabel(CloudflareApi $api): string
    {
        if ($api->method === CloudflareApi::METHOD_PLUGIN) {
            return __('Cloudflare plugin sign-in', 'cache-manager-for-cloudpanel');
        }
        if (self::tokenFromConstant()) {
            return __('API token in wp-config.php', 'cache-manager-for-cloudpanel');
        }
        return sprintf(
            /* translators: %s: last four characters of the API token */
            __('API token ending in %s', 'cache-manager-for-cloudpanel'),
            substr(self::token(), -4)
        );
    }
}
