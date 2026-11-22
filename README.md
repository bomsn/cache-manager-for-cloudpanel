# Cache Manager for CloudPanel

A WordPress plugin for sites hosted on [CloudPanel](https://www.cloudpanel.io/). It gives your site a fast Varnish page cache that clears itself when you change content, and one place to clear every cache the site uses.

![Settings > Cache](docs/settings.png)

## What it clears

| Cache | What the plugin does | What it needs |
|---|---|---|
| **Page cache** | Serves pages to visitors from Varnish and clears them as soon as you edit content. | CloudPanel's Varnish Cache switched on for the site, or the CloudPanel Companion |
| **Cloudflare** | Purges your Cloudflare zone, or a single page, when you ask. | An API token, or the Cloudflare plugin signed in |
| **Object cache** | Flushes Redis, Memcached or any other persistent object cache. | An object cache drop-in |
| **WordPress** | Removes expired transients and, if you use Elementor, regenerates its CSS files. | Nothing |

The page cache works with either of two Varnish setups:

- **CloudPanel's own Varnish Cache**, switched on in the site's **Varnish Cache** tab in CloudPanel. Nothing else is needed: the plugin reads the settings CloudPanel writes for the site and clears through the same server CloudPanel uses.
- **The [CloudPanel Companion](https://github.com/bomsn/cloudpanel-companion)**, which sets up its own Varnish and a small local service the plugin talks to.

A server runs one Varnish, so a site uses one of these. Installing the Companion replaces CloudPanel's Varnish on the server, so when the Companion is set up for a site the plugin uses it, even if CloudPanel still shows Varnish Cache as switched on. With neither, the plugin still clears Cloudflare, the object cache and WordPress's own caches, and the page cache simply shows as **Off**.

### With CloudPanel's Varnish Cache

When Varnish Cache is switched on for a site, CloudPanel runs a small script of its own before WordPress on every page. It decides which pages Varnish may keep and labels each one with the site's cache tag and the posts it shows. The plugin clears pages by those labels:

- It matches whole labels only, so it never clears another site on the same server, and clearing post 2 never clears post 23.
- **Clear this page** on a page that shows one post clears that page and the listings that show the post. On any other page (the shop, a category, the posts page) it clears the whole site, because those pages are labelled with the posts they list.
- When you save a post in the editor, CloudPanel's script also clears that post's pages. The plugin then clears the whole site, so the home page, listings and menus show the change too. Both can stay on.
- **Test connections** also loads your home page through Varnish to check it carries the site's cache tag. If it doesn't, CloudPanel's script is not running for the site, and clearing from WordPress would not reach the cached pages.

You don't need CloudPanel's **CLP Varnish Cache** plugin. If it is active, the page cache card asks you to deactivate it: it adds a second Cache menu and clears the same cache again. How long pages are kept and which pages are never cached stay in the site's **Varnish Cache** tab in CloudPanel. With the Companion, that plugin cannot clear anything at all.

## Requirements

- WordPress 6.2 or later and PHP 8.1 or later, on a single site (multisite is not supported).
- For the page cache: a CloudPanel site with Varnish Cache switched on in CloudPanel, or a CloudPanel server with the [CloudPanel Companion](https://github.com/bomsn/cloudpanel-companion).

## Installation

**With CloudPanel's Varnish Cache, or without a page cache.** Switch on Varnish Cache for the site in CloudPanel if you want the page cache, then add the plugin to your site and activate it:

```shell
cd wp-content/plugins
git clone https://github.com/bomsn/cache-manager-for-cloudpanel.git
wp plugin activate cache-manager-for-cloudpanel
```

You can also activate it under **Plugins** in the dashboard. Run WP-CLI as the site's CloudPanel user (as you normally would), so the plugin finds the Varnish settings in that user's home folder.

**With the CloudPanel Companion.** On the server, download the plugin next to the Companion and run the Companion's installer:

```shell
git clone https://github.com/bomsn/cloudpanel-companion.git
git clone https://github.com/bomsn/cache-manager-for-cloudpanel.git
cd cloudpanel-companion
sudo bash install.sh
```

Answer **yes** when it asks about the page cache and confirm the plugin folder (it suggests the copy next to the Companion). The installer copies the plugin into your site, adds its settings to `wp-config.php`, starts Varnish and activates the plugin.

## Using it

### Settings > Cache

The top of the screen tells you whether every cache is ready or which one needs attention, with two buttons:

- **Clear all caches** clears every cache you chose in the settings (all of them by default).
- **Test connections** checks that the page cache accepts purges from your site (CloudPanel's Varnish takes a purge that matches no page and serves your home page with the site's cache tag; the Companion's service checks your site's purge key and that it can reach Varnish), and that Cloudflare accepts the sign-in and can find your zone. It never clears any page.

Below that, each cache has a card with its status, the details that matter (how long pages are kept, the last warm-up, the Cloudflare zone, the object cache provider), when it was last cleared and its own button.

At the bottom you can choose:

- **Clear all includes**: which caches **Clear all caches** clears.
- **Cache warming**: after the page cache is cleared, the home page and the pages in your main menu are loaded again in the background, so your first visitors get cached pages. It is on by default, for up to 30 pages.
- **Cloudflare**: your API token and, if needed, the Zone ID (see below).

### The Cache menu

![The Cache menu on the front end](docs/admin-bar.png)

Administrators get a **Cache** menu in the admin bar, both on the site and in the dashboard:

- **Clear all caches**.
- **Clear this page** (on the site only) clears the page you are looking at from the page cache and Cloudflare.
- One item per cache the site has: **Clear page cache**, **Purge Cloudflare cache**, **Flush object cache**, **Clean expired transients** and, with Elementor, **Regenerate Elementor CSS**.
- **Settings** opens Settings > Cache.

An amber dot next to **Cache** means the page cache needs attention: its settings in `wp-config.php` are invalid, its service is down (pages are served uncached until it recovers), a clear failed, or another plugin clears the same cache. The reason is shown at the top of the menu.

After every action, a short notice lists each cache and what happened to it: cleared, skipped (with the reason) or failed (with the reason).

### After plugin, theme and WordPress updates

When plugins or themes are installed, updated, activated, deactivated or deleted, the theme is switched or WordPress is updated (from the dashboard, WP-CLI or automatic updates), administrators see a notice in the dashboard saying what changed. The page cache is usually cleared for you, and the notice says when it was, but Cloudflare and the object cache keep what they had. If anything looks out of date, click **Clear all caches** in the notice. **Dismiss** hides it until the next change. It also goes away once **Clear all caches** runs from anywhere (the notice, the Cache menu, Settings > Cache or `wp cloudpanel-cache purge --layer=all`); the notice that follows lists any cache that could not be cleared. Otherwise it goes a week after the last change.

## Connecting Cloudflare

Cloudflare is optional, and there are two ways to connect it.

**With your own API token.** In Cloudflare, go to **My Profile > API Tokens > Create Token** and create a custom token for your site's zone with these permissions:

- **Zone > Cache Purge > Purge**
- **Zone > Zone > Read** (lets the plugin find the zone; without it, enter the Zone ID in the settings)

Paste the token into **Settings > Cache**, save, and click **Test connections**. The token is stored apart from the other settings, is never shown again (only its last four characters) and is removed when you delete the plugin. You can put it in `wp-config.php` instead:

```php
define( 'CACHE_MANAGER_FOR_CLOUDPANEL_CLOUDFLARE_TOKEN', 'your-token' );
```

**With the Cloudflare plugin.** If the official Cloudflare plugin is active and signed in, and you haven't saved a token, its sign-in is used. There is nothing else to set up.

Good to know:

- Cloudflare is only purged when you ask. If you use the Cloudflare plugin, it keeps purging the pages you change, and with the page cache on, Cloudflare and browsers keep a page for at most 5 minutes.
- **Purge Cloudflare cache** clears the whole zone, which includes any other sites on the same domain.
- Cloudflare is used only on a production site. On a copy whose `WP_ENVIRONMENT_TYPE` is `staging`, `development` or `local`, it is skipped, because a copy of the site usually shares the live site's zone and would purge the live cache.

## Command line

```shell
wp cloudpanel-cache purge                                # page cache, whole site
wp cloudpanel-cache purge --layer=all                    # everything chosen for Clear all caches
wp cloudpanel-cache purge --layer=cloudflare             # page_cache, cloudflare, object_cache or wordpress
wp cloudpanel-cache purge https://example.com/shop/      # one page, in the page cache and Cloudflare
wp cloudpanel-cache status                               # the state of each cache
```

`purge` exits with an error when nothing was cleared or a cache failed, so deploy scripts can rely on it.

## Updating

Each release is published as a single commit, so update with a fetch and reset rather than `git pull`.

**With the CloudPanel Companion**, update the folder you cloned, then run the Companion's upgrade from the Companion folder, with your site's address, its CloudPanel site user and its PHP version:

```shell
cd cache-manager-for-cloudpanel && git fetch origin && git reset --hard origin/master
cd ../cloudpanel-companion
sudo python3 install-cache.py --host shop.example.com --user shop --php 8.3 --plugin-source ../cache-manager-for-cloudpanel --upgrade
```

It shows a short maintenance page, swaps the plugin, checks the site and puts the old version back automatically if anything fails. Don't copy plugin files over the live folder by hand.

**Otherwise** (CloudPanel's Varnish Cache, or no page cache), update the plugin folder in your site directly:

```shell
cd wp-content/plugins/cache-manager-for-cloudpanel && git fetch origin && git reset --hard origin/master
```

## Deactivating and deleting

- **Deactivating:** while the page cache is on, WordPress only lets you deactivate the plugin once the page cache has been cleared, so visitors never see stale pages.
- **Deleting** the plugin removes its settings, the saved Cloudflare token, its clear and warm-up history, any pending change notice, its invalidation queue table and its scheduled tasks. CloudPanel's Varnish Cache stays as set in CloudPanel. With the Companion, its page cache settings in `wp-config.php` stay; to retire that page cache completely, follow "Disabling or retiring an installation" in the Companion's [how-it-works guide](https://github.com/bomsn/cloudpanel-companion/blob/master/docs/how-it-works.md).

## What the page cache stores

With CloudPanel's own Varnish Cache, CloudPanel's rules decide what is cached; you can add excluded addresses in the site's **Varnish Cache** tab. With the Companion, its policy decides:

**Cached:** normal pages for visitors who aren't logged in, including visitors who only have analytics cookies (Google Analytics, Meta, Hotjar, ContentSquare, Microsoft Clarity, WooCommerce order attribution, Cloudflare bot checks) and links with campaign tags (`utm_*`, `gclid`, `fbclid`, `msclkid`, Mailchimp).

**Never cached:** logged-in users, carts and checkout, account pages, visitors with any other cookie, other query strings, the REST API, `wp-admin`, and any page that sets a cookie or says `private` or `no-store`.

With either setup, pages are cleared automatically when you change a post, page, product, price, stock level, category (including its details, such as an icon or description), menu, widget, theme or plugin. Visitors reading the site never clear it, even when a plugin records something on each page view (a view counter, for example). If the Companion's cache service stops, the site keeps working and runs uncached until it is back. If a clear is not confirmed, it is retried automatically until it is.

## For developers

- **Check a page:** with the Companion, the `X-Cache-Manager-For-CloudPanel` response header is `HIT` (served from the cache), `MISS` (just built and stored) or `BYPASS` (deliberately not cached). With CloudPanel's Varnish, cached pages carry CloudPanel's `X-Cache-Tags` header.
- **Clear after your own changes:** if your theme or another plugin changes something this plugin can't see (an options page, data in custom tables), clear the page cache for the whole site after saving. It is sent when the request ends, with automatic retries, and does nothing while the page cache is off.

  ```php
  do_action( 'cache_manager_for_cloudpanel_purge' );
  ```

- **Warm more pages:** add pages on your site to the warm-up list. Addresses with a query string are skipped, and the **Pages to preload** limit still applies.

  ```php
  add_filter( 'cache_manager_for_cloudpanel_warm_urls', function ( $urls ) {
      $urls[] = home_url( '/shop/' );
      return $urls;
  } );
  ```

- **Keep pages longer or shorter:** with CloudPanel's Varnish, set the cache lifetime in the site's **Varnish Cache** tab. With the Companion, set `CACHE_MANAGER_FOR_CLOUDPANEL_ORIGIN_TTL` in `wp-config.php` to a number of seconds between 300 and 86400 (the default is 3600); its 5-minute limit for Cloudflare and browsers stays the same.
- **Tests:** `python3 tests/php_checks.py` lints every PHP file and runs the unit checks. It needs only a PHP 8.1+ command line. The Varnish rules in `server/cache-policy.vcl` are tested against real Varnish and the Companion's cache service with `python3 tests/varnish_policy.py /path/to/cloudpanel-companion` (Linux, with `varnishd` installed).

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
