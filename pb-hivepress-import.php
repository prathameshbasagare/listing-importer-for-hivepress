<?php
/**
 * Plugin Name: HivePress Import by PB
 * Plugin URI: https://github.com/
 * Description: High-performance admin importer for HivePress with smart MIME-type detection and auto-extension resolution for extensionless and CDN image URLs.
 * Version: 1.0.1
 * Author: PB
 * Text Domain: pb-hivepress-import
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package HivePress_Import_PB
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Autoload plugin classes.
spl_autoload_register( function( $class ) {
	$prefix = 'HivePress_Import_PB\\';
	$base_dir = __DIR__ . '/includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file = $base_dir . 'class-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';

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
				esc_html__( 'HivePress Import by PB requires the HivePress plugin to be active.', 'pb-hivepress-import' ) .
				'</p></div>';
		} );
		return;
	}

	// Initialize Admin Wizard.
	if ( is_admin() ) {
		HivePress_Import_PB\Admin_Page::init();
	}
} );
