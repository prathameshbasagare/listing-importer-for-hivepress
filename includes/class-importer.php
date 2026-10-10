<?php
/**
 * Importer Engine.
 *
 * @package HivePress_Import_PB
 */

namespace HivePress_Import_PB;

use HivePress\Models;
use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Importer
 *
 * Parses CSV files, maps columns to HivePress listings and attributes,
 * processes remote images with smart MIME sniffing, and creates/updates listings.
 */
class Importer {

	/**
	 * Convert dot-notated or underscored slugs into clean, human-readable labels.
	 * E.g., 'company_insights.founded_year' -> 'Company Insights → Founded Year'
	 *
	 * @param string $slug
	 * @return string
	 */
	public static function format_column_label( $slug ) {
		$slug = trim( $slug );
		if ( empty( $slug ) ) {
			return '';
		}

		// Handle dot notation (e.g., nested JSON/CSV fields like company_insights.founded_year)
		if ( strpos( $slug, '.' ) !== false ) {
			$segments = explode( '.', $slug );
			$readable_segments = array_map( function( $seg ) {
				return ucwords( str_replace( [ '_', '-' ], ' ', $seg ) );
			}, $segments );
			return implode( ' → ', $readable_segments );
		}

		// Standard underscore or dash separation
		return ucwords( str_replace( [ '_', '-' ], ' ', $slug ) );
	}

	/**
	 * Get all available HivePress listing fields and custom attributes.
	 *
	 * @param int $category_id Optional category ID for category-specific attributes.
	 * @param string $mode Import mode ('create', 'update', 'append').
	 * @return array Array of field definitions.
	 */
	public static function get_mappable_fields( $category_id = 0, $mode = 'create' ) {
		$fields = [];

		// Listing ID for updates
		if ( 'create' !== $mode ) {
			$fields['id'] = [
				'label'       => __( 'Listing ID', 'pb-hivepress-import' ),
				'description' => __( 'Select the CSV column containing the Listing ID for updating existing records.', 'pb-hivepress-import' ),
				'required'    => true,
				'_order'      => 1,
			];
		}

		// Title
		$fields['title'] = [
			'label'       => __( 'Title', 'hivepress' ),
			'description' => __( 'The main title or name of the listing.', 'hivepress' ),
			'required'    => ( 'update' !== $mode ),
			'_order'      => 10,
		];

		// Category (if not pre-selected blanket category in Step 1)
		if ( ! $category_id ) {
			$fields['categories'] = [
				'label'       => __( 'Categories', 'hivepress' ),
				'description' => __( 'Category names, slugs, or IDs (comma-separated for multiple).', 'pb-hivepress-import' ),
				'required'    => false,
				'_order'      => 20,
			];
		}

		// Images
		$fields['images'] = [
			'label'       => __( 'Images', 'hivepress' ),
			'description' => __( 'Image URLs (comma-separated). Supports extensionless CDN/Unsplash URLs with automatic format detection.', 'pb-hivepress-import' ),
			'required'    => false,
			'_order'      => 30,
		];

		// Description
		$fields['description'] = [
			'label'       => __( 'Description', 'hivepress' ),
			'description' => __( 'The full text content or description of the listing.', 'hivepress' ),
			'required'    => true,
			'_order'      => 40,
		];

		// Author / User
		$fields['user'] = [
			'label'       => __( 'Author / User', 'pb-hivepress-import' ),
			'description' => __( 'Username or email address. Defaults to current admin if empty.', 'pb-hivepress-import' ),
			'required'    => false,
			'_order'      => 50,
		];

		// Featured & Verified
		$fields['featured'] = [
			'label'       => __( 'Featured', 'hivepress' ),
			'description' => __( 'Accepts "1", "true", "yes", or empty.', 'pb-hivepress-import' ),
			'required'    => false,
			'_order'      => 60,
		];
		$fields['verified'] = [
			'label'       => __( 'Verified', 'hivepress' ),
			'description' => __( 'Accepts "1", "true", "yes", or empty.', 'pb-hivepress-import' ),
			'required'    => false,
			'_order'      => 70,
		];

		// Instantiate listing model to fetch any custom attributes defined in HivePress Attributes
		$listing = new Models\Listing();
		if ( $category_id ) {
			$listing->set_categories( [ $category_id ] );
		}

		if ( class_exists( '\HivePress\Forms\Listing_Update' ) ) {
			$form_fields = ( new \HivePress\Forms\Listing_Update( [
				'model' => $listing,
			] ) )->get_fields();

			foreach ( $form_fields as $name => $field ) {
				if ( ! isset( $fields[ $name ] ) && $field->get_label() ) {
					$fields[ $name ] = [
						'label'       => $field->get_label(),
						'description' => $field->get_description(),
						'required'    => $field->is_required(),
						'field_obj'   => $field,
						'_order'      => 100,
					];
				}
			}
		}

		return $fields;
	}

