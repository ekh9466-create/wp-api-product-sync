# wp-api-product-sync

A WordPress plugin to transfer/sync WooCommerce products between two sites via the WooCommerce REST API.

## Features
- List source-site products inside the WordPress admin
- Select a product and transfer it to the destination site
- Transfers full product content (title, description, featured image, gallery, attributes)
- Useful for migration and content synchronization workflows

## Requirements
- WordPress + WooCommerce on both sites
- WooCommerce REST API Consumer Key/Secret
- Network access from the source site to the destination site

## Installation
1) Upload the plugin folder to:
   `wp-content/plugins/wp-api-product-sync/`
2) Activate the plugin from **Plugins**.
3) Fill in connection settings (Base URL + API keys) in the plugin settings page.

## Notes
- Price is intentionally not transferred (by current requirement).

## License
GPL-2.0
