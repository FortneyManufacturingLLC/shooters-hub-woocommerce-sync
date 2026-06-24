<?php
/**
 * Plugin Name: Shooters Hub WooCommerce Sync
 * Description: Sync WooCommerce catalog products to The Shooters Hub Swap Meet as brand-backed vendor listings.
 * Version: 0.1.0
 * Author: Fortney Manufacturing LLC
 * License: GPL-2.0-or-later
 * Text Domain: shooters-hub-woocommerce-sync
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SHWC_SYNC_VERSION', '0.1.0');
define('SHWC_SYNC_FILE', __FILE__);
define('SHWC_SYNC_OPTION', 'shwc_sync_options');
define('SHWC_SYNC_LOG_OPTION', 'shwc_sync_logs');
define('SHWC_SYNC_ACTION', 'shwc_sync_product');

final class Shooters_Hub_WooCommerce_Sync {
    private static ?Shooters_Hub_WooCommerce_Sync $instance = null;

    public static function instance(): Shooters_Hub_WooCommerce_Sync {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
        register_activation_hook(__FILE__, [$this, 'activate']);
    }

    public function activate(): void {
        $defaults = $this->default_options();
        $current = get_option(SHWC_SYNC_OPTION, []);
        update_option(SHWC_SYNC_OPTION, wp_parse_args(is_array($current) ? $current : [], $defaults));
    }

    public function init(): void {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_shwc_sync_product', [$this, 'handle_sync_product']);
        add_action('admin_post_shwc_sync_all', [$this, 'handle_sync_all']);
        add_action('admin_post_shwc_toggle_product_rule', [$this, 'handle_toggle_product_rule']);

        add_action('save_post_product', [$this, 'schedule_product_sync'], 20, 2);
        add_action('woocommerce_product_set_stock', [$this, 'schedule_stock_sync']);
        add_action('woocommerce_variation_set_stock', [$this, 'schedule_stock_sync']);
        add_action('trashed_post', [$this, 'schedule_deleted_product_sync']);
        add_action('before_delete_post', [$this, 'schedule_deleted_product_sync']);

        add_action(SHWC_SYNC_ACTION, [$this, 'sync_product_action'], 10, 2);
    }

    public function woocommerce_missing_notice(): void {
        echo '<div class="notice notice-error"><p>Shooters Hub WooCommerce Sync requires WooCommerce.</p></div>';
    }

    private function default_options(): array {
        return [
            'api_base' => 'https://us-central1-the-shooters-hub.cloudfunctions.net/api',
            'sync_key' => '',
            'store_id' => sanitize_title(get_bloginfo('name')),
            'store_url' => home_url('/'),
            'brand_id' => '',
            'link_mode' => 'shooters_hub_listing',
            'publish_mode' => 'active',
            'sync_scope' => 'all',
            'include_categories' => [],
            'exclude_categories' => [],
            'include_tags' => [],
            'exclude_tags' => [],
            'exclude_skus' => '',
            'category_mappings' => '',
        ];
    }

    private function options(): array {
        $options = get_option(SHWC_SYNC_OPTION, []);
        return wp_parse_args(is_array($options) ? $options : [], $this->default_options());
    }

    public function admin_menu(): void {
        add_menu_page(
            'Shooters Hub Sync',
            'Shooters Hub Sync',
            'manage_woocommerce',
            'shooters-hub-sync',
            [$this, 'render_connection_page'],
            'dashicons-update',
            56
        );
        add_submenu_page('shooters-hub-sync', 'Connection', 'Connection', 'manage_woocommerce', 'shooters-hub-sync', [$this, 'render_connection_page']);
        add_submenu_page('shooters-hub-sync', 'Products', 'Products', 'manage_woocommerce', 'shooters-hub-products', [$this, 'render_products_page']);
        add_submenu_page('shooters-hub-sync', 'Rules', 'Rules', 'manage_woocommerce', 'shooters-hub-rules', [$this, 'render_rules_page']);
        add_submenu_page('shooters-hub-sync', 'Sync Log', 'Sync Log', 'manage_woocommerce', 'shooters-hub-sync-log', [$this, 'render_log_page']);
    }

    public function register_settings(): void {
        register_setting('shwc_sync_settings', SHWC_SYNC_OPTION, [$this, 'sanitize_options']);
    }

    public function sanitize_options($input): array {
        $input = is_array($input) ? $input : [];
        $current = $this->options();
        return [
            'api_base' => esc_url_raw($input['api_base'] ?? $current['api_base']),
            'sync_key' => sanitize_text_field($input['sync_key'] ?? $current['sync_key']),
            'store_id' => sanitize_title($input['store_id'] ?? $current['store_id']),
            'store_url' => esc_url_raw($input['store_url'] ?? $current['store_url']),
            'brand_id' => sanitize_text_field($input['brand_id'] ?? $current['brand_id']),
            'link_mode' => in_array(($input['link_mode'] ?? ''), ['shooters_hub_listing', 'external_store', 'both'], true) ? $input['link_mode'] : 'shooters_hub_listing',
            'publish_mode' => in_array(($input['publish_mode'] ?? ''), ['active', 'pending_review', 'paused'], true) ? $input['publish_mode'] : 'active',
            'sync_scope' => in_array(($input['sync_scope'] ?? ''), ['all', 'in_stock'], true) ? $input['sync_scope'] : 'all',
            'include_categories' => array_map('absint', (array)($input['include_categories'] ?? [])),
            'exclude_categories' => array_map('absint', (array)($input['exclude_categories'] ?? [])),
            'include_tags' => array_map('absint', (array)($input['include_tags'] ?? [])),
            'exclude_tags' => array_map('absint', (array)($input['exclude_tags'] ?? [])),
            'exclude_skus' => sanitize_textarea_field($input['exclude_skus'] ?? ''),
            'category_mappings' => sanitize_textarea_field($input['category_mappings'] ?? ''),
        ];
    }

    public function render_connection_page(): void {
        $options = $this->options();
        ?>
        <div class="wrap">
            <h1>Shooters Hub WooCommerce Sync</h1>
            <form method="post" action="options.php">
                <?php settings_fields('shwc_sync_settings'); ?>
                <table class="form-table" role="presentation">
                    <?php $this->text_row('api_base', 'Shooters Hub API Base', $options['api_base']); ?>
                    <?php $this->text_row('sync_key', 'Store Sync Key', $options['sync_key'], 'password'); ?>
                    <?php $this->text_row('store_id', 'Store ID', $options['store_id']); ?>
                    <?php $this->text_row('store_url', 'Store URL', $options['store_url']); ?>
                    <?php $this->text_row('brand_id', 'Shooters Hub Brand ID', $options['brand_id']); ?>
                    <tr>
                        <th scope="row"><label for="shwc_link_mode">Buyer Flow</label></th>
                        <td>
                            <select id="shwc_link_mode" name="<?php echo esc_attr(SHWC_SYNC_OPTION); ?>[link_mode]">
                                <?php $this->option('shooters_hub_listing', 'Shooters Hub listing page', $options['link_mode']); ?>
                                <?php $this->option('external_store', 'Direct vendor store link', $options['link_mode']); ?>
                                <?php $this->option('both', 'Both', $options['link_mode']); ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="shwc_publish_mode">Publish Mode</label></th>
                        <td>
                            <select id="shwc_publish_mode" name="<?php echo esc_attr(SHWC_SYNC_OPTION); ?>[publish_mode]">
                                <?php $this->option('active', 'Active immediately', $options['publish_mode']); ?>
                                <?php $this->option('pending_review', 'Pending review', $options['publish_mode']); ?>
                                <?php $this->option('paused', 'Paused', $options['publish_mode']); ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private function text_row(string $key, string $label, string $value, string $type = 'text'): void {
        ?>
        <tr>
            <th scope="row"><label for="shwc_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td><input class="regular-text" id="shwc_<?php echo esc_attr($key); ?>" type="<?php echo esc_attr($type); ?>" name="<?php echo esc_attr(SHWC_SYNC_OPTION); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>" /></td>
        </tr>
        <?php
    }

    private function option(string $value, string $label, string $selected): void {
        printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($selected, $value, false), esc_html($label));
    }

    public function render_rules_page(): void {
        $options = $this->options();
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        $tags = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false]);
        ?>
        <div class="wrap">
            <h1>Shooters Hub Sync Rules</h1>
            <form method="post" action="options.php">
                <?php settings_fields('shwc_sync_settings'); ?>
                <input type="hidden" name="<?php echo esc_attr(SHWC_SYNC_OPTION); ?>[api_base]" value="<?php echo esc_attr($options['api_base']); ?>" />
                <input type="hidden" name="<?php echo esc_attr(SHWC_SYNC_OPTION); ?>[sync_key]" value="<?php echo esc_attr($options['sync_key']); ?>" />
                <input type="hidden" name="<?php echo esc_attr(SHWC_SYNC_OPTION); ?>[store_id]" value="<?php echo esc_attr($options['store_id']); ?>" />
                <input type="hidden" name="<?php echo esc_attr(SHWC_SYNC_OPTION); ?>[store_url]" value="<?php echo esc_attr($options['store_url']); ?>" />
                <input type="hidden" name="<?php echo esc_attr(SHWC_SYNC_OPTION); ?>[brand_id]" value="<?php echo esc_attr($options['brand_id']); ?>" />
                <input type="hidden" name="<?php echo esc_attr(SHWC_SYNC_OPTION); ?>[link_mode]" value="<?php echo esc_attr($options['link_mode']); ?>" />
                <input type="hidden" name="<?php echo esc_attr(SHWC_SYNC_OPTION); ?>[publish_mode]" value="<?php echo esc_attr($options['publish_mode']); ?>" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Product Scope</th>
                        <td>
                            <label><input type="radio" name="<?php echo esc_attr(SHWC_SYNC_OPTION); ?>[sync_scope]" value="all" <?php checked($options['sync_scope'], 'all'); ?> /> Sync all products</label><br />
                            <label><input type="radio" name="<?php echo esc_attr(SHWC_SYNC_OPTION); ?>[sync_scope]" value="in_stock" <?php checked($options['sync_scope'], 'in_stock'); ?> /> Sync only in-stock products</label>
                        </td>
                    </tr>
                    <?php $this->term_checkboxes('Whitelist Categories', 'include_categories', $categories, $options['include_categories']); ?>
                    <?php $this->term_checkboxes('Blacklist Categories', 'exclude_categories', $categories, $options['exclude_categories']); ?>
                    <?php $this->term_checkboxes('Whitelist Tags', 'include_tags', $tags, $options['include_tags']); ?>
                    <?php $this->term_checkboxes('Blacklist Tags', 'exclude_tags', $tags, $options['exclude_tags']); ?>
                    <tr>
                        <th scope="row"><label for="shwc_exclude_skus">Blacklisted SKUs</label></th>
                        <td><textarea class="large-text" rows="4" id="shwc_exclude_skus" name="<?php echo esc_attr(SHWC_SYNC_OPTION); ?>[exclude_skus]"><?php echo esc_textarea($options['exclude_skus']); ?></textarea><p class="description">One SKU per line.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="shwc_category_mappings">Category Mappings</label></th>
                        <td><textarea class="large-text code" rows="10" id="shwc_category_mappings" name="<?php echo esc_attr(SHWC_SYNC_OPTION); ?>[category_mappings]"><?php echo esc_textarea($options['category_mappings']); ?></textarea><p class="description">Optional JSON keyed by Woo category/tag slug. Example: {"scopes":{"category":"optics","productType":"optic"}}</p></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private function term_checkboxes(string $label, string $key, $terms, array $selected): void {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($label); ?></th>
            <td>
                <?php if (is_wp_error($terms) || empty($terms)): ?>
                    <em>No terms found.</em>
                <?php else: foreach ($terms as $term): ?>
                    <label style="display:inline-block; min-width: 220px; margin-bottom: 6px;">
                        <input type="checkbox" name="<?php echo esc_attr(SHWC_SYNC_OPTION); ?>[<?php echo esc_attr($key); ?>][]" value="<?php echo esc_attr($term->term_id); ?>" <?php checked(in_array((int)$term->term_id, $selected, true)); ?> />
                        <?php echo esc_html($term->name); ?>
                    </label>
                <?php endforeach; endif; ?>
            </td>
        </tr>
        <?php
    }

    public function render_products_page(): void {
        $filter = sanitize_key($_GET['shwc_filter'] ?? 'all');
        $paged = max(1, absint($_GET['paged'] ?? 1));
        $query = [
            'status' => ['publish', 'draft', 'pending', 'private'],
            'limit' => 25,
            'page' => $paged,
            'paginate' => true,
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        if ($filter === 'in_stock') {
            $query['stock_status'] = 'instock';
        }
        $result = wc_get_products($query);
        $products = $result->products ?? [];
        ?>
        <div class="wrap">
            <h1>Shooters Hub Products</h1>
            <p>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=shooters-hub-products')); ?>">All products</a>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=shooters-hub-products&shwc_filter=in_stock')); ?>">In stock</a>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=shwc_sync_all'), 'shwc_sync_all')); ?>">Queue full sync</a>
            </p>
            <table class="widefat striped">
                <thead><tr><th>Product</th><th>SKU</th><th>Stock</th><th>Rule</th><th>Last Sync</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($products as $product): ?>
                    <?php
                    $id = $product->get_id();
                    $excluded = get_post_meta($id, '_shwc_sync_excluded', true) === 'yes';
                    $last_sync = get_post_meta($id, '_shwc_last_sync_at', true);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($product->get_name()); ?></strong><br /><code><?php echo esc_html((string)$id); ?></code></td>
                        <td><?php echo esc_html($product->get_sku()); ?></td>
                        <td><?php echo esc_html($product->get_stock_status()); ?><?php echo $product->managing_stock() ? ' (' . esc_html((string)$product->get_stock_quantity()) . ')' : ''; ?></td>
                        <td><?php echo $excluded ? '<span style="color:#b32d2e;">Blacklisted</span>' : '<span style="color:#008a20;">Sync enabled</span>'; ?></td>
                        <td><?php echo esc_html($last_sync ?: 'Never'); ?></td>
                        <td>
                            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=shwc_sync_product&product_id=' . $id), 'shwc_sync_product_' . $id)); ?>">Sync now</a>
                            <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=shwc_toggle_product_rule&product_id=' . $id), 'shwc_toggle_product_rule_' . $id)); ?>"><?php echo $excluded ? 'Whitelist' : 'Blacklist'; ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function render_log_page(): void {
        $logs = get_option(SHWC_SYNC_LOG_OPTION, []);
        $logs = is_array($logs) ? array_reverse($logs) : [];
        ?>
        <div class="wrap">
            <h1>Shooters Hub Sync Log</h1>
            <table class="widefat striped">
                <thead><tr><th>Time</th><th>Level</th><th>Message</th><th>Context</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($logs, 0, 200) as $log): ?>
                    <tr>
                        <td><?php echo esc_html($log['time'] ?? ''); ?></td>
                        <td><?php echo esc_html($log['level'] ?? 'info'); ?></td>
                        <td><?php echo esc_html($log['message'] ?? ''); ?></td>
                        <td><code><?php echo esc_html(wp_json_encode($log['context'] ?? [])); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function handle_sync_product(): void {
        $product_id = absint($_GET['product_id'] ?? 0);
        check_admin_referer('shwc_sync_product_' . $product_id);
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden');
        }
        $this->queue_product_sync($product_id, false);
        wp_safe_redirect(admin_url('admin.php?page=shooters-hub-products'));
        exit;
    }

    public function handle_sync_all(): void {
        check_admin_referer('shwc_sync_all');
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden');
        }
        $ids = wc_get_products(['status' => ['publish', 'draft', 'pending', 'private'], 'limit' => -1, 'return' => 'ids']);
        foreach ($ids as $id) {
            $this->queue_product_sync((int)$id, false);
        }
        $this->log('info', 'Queued full product sync', ['count' => count($ids)]);
        wp_safe_redirect(admin_url('admin.php?page=shooters-hub-products'));
        exit;
    }

    public function handle_toggle_product_rule(): void {
        $product_id = absint($_GET['product_id'] ?? 0);
        check_admin_referer('shwc_toggle_product_rule_' . $product_id);
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Forbidden');
        }
        $excluded = get_post_meta($product_id, '_shwc_sync_excluded', true) === 'yes';
        update_post_meta($product_id, '_shwc_sync_excluded', $excluded ? 'no' : 'yes');
        wp_safe_redirect(admin_url('admin.php?page=shooters-hub-products'));
        exit;
    }

    public function schedule_product_sync(int $post_id, WP_Post $post): void {
        if ($post->post_type !== 'product' || wp_is_post_revision($post_id)) {
            return;
        }
        $this->queue_product_sync($post_id);
    }

    public function schedule_stock_sync($product): void {
        if ($product instanceof WC_Product) {
            $this->queue_product_sync($product->get_parent_id() ?: $product->get_id());
        }
    }

    public function schedule_deleted_product_sync(int $post_id): void {
        if (get_post_type($post_id) === 'product') {
            $this->queue_product_sync($post_id, true);
        }
    }

    private function queue_product_sync(int $product_id, bool $deleted = false): void {
        if (!$product_id) {
            return;
        }
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(SHWC_SYNC_ACTION, ['product_id' => $product_id, 'deleted' => $deleted], 'shooters-hub');
            return;
        }
        wp_schedule_single_event(time() + 5, SHWC_SYNC_ACTION, [$product_id, $deleted]);
    }

    public function sync_product_action(int $product_id, bool $deleted = false): void {
        $product = wc_get_product($product_id);
        if (!$product && !$deleted) {
            return;
        }
        $payload = $deleted ? $this->deleted_product_payload($product_id) : $this->product_payload($product);
        if (!$payload) {
            return;
        }
        $result = $this->send_products([$payload]);
        if ($result['ok']) {
            update_post_meta($product_id, '_shwc_last_sync_at', gmdate('c'));
        }
    }

    private function should_sync_product(WC_Product $product): bool {
        if (get_post_meta($product->get_id(), '_shwc_sync_excluded', true) === 'yes') {
            return false;
        }
        $options = $this->options();
        if ($options['sync_scope'] === 'in_stock' && !$product->is_in_stock()) {
            return false;
        }
        $exclude_skus = array_filter(array_map('trim', preg_split('/\r?\n/', (string)$options['exclude_skus'])));
        if ($product->get_sku() && in_array($product->get_sku(), $exclude_skus, true)) {
            return false;
        }
        $category_ids = wc_get_product_term_ids($product->get_id(), 'product_cat');
        $tag_ids = wc_get_product_term_ids($product->get_id(), 'product_tag');
        if ($options['include_categories'] && !array_intersect($category_ids, $options['include_categories'])) {
            return false;
        }
        if ($options['include_tags'] && !array_intersect($tag_ids, $options['include_tags'])) {
            return false;
        }
        if (array_intersect($category_ids, $options['exclude_categories']) || array_intersect($tag_ids, $options['exclude_tags'])) {
            return false;
        }
        return true;
    }

    private function product_payload(?WC_Product $product): ?array {
        if (!$product || !$this->should_sync_product($product)) {
            return null;
        }
        $id = $product->get_id();
        $image_ids = array_values(array_filter(array_merge([$product->get_image_id()], $product->get_gallery_image_ids())));
        $variations = [];
        if ($product->is_type('variable')) {
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) {
                    continue;
                }
                $variations[] = [
                    'id' => $variation->get_id(),
                    'name' => $variation->get_name(),
                    'sku' => $variation->get_sku(),
                    'price' => $variation->get_price(),
                    'regular_price' => $variation->get_regular_price(),
                    'sale_price' => $variation->get_sale_price(),
                    'stock_quantity' => $variation->get_stock_quantity(),
                    'stock_status' => $variation->get_stock_status(),
                    'in_stock' => $variation->is_in_stock(),
                    'permalink' => get_permalink($variation->get_parent_id()),
                    'attributes' => $variation->get_attributes(),
                ];
            }
        }
        return [
            'id' => $id,
            'sku' => $product->get_sku(),
            'slug' => $product->get_slug(),
            'name' => $product->get_name(),
            'permalink' => get_permalink($id),
            'short_description' => wp_strip_all_tags($product->get_short_description()),
            'description' => wp_strip_all_tags($product->get_description()),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status' => $product->get_stock_status(),
            'in_stock' => $product->is_in_stock(),
            'status' => get_post_status($id),
            'date_modified_gmt' => $product->get_date_modified() ? $product->get_date_modified()->date('c') : null,
            'images' => array_map([$this, 'image_payload'], $image_ids),
            'categories' => $this->term_payloads($id, 'product_cat'),
            'tags' => $this->term_payloads($id, 'product_tag'),
            'attributes' => $this->attribute_payloads($product),
            'variations' => $variations,
        ];
    }

    private function deleted_product_payload(int $product_id): array {
        return [
            'id' => $product_id,
            'name' => get_the_title($product_id),
            'permalink' => get_permalink($product_id),
            'status' => 'deleted',
            'deleted' => true,
            'stock_status' => 'outofstock',
            'in_stock' => false,
            'stock_quantity' => 0,
        ];
    }

    public function image_payload(int $attachment_id): array {
        return [
            'id' => $attachment_id,
            'src' => wp_get_attachment_url($attachment_id),
            'thumbnail' => wp_get_attachment_image_url($attachment_id, 'woocommerce_thumbnail') ?: wp_get_attachment_url($attachment_id),
            'alt' => get_post_meta($attachment_id, '_wp_attachment_image_alt', true),
        ];
    }

    private function term_payloads(int $product_id, string $taxonomy): array {
        $terms = get_the_terms($product_id, $taxonomy);
        if (is_wp_error($terms) || !$terms) {
            return [];
        }
        return array_map(static fn($term) => ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug], $terms);
    }

    private function attribute_payloads(WC_Product $product): array {
        $out = [];
        foreach ($product->get_attributes() as $attribute) {
            if ($attribute instanceof WC_Product_Attribute) {
                $out[] = [
                    'name' => wc_attribute_label($attribute->get_name()),
                    'slug' => $attribute->get_name(),
                    'options' => array_map('strval', $attribute->get_options()),
                ];
            }
        }
        return $out;
    }

    private function category_mappings(): array {
        $json = $this->options()['category_mappings'];
        $decoded = json_decode((string)$json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function send_products(array $products): array {
        $options = $this->options();
        if (!$options['api_base'] || !$options['sync_key']) {
            $this->log('error', 'Missing API base or sync key');
            return ['ok' => false];
        }
        $url = trailingslashit($options['api_base']) . 'marketplace/vendor-sync/woocommerce';
        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Shooters-Hub-Sync-Key' => $options['sync_key'],
            ],
            'body' => wp_json_encode([
                'storeId' => $options['store_id'],
                'storeUrl' => $options['store_url'],
                'brandId' => $options['brand_id'],
                'pluginVersion' => SHWC_SYNC_VERSION,
                'wordpressUrl' => home_url('/'),
                'linkMode' => $options['link_mode'],
                'publishMode' => $options['publish_mode'],
                'categoryMappings' => $this->category_mappings(),
                'products' => $products,
            ]),
        ]);
        if (is_wp_error($response)) {
            $this->log('error', 'Sync request failed', ['error' => $response->get_error_message()]);
            return ['ok' => false];
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        $body = json_decode((string)wp_remote_retrieve_body($response), true);
        $ok = $code >= 200 && $code < 300;
        $this->log($ok ? 'info' : 'error', $ok ? 'Synced products' : 'Sync failed', ['status' => $code, 'response' => $body]);
        return ['ok' => $ok, 'response' => $body, 'status' => $code];
    }

    private function log(string $level, string $message, array $context = []): void {
        $logs = get_option(SHWC_SYNC_LOG_OPTION, []);
        $logs = is_array($logs) ? $logs : [];
        $logs[] = ['time' => gmdate('c'), 'level' => $level, 'message' => $message, 'context' => $context];
        update_option(SHWC_SYNC_LOG_OPTION, array_slice($logs, -500), false);
    }
}

Shooters_Hub_WooCommerce_Sync::instance();
