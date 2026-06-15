<?php
/**
 * Admin UI and storage for exam post metadata.
 *
 * @package ExamManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles em_exam admin fields and validation.
 */
class EM_Exam_Admin {

	const POST_TYPE     = 'em_exam';
	const TAXONOMY      = 'em_term';
	const META_START    = 'em_exam_start_datetime';
	const META_END      = 'em_exam_end_datetime';
	const META_SUBJECT  = 'em_exam_subject_id';
	const META_BOX_ID   = 'em_exam_details';
	const NONCE_ACTION  = 'em_exam_save';
	const NONCE_FIELD   = 'em_exam_nonce';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_meta' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_exam' ) );

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
	 * Validate exam data before WordPress saves the post.
	 *
	 * Prevents publishing when required fields are missing or invalid.
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

		$validation_error = self::validate_exam_data( self::get_submitted_data() );
		if ( is_wp_error( $validation_error ) ) {
			self::set_validation_notice( $validation_error->get_error_message() );

			if ( in_array( $data['post_status'], array( 'publish', 'future' ), true ) ) {
				$data['post_status'] = 'draft';
			}
		}

		return $data;
	}

	/**
	 * Register post meta for REST and sanitization.
	 */
	public static function register_post_meta() {
		register_post_meta(
			self::POST_TYPE,
			self::META_START,
			array(
				'type'              => 'string',
				'description'       => __( 'Exam start date and time.', 'exam-management' ),
				'single'            => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_datetime' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_END,
			array(
				'type'              => 'string',
				'description'       => __( 'Exam end date and time.', 'exam-management' ),
				'single'            => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_datetime' ),
				'show_in_rest'      => true,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_SUBJECT,
			array(
				'type'              => 'integer',
				'description'       => __( 'Linked subject post ID.', 'exam-management' ),
				'single'            => true,
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
			)
		);
	}

	/**
	 * Register the exam details meta box and remove the default term box.
	 */
	public static function register_meta_boxes() {
		remove_meta_box( 'tagsdiv-' . self::TAXONOMY, self::POST_TYPE, 'side' );

		add_meta_box(
			self::META_BOX_ID,
			__( 'Exam Details', 'exam-management' ),
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render exam detail fields.
	 *
	 * @param WP_Post $post Current exam post.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$start_datetime = get_post_meta( $post->ID, self::META_START, true );
		$end_datetime   = get_post_meta( $post->ID, self::META_END, true );
		$subject_id     = (int) get_post_meta( $post->ID, self::META_SUBJECT, true );
		$assigned_terms = wp_get_object_terms( $post->ID, self::TAXONOMY, array( 'fields' => 'ids' ) );
		$term_id        = ! empty( $assigned_terms ) && ! is_wp_error( $assigned_terms ) ? (int) $assigned_terms[0] : 0;

		$subjects = self::get_subjects();
		$terms    = self::get_terms_list();
		?>
		<table class="form-table em-exam-details-table">
			<tbody>
				<tr>
					<th scope="row">
						<label for="em_exam_start_datetime"><?php esc_html_e( 'Start Date & Time', 'exam-management' ); ?></label>
					</th>
					<td>
						<input
							type="datetime-local"
							name="em_exam_start_datetime"
							id="em_exam_start_datetime"
							value="<?php echo esc_attr( self::format_datetime_for_input( $start_datetime ) ); ?>"
							required
						/>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="em_exam_end_datetime"><?php esc_html_e( 'End Date & Time', 'exam-management' ); ?></label>
					</th>
					<td>
						<input
							type="datetime-local"
							name="em_exam_end_datetime"
							id="em_exam_end_datetime"
							value="<?php echo esc_attr( self::format_datetime_for_input( $end_datetime ) ); ?>"
							required
						/>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="em_exam_subject_id"><?php esc_html_e( 'Subject', 'exam-management' ); ?></label>
					</th>
					<td>
						<select name="em_exam_subject_id" id="em_exam_subject_id" required>
							<option value=""><?php esc_html_e( 'Select a subject', 'exam-management' ); ?></option>
							<?php foreach ( $subjects as $subject ) : ?>
								<option value="<?php echo esc_attr( $subject->ID ); ?>" <?php selected( $subject_id, $subject->ID ); ?>>
									<?php echo esc_html( $subject->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="em_exam_term_id"><?php esc_html_e( 'Academic Term', 'exam-management' ); ?></label>
					</th>
					<td>
						<select name="em_exam_term_id" id="em_exam_term_id" required>
							<option value=""><?php esc_html_e( 'Select an academic term', 'exam-management' ); ?></option>
							<?php foreach ( $terms as $term ) : ?>
								<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $term_id, $term->term_id ); ?>>
									<?php echo esc_html( self::format_term_option_label( $term ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Save exam metadata and term assignment.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function save_exam( $post_id ) {
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
		$validation_error = self::validate_exam_data( $data );

		if ( is_wp_error( $validation_error ) ) {
			self::set_validation_notice( $validation_error->get_error_message() );
			return;
		}

		update_post_meta( $post_id, self::META_START, $data['start'] );
		update_post_meta( $post_id, self::META_END, $data['end'] );
		update_post_meta( $post_id, self::META_SUBJECT, $data['subject_id'] );
		wp_set_object_terms( $post_id, array( $data['term_id'] ), self::TAXONOMY );

		delete_transient( 'em_exam_admin_notice_' . get_current_user_id() );
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Used only to detect the exam edit screen context.
		return isset( $_POST['post_type'] ) && self::POST_TYPE === sanitize_key( wp_unslash( $_POST['post_type'] ) );
	}

	/**
	 * Verify the exam meta box nonce.
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
		set_transient( 'em_exam_admin_notice_' . get_current_user_id(), $message, 30 );
	}

	/**
	 * Read submitted exam data from the request.
	 *
	 * @return array{
	 *     start: string,
	 *     end: string,
	 *     subject_id: int,
	 *     term_id: int
	 * }
	 */
	private static function get_submitted_data() {
		$start = isset( $_POST['em_exam_start_datetime'] )
			? self::sanitize_datetime( wp_unslash( $_POST['em_exam_start_datetime'] ) )
			: '';
		$end   = isset( $_POST['em_exam_end_datetime'] )
			? self::sanitize_datetime( wp_unslash( $_POST['em_exam_end_datetime'] ) )
			: '';

		return array(
			'start'      => $start,
			'end'        => $end,
			'subject_id' => isset( $_POST['em_exam_subject_id'] ) ? absint( wp_unslash( $_POST['em_exam_subject_id'] ) ) : 0,
			'term_id'    => isset( $_POST['em_exam_term_id'] ) ? absint( wp_unslash( $_POST['em_exam_term_id'] ) ) : 0,
		);
	}

	/**
	 * Validate submitted exam data.
	 *
	 * @param array $data Submitted exam data.
	 * @return true|WP_Error
	 */
	private static function validate_exam_data( $data ) {
		if ( empty( $data['start'] ) || empty( $data['end'] ) ) {
			return new WP_Error(
				'em_exam_missing_datetimes',
				__( 'Start and end date/time are required for an exam.', 'exam-management' )
			);
		}

		if ( $data['end'] < $data['start'] ) {
			return new WP_Error(
				'em_exam_invalid_datetimes',
				__( 'The exam end date/time must be on or after the start date/time.', 'exam-management' )
			);
		}

		if ( empty( $data['subject_id'] ) ) {
			return new WP_Error(
				'em_exam_missing_subject',
				__( 'Please select a subject for this exam.', 'exam-management' )
			);
		}

		if ( 'em_subject' !== get_post_type( $data['subject_id'] ) || 'publish' !== get_post_status( $data['subject_id'] ) ) {
			return new WP_Error(
				'em_exam_invalid_subject',
				__( 'Please select a valid published subject.', 'exam-management' )
			);
		}

		if ( empty( $data['term_id'] ) ) {
			return new WP_Error(
				'em_exam_missing_term',
				__( 'Please select an academic term for this exam.', 'exam-management' )
			);
		}

		$term = get_term( $data['term_id'], self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error(
				'em_exam_invalid_term',
				__( 'Please select a valid academic term.', 'exam-management' )
			);
		}

		return true;
	}

	/**
	 * Sanitize a datetime string to Y-m-d H:i:s.
	 *
	 * @param string $datetime Raw datetime input.
	 * @return string
	 */
	public static function sanitize_datetime( $datetime ) {
		$datetime = sanitize_text_field( $datetime );

		if ( empty( $datetime ) ) {
			return '';
		}

		$datetime = str_replace( 'T', ' ', $datetime );
		$timezone = wp_timezone();

		$parsed = DateTime::createFromFormat( 'Y-m-d H:i', $datetime, $timezone );
		if ( false === $parsed ) {
			$parsed = DateTime::createFromFormat( 'Y-m-d H:i:s', $datetime, $timezone );
		}

		if ( false === $parsed ) {
			return '';
		}

		return $parsed->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Format stored datetime for HTML datetime-local input.
	 *
	 * @param string $stored Stored datetime.
	 * @return string
	 */
	public static function format_datetime_for_input( $stored ) {
		if ( empty( $stored ) ) {
			return '';
		}

		$parsed = DateTime::createFromFormat( 'Y-m-d H:i:s', $stored, wp_timezone() );
		if ( false === $parsed ) {
			return '';
		}

		return $parsed->format( 'Y-m-d\TH:i' );
	}

	/**
	 * Get published subjects for the dropdown.
	 *
	 * @return WP_Post[]
	 */
	private static function get_subjects() {
		return get_posts(
			array(
				'post_type'      => 'em_subject',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Get academic terms for the dropdown.
	 *
	 * @return WP_Term[]
	 */
	private static function get_terms_list() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		usort(
			$terms,
			function ( $a, $b ) {
				return strcasecmp( $a->name, $b->name );
			}
		);

		return $terms;
	}

	/**
	 * Build a readable label for a term option.
	 *
	 * @param WP_Term $term Term object.
	 * @return string
	 */
	private static function format_term_option_label( $term ) {
		$start_date = get_term_meta( $term->term_id, EM_Term_Admin::META_START_DATE, true );
		$end_date   = get_term_meta( $term->term_id, EM_Term_Admin::META_END_DATE, true );

		if ( $start_date && $end_date ) {
			return sprintf(
				'%s (%s - %s)',
				$term->name,
				$start_date,
				$end_date
			);
		}

		return $term->name;
	}

	/**
	 * Add columns to the exams list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_list_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'title' === $key ) {
				$new_columns['em_exam_subject'] = __( 'Subject', 'exam-management' );
				$new_columns['em_exam_term']    = __( 'Academic Term', 'exam-management' );
				$new_columns['em_exam_start']   = __( 'Start', 'exam-management' );
				$new_columns['em_exam_end']     = __( 'End', 'exam-management' );
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
		switch ( $column_name ) {
			case 'em_exam_subject':
				$subject_id = (int) get_post_meta( $post_id, self::META_SUBJECT, true );
				if ( $subject_id ) {
					echo esc_html( get_the_title( $subject_id ) );
				} else {
					echo '—';
				}
				break;

			case 'em_exam_term':
				$terms = get_the_terms( $post_id, self::TAXONOMY );
				if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					echo esc_html( $terms[0]->name );
				} else {
					echo '—';
				}
				break;

			case 'em_exam_start':
				$start = get_post_meta( $post_id, self::META_START, true );
				echo $start ? esc_html( self::format_datetime_for_display( $start ) ) : '—';
				break;

			case 'em_exam_end':
				$end = get_post_meta( $post_id, self::META_END, true );
				echo $end ? esc_html( self::format_datetime_for_display( $end ) ) : '—';
				break;
		}
	}

	/**
	 * Format stored datetime for display.
	 *
	 * @param string $stored Stored datetime.
	 * @return string
	 */
	private static function format_datetime_for_display( $stored ) {
		$timestamp = strtotime( $stored );

		if ( false === $timestamp ) {
			return $stored;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Enqueue admin scripts on the exam edit screen.
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
			'em-exam-admin',
			EM_PLUGIN_URL . 'assets/js/exam-admin.js',
			array(),
			'1.0.1',
			true
		);

		wp_localize_script(
			'em-exam-admin',
			'emExamAdmin',
			array(
				'invalidDateTimeMessage' => __( 'The exam end date/time must be on or after the start date/time.', 'exam-management' ),
				'missingFieldMessage'    => __( 'Please complete all required exam fields before saving.', 'exam-management' ),
			)
		);
	}

	/**
	 * Display validation errors in the admin.
	 */
	public static function render_admin_notices() {
		$message = get_transient( 'em_exam_admin_notice_' . get_current_user_id() );

		if ( empty( $message ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
