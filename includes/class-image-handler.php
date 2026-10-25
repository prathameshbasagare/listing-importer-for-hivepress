<?php
/**
 * Image Downloader & Sideload Handler.
 *
 * @package Listing_Importer_For_HivePress
 * @license GPL-3.0-or-later
 */

namespace Listing_Importer_For_HivePress;

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
		$url = esc_url_raw( trim( $url ) );

		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'invalid_url', esc_html__( 'Invalid or prohibited image URL provided.', 'listing-importer-for-hivepress' ) );
		}

		// Image deduplication: reuse an attachment previously imported from this URL.
		$existing_id = self::find_reusable_attachment( md5( $url ), $post_parent );

		if ( $existing_id ) {
			if ( $post_parent ) {
				wp_update_post( [
					'ID'          => $existing_id,
					'post_parent' => $post_parent,
				] );
				update_post_meta( $existing_id, 'hp_parent_model', 'listing' );
				update_post_meta( $existing_id, 'hp_parent_field', sanitize_key( $parent_field ) );
			}

			return $existing_id;
		}

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
					__( 'Unsupported image type "%1$s" for URL: %2$s', 'listing-importer-for-hivepress' ),
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
	 * Download a remote file for a listing attachment attribute, as HivePress Import does.
	 *
	 * Unlike sideload_image(), any format allowed by the field is accepted. An
	 * extensionless image URL still gets its extension from the detected MIME type.
	 *
	 * @param string   $url Remote file URL.
	 * @param int      $post_parent Listing ID.
	 * @param string   $parent_field HivePress field name.
	 * @param string[] $formats Allowed file extensions (empty for any allowed by WordPress).
	 * @return int|\WP_Error Attachment ID on success, or WP_Error on failure.
	 */
	public static function sideload_file( $url, $post_parent, $parent_field, $formats = [] ) {
		$url = esc_url_raw( trim( $url ) );

		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'invalid_url', esc_html__( 'Invalid or prohibited file URL provided.', 'listing-importer-for-hivepress' ) );
		}

		$format_error = sprintf(
			/* translators: %s: allowed file extensions. */
			__( 'Only %s files are allowed.', 'listing-importer-for-hivepress' ),
			strtoupper( implode( ', ', (array) $formats ) )
		);

		// Reuse an attachment previously imported from this URL, if it suits this field.
		$existing_id = self::find_reusable_attachment( md5( $url ), $post_parent );

		if ( $existing_id ) {
			$existing_path = get_attached_file( $existing_id );

			if ( ! $formats || ( $existing_path && hivepress()->attachment->is_valid_file( $existing_path, basename( $existing_path ), $formats ) ) ) {
				wp_update_post( [
					'ID'          => $existing_id,
					'post_parent' => $post_parent,
				] );
				update_post_meta( $existing_id, 'hp_parent_model', 'listing' );
				update_post_meta( $existing_id, 'hp_parent_field', sanitize_key( $parent_field ) );

				return $existing_id;
			}
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$tmp_file = download_url( $url );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$filename = sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );

		if ( ! pathinfo( $filename, PATHINFO_EXTENSION ) ) {
			$ext = self::get_extension_for_mime( self::detect_mime_type( $tmp_file ) );

			if ( $ext ) {
				$filename = self::generate_filename( $url, $ext );
			}
		}

		if ( ! $filename ) {
			@unlink( $tmp_file );
			return new \WP_Error( 'invalid_name', __( 'Invalid file name.', 'listing-importer-for-hivepress' ) );
		}

		if ( $formats && ! hivepress()->attachment->is_valid_file( $tmp_file, $filename, $formats ) ) {
			@unlink( $tmp_file );
			return new \WP_Error( 'invalid_format', $format_error );
		}

		$attachment_id = media_handle_sideload(
			[
				'name'     => $filename,
				'tmp_name' => $tmp_file,
			],
			$post_parent
		);

		if ( file_exists( $tmp_file ) ) {
			@unlink( $tmp_file );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		update_post_meta( $attachment_id, 'hp_parent_model', 'listing' );
		update_post_meta( $attachment_id, 'hp_parent_field', sanitize_key( $parent_field ) );
		update_post_meta( $attachment_id, '_hp_import_source_hash', md5( $url ) );

		return $attachment_id;
	}

	/**
	 * Find an existing imported attachment with the given source hash that can be
	 * attached to the given post.
	 *
	 * HivePress links gallery images one-to-many via post_parent, so an attachment
	 * still owned by another live listing is not reused (that would remove it from
	 * that listing). Attachments that are unattached, already on this post, or
	 * whose parent is not a live listing are reusable. The latter includes images
	 * of trashed listings and of deleted listings, which WordPress re-parents to
	 * the listing's own parent (the HivePress vendor).
	 *
	 * @param string $source_hash MD5 of the source URL.
	 * @param int    $post_parent Target post ID.
	 * @return int Attachment ID, or 0 if none is reusable.
	 */
	protected static function find_reusable_attachment( $source_hash, $post_parent ) {
		global $wpdb;

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
			WHERE pm.meta_key = '_hp_import_source_hash' AND pm.meta_value = %s
			ORDER BY pm.post_id ASC",
			$source_hash
		) );

		$fallback = 0;

		foreach ( $ids as $id ) {
			$parent_id = (int) wp_get_post_parent_id( (int) $id );

			if ( $post_parent && $parent_id === (int) $post_parent ) {
				return (int) $id;
			}

			$parent = $parent_id ? get_post( $parent_id ) : null;

			if ( ! $fallback && ( ! $parent || 'hp_listing' !== $parent->post_type || 'trash' === $parent->post_status ) ) {
				$fallback = (int) $id;
			}
		}

		return $fallback;
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
