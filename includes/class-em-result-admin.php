<?php
/**
 * Admin UI and storage for exam result metadata.
 *
 * @package ExamManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles em_result admin fields and validation.
 */
class EM_Result_Admin {

	const POST_TYPE        = 'em_result';
	const EXAM_POST_TYPE   = 'em_exam';
	const STUDENT_POST_TYPE = 'em_student';
	const META_EXAM_ID     = 'em_result_exam_id';
	const META_MARKS       = 'em_result_marks';
	const META_BOX_ID      = 'em_result_details';
	const NONCE_ACTION     = 'em_result_save';
	const NONCE_FIELD      = 'em_result_nonce';
	const MAX_MARK         = 100;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_meta' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_result' ) );

		add_filter( 'wp_insert_post_data', array( __CLASS__, 'validate_before_save' ), 10, 2 );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor' ), 10, 2 );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_list_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_list_column' ), 10, 2 );

		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Use the classic editor so meta box validation runs on standard post save.
	 *
	 * @param bool   $use_block_editor Whether the block editor is enabled.
	 * @param string $post_type        Post type slug.
	 * @return bool
	 */
	public static function disable_block_editor( $use_block_editor, $post_type ) {
		if ( self::POST_TYPE === $post_type ) {
			return false;
		}

		return $use_block_editor;
	}

	/**
	 * Register post meta for REST and sanitization.
	 */
	public static function register_post_meta() {
		register_post_meta(
			self::POST_TYPE,
			self::META_EXAM_ID,
			array(
				'type'              => 'integer',
				'description'       => __( 'Linked exam post ID.', 'exam-management' ),
				'single'            => true,
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_MARKS,
			array(
				'type'              => 'object',
				'description'       => __( 'Student marks keyed by student post ID.', 'exam-management' ),
				'single'            => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_marks_meta' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => array(
							'type' => 'number',
						),
					),
				),
			)
		);
	}

	/**
	 * Register the result details meta box.
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			self::META_BOX_ID,
			__( 'Exam Results', 'exam-management' ),
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render result fields.
	 *
	 * @param WP_Post $post Current result post.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$exam_id     = (int) get_post_meta( $post->ID, self::META_EXAM_ID, true );
		$saved_marks = get_post_meta( $post->ID, self::META_MARKS, true );
		$saved_marks = is_array( $saved_marks ) ? $saved_marks : array();

		$exams    = self::get_exams( $post->ID );
		$students = self::get_students();
		$subject  = $exam_id ? self::get_exam_subject_name( $exam_id ) : '';
		$is_edit  = $exam_id > 0;
		?>
		<div class="em-result-admin">
			<table class="form-table em-result-details-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="em_result_exam_id"><?php esc_html_e( 'Exam', 'exam-management' ); ?></label>
						</th>
						<td>
							<?php if ( $is_edit ) : ?>
								<strong><?php echo esc_html( get_the_title( $exam_id ) ); ?></strong>
								<input type="hidden" name="em_result_exam_id" id="em_result_exam_id" value="<?php echo esc_attr( $exam_id ); ?>" />
							<?php else : ?>
								<select name="em_result_exam_id" id="em_result_exam_id" required>
									<option value=""><?php esc_html_e( 'Select an exam', 'exam-management' ); ?></option>
									<?php foreach ( $exams as $exam ) : ?>
										<?php
										$exam_subject = self::get_exam_subject_name( $exam->ID );
										$label          = $exam_subject
											? sprintf( '%s (%s)', $exam->post_title, $exam_subject )
											: $exam->post_title;
										?>
										<option
											value="<?php echo esc_attr( $exam->ID ); ?>"
											data-subject="<?php echo esc_attr( $exam_subject ); ?>"
										>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Subject', 'exam-management' ); ?></th>
						<td>
							<span id="em_result_subject_name">
								<?php echo $subject ? esc_html( $subject ) : esc_html__( 'Select an exam to view its subject.', 'exam-management' ); ?>
							</span>
						</td>
					</tr>
				</tbody>
			</table>

			<h3><?php esc_html_e( 'Student Marks', 'exam-management' ); ?></h3>
			<p class="description">
				<?php
				printf(
					/* translators: %d: maximum mark value */
					esc_html__( 'Enter marks out of %d for each student. Leave blank for students who did not take this exam.', 'exam-management' ),
					self::MAX_MARK
				);
				?>
			</p>

			<?php if ( empty( $students ) ) : ?>
				<p><?php esc_html_e( 'No students found. Please add students before recording results.', 'exam-management' ); ?></p>
			<?php else : ?>
				<table class="widefat striped em-result-marks-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Student', 'exam-management' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Mark (out of 100)', 'exam-management' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $students as $student ) : ?>
							<?php
							$student_mark = isset( $saved_marks[ $student->ID ] )
								? $saved_marks[ $student->ID ]
								: '';
							?>
							<tr>
								<td>
									<label for="em_result_mark_<?php echo esc_attr( $student->ID ); ?>">
										<?php echo esc_html( $student->post_title ); ?>
									</label>
								</td>
								<td>
									<input
										type="number"
										name="em_result_marks[<?php echo esc_attr( $student->ID ); ?>]"
										id="em_result_mark_<?php echo esc_attr( $student->ID ); ?>"
										value="<?php echo esc_attr( $student_mark ); ?>"
										min="0"
										max="<?php echo esc_attr( self::MAX_MARK ); ?>"
										step="0.01"
										class="small-text em-result-mark-input"
									/>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Validate result data before WordPress saves the post.
	 *
	 * @param array $data    Slashed post data.
	 * @param array $postarr Raw post data from the request.
	 * @return array
	 */
	public static function validate_before_save( $data, $postarr ) {
		if ( self::POST_TYPE !== $data['post_type'] || ! self::should_validate_request() ) {
			return $data;
		}

		if ( ! self::verify_save_nonce() ) {
			return $data;
		}

		$post_id          = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$validation_error = self::validate_result_data( self::get_submitted_data(), $post_id );

		if ( is_wp_error( $validation_error ) ) {
			self::set_validation_notice( $validation_error->get_error_message() );

			if ( in_array( $data['post_status'], array( 'publish', 'future' ), true ) ) {
				$data['post_status'] = 'draft';
			}
		} elseif ( empty( $data['post_title'] ) ) {
			$exam_id = absint( self::get_submitted_data()['exam_id'] );
			if ( $exam_id ) {
				$data['post_title'] = sprintf(
					/* translators: %s: exam title */
					__( 'Results for %s', 'exam-management' ),
					get_the_title( $exam_id )
				);
			}
		}

		return $data;
	}

	/**
	 * Save result metadata.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function save_result( $post_id ) {
		if ( ! self::verify_save_nonce() ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$data             = self::get_submitted_data();
		$validation_error = self::validate_result_data( $data, $post_id );

		if ( is_wp_error( $validation_error ) ) {
			self::set_validation_notice( $validation_error->get_error_message() );
			return;
		}

		update_post_meta( $post_id, self::META_EXAM_ID, $data['exam_id'] );
		update_post_meta( $post_id, self::META_MARKS, $data['marks'] );

		if ( empty( get_post_field( 'post_title', $post_id ) ) ) {
			remove_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_result' ) );
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => sprintf(
						/* translators: %s: exam title */
						__( 'Results for %s', 'exam-management' ),
						get_the_title( $data['exam_id'] )
					),
				)
			);
			add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_result' ) );
		}

		delete_transient( 'em_result_admin_notice_' . get_current_user_id() );
	}

	/**
	 * Read submitted result data from the request.
	 *
	 * @return array{
	 *     exam_id: int,
	 *     marks: array<int, float>
	 * }
	 */
	private static function get_submitted_data() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified before this method is used for saving.
		$exam_id = isset( $_POST['em_result_exam_id'] ) ? absint( wp_unslash( $_POST['em_result_exam_id'] ) ) : 0;
		$marks   = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified before this method is used for saving.
		if ( isset( $_POST['em_result_marks'] ) && is_array( $_POST['em_result_marks'] ) ) {
			foreach ( wp_unslash( $_POST['em_result_marks'] ) as $student_id => $mark ) {
				$student_id = absint( $student_id );
				$mark       = is_string( $mark ) ? trim( $mark ) : $mark;

				if ( '' === $mark || null === $mark ) {
					continue;
				}

				$marks[ $student_id ] = self::sanitize_mark( $mark );
			}
		}

		return array(
			'exam_id' => $exam_id,
			'marks'   => $marks,
		);
	}

	/**
	 * Validate submitted result data.
	 *
	 * @param array $data    Submitted result data.
	 * @param int   $post_id Current result post ID.
	 * @return true|WP_Error
	 */
	private static function validate_result_data( $data, $post_id = 0 ) {
		if ( empty( $data['exam_id'] ) ) {
			return new WP_Error(
				'em_result_missing_exam',
				__( 'Please select an exam for these results.', 'exam-management' )
			);
		}

		if ( self::EXAM_POST_TYPE !== get_post_type( $data['exam_id'] ) || 'publish' !== get_post_status( $data['exam_id'] ) ) {
			return new WP_Error(
				'em_result_invalid_exam',
				__( 'Please select a valid published exam.', 'exam-management' )
			);
		}

		$existing_result_id = self::get_result_id_for_exam( $data['exam_id'] );
		if ( $existing_result_id && (int) $existing_result_id !== (int) $post_id ) {
			return new WP_Error(
				'em_result_duplicate_exam',
				__( 'Results for this exam already exist. Please edit the existing result instead.', 'exam-management' )
			);
		}

		if ( empty( $data['marks'] ) ) {
			return new WP_Error(
				'em_result_missing_marks',
				__( 'Please enter at least one student mark before saving.', 'exam-management' )
			);
		}

		$valid_student_ids = array_map( 'intval', wp_list_pluck( self::get_students(), 'ID' ) );

		foreach ( $data['marks'] as $student_id => $mark ) {
			if ( ! in_array( (int) $student_id, $valid_student_ids, true ) ) {
				return new WP_Error(
					'em_result_invalid_student',
					__( 'One or more marks were submitted for invalid students.', 'exam-management' )
				);
			}

			if ( is_wp_error( self::validate_mark_value( $mark ) ) ) {
				return self::validate_mark_value( $mark );
			}
		}

		return true;
	}

	/**
	 * Sanitize a single mark value.
	 *
	 * @param mixed $mark Raw mark value.
	 * @return float
	 */
	public static function sanitize_mark( $mark ) {
		return round( (float) $mark, 2 );
	}

	/**
	 * Sanitize stored marks meta.
	 *
	 * @param mixed $marks Raw marks meta.
	 * @return array<int, float>
	 */
	public static function sanitize_marks_meta( $marks ) {
		if ( ! is_array( $marks ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $marks as $student_id => $mark ) {
			$student_id = absint( $student_id );
			if ( ! $student_id ) {
				continue;
			}

			$sanitized[ $student_id ] = self::sanitize_mark( $mark );
		}

		return $sanitized;
	}

	/**
	 * Merge student marks without reindexing numeric student IDs.
	 *
	 * array_merge() must not be used here because it renumbers integer keys.
	 *
	 * @param array $existing_marks Existing marks keyed by student ID.
	 * @param array $new_marks      New marks keyed by student ID.
	 * @return array<int, float>
	 */
	public static function merge_student_marks( array $existing_marks, array $new_marks ) {
		$merged = self::sanitize_marks_meta( $existing_marks );

		foreach ( self::sanitize_marks_meta( $new_marks ) as $student_id => $mark ) {
			$merged[ $student_id ] = $mark;
		}

		return $merged;
	}

	/**
	 * Validate a mark is within the allowed range.
	 *
	 * @param float $mark Mark value.
	 * @return true|WP_Error
	 */
	private static function validate_mark_value( $mark ) {
		if ( $mark < 0 || $mark > self::MAX_MARK ) {
			return new WP_Error(
				'em_result_invalid_mark',
				sprintf(
					/* translators: %d: maximum mark value */
					__( 'Marks must be between 0 and %d.', 'exam-management' ),
					self::MAX_MARK
				)
			);
		}

		return true;
	}

	/**
	 * Find an existing result post for an exam.
	 *
	 * @param int $exam_id Exam post ID.
	 * @return int Result post ID or 0.
	 */
	public static function get_result_id_for_exam( $exam_id ) {
		$results = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_EXAM_ID,
				'meta_value'     => absint( $exam_id ),
			)
		);

		return ! empty( $results ) ? (int) $results[0] : 0;
	}

	/**
	 * Get published exams available for result entry.
	 *
	 * @param int $current_result_id Current result post ID.
	 * @return WP_Post[]
	 */
	private static function get_exams( $current_result_id = 0 ) {
		$exams = get_posts(
			array(
				'post_type'      => self::EXAM_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		return array_values(
			array_filter(
				$exams,
				function ( $exam ) use ( $current_result_id ) {
					$existing_result_id = self::get_result_id_for_exam( $exam->ID );

					return ! $existing_result_id || (int) $existing_result_id === (int) $current_result_id;
				}
			)
		);
	}

	/**
	 * Get published students.
	 *
	 * @return WP_Post[]
	 */
	private static function get_students() {
		return get_posts(
			array(
				'post_type'      => self::STUDENT_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Get the subject name linked to an exam.
	 *
	 * @param int $exam_id Exam post ID.
	 * @return string
	 */
	private static function get_exam_subject_name( $exam_id ) {
		$subject_id = (int) get_post_meta( $exam_id, EM_Exam_Admin::META_SUBJECT, true );

		if ( ! $subject_id ) {
			return '';
		}

		return get_the_title( $subject_id );
	}

	/**
	 * Determine whether the current request should be validated.
	 *
	 * @return bool
	 */
	private static function should_validate_request() {
		if ( ! is_admin() ) {
			return false;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Used only to detect the result edit screen context.
		return isset( $_POST['post_type'] ) && self::POST_TYPE === sanitize_key( wp_unslash( $_POST['post_type'] ) );
	}

	/**
	 * Verify the result meta box nonce.
	 *
	 * @return bool
	 */
	private static function verify_save_nonce() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified here.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return false;
		}

		return (bool) wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ),
			self::NONCE_ACTION
		);
	}

	/**
	 * Store a validation message for the current user.
	 *
	 * @param string $message Error message.
	 */
	private static function set_validation_notice( $message ) {
		set_transient( 'em_result_admin_notice_' . get_current_user_id(), $message, 30 );
	}

	/**
	 * Add columns to the results list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_list_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'title' === $key ) {
				$new_columns['em_result_exam']    = __( 'Exam', 'exam-management' );
				$new_columns['em_result_subject'] = __( 'Subject', 'exam-management' );
				$new_columns['em_result_students'] = __( 'Students Marked', 'exam-management' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render custom list table column values.
	 *
	 * @param string $column_name Column key.
	 * @param int    $post_id     Post ID.
	 */
	public static function render_list_column( $column_name, $post_id ) {
		$exam_id = (int) get_post_meta( $post_id, self::META_EXAM_ID, true );
		$marks   = get_post_meta( $post_id, self::META_MARKS, true );
		$marks   = is_array( $marks ) ? $marks : array();

		switch ( $column_name ) {
			case 'em_result_exam':
				echo $exam_id ? esc_html( get_the_title( $exam_id ) ) : '—';
				break;

			case 'em_result_subject':
				echo $exam_id ? esc_html( self::get_exam_subject_name( $exam_id ) ) : '—';
				break;

			case 'em_result_students':
				echo esc_html( (string) count( $marks ) );
				break;
		}
	}

	/**
	 * Enqueue admin scripts on the result edit screen.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_admin_scripts( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'em-result-admin',
			EM_PLUGIN_URL . 'assets/js/result-admin.js',
			array(),
			'1.0.0',
			true
		);

		wp_localize_script(
			'em-result-admin',
			'emResultAdmin',
			array(
				'missingExamMessage' => __( 'Please select an exam before saving results.', 'exam-management' ),
				'missingMarksMessage' => __( 'Please enter at least one student mark before saving.', 'exam-management' ),
				'invalidMarkMessage' => sprintf(
					/* translators: %d: maximum mark value */
					__( 'Marks must be between 0 and %d.', 'exam-management' ),
					self::MAX_MARK
				),
				'maxMark'            => self::MAX_MARK,
			)
		);
	}

	/**
	 * Display validation errors in the admin.
	 */
	public static function render_admin_notices() {
		$message = get_transient( 'em_result_admin_notice_' . get_current_user_id() );

		if ( empty( $message ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
