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

		// Expose attributes the edit form omits because they are not marked
		// "editable" (e.g. email, google_place_id). They are still registered
		// on the model and can be written through fill()/save().
		$listing->set_id( null );

		foreach ( $listing->_get_fields() as $name => $field ) {
			if ( isset( $fields[ $name ] ) || ! $field->get_label() ) {
				continue;
			}

			$fields[ $name ] = [
				'label'       => $field->get_label(),
				'description' => $field->get_description(),
				'required'    => false,
				'field_obj'   => $field,
				'_order'      => 110,
			];
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

		$columns = fgetcsv( $file, 0, $delimiter, '"', '\\' );
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
	 * Count the data rows (excluding the header) in a CSV file.
	 *
	 * @param string $file_path
	 * @param string $delimiter
	 * @return int
	 */
	public static function count_rows( $file_path, $delimiter = ',' ) {
		$file = is_readable( $file_path ) ? fopen( $file_path, 'r' ) : false;
		if ( ! $file ) {
			return 0;
		}

		$count = -1; // Header row.
		while ( false !== fgetcsv( $file, 0, $delimiter, '"', '\\' ) ) {
			$count++;
		}

		fclose( $file );

		return max( 0, $count );
	}

	/**
	 * Run the import process for a given CSV file in a single request.
	 *
	 * Used when JavaScript is unavailable; the admin UI normally imports in
	 * chunks via process_chunk().
	 *
	 * @param array $args Import arguments:
	 *                    - file_path: string
	 *                    - delimiter: string
	 *                    - mode: 'create'|'update'|'append'
	 *                    - category_id: int
	 *                    - mappings: array [ field_key => column_index_or_none ]
	 * @return array { success: bool, message?: string, stats?: array }
	 */
	public static function process( $args ) {
		$result = self::process_chunk( array_merge( $args, [
			'offset'     => 0,
			'row_index'  => 1,
			'chunk_size' => PHP_INT_MAX,
		] ) );

		if ( empty( $result['success'] ) ) {
			return [
				'success' => false,
				'message' => $result['message'],
			];
		}

		return [
			'success' => true,
			'stats'   => [
				'total_rows' => $result['processed'],
				'created'    => $result['created'],
				'updated'    => $result['updated'],
				'failed'     => $result['failed'],
				'errors'     => $result['errors'],
			],
		];
	}

	/**
	 * Import a single CSV row as a listing.
	 *
	 * Maps CSV values onto a Listing model using HivePress `fill()` (magic
	 * `set_<field>()`), which writes to the correct post fields, term
	 * relationships and `hp_*` meta keys for custom attributes.
	 *
	 * @param array $row CSV row values.
	 * @param array $field_to_col Map of field_key => column_index.
	 * @param array $mappable_fields Field definitions from get_mappable_fields().
	 * @param string $mode Import mode ('create', 'update', 'append').
	 * @param int $category_id Optional blanket category ID.
	 * @return array { result: 'created'|'updated'|'failed', errors: string[] }
	 */
	private static function import_row( $row, $field_to_col, $mappable_fields, $mode, $category_id ) {
		$listing_errors = [];

		// Map row values into a field array, converting select option labels to values.
		$field_values = [];
		foreach ( $field_to_col as $field_key => $col_idx ) {
			if ( ! isset( $row[ $col_idx ] ) ) {
				continue;
			}

			$val       = trim( $row[ $col_idx ] );
			$field_obj = isset( $mappable_fields[ $field_key ]['field_obj'] ) ? $mappable_fields[ $field_key ]['field_obj'] : null;

			if ( $field_obj && is_array( $field_obj->get_arg( 'options' ) ) ) {
				$matched_options = [];
				$labels          = array_filter( array_map( 'trim', explode( ',', $val ) ) );

				foreach ( $field_obj->get_arg( 'options' ) as $opt_name => $opt_label ) {
					$opt_label_text = is_array( $opt_label ) && isset( $opt_label['label'] ) ? $opt_label['label'] : (string) $opt_label;
					$opt_label_text = html_entity_decode( $opt_label_text );

					if ( in_array( $opt_label_text, $labels, true ) || in_array( (string) $opt_name, $labels, true ) ) {
						$matched_options[] = $opt_name;
					}
				}

				// Select fields expose "multiple" as an arg; is_multiple() only exists on upload fields.
				if ( $field_obj->get_arg( 'multiple' ) ) {
					$field_values[ $field_key ] = $matched_options;
				} elseif ( ! empty( $matched_options ) ) {
					$field_values[ $field_key ] = $matched_options[0];
				}

				continue;
			}

			$field_values[ $field_key ] = $val;
		}

		// Validate mandatory fields.
		if ( empty( $field_values['title'] ) && 'update' !== $mode ) {
			return [
				'result' => 'failed',
				'errors' => [ __( 'Missing Title for new listing.', 'pb-hivepress-import' ) ],
			];
		}

		// Resolve author/user.
		$user_id = get_current_user_id();
		if ( ! empty( $field_values['user'] ) ) {
			$user = is_email( $field_values['user'] )
				? get_user_by( 'email', $field_values['user'] )
				: get_user_by( 'login', $field_values['user'] );

			if ( $user ) {
				$user_id = $user->ID;
			}
		}

		// Fall back to an administrator so rows never fail on the required
		// author field (e.g. when running via WP-CLI or a cron context).
		if ( empty( $user_id ) ) {
			$admins = get_users( [
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			] );
			if ( ! empty( $admins ) ) {
				$user_id = (int) $admins[0];
			}
		}

		// Handle Listing Lookup (Update vs Create).
		$listing    = null;
		$listing_id = ! empty( $field_values['id'] ) ? absint( $field_values['id'] ) : 0;

		if ( $listing_id ) {
			$listing = Models\Listing::query()->get_by_id( $listing_id );
		}

		if ( 'update' === $mode && ! $listing ) {
			return [
				'result' => 'failed',
				'errors' => [ sprintf(
					/* translators: %d: listing ID */
					__( 'Listing ID %d not found for update.', 'pb-hivepress-import' ),
					$listing_id
				) ],
			];
		}

		$is_new = false;
		if ( ! $listing ) {
			$is_new  = true;
			$listing = new Models\Listing();

			if ( $category_id ) {
				$listing->set_categories( [ $category_id ] );
			}

			// Load attribute fields onto the new (unsaved) model. Custom
			// attributes are only attached to the model inside set_id(), so
			// without this call fill()/set_*() on attributes is a no-op.
			$listing->set_id( null );
		}

		// Resolve category names/slugs/IDs from CSV to term IDs.
		$cat_ids = [];
		if ( $category_id ) {
			$cat_ids[] = $category_id;
		}
		if ( ! empty( $field_values['categories'] ) ) {
			$cat_parts = array_filter( array_map( 'trim', explode( ',', (string) $field_values['categories'] ) ) );
			foreach ( $cat_parts as $part ) {
				if ( is_numeric( $part ) ) {
					$cat_ids[] = absint( $part );
					continue;
				}
				$term = get_term_by( 'name', $part, 'hp_listing_category' );
				if ( ! $term ) {
					$term = get_term_by( 'slug', $part, 'hp_listing_category' );
				}
				if ( $term ) {
					$cat_ids[] = $term->term_id;
				}
			}
		}
		$field_values['categories'] = array_values( array_unique( $cat_ids ) );

		// Extract remote images (handled separately after save).
		$image_urls = ! empty( $field_values['images'] ) ? $field_values['images'] : '';
		unset( $field_values['images'] );

		unset( $field_values['id'] );

		// HivePress enforces a required description; fall back to title for new listings.
		if ( $is_new && empty( $field_values['description'] ) ) {
			$field_values['description'] = ! empty( $field_values['title'] )
				? sanitize_text_field( $field_values['title'] )
				: __( 'No description provided.', 'pb-hivepress-import' );
		}

		// Normalize boolean flags.
		foreach ( [ 'featured', 'verified' ] as $flag_key ) {
			if ( isset( $field_values[ $flag_key ] ) ) {
				$field_values[ $flag_key ] = in_array( strtolower( (string) $field_values[ $flag_key ] ), [ '1', 'true', 'yes' ], true );
			}
		}

		// Publish new listings.
		if ( $is_new ) {
			$field_values['status'] = 'publish';
		}

		// Ensure author is set.
		$field_values['user'] = $user_id;

		// Fill the model (magic set_<field>() → correct fields, terms and meta).
		$listing->fill( $field_values );

		$save_names = array_unique( array_merge(
			array_keys( $field_values ),
			[ 'title', 'description', 'user', 'status', 'categories', 'featured', 'verified', 'image', 'images' ]
		) );

		if ( ! $listing->save( $save_names ) ) {
			$errs = $listing->_get_errors();
			return [
				'result' => 'failed',
				'errors' => [ ! empty( $errs ) ? implode( '; ', $errs ) : __( 'Failed to save listing record.', 'pb-hivepress-import' ) ],
			];
		}

		// Process images with smart MIME sideloading.
		if ( ! empty( $image_urls ) ) {
			$img_urls                  = array_filter( array_map( 'trim', explode( ',', $image_urls ) ) );
			$downloaded_attachment_ids = [];

			foreach ( $img_urls as $img_idx => $img_url ) {
				$att_id = Image_Handler::sideload_image( $img_url, $listing->get_id(), 'images' );

				if ( is_wp_error( $att_id ) ) {
					$listing_errors[] = sprintf(
						/* translators: 1: url, 2: error message */
						__( 'Image error for "%1$s": %2$s', 'pb-hivepress-import' ),
						esc_url( $img_url ),
						$att_id->get_error_message()
					);
				} else {
					$downloaded_attachment_ids[] = $att_id;

					// Link first image as Featured / Thumbnail.
					if ( 0 === $img_idx ) {
						set_post_thumbnail( $listing->get_id(), $att_id );
						$listing->set_image( $att_id );
					}
				}
			}

			if ( ! empty( $downloaded_attachment_ids ) ) {
				$existing_images = (array) $listing->get_images__id();
				$all_images      = array_unique( array_merge( $existing_images, $downloaded_attachment_ids ) );
				$listing->set_images( $all_images );
				$listing->save( [ 'image', 'images' ] );

				// Flush HivePress attachment cache for this listing.
				hivepress()->cache->delete_post_cache( $listing->get_id(), 'image_ids', 'models/attachment' );
			}
		}

		return [
			'result' => $is_new ? 'created' : 'updated',
			'errors' => $listing_errors,
		];
	}

	/**
	 * Import a chunk of rows, resuming from a byte offset in the CSV file.
	 *
	 * Resuming by offset (rather than by row number) avoids re-reading the file
	 * from the start on every request and handles quoted multi-line fields.
	 *
	 * @param array $args Chunk processing arguments:
	 *                    - file_path: string
	 *                    - delimiter: string
	 *                    - mode: 'create'|'update'|'append'
	 *                    - category_id: int
	 *                    - mappings: array [ field_key => column_index_or_none ]
	 *                    - offset: int Byte offset of the next row (0 = start of file, header is skipped).
	 *                    - row_index: int CSV row number of the last processed row (header = 1).
	 *                    - chunk_size: int Maximum number of rows to process.
	 *                    - skip_first: bool Skip the next row and report it as failed (it crashed a previous request).
	 *                    - on_progress: callable Called with [ 'type' => 'start' ] before each row and
	 *                      [ 'type' => 'row', 'result', 'errors' ] after it, both with 'offset' and 'row_index'.
	 * @return array { success, message, offset, row_index, processed, created, updated, failed, errors, done }
	 */
	public static function process_chunk( $args ) {
		$file_path    = isset( $args['file_path'] ) ? $args['file_path'] : '';
		$delimiter    = ! empty( $args['delimiter'] ) ? $args['delimiter'] : ',';
		$mode         = ! empty( $args['mode'] ) ? $args['mode'] : 'create';
		$category_id  = ! empty( $args['category_id'] ) ? absint( $args['category_id'] ) : 0;
		$raw_mappings = ! empty( $args['mappings'] ) ? $args['mappings'] : [];
		$offset       = ! empty( $args['offset'] ) ? absint( $args['offset'] ) : 0;
		$row_index    = ! empty( $args['row_index'] ) ? absint( $args['row_index'] ) : 1;
		$chunk_size   = ! empty( $args['chunk_size'] ) ? (int) $args['chunk_size'] : 10;
		$on_progress  = isset( $args['on_progress'] ) && is_callable( $args['on_progress'] ) ? $args['on_progress'] : null;

		$result = [
			'success'   => false,
			'message'   => '',
			'offset'    => $offset,
			'row_index' => $row_index,
			'processed' => 0,
			'created'   => 0,
			'updated'   => 0,
			'failed'    => 0,
			'errors'    => [],
			'done'      => false,
		];

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			$result['message'] = __( 'CSV file could not be found or read.', 'pb-hivepress-import' );
			return $result;
		}

		$file = fopen( $file_path, 'r' );
		if ( ! $file ) {
			$result['message'] = __( 'Failed to open CSV file stream.', 'pb-hivepress-import' );
			return $result;
		}

		if ( $offset ) {
			fseek( $file, $offset );
		} elseif ( ! fgetcsv( $file, 0, $delimiter, '"', '\\' ) ) {
			fclose( $file );
			$result['message'] = __( 'CSV file header is empty or invalid.', 'pb-hivepress-import' );
			return $result;
		}

		// Active mappings: field_key => column_index
		$field_to_col = [];
		foreach ( $raw_mappings as $field_key => $col_idx ) {
			if ( is_numeric( $col_idx ) && (int) $col_idx >= 0 ) {
				$field_to_col[ sanitize_key( $field_key ) ] = (int) $col_idx;
			}
		}

		$mappable_fields = self::get_mappable_fields( $category_id, $mode );
		$skip_first      = ! empty( $args['skip_first'] );

		while ( $result['processed'] < $chunk_size ) {
			if ( $on_progress ) {
				call_user_func( $on_progress, [
					'type'      => 'start',
					'offset'    => ftell( $file ),
					'row_index' => $row_index,
				] );
			}

			$row = fgetcsv( $file, 0, $delimiter, '"', '\\' );
			if ( false === $row ) {
				break;
			}

			$row_index++;
			$result['processed']++;

			if ( $skip_first ) {
				// A previous request stopped while importing this row; don't retry it forever.
				$skip_first = false;
				$row_result = [
					'result' => 'failed',
					'errors' => [ __( 'Skipped: the server stopped while importing this row (timeout or fatal error).', 'pb-hivepress-import' ) ],
				];
			} elseif ( empty( array_filter( $row ) ) ) {
				// Skip completely empty lines
				$row_result = [
					'result' => 'skipped',
					'errors' => [],
				];
			} else {
				$row_result = self::import_row( $row, $field_to_col, $mappable_fields, $mode, $category_id );
			}

			if ( ! empty( $row_result['errors'] ) ) {
				$result['errors'][ $row_index ] = $row_result['errors'];
			}

			if ( 'failed' === $row_result['result'] ) {
				$result['failed']++;
			} elseif ( 'created' === $row_result['result'] ) {
				$result['created']++;
			} elseif ( 'updated' === $row_result['result'] ) {
				$result['updated']++;
			}

			if ( $on_progress ) {
				call_user_func( $on_progress, [
					'type'      => 'row',
					'offset'    => ftell( $file ),
					'row_index' => $row_index,
					'result'    => $row_result['result'],
					'errors'    => $row_result['errors'],
				] );
			}
		}

		$result['offset']    = ftell( $file );
		$result['row_index'] = $row_index;
		$result['done']      = false === fgetcsv( $file, 0, $delimiter, '"', '\\' );
		$result['success']   = true;

		fclose( $file );

		return $result;
	}
}
