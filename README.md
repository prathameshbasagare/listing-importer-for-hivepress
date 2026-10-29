# Listonix: Listing Importer for HivePress

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-green.svg)](https://php.net)

**Listonix: Listing Importer for HivePress** is a fast, reliable, admin-centric listings importer and exporter built specifically for [HivePress](https://hivepress.io). It allows you to import hundreds or thousands of listings from spreadsheets (CSV/TXT) smoothly without server timeouts, dynamic image issues, or duplicate media clutter.

---

## 🌟 Built for Reliability & Scale

If you run a directory, classifieds, or marketplace site on HivePress, adding listings manually one-by-one is impossible at scale.

This plugin provides key capabilities to handle imports reliably:

| Capability | How Listonix Works |
| :--- | :--- |
| **Prevents Server Timeouts** | **Asynchronous AJAX Batching**: Imports rows in small chunks in the background with a real-time progress bar. |
| **Automatic Resume & Recovery** | **Crash/Resume Recovery**: Saves its byte offset after every row. A single click on *"Retry / Resume"* continues right where it stopped. |
| **Supports Dynamic CDN Images** | **Smart Binary MIME Detection**: Reads binary file headers of dynamic/extensionless URLs (Unsplash, Google Places, AWS S3) and auto-resolves `.jpg`, `.png`, `.webp`, `.avif`. |
| **Prevents Media Duplication** | **Cryptographic Deduplication**: Re-uses existing media attachments based on source URL hashes instead of downloading duplicate files. |
| **Clean Header Handling** | **Auto-Label Conversion & Matching**: Automatically transforms dot-notated columns like `company_insights.founded_year` into readable labels and pre-matches them. |
| **Data Export** | **Built-in CSV Exporter**: Exports listings with categories, custom attributes, and image URLs formatted with Excel UTF-8 BOM encoding. |

---

## 🚀 Key Features

* **3-Step Visual Admin Wizard**: Accessible under `wp-admin > Listings > Import Listings`.
* **Flexible Modes**:
  * **Create New Listings**: Add new listings without modifying existing ones.
  * **Update Existing Listings**: Match by ID column to update records in bulk.
  * **Append / Upsert**: Update existing listings if ID exists; otherwise create new.
* **Full Custom Attribute Support**: Automatically loads every custom attribute defined in `Listings > Attributes` (text, dropdowns, checkboxes, dates, URLs, numbers).
* **Multi-Image Galleries**: Supports comma-separated image URLs in a single column. The first is set as Featured Thumbnail, and remaining images attach to the HivePress Gallery.
* **Category Auto-Assignment or Row Mapping**: Assign a single blanket category or map a CSV column containing category names, slugs, or IDs.
* **Security Hardened**: Protected against CSRF, SSRF, directory traversal, and CSV Formula Injection (CWE-1236).

---

## 📖 How It Works in 3 Steps

1. **Step 1: Upload Your File**: Select your CSV or TXT file, set your delimiter (usually `,`), and pick your import mode.
2. **Step 2: Map Your Columns**: Review the auto-detected columns and map any remaining custom fields via intuitive dropdowns.
3. **Step 3: Watch It Run**: The real-time progress bar tracks created, updated, and failed rows. Detailed error logs tell you exactly which rows had issues and why.

---

## 🛠 Installation

1. Upload the plugin ZIP file via **Plugins > Add New > Upload Plugin**, or place the `listonix-listing-importer-for-hivepress` folder in your `/wp-content/plugins/` directory.
2. Activate the plugin in **WordPress Admin > Plugins**.
3. Ensure the core **HivePress** plugin is active.
4. Navigate to **Listings > Import Listings** to start importing.

---

## 📄 License

This plugin is free software, licensed under the [GNU General Public License v3 or later (GPLv3+)](https://www.gnu.org/licenses/gpl-3.0.html).
