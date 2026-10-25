=== Listing Importer for HivePress ===
Contributors: curiouswpdev
Donate link: https://github.com/
Tags: hivepress, import, csv, listing, listings, importer
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Fast, chunked CSV importer and exporter for HivePress listings with smart binary MIME-type detection for CDN images and image deduplication.

== Description ==

Listing Importer for HivePress is a high-performance, admin-centric listing importer and exporter designed specifically for HivePress websites.

Traditional importers often fail on large CSV files due to server timeouts or choke on dynamic CDN/extensionless image URLs (such as Google Places, AWS S3, Cloudinary, and Unsplash). This plugin solves those challenges by combining asynchronous batch processing with intelligent media handling.

= Key Features =

* **Chunked Asynchronous Processing**: Import CSV rows in batches via AJAX with a real-time progress bar. Eliminates PHP execution timeouts and 504 Gateway errors on large datasets.
* **Resume Capability**: If an import is interrupted by a network drop, the importer safely resumes exactly from the pending row offset without re-importing earlier listings.
* **Smart Binary MIME Detection**: Automatically sniffs image headers (`image/jpeg`, `image/png`, `image/webp`, `image/avif`) on extensionless and CDN image URLs, resolving and applying the correct file extension automatically.
* **Image Deduplication**: Calculates MD5 checksums on source image URLs to reuse existing Media Library items, preventing duplicated image files across listings.
* **Flexible Column Mapping**: Easily map CSV headers to HivePress core fields, custom category attributes, pricing, location, and tags.
* **Nested & Outscraper Format Support**: Cleanly converts complex dot-notated column names (e.g., `company_insights.founded_year`) into readable labels.
* **CSV Listing Exporter**: Stream download clean CSV exports of existing listings filtered by category, including custom attributes and Excel UTF-8 BOM encoding.
* **Admin-Centric Wizard**: Accessible directly from `Listings > Import Listings` with clean step-by-step navigation.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory, or install via the WordPress Plugins menu.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Ensure the core **HivePress** plugin is active.
4. Navigate to **Listings > Import Listings** to start importing or exporting.

== Frequently Asked Questions ==

= Does this plugin require HivePress? =
Yes, this plugin is an extension for the HivePress directory platform and requires HivePress to be installed and active.

= Can I import images from CDNs or URLs without extensions? =
Yes. The plugin uses binary MIME inspection to determine the format of extensionless image URLs (like Unsplash, Google Places, or AWS S3) and applies the correct file extension upon sideloading.

= Will re-importing download duplicate images? =
No. The plugin tracks the source URL of imported images using an MD5 hash. If an image from the same URL was already imported, it links the existing Media Library attachment rather than downloading a duplicate file.

= What happens if the import fails midway? =
Because processing is done in AJAX chunks, your progress is saved after each row. You can resume the import without losing previously imported data.

== Screenshots ==

1. Step 1: Upload CSV file and choose delimiter and mode.
2. Step 2: Map CSV columns to HivePress fields and custom attributes.
3. Step 3: Asynchronous progress bar with real-time created, updated, and failed counters.
4. Export: Filter and export listings to CSV.

== Changelog ==

= 1.0.1 =
* Renamed plugin to Listing Importer for HivePress in compliance with WordPress.org trademark guidelines.
* Added official GPLv3 licensing and standardized readme.txt.
* Enhanced image deduplication and MIME-type detection.

= 1.0.0 =
* Initial release with chunked AJAX batching and CSV export support.
