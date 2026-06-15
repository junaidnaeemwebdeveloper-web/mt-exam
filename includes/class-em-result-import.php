<?php
/**
 * Bulk CSV import for exam results.
 *
 * @package ExamManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin CSV import for em_result posts.
 */
class EM_Result_Import {

	const PAGE_SLUG     = 'em-import-results';
	const NONCE_ACTION  = 'em_result_import';
	const NONCE_FIELD   = 'em_result_import_nonce';
	const REQUIRED_COLS = array( 'student_id', 'exam_id', 'mark' );

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_import_request' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_sample_download' ) );
	}

	/**
	 * Register the import page under Results.
	 */
	public static function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=' . EM_Result_Admin::POST_TYPE,
			__( 'Import Results', 'exam-management' ),
			__( 'Import CSV', 'exam-management' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Process CSV upload requests.
	 */
	public static function handle_import_request() {
		if ( ! isset( $_POST['em_result_import_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to import results.', 'exam-management' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		if ( empty( $_FILES['em_result_csv']['name'] ) ) {
			self::redirect_with_notice( 'error', __( 'Please choose a CSV file to import.', 'exam-management' ) );
		}

		$file = $_FILES['em_result_csv'];

		if ( ! empty( $file['error'] ) ) {
			self::redirect_with_notice( 'error', __( 'The uploaded file could not be processed.', 'exam-management' ) );
		}

		$file_type = wp_check_filetype( $file['name'], array( 'csv' => 'text/csv' ) );
		if ( 'csv' !== $file_type['ext'] ) {
			self::redirect_with_notice( 'error', __( 'Only CSV files are allowed.', 'exam-management' ) );
		}

		$import_result = self::import_csv_file( $file['tmp_name'] );

		set_transient(
			'em_result_import_report_' . get_current_user_id(),
			$import_result,
			60
		);

		$status  = empty( $import_result['errors'] ) ? 'success' : 'warning';
		$message = self::build_summary_message( $import_result );

		self::redirect_with_notice( $status, $message );
	}

	/**
	 * Import rows from a CSV file.
	 *
	 * @param string $file_path Path to the uploaded CSV file.
	 * @return array
	 */
	public static function import_csv_file( $file_path ) {
		$report = array(
			'imported_rows' => 0,
			'created_results' => 0,
			'updated_results' => 0,
			'exams_processed' => 0,
			'errors'          => array(),
		);

		$rows = self::parse_csv_file( $file_path, $report['errors'] );

		if ( empty( $rows ) ) {
			if ( empty( $report['errors'] ) ) {
				$report['errors'][] = __( 'The CSV file does not contain any importable rows.', 'exam-management' );
			}
			return $report;
		}

		$grouped_rows = array();

		foreach ( $rows as $row_number => $row ) {
			$validation = self::validate_row( $row, $row_number );

			if ( is_wp_error( $validation ) ) {
				$report['errors'][] = $validation->get_error_message();
				continue;
			}

			$exam_id    = (int) $row['exam_id'];
			$student_id = (int) $row['student_id'];

			if ( ! isset( $grouped_rows[ $exam_id ] ) ) {
				$grouped_rows[ $exam_id ] = array();
			}

			$grouped_rows[ $exam_id ][ $student_id ] = EM_Result_Admin::sanitize_mark( $row['mark'] );
			$report['imported_rows']++;
		}

		if ( empty( $grouped_rows ) ) {
			return $report;
		}

		foreach ( $grouped_rows as $exam_id => $marks ) {
			$save_result = self::save_exam_results( $exam_id, $marks );

			if ( is_wp_error( $save_result ) ) {
				$report['errors'][] = $save_result->get_error_message();
				continue;
			}

			$report['exams_processed']++;

			if ( 'created' === $save_result['action'] ) {
				$report['created_results']++;
			} else {
				$report['updated_results']++;
			}
		}

		if ( $report['imported_rows'] > 0 && class_exists( 'EM_Top_Students_Shortcode' ) ) {
			EM_Top_Students_Shortcode::clear_cache();
		}

		return $report;
	}

	/**
	 * Parse a CSV file into normalized rows.
	 *
	 * @param string $file_path CSV file path.
	 * @param array  $errors    Error collection passed by reference.
	 * @return array
	 */
	private static function parse_csv_file( $file_path, array &$errors ) {
		$handle = fopen( $file_path, 'r' );

		if ( false === $handle ) {
			$errors[] = __( 'Unable to open the uploaded CSV file.', 'exam-management' );
			return array();
		}

		$header = fgetcsv( $handle );

		if ( empty( $header ) ) {
			fclose( $handle );
			$errors[] = __( 'The CSV file is missing a header row.', 'exam-management' );
			return array();
		}

		$header_map = self::normalize_header( $header );
		$missing    = array_diff( self::REQUIRED_COLS, array_keys( $header_map ) );

		if ( ! empty( $missing ) ) {
			fclose( $handle );
			$errors[] = sprintf(
				/* translators: %s: comma-separated column names */
				__( 'The CSV file must include the following columns: %s', 'exam-management' ),
				implode( ', ', self::REQUIRED_COLS )
			);
			return array();
		}

		$rows      = array();
		$row_index = 1;

		while ( false !== ( $data = fgetcsv( $handle ) ) ) {
			$row_index++;

			if ( self::is_empty_row( $data ) ) {
				continue;
			}

			$row = array(
				'student_id' => isset( $header_map['student_id'] ) ? trim( (string) $data[ $header_map['student_id'] ] ) : '',
				'exam_id'    => isset( $header_map['exam_id'] ) ? trim( (string) $data[ $header_map['exam_id'] ] ) : '',
				'mark'       => isset( $header_map['mark'] ) ? trim( (string) $data[ $header_map['mark'] ] ) : '',
			);

			$rows[ $row_index ] = $row;
		}

		fclose( $handle );

		return $rows;
	}

	/**
	 * Normalize CSV header names.
	 *
	 * @param array $header Raw header row.
	 * @return array
	 */
	private static function normalize_header( array $header ) {
		$map = array();

		foreach ( $header as $index => $column ) {
			$key = strtolower( trim( (string) $column ) );
			if ( '' !== $key ) {
				$map[ $key ] = $index;
			}
		}

		return $map;
	}

	/**
	 * Determine whether a CSV row is empty.
	 *
	 * @param array $data CSV row.
	 * @return bool
	 */
	private static function is_empty_row( array $data ) {
		foreach ( $data as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate a parsed CSV row.
	 *
	 * @param array $row        Parsed row.
	 * @param int   $row_number CSV row number.
	 * @return true|WP_Error
	 */
	private static function validate_row( array $row, $row_number ) {
		$student_id = absint( $row['student_id'] );
		$exam_id    = absint( $row['exam_id'] );

		if ( ! $student_id || ! $exam_id || '' === $row['mark'] ) {
			return new WP_Error(
				'em_result_import_missing_values',
				sprintf(
					/* translators: %d: CSV row number */
					__( 'Row %d is missing student_id, exam_id, or mark.', 'exam-management' ),
					$row_number
				)
			);
		}

		if ( EM_Result_Admin::STUDENT_POST_TYPE !== get_post_type( $student_id ) || 'publish' !== get_post_status( $student_id ) ) {
			return new WP_Error(
				'em_result_import_invalid_student',
				sprintf(
					/* translators: 1: CSV row number, 2: student ID */
					__( 'Row %1$d contains an invalid student ID (%2$d).', 'exam-management' ),
					$row_number,
					$student_id
				)
			);
		}

		if ( EM_Result_Admin::EXAM_POST_TYPE !== get_post_type( $exam_id ) || 'publish' !== get_post_status( $exam_id ) ) {
			return new WP_Error(
				'em_result_import_invalid_exam',
				sprintf(
					/* translators: 1: CSV row number, 2: exam ID */
					__( 'Row %1$d contains an invalid exam ID (%2$d).', 'exam-management' ),
					$row_number,
					$exam_id
				)
			);
		}

		$mark = EM_Result_Admin::sanitize_mark( $row['mark'] );
		if ( $mark < 0 || $mark > EM_Result_Admin::MAX_MARK ) {
			return new WP_Error(
				'em_result_import_invalid_mark',
				sprintf(
					/* translators: 1: CSV row number, 2: maximum mark */
					__( 'Row %1$d contains a mark outside the allowed 0-%2$d range.', 'exam-management' ),
					$row_number,
					EM_Result_Admin::MAX_MARK
				)
			);
		}

		return true;
	}

	/**
	 * Create or update a result post for an exam.
	 *
	 * @param int               $exam_id Exam post ID.
	 * @param array<int, float> $marks   Student marks keyed by student ID.
	 * @return array|WP_Error
	 */
	private static function save_exam_results( $exam_id, array $marks ) {
		$result_id      = EM_Result_Admin::get_result_id_for_exam( $exam_id );
		$existing_marks = array();

		if ( $result_id ) {
			$stored = get_post_meta( $result_id, EM_Result_Admin::META_MARKS, true );
			if ( is_array( $stored ) ) {
				$existing_marks = $stored;
			}
		}

		$merged_marks = EM_Result_Admin::merge_student_marks( $existing_marks, $marks );
		$merged_marks = self::filter_valid_student_marks( $merged_marks );

		if ( empty( $merged_marks ) ) {
			return new WP_Error(
				'em_result_import_empty_marks',
				sprintf(
					/* translators: %d: exam ID */
					__( 'No valid marks were available to save for exam ID %d.', 'exam-management' ),
					$exam_id
				)
			);
		}

		if ( $result_id ) {
			update_post_meta( $result_id, EM_Result_Admin::META_MARKS, $merged_marks );
			wp_update_post(
				array(
					'ID'          => $result_id,
					'post_status' => 'publish',
				)
			);

			return array(
				'action'    => 'updated',
				'result_id' => $result_id,
			);
		}

		$result_id = wp_insert_post(
			array(
				'post_type'   => EM_Result_Admin::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => sprintf(
					/* translators: %s: exam title */
					__( 'Results for %s', 'exam-management' ),
					get_the_title( $exam_id )
				),
			),
			true
		);

		if ( is_wp_error( $result_id ) ) {
			return $result_id;
		}

		update_post_meta( $result_id, EM_Result_Admin::META_EXAM_ID, $exam_id );
		update_post_meta( $result_id, EM_Result_Admin::META_MARKS, $merged_marks );

		return array(
			'action'    => 'created',
			'result_id' => $result_id,
		);
	}

	/**
	 * Keep only marks for valid published students.
	 *
	 * @param array $marks Student marks keyed by student ID.
	 * @return array<int, float>
	 */
	private static function filter_valid_student_marks( array $marks ) {
		if ( empty( $marks ) ) {
			return array();
		}

		$student_ids = array_map( 'intval', array_keys( $marks ) );
		$valid_posts = get_posts(
			array(
				'post_type'              => EM_Result_Admin::STUDENT_POST_TYPE,
				'post_status'            => 'publish',
				'post__in'               => $student_ids,
				'posts_per_page'         => count( $student_ids ),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$valid_ids = array_flip( array_map( 'intval', $valid_posts ) );
		$filtered  = array();

		foreach ( $marks as $student_id => $mark ) {
			$student_id = (int) $student_id;
			if ( isset( $valid_ids[ $student_id ] ) ) {
				$filtered[ $student_id ] = $mark;
			}
		}

		return $filtered;
	}

	/**
	 * Download a sample CSV generated from the current site data.
	 */
	public static function handle_sample_download() {
		if ( empty( $_GET['em_download_sample_csv'] ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to download the sample CSV.', 'exam-management' ) );
		}

		check_admin_referer( 'em_download_sample_csv' );

		$students = get_posts(
			array(
				'post_type'      => EM_Result_Admin::STUDENT_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 3,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$exams = get_posts(
			array(
				'post_type'      => EM_Result_Admin::EXAM_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$exam_id = ! empty( $exams ) ? (int) $exams[0]->ID : 0;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=sample-results-import.csv' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, self::REQUIRED_COLS );

		if ( ! empty( $students ) && $exam_id ) {
			$sample_marks = array( 88, 76.5, 92 );

			foreach ( $students as $index => $student ) {
				fputcsv(
					$output,
					array(
						$student->ID,
						$exam_id,
						$sample_marks[ $index ] ?? 75,
					)
				);
			}
		} else {
			fputcsv( $output, array( 101, 201, 88 ) );
			fputcsv( $output, array( 102, 201, 76.5 ) );
			fputcsv( $output, array( 103, 201, 92 ) );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Render the import admin page.
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'exam-management' ) );
		}

		$notice   = self::get_notice_from_request();
		$report   = get_transient( 'em_result_import_report_' . get_current_user_id() );
		$students = self::get_reference_students();
		$exams    = self::get_reference_exams();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Results from CSV', 'exam-management' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( is_array( $report ) ) : ?>
				<div class="card em-result-import-report">
					<h2><?php esc_html_e( 'Import Summary', 'exam-management' ); ?></h2>
					<ul>
						<li><?php echo esc_html( sprintf( __( 'Rows imported: %d', 'exam-management' ), (int) $report['imported_rows'] ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Exams processed: %d', 'exam-management' ), (int) $report['exams_processed'] ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Results created: %d', 'exam-management' ), (int) $report['created_results'] ) ); ?></li>
						<li><?php echo esc_html( sprintf( __( 'Results updated: %d', 'exam-management' ), (int) $report['updated_results'] ) ); ?></li>
					</ul>
					<?php if ( ! empty( $report['errors'] ) ) : ?>
						<h3><?php esc_html_e( 'Row Errors', 'exam-management' ); ?></h3>
						<ul class="em-result-import-errors">
							<?php foreach ( $report['errors'] as $error ) : ?>
								<li><?php echo esc_html( $error ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
				<?php delete_transient( 'em_result_import_report_' . get_current_user_id() ); ?>
			<?php endif; ?>

			<div class="card">
				<h2><?php esc_html_e( 'CSV Format', 'exam-management' ); ?></h2>
				<p><?php esc_html_e( 'Upload a CSV file with one mark per student per exam. Rows with the same exam ID are grouped into a single result record.', 'exam-management' ); ?></p>
				<p><?php esc_html_e( 'Use the WordPress post IDs shown below, not row numbers from the admin list.', 'exam-management' ); ?></p>
				<p><code>student_id,exam_id,mark</code></p>
				<p>
					<a class="button button-secondary" href="<?php echo esc_url( self::get_sample_csv_download_url() ); ?>">
						<?php esc_html_e( 'Download Sample CSV', 'exam-management' ); ?>
					</a>
				</p>
			</div>

			<div class="card em-result-import-reference">
				<h2><?php esc_html_e( 'Available IDs', 'exam-management' ); ?></h2>
				<style>
					.em-result-import-reference__grid {
						display: grid;
						gap: 1.5rem;
						grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
					}
				</style>
				<div class="em-result-import-reference__grid">
					<div>
						<h3><?php esc_html_e( 'Students', 'exam-management' ); ?></h3>
						<?php if ( empty( $students ) ) : ?>
							<p><?php esc_html_e( 'No published students found.', 'exam-management' ); ?></p>
						<?php else : ?>
							<table class="widefat striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'ID', 'exam-management' ); ?></th>
										<th><?php esc_html_e( 'Name', 'exam-management' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $students as $student ) : ?>
										<tr>
											<td><?php echo esc_html( (string) $student->ID ); ?></td>
											<td><?php echo esc_html( $student->post_title ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
					<div>
						<h3><?php esc_html_e( 'Exams', 'exam-management' ); ?></h3>
						<?php if ( empty( $exams ) ) : ?>
							<p><?php esc_html_e( 'No published exams found.', 'exam-management' ); ?></p>
						<?php else : ?>
							<table class="widefat striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'ID', 'exam-management' ); ?></th>
										<th><?php esc_html_e( 'Title', 'exam-management' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $exams as $exam ) : ?>
										<tr>
											<td><?php echo esc_html( (string) $exam->ID ); ?></td>
											<td><?php echo esc_html( $exam->post_title ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<form method="post" enctype="multipart/form-data" class="card em-result-import-form">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="em_result_csv"><?php esc_html_e( 'CSV File', 'exam-management' ); ?></label>
							</th>
							<td>
								<input type="file" name="em_result_csv" id="em_result_csv" accept=".csv,text/csv" required />
								<p class="description">
									<?php
									printf(
										/* translators: %d: maximum mark value */
										esc_html__( 'Required columns: student_id, exam_id, mark (0-%d).', 'exam-management' ),
										EM_Result_Admin::MAX_MARK
									);
									?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Import Results', 'exam-management' ), 'primary', 'em_result_import_submit' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Get published students for the reference table.
	 *
	 * @return WP_Post[]
	 */
	private static function get_reference_students() {
		return get_posts(
			array(
				'post_type'      => EM_Result_Admin::STUDENT_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Get published exams for the reference table.
	 *
	 * @return WP_Post[]
	 */
	private static function get_reference_exams() {
		return get_posts(
			array(
				'post_type'      => EM_Result_Admin::EXAM_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Get the admin URL for the sample CSV download.
	 *
	 * @return string
	 */
	public static function get_sample_csv_download_url() {
		return wp_nonce_url(
			add_query_arg(
				array(
					'post_type'              => EM_Result_Admin::POST_TYPE,
					'page'                   => self::PAGE_SLUG,
					'em_download_sample_csv' => '1',
				),
				admin_url( 'edit.php' )
			),
			'em_download_sample_csv'
		);
	}

	/**
	 * Build a summary message from an import report.
	 *
	 * @param array $report Import report.
	 * @return string
	 */
	private static function build_summary_message( array $report ) {
		if ( empty( $report['imported_rows'] ) && ! empty( $report['errors'] ) ) {
			return __( 'Import failed. Review the errors below.', 'exam-management' );
		}

		return sprintf(
			/* translators: 1: imported rows, 2: exams processed */
			__( 'Import completed: %1$d rows imported across %2$d exams.', 'exam-management' ),
			(int) $report['imported_rows'],
			(int) $report['exams_processed']
		);
	}

	/**
	 * Redirect back to the import page with an admin notice.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Notice message.
	 */
	private static function redirect_with_notice( $type, $message ) {
		$url = add_query_arg(
			array(
				'post_type'          => EM_Result_Admin::POST_TYPE,
				'page'               => self::PAGE_SLUG,
				'em_import_notice'   => $type,
				'em_import_message'  => rawurlencode( $message ),
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Read import notice data from the query string.
	 *
	 * @return array|null
	 */
	private static function get_notice_from_request() {
		if ( empty( $_GET['em_import_notice'] ) || empty( $_GET['em_import_message'] ) ) {
			return null;
		}

		$type = sanitize_key( wp_unslash( $_GET['em_import_notice'] ) );

		if ( ! in_array( $type, array( 'success', 'warning', 'error' ), true ) ) {
			return null;
		}

		return array(
			'type'    => $type,
			'message' => sanitize_text_field( wp_unslash( rawurldecode( $_GET['em_import_message'] ) ) ),
		);
	}
}
