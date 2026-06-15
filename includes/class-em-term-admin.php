<?php
/**
 * Admin UI and storage for academic term metadata.
 *
 * @package ExamManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles em_term taxonomy admin fields (start/end dates).
 */
class EM_Term_Admin {

	const TAXONOMY        = 'em_term';
	const META_START_DATE = 'em_start_date';
	const META_END_DATE   = 'em_end_date';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_term_meta' ) );

		add_filter( 'pre_insert_term', array( __CLASS__, 'validate_term_before_insert' ), 10, 3 );

		add_action( self::TAXONOMY . '_add_form_fields', array( __CLASS__, 'render_add_form_fields' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( __CLASS__, 'render_edit_form_fields' ), 10, 2 );

		add_action( 'created_' . self::TAXONOMY, array( __CLASS__, 'save_term_meta' ) );
		add_action( 'edited_' . self::TAXONOMY, array( __CLASS__, 'save_term_meta' ) );

		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( __CLASS__, 'add_list_columns' ) );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( __CLASS__, 'render_list_column' ), 10, 3 );

		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Register term meta for REST and sanitization.
	 */
	public static function register_term_meta() {
		register_term_meta(
			self::TAXONOMY,
			self::META_START_DATE,
			array(
				'type'              => 'string',
				'description'       => __( 'Academic term start date.', 'exam-management' ),
				'single'            => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_date' ),
				'show_in_rest'      => true,
			)
		);

		register_term_meta(
			self::TAXONOMY,
			self::META_END_DATE,
			array(
				'type'              => 'string',
				'description'       => __( 'Academic term end date.', 'exam-management' ),
				'single'            => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_date' ),
				'show_in_rest'      => true,
			)
		);
	}

	/**
	 * Render fields on the add-term screen.
	 *
	 * @param string $taxonomy Current taxonomy slug.
	 */
	public static function render_add_form_fields( $taxonomy ) {
		if ( self::TAXONOMY !== $taxonomy ) {
			return;
		}

		self::render_date_fields();
	}

	/**
	 * Render fields on the edit-term screen.
	 *
	 * @param WP_Term $term     Current term object.
	 * @param string  $taxonomy Current taxonomy slug.
	 */
	public static function render_edit_form_fields( $term, $taxonomy ) {
		if ( self::TAXONOMY !== $taxonomy ) {
			return;
		}

		$start_date = get_term_meta( $term->term_id, self::META_START_DATE, true );
		$end_date   = get_term_meta( $term->term_id, self::META_END_DATE, true );

		self::render_date_fields( $start_date, $end_date, true );
	}

	/**
	 * Output start and end date inputs.
	 *
	 * @param string $start_date Existing start date.
	 * @param string $end_date   Existing end date.
	 * @param bool   $is_edit    Whether this is the edit form.
	 */
	private static function render_date_fields( $start_date = '', $end_date = '', $is_edit = false ) {
		if ( $is_edit ) {
			echo '<tr class="form-field">';
			echo '<th scope="row"><label for="em_start_date">' . esc_html__( 'Start Date', 'exam-management' ) . '</label></th>';
			echo '<td>';
		} else {
			echo '<div class="form-field">';
			echo '<label for="em_start_date">' . esc_html__( 'Start Date', 'exam-management' ) . '</label>';
		}

		printf(
			'<input type="date" name="em_start_date" id="em_start_date" value="%s" required />',
			esc_attr( $start_date )
		);

		if ( ! $is_edit ) {
			echo '<p>' . esc_html__( 'The first day of the academic term.', 'exam-management' ) . '</p>';
		}

		if ( $is_edit ) {
			echo '</td></tr>';
			echo '<tr class="form-field">';
			echo '<th scope="row"><label for="em_end_date">' . esc_html__( 'End Date', 'exam-management' ) . '</label></th>';
			echo '<td>';
		} else {
			echo '</div><div class="form-field">';
			echo '<label for="em_end_date">' . esc_html__( 'End Date', 'exam-management' ) . '</label>';
		}

		printf(
			'<input type="date" name="em_end_date" id="em_end_date" value="%s" required />',
			esc_attr( $end_date )
		);

		if ( ! $is_edit ) {
			echo '<p>' . esc_html__( 'The last day of the academic term.', 'exam-management' ) . '</p>';
		}

		if ( $is_edit ) {
			echo '</td></tr>';
		} else {
			echo '</div>';
		}
	}

	/**
	 * Block term creation when submitted dates are invalid.
	 *
	 * Runs before WordPress inserts the term, so invalid terms are never saved.
	 *
	 * @param string|WP_Error $term     Term name or error.
	 * @param string          $taxonomy Taxonomy slug.
	 * @param array           $args     Arguments passed to wp_insert_term().
	 * @return string|WP_Error
	 */
	public static function validate_term_before_insert( $term, $taxonomy, $args = array() ) {
		if ( self::TAXONOMY !== $taxonomy || is_wp_error( $term ) ) {
			return $term;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return $term;
		}

		$validation_error = self::validate_submitted_dates();
		if ( is_wp_error( $validation_error ) ) {
			return $validation_error;
		}

		return $term;
	}

	/**
	 * Save term meta when a term is created or updated.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function save_term_meta( $term_id ) {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$validation_error = self::validate_submitted_dates();
		if ( is_wp_error( $validation_error ) ) {
			set_transient(
				'em_term_admin_notice_' . get_current_user_id(),
				$validation_error->get_error_message(),
				30
			);
			return;
		}

		$dates = self::get_submitted_dates();

		update_term_meta( $term_id, self::META_START_DATE, $dates['start'] );
		update_term_meta( $term_id, self::META_END_DATE, $dates['end'] );

		delete_transient( 'em_term_admin_notice_' . get_current_user_id() );
	}

	/**
	 * Read and sanitize submitted date fields.
	 *
	 * @return array{start: string, end: string}
	 */
	private static function get_submitted_dates() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Taxonomy forms do not provide a nonce; capability check is used.
		$start_date = isset( $_POST['em_start_date'] )
			? self::sanitize_date( wp_unslash( $_POST['em_start_date'] ) )
			: '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Taxonomy forms do not provide a nonce; capability check is used.
		$end_date = isset( $_POST['em_end_date'] )
			? self::sanitize_date( wp_unslash( $_POST['em_end_date'] ) )
			: '';

		return array(
			'start' => $start_date,
			'end'   => $end_date,
		);
	}

	/**
	 * Validate submitted date fields from the admin form.
	 *
	 * @return true|WP_Error
	 */
	private static function validate_submitted_dates() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Taxonomy forms do not provide a nonce; capability check is used.
		if ( ! isset( $_POST['em_start_date'], $_POST['em_end_date'] ) ) {
			return new WP_Error(
				'em_term_missing_dates',
				__( 'Both start and end dates are required for an academic term.', 'exam-management' )
			);
		}

		$dates = self::get_submitted_dates();

		return self::validate_dates( $dates['start'], $dates['end'] );
	}

	/**
	 * Sanitize a date string to Y-m-d format.
	 *
	 * @param string $date Raw date input.
	 * @return string Sanitized date or empty string.
	 */
	public static function sanitize_date( $date ) {
		$date = sanitize_text_field( $date );

		if ( empty( $date ) ) {
			return '';
		}

		$datetime = DateTime::createFromFormat( 'Y-m-d', $date );
		if ( false === $datetime || $datetime->format( 'Y-m-d' ) !== $date ) {
			return '';
		}

		return $date;
	}

	/**
	 * Validate start and end dates.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private static function validate_dates( $start_date, $end_date ) {
		if ( empty( $start_date ) || empty( $end_date ) ) {
			return new WP_Error(
				'em_term_missing_dates',
				__( 'Both start and end dates are required for an academic term.', 'exam-management' )
			);
		}

		if ( $end_date < $start_date ) {
			return new WP_Error(
				'em_term_invalid_dates',
				__( 'The end date must be on or after the start date.', 'exam-management' )
			);
		}

		return true;
	}

	/**
	 * Add date columns to the terms list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_list_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'name' === $key ) {
				$new_columns['em_start_date'] = __( 'Start Date', 'exam-management' );
				$new_columns['em_end_date']   = __( 'End Date', 'exam-management' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render custom column values.
	 *
	 * @param string $content     Column content.
	 * @param string $column_name Column key.
	 * @param int    $term_id     Term ID.
	 * @return string
	 */
	public static function render_list_column( $content, $column_name, $term_id ) {
		if ( 'em_start_date' === $column_name ) {
			$start_date = get_term_meta( $term_id, self::META_START_DATE, true );
			return $start_date ? esc_html( self::format_display_date( $start_date ) ) : '—';
		}

		if ( 'em_end_date' === $column_name ) {
			$end_date = get_term_meta( $term_id, self::META_END_DATE, true );
			return $end_date ? esc_html( self::format_display_date( $end_date ) ) : '—';
		}

		return $content;
	}

	/**
	 * Format a stored date for display.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return string
	 */
	private static function format_display_date( $date ) {
		$timestamp = strtotime( $date );

		if ( false === $timestamp ) {
			return $date;
		}

		return wp_date( get_option( 'date_format' ), $timestamp );
	}

	/**
	 * Enqueue admin scripts on the term management screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_admin_scripts( $hook_suffix ) {
		if ( 'edit-tags.php' !== $hook_suffix && 'term.php' !== $hook_suffix ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only taxonomy context check.
		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
		if ( self::TAXONOMY !== $taxonomy ) {
			return;
		}

		wp_enqueue_script(
			'em-term-admin',
			EM_PLUGIN_URL . 'assets/js/term-admin.js',
			array(),
			'1.0.0',
			true
		);

		wp_localize_script(
			'em-term-admin',
			'emTermAdmin',
			array(
				'invalidDateMessage' => __( 'The end date must be on or after the start date.', 'exam-management' ),
				'missingDateMessage' => __( 'Both start and end dates are required for an academic term.', 'exam-management' ),
			)
		);
	}

	/**
	 * Display validation errors in the admin.
	 */
	public static function render_admin_notices() {
		$message = get_transient( 'em_term_admin_notice_' . get_current_user_id() );

		if ( empty( $message ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
