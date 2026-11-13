<?php
namespace CacheManagerForCloudPanel;

/**
 * The "Cache" menu in the admin bar, on the front end and in wp-admin.
 *
 * Built from the layer registry: each available layer contributes its own
 * actions, so a cache this site does not have never shows a dead item.
 */
final class AdminBar
{
    private const NODE = 'cache-manager-for-cloudpanel';

    public function __construct(private CacheLayers $layers, private PageCache $pageCache) {}

    public function register(): void
    {
        add_action('admin_bar_menu', [$this, 'menu'], 100);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        if (is_admin_bar_showing() && current_user_can('manage_options')) {
            wp_enqueue_style('cache-manager-for-cloudpanel-admin-bar', plugins_url('assets/css/admin-bar.css', PLUGIN_FILE), [], VERSION);
        }
    }

    public function menu(\WP_Admin_Bar $bar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $attention = $this->pageCache->attention();
        $title = '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">' . esc_html__('Cache', 'cache-manager-for-cloudpanel') . '</span>';
        if ($attention !== '') {
            $title .= '<span class="cmcp-indicator" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Needs attention', 'cache-manager-for-cloudpanel') . '</span>';
        }
        $bar->add_node([
            'id' => self::NODE,
            'title' => $title,
            'href' => SettingsPage::url(),
            'meta' => [
                'title' => $attention !== '' ? $attention : __('Clear and check this site\'s caches', 'cache-manager-for-cloudpanel'),
            ],
        ]);

        // The reason is spelled out in the menu as well as in the hover title,
        // which touch screens never show.
        if ($attention !== '') {
            $this->group($bar, 'status');
            $bar->add_node([
                'parent' => self::NODE . '-status',
                'id' => self::NODE . '-attention',
                'title' => Icons::svg('warning') . '<span>' . esc_html($attention) . '</span>',
                'href' => SettingsPage::url(),
                'meta' => ['class' => 'cmcp-attention'],
            ]);
        }

        $this->group($bar, 'main');
        $this->item($bar, 'main', 'clear-all', __('Clear all caches', 'cache-manager-for-cloudpanel'), 'refresh');
        if (!is_admin() && $this->canClearPages()) {
            $this->item($bar, 'main', 'clear-page', __('Clear this page', 'cache-manager-for-cloudpanel'), 'page');
        }

        $this->group($bar, 'layers');
        foreach ($this->layers->layers() as $layer) {
            if ($layer->unavailable() === '') {
                foreach ($layer->actions() as $action => $label) {
                    $this->item($bar, 'layers', $action, $label, $layer->icon());
                }
            }
        }

        $this->group($bar, 'settings', 'ab-sub-secondary');
        $bar->add_node([
            'parent' => self::NODE . '-settings',
            'id' => self::NODE . '-settings-page',
            'title' => Icons::svg('sliders') . '<span>' . esc_html__('Settings', 'cache-manager-for-cloudpanel') . '</span>',
            'href' => SettingsPage::url(),
        ]);
    }

    private function canClearPages(): bool
    {
        foreach ($this->layers->layers() as $layer) {
            if ($layer instanceof UrlLayer && $layer->unavailable() === '') {
                return true;
            }
        }
        return false;
    }

    private function group(\WP_Admin_Bar $bar, string $name, string $class = ''): void
    {
        $bar->add_group(['parent' => self::NODE, 'id' => self::NODE . '-' . $name, 'meta' => ['class' => $class]]);
    }

    private function item(\WP_Admin_Bar $bar, string $group, string $action, string $label, string $icon): void
    {
        $bar->add_node([
            'parent' => self::NODE . '-' . $group,
            'id' => self::NODE . '-' . $action,
            'title' => Icons::svg($icon) . '<span>' . esc_html($label) . '</span>',
            'href' => Actions::url($action),
        ]);
    }
}
