<?php
namespace CacheManagerForCloudPanel;

final class ContentInvalidation
{
    public function __construct(private InvalidationQueue $queue) {}

    public function register(): void
    {
        add_action('wp_after_insert_post', [$this, 'post'], 20, 2);
        add_action('deleted_post', [$this, 'post'], 20, 2);
        add_action('set_object_terms', [$this, 'terms'], 20, 1);
        add_action('deleted_term_relationships', [$this, 'terms'], 20, 1);
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [$this, 'metadata'], 20, 3);
        }
        add_action('updated_option', [$this, 'option'], 20, 1);
        foreach (['created_term', 'edited_term', 'delete_term'] as $hook) {
            add_action($hook, [$this, 'all'], 20, 0);
        }
        foreach (['woocommerce_product_set_stock', 'woocommerce_variation_set_stock',
                  'woocommerce_update_product', 'woocommerce_update_product_variation',
                  'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status'] as $hook) {
            add_action($hook, [$this, 'all'], 20, 0);
        }
        foreach (['wp_update_nav_menu', 'switch_theme', 'customize_save_after', 'upgrader_process_complete',
                  'activated_plugin', 'deactivated_plugin'] as $hook) {
            add_action($hook, [$this, 'all'], 20, 0);
        }
        // Public action for site code whose changes this plugin cannot detect itself
        // (custom term meta, option pages, recommendation settings):
        // do_action('cache_manager_for_cloudpanel_purge');
        add_action('cache_manager_for_cloudpanel_purge', [$this, 'all'], 10, 0);
    }

    public function post(int $id, \WP_Post $post): void
    {
        if (in_array($post->post_type, ['revision', 'shop_order', 'shop_order_refund'], true)) {
            return;
        }
        // This release uses one site revision, including archive and recommendation
        // dependencies. Selective invalidation is deliberately not advertised.
        $this->all();
    }

    public function terms(int $id): void
    {
        $post = get_post($id);
        if ($post instanceof \WP_Post) {
            $this->post($id, $post);
        }
    }

    public function metadata($metadataIds, int $id, string $key): void
    {
        if (in_array($key, ['_edit_lock', '_edit_last'], true)) {
            return;
        }
        $this->terms($id);
    }

    public function option(string $name): void
    {
        if (str_starts_with($name, 'widget_') || str_starts_with($name, 'theme_mods_') ||
            in_array($name, ['sidebars_widgets', 'blogname', 'blogdescription', 'permalink_structure',
                'page_on_front', 'page_for_posts', 'show_on_front', 'woocommerce_shop_page_id',
                'woocommerce_hide_out_of_stock_items', 'woocommerce_tax_display_shop'], true)) {
            $this->all();
        }
    }

    public function all(): void
    {
        $this->queue->enqueue('site');
    }
}