	/**
	 * Read CSV header row columns.
	 *
	 * @param string $filepath
	 * @param string $delimiter
	 * @return array
	 */
	public static function get_csv_columns( $filepath, $delimiter = ',' ) {
		if ( ! file_exists( $filepath ) || ! is_readable( $filepath ) ) {
			return [];
		}

		$file = fopen( $filepath, 'r' );
		if ( ! $file ) {
			return [];
		}

		$columns = fgetcsv( $file, 0, $delimiter );
		fclose( $file );

		if ( ! is_array( $columns ) ) {
			return [];
		}

		// Clean BOM or invisible characters from column headers
		return array_values( array_filter( array_map( function( $col ) {
			$col = preg_replace( '/[\x00-\x1F\x80-\xFF]/', '', trim( $col ) );
			return trim( $col );
		}, $columns ) ) );
	}

	/**
	 * Run the import process for a given CSV file.
	 *
	 * @param array $args Import arguments:
	 *                    - file_path: string
	 *                    - delimiter: string
	 *                    - mode: 'create'|'update'|'append'
	 *                    - category_id: int
	 *                    - mappings: array [ field_key => column_index_or_none ]
	 * @return array Result stats: count, errors, updated, created
	 */
	public static function process( $args ) {
		$file_path   = isset( $args['file_path'] ) ? $args['file_path'] : '';
		$delimiter   = ! empty( $args['delimiter'] ) ? $args['delimiter'] : ',';
		$mode        = ! empty( $args['mode'] ) ? $args['mode'] : 'create';
		$category_id = ! empty( $args['category_id'] ) ? absint( $args['category_id'] ) : 0;
		$raw_mappings = ! empty( $args['mappings'] ) ? $args['mappings'] : [];

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return [
				'success' => false,
				'message' => __( 'CSV file could not be found or read.', 'pb-hivepress-import' ),
			];
		}

		$file = fopen( $file_path, 'r' );
		if ( ! $file ) {
			return [
				'success' => false,
				'message' => __( 'Failed to open CSV file stream.', 'pb-hivepress-import' ),
			];
		}

		// Read header
		$header = fgetcsv( $file, 0, $delimiter );
		if ( ! $header ) {
			fclose( $file );
			return [
				'success' => false,
				'message' => __( 'CSV file header is empty or invalid.', 'pb-hivepress-import' ),
			];
		}

		// Active mappings: field_key => column_index
		$field_to_col = [];
		foreach ( $raw_mappings as $field_key => $col_idx ) {
			if ( is_numeric( $col_idx ) && (int) $col_idx >= 0 ) {
				$field_to_col[ sanitize_key( $field_key ) ] = (int) $col_idx;
			}
		}

		$mappable_fields = self::get_mappable_fields( $category_id, $mode );
		$row_index       = 1;
		$stats = [
			'total_rows'   => 0,
			'created'      => 0,
			'updated'      => 0,
			'failed'       => 0,
			'errors'       => [],
		];

