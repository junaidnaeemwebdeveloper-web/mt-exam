<?php
/**
 * Shared student statistics aggregation.
 *
 * @package ExamManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds student performance statistics grouped by academic term.
 */
class EM_Student_Stats {

	/**
	 * Build the student statistics report dataset.
	 *
	 * @return array{
	 *     terms: array<int, array{term_id:int, term_name:string, start_date:string, end_date:string}>,
	 *     students: array<int, array{id:int, name:string, term_totals:array<int, float>, average:float}>
	 * }
	 */
	public static function get_report_data() {
		$terms = self::get_terms_ordered();

		if ( empty( $terms ) ) {
			return array(
				'terms'    => array(),
				'students' => self::get_empty_student_rows(),
			);
		}

		$exam_term_map = self::get_exam_term_map();
		$result_rows   = self::get_result_rows();
		$term_totals   = array();

		foreach ( $result_rows as $row ) {
			$exam_id = (int) $row['exam_id'];

			if ( ! isset( $exam_term_map[ $exam_id ] ) ) {
				continue;
			}

			$term_id = (int) $exam_term_map[ $exam_id ];
			$marks   = maybe_unserialize( $row['marks'] );

			if ( ! is_array( $marks ) || empty( $marks ) ) {
				continue;
			}

			foreach ( $marks as $student_id => $mark ) {
				$student_id = (int) $student_id;
				$mark       = (float) $mark;

				if ( $student_id <= 0 ) {
					continue;
				}

				if ( ! isset( $term_totals[ $student_id ][ $term_id ] ) ) {
					$term_totals[ $student_id ][ $term_id ] = 0.0;
				}

				$term_totals[ $student_id ][ $term_id ] += $mark;
			}
		}

		$students = self::get_all_students();
		$rows     = array();

		foreach ( $students as $student ) {
			$student_term_totals = isset( $term_totals[ $student->ID ] ) ? $term_totals[ $student->ID ] : array();
			$rows[]              = self::build_student_row( $student, $terms, $student_term_totals );
		}

		return array(
			'terms'    => self::format_terms( $terms ),
			'students' => $rows,
		);
	}

	/**
	 * Get all published students.
	 *
	 * @return WP_Post[]
	 */
	private static function get_all_students() {
		return get_posts(
			array(
				'post_type'              => EM_Result_Admin::STUDENT_POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
	}

	/**
	 * Build empty student rows when no terms exist.
	 *
	 * @return array
	 */
	private static function get_empty_student_rows() {
		$rows = array();

		foreach ( self::get_all_students() as $student ) {
			$rows[] = array(
				'id'          => (int) $student->ID,
				'name'        => $student->post_title,
				'term_totals' => array(),
				'average'     => 0.0,
			);
		}

		return $rows;
	}

	/**
	 * Build a single student report row.
	 *
	 * @param WP_Post $student           Student post object.
	 * @param array   $terms             Ordered term rows.
	 * @param array   $student_term_data Term totals keyed by term ID.
	 * @return array
	 */
	private static function build_student_row( $student, array $terms, array $student_term_data ) {
		$term_totals   = array();
		$average_parts = array();

		foreach ( $terms as $term ) {
			$term_id = (int) $term['term_id'];
			$total   = isset( $student_term_data[ $term_id ] ) ? round( (float) $student_term_data[ $term_id ], 2 ) : null;

			$term_totals[ $term_id ] = $total;

			if ( null !== $total ) {
				$average_parts[] = $total;
			}
		}

		$average = ! empty( $average_parts )
			? round( array_sum( $average_parts ) / count( $average_parts ), 2 )
			: 0.0;

		return array(
			'id'          => (int) $student->ID,
			'name'        => $student->post_title,
			'term_totals' => $term_totals,
			'average'     => $average,
		);
	}

	/**
	 * Normalize term rows for output.
	 *
	 * @param array $terms Raw term rows.
	 * @return array
	 */
	private static function format_terms( array $terms ) {
		$formatted = array();

		foreach ( $terms as $term ) {
			$formatted[] = array(
				'term_id'    => (int) $term['term_id'],
				'term_name'  => $term['name'],
				'start_date' => $term['start_date'],
				'end_date'   => $term['end_date'],
			);
		}

		return $formatted;
	}

	/**
	 * Get academic terms ordered by latest end date first.
	 *
	 * @return array
	 */
	private static function get_terms_ordered() {
		global $wpdb;

		$sql = "
			SELECT
				t.term_id,
				t.name,
				COALESCE( end_meta.meta_value, '' ) AS end_date,
				COALESCE( start_meta.meta_value, '' ) AS start_date
			FROM {$wpdb->terms} AS t
			INNER JOIN {$wpdb->term_taxonomy} AS tt
				ON t.term_id = tt.term_id
				AND tt.taxonomy = %s
			LEFT JOIN {$wpdb->termmeta} AS end_meta
				ON t.term_id = end_meta.term_id
				AND end_meta.meta_key = %s
			LEFT JOIN {$wpdb->termmeta} AS start_meta
				ON t.term_id = start_meta.term_id
				AND start_meta.meta_key = %s
			ORDER BY
				CASE
					WHEN end_meta.meta_value IS NULL OR end_meta.meta_value = '' THEN 1
					ELSE 0
				END ASC,
				end_meta.meta_value DESC,
				start_meta.meta_value DESC,
				t.name ASC
		";

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$sql,
				EM_Term_Admin::TAXONOMY,
				EM_Term_Admin::META_END_DATE,
				EM_Term_Admin::META_START_DATE
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Map exam IDs to academic term IDs.
	 *
	 * @return array<int, int>
	 */
	private static function get_exam_term_map() {
		global $wpdb;

		$sql = "
			SELECT
				tr.object_id AS exam_id,
				tt.term_id
			FROM {$wpdb->term_relationships} AS tr
			INNER JOIN {$wpdb->term_taxonomy} AS tt
				ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->posts} AS p
				ON tr.object_id = p.ID
				AND p.post_type = %s
				AND p.post_status = 'publish'
			WHERE tt.taxonomy = %s
		";

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$sql,
				EM_Exam_Admin::POST_TYPE,
				EM_Term_Admin::TAXONOMY
			),
			ARRAY_A
		);

		$map = array();

		if ( ! is_array( $rows ) ) {
			return $map;
		}

		foreach ( $rows as $row ) {
			$map[ (int) $row['exam_id'] ] = (int) $row['term_id'];
		}

		return $map;
	}

	/**
	 * Fetch published result rows with linked exam IDs and marks.
	 *
	 * @return array
	 */
	private static function get_result_rows() {
		global $wpdb;

		$sql = "
			SELECT
				exam_meta.meta_value AS exam_id,
				marks_meta.meta_value AS marks
			FROM {$wpdb->posts} AS p
			INNER JOIN {$wpdb->postmeta} AS exam_meta
				ON p.ID = exam_meta.post_id
				AND exam_meta.meta_key = %s
			INNER JOIN {$wpdb->postmeta} AS marks_meta
				ON p.ID = marks_meta.post_id
				AND marks_meta.meta_key = %s
			WHERE p.post_type = %s
				AND p.post_status = 'publish'
		";

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$sql,
				EM_Result_Admin::META_EXAM_ID,
				EM_Result_Admin::META_MARKS,
				EM_Result_Admin::POST_TYPE
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
