# Shooters Hub WooCommerce Sync

Standalone WooCommerce plugin for syncing a vendor catalog into The Shooters Hub Swap Meet.

## What It Does

- Syncs WooCommerce products to Shooters Hub vendor listings.
- Uses a Shooters Hub brand identity as the public seller.
- Supports one Shooters Hub brand with zero or many manager UIDs.
- Defaults to syncing all products and publishing immediately.
- Keeps out-of-stock products visible as out of stock.
- Tombstones deleted WooCommerce products as removed listings in Shooters Hub.
- Supports Shooters Hub listing pages, direct store links, or both.
- Includes whitelist and blacklist controls by product, category, tag, and SKU.
- Queues product syncs through Action Scheduler when available.
- Sends WooCommerce thumbnails and image URLs for Swap Meet display.

## Requirements

- WordPress with WooCommerce installed.
- A Shooters Hub brand record for the vendor.
- A Shooters Hub vendor store sync key created for that brand.

## Admin Pages

After activation, open **Shooters Hub Sync** in WordPress admin.

- **Connection**: API base URL, sync key, store ID, store URL, brand ID, buyer flow, publish mode.
- **Products**: all products, in-stock filter, per-product sync, per-product whitelist/blacklist.
- **Rules**: global all-products/in-stock mode, category/tag whitelist and blacklist, SKU blacklist, optional category mapping JSON.
- **Sync Log**: recent sync requests and API responses.

## Default Behavior

The plugin is intentionally broad by default:

- `sync_scope`: all products
- `publish_mode`: active
- `link_mode`: Shooters Hub listing page
- out of stock: synced and visible
- deleted/trashed products: sent as deleted so Shooters Hub can mark the listing removed

## Category Mapping JSON

Rules can provide a JSON object keyed by Woo category or tag slug:

```json
{
  "scopes": { "category": "optics", "productType": "optic" },
  "reloading": { "category": "reloading", "productType": "reloading" },
  "bipods": { "category": "accessories", "productType": "accessory" }
}
```

If no rule matches, Shooters Hub applies its own category hints and then falls back to accessories.

## Release

Tag a release in GitHub. The package workflow creates an installable plugin ZIP artifact.
