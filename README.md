## Cache Manager for CloudPanel

A WordPress plugin that gives a WordPress or WooCommerce site on a [CloudPanel](https://www.cloudpanel.io/) server a fast, safe page cache.

Pages for anonymous visitors are kept in Varnish for up to an hour, so they load without running WordPress. As soon as you edit a post, page, product, price, stock level, category, menu or widget, the cache for the site is cleared automatically. Cloudflare and browsers are told to keep a page for at most 5 minutes (`s-maxage=300`). Logged-in users, carts, checkout and account pages are never cached.

It works together with [CloudPanel Companion](https://github.com/bomsn/cloudpanel-companion), which sets up the Varnish server side. The plugin on its own does nothing.

## Requirements

- A CloudPanel server with the Companion installed (`sudo bash install.sh` in the Companion folder).
- WordPress 6.2 or later, PHP 8.1 or later, single site (not multisite).

## Installation

You don't install this plugin from the WordPress dashboard. The Companion installs and connects it:

```shell
git clone https://github.com/bomsn/cache-manager-for-cloudpanel.git
cd cloudpanel-companion
sudo bash install.sh
```

Answer **yes** when it asks about the page cache and point it to the `cache-manager-for-cloudpanel` folder. It copies the plugin into your site, adds its settings to `wp-config.php`, starts the cache and activates the plugin.

## Using it

In WordPress, go to **Tools > CloudPanel cache**. It shows whether the cache is ready, any clear-cache requests still waiting, and a **Purge local page cache** button. It also shows whether an object cache (Redis) is in use.

From the command line:

```shell
wp cloudpanel-cache purge
```

To check a page, look at the `X-Cache-Manager-For-CloudPanel` response header: `HIT` means it came from the cache, `MISS` means it was just built and stored, `BYPASS` means it was deliberately not cached.

If your theme or another plugin changes something this plugin can't see (custom term meta, an options page, recommendation settings), have it clear the cache for the whole site after saving:

```php
do_action( 'cache_manager_for_cloudpanel_purge' );
```

It is queued and sent when the request ends, with automatic retries, like the plugin's own clears. It does nothing while the integration is off.

To keep pages in Varnish for a different time, set `CACHE_MANAGER_FOR_CLOUDPANEL_ORIGIN_TTL` in `wp-config.php` to a number of seconds between 300 and 86400 (default 3600). The 5-minute limit for Cloudflare and browsers stays the same.

## What gets cached

Cached: normal pages for visitors who aren't logged in, including visitors who only have analytics cookies (Google Analytics, Meta, Hotjar, ContentSquare, Microsoft Clarity, WooCommerce order attribution, Cloudflare bot checks) and links with campaign tags (`utm_*`, `gclid`, `fbclid`, `msclkid`, Mailchimp).

Never cached: logged-in users, carts and checkout, account pages, any other cookie, other query strings, the REST API, `wp-admin`, and any page that sends a cookie or says `private` or `no-store`.

If the cache service stops, the site keeps working and simply runs uncached until it is back.

## Updating

Pull the new version, then run the Companion's upgrade command:

```shell
cd cache-manager-for-cloudpanel && git pull && cd ../cloudpanel-companion
sudo python3 install-cache.py --host shop.example.com --user shop --php 8.3 --plugin-source ../cache-manager-for-cloudpanel --upgrade
```

It shows a short maintenance page, swaps the plugin, checks the site and restores the old version automatically if anything fails. Don't copy plugin files over the live folder by hand.

## Good to know

- **Cloudflare is a separate cache.** This plugin doesn't change Cloudflare settings or clear Cloudflare's cache.
- **Other plugins' settings:** changes made in unrelated plugin settings may need a manual purge.
- **Deactivating:** WordPress will only let you deactivate the plugin after the cache has been cleared, so visitors never see stale pages. To remove it completely, follow "Disabling or retiring" in the Companion's [how-it-works](https://github.com/bomsn/cloudpanel-companion/blob/master/docs/how-it-works.md) guide.
- Multisite and Memcached are not supported.

The server rules live in `server/cache-policy.vcl`. Tests for them run with real Varnish in the Companion (`tests/cache_integration.py`).
