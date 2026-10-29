=== Listonix: Listing Importer for HivePress ===
Contributors: curiouswpdev
Tags: hivepress, import, csv, listing, listings
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: hivepress
Stable tag: 1.0.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Fast, reliable CSV listing importer and exporter for HivePress with chunked background batching, smart image MIME detection, and deduplication.

== Description ==

**Listonix: Listing Importer for HivePress** is a powerful, user-friendly tool built to help you import hundreds or thousands of listings into your HivePress directory website effortlessly—preventing server timeouts, handling dynamic image URLs, and avoiding duplicate media clutter.

Whether you are building a real estate portal, business directory, classifieds site, or service marketplace, adding listings manually one-by-one is tedious and time-consuming. This plugin allows you to upload any standard spreadsheet (CSV or TXT), match your columns with a few clicks, and watch your listings get published with full attribute and gallery support.

---

### Key Capabilities Built for Scale

Listonix provides five core innovations for reliable directory management:

#### 1. Asynchronous Chunked Batching (No Timeouts)
Instead of processing an entire spreadsheet in a single server request, the plugin processes rows in small, manageable chunks in the background via AJAX. A real-time visual progress bar shows you exactly what row is currently being imported, how many listings have been created or updated, and any rows that encountered issues.

#### 2. Automatic Resume & Failure Recovery
If an import is interrupted by a temporary network drop or server restart, you do not have to start over or clean up half-imported data. The plugin saves its exact byte offset after each row. Simply click **"Retry / Resume Import"**, and it picks up right where it stopped without re-importing earlier rows.

#### 3. Smart Binary MIME Sniffing for CDN & Dynamic Images
Many modern image providers, scrapers, and CDNs (such as Unsplash, Google Places, AWS S3, or Cloudinary) provide image URLs that do not end with standard `.jpg` or `.png` extensions.
Our plugin reads the actual binary header of the downloaded image file, automatically detects whether it is a JPEG, PNG, WebP, GIF, or AVIF image, assigns the correct extension, and sideloads it into your WordPress Media Library and HivePress listing gallery.

#### 4. Image Deduplication (Saves Server Disk Space)
Re-importing updated data can fill up your media library with redundant copies (`photo-1.jpg`, `photo-2.jpg`, etc.). This plugin calculates cryptographic hashes for each source image URL. If an image has already been imported, the plugin links the existing media file rather than downloading another copy, saving hosting disk space.

#### 5. Intelligent Field Auto-Matching & Clean Headers
If you export data from tools with nested or dotted column headers (such as `company_insights.founded_year` or `contact_details.phone`), the plugin automatically cleans these into readable labels (`Company Insights → Founded Year`) and pre-selects matching HivePress fields (Title, Description, Images, Categories, Location, Phone, etc.).

---

### Key Features at a Glance

* **Visual 3-Step Wizard**: Located conveniently in your WordPress admin menu under **Listings > Import Listings**.
* **Flexible Import Modes**:
  * *Create new listings only*: Adds new listings without modifying existing ones.
  * *Update existing listings only*: Updates records matching an ID column.
  * *Update existing or create new*: Updates listings if an ID is present, or creates a new listing if not.
* **Full HivePress Attribute Support**: Compatible with all core HivePress fields (Title, Description, Featured, Verified, Author, Images) plus any custom category-specific attributes you create in **Listings > Attributes**.
* **Category Mapping or Bulk Assignment**: Assign a single category to the entire import with one click, or map a CSV column containing category names, slugs, or IDs.
* **Multiple Images per Listing**: Supports comma-separated image URLs in a single column (e.g. `https://site.com/img1.jpg, https://site.com/img2.jpg`). The first image is set as the Featured Thumbnail, and subsequent images are attached to the listing gallery.
* **Built-in CSV Listing Exporter**: Need a backup or want to edit listings in a spreadsheet? Export your existing listings filtered by category into a clean CSV file with Excel UTF-8 BOM encoding so special characters open correctly in Microsoft Excel, Google Sheets, or Numbers.
* **Enterprise-Grade Security**: Fully protected against CSRF, unauthorized access, path traversal, CSV formula injection (CWE-1236), and Server-Side Request Forgery (SSRF).

---

### How to Use the Plugin in 3 Easy Steps

