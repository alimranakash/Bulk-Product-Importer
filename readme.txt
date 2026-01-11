=== Bulk Product Importer for WooCommerce ===
Contributors: alimranakash
Tags: woocommerce, bulk import, product import, excel import, csv alternative
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import thousands of WooCommerce products with Excel files and images. Background processing ensures zero timeouts and 100% success rate.

== Description ==

**Bulk Product Importer** is the fastest and most reliable way to import WooCommerce products. Unlike the default CSV importer, our plugin uses background processing to handle imports of any size without timeouts or failures.

= 🚀 Key Features =

* **Excel File Support** - Import products using familiar .xlsx and .xls spreadsheets
* **Image Bundling** - Include product images in the same ZIP file for automatic upload
* **Background Processing** - Uses WooCommerce's Action Scheduler for reliable imports
* **Zero Timeouts** - Products are processed in batches, eliminating timeout errors
* **Variable Products** - Full support for variable products with unlimited variations
* **Smart Updates** - Existing products are updated based on SKU matching
* **Progress Tracking** - Real-time progress updates during import
* **Configurable Batches** - Adjust batch size based on your server capacity

= 📊 Why Choose Bulk Product Importer? =

| Feature | Bulk Product Importer | WooCommerce CSV |
|---------|----------------------|-----------------|
| File Format | Excel (.xlsx, .xls) | CSV only |
| Image Bundling | ✅ ZIP with images | ❌ URLs only |
| Background Processing | ✅ Action Scheduler | ❌ Single request |
| Large Imports (5000+) | ✅ No timeouts | ❌ Often fails |
| Close Browser | ✅ Continues | ❌ Import fails |

= ⏱️ Time Savings =

* Manual entry: ~5 minutes per product
* 1,000 products manually: **83 hours**
* 1,000 products with our plugin: **~40 minutes**
* **That's a 125x improvement!**

= 📋 How It Works =

1. **Prepare** - Create Excel file with product data, add images to "images" folder
2. **ZIP** - Compress Excel file(s) and images folder into a single ZIP
3. **Upload** - Upload ZIP to the plugin interface
4. **Import** - Click "Start Import" and watch the progress
5. **Done** - Products appear in WooCommerce, images in Media Library

= 🎯 Perfect For =

* WooCommerce store owners with large catalogs
* Developers and agencies managing multiple client stores
* E-commerce businesses migrating from other platforms
* Dropshipping stores importing supplier catalogs
* Anyone tired of manual product entry

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/bulk-product-importer/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to 'Bulk Importer' in the WordPress admin menu
4. Upload your ZIP file containing Excel and images
5. Click 'Start Import' and monitor progress

= Minimum Requirements =

* WordPress 5.8 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher
* PHP ZIP extension enabled

== Frequently Asked Questions ==

= What file format does the plugin accept? =

The plugin accepts ZIP files containing Excel spreadsheets (.xlsx or .xls) and an optional "images" folder with product images.

= Will importing overwrite my existing products? =

Products are matched by SKU. If a product with the same SKU exists, it will be updated. New SKUs create new products.

= Can I close my browser during import? =

Yes! Once the import starts, it runs in the background using WooCommerce's Action Scheduler. You can close your browser and check back later.

= How many products can I import at once? =

There's no hard limit. We've tested with 20,000+ products successfully. The import runs in batches to prevent timeouts.

= What happens if the import fails midway? =

Products already imported remain in WooCommerce. You can re-run the import – existing products will be updated, not duplicated.

= Does it support variable products? =

Yes! Full support for variable products with multiple attributes and unlimited variations.

== Screenshots ==

1. Main import interface with drag-and-drop upload
2. Import progress with real-time updates
3. Completed import summary
4. Sample Excel file format

== Changelog ==

= 1.0.0 =
* Initial release
* Excel file support (.xlsx, .xls)
* Image bundling in ZIP files
* Background processing with Action Scheduler
* Simple and variable product support
* Progress tracking interface
* Configurable batch sizes
* SKU-based product updates

== Upgrade Notice ==

= 1.0.0 =
Initial release of Bulk Product Importer for WooCommerce.

== Excel File Format ==

Your Excel file should contain these columns:

= Required Columns =
* **Type** - Product type: simple, variable, or variation
* **SKU** - Unique product identifier
* **Name** - Product title
* **Regular Price** - Standard price (leave empty for variable products)

= Optional Columns =
* **Description** - Full product description (HTML allowed)
* **Short Description** - Brief product summary
* **Sale Price** - Discounted price
* **Stock** - Inventory quantity
* **Categories** - Pipe-separated categories (e.g., Clothing|T-Shirts)
* **Tags** - Pipe-separated tags
* **Images** - Pipe-separated image filenames
* **Weight** - Product weight
* **Length**, **Width**, **Height** - Product dimensions

= Variable Products =
* **Attribute 1 Name** - First attribute name (e.g., Size)
* **Attribute 1 Values** - Pipe-separated values (e.g., S|M|L|XL)
* **Attribute 1 Visible** - 1 for visible, 0 for hidden
* **Attribute 1 Variation** - 1 if used for variations

= Variations =
* **Parent SKU** - SKU of the parent variable product
* **Attribute 1 Value** - Specific value for this variation

== ZIP File Structure ==

`
my-products.zip
├── products.xlsx          (Required: Product data)
├── electronics.xlsx       (Optional: Multiple files supported)
└── images/                (Optional: Must be named "images")
    ├── product-001.jpg
    ├── product-002.jpg
    └── ...
`

== Support ==

For support, feature requests, or bug reports, please contact us through our website or the WordPress support forums.

== Privacy Policy ==

This plugin does not collect or store any personal data. All product data remains on your server.

