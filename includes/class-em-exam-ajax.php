<?php
/**
 * AJAX handlers for exam listings.
 *
 * @package ExamManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a paginated AJAX endpoint for exams.
 */
class EM_Exam_Ajax {

	const ACTION           = 'em_get_exams';
	const NONCE_ACTION     = 'em_exam_list';
	const DEFAULT_PER_PAGE = 10;
	const MAX_PER_PAGE     = 50;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle_get_exams' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'handle_get_exams' ) );
	}

	/**
	 * Handle the paginated exam list AJAX request.
	 */
	public static function handle_get_exams() {
		if ( isset( $_REQUEST['nonce'] ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ), self::NONCE_ACTION ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Invalid security token.', 'exam-management' ),
				),
				403
			);
		}

		$page = isset( $_REQUEST['page'] ) ? max( 1, absint( wp_unslash( $_REQUEST['page'] ) ) ) : 1;
		$per_page = isset( $_REQUEST['per_page'] )
			? min( self::MAX_PER_PAGE, max( 1, absint( wp_unslash( $_REQUEST['per_page'] ) ) ) )
			: self::DEFAULT_PER_PAGE;

		$result = self::get_paginated_exams( $page, $per_page );

		wp_send_json_success( $result );
	}

	/**
	 * Get a paginated list of exams ordered by status tier.
	 *
	 * @param int $page     Current page number.
	 * @param int $per_page Items per page.
	 * @return array
	 */
	public static function get_paginated_exams( $page, $per_page ) {
		$page     = max( 1, (int) $page );
		$per_page = min( self::MAX_PER_PAGE, max( 1, (int) $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$now      = current_time( 'mysql' );

		$total = self::get_exam_count();
		$ids   = self::get_ordered_exam_ids( $per_page, $offset, $now );

		if ( empty( $ids ) ) {
			return array(
				'exams'      => array(),
				'pagination' => self::build_pagination( $page, $per_page, $total ),
			);
		}

		self::prime_exam_caches( $ids );

		$exams = array();
		foreach ( $ids as $exam_id ) {
			$exams[] = self::format_exam( (int) $exam_id, $now );
		}

		return array(
			'exams'      => $exams,
			'pagination' => self::build_pagination( $page, $per_page, $total ),
		);
	}

	/**
	 * Count published exams with schedule metadata.
	 *
	 * @return int
	 */
	private static function get_exam_count() {
		global $wpdb;

		$start_key = EM_Exam_Admin::META_START;
		$end_key   = EM_Exam_Admin::META_END;

		$sql = "
			SELECT COUNT( DISTINCT p.ID )
			FROM {$wpdb->posts} AS p
			INNER JOIN {$wpdb->postmeta} AS start_meta
				ON p.ID = start_meta.post_id
				AND start_meta.meta_key = %s
				AND start_meta.meta_value <> ''
			INNER JOIN {$wpdb->postmeta} AS end_meta
				ON p.ID = end_meta.post_id
				AND end_meta.meta_key = %s
				AND end_meta.meta_value <> ''
			WHERE p.post_type = %s
				AND p.post_status = 'publish'
		";

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				$sql,
				$start_key,
				$end_key,
				EM_Exam_Admin::POST_TYPE
			)
		);
	}

	/**
	 * Get exam IDs ordered by ongoing, upcoming, then past exams.
	 *
	 * @param int    $per_page Items per page.
	 * @param int    $offset   Query offset.
	 * @param string $now      Current site datetime.
	 * @return int[]
	 */
	private static function get_ordered_exam_ids( $per_page, $offset, $now ) {
		global $wpdb;

		$start_key = EM_Exam_Admin::META_START;
		$end_key   = EM_Exam_Admin::META_END;

		$sql = "
			SELECT ordered_exams.ID
			FROM (
				SELECT
					p.ID,
					start_meta.meta_value AS start_datetime,
					end_meta.meta_value AS end_datetime,
					CASE
						WHEN start_meta.meta_value <= %s AND end_meta.meta_value >= %s THEN 1
						WHEN start_meta.meta_value > %s THEN 2
						ELSE 3
					END AS exam_tier
				FROM {$wpdb->posts} AS p
				INNER JOIN {$wpdb->postmeta} AS start_meta
					ON p.ID = start_meta.post_id
					AND start_meta.meta_key = %s
					AND start_meta.meta_value <> ''
				INNER JOIN {$wpdb->postmeta} AS end_meta
					ON p.ID = end_meta.post_id
					AND end_meta.meta_key = %s
					AND end_meta.meta_value <> ''
				WHERE p.post_type = %s
					AND p.post_status = 'publish'
			) AS ordered_exams
			ORDER BY
				ordered_exams.exam_tier ASC,
				CASE
					WHEN ordered_exams.exam_tier = 1 THEN ordered_exams.end_datetime
				END ASC,
				CASE
					WHEN ordered_exams.exam_tier = 2 THEN ordered_exams.start_datetime
				END ASC,
				CASE
					WHEN ordered_exams.exam_tier = 3 THEN ordered_exams.end_datetime
				END DESC
			LIMIT %d OFFSET %d
		";

		$prepared = $wpdb->prepare(
			$sql,
			$now,
			$now,
			$now,
			$start_key,
			$end_key,
			EM_Exam_Admin::POST_TYPE,
			$per_page,
			$offset
		);

		$ids = $wpdb->get_col( $prepared );

		return array_map( 'intval', $ids );
	}

	/**
	 * Prime post, meta, and term caches for a set of exams.
	 *
	 * @param int[] $exam_ids Exam post IDs.
	 */
	private static function prime_exam_caches( array $exam_ids ) {
		update_meta_cache( 'post', $exam_ids );
		update_object_term_cache( $exam_ids, EM_Exam_Admin::POST_TYPE );

		foreach ( $exam_ids as $exam_id ) {
			get_post( $exam_id );
		}
	}

	/**
	 * Build pagination metadata.
	 *
	 * @param int $page     Current page.
	 * @param int $per_page Items per page.
	 * @param int $total    Total matching exams.
	 * @return array
	 */
	private static function build_pagination( $page, $per_page, $total ) {
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		return array(
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * Format a single exam for the AJAX response.
	 *
	 * @param int    $exam_id Exam post ID.
	 * @param string $now     Current site datetime.
	 * @return array
	 */
	private static function format_exam( $exam_id, $now ) {
		$start = get_post_meta( $exam_id, EM_Exam_Admin::META_START, true );
		$end   = get_post_meta( $exam_id, EM_Exam_Admin::META_END, true );

		$subject_id = (int) get_post_meta( $exam_id, EM_Exam_Admin::META_SUBJECT, true );
		$terms      = get_the_terms( $exam_id, EM_Exam_Admin::TAXONOMY );
		$term       = ( ! empty( $terms ) && ! is_wp_error( $terms ) ) ? $terms[0] : null;

		return array(
			'id'             => $exam_id,
			'title'          => get_the_title( $exam_id ),
			'start_datetime' => $start,
			'end_datetime'   => $end,
			'status'         => self::get_exam_status( $start, $end, $now ),
			'subject'        => $subject_id
				? array(
					'id'    => $subject_id,
					'title' => get_the_title( $subject_id ),
				)
				: null,
			'term'           => $term
				? array(
					'id'   => (int) $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				)
				: null,
		);
	}

	/**
	 * Determine whether an exam is ongoing, upcoming, or past.
	 *
	 * @param string $start Start datetime.
	 * @param string $end   End datetime.
	 * @param string $now   Current site datetime.
	 * @return string
	 */
	public static function get_exam_status( $start, $end, $now = null ) {
		if ( null === $now ) {
			$now = current_time( 'mysql' );
		}

		if ( $start <= $now && $end >= $now ) {
			return 'ongoing';
		}

		if ( $start > $now ) {
			return 'upcoming';
		}

		return 'past';
	}

	/**
	 * Create a nonce for exam list AJAX requests.
	 *
	 * @return string
	 */
	public static function create_nonce() {
		return wp_create_nonce( self::NONCE_ACTION );
	}

	/**
	 * Get the AJAX endpoint URL for the exam list.
	 *
	 * @return string
	 */
	public static function get_ajax_url() {
		return admin_url( 'admin-ajax.php' );
	}
}
