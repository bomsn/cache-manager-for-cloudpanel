<?php
// The parts of the official Cloudflare plugin (4.x) that CloudflareApi relies on,
// loaded by plugin_unit.php only after it has checked the "plugin missing" path.

namespace Cloudflare\APO\Integration {
    class DefaultLogger
    {
        public function __construct($debug = false) {}
    }
}

namespace Cloudflare\APO\API {
    class Client
    {
        public static function isGlobalApiKey($key)
        {
            return is_string($key) && (bool) preg_match('/^[0-9a-f]{37,45}$/', $key);
        }
    }
}

namespace Cloudflare\APO\WordPress {
    class DataStore
    {
        public function __construct($logger) {}

        public function getClientV4APIKey()
        {
            return get_option('cloudflare_api_key');
        }

        public function getCloudFlareEmail()
        {
            return get_option('cloudflare_api_email');
        }
    }
}
