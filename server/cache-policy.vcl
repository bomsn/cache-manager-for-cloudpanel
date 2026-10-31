# Include after the operator's backend and per-host revision subroutine.
# The loopback control service activates a validated VCL revision after edits.
# Older in-flight requests retain their old hash namespace. No optional VMOD.
import std;

sub vcl_recv {
    if (req.method == "PURGE") {
        return (synth(405, "Use the authenticated cache control endpoint"));
    }
    # Neither the browser nor an upstream proxy may inject internal metadata.
    unset req.http.X-Cache-Manager-For-CloudPanel-Key;
    unset req.http.X-Cache-Manager-For-CloudPanel-Tag;
    unset req.http.X-Cache-Manager-For-CloudPanel-Revision;
    unset req.http.X-Cache-Manager-For-CloudPanel-Cookies;
    if (req.method != "GET" && req.method != "HEAD") {
        return (pass);
    }
    # Analytics, attribution and bot-check cookies are written and read by browser
    # scripts or the CDN. When a request carries only these, it is an anonymous
    # visit and can share the cache; otherwise every returning visitor would
    # bypass it. If any other cookie is present the request passes to WordPress
    # with all of its cookies untouched.
    if (req.http.Cookie) {
        set req.http.X-Cache-Manager-For-CloudPanel-Cookies = ";" + req.http.Cookie;
        set req.http.X-Cache-Manager-For-CloudPanel-Cookies = regsuball(req.http.X-Cache-Manager-For-CloudPanel-Cookies, "; *(_ga|_ga_[A-Za-z0-9]+|_gid|_gat|_gat_[A-Za-z0-9_]+|_gcl_[a-z]+|_fbp|_fbc|_hj[A-Za-z0-9_]+|_cs_[a-z_]+|sbjs_[a-z_]+|_clck|_clsk|_uetsid|_uetvid|__cf_bm|cf_clearance)=[^;]*", "");
        if (req.http.X-Cache-Manager-For-CloudPanel-Cookies ~ "^[; ]*$") {
            unset req.http.Cookie;
        }
        unset req.http.X-Cache-Manager-For-CloudPanel-Cookies;
    }
    # Campaign tracking parameters do not change an anonymous page. Remove them so
    # ad and newsletter links share the cached page; other query strings bypass.
    if (!req.http.Cookie && req.url ~ "[?&](utm_[a-z]+|gclid|gbraid|wbraid|fbclid|msclkid|mc_cid|mc_eid)=") {
        set req.url = regsuball(req.url, "([?&])(utm_[a-z]+|gclid|gbraid|wbraid|fbclid|msclkid|mc_cid|mc_eid)=[^&]*", "\1");
        set req.url = regsuball(req.url, "([?&])&+", "\1");
        set req.url = regsub(req.url, "[?&]+$", "");
    }
    # Unknown cookies may personalize a page. Never drop them to manufacture HITs.
    # Other query-string responses remain functional but uncached.
    if (req.http.Authorization || req.http.Cookie || req.http.Range ||
        req.http.Upgrade || req.url ~ "[?]" ||
        req.url ~ "(?i)(^|/)(wp-admin|wp-json|wc-api|wp-login[.]php|wp-cron[.]php|xmlrpc[.]php)(/|$)") {
        return (pass);
    }
    call cache_manager_for_cloudpanel_revision;
    return (hash);
}

sub vcl_hash {
    hash_data(req.http.X-Cache-Manager-For-CloudPanel-Revision);
    # Continue into built-in VCL so URL and Host remain part of the hash.
}

sub vcl_backend_response {
    set beresp.grace = 0s;
    set beresp.keep = 0s;
    # Application approval is necessary but cannot override HTTP privacy signals.
    if (bereq.uncacheable || beresp.status != 200 ||
        beresp.http.X-Cache-Manager-For-CloudPanel-Public != "1" ||
        beresp.http.Content-Type !~ "(?i)^text/html(;|$)" ||
        beresp.http.Set-Cookie ||
        beresp.http.Cache-Control ~ "(?i)(private|no-store|no-cache)" ||
        beresp.http.Pragma ~ "(?i)no-cache" ||
        (beresp.http.Vary && beresp.http.Vary !~ "(?i)^Accept-Encoding$") ||
        beresp.ttl <= 0s) {
        set beresp.ttl = 0s;
        set beresp.uncacheable = true;
        return (deliver);
    }
    if (beresp.ttl > 300s) {
        set beresp.ttl = 300s;
    }
    # A CDN or browser must not keep HTML longer than the local freshness
    # contract, even if a later application handler raised its original TTL.
    set beresp.http.Cache-Control = "public, max-age=0, s-maxage=" + std.integer(duration=beresp.ttl, fallback=0) + ", must-revalidate";
    # Origin retention: Varnish itself keeps a page that kept the full public
    # lifetime for the plugin's origin setting, because every edit invalidates the
    # whole site. A lifetime another handler shortened is kept as it is.
    if (beresp.ttl >= 300s && beresp.http.X-Cache-Manager-For-CloudPanel-Origin-TTL ~ "^[0-9]{3,5}$") {
        set beresp.ttl = std.duration(beresp.http.X-Cache-Manager-For-CloudPanel-Origin-TTL + "s", 300s);
        if (beresp.ttl > 86400s) {
            set beresp.ttl = 86400s;
        } else if (beresp.ttl < 300s) {
            set beresp.ttl = 300s;
        }
    }
    set beresp.http.X-Cache-Manager-For-CloudPanel-Host = bereq.http.host;
    return (deliver);
}

sub vcl_deliver {
    if (obj.uncacheable) {
        set resp.http.X-Cache-Manager-For-CloudPanel = "BYPASS";
        # Protect personalized/action/HTML responses without disabling a CDN's
        # legitimate public PDF/image/download caching on an uncached pass.
        if (req.http.Cookie || req.http.Authorization ||
            (req.method != "GET" && req.method != "HEAD") || req.url ~ "[?]" ||
            req.url ~ "(?i)(^|/)(wp-admin|wp-json|wc-api|wp-login[.]php|wp-cron[.]php|xmlrpc[.]php)(/|$)" ||
            resp.http.Set-Cookie || resp.http.Content-Type ~ "(?i)^text/html(;|$)") {
            set resp.http.Cache-Control = "private, no-store";
        }
    } else {
        set resp.http.X-Cache-Manager-For-CloudPanel = "MISS";
        if (obj.hits > 0) {
            set resp.http.X-Cache-Manager-For-CloudPanel = "HIT";
        }
        # The origin copy can be older than the 300-second downstream lifetime.
        # Its age would make Cloudflare and browsers treat every such HIT as
        # already stale, so each delivery starts a fresh downstream window.
        unset resp.http.Age;
    }
    unset resp.http.X-Cache-Manager-For-CloudPanel-Public;
    unset resp.http.X-Cache-Manager-For-CloudPanel-Origin-TTL;
    unset resp.http.X-Cache-Manager-For-CloudPanel-Tags;
    unset resp.http.X-Cache-Manager-For-CloudPanel-Host;
    # Eligible HTML shares the bounded lifetime; public non-HTML passes keep theirs.
}

