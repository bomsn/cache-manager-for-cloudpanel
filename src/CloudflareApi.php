<?php
namespace CacheManagerForCloudPanel;

/**
 * Minimal Cloudflare v4 API client: one request shape, short timeouts, and
 * Cloudflare's own error messages passed through to the administrator.
 */
final class CloudflareApi
{
    public const METHOD_TOKEN = 'token';
    public const METHOD_PLUGIN = 'plugin';

    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/';

    /** @param array<string, string> $auth Authentication headers. */
    private function __construct(private array $auth, public readonly string $method) {}

    public static function withToken(string $token): self
    {
        return new self(['Authorization' => 'Bearer ' . $token], self::METHOD_TOKEN);
    }

    /**
     * Reuse the sign-in of the official Cloudflare plugin. Only its data store and
     * its key-type check are used, behind class checks, so a change in that plugin
     * makes Cloudflare "not connected" here instead of causing a fatal error.
     * Requests still go through this client, which keeps Cloudflare's error
     * messages (the plugin's client replaces them with the HTTP reason phrase).
     */
    public static function fromCloudflarePlugin(): ?self
    {
        $store = '\Cloudflare\APO\WordPress\DataStore';
        $logger = '\Cloudflare\APO\Integration\DefaultLogger';
        $client = '\Cloudflare\APO\API\Client';
        if (!class_exists($store) || !class_exists($logger) || !class_exists($client) ||
            !method_exists($client, 'isGlobalApiKey')) {
            return null;
        }
        try {
            $data = new $store(new $logger(false));
            $key = (string) $data->getClientV4APIKey();
            if ($key === '') {
                return null;
            }
            if (!$client::isGlobalApiKey($key)) {
                return new self(['Authorization' => 'Bearer ' . $key], self::METHOD_PLUGIN);
            }
            // A Global API Key is only valid together with the account email.
            $email = (string) $data->getCloudFlareEmail();
            return $email === '' ? null : new self(['X-Auth-Email' => $email, 'X-Auth-Key' => $key], self::METHOD_PLUGIN);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The sign-in method plus a one-way hash of the credentials, for remembering
     * what a stored zone was found with. The credentials themselves never leave
     * this object.
     */
    public function identity(): string
    {
        return $this->method . ':' . hash('sha256', serialize($this->auth));
    }

    /**
     * @param array<string, string> $query
     * @return array|\WP_Error The response's "result" member, or Cloudflare's error.
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array|\WP_Error
    {
        $args = [
            'method' => $method,
            'timeout' => 15,
            'redirection' => 0,
            'headers' => $this->auth + ['Accept' => 'application/json'],
        ];
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }
        $url = self::ENDPOINT . $path;
        if ($query) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return new \WP_Error('cache_manager_for_cloudpanel_cloudflare', sprintf(
                /* translators: %s: connection error */
                __('Could not reach Cloudflare: %s', 'cache-manager-for-cloudpanel'),
                $response->get_error_message()
            ));
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (is_array($data) && ($data['success'] ?? false) === true) {
            return is_array($data['result'] ?? null) ? $data['result'] : [];
        }
        return new \WP_Error('cache_manager_for_cloudpanel_cloudflare', self::errorMessage(
            is_array($data) ? $data : [],
            (int) wp_remote_retrieve_response_code($response)
        ));
    }

    /** Cloudflare's own messages, e.g. "Authentication error (10000)". */
    private static function errorMessage(array $data, int $code): string
    {
        $messages = [];
        foreach ((array) ($data['errors'] ?? []) as $error) {
            if (is_array($error) && !empty($error['message'])) {
                $messages[] = (string) $error['message'] . (isset($error['code']) ? ' (' . $error['code'] . ')' : '');
            }
        }
        if ($messages) {
            return sprintf(
                /* translators: %s: Cloudflare error messages */
                __('Cloudflare said: %s', 'cache-manager-for-cloudpanel'),
                implode('; ', $messages)
            );
        }
        return sprintf(
            /* translators: %d: HTTP status code */
            __('Cloudflare answered with HTTP %d.', 'cache-manager-for-cloudpanel'),
            $code
        );
    }
}