		// Iterate through rows
		while ( ( $row = fgetcsv( $file, 0, $delimiter ) ) !== false ) {
			$row_index++;
			$stats['total_rows']++;

			// Skip completely empty lines
			if ( empty( array_filter( $row ) ) ) {
				continue;
			}

			// Map row values into field array
			$field_values = [];
			foreach ( $field_to_col as $field_key => $col_idx ) {
				if ( isset( $row[ $col_idx ] ) ) {
					$field_values[ $field_key ] = trim( $row[ $col_idx ] );
				}
			}

			// Validate mandatory fields
			if ( empty( $field_values['title'] ) && 'update' !== $mode ) {
				$stats['failed']++;
				$stats['errors'][ $row_index ][] = __( 'Missing Title for new listing.', 'pb-hivepress-import' );
				continue;
			}

			// Resolve author/user
			$user_id = get_current_user_id();
			if ( ! empty( $field_values['user'] ) ) {
				$user = is_email( $field_values['user'] )
					? get_user_by( 'email', $field_values['user'] )
					: get_user_by( 'login', $field_values['user'] );

				if ( $user ) {
					$user_id = $user->ID;
				}
			}
			$field_values['user'] = $user_id;

			// Handle Listing Lookup (Update vs Create)
			$listing = null;
			$listing_id = ! empty( $field_values['id'] ) ? absint( $field_values['id'] ) : 0;

			if ( $listing_id ) {
				$listing = Models\Listing::query()->get_by_id( $listing_id );
			}

			if ( 'update' === $mode && ! $listing ) {
				$stats['failed']++;
				$stats['errors'][ $row_index ][] = sprintf(
					/* translators: %d: listing ID */
					__( 'Listing ID %d not found for update.', 'pb-hivepress-import' ),
					$listing_id
				);
				continue;
			}

			$is_new = false;
			if ( ! $listing ) {
				$is_new  = true;
				$listing = new Models\Listing();
				$listing->set_status( 'publish' );
			}

			// Categories
			$cat_ids = [];
			if ( $category_id ) {
				$cat_ids[] = $category_id;
			}
			if ( ! empty( $field_values['categories'] ) ) {
				$cat_parts = array_filter( array_map( 'trim', explode( ',', $field_values['categories'] ) ) );
				foreach ( $cat_parts as $part ) {
					if ( is_numeric( $part ) ) {
						$cat_ids[] = absint( $part );
					} else {
						$term = get_term_by( 'name', $part, 'hp_listing_category' );
						if ( ! $term ) {
							$term = get_term_by( 'slug', $part, 'hp_listing_category' );
						}
						if ( $term ) {
							$cat_ids[] = $term->term_id;
						}
					}
				}
			}
			if ( ! empty( $cat_ids ) ) {
				$listing->set_categories( array_unique( $cat_ids ) );
			}

			// Core standard attributes
			if ( isset( $field_values['title'] ) ) {
				$listing->set_title( sanitize_text_field( $field_values['title'] ) );
			}
			$description = isset( $field_values['description'] ) ? trim( $field_values['description'] ) : '';
			if ( empty( $description ) && $is_new ) {
				// HivePress Model enforces required description; fallback to Title or clean placeholder
				$description = ! empty( $field_values['title'] ) ? sanitize_text_field( $field_values['title'] ) : __( 'No description provided.', 'pb-hivepress-import' );
			}
			$listing->set_description( wp_kses_post( $description ) );
			if ( isset( $field_values['featured'] ) ) {
				$listing->set_featured( in_array( strtolower( (string) $field_values['featured'] ), [ '1', 'true', 'yes' ], true ) );
			}
			if ( isset( $field_values['verified'] ) ) {
				$listing->set_verified( in_array( strtolower( (string) $field_values['verified'] ), [ '1', 'true', 'yes' ], true ) );
			}
			$listing->set_user( $user_id );

			// Process Custom HivePress Attributes
			foreach ( $field_values as $key => $val ) {
				if ( in_array( $key, [ 'title', 'description', 'id', 'user', 'images', 'categories', 'featured', 'verified' ], true ) ) {
					continue;
				}

				if ( ! isset( $mappable_fields[ $key ] ) ) {
					continue;
				}

				$field_def = $mappable_fields[ $key ];
				$field_obj = isset( $field_def['field_obj'] ) ? $field_def['field_obj'] : null;

				if ( $field_obj && is_array( $field_obj->get_arg( 'options' ) ) ) {
					// Select / Multi-Select
					$matched_options = [];
					$labels = array_filter( array_map( 'trim', explode( ',', $val ) ) );
					foreach ( $field_obj->get_arg( 'options' ) as $opt_name => $opt_label ) {
						$opt_label_text = is_array( $opt_label ) && isset( $opt_label['label'] ) ? $opt_label['label'] : (string) $opt_label;
						if ( in_array( html_entity_decode( $opt_label_text ), $labels, true ) || in_array( (string) $opt_name, $labels, true ) ) {
							$matched_options[] = $opt_name;
						}
					}
					if ( $field_obj->is_multiple() ) {
						$listing->set_value( $key, $matched_options );
					} elseif ( ! empty( $matched_options ) ) {
						$listing->set_value( $key, $matched_options[0] );
					}
				} else {
					$listing->set_value( $key, sanitize_text_field( $val ) );
				}
			}

			// Save listing instance
			$save_fields = array_keys( $field_values );
			$save_fields = array_merge( $save_fields, [ 'title', 'description', 'user', 'status', 'categories', 'featured', 'verified' ] );

			if ( ! $listing->save( array_unique( $save_fields ) ) ) {
				$stats['failed']++;
				$errs = $listing->_get_errors();
				$stats['errors'][ $row_index ][] = ! empty( $errs ) ? implode( '; ', $errs ) : __( 'Failed to save listing record.', 'pb-hivepress-import' );
				continue;
			}

			// Process Images with Smart MIME Sideloading
			if ( ! empty( $field_values['images'] ) ) {
				$img_urls = array_filter( array_map( 'trim', explode( ',', $field_values['images'] ) ) );
				$downloaded_attachment_ids = [];

				foreach ( $img_urls as $img_idx => $img_url ) {
					$att_id = Image_Handler::sideload_image( $img_url, $listing->get_id(), 'images' );

					if ( is_wp_error( $att_id ) ) {
						$stats['errors'][ $row_index ][] = sprintf(
							/* translators: 1: url, 2: error message */
							__( 'Image error for "%1$s": %2$s', 'pb-hivepress-import' ),
							esc_url( $img_url ),
							$att_id->get_error_message()
						);
					} else {
						$downloaded_attachment_ids[] = $att_id;

						// Link first image as Featured / Thumbnail
						if ( 0 === $img_idx ) {
							set_post_thumbnail( $listing->get_id(), $att_id );
							$listing->set_image( $att_id );
						}
					}
				}

				if ( ! empty( $downloaded_attachment_ids ) ) {
					// Attach gallery images to listing model
					$existing_images = (array) $listing->get_images__id();
					$all_images      = array_unique( array_merge( $existing_images, $downloaded_attachment_ids ) );
					$listing->set_images( $all_images );
					$listing->save( [ 'image', 'images' ] );

					// Flush HivePress attachment cache for this listing
					hivepress()->cache->delete_post_cache( $listing->get_id(), 'image_ids', 'models/attachment' );
				}
			}

			if ( $is_new ) {
				$stats['created']++;
			} else {
				$stats['updated']++;
			}
		}

