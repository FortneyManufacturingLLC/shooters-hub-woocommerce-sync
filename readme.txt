=== Shooters Hub WooCommerce Sync ===
Contributors: fortneymanufacturingllc
Tags: woocommerce, marketplace, catalog, sync
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WooCommerce products to The Shooters Hub Swap Meet as brand-backed vendor listings.

== Description ==

Shooters Hub WooCommerce Sync connects a WooCommerce catalog to The Shooters Hub Swap Meet.

The plugin syncs products, stock, prices, variations, categories, tags, images, and deleted-product tombstones to a Shooters Hub vendor store endpoint. The public seller identity is a Shooters Hub brand record, while WordPress remains the source of truth for product data.

== Installation ==

1. Upload the plugin ZIP in WordPress admin.
2. Activate the plugin.
3. Open Shooters Hub Sync.
4. Enter the Shooters Hub API base URL, store sync key, store ID, store URL, and brand ID.
5. Queue a full sync from the Products screen.

== Frequently Asked Questions ==

= Does checkout happen on Shooters Hub? =

No. Synced listings can render as Shooters Hub product pages, but purchase actions link back to the vendor WooCommerce product.

= What happens to out-of-stock products? =

They remain synced and visible as out of stock.

= What happens to deleted WooCommerce products? =

The plugin sends a deletion payload. Shooters Hub marks the synced listing removed instead of hard-deleting its history.

== Changelog ==

= 0.1.0 =
Initial public plugin scaffold.
