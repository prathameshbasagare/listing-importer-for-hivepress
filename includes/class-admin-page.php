<?php
/**
 * Admin Interface & Wizard Page.
 *
 * @package HivePress_Import_PB
 */

namespace HivePress_Import_PB;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Admin_Page
 *
 * Renders the multi-step import and export admin dashboard under Listings > Import Listings.
 */
class Admin_Page {

	/**
	 * Page slug.
	 */
	const PAGE_SLUG = 'pb-hivepress-import';

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'register_admin_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_export_request' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_step_actions' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
		add_action( 'wp_ajax_hp_pb_import_start', [ __CLASS__, 'ajax_import_start' ] );
		add_action( 'wp_ajax_hp_pb_import_chunk', [ __CLASS__, 'ajax_import_chunk' ] );
	}

	/**
	 * Register menu item under 'edit.php?post_type=hp_listing'.
	 */
	public static function register_admin_menu() {
		add_submenu_page(
			'edit.php?post_type=hp_listing',
			__( 'Import & Export Listings', 'pb-hivepress-import' ),
			__( 'Import Listings', 'pb-hivepress-import' ),
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	/**
	 * Enqueue simple admin styling for clean tables and wizard badges.
	 */
	public static function enqueue_admin_assets( $hook ) {
		if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
			return;
		}

		wp_add_inline_style( 'common', '
			.hp-import-wrap { max-width: 1050px; margin-top: 20px; }
			.hp-card { background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 24px; border-radius: 4px; margin-bottom: 24px; }
			.hp-step-badge { display: inline-block; background: #2271b1; color: #fff; padding: 3px 10px; border-radius: 12px; font-weight: 600; font-size: 12px; margin-right: 8px; }
			.hp-mapping-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
			.hp-mapping-table th, .hp-mapping-table td { padding: 12px 14px; border-bottom: 1px solid #e2e4e7; text-align: left; vertical-align: top; }
			.hp-mapping-table tr:hover { background-color: #f9f9f9; }
			.hp-notice-card { background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 18px; margin-bottom: 18px; }
			.hp-error-box { background: #fcf0f2; border-left: 4px solid #d63638; padding: 12px 18px; margin-top: 15px; max-height: 250px; overflow-y: auto; }
			.hp-field-desc { color: #646970; font-size: 12px; margin-top: 4px; line-height: 1.4; }
			.hp-col-select { min-width: 320px; max-width: 480px; }
			.hp-progress { height: 22px; background: #f0f0f1; border: 1px solid #c3c4c7; border-radius: 11px; overflow: hidden; margin: 15px 0 10px; max-width: 600px; }
			.hp-progress-bar { height: 100%; width: 0; background: #2271b1; transition: width .3s ease; }
			.hp-progress-text { font-size: 14px; font-weight: 600; }
			.hp-progress-stats strong { margin-right: 14px; }
		' );

		$step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1;
		if ( 2 !== $step ) {
			return;
		}

		wp_enqueue_script(
			'pb-hivepress-import-progress',
			plugins_url( 'assets/js/import-progress.js', dirname( __FILE__ ) ),
			[],
			'1.1.0',
			true
		);

		wp_localize_script( 'pb-hivepress-import-progress', 'hpPbImport', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'i18n'    => [
				/* translators: Placeholders are kept literal: {processed}, {total}, {percent}. */
				'progress'    => __( 'Processing row {processed} of {total} ({percent}%)…', 'pb-hivepress-import' ),
				'preparing'   => __( 'Preparing import…', 'pb-hivepress-import' ),
				'resuming'    => __( 'Resuming import…', 'pb-hivepress-import' ),
				'done'        => __( 'Import complete. Loading summary…', 'pb-hivepress-import' ),
				'failed'      => __( 'Import paused:', 'pb-hivepress-import' ),
				/* translators: {status} is the HTTP status code. */
				'badResponse' => __( 'Unexpected server response (HTTP {status}). Check the PHP error log.', 'pb-hivepress-import' ),
				'unknown'     => __( 'Unknown error.', 'pb-hivepress-import' ),
				/* translators: {row} is the CSV row number. */
				'rowLabel'    => __( 'Row #{row}:', 'pb-hivepress-import' ),
			],
		] );
	}

	/**
	 * Verify an import AJAX request and return the current import session.
	 *
	 * Sends a JSON error and exits when the request is invalid.
	 *
	 * @return array
	 */
	protected static function verify_ajax_request() {
		if ( ! check_ajax_referer( 'hp_pb_process_action', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Your session has expired. Please reload the page.', 'pb-hivepress-import' ) ], 403 );
		}

		$session = get_transient( 'hp_import_session_' . get_current_user_id() );
		if ( ! $session || ! file_exists( $session['file_path'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Upload session expired. Please upload your CSV again.', 'pb-hivepress-import' ) ] );
		}

		return $session;
	}

	/**
	 * Build the progress payload returned to the browser.
	 *
	 * @param array $session Import session.
	 * @return array
	 */
	protected static function get_progress_data( $session ) {
		return [
			'processed' => (int) $session['row_index'] - 1,
			'total'     => (int) $session['total_rows'],
			'created'   => (int) $session['created'],
			'updated'   => (int) $session['updated'],
			'failed'    => (int) $session['failed'],
		];
	}

	/**
	 * Build the error shown when required fields are not mapped.
	 *
	 * @param string[] $labels Field labels.
	 * @return string
	 */
	protected static function get_missing_fields_message( $labels ) {
		/* translators: %s: field labels. */
		return sprintf( __( 'Please select a CSV column for these required fields: %s.', 'pb-hivepress-import' ), implode( ', ', $labels ) );
	}

	/**
	 * AJAX: store the column mapping and reset progress before chunked import.
	 */
	public static function ajax_import_start() {
		$session = self::verify_ajax_request();

		$mappings = ! empty( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mapping'] ) ) : [];
		$missing  = Importer::get_unmapped_required_fields( Importer::get_mappable_fields( $session['category_id'], $session['mode'] ), $mappings );

		if ( $missing ) {
			wp_send_json_error( [ 'message' => self::get_missing_fields_message( $missing ) ] );
		}

		$session['mappings']       = $mappings;
		$session['total_rows']     = Importer::count_rows( $session['file_path'], $session['delimiter'] );
		$session['offset']         = 0;
		$session['row_index']      = 1;
		$session['pending_offset'] = null;
		$session['created']        = 0;
		$session['updated']        = 0;
		$session['failed']         = 0;
		$session['errors']         = [];
		set_transient( 'hp_import_session_' . get_current_user_id(), $session, HOUR_IN_SECONDS );

		wp_send_json_success( self::get_progress_data( $session ) );
	}

	/**
	 * AJAX: import the next chunk of rows.
	 *
	 * Progress is saved to the session after every row, so a retry after a
	 * failed request resumes exactly where the import stopped.
	 */
	public static function ajax_import_chunk() {
		$session = self::verify_ajax_request();

		if ( ! isset( $session['mappings'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Import has not been started.', 'pb-hivepress-import' ) ] );
		}

		$session_key = 'hp_import_session_' . get_current_user_id();

		// A pending offset means the previous request died while importing that row.
		$skip_first = null !== $session['pending_offset'];
		$offset     = $skip_first ? $session['pending_offset'] : $session['offset'];

		$result = Importer::process_chunk( [
			'file_path'   => $session['file_path'],
			'delimiter'   => $session['delimiter'],
			'mode'        => $session['mode'],
			'category_id' => $session['category_id'],
			'mappings'    => $session['mappings'],
			'offset'      => $offset,
			'row_index'   => $session['row_index'],
			'chunk_size'  => (int) apply_filters( 'hp_pb_import_chunk_size', 5 ),
			'skip_first'  => $skip_first,
			'on_progress' => function( $event ) use ( &$session, $session_key ) {
				if ( 'start' === $event['type'] ) {
					$session['pending_offset'] = $event['offset'];
				} else {
					$session['pending_offset'] = null;
					$session['offset']         = $event['offset'];
					$session['row_index']      = $event['row_index'];

					if ( isset( $session[ $event['result'] ] ) && in_array( $event['result'], [ 'created', 'updated', 'failed' ], true ) ) {
						$session[ $event['result'] ]++;
					}
					if ( ! empty( $event['errors'] ) ) {
						$session['errors'][ $event['row_index'] ] = $event['errors'];
					}
				}

				set_transient( $session_key, $session, HOUR_IN_SECONDS );
			},
		] );

		if ( empty( $result['success'] ) ) {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}

		// The last "start" event fires at end of file; clear it so a retry doesn't skip anything.
		$session['pending_offset'] = null;

		$data           = self::get_progress_data( $session );
		$data['errors'] = $result['errors'];
		$data['done']   = $result['done'];

		if ( $result['done'] ) {
			set_transient( 'hp_import_result_' . get_current_user_id(), [
				'success' => true,
				'stats'   => [
					'total_rows' => $data['processed'],
					'created'    => $session['created'],
					'updated'    => $session['updated'],
					'failed'     => $session['failed'],
					'errors'     => $session['errors'],
				],
			], HOUR_IN_SECONDS );

			if ( file_exists( $session['file_path'] ) ) {
				@unlink( $session['file_path'] );
			}
			delete_transient( $session_key );

			$data['redirect'] = admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&step=3' );
		} else {
			set_transient( $session_key, $session, HOUR_IN_SECONDS );
		}

		wp_send_json_success( $data );
	}

	/**
	 * Handle CSV Export direct downloads.
	 */
	public static function handle_export_request() {
		if ( ! empty( $_POST['hp_pb_export_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['hp_pb_export_nonce'] ), 'hp_pb_export_action' ) ) {
			Exporter::export( [
				'category_id' => ! empty( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0,
				'delimiter'   => ! empty( $_POST['delimiter'] ) ? sanitize_text_field( $_POST['delimiter'] ) : ',',
			] );
		}
	}

	/**
	 * Handle Wizard Post Submissions (Upload CSV & Process Import).
	 */
	public static function handle_step_actions() {
		// admin_init also fires for admin-ajax.php; chunked imports are handled by the wp_ajax_* actions.
		if ( wp_doing_ajax() ) {
			return;
		}

		// Action 1: Upload file (Step 1 -> Step 2)
		if ( ! empty( $_POST['hp_pb_upload_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['hp_pb_upload_nonce'] ), 'hp_pb_upload_action' ) ) {
			if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
				add_settings_error( 'hp_import', 'no_file', __( 'Please choose a CSV file to upload.', 'pb-hivepress-import' ) );
				return;
			}

			// Save to uploads folder
			$upload_dir = wp_upload_dir();
			$target_dir = $upload_dir['basedir'] . '/hp_imports';
			if ( ! file_exists( $target_dir ) ) {
				wp_mkdir_p( $target_dir );
			}

			$filename = 'import-' . wp_generate_password( 10, false, false ) . '.csv';
			$target_path = $target_dir . '/' . $filename;

			if ( ! move_uploaded_file( $_FILES['csv_file']['tmp_name'], $target_path ) ) {
				add_settings_error( 'hp_import', 'upload_failed', __( 'Could not save uploaded CSV file.', 'pb-hivepress-import' ) );
				return;
			}

			$delimiter   = ! empty( $_POST['delimiter'] ) ? sanitize_text_field( $_POST['delimiter'] ) : ',';
			$mode        = ! empty( $_POST['mode'] ) ? sanitize_text_field( $_POST['mode'] ) : 'create';
			$category_id = ! empty( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;

			// Store in transient for current admin
			$session_data = [
				'file_path'   => $target_path,
				'delimiter'   => $delimiter,
				'mode'        => $mode,
				'category_id' => $category_id,
			];
			set_transient( 'hp_import_session_' . get_current_user_id(), $session_data, HOUR_IN_SECONDS );

			wp_safe_redirect( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&step=2' ) );
			exit;
		}

		// Action 2: Process Mapped Data (Step 2 -> Step 3)
		if ( ! empty( $_POST['hp_pb_process_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['hp_pb_process_nonce'] ), 'hp_pb_process_action' ) ) {
			$session = get_transient( 'hp_import_session_' . get_current_user_id() );
			if ( ! $session || ! file_exists( $session['file_path'] ) ) {
				wp_safe_redirect( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&step=1' ) );
				exit;
			}

			$mappings = ! empty( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mapping'] ) ) : [];
			$missing  = Importer::get_unmapped_required_fields( Importer::get_mappable_fields( $session['category_id'], $session['mode'] ), $mappings );

			if ( $missing ) {
				add_settings_error( 'hp_import', 'unmapped_required', self::get_missing_fields_message( $missing ) );
				return;
			}

			$result = Importer::process( [
				'file_path'   => $session['file_path'],
				'delimiter'   => $session['delimiter'],
				'mode'        => $session['mode'],
				'category_id' => $session['category_id'],
				'mappings'    => $mappings,
			] );

			// Clean up imported file (kept on failure so the mapping step can be retried)
			if ( ! empty( $result['success'] ) ) {
				if ( file_exists( $session['file_path'] ) ) {
					@unlink( $session['file_path'] );
				}
				delete_transient( 'hp_import_session_' . get_current_user_id() );
			}

			// Store result for Step 3 summary
			set_transient( 'hp_import_result_' . get_current_user_id(), $result, HOUR_IN_SECONDS );

			wp_safe_redirect( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&step=3' ) );
			exit;
		}
	}

	/**
	 * Render Admin Dashboard Page.
	 */
	public static function render_page() {
		$step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1;
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'import';

		?>
		<div class="wrap hp-import-wrap">
			<h1><?php esc_html_e( 'HivePress Listings Import & Export', 'pb-hivepress-import' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Import listings into HivePress with intelligent MIME-type detection for extensionless and CDN image URLs.', 'pb-hivepress-import' ); ?>
			</p>

			<nav class="nav-tab-wrapper wp-clearfix">
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&tab=import' ) ); ?>" class="nav-tab <?php echo 'import' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Import Listings', 'pb-hivepress-import' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&tab=export' ) ); ?>" class="nav-tab <?php echo 'export' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Export Listings', 'pb-hivepress-import' ); ?>
				</a>
			</nav>

			<?php settings_errors( 'hp_import' ); ?>

			<div style="margin-top: 20px;">
				<?php
				if ( 'export' === $tab ) {
					self::render_export_tab();
				} else {
					switch ( $step ) {
						case 2:
							self::render_step_2_mapping();
							break;
						case 3:
							self::render_step_3_complete();
							break;
						case 1:
						default:
							self::render_step_1_upload();
							break;
					}
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Step 1: Upload CSV Form.
	 */
	protected static function render_step_1_upload() {
		$categories = get_terms( [
			'taxonomy'   => 'hp_listing_category',
			'hide_empty' => false,
		] );
		?>
		<div class="hp-card">
			<h2><span class="hp-step-badge"><?php esc_html_e( 'Step 1', 'pb-hivepress-import' ); ?></span> <?php esc_html_e( 'Upload CSV File', 'pb-hivepress-import' ); ?></h2>
			<form method="post" enctype="multipart/form-data" action="">
				<?php wp_nonce_field( 'hp_pb_upload_action', 'hp_pb_upload_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="csv_file"><?php esc_html_e( 'CSV / TXT File', 'pb-hivepress-import' ); ?></label></th>
						<td>
							<input type="file" name="csv_file" id="csv_file" accept=".csv, .txt" required />
							<p class="description"><?php esc_html_e( 'Select the CSV file containing your listings data.', 'pb-hivepress-import' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="delimiter"><?php esc_html_e( 'CSV Delimiter', 'pb-hivepress-import' ); ?></label></th>
						<td>
							<input type="text" name="delimiter" id="delimiter" value="," class="small-text" maxlength="1" required />
							<p class="description"><?php esc_html_e( 'Typically a comma (,) or semicolon (;).', 'pb-hivepress-import' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mode"><?php esc_html_e( 'Import Mode', 'pb-hivepress-import' ); ?></label></th>
						<td>
							<select name="mode" id="mode">
								<option value="create"><?php esc_html_e( 'Create new listings only', 'pb-hivepress-import' ); ?></option>
								<option value="update"><?php esc_html_e( 'Update existing listings only (requires ID column)', 'pb-hivepress-import' ); ?></option>
								<option value="append"><?php esc_html_e( 'Update existing or create new', 'pb-hivepress-import' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="category_id"><?php esc_html_e( 'Target Category', 'pb-hivepress-import' ); ?></label></th>
						<td>
							<select name="category_id" id="category_id">
								<option value=""><?php esc_html_e( '— None (Map categories per row in CSV) —', 'pb-hivepress-import' ); ?></option>
								<?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
									<?php foreach ( $categories as $cat ) : ?>
										<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
									<?php endforeach; ?>
								<?php endif; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Optionally assign all imported listings to a single default category.', 'pb-hivepress-import' ); ?></p>
						</td>
					</tr>
				</table>

				<div class="hp-notice-card">
					<strong><?php esc_html_e( '💡 Smart Image Import Feature Active:', 'pb-hivepress-import' ); ?></strong>
					<?php esc_html_e( 'Image URLs in CSV without file extensions (such as Unsplash, AWS S3, or modern CDN URLs) will be inspected on-the-fly and automatically assigned proper extensions (.jpg, .png, .webp).', 'pb-hivepress-import' ); ?>
				</div>

				<?php submit_button( __( 'Continue to Column Mapping →', 'pb-hivepress-import' ), 'primary' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Step 2: Map Columns (HivePress Fields as Rows, User-Readable CSV Column Dropdowns).
	 */
	protected static function render_step_2_mapping() {
		$session = get_transient( 'hp_import_session_' . get_current_user_id() );
		if ( ! $session || ! file_exists( $session['file_path'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Upload session expired. Please upload your CSV again.', 'pb-hivepress-import' ) . '</p></div>';
			self::render_step_1_upload();
			return;
		}

		$columns = Importer::get_csv_columns( $session['file_path'], $session['delimiter'] );
		$fields  = Importer::get_mappable_fields( $session['category_id'], $session['mode'] );
		$posted  = ! empty( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mapping'] ) ) : [];
		?>
		<div class="hp-card">
			<h2><span class="hp-step-badge"><?php esc_html_e( 'Step 2', 'pb-hivepress-import' ); ?></span> <?php esc_html_e( 'Map Columns', 'pb-hivepress-import' ); ?></h2>
			<p><?php esc_html_e( 'For each HivePress listing field below, choose which column from your CSV file contains the data.', 'pb-hivepress-import' ); ?></p>

			<form method="post" action="" id="hp-pb-mapping-form">
				<?php wp_nonce_field( 'hp_pb_process_action', 'hp_pb_process_nonce' ); ?>

				<table class="hp-mapping-table">
					<thead>
						<tr>
							<th style="width: 40%;"><?php esc_html_e( 'HivePress Field / Attribute', 'pb-hivepress-import' ); ?></th>
							<th style="width: 60%;"><?php esc_html_e( 'CSV Column', 'pb-hivepress-import' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $fields as $field_key => $field_meta ) :
							$label = $field_meta['label'];
							$desc  = isset( $field_meta['description'] ) ? $field_meta['description'] : '';
							$is_req = ! empty( $field_meta['required'] );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $label ); ?></strong>
									<?php if ( $is_req ) : ?>
										<span style="color: #d63638;" title="<?php esc_attr_e( 'Required', 'pb-hivepress-import' ); ?>">*</span>
									<?php endif; ?>
									<?php if ( $desc ) : ?>
										<div class="hp-field-desc"><?php echo esc_html( $desc ); ?></div>
									<?php endif; ?>
								</td>
								<td>
									<select name="mapping[<?php echo esc_attr( $field_key ); ?>]" class="hp-col-select" <?php echo $is_req ? 'required' : ''; ?>>
										<option value=""><?php esc_html_e( '— None (Do not import) —', 'pb-hivepress-import' ); ?></option>
										<?php
										// Keep the submitted choice when the mapping is shown again after a validation error.
										$posted_col   = isset( $posted[ $field_key ] ) ? (string) $posted[ $field_key ] : null;
										$has_selected = null !== $posted_col;
										foreach ( $columns as $idx => $raw_col ) :
											$clean_col_name = strtolower( trim( $raw_col ) );
											$readable_label = Importer::format_column_label( $raw_col );

											// Smart auto-detection between CSV column and HivePress field
											$is_match = false;
											if (
												$clean_col_name === strtolower( $field_key ) ||
												$clean_col_name === strtolower( $label ) ||
												( 'title' === $field_key && in_array( $clean_col_name, [ 'name', 'title', 'listing_title', 'company_name' ], true ) ) ||
												( 'description' === $field_key && in_array( $clean_col_name, [ 'description', 'company description', 'company_description', 'website_description', 'content', 'details' ], true ) ) ||
												( 'location' === $field_key && in_array( $clean_col_name, [ 'address', 'full_address', 'location' ], true ) ) ||
												( 'images' === $field_key && in_array( $clean_col_name, [ 'images', 'image', 'photo', 'photos', 'picture' ], true ) ) ||
												( 'categories' === $field_key && in_array( $clean_col_name, [ 'categories', 'category', 'subtypes', 'industry' ], true ) ) ||
												( 'id' === $field_key && in_array( $clean_col_name, [ 'id', 'listing_id', 'post_id' ], true ) ) ||
												( 'phone_number' === $field_key && in_array( $clean_col_name, [ 'phone', 'phone_number', 'telephone' ], true ) ) ||
												( 'google_cid' === $field_key && in_array( $clean_col_name, [ 'cid', 'google_cid' ], true ) ) ||
												( 'google_place_id' === $field_key && in_array( $clean_col_name, [ 'place_id', 'google_place_id' ], true ) ) ||
												( 'google_rating' === $field_key && in_array( $clean_col_name, [ 'rating', 'google_rating' ], true ) ) ||
												( 'total_google_reviews' === $field_key && in_array( $clean_col_name, [ 'reviews', 'reviews_count', 'total_reviews' ], true ) ) ||
												( 'user' === $field_key && in_array( $clean_col_name, [ 'user', 'author', 'username' ], true ) )
											) {
												$is_match = true;
											}

											$selected = null !== $posted_col && (string) $idx === $posted_col;
											if ( ! $has_selected && $is_match ) {
												$selected = true;
												$has_selected = true;
											}
											?>
											<option value="<?php echo esc_attr( $idx ); ?>" <?php selected( $selected, true ); ?>>
												<?php
												// Display clean human-readable name, along with original raw column if they differ
												if ( $readable_label && $readable_label !== $raw_col ) {
													echo esc_html( $readable_label . ' (' . $raw_col . ')' );
												} else {
													echo esc_html( $raw_col );
												}
												?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div style="margin-top: 25px;">
					<?php submit_button( __( 'Start Import Process Now 🚀', 'pb-hivepress-import' ), 'primary', 'submit', false ); ?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&step=1' ) ); ?>" class="button button-secondary" style="margin-left: 10px;">
						<?php esc_html_e( '← Back / Cancel', 'pb-hivepress-import' ); ?>
					</a>
				</div>
			</form>

			<div id="hp-pb-progress" hidden>
				<div class="hp-progress"><div class="hp-progress-bar"></div></div>
				<p class="hp-progress-text"></p>
				<p class="hp-progress-stats">
					<strong style="color: #00a32a;"><?php esc_html_e( 'Created:', 'pb-hivepress-import' ); ?> <span data-stat="created">0</span></strong>
					<strong style="color: #2271b1;"><?php esc_html_e( 'Updated:', 'pb-hivepress-import' ); ?> <span data-stat="updated">0</span></strong>
					<strong style="color: #d63638;"><?php esc_html_e( 'Failed:', 'pb-hivepress-import' ); ?> <span data-stat="failed">0</span></strong>
				</p>
				<p class="description"><?php esc_html_e( 'Keep this page open until the import finishes.', 'pb-hivepress-import' ); ?></p>
				<div class="hp-error-box" hidden>
					<h3 style="margin-top: 0; color: #d63638;"><?php esc_html_e( 'Import Warnings & Errors Log:', 'pb-hivepress-import' ); ?></h3>
					<ul style="list-style: disc; margin-left: 20px;"></ul>
				</div>
				<p class="hp-progress-actions" hidden>
					<button type="button" class="button button-primary" id="hp-pb-retry"><?php esc_html_e( 'Retry / Resume Import', 'pb-hivepress-import' ); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Step 3: Import Results and Error Summary.
	 */
	protected static function render_step_3_complete() {
		$result = get_transient( 'hp_import_result_' . get_current_user_id() );
		delete_transient( 'hp_import_result_' . get_current_user_id() );

		if ( empty( $result ) ) {
			?>
			<div class="hp-card">
				<h2><?php esc_html_e( 'No recent import results found.', 'pb-hivepress-import' ); ?></h2>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG ) ); ?>" class="button button-primary"><?php esc_html_e( 'Start New Import', 'pb-hivepress-import' ); ?></a>
			</div>
			<?php
			return;
		}

		if ( empty( $result['success'] ) ) {
			?>
			<div class="hp-card">
				<h2><?php esc_html_e( 'Import Failed', 'pb-hivepress-import' ); ?></h2>
				<p><?php echo esc_html( isset( $result['message'] ) ? $result['message'] : __( 'Unknown error.', 'pb-hivepress-import' ) ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&step=2' ) ); ?>" class="button button-primary"><?php esc_html_e( '← Back to Mapping', 'pb-hivepress-import' ); ?></a>
			</div>
			<?php
			return;
		}

		$stats = isset( $result['stats'] ) ? $result['stats'] : [];
		?>
		<div class="hp-card">
			<h2><span class="hp-step-badge" style="background: #00a32a;">✓</span> <?php esc_html_e( 'Import Process Completed', 'pb-hivepress-import' ); ?></h2>

			<table class="widefat" style="max-width: 600px; margin-top: 15px;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Total CSV Rows Processed:', 'pb-hivepress-import' ); ?></strong></td>
						<td><?php echo esc_html( $stats['total_rows'] ); ?></td>
					</tr>
					<tr>
						<td><strong style="color: #00a32a;"><?php esc_html_e( 'New Listings Created:', 'pb-hivepress-import' ); ?></strong></td>
						<td><strong><?php echo esc_html( $stats['created'] ); ?></strong></td>
					</tr>
					<tr>
						<td><strong style="color: #2271b1;"><?php esc_html_e( 'Existing Listings Updated:', 'pb-hivepress-import' ); ?></strong></td>
						<td><strong><?php echo esc_html( $stats['updated'] ); ?></strong></td>
					</tr>
					<tr>
						<td><strong style="color: #d63638;"><?php esc_html_e( 'Failed Rows:', 'pb-hivepress-import' ); ?></strong></td>
						<td><?php echo esc_html( $stats['failed'] ); ?></td>
					</tr>
				</tbody>
			</table>

			<?php if ( ! empty( $stats['errors'] ) ) : ?>
				<div class="hp-error-box">
					<h3 style="margin-top: 0; color: #d63638;"><?php esc_html_e( 'Import Warnings & Errors Log:', 'pb-hivepress-import' ); ?></h3>
					<ul style="list-style: disc; margin-left: 20px;">
						<?php foreach ( $stats['errors'] as $row_num => $row_errors ) : ?>
							<li>
								<strong><?php printf( esc_html__( 'Row #%d:', 'pb-hivepress-import' ), $row_num ); ?></strong>
								<?php echo esc_html( implode( ' | ', (array) $row_errors ) ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div style="margin-top: 25px;">
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=hp_listing' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'View All Listings', 'pb-hivepress-import' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&step=1' ) ); ?>" class="button button-secondary" style="margin-left: 10px;">
					<?php esc_html_e( 'Import Another File', 'pb-hivepress-import' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Export Listings Tab.
	 */
	protected static function render_export_tab() {
		$categories = get_terms( [
			'taxonomy'   => 'hp_listing_category',
			'hide_empty' => false,
		] );
		?>
		<div class="hp-card">
			<h2><?php esc_html_e( 'Export Listings to CSV', 'pb-hivepress-import' ); ?></h2>
			<p><?php esc_html_e( 'Download all listings, including titles, descriptions, categories, image URLs, and custom attributes.', 'pb-hivepress-import' ); ?></p>

			<form method="post" action="">
				<?php wp_nonce_field( 'hp_pb_export_action', 'hp_pb_export_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="export_category_id"><?php esc_html_e( 'Category Filter', 'pb-hivepress-import' ); ?></label></th>
						<td>
							<select name="category_id" id="export_category_id">
								<option value=""><?php esc_html_e( '— All Categories —', 'pb-hivepress-import' ); ?></option>
								<?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
									<?php foreach ( $categories as $cat ) : ?>
										<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
									<?php endforeach; ?>
								<?php endif; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="export_delimiter"><?php esc_html_e( 'CSV Delimiter', 'pb-hivepress-import' ); ?></label></th>
						<td>
							<input type="text" name="delimiter" id="export_delimiter" value="," class="small-text" maxlength="1" required />
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Export Listings to CSV 📥', 'pb-hivepress-import' ), 'primary' ); ?>
			</form>
		</div>
		<?php
	}
}