		fclose( $file );

		return [
			'success' => true,
			'stats'   => $stats,
		];
	}

	/**
	 * Process a chunk of rows for AJAX batch import.
	 *
	 * @param array $args Chunk processing arguments:
	 *                    - file_path: string
	 *                    - delimiter: string
	 *                    - mode: 'create'|'update'|'append'
	 *                    - category_id: int
	 *                    - mappings: array [ field_key => column_index_or_none ]
	 *                    - start_row: int (first row to process)
	 *                    - chunk_size: int (number of rows to process)
	 * @return array Chunk result: current_row, total_rows, created, updated, failed, errors
	 */
	public static function process_chunk( $args ) {
		$file_path   = isset( $args['file_path'] ) ? $args['file_path'] : '';
		$delimiter   = ! empty( $args['delimiter'] ) ? $args['delimiter'] : ',';
		$mode        = ! empty( $args['mode'] ) ? $args['mode'] : 'create';
		$category_id = ! empty( $args['category_id'] ) ? absint( $args['category_id'] ) : 0;
		$raw_mappings = ! empty( $args['mappings'] ) ? $args['mappings'] : [];
		$start_row   = ! empty( $args['start_row'] ) ? absint( $args['start_row'] ) : 1;
		$chunk_size  = ! empty( $args['chunk_size'] ) ? absint( $args['chunk_size'] ) : 10;

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return [
				'success' => false,
				'current_row'   => $start_row - 1,
				'total_rows'    => 0,
				'created'       => 0,
				'updated'       => 0,
				'failed'        => 0,
				'errors'        => [],
				'message'       => __( 'CSV file could not be found or read.', 'pb-hivepress-import' ),
			];
		}

		$file = fopen( $file_path, 'r' );
		if ( ! $file ) {
			return [
				'success' => false,
				'current_row'   => $start_row - 1,
				'total_rows'    => 0,
				'created'       => 0,
				'updated'       => 0,
				'failed'        => 0,
				'errors'        => [],
				'message'       => __( 'Failed to open CSV file stream.', 'pb-hivepress-import' ),
			];
		}

		// Read header
		$header = fgetcsv( $file, 0, $delimiter );
		if ( ! $header ) {
			fclose( $file );
			return [
				'success' => false,
				'current_row'   => $start_row - 1,
				'total_rows'    => 0,
				'created'       => 0,
				'updated'       => 0,
				'failed'        => 0,
				'errors'        => [],
				'message'       => __( 'CSV file header is empty or invalid.', 'pb-hivepress-import' ),
			];
		}

		// Active mappings: field_key => column_index
		$field_to_col = [];
		foreach ( $raw_mappings as $field_key => $col_idx ) {
			if ( is_numeric( $col_idx ) && (int) $col_idx >= 0 ) {
				$field_to_col[ sanitize_key( $field_key ) ] = (int) $col_idx;
			}
		}

		$mappable_fields = self::get_mappable_fields( $category_id, $mode );
		$row_index       = $start_row;
		$stats = [
			'total_rows'   => 0,
			'created'      => 0,
			'updated'      => 0,
			'failed'       => 0,
			'errors'       => [],
		];

		// Track progress within this chunk
		$chunk_start_row = $row_index;
		$rows_processed_in_chunk = 0;

		// Iterate through rows (limited to chunk_size)
		while ( ( $row = fgetcsv( $file, 0, $delimiter ) ) !== false && $rows_processed_in_chunk < $chunk_size ) {
			$row_index++;
			$stats['total_rows']++;

			// Skip completely empty lines
			if ( empty( array_filter( $row ) ) ) {
				continue;
			}

			// Map row values into field array
			$field_values = [];
			foreach ( $field_to_col as $field_key => $col_idx ) {
				if ( isset( $row[ $col_idx ] ) ) {
					$field_values[ $field_key ] = trim( $row[ $col_idx ] );
				}
			}

			// Validate mandatory fields
			if ( empty( $field_values['title'] ) && 'update' !== $mode ) {
				$stats['failed']++;
				$stats['errors'][ $row_index ][] = __( 'Missing Title for new listing.', 'pb-hivepress-import' );
				$rows_processed_in_chunk++;
				continue;
			}

			// Resolve author/user
			$user_id = get_current_user_id();
			if ( ! empty( $field_values['user'] ) ) {
				$user = is_email( $field_values['user'] )
					? get_user_by( 'email', $field_values['user'] )
					: get_user_by( 'login', $field_values['user'] );

				if ( $user ) {
					$user_id = $user->ID;
				}
			}
			$field_values['user'] = $user_id;

			// Handle Listing Lookup (Update vs Create)
			$listing = null;
			$listing_id = ! empty( $field_values['id'] ) ? absint( $field_values['id'] ) : 0;

			if ( $listing_id ) {
				$listing = Models\Listing::query()->get_by_id( $listing_id );
			}

			if ( 'update' === $mode && ! $listing ) {
				$stats['failed']++;
				$stats['errors'][ $row_index ][] = sprintf(
					__( 'Listing ID %d not found for update.', 'pb-hivepress-import' ),
					$listing_id
				);
				$rows_processed_in_chunk++;
				continue;
			}

			$is_new = false;
			if ( ! $listing ) {
				$is_new  = true;
				$listing = new Models\Listing();
				$listing->set_status( 'publish' );
			}

			// Categories
			$cat_ids = [];
			if ( $category_id ) {
				$cat_ids[] = $category_id;
			}
			if ( ! empty( $field_values['categories'] ) ) {
				$cat_parts = array_filter( array_map( 'trim', explode( ',', $field_values['categories'] ) ) );
				foreach ( $cat_parts as $part ) {
					if ( is_numeric( $part ) ) {
						$cat_ids[] = absint( $part );
					} else {
						$term = get_term_by( 'name', $part, 'hp_listing_category' );
						if ( ! $term ) {
							$term = get_term_by( 'slug', $part, 'hp_listing_category' );
						}
						if ( $term ) {
							$cat_ids[] = $term->term_id;
						}
					}
				}
			}
			if ( ! empty( $cat_ids ) ) {
				$listing->set_categories( array_unique( $cat_ids ) );
			}

			// Core standard attributes
			if ( isset( $field_values['title'] ) ) {
				$listing->set_title( sanitize_text_field( $field_values['title'] ) );
			}
			$description = isset( $field_values['description'] ) ? trim( $field_values['description'] ) : '';
			if ( empty( $description ) && $is_new ) {
				$description = ! empty( $field_values['title'] ) ? sanitize_text_field( $field_values['title'] ) : __( 'No description provided.', 'pb-hivepress-import' );
			}
			$listing->set_description( wp_kses_post( $description ) );
			if ( isset( $field_values['featured'] ) ) {
				$listing->set_featured( in_array( strtolower( (string) $field_values['featured'] ), [ '1', 'true', 'yes' ], true ) );
			}
			if ( isset( $field_values['verified'] ) ) {
				$listing->set_verified( in_array( strtolower( (string) $field_values['verified'] ), [ '1', 'true', 'yes' ], true ) );
			}
			$listing->set_user( $user_id );

			// Process Custom HivePress Attributes
			foreach ( $field_values as $key => $val ) {
				if ( in_array( $key, [ 'title', 'description', 'id', 'user', 'images', 'categories', 'featured', 'verified' ], true ) ) {
					continue;
				}

				if ( ! isset( $mappable_fields[ $key ] ) ) {
					continue;
				}

				$field_def = $mappable_fields[ $key ];
				$field_obj = isset( $field_def['field_obj'] ) ? $field_def['field_obj'] : null;

				if ( $field_obj && is_array( $field_obj->get_arg( 'options' ) ) ) {
					// Select / Multi-Select
					$matched_options = [];
					$labels = array_filter( array_map( 'trim', explode( ',', $val ) ) );
					foreach ( $field_obj->get_arg( 'options' ) as $opt_name => $opt_label ) {
						$opt_label_text = is_array( $opt_label ) && isset( $opt_label['label'] ) ? $opt_label['label'] : (string) $opt_label;
						if ( in_array( html_entity_decode( $opt_label_text ), $labels, true ) || in_array( (string) $opt_name, $labels, true ) ) {
							$matched_options[] = $opt_name;
						}
					}
					if ( $field_obj->is_multiple() ) {
						$listing->set_value( $key, $matched_options );
					} elseif ( ! empty( $matched_options ) ) {
						$listing->set_value( $key, $matched_options[0] );
					}
				} else {
					$listing->set_value( $key, sanitize_text_field( $val ) );
				}
			}

			// Save listing instance
			$save_fields = array_keys( $field_values );
			$save_fields = array_merge( $save_fields, [ 'title', 'description', 'user', 'status', 'categories', 'featured', 'verified' ] );

			if ( ! $listing->save( array_unique( $save_fields ) ) ) {
				$stats['failed']++;
				$errs = $listing->_get_errors();
				$stats['errors'][ $row_index ][] = ! empty( $errs ) ? implode( '; ', $errs ) : __( 'Failed to save listing record.', 'pb-hivepress-import' );
				$rows_processed_in_chunk++;
				continue;
			}

			// Process Images with Smart MIME Sideloading
			if ( ! empty( $field_values['images'] ) ) {
				$img_urls = array_filter( array_map( 'trim', explode( ',', $field_values['images'] ) ) );
				$downloaded_attachment_ids = [];

				foreach ( $img_urls as $img_idx => $img_url ) {
					$att_id = Image_Handler::sideload_image( $img_url, $listing->get_id(), 'images' );

					if ( is_wp_error( $att_id ) ) {
						$stats['errors'][ $row_index ][] = sprintf(
							__( 'Image error for "%1$s": %2$s', 'pb-hivepress-import' ),
							esc_url( $img_url ),
							$att_id->get_error_message()
						);
					} else {
						$downloaded_attachment_ids[] = $att_id;

						// Link first image as Featured / Thumbnail
						if ( 0 === $img_idx ) {
							set_post_thumbnail( $listing->get_id(), $att_id );
							$listing->set_image( $att_id );
						}
					}
				}

				if ( ! empty( $downloaded_attachment_ids ) ) {
					// Attach gallery images to listing model
					$existing_images = (array) $listing->get_images__id();
					$all_images      = array_unique( array_merge( $existing_images, $downloaded_attachment_ids ) );
					$listing->set_images( $all_images );
					$listing->save( [ 'image', 'images' ] );

					// Flush HivePress attachment cache for this listing
					hivepress()->cache->delete_post_cache( $listing->get_id(), 'image_ids', 'models/attachment' );
				}
			}

			if ( $is_new ) {
				$stats['created']++;
			} else {
				$stats['updated']++;
			}

			$rows_processed_in_chunk++;
		}

		fclose( $file );

		return [
			'success' => true,
			'current_row'   => $row_index,
			'total_rows'    => $stats['total_rows'] ?? 0,
			'created'       => $stats['created'] ?? 0,
			'updated'       => $stats['updated'] ?? 0,
			'failed'        => $stats['failed'] ?? 0,
			'errors'        => $stats['errors'] ?? [],
			'message'       => '',
		];
	}
}
