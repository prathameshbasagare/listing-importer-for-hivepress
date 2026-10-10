<?php
/**
 * Image Downloader & Sideload Handler.
 *
 * @package HivePress_Import_PB
 */

namespace HivePress_Import_PB;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Image_Handler
 *
* Downloads remote images, reliably detects actual binary MIME types,
	 * generates appropriate file extensions for extensionless/CDN URLs,
	 * and sideloads them into the WordPress Media Library.
	 *
	 * Image deduplication: Before downloading, checks if an attachment
	 * with the same source URL hash already exists in the Media Library.
	 * If found, reuses the existing attachment ID to prevent duplicate files.
 */
class Image_Handler {

	/**
	 * Map of MIME types to extensions.
	 *
	 * @var array
	 */
	protected static $mime_to_ext = [
		'image/jpeg' => 'jpg',
		'image/jpg'  => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
		'image/bmp'  => 'bmp',
		'image/tiff' => 'tiff',
		'image/svg+xml' => 'svg',
	];

	/**
	 * Download a remote image, detect MIME type, ensure correct extension, and sideload to WP Media.
	 *
	 * @param string $url Remote image URL.
	 * @param int $post_parent Optional post ID to attach the media to.
	 * @param string $parent_field Field name in HivePress (default 'images').
	 * @return int|\WP_Error Attachment ID on success, or WP_Error on failure.
	 */
	public static function sideload_image( $url, $post_parent = 0, $parent_field = 'images' ) {
		$url = trim( $url );

		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_url', __( 'Invalid image URL provided.', 'pb-hivepress-import' ) );
		}

		// Image deduplication: check if attachment with this source URL hash already exists
		$source_hash = md5( $url );
		$existing_id = false;

		global $wpdb;
		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_hp_import_source_hash' AND meta_value = %s LIMIT 1",
			$source_hash
		) );

		// Ensure WordPress administration file dependencies are available.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Step 1: Download remote file to temporary directory.
		$tmp_file = download_url( $url );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		// Step 2: Determine actual MIME type by inspecting file contents.
		$mime_type = self::detect_mime_type( $tmp_file );

		// Step 3: Determine correct extension based on MIME type.
		$ext = self::get_extension_for_mime( $mime_type );

		if ( ! $ext ) {
			// Fallback: check if the URL path had an extension.
			$url_path = wp_parse_url( $url, PHP_URL_PATH );
			$url_ext  = strtolower( pathinfo( $url_path, PATHINFO_EXTENSION ) );

			if ( in_array( $url_ext, [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ], true ) ) {
				$ext = ( 'jpeg' === $url_ext ) ? 'jpg' : $url_ext;
			}
		}

		if ( ! $ext ) {
			@unlink( $tmp_file );
			return new \WP_Error(
				'unsupported_mime',
				sprintf(
					/* translators: 1: detected mime type, 2: url */
					__( 'Unsupported image type "%1$s" for URL: %2$s', 'pb-hivepress-import' ),
					$mime_type ? $mime_type : 'unknown',
					$url
				)
			);
		}

		// Step 4: Construct a clean, sanitized filename.
		$filename = self::generate_filename( $url, $ext );

		// Step 5: Perform the sideload into Media Library.
		$file_array = [
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		];

		// sideload media
		$attachment_id = media_handle_sideload( $file_array, $post_parent );

		// Delete temp file if still present
		if ( file_exists( $tmp_file ) ) {
			@unlink( $tmp_file );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Step 6: Mark metadata for HivePress compatibility.
		if ( $post_parent ) {
			update_post_meta( $attachment_id, 'hp_parent_model', 'listing' );
			update_post_meta( $attachment_id, 'hp_parent_field', sanitize_key( $parent_field ) );
		}

		// Step 7: Store source URL hash for deduplication
		update_post_meta( $attachment_id, '_hp_import_source_hash', md5( $url ) );

		return $attachment_id;
	}

	/**
	 * Detect binary MIME type using PHP's finfo or mime_content_type.
	 *
	 * @param string $filepath Path to temporary file.
	 * @return string MIME type string or empty string.
	 */
	public static function detect_mime_type( $filepath ) {
		$mime = '';

		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$mime = finfo_file( $finfo, $filepath );
				finfo_close( $finfo );
			}
		}

		if ( empty( $mime ) && function_exists( 'mime_content_type' ) ) {
			$mime = mime_content_type( $filepath );
		}

		if ( empty( $mime ) && function_exists( 'wp_check_filetype' ) ) {
			$check = wp_check_filetype( $filepath );
			$mime  = ! empty( $check['type'] ) ? $check['type'] : '';
		}

		return strtolower( trim( $mime ) );
	}

	/**
	 * Map a MIME type to its standard file extension.
	 *
	 * @param string $mime_type
	 * @return string|null
	 */
	public static function get_extension_for_mime( $mime_type ) {
		return isset( self::$mime_to_ext[ $mime_type ] ) ? self::$mime_to_ext[ $mime_type ] : null;
	}

	/**
	 * Generate a clean, descriptive filename preserving base name when possible.
	 *
	 * @param string $url Original URL.
	 * @param string $ext Extension (e.g. 'jpg', 'png').
	 * @return string
	 */
	protected static function generate_filename( $url, $ext ) {
		$url_path  = wp_parse_url( $url, PHP_URL_PATH );
		$base_name = sanitize_file_name( pathinfo( $url_path, PATHINFO_FILENAME ) );

		// If base name is empty, random hash, or purely query string
		if ( empty( $base_name ) || strlen( $base_name ) < 2 ) {
			$base_name = 'listing-image-' . wp_generate_password( 8, false, false );
		}

		// Strip any trailing dots or old mismatched extensions
		$base_name = preg_replace( '/\.(jpg|jpeg|png|webp|gif|avif)$/i', '', $base_name );

		return $base_name . '.' . $ext;
	}
}
