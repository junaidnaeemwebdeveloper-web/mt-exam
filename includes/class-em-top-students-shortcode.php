<?php
/**
 * Shortcode for displaying top students by academic term.
 *
 * @package ExamManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the [em_top_students] shortcode.
 */
class EM_Top_Students_Shortcode {

	const SHORTCODE     = 'em_top_students';
	const CACHE_KEY     = 'em_top_students_cache';
	const CACHE_VERSION = '1';
	const DEFAULT_LIMIT = 3;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_styles' ) );

		add_action( 'save_post_' . EM_Result_Admin::POST_TYPE, array( __CLASS__, 'clear_cache' ) );
		add_action( 'save_post_' . EM_Exam_Admin::POST_TYPE, array( __CLASS__, 'clear_cache' ) );
		add_action( 'edited_' . EM_Term_Admin::TAXONOMY, array( __CLASS__, 'clear_cache' ) );
		add_action( 'created_' . EM_Term_Admin::TAXONOMY, array( __CLASS__, 'clear_cache' ) );
		add_action( 'delete_' . EM_Term_Admin::TAXONOMY, array( __CLASS__, 'clear_cache' ) );
	}

	/**
	 * Register stylesheet for the shortcode output.
	 */
	public static function register_styles() {
		wp_register_style(
			'em-top-students',
			EM_PLUGIN_URL . 'assets/css/top-students.css',
			array(),
			'1.1.0'
		);
	}

	/**
	 * Render the shortcode output.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit' => self::DEFAULT_LIMIT,
			),
			$atts,
			self::SHORTCODE
		);

		$limit = max( 1, absint( $atts['limit'] ) );
		$data  = self::get_top_students_by_term( $limit );

		if ( empty( $data ) ) {
			return '<p class="em-top-students__empty">' . esc_html__( 'No student results are available yet.', 'exam-management' ) . '</p>';
		}

		wp_enqueue_style( 'em-top-students' );

		return self::render_markup( $data );
	}

	/**
	 * Get cached top students grouped by academic term.
	 *
	 * @param int $limit Students per term.
	 * @return array
	 */
	public static function get_top_students_by_term( $limit = self::DEFAULT_LIMIT ) {
		$limit    = max( 1, (int) $limit );
		$cache_key = self::get_cache_key( $limit );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$data = self::build_top_students_data( $limit );

		set_transient( $cache_key, $data, HOUR_IN_SECONDS );

		return $data;
	}

	/**
	 * Build top student rankings from stored results.
	 *
	 * @param int $limit Students per term.
	 * @return array
	 */
	private static function build_top_students_data( $limit ) {
		$terms = self::get_terms_ordered();

		if ( empty( $terms ) ) {
			return array();
		}

		$exam_term_map = self::get_exam_term_map();
		$result_rows   = self::get_result_rows();

		if ( empty( $exam_term_map ) || empty( $result_rows ) ) {
			return array();
		}

		$term_stats = array();

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

				if ( ! isset( $term_stats[ $term_id ][ $student_id ] ) ) {
					$term_stats[ $term_id ][ $student_id ] = array(
						'total_marks' => 0.0,
						'exam_count'  => 0,
					);
				}

				$term_stats[ $term_id ][ $student_id ]['total_marks'] += $mark;
				$term_stats[ $term_id ][ $student_id ]['exam_count']++;
			}
		}

		if ( empty( $term_stats ) ) {
			return array();
		}

		$student_ids = array();
		foreach ( $term_stats as $students ) {
			$student_ids = array_merge( $student_ids, array_keys( $students ) );
		}
		$student_names = self::get_student_names( array_unique( $student_ids ) );

		$output = array();

		foreach ( $terms as $term ) {
			$term_id = (int) $term['term_id'];

			if ( empty( $term_stats[ $term_id ] ) ) {
				continue;
			}

			$ranked_students = self::rank_students( $term_stats[ $term_id ], $student_names, $limit );

			if ( empty( $ranked_students ) ) {
				continue;
			}

			$output[] = array(
				'term_id'    => $term_id,
				'term_name'  => $term['name'],
				'start_date' => $term['start_date'],
				'end_date'   => $term['end_date'],
				'students'   => $ranked_students,
			);
		}

		return $output;
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
				exam_meta.post_id AS result_id,
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

	/**
	 * Load student names in a single query.
	 *
	 * @param int[] $student_ids Student post IDs.
	 * @return array<int, string>
	 */
	private static function get_student_names( array $student_ids ) {
		if ( empty( $student_ids ) ) {
			return array();
		}

		$student_ids = array_map( 'intval', $student_ids );
		$students    = get_posts(
			array(
				'post_type'              => EM_Result_Admin::STUDENT_POST_TYPE,
				'post_status'            => 'publish',
				'post__in'               => $student_ids,
				'posts_per_page'         => count( $student_ids ),
				'orderby'                => 'post__in',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$names = array();

		foreach ( $students as $student ) {
			$names[ $student->ID ] = $student->post_title;
		}

		return $names;
	}

	/**
	 * Rank students for a single academic term.
	 *
	 * @param array           $students      Student stats keyed by student ID.
	 * @param array<int, string> $student_names Student names keyed by ID.
	 * @param int             $limit         Number of students to return.
	 * @return array
	 */
	private static function rank_students( array $students, array $student_names, $limit ) {
		$ranked = array();

		foreach ( $students as $student_id => $stats ) {
			if ( empty( $student_names[ $student_id ] ) ) {
				continue;
			}

			$exam_count = max( 1, (int) $stats['exam_count'] );

			$ranked[] = array(
				'id'           => (int) $student_id,
				'name'         => $student_names[ $student_id ],
				'total_marks'  => round( (float) $stats['total_marks'], 2 ),
				'exam_count'   => (int) $stats['exam_count'],
				'average_mark' => round( (float) $stats['total_marks'] / $exam_count, 2 ),
			);
		}

		usort(
			$ranked,
			function ( $left, $right ) {
				if ( $left['total_marks'] !== $right['total_marks'] ) {
					return $right['total_marks'] <=> $left['total_marks'];
				}

				if ( $left['average_mark'] !== $right['average_mark'] ) {
					return $right['average_mark'] <=> $left['average_mark'];
				}

				return strcasecmp( $left['name'], $right['name'] );
			}
		);

		return array_slice( $ranked, 0, $limit );
	}

	/**
	 * Render shortcode markup.
	 *
	 * @param array $data Top students grouped by term.
	 * @return string
	 */
	private static function render_markup( array $data ) {
		ob_start();
		?>
		<div class="em-top-students">
			<div class="em-top-students__header">
				<h2 class="em-top-students__title"><?php esc_html_e( 'Top Students', 'exam-management' ); ?></h2>
				<p class="em-top-students__subtitle"><?php esc_html_e( 'Highest performers by academic term', 'exam-management' ); ?></p>
			</div>

			<div class="em-top-students__grid">
				<?php foreach ( $data as $term_data ) : ?>
					<section class="em-top-students__term">
						<header class="em-top-students__term-header">
							<h3 class="em-top-students__term-title">
								<?php echo esc_html( $term_data['term_name'] ); ?>
							</h3>
							<?php if ( ! empty( $term_data['start_date'] ) && ! empty( $term_data['end_date'] ) ) : ?>
								<p class="em-top-students__term-dates">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: start date, 2: end date */
											__( '%1$s – %2$s', 'exam-management' ),
											$term_data['start_date'],
											$term_data['end_date']
										)
									);
									?>
								</p>
							<?php endif; ?>
						</header>

						<ol class="em-top-students__list">
							<?php foreach ( $term_data['students'] as $index => $student ) : ?>
								<?php
								$rank        = $index + 1;
								$rank_class  = 'em-top-students__item--rank-' . $rank;
								$rank_labels = array(
									1 => __( '1st', 'exam-management' ),
									2 => __( '2nd', 'exam-management' ),
									3 => __( '3rd', 'exam-management' ),
								);
								$rank_label  = isset( $rank_labels[ $rank ] ) ? $rank_labels[ $rank ] : '#' . $rank;
								?>
								<li class="em-top-students__item <?php echo esc_attr( $rank_class ); ?>">
									<div class="em-top-students__rank" aria-hidden="true">
										<span class="em-top-students__rank-label"><?php echo esc_html( $rank_label ); ?></span>
									</div>
									<div class="em-top-students__content">
										<span class="em-top-students__student-name"><?php echo esc_html( $student['name'] ); ?></span>
										<div class="em-top-students__stats">
											<span class="em-top-students__stat">
												<span class="em-top-students__stat-value"><?php echo esc_html( number_format_i18n( $student['total_marks'], 2 ) ); ?></span>
												<span class="em-top-students__stat-label"><?php esc_html_e( 'Total', 'exam-management' ); ?></span>
											</span>
											<span class="em-top-students__stat">
												<span class="em-top-students__stat-value"><?php echo esc_html( number_format_i18n( $student['average_mark'], 2 ) ); ?></span>
												<span class="em-top-students__stat-label"><?php esc_html_e( 'Average', 'exam-management' ); ?></span>
											</span>
										</div>
									</div>
								</li>
							<?php endforeach; ?>
						</ol>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Build a cache key for the shortcode output.
	 *
	 * @param int $limit Students per term.
	 * @return string
	 */
	private static function get_cache_key( $limit ) {
		return self::CACHE_KEY . '_v' . self::CACHE_VERSION . '_' . (int) $limit;
	}

	/**
	 * Clear cached shortcode data.
	 */
	public static function clear_cache() {
		delete_transient( self::get_cache_key( self::DEFAULT_LIMIT ) );

		/**
		 * Allow other limits to be cleared if themes use custom shortcode limits.
		 */
		for ( $limit = 1; $limit <= 10; $limit++ ) {
			delete_transient( self::get_cache_key( $limit ) );
		}
	}
}
