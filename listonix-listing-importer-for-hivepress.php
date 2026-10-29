<?php
/**
 * Plugin Name: Listonix: Listing Importer for HivePress
 * Plugin URI: https://wordpress.org/plugins/listonix-listing-importer-for-hivepress/
 * Description: Fast, batch-processed CSV importer and exporter for HivePress listings with intelligent MIME detection for CDN images and deduplication.
 * Version: 1.0.2
 * Author: curiouswpdev
 * Author URI: https://profiles.wordpress.org/curiouswpdev/
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: listonix-listing-importer-for-hivepress
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: hivepress
 *
 * @package Listonix_Listing_Importer_For_HivePress
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * Includes portions and field mapping structures derived from the
 * open-source HivePress ecosystem, licensed under GNU GPLv3.
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Autoload plugin classes.
spl_autoload_register( function( $class ) {
	$prefix   = 'Listonix_Listing_Importer_For_HivePress\\';
	$base_dir = __DIR__ . '/includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file           = $base_dir . 'class-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Initialize plugin.
 */
add_action( 'plugins_loaded', function() {
	// Verify HivePress is active.
	if ( ! function_exists( 'hivepress' ) ) {
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-warning is-dismissible"><p>' .
				esc_html__( 'Listonix: Listing Importer for HivePress requires the HivePress plugin to be active.', 'listonix-listing-importer-for-hivepress' ) .
				'</p></div>';
		} );
		return;
	}

	// Initialize Admin Wizard.
	if ( is_admin() ) {
		Listonix_Listing_Importer_For_HivePress\Admin_Page::init();
	}
} );