1. **Step 1: Upload Your File**
   Go to **Listings > Import Listings** in your WordPress admin. Choose your CSV file, select your delimiter (usually a comma `,`), pick your import mode (Create or Update), and optionally pick a default category.
2. **Step 2: Map Your Columns**
   Review the mapping table. The plugin will automatically match common columns. For any custom fields, simply select which CSV column belongs to which HivePress attribute using the dropdown menus.
3. **Step 3: Run the Import & Monitor Progress**
   Click **"Start Import Process Now"**. Watch the live progress bar track each row in real-time. When finished, you'll see a complete summary of created listings, updated listings, and any specific row warnings.

== Installation ==

1. Upload the `listonix-listing-importer-for-hivepress` folder to your `/wp-content/plugins/` directory, or install the plugin ZIP file via **Plugins > Add New > Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Ensure the core **HivePress** plugin is installed and activated.
4. Navigate to **Listings > Import Listings** in your WordPress admin sidebar to start importing or exporting.

== Frequently Asked Questions ==

= Does this plugin connect to any third-party services or APIs? =
No. This plugin does not connect to any external tracking services, third-party SaaS platforms, or proprietary APIs. The only outbound HTTP requests made are WordPress core `download_url()` calls to retrieve media files when image URLs are explicitly included by an administrator in their uploaded CSV spreadsheet.

= Does this plugin require HivePress? =
Yes. This plugin is an extension purpose-built for the HivePress directory framework and requires the core HivePress plugin to be active.

= How should multiple image URLs be formatted in my CSV? =
Separate image URLs with commas inside the image column, like this:
`https://example.com/photo1.jpg, https://example.com/photo2.webp, https://example.com/photo3.png`
The plugin will download each image, attach the first one as the listing's featured image, and attach the rest to the HivePress image gallery.

= Can I import images from Unsplash, Google Places, or AWS S3 without file extensions? =
Yes! The plugin inspects the binary content of the file and assigns the correct extension (`.jpg`, `.png`, `.webp`, `.avif`) automatically.

= Will re-importing duplicate my images? =
No. The plugin stores a checksum hash of each imported image source URL. If you re-import or update listings with the same image URLs, it reuses the existing file in your WordPress Media Library.

= Can I import custom attributes I created in HivePress? =
Yes. All custom attributes you have created under **Listings > Attributes** (text fields, numbers, select dropdowns, checkboxes, dates, URLs, etc.) will automatically appear in the column mapping step.

= Can I update existing listings instead of creating duplicates? =
Yes. In Step 1, select the **"Update existing listings only"** or **"Update existing or create new"** mode. Ensure your CSV has an `ID` column containing the WordPress Post ID of the listing you wish to update.

= What happens if an import gets interrupted? =
Because the import runs in small chunks via AJAX and saves progress after every single row, you can simply click the **"Retry / Resume Import"** button to continue from where it paused without re-importing earlier rows.

= Can I export my listings back to CSV? =
Yes. Click the **"Export Listings"** tab at the top of the page. You can filter by category or export all listings. The generated file includes all core fields, custom attributes, categories, and image URLs, with UTF-8 BOM encoding so it opens cleanly in Microsoft Excel.

== Screenshots ==

1. **Upload Wizard (Step 1)**: Upload CSV, select delimiter, and choose your import mode.
2. **Column Mapping (Step 2)**: Visual dropdown interface matching CSV columns to HivePress attributes.
3. **Live Progress Dashboard (Step 3)**: Real-time chunked processing bar with live stats for created, updated, and failed rows.
4. **CSV Export Utility**: One-click listing export filtered by category with custom delimiter selection.

== Changelog ==

= 1.0.2 =
* Renamed to Listonix: Listing Importer for HivePress to ensure distinctiveness and full compliance with WordPress.org guidelines.
* Refactored internal prefixes and transient storage to prevent naming collisions.
* Refined documentation and descriptions in compliance with WordPress repository guidelines.

= 1.0.1 =
* Fully standardized for the WordPress.org Plugin Directory.
* Added chunked AJAX background processing with resume and crash recovery.
* Added intelligent binary MIME sniffing for dynamic and CDN image URLs.
* Added cryptographic image deduplication to prevent media library clutter.
* Added CSV Formula Injection (CWE-1236) and SSRF security defenses.
* Added built-in CSV export tool with Excel UTF-8 BOM encoding.

= 1.0.0 =
* Initial development version.
