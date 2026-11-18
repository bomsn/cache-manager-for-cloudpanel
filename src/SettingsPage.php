<?php
namespace CacheManagerForCloudPanel;

/**
 * Settings > Cache, top to bottom: the overall state with the two site-wide
 * actions, one status card per layer with its own actions, then the preferences
 * form (Settings API), one panel per section.
 */
final class SettingsPage
{
    public const SLUG = 'cache-manager-for-cloudpanel';
    private const GROUP = 'cache_manager_for_cloudpanel';
    private const FIELD = Settings::OPTION;

    /** Layers connected from a section of this form: layer ID => section ID. */
    private const SETUP_SECTIONS = ['cloudflare' => 'cloudflare'];

    private string $hook = '';

    public function __construct(private CacheLayers $layers, private Settings $settings) {}

    public static function url(): string
    {
        return admin_url('options-general.php?page=' . self::SLUG);
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'fields']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_page_access_denied', [$this, 'redirectOldScreen']);
        add_filter('plugin_action_links_' . plugin_basename(PLUGIN_FILE), [$this, 'actionLinks']);
    }

    public function menu(): void
    {
        $this->hook = (string) add_options_page(
            __('Cache', 'cache-manager-for-cloudpanel'),
            __('Cache', 'cache-manager-for-cloudpanel'),
            'manage_options',
            self::SLUG,
            [$this, 'render']
        );
    }

    public function enqueue(string $hook): void
    {
        if ($hook === $this->hook) {
            wp_enqueue_style('cache-manager-for-cloudpanel-settings', plugins_url('assets/css/admin.css', PLUGIN_FILE), [], VERSION);
        }
    }

    /** Bookmarks of the old Tools > CloudPanel cache screen land here instead of an error. */
    public function redirectOldScreen(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect.
        if (($GLOBALS['pagenow'] ?? '') === 'tools.php' && ($_GET['page'] ?? '') === self::SLUG && current_user_can('manage_options')) {
            wp_safe_redirect(self::url());
            exit;
        }
    }

    public function actionLinks(array $links): array
    {
        array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url(self::url()), esc_html__('Settings', 'cache-manager-for-cloudpanel')));
        return $links;
    }

    public function fields(): void
    {
        register_setting(self::GROUP, Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default' => Settings::defaults(),
        ]);
        add_settings_section('clearing', self::sectionTitle('refresh', __('Clearing', 'cache-manager-for-cloudpanel')), '__return_null', self::SLUG, self::panel('clearing'));
        add_settings_field('clear_all', __('Clear all includes', 'cache-manager-for-cloudpanel'), [$this, 'clearAllField'], self::SLUG, 'clearing');
        add_settings_field('warm', __('Cache warming', 'cache-manager-for-cloudpanel'), [$this, 'warmField'], self::SLUG, 'clearing');
        add_settings_section('cloudflare', self::sectionTitle('cloud', 'Cloudflare'), [$this, 'cloudflareIntro'], self::SLUG, self::panel('cloudflare'));
        // A token set in wp-config.php has no input for the label to point at.
        add_settings_field('cloudflare_token', __('API token', 'cache-manager-for-cloudpanel'), [$this, 'tokenField'], self::SLUG, 'cloudflare',
            Cloudflare::tokenFromConstant() ? [] : ['label_for' => 'cmcp-cloudflare-token']);
        add_settings_field('cloudflare_zone', __('Zone ID', 'cache-manager-for-cloudpanel'), [$this, 'zoneField'], self::SLUG, 'cloudflare', ['label_for' => 'cmcp-cloudflare-zone']);
    }

    /** Section heading markup; do_settings_sections() prints a section title as given. */
    private static function sectionTitle(string $icon, string $title): string
    {
        return Icons::svg($icon) . '<span>' . esc_html($title) . '</span>';
    }

    /** Wraps a section in its own panel, with an ID that status cards link to. */
    private static function panel(string $section): array
    {
        return [
            'before_section' => '<div id="' . esc_attr(self::sectionId($section)) . '" class="cmcp-panel">',
            'after_section' => '</div>',
        ];
    }

    private static function sectionId(string $section): string
    {
        return 'cmcp-section-' . $section;
    }

    /**
     * Also runs a second time on the first save (WordPress sanitizes again inside
     * add_option), so every step must accept its own output.
     */
    public function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $zone = strtolower(trim((string) ($input['cloudflare_zone'] ?? '')));
        if ($zone !== '' && !preg_match('/^[a-f0-9]{32}$/D', $zone)) {
            add_settings_error(Settings::OPTION, 'cmcp-zone', __('A Zone ID is 32 characters long (0-9 and a-f). The previous Zone ID was kept.', 'cache-manager-for-cloudpanel'));
            $zone = (string) $this->settings->get('cloudflare_zone');
        }
        $this->saveToken($input);
        return [
            'clear_all' => array_values(array_intersect(array_keys($this->layers->layers()), array_filter((array) ($input['clear_all'] ?? []), 'is_string'))),
            'warm' => !empty($input['warm']),
            'warm_limit' => Settings::boundedWarmLimit($input['warm_limit'] ?? Settings::WARM_LIMIT_DEFAULT),
            'cloudflare_zone' => $zone,
        ];
    }

    /** The token is stored in its own option and is never sent back to the browser. */
    private function saveToken(array $input): void
    {
        if (Cloudflare::tokenFromConstant()) {
            return;
        }
        if (!empty($input['cloudflare_token_remove'])) {
            Cloudflare::deleteToken();
            return;
        }
        $token = trim((string) ($input['cloudflare_token'] ?? ''));
        if ($token === '') {
            return;
        }
        if (!preg_match('/^[A-Za-z0-9_-]{20,255}$/D', $token)) {
            add_settings_error(Settings::OPTION, 'cmcp-token', __('That does not look like a Cloudflare API token, so the saved token was not changed.', 'cache-manager-for-cloudpanel'));
            return;
        }
        Cloudflare::saveToken($token);
    }

    public function clearAllField(): void
    {
        $selected = $this->layers->selectedForClearAll();
        echo '<fieldset><legend class="screen-reader-text">' . esc_html__('Clear all includes', 'cache-manager-for-cloudpanel') . '</legend>';
        foreach ($this->layers->layers() as $id => $layer) {
            printf(
                '<label class="cmcp-choice"><input type="checkbox" name="%1$s[clear_all][]" value="%2$s"%3$s>%4$s<span>%5$s</span>%6$s</label>',
                esc_attr(self::FIELD),
                esc_attr($id),
                checked(in_array($id, $selected, true), true, false),
                Icons::svg($layer->icon()),
                esc_html($layer->label()),
                $layer->unavailable() !== '' ? ' <span class="cmcp-muted">' . esc_html__('(not available on this site)', 'cache-manager-for-cloudpanel') . '</span>' : ''
            );
        }
        echo '</fieldset>';
    }

    public function warmField(): void
    {
        printf(
            '<label><input type="checkbox" name="%1$s[warm]" value="1"%2$s> %3$s</label>',
            esc_attr(self::FIELD),
            checked((bool) $this->settings->get('warm'), true, false),
            esc_html__('Preload the home page and main menu after the page cache is cleared', 'cache-manager-for-cloudpanel')
        );
        printf(
            '<p><label for="cmcp-warm-limit">%1$s</label> <input type="number" id="cmcp-warm-limit" class="small-text" name="%2$s[warm_limit]" min="1" max="%3$d" value="%4$d"></p>',
            esc_html__('Pages to preload, at most', 'cache-manager-for-cloudpanel'),
            esc_attr(self::FIELD),
            Settings::WARM_LIMIT_MAXIMUM,
            $this->settings->warmLimit()
        );
    }

    public function cloudflareIntro(): void
    {
        echo '<p class="cmcp-panel__intro">' . esc_html__('Optional. Cloudflare is purged only when you ask; the Cloudflare plugin, if you use it, keeps purging the pages you change. Without a token, that plugin\'s sign-in is used.', 'cache-manager-for-cloudpanel') . '</p>';
    }

    public function tokenField(): void
    {
        if (Cloudflare::tokenFromConstant()) {
            echo '<p>' . esc_html__('Set in wp-config.php (CACHE_MANAGER_FOR_CLOUDPANEL_CLOUDFLARE_TOKEN).', 'cache-manager-for-cloudpanel') . '</p>';
            return;
        }
        $saved = Cloudflare::token();
        printf(
            '<input type="password" id="cmcp-cloudflare-token" class="regular-text" name="%1$s[cloudflare_token]" value="" autocomplete="new-password" spellcheck="false" placeholder="%2$s">',
            esc_attr(self::FIELD),
            esc_attr($saved !== ''
                /* translators: %s: last four characters of the saved token */
                ? sprintf(__('Saved, ending in %s', 'cache-manager-for-cloudpanel'), substr($saved, -4))
                : __('Paste a token', 'cache-manager-for-cloudpanel'))
        );
        if ($saved !== '') {
            printf(
                '<p><label><input type="checkbox" name="%1$s[cloudflare_token_remove]" value="1"> %2$s</label></p>',
                esc_attr(self::FIELD),
                esc_html__('Remove the saved token', 'cache-manager-for-cloudpanel')
            );
        }
        printf(
            '<p class="description">%1$s</p><p class="description"><a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener noreferrer" class="cmcp-external">%2$s%3$s<span class="screen-reader-text"> %4$s</span></a></p>',
            esc_html__('Create a custom token for this site\'s zone with two permissions: Zone > Cache Purge > Purge, and Zone > Zone > Read (used to find the zone). Leave the field empty to keep the saved token.', 'cache-manager-for-cloudpanel'),
            esc_html__('Create a token in Cloudflare', 'cache-manager-for-cloudpanel'),
            Icons::svg('external'),
            esc_html__('(opens in a new tab)', 'cache-manager-for-cloudpanel')
        );
    }

    public function zoneField(): void
    {
        printf(
            '<input type="text" id="cmcp-cloudflare-zone" class="regular-text code" name="%1$s[cloudflare_zone]" value="%2$s" maxlength="32" spellcheck="false" autocomplete="off">',
            esc_attr(self::FIELD),
            esc_attr((string) $this->settings->get('cloudflare_zone'))
        );
        echo '<p class="description">' . esc_html__('Found automatically. Enter it only when the token cannot read zones; it is on the zone\'s Overview page in Cloudflare.', 'cache-manager-for-cloudpanel') . '</p>';
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $page = self::url();
        $statuses = [];
        foreach ($this->layers->layers() as $id => $layer) {
            $statuses[$id] = $this->layers->status($id);
        }
        $overview = $this->layers->overview($statuses);
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons returns fixed SVG markup.
        ?>
        <div class="wrap cmcp">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <div class="cmcp-overview cmcp-overview--<?php echo esc_attr($overview['tone']); ?>">
                <?php echo Icons::state($overview['tone']); ?>
                <div class="cmcp-overview__text">
                    <p class="cmcp-overview__title"><?php echo esc_html($overview['title']); ?></p>
                    <p class="cmcp-overview__description"><?php echo esc_html($overview['text']); ?></p>
                </div>
                <p class="cmcp-overview__actions">
                    <a class="button button-primary" href="<?php echo esc_url(Actions::url('clear-all', $page)); ?>"><?php echo Icons::svg('refresh'); ?><?php esc_html_e('Clear all caches', 'cache-manager-for-cloudpanel'); ?></a>
                    <a class="button" href="<?php echo esc_url(Actions::url('test', $page)); ?>"><?php echo Icons::svg('pulse'); ?><?php esc_html_e('Test connections', 'cache-manager-for-cloudpanel'); ?></a>
                </p>
            </div>
            <h2 class="cmcp-heading"><?php esc_html_e('Caches', 'cache-manager-for-cloudpanel'); ?></h2>
            <div class="cmcp-cards">
                <?php foreach ($this->layers->layers() as $id => $layer) : ?>
                    <?php $this->card($id, $layer, $statuses[$id], $page); ?>
                <?php endforeach; ?>
            </div>
            <form method="post" action="options.php" class="cmcp-form">
                <?php
                settings_fields(self::GROUP);
                do_settings_sections(self::SLUG);
                submit_button();
                ?>
            </form>
        </div>
        <?php
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /** @param array{state: string, tone: string, details: array<string, string>, meter?: array, cleared?: int} $status From CacheLayers::status(). */
    private function card(string $id, Layer $layer, array $status, string $page): void
    {
        $reason = $layer->unavailable();
        $heading = 'cmcp-card-' . $id;
        // A token set in wp-config.php cannot be changed here, so there is nothing to set up.
        $setup = $reason !== '' && isset(self::SETUP_SECTIONS[$id]) && !Cloudflare::tokenFromConstant();
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons returns fixed SVG markup; cleared() escapes its own output.
        ?>
        <section class="cmcp-card" aria-labelledby="<?php echo esc_attr($heading); ?>">
            <header class="cmcp-card__header">
                <span class="cmcp-card__icon"><?php echo Icons::svg($layer->icon()); ?></span>
                <h3 id="<?php echo esc_attr($heading); ?>"><?php echo esc_html($layer->label()); ?></h3>
                <span class="cmcp-badge cmcp-badge--<?php echo esc_attr($status['tone']); ?>"><?php echo Icons::state($status['tone']); ?><?php echo esc_html($status['state']); ?></span>
            </header>
            <?php if ($status['details'] || isset($status['meter'])) : ?>
                <dl class="cmcp-card__details">
                    <?php foreach ($status['details'] as $term => $value) : ?>
                        <dt><?php echo esc_html($term); ?></dt>
                        <dd><?php echo esc_html($value); ?></dd>
                    <?php endforeach; ?>
                    <?php if (isset($status['meter'])) : ?>
                        <dt><?php echo esc_html($status['meter']['label']); ?></dt>
                        <dd>
                            <?php echo esc_html($status['meter']['text']); ?>
                            <?php echo $this->meter($status['meter']['value'], $status['meter']['max']); ?>
                        </dd>
                    <?php endif; ?>
                </dl>
            <?php endif; ?>
            <?php if ($reason !== '') : ?>
                <p class="cmcp-card__note"><?php echo Icons::svg('info'); ?><span><?php echo esc_html($reason); ?></span></p>
            <?php endif; ?>
            <?php if ($reason === '' || $setup) : ?>
                <footer class="cmcp-card__footer">
                    <?php if (isset($status['cleared'])) : ?>
                        <p class="cmcp-card__cleared"><?php echo Icons::svg('clock'); ?><span><?php echo $this->cleared($status['cleared']); ?></span></p>
                    <?php endif; ?>
                    <p class="cmcp-card__actions">
                        <?php if ($setup) : ?>
                            <a class="button" href="#<?php echo esc_attr(self::sectionId(self::SETUP_SECTIONS[$id])); ?>"><?php
                                /* translators: %s: cache name, e.g. Cloudflare */
                                echo esc_html(sprintf(__('Set up %s', 'cache-manager-for-cloudpanel'), $layer->label()));
                            ?></a>
                        <?php else : ?>
                            <?php foreach ($layer->actions() as $action => $label) : ?>
                                <a class="button" href="<?php echo esc_url(Actions::url($action, $page)); ?>"><?php echo esc_html($label); ?></a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </p>
                </footer>
            <?php endif; ?>
        </section>
        <?php
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /** A small bar for a count such as pages warmed. Decorative: the text beside it says the same. */
    private function meter(int $value, int $max): string
    {
        $percent = $max > 0 ? (int) round(100 * min($value, $max) / $max) : 0;
        return sprintf(
            '<span class="cmcp-meter%1$s" aria-hidden="true"><span style="width: %2$d%%"></span></span>',
            $value < $max ? ' cmcp-meter--partial' : '',
            $percent
        );
    }

    /** "Cleared 5 mins ago" with the exact time on hover, or "Not cleared from here yet". Escaped. */
    private function cleared(int $time): string
    {
        if ($time <= 0) {
            return esc_html__('Not cleared from here yet', 'cache-manager-for-cloudpanel');
        }
        return sprintf(
            /* translators: %s: time since, e.g. "5 mins ago" */
            esc_html__('Cleared %s', 'cache-manager-for-cloudpanel'),
            sprintf(
                '<time datetime="%1$s" title="%2$s">%3$s</time>',
                esc_attr(gmdate('c', $time)),
                esc_attr(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $time)),
                esc_html(CacheLayers::ago($time))
            )
        );
    }
}
