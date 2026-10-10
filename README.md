# PB HivePress Import

A fast, admin-centric listings importer and exporter for **HivePress** with intelligent binary MIME-type inspection and automatic file extension resolution for extensionless and CDN image URLs.

## Features
- **Admin-Only Workflow**: Located in WordPress Admin at `Listings > Import Listings`.
- **Smart Image Importer**:
  - Automatically inspects the binary content (`image/jpeg`, `image/png`, `image/webp`, etc.) of extensionless and CDN URLs (e.g., Unsplash, Google Street View, AWS S3).
  - Automatically resolves and appends the correct extension.
  - Sideloads into the WordPress Media Library and links the primary image as the Featured Image and secondary images to the HivePress Gallery.
- **Outscraper & Complex CSV Compatibility**:
  - Automatically parses dot-notated/nested fields (e.g., `company_insights.founded_year` $\to$ `Company Insights → Founded Year`).
  - Auto-matches columns intelligently.
  - Fallback logic for mandatory HivePress description requirement.
- **Export Utility**: Export listings back to CSV filtered by category with custom delimiters.
