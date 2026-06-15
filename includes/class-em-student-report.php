<?php
/**
 * Admin student statistics report and PDF export.
 *
 * @package ExamManagement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the student statistics report in admin and exports PDF.
 */
class EM_Student_Report {

	const PAGE_SLUG        = 'em-student-statistics';
	const EXPORT_ACTION    = 'em_export_student_report_pdf';
	const NONCE_ACTION     = 'em_student_report_export';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'handle_pdf_export' ) );
	}

	/**
	 * Register the statistics report page under Results.
	 */
	public static function register_admin_page() {
		add_submenu_page(
			'edit.php?post_type=' . EM_Result_Admin::POST_TYPE,
			__( 'Student Statistics', 'exam-management' ),
			__( 'Student Statistics', 'exam-management' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Render the statistics report page.
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'exam-management' ) );
		}

		$report = EM_Student_Stats::get_report_data();
		?>
		<div class="wrap em-student-report-wrap">
			<h1><?php esc_html_e( 'Student Statistics Report', 'exam-management' ); ?></h1>
			<p><?php esc_html_e( 'View total marks per academic term and the average marks across all terms for every student.', 'exam-management' ); ?></p>

			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::get_export_url() ); ?>">
					<?php esc_html_e( 'Export PDF', 'exam-management' ); ?>
				</a>
			</p>

			<?php echo self::render_report_table( $report ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
	}

	/**
	 * Handle PDF export requests.
	 */
	public static function handle_pdf_export() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to export this report.', 'exam-management' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$report = EM_Student_Stats::get_report_data();
		$html   = self::render_report_document( $report );

		if ( ! self::load_pdf_library() ) {
			wp_die( esc_html__( 'The PDF library is not available. Please run composer install in the plugin directory.', 'exam-management' ) );
		}

		$dompdf = new Dompdf\Dompdf(
			array(
				'isRemoteEnabled' => true,
			)
		);

		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4', count( $report['terms'] ) > 2 ? 'landscape' : 'portrait' );
		$dompdf->render();

		$filename = 'student-statistics-' . gmdate( 'Y-m-d' ) . '.pdf';
		$dompdf->stream( $filename, array( 'Attachment' => true ) );
		exit;
	}

	/**
	 * Load the Dompdf library if available.
	 *
	 * @return bool
	 */
	private static function load_pdf_library() {
		$autoload = EM_PLUGIN_DIR . 'vendor/autoload.php';

		if ( ! file_exists( $autoload ) ) {
			return false;
		}

		require_once $autoload;

		return class_exists( 'Dompdf\Dompdf' );
	}

	/**
	 * Get the PDF export URL.
	 *
	 * @return string
	 */
	public static function get_export_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::EXPORT_ACTION ),
			self::NONCE_ACTION
		);
	}

	/**
	 * Render the report table for the admin screen.
	 *
	 * @param array $report Report data.
	 * @return string
	 */
	private static function render_report_table( array $report ) {
		ob_start();
		?>
		<div class="em-student-report-table-wrap">
			<table class="widefat striped em-student-report-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Student', 'exam-management' ); ?></th>
						<?php foreach ( $report['terms'] as $term ) : ?>
							<th><?php echo esc_html( $term['term_name'] ); ?></th>
						<?php endforeach; ?>
						<th><?php esc_html_e( 'Average', 'exam-management' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $report['students'] ) ) : ?>
						<tr>
							<td colspan="<?php echo esc_attr( (string) ( count( $report['terms'] ) + 2 ) ); ?>">
								<?php esc_html_e( 'No students found.', 'exam-management' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $report['students'] as $student ) : ?>
							<tr>
								<td><?php echo esc_html( $student['name'] ); ?></td>
								<?php foreach ( $report['terms'] as $term ) : ?>
									<td><?php echo esc_html( self::format_term_total( $student, $term['term_id'] ) ); ?></td>
								<?php endforeach; ?>
								<td><strong><?php echo esc_html( number_format_i18n( $student['average'], 2 ) ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render a complete HTML document for PDF export.
	 *
	 * @param array $report Report data.
	 * @return string
	 */
	private static function render_report_document( array $report ) {
		$generated_at = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="utf-8">
			<title><?php esc_html_e( 'Student Statistics Report', 'exam-management' ); ?></title>
			<style>
				body {
					font-family: DejaVu Sans, sans-serif;
					color: #111827;
					font-size: 12px;
				}
				h1 {
					font-size: 20px;
					margin: 0 0 8px;
				}
				.meta {
					color: #6b7280;
					margin-bottom: 18px;
				}
				table {
					width: 100%;
					border-collapse: collapse;
				}
				th,
				td {
					border: 1px solid #d1d5db;
					padding: 8px;
					text-align: left;
				}
				th {
					background: #f3f4f6;
				}
				.average {
					font-weight: bold;
				}
			</style>
		</head>
		<body>
			<h1><?php esc_html_e( 'Student Statistics Report', 'exam-management' ); ?></h1>
			<p class="meta">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: generated date/time */
						__( 'Generated on %s', 'exam-management' ),
						$generated_at
					)
				);
				?>
			</p>
			<table>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Student', 'exam-management' ); ?></th>
						<?php foreach ( $report['terms'] as $term ) : ?>
							<th><?php echo esc_html( $term['term_name'] ); ?></th>
						<?php endforeach; ?>
						<th><?php esc_html_e( 'Average', 'exam-management' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $report['students'] as $student ) : ?>
						<tr>
							<td><?php echo esc_html( $student['name'] ); ?></td>
							<?php foreach ( $report['terms'] as $term ) : ?>
								<td><?php echo esc_html( self::format_term_total( $student, $term['term_id'] ) ); ?></td>
							<?php endforeach; ?>
							<td class="average"><?php echo esc_html( number_format_i18n( $student['average'], 2 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</body>
		</html>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Format a student's total marks for a term.
	 *
	 * @param array $student Student row.
	 * @param int   $term_id Term ID.
	 * @return string
	 */
	private static function format_term_total( array $student, $term_id ) {
		if ( ! isset( $student['term_totals'][ $term_id ] ) || null === $student['term_totals'][ $term_id ] ) {
			return '—';
		}

		return number_format_i18n( (float) $student['term_totals'][ $term_id ], 2 );
	}
}
