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
		' );
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

			$mappings = ! empty( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? $_POST['mapping'] : [];

			$result = Importer::process( [
				'file_path'   => $session['file_path'],
				'delimiter'   => $session['delimiter'],
				'mode'        => $session['mode'],
				'category_id' => $session['category_id'],
				'mappings'    => $mappings,
			] );

			// Clean up imported file
			if ( file_exists( $session['file_path'] ) ) {
				@unlink( $session['file_path'] );
			}
			delete_transient( 'hp_import_session_' . get_current_user_id() );

			// Store result for Step 3 summary
			set_transient( 'hp_import_result_' . get_current_user_id(), $result, HOUR_IN_SECONDS );

			wp_safe_redirect( admin_url( 'edit.php?post_type=hp_listing&page=' . self::PAGE_SLUG . '&step=3' ) );
			exit;
		}

		// Action 3: AJAX Batch Chunk Processing (Step 2 -> Progress)
		if ( ! empty( $_POST['hp_pb_ajax_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['hp_pb_ajax_nonce'] ), 'hp_pb_ajax_action' ) ) {
			$session = get_transient( 'hp_import_session_' . get_current_user_id() );
			if ( ! $session || ! file_exists( $session['file_path'] ) ) {
				wp_send_json_error( [ 'message' => 'Upload session expired' ] );
				wp_die();
			}

			$chunk_size = ! empty( $_POST['chunk_size'] ) ? absint( $_POST['chunk_size'] ) : 10;
			$start_row  = ! empty( $_POST['start_row'] ) ? absint( $_POST['start_row'] ) : 1;

			$result = Importer::process_chunk( [
				'file_path'   => $session['file_path'],
				'delimiter'   => $session['delimiter'],
				'mode'        => $session['mode'],
				'category_id' => $session['category_id'],
				'mappings'    => ! empty( $_POST['mapping'] ) ? $_POST['mapping'] : [],
				'start_row'   => $start_row,
				'chunk_size'  => $chunk_size,
			] );

			// Update session with new progress
			$session['current_row'] = $result['current_row'];
			$session['total_rows']  = $result['total_rows'];
			$session['created']     = $result['created'] + ( $session['created'] ?? 0 );
			$session['updated']     = $result['updated'] + ( $session['updated'] ?? 0 );
			$session['failed']      = $result['failed'] + ( $session['failed'] ?? 0 );
			set_transient( 'hp_import_session_' . get_current_user_id(), $session, HOUR_IN_SECONDS );

			wp_send_json_success( $result );
			wp_die();
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
		?>
		<div class="hp-card">
			<h2><span class="hp-step-badge"><?php esc_html_e( 'Step 2', 'pb-hivepress-import' ); ?></span> <?php esc_html_e( 'Map Columns', 'pb-hivepress-import' ); ?></h2>
			<p><?php esc_html_e( 'For each HivePress listing field below, choose which column from your CSV file contains the data.', 'pb-hivepress-import' ); ?></p>

			<form method="post" action="">
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
									<select name="mapping[<?php echo esc_attr( $field_key ); ?>]" class="hp-col-select">
										<option value="none"><?php esc_html_e( '— None (Do not import) —', 'pb-hivepress-import' ); ?></option>
										<?php
										$has_selected = false;
										foreach ( $columns as $idx => $raw_col ) :
											$clean_col_name = strtolower( trim( $raw_col ) );
											$readable_label = Importer::format_column_label( $raw_col );

											// Smart auto-detection between CSV column and HivePress field
											$is_match = false;
											if (
												$clean_col_name === strtolower( $field_key ) ||
												$clean_col_name === strtolower( $label ) ||
												( 'title' === $field_key && in_array( $clean_col_name, [ 'name', 'title', 'listing_title', 'company_name' ], true ) ) ||
												( 'description' === $field_key && in_array( $clean_col_name, [ 'description', 'website_description', 'about', 'content', 'details' ], true ) ) ||
												( 'images' === $field_key && in_array( $clean_col_name, [ 'images', 'image', 'photo', 'photos', 'picture' ], true ) ) ||
												( 'categories' === $field_key && in_array( $clean_col_name, [ 'categories', 'category', 'subtypes', 'industry' ], true ) ) ||
												( 'id' === $field_key && in_array( $clean_col_name, [ 'id', 'listing_id', 'post_id' ], true ) ) ||
												( 'user' === $field_key && in_array( $clean_col_name, [ 'user', 'author', 'email', 'username' ], true ) )
											) {
												$is_match = true;
											}

											$selected = false;
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
