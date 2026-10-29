<?php
/**
 * Importer Engine.
 *
 * @package CuriousWP_Listing_Importer_For_HivePress
 * @license GPL-3.0-or-later
 */

namespace CuriousWP_Listing_Importer_For_HivePress;

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
	 * Mirrors HivePress Import (`hivepress()->import->get_fields()`): the import
	 * fields are passed into the listing edit form, so their definitions are
	 * merged with the listing model, and HivePress adds every "Editable"
	 * attribute for the selected category (or all categories). Labels, required
	 * flags and order therefore come from HivePress itself.
	 *
	 * @param int $category_id Optional category ID for category-specific attributes.
	 * @param string $mode Import mode ('create', 'update', 'append').
	 * @return array Array of field definitions, keyed by field name.
	 */
	public static function get_mappable_fields( $category_id = 0, $mode = 'create' ) {
		$import_fields = [];
		$listing       = null;

		if ( 'create' !== $mode ) {

			// Add ID field.
			$import_fields['id'] = [
				'label'       => __( 'ID', 'curiouswp-listing-importer-for-hivepress' ),
				'description' => __( 'Select the ID column for updating existing listings.', 'curiouswp-listing-importer-for-hivepress' ),
				'type'        => 'id',
				'required'    => true,
				'_order'      => 1,
			];
		}

		if ( $category_id ) {

			// Load the category's attributes onto the model.
			$listing = ( new Models\Listing() )->set_categories( [ $category_id ] );
			$listing->set_id( null );
		} else {

			// Add category field.
			$import_fields['categories'] = [
				'required' => true,
				'_order'   => 5,
			];
		}

		if ( current_user_can( 'import' ) ) {

			// Add admin fields.
			$import_fields = array_merge(
				$import_fields,
				[
					'featured' => [
						'_order' => 1000,
					],

					'verified' => [
						'_order' => 1010,
					],

					'user'     => [
						'label'       => hivepress()->translator->get_string( 'user' ),
						'description' => __( 'Accepts usernames or email addresses.', 'curiouswp-listing-importer-for-hivepress' ),
						'required'    => false,
						'_order'      => 2,
					],
				]
			);
		}

		$form_fields = array_filter(
			( new \HivePress\Forms\Listing_Update(
				[
					'fields' => $import_fields,
					'model'  => $listing,
				]
			) )->get_fields(),
			function( $field ) {
				return $field->get_label();
			}
		);

		/**
		 * Filters extra listing attributes offered for mapping although they are
		 * not "Editable" (and so not on the listing edit form). Keeping them
		 * non-editable stops vendors from changing them on the front end.
		 *
		 * @param string[] $names Attribute field names.
		 */
		// $extra_names = (array) apply_filters( 'curiouswp_li_import_extra_fields', [ 'google_place_id', 'google_rating' ] );

		// if ( $extra_names ) {
		// 	$model_fields = ( new Models\Listing() )->set_id( null )->_get_fields();

		// 	foreach ( $extra_names as $name ) {
		// 		if ( ! isset( $form_fields[ $name ] ) && isset( $model_fields[ $name ] ) && $model_fields[ $name ]->get_label() ) {
		// 			$form_fields[ $name ] = $model_fields[ $name ];
		// 		}
		// 	}
		// }

		$fields = [];

		foreach ( $form_fields as $name => $field ) {
			$fields[ $name ] = [
				'label'       => $field->get_label(),
				'description' => self::get_field_description( $field ),
				'required'    => $field->is_required(),
				'field_obj'   => $field,
			];
		}

		return $fields;
	}

	/**
	 * Get the mapping hint shown under a field, as in HivePress Import.
	 *
	 * @param object $field Field object.
	 * @return string
	 */
	public static function get_field_description( $field ) {
		$field_type = $field::get_meta( 'name' );

		if ( in_array( $field->get_name(), [ 'id', 'user' ], true ) ) {
			return $field->get_description();
		}

		if ( 'categories' === $field->get_name() ) {

			// Categories are resolved by name, slug or ID rather than by option label.
			return __( 'Accepts category names, slugs or IDs (comma-separated).', 'curiouswp-listing-importer-for-hivepress' );
		}

		if ( is_array( $field->get_arg( 'options' ) ) ) {
			$field_labels = array_map(
				function( $option ) {
					return hp\get_array_value( $option, 'label', $option );
				},
				$field->get_arg( 'options' )
			);

			return sprintf(
				/* translators: %s: list of allowed option labels. */
				__( 'Accepts any of these values: %s.', 'curiouswp-listing-importer-for-hivepress' ),
				implode( ', ', $field_labels )
			);
		}

		switch ( $field_type ) {
			case 'attachment_upload':
				return sprintf(
					/* translators: %s: allowed file formats. */
					__( 'Accepts URLs of these file formats: %s.', 'curiouswp-listing-importer-for-hivepress' ),
					strtoupper( implode( ', ', $field->get_formats() ) )
				);

			case 'date':
				$field_format = 'Y-m-d';

				if ( $field->get_arg( 'format' ) ) {
					$field_format = $field->get_arg( 'format' );
				} elseif ( $field->get_arg( 'time' ) ) {
					$field_format .= ' H:i:s';
				}

				return sprintf(
					/* translators: %s: date format string. */
					__( 'Accepts dates in "%s" format.', 'curiouswp-listing-importer-for-hivepress' ),
					$field_format
				);

			case 'embed':
				return __( 'Accepts the embed URLs.', 'curiouswp-listing-importer-for-hivepress' );

			case 'checkbox':
				return sprintf(
					/* translators: %s: allowed value (e.g. 1). */
					__( 'Accepts "%s" or empty values.', 'curiouswp-listing-importer-for-hivepress' ),
					1
				);

			case 'time':
				return __( 'Accepts times in seconds.', 'curiouswp-listing-importer-for-hivepress' );

			case 'url':
				return __( 'Accepts URLs.', 'curiouswp-listing-importer-for-hivepress' );
		}

		return '';
	}

	/**
	 * Get labels of required fields that are not mapped to a CSV column.
	 *
	 * @param array $fields Field definitions from get_mappable_fields().
	 * @param array $mappings Map of field_key => column index or 'none'.
	 * @return string[]
	 */
	public static function get_unmapped_required_fields( $fields, $mappings ) {
		$labels = [];

		foreach ( $fields as $name => $field ) {
			$col_idx = isset( $mappings[ $name ] ) ? $mappings[ $name ] : 'none';

			if ( ! empty( $field['required'] ) && ! ( is_numeric( $col_idx ) && (int) $col_idx >= 0 ) ) {
				$labels[] = $field['label'];
			}
		}

		return $labels;
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream required for fgetcsv.
		$file = fopen( $filepath, 'r' );
		if ( ! $file ) {
			return [];
		}

		$columns = fgetcsv( $file, 0, $delimiter, '"', '\\' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing CSV file pointer.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing CSV file pointer.
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream required for line-by-line counting.
		$file = is_readable( $file_path ) ? fopen( $file_path, 'r' ) : false;
		if ( ! $file ) {
			return 0;
		}

		$count = -1; // Header row.
		while ( false !== fgetcsv( $file, 0, $delimiter, '"', '\\' ) ) {
			$count++;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing CSV file pointer.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing CSV file pointer.
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

			// Categories are resolved by name, slug or ID below.
			if ( $field_obj && 'categories' !== $field_key && is_array( $field_obj->get_arg( 'options' ) ) ) {
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
		if ( empty( $field_values['title'] ) ) {
			return [
				'result' => 'failed',
				'errors' => [ __( 'Missing Title.', 'curiouswp-listing-importer-for-hivepress' ) ],
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
					__( 'Listing ID %d not found for update.', 'curiouswp-listing-importer-for-hivepress' ),
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

		// Extract URLs for other file attributes (uploaded after save, like images).
		$file_urls = [];
		foreach ( $field_values as $field_key => $val ) {
			$field_obj = isset( $mappable_fields[ $field_key ]['field_obj'] ) ? $mappable_fields[ $field_key ]['field_obj'] : null;

			if ( $field_obj && 'attachment_upload' === $field_obj::get_meta( 'name' ) ) {
				$file_urls[ $field_key ] = array_filter( array_map( 'trim', explode( ',', (string) $val ) ) );
				unset( $field_values[ $field_key ] );
			}
		}

		unset( $field_values['id'] );

		// HivePress enforces a required description; fall back to title for new listings.
		if ( $is_new && empty( $field_values['description'] ) ) {
			$field_values['description'] = ! empty( $field_values['title'] )
				? sanitize_text_field( $field_values['title'] )
				: __( 'No description provided.', 'curiouswp-listing-importer-for-hivepress' );
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
				'errors' => [ ! empty( $errs ) ? implode( '; ', $errs ) : __( 'Failed to save listing record.', 'curiouswp-listing-importer-for-hivepress' ) ],
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
						__( 'Image error for "%1$s": %2$s', 'curiouswp-listing-importer-for-hivepress' ),
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

		// Upload files for other attachment attributes, as HivePress Import does:
		// multiple fields get all uploaded files, single ones the first valid file.
		$file_values = [];
		foreach ( $file_urls as $field_key => $urls ) {
			$field_obj = $mappable_fields[ $field_key ]['field_obj'];
			$file_ids  = [];

			foreach ( $urls as $file_url ) {
				$att_id = Image_Handler::sideload_file( $file_url, $listing->get_id(), $field_key, (array) $field_obj->get_formats() );

				if ( is_wp_error( $att_id ) ) {
					$listing_errors[] = sprintf(
						/* translators: 1: file URL, 2: field label, 3: error message. */
						__( 'Error uploading "%1$s" file for "%2$s": %3$s', 'curiouswp-listing-importer-for-hivepress' ),
						esc_url( $file_url ),
						$field_obj->get_label(),
						$att_id->get_error_message()
					);

					continue;
				}

				$file_ids[] = $att_id;

				if ( ! $field_obj->is_multiple() ) {
					break;
				}
			}

			if ( $file_ids ) {
				$file_values[ $field_key ] = $field_obj->is_multiple() ? $file_ids : $file_ids[0];
			}
		}

		if ( $file_values ) {
			$listing->fill( $file_values );

			if ( ! $listing->save( array_keys( $file_values ) ) ) {
				$listing_errors = array_merge( $listing_errors, $listing->_get_errors() );
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
			$result['message'] = __( 'CSV file could not be found or read.', 'curiouswp-listing-importer-for-hivepress' );
			return $result;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream required for chunked fseek and fgetcsv.
		$file = fopen( $file_path, 'r' );
		if ( ! $file ) {
			$result['message'] = __( 'Failed to open CSV file stream.', 'curiouswp-listing-importer-for-hivepress' );
			return $result;
		}

		if ( $offset ) {
			fseek( $file, $offset );
		} elseif ( ! fgetcsv( $file, 0, $delimiter, '"', '\\' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing CSV file pointer.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing CSV file pointer.
		fclose( $file );
			$result['message'] = __( 'CSV file header is empty or invalid.', 'curiouswp-listing-importer-for-hivepress' );
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
					'errors' => [ __( 'Skipped: the server stopped while importing this row (timeout or fatal error).', 'curiouswp-listing-importer-for-hivepress' ) ],
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing CSV file pointer.
		fclose( $file );

		return $result;
	}
}
