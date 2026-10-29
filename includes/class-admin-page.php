<?php
/**
 * Admin Interface & Wizard Page.
 *
 * @package CuriousWP_Listing_Importer_For_HivePress
 */

namespace CuriousWP_Listing_Importer_For_HivePress;

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
	const PAGE_SLUG = 'curiouswp-listing-importer-for-hivepress';

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'register_admin_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_export_request' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_step_actions' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
		add_action( 'wp_ajax_curiouswp_li_start', [ __CLASS__, 'ajax_import_start' ] );
		add_action( 'wp_ajax_curiouswp_li_chunk', [ __CLASS__, 'ajax_import_chunk' ] );
	}

	/**
	 * Register menu item under 'edit.php?post_type=hp_listing'.
	 */
	public static function register_admin_menu() {
		add_submenu_page(
			'edit.php?post_type=hp_listing',
			__( 'Import & Export Listings', 'curiouswp-listing-importer-for-hivepress' ),
			__( 'Import Listings', 'curiouswp-listing-importer-for-hivepress' ),
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
			.curiouswp-li-wrap { max-width: 1050px; margin-top: 20px; }
			.curiouswp-li-card { background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 24px; border-radius: 4px; margin-bottom: 24px; }
			.curiouswp-li-step-badge { display: inline-block; background: #2271b1; color: #fff; padding: 3px 10px; border-radius: 12px; font-weight: 600; font-size: 12px; margin-right: 8px; }
			.curiouswp-li-mapping-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
			.curiouswp-li-mapping-table th, .curiouswp-li-mapping-table td { padding: 12px 14px; border-bottom: 1px solid #e2e4e7; text-align: left; vertical-align: top; }
			.curiouswp-li-mapping-table tr:hover { background-color: #f9f9f9; }
			.curiouswp-li-notice-card { background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 18px; margin-bottom: 18px; }
			.curiouswp-li-error-box { background: #fcf0f2; border-left: 4px solid #d63638; padding: 12px 18px; margin-top: 15px; max-height: 250px; overflow-y: auto; }
			.curiouswp-li-field-desc { color: #646970; font-size: 12px; margin-top: 4px; line-height: 1.4; }
			.curiouswp-li-col-select { min-width: 320px; max-width: 480px; }
			.curiouswp-li-progress { height: 22px; background: #f0f0f1; border: 1px solid #c3c4c7; border-radius: 11px; overflow: hidden; margin: 15px 0 10px; max-width: 600px; }
			.curiouswp-li-progress-bar { height: 100%; width: 0; background: #2271b1; transition: width .3s ease; }
			.curiouswp-li-progress-text { font-size: 14px; font-weight: 600; }
			.curiouswp-li-progress-stats strong { margin-right: 14px; }
		' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Enqueueing scripts based on view step.
		$step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1;
		if ( 2 !== $step ) {
			return;
		}

		wp_enqueue_script(
			'curiouswp-li-progress',
			plugins_url( 'assets/js/import-progress.js', dirname( __FILE__ ) ),
			[],
			'1.0.2',
			true
		);

		wp_localize_script( 'curiouswp-li-progress', 'curiouswpListingImporter', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'i18n'    => [
				/* translators: Placeholders are kept literal: {processed}, {total}, {percent}. */
				'progress'    => __( 'Processing row {processed} of {total} ({percent}%)…', 'curiouswp-listing-importer-for-hivepress' ),
				'preparing'   => __( 'Preparing import…', 'curiouswp-listing-importer-for-hivepress' ),
				'resuming'    => __( 'Resuming import…', 'curiouswp-listing-importer-for-hivepress' ),
				'done'        => __( 'Import complete. Loading summary…', 'curiouswp-listing-importer-for-hivepress' ),
				'failed'      => __( 'Import paused:', 'curiouswp-listing-importer-for-hivepress' ),
				/* translators: {status} is the HTTP status code. */
				'badResponse' => __( 'Unexpected server response (HTTP {status}). Check the PHP error log.', 'curiouswp-listing-importer-for-hivepress' ),
				'unknown'     => __( 'Unknown error.', 'curiouswp-listing-importer-for-hivepress' ),
				/* translators: {row} is the CSV row number. */
				'rowLabel'    => __( 'Row #{row}:', 'curiouswp-listing-importer-for-hivepress' ),
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
		if ( ! check_ajax_referer( 'curiouswp_li_process_action', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Your session has expired or you do not have sufficient permissions. Please reload the page.', 'curiouswp-listing-importer-for-hivepress' ) ], 403 );
		}

		$session = get_transient( 'curiouswp_li_session_' . get_current_user_id() );
		if ( ! $session || ! file_exists( $session['file_path'] ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Upload session expired. Please upload your CSV again.', 'curiouswp-listing-importer-for-hivepress' ) ] );
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
		return sprintf(
			/* translators: %s: comma-separated list of required field labels. */
			__( 'Please select a CSV column for these required fields: %s.', 'curiouswp-listing-importer-for-hivepress' ),
			implode( ', ', $labels )
		);
	}

	/**
	 * AJAX: store the column mapping and reset progress before chunked import.
	 */
	public static function ajax_import_start() {
		$session = self::verify_ajax_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_ajax_request().
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
		set_transient( 'curiouswp_li_session_' . get_current_user_id(), $session, HOUR_IN_SECONDS );

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
			wp_send_json_error( [ 'message' => __( 'Import has not been started.', 'curiouswp-listing-importer-for-hivepress' ) ] );
		}

		$session_key = 'curiouswp_li_session_' . get_current_user_id();

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
			'chunk_size'  => (int) apply_filters( 'curiouswp_li_chunk_size', 5 ),
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
			set_transient( 'curiouswp_li_result_' . get_current_user_id(), [
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
				wp_delete_file( $session['file_path'] );
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
		if ( empty( $_POST['curiouswp_li_export_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['curiouswp_li_export_nonce'] ) ), 'curiouswp_li_export_action' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'curiouswp-listing-importer-for-hivepress' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized user.', 'curiouswp-listing-importer-for-hivepress' ) );
		}

		Exporter::export( [
			'category_id' => ! empty( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0,
			'delimiter'   => ! empty( $_POST['delimiter'] ) ? sanitize_text_field( wp_unslash( $_POST['delimiter'] ) ) : ',',
		] );
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
		if ( ! empty( $_POST['curiouswp_li_upload_nonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['curiouswp_li_upload_nonce'] ) ), 'curiouswp_li_upload_action' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'curiouswp-listing-importer-for-hivepress' ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unauthorized user.', 'curiouswp-listing-importer-for-hivepress' ) );
			}

			if ( empty( $_FILES['csv_file']['tmp_name'] ) || empty( $_FILES['csv_file']['name'] ) ) {
				add_settings_error( 'curiouswp_li_import', 'no_file', esc_html__( 'Please choose a CSV file to upload.', 'curiouswp-listing-importer-for-hivepress' ) );
				return;
			}

			$orig_filename = sanitize_file_name( wp_unslash( $_FILES['csv_file']['name'] ) );
			$file_ext      = strtolower( pathinfo( $orig_filename, PATHINFO_EXTENSION ) );
			if ( ! in_array( $file_ext, [ 'csv', 'txt' ], true ) ) {
				add_settings_error( 'curiouswp_li_import', 'invalid_extension', esc_html__( 'Only CSV and TXT files are allowed.', 'curiouswp-listing-importer-for-hivepress' ) );
				return;
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_handle_upload validates and handles the file array.
			$uploaded = wp_handle_upload( $_FILES['csv_file'], [
				'test_form' => false,
				'test_type' => false,
			] );

			if ( isset( $uploaded['error'] ) ) {
				add_settings_error( 'curiouswp_li_import', 'upload_failed', esc_html( $uploaded['error'] ) );
				return;
			}

			$target_path = $uploaded['file'];

			$delimiter   = ! empty( $_POST['delimiter'] ) ? sanitize_text_field( wp_unslash( $_POST['delimiter'] ) ) : ',';
			$mode        = ! empty( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'create';
			if ( ! in_array( $mode, [ 'create', 'update', 'append' ], true ) ) {
				$mode = 'create';
			}
			$category_id = ! empty( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;

			// Store in transient for current admin
			$session_data = [
				'file_path'   => $target_path,
				'delimiter'   => $delimiter,
				'mode'        => $mode,
				'category_id' => $category_id,
			];
			set_transient( 'curiouswp_li_session_' . get_current_user_id(), $session_data, HOUR_IN_SECONDS );

			wp_safe_redirect( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&step=2' ) );
			exit;
		}

		// Action 2: Process Mapped Data (Step 2 -> Step 3)
		if ( ! empty( $_POST['curiouswp_li_process_nonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['curiouswp_li_process_nonce'] ) ), 'curiouswp_li_process_action' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'curiouswp-listing-importer-for-hivepress' ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unauthorized user.', 'curiouswp-listing-importer-for-hivepress' ) );
			}

			$session = get_transient( 'curiouswp_li_session_' . get_current_user_id() );
			if ( ! $session || ! file_exists( $session['file_path'] ) ) {
				wp_safe_redirect( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&step=1' ) );
				exit;
			}

			$mappings = ! empty( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mapping'] ) ) : [];
			$missing  = Importer::get_unmapped_required_fields( Importer::get_mappable_fields( $session['category_id'], $session['mode'] ), $mappings );

			if ( $missing ) {
				add_settings_error( 'curiouswp_li_import', 'unmapped_required', self::get_missing_fields_message( $missing ) );
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
					wp_delete_file( $session['file_path'] );
				}
				delete_transient( 'curiouswp_li_session_' . get_current_user_id() );
			}

			// Store result for Step 3 summary
			set_transient( 'curiouswp_li_result_' . get_current_user_id(), $result, HOUR_IN_SECONDS );

			wp_safe_redirect( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&step=3' ) );
			exit;
		}
	}

	/**
	 * Render Admin Dashboard Page.
	 */
	public static function render_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab and step determine view state only.
		$step = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab and step determine view state only.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'import';

		?>
		<div class="wrap curiouswp-li-wrap">
			<h1><?php esc_html_e( 'CuriousWP Listing Importer for HivePress', 'curiouswp-listing-importer-for-hivepress' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Import listings into HivePress with intelligent MIME-type detection for extensionless and CDN image URLs.', 'curiouswp-listing-importer-for-hivepress' ); ?>
			</p>

			<nav class="nav-tab-wrapper wp-clearfix">
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&tab=import' ) ); ?>" class="nav-tab <?php echo 'import' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Import Listings', 'curiouswp-listing-importer-for-hivepress' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&tab=export' ) ); ?>" class="nav-tab <?php echo 'export' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Export Listings', 'curiouswp-listing-importer-for-hivepress' ); ?>
				</a>
			</nav>

			<?php settings_errors( 'curiouswp_li_import' ); ?>

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
		<div class="curiouswp-li-card">
			<h2><span class="curiouswp-li-step-badge"><?php esc_html_e( 'Step 1', 'curiouswp-listing-importer-for-hivepress' ); ?></span> <?php esc_html_e( 'Upload CSV File', 'curiouswp-listing-importer-for-hivepress' ); ?></h2>
			<form method="post" enctype="multipart/form-data" action="">
				<?php wp_nonce_field( 'curiouswp_li_upload_action', 'curiouswp_li_upload_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="csv_file"><?php esc_html_e( 'CSV / TXT File', 'curiouswp-listing-importer-for-hivepress' ); ?></label></th>
						<td>
							<input type="file" name="csv_file" id="csv_file" accept=".csv, .txt" required />
							<p class="description"><?php esc_html_e( 'Select the CSV file containing your listings data.', 'curiouswp-listing-importer-for-hivepress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="delimiter"><?php esc_html_e( 'CSV Delimiter', 'curiouswp-listing-importer-for-hivepress' ); ?></label></th>
						<td>
							<input type="text" name="delimiter" id="delimiter" value="," class="small-text" maxlength="1" required />
							<p class="description"><?php esc_html_e( 'Typically a comma (,) or semicolon (;).', 'curiouswp-listing-importer-for-hivepress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mode"><?php esc_html_e( 'Import Mode', 'curiouswp-listing-importer-for-hivepress' ); ?></label></th>
						<td>
							<select name="mode" id="mode">
								<option value="create"><?php esc_html_e( 'Create new listings only', 'curiouswp-listing-importer-for-hivepress' ); ?></option>
								<option value="update"><?php esc_html_e( 'Update existing listings only (requires ID column)', 'curiouswp-listing-importer-for-hivepress' ); ?></option>
								<option value="append"><?php esc_html_e( 'Update existing or create new', 'curiouswp-listing-importer-for-hivepress' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="category_id"><?php esc_html_e( 'Target Category', 'curiouswp-listing-importer-for-hivepress' ); ?></label></th>
						<td>
							<select name="category_id" id="category_id">
								<option value=""><?php esc_html_e( '— None (Map categories per row in CSV) —', 'curiouswp-listing-importer-for-hivepress' ); ?></option>
								<?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
									<?php foreach ( $categories as $cat ) : ?>
										<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
									<?php endforeach; ?>
								<?php endif; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Optionally assign all imported listings to a single default category.', 'curiouswp-listing-importer-for-hivepress' ); ?></p>
						</td>
					</tr>
				</table>

				<div class="curiouswp-li-notice-card">
					<strong><?php esc_html_e( '💡 Smart Image Import Feature Active:', 'curiouswp-listing-importer-for-hivepress' ); ?></strong>
					<?php esc_html_e( 'Image URLs in CSV without file extensions (such as Unsplash, AWS S3, or modern CDN URLs) will be inspected on-the-fly and automatically assigned proper extensions (.jpg, .png, .webp).', 'curiouswp-listing-importer-for-hivepress' ); ?>
				</div>

				<?php submit_button( __( 'Continue to Column Mapping →', 'curiouswp-listing-importer-for-hivepress' ), 'primary' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Step 2: Map Columns (HivePress Fields as Rows, User-Readable CSV Column Dropdowns).
	 */
	protected static function render_step_2_mapping() {
		$session = get_transient( 'curiouswp_li_session_' . get_current_user_id() );
		if ( ! $session || ! file_exists( $session['file_path'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Upload session expired. Please upload your CSV again.', 'curiouswp-listing-importer-for-hivepress' ) . '</p></div>';
			self::render_step_1_upload();
			return;
		}

		$columns = Importer::get_csv_columns( $session['file_path'], $session['delimiter'] );
		$fields  = Importer::get_mappable_fields( $session['category_id'], $session['mode'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified when processing form submission.
		$posted  = ! empty( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mapping'] ) ) : [];
		?>
		<div class="curiouswp-li-card">
			<h2><span class="curiouswp-li-step-badge"><?php esc_html_e( 'Step 2', 'curiouswp-listing-importer-for-hivepress' ); ?></span> <?php esc_html_e( 'Map Columns', 'curiouswp-listing-importer-for-hivepress' ); ?></h2>
			<p><?php esc_html_e( 'For each HivePress listing field below, choose which column from your CSV file contains the data.', 'curiouswp-listing-importer-for-hivepress' ); ?></p>

			<form method="post" action="" id="curiouswp-li-mapping-form">
				<?php wp_nonce_field( 'curiouswp_li_process_action', 'curiouswp_li_process_nonce' ); ?>

				<table class="curiouswp-li-mapping-table">
					<thead>
						<tr>
							<th style="width: 40%;"><?php esc_html_e( 'HivePress Field / Attribute', 'curiouswp-listing-importer-for-hivepress' ); ?></th>
							<th style="width: 60%;"><?php esc_html_e( 'CSV Column', 'curiouswp-listing-importer-for-hivepress' ); ?></th>
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
										<span style="color: #d63638;" title="<?php esc_attr_e( 'Required', 'curiouswp-listing-importer-for-hivepress' ); ?>">*</span>
									<?php endif; ?>
									<?php if ( $desc ) : ?>
										<div class="curiouswp-li-field-desc"><?php echo esc_html( $desc ); ?></div>
									<?php endif; ?>
								</td>
								<td>
									<select name="mapping[<?php echo esc_attr( $field_key ); ?>]" class="curiouswp-li-col-select" <?php echo $is_req ? 'required' : ''; ?>>
										<option value=""><?php esc_html_e( '— None (Do not import) —', 'curiouswp-listing-importer-for-hivepress' ); ?></option>
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
					<?php submit_button( __( 'Start Import Process Now 🚀', 'curiouswp-listing-importer-for-hivepress' ), 'primary', 'submit', false ); ?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&step=1' ) ); ?>" class="button button-secondary" style="margin-left: 10px;">
						<?php esc_html_e( '← Back / Cancel', 'curiouswp-listing-importer-for-hivepress' ); ?>
					</a>
				</div>
			</form>

			<div id="curiouswp-li-progress" hidden>
				<div class="curiouswp-li-progress"><div class="curiouswp-li-progress-bar"></div></div>
				<p class="curiouswp-li-progress-text"></p>
				<p class="curiouswp-li-progress-stats">
					<strong style="color: #00a32a;"><?php esc_html_e( 'Created:', 'curiouswp-listing-importer-for-hivepress' ); ?> <span data-stat="created">0</span></strong>
					<strong style="color: #2271b1;"><?php esc_html_e( 'Updated:', 'curiouswp-listing-importer-for-hivepress' ); ?> <span data-stat="updated">0</span></strong>
					<strong style="color: #d63638;"><?php esc_html_e( 'Failed:', 'curiouswp-listing-importer-for-hivepress' ); ?> <span data-stat="failed">0</span></strong>
				</p>
				<p class="description"><?php esc_html_e( 'Keep this page open until the import finishes.', 'curiouswp-listing-importer-for-hivepress' ); ?></p>
				<div class="curiouswp-li-error-box" hidden>
					<h3 style="margin-top: 0; color: #d63638;"><?php esc_html_e( 'Import Warnings & Errors Log:', 'curiouswp-listing-importer-for-hivepress' ); ?></h3>
					<ul style="list-style: disc; margin-left: 20px;"></ul>
				</div>
				<p class="curiouswp-li-progress-actions" hidden>
					<button type="button" class="button button-primary" id="curiouswp-li-retry"><?php esc_html_e( 'Retry / Resume Import', 'curiouswp-listing-importer-for-hivepress' ); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Step 3: Import Results and Error Summary.
	 */
	protected static function render_step_3_complete() {
		$result = get_transient( 'curiouswp_li_result_' . get_current_user_id() );
		delete_transient( 'curiouswp_li_result_' . get_current_user_id() );

		if ( empty( $result ) ) {
			?>
			<div class="curiouswp-li-card">
				<h2><?php esc_html_e( 'No recent import results found.', 'curiouswp-listing-importer-for-hivepress' ); ?></h2>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG ) ); ?>" class="button button-primary"><?php esc_html_e( 'Start New Import', 'curiouswp-listing-importer-for-hivepress' ); ?></a>
			</div>
			<?php
			return;
		}

		if ( empty( $result['success'] ) ) {
			?>
			<div class="curiouswp-li-card">
				<h2><?php esc_html_e( 'Import Failed', 'curiouswp-listing-importer-for-hivepress' ); ?></h2>
				<p><?php echo esc_html( isset( $result['message'] ) ? $result['message'] : __( 'Unknown error.', 'curiouswp-listing-importer-for-hivepress' ) ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&step=2' ) ); ?>" class="button button-primary"><?php esc_html_e( '← Back to Mapping', 'curiouswp-listing-importer-for-hivepress' ); ?></a>
			</div>
			<?php
			return;
		}

		$stats = isset( $result['stats'] ) ? $result['stats'] : [];
		?>
		<div class="curiouswp-li-card">
			<h2><span class="curiouswp-li-step-badge" style="background: #00a32a;">✓</span> <?php esc_html_e( 'Import Process Completed', 'curiouswp-listing-importer-for-hivepress' ); ?></h2>

			<table class="widefat" style="max-width: 600px; margin-top: 15px;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Total CSV Rows Processed:', 'curiouswp-listing-importer-for-hivepress' ); ?></strong></td>
						<td><?php echo esc_html( isset( $stats['total_rows'] ) ? (string) $stats['total_rows'] : '0' ); ?></td>
					</tr>
					<tr>
						<td><strong style="color: #00a32a;"><?php esc_html_e( 'New Listings Created:', 'curiouswp-listing-importer-for-hivepress' ); ?></strong></td>
						<td><strong><?php echo esc_html( isset( $stats['created'] ) ? (string) $stats['created'] : '0' ); ?></strong></td>
					</tr>
					<tr>
						<td><strong style="color: #2271b1;"><?php esc_html_e( 'Existing Listings Updated:', 'curiouswp-listing-importer-for-hivepress' ); ?></strong></td>
						<td><strong><?php echo esc_html( isset( $stats['updated'] ) ? (string) $stats['updated'] : '0' ); ?></strong></td>
					</tr>
					<tr>
						<td><strong style="color: #d63638;"><?php esc_html_e( 'Failed Rows:', 'curiouswp-listing-importer-for-hivepress' ); ?></strong></td>
						<td><?php echo esc_html( isset( $stats['failed'] ) ? (string) $stats['failed'] : '0' ); ?></td>
					</tr>
				</tbody>
			</table>

			<?php if ( ! empty( $stats['errors'] ) ) : ?>
				<div class="curiouswp-li-error-box">
					<h3 style="margin-top: 0; color: #d63638;"><?php esc_html_e( 'Import Warnings & Errors Log:', 'curiouswp-listing-importer-for-hivepress' ); ?></h3>
					<ul style="list-style: disc; margin-left: 20px;">
						<?php foreach ( $stats['errors'] as $row_num => $row_errors ) : ?>
							<li>
								<strong>
									<?php
									printf(
										/* translators: %d: CSV row number. */
										esc_html__( 'Row #%d:', 'curiouswp-listing-importer-for-hivepress' ),
										absint( $row_num )
									);
									?>
								</strong>
								<?php echo esc_html( implode( ' | ', (array) $row_errors ) ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<div style="margin-top: 25px;">
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=hp_listing' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'View All Listings', 'curiouswp-listing-importer-for-hivepress' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&step=1' ) ); ?>" class="button button-secondary" style="margin-left: 10px;">
					<?php esc_html_e( 'Import Another File', 'curiouswp-listing-importer-for-hivepress' ); ?>
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
		<div class="curiouswp-li-card">
			<h2><?php esc_html_e( 'Export Listings to CSV', 'curiouswp-listing-importer-for-hivepress' ); ?></h2>
			<p><?php esc_html_e( 'Download all listings, including titles, descriptions, categories, image URLs, and custom attributes.', 'curiouswp-listing-importer-for-hivepress' ); ?></p>

			<form method="post" action="">
				<?php wp_nonce_field( 'curiouswp_li_export_action', 'curiouswp_li_export_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="export_category_id"><?php esc_html_e( 'Category Filter', 'curiouswp-listing-importer-for-hivepress' ); ?></label></th>
						<td>
							<select name="category_id" id="export_category_id">
								<option value=""><?php esc_html_e( '— All Categories —', 'curiouswp-listing-importer-for-hivepress' ); ?></option>
								<?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
									<?php foreach ( $categories as $cat ) : ?>
										<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
									<?php endforeach; ?>
								<?php endif; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="export_delimiter"><?php esc_html_e( 'CSV Delimiter', 'curiouswp-listing-importer-for-hivepress' ); ?></label></th>
						<td>
							<input type="text" name="delimiter" id="export_delimiter" value="," class="small-text" maxlength="1" required />
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Export Listings to CSV 📥', 'curiouswp-listing-importer-for-hivepress' ), 'primary' ); ?>
			</form>
		</div>
		<?php
	}
}
