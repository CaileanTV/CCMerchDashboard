<?php
/**
 * "Import" tab of the Alerts admin page.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Modules\Alerts\Import\MerchAlertImporter;
use MerchBoard\Modules\Alerts\Import\MerchAlertImportException;
use MerchBoard\Modules\Alerts\Import\MerchAlertImportPlan;
use MerchBoard\Modules\Alerts\Import\MerchAlertImportReport;

defined( 'ABSPATH' ) || exit;

/**
 * The import from MerchAlert (step 25.10.1, ADR-065 pkt. 10): what is
 * imported and what is not, a dry run (read-only, `preview=1`) and the
 * confirmation form with the dry run's fingerprint and the backup checkbox
 * (MerchAlertImportActions). The page itself changes nothing.
 */
final class MerchAlertImportTab {

	/**
	 * The tab's key in the page URL.
	 */
	public const TAB = 'import';

	/**
	 * Query argument: show the dry run.
	 */
	public const PREVIEW_ARG = 'preview';

	/**
	 * Constructor.
	 *
	 * @param MerchAlertImporter $importer The import.
	 */
	public function __construct( private MerchAlertImporter $importer ) {
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'imported'                                    => array( __( 'The import is done. Check the creators\' overlays, then switch alerts on in Settings → Alerts.', 'merchboard' ), 'success' ),
			'import_error'                                => array( __( 'The import stopped because of a technical error. What was imported is kept; run the dry run again to import the rest.', 'merchboard' ), 'error' ),
			MerchAlertImportException::MERCHALERT_ACTIVE => array( __( 'Deactivate MerchAlert first (do not delete it).', 'merchboard' ), 'error' ),
			MerchAlertImportException::NO_DATA           => array( __( 'No MerchAlert data was found.', 'merchboard' ), 'error' ),
			MerchAlertImportException::PREVIEW_OUTDATED  => array( __( 'The data changed since the dry run. Check the new dry run and import again.', 'merchboard' ), 'warning' ),
			MerchAlertImportException::BACKUP_REQUIRED   => array( __( 'Confirm that you have a backup before you import.', 'merchboard' ), 'error' ),
			default                                       => null,
		};
	}

	/**
	 * Renders the tab.
	 *
	 * @param bool $preview Whether to show the dry run.
	 */
	public function render( bool $preview ): void {
		echo '<p>' . esc_html__( 'Moves the alerts from the old plugin MerchAlert once: every creator\'s overlay with the same URL, settings and sounds, the alert history, the chatbot\'s template, switch and sent messages, the global overlay (switched off), the approved fonts and Google Fonts key, the sound limits and the username field. A MerchAlert creator is matched by their product category. MerchAlert\'s data is only read, never changed.', 'merchboard' ) . '</p>';
		echo '<ul class="ul-disc"><li>' . esc_html__( 'Take a backup of the site first.', 'merchboard' ) . '</li><li>' . esc_html__( 'Deactivate MerchAlert, but do not delete it: deleting it deletes its data.', 'merchboard' ) . '</li><li>' . esc_html__( 'Twitch logins are not moved: log the bot in again under Chatbot, and creators add the bot to their channel again.', 'merchboard' ) . '</li><li>' . esc_html__( 'Customers\' first names are not moved; alerts show the username.', 'merchboard' ) . '</li></ul>';

		$this->render_last();

		try {
			if ( ! $preview ) {
				$this->importer->preview_check();
				echo '<p><a class="button button-primary" href="' . esc_url( add_query_arg( self::PREVIEW_ARG, '1', AlertsAdminPage::url( self::TAB ) ) ) . '">' . esc_html__( 'Dry run', 'merchboard' ) . '</a></p>';

				return;
			}

			$report = $this->importer->preview();
		} catch ( MerchAlertImportException $e ) {
			$message = self::result_message( $e->reason() );
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $message[1] ?? 'error' ), esc_html( $message[0] ?? '' ) );

			return;
		} catch ( DatabaseException ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'MerchAlert\'s data could not be read. Please try again later.', 'merchboard' ) . '</p></div>';

			return;
		}

		$this->render_report( $report );
	}

	/**
	 * The last import.
	 */
	private function render_last(): void {
		$last = get_option( MerchAlertImportPlan::MARKER_OPTION, null );

		if ( ! is_array( $last ) || ! is_string( $last['completed_at'] ?? null ) ) {
			return;
		}

		$counts = is_array( $last['last'] ?? null ) ? $last['last'] : array();

		printf(
			'<p><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: 1: date and time, 2: creators, 3: alerts, 4: chat messages. */
					__( 'Last import: %1$s (%2$d creators, %3$d alerts, %4$d chat messages added).', 'merchboard' ),
					get_date_from_gmt( $last['completed_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
					(int) ( $counts['creators'] ?? 0 ),
					(int) ( $counts['events'] ?? 0 ),
					(int) ( $counts['messages'] ?? 0 )
				)
			)
		);
	}

	/**
	 * The dry run and the confirmation form.
	 *
	 * @param MerchAlertImportReport $report The dry run.
	 */
	private function render_report( MerchAlertImportReport $report ): void {
		echo '<h2>' . esc_html__( 'Dry run', 'merchboard' ) . '</h2>';
		echo '<h3>' . esc_html__( 'Creators', 'merchboard' ) . '</h3>';

		if ( array() === $report->creators ) {
			echo '<p>' . esc_html__( 'MerchAlert has no creators.', 'merchboard' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'MerchAlert creator', 'merchboard' ) . '</th><th>' . esc_html__( 'Category', 'merchboard' ) . '</th><th>' . esc_html__( 'Creator here', 'merchboard' ) . '</th><th>' . esc_html__( 'What happens', 'merchboard' ) . '</th><th>' . esc_html__( 'Alerts', 'merchboard' ) . '</th><th>' . esc_html__( 'Chat messages', 'merchboard' ) . '</th><th>' . esc_html__( 'Sounds', 'merchboard' ) . '</th><th>' . esc_html__( 'Chatbot', 'merchboard' ) . '</th></tr></thead><tbody>';

			foreach ( $report->creators as $row ) {
				$term     = $row['term_id'] > 0 ? get_term( $row['term_id'], 'product_cat' ) : null;
				$category = $term instanceof \WP_Term ? $term->name : ( $row['term_id'] > 0 ? '#' . $row['term_id'] : '—' );
				$imports  = MerchAlertImportReport::imports( $row['status'] );

				printf(
					'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td><td>%7$s</td><td>%8$s</td></tr>',
					esc_html( $row['name'] ),
					esc_html( $category ),
					esc_html( '' === $row['creator_name'] ? '—' : $row['creator_name'] ),
					esc_html( self::status_label( $row['status'] ) ),
					esc_html( $imports ? (string) $row['events'] : '—' ),
					esc_html( $imports ? (string) $row['messages'] : '—' ),
					esc_html( $imports ? (string) $row['sounds'] : '—' ),
					esc_html( $imports ? ( $row['chatbot'] ? __( 'Yes', 'merchboard' ) : __( 'No', 'merchboard' ) ) : '—' )
				);
			}

			echo '</tbody></table>';
		}

		$settings = $report->settings;

		echo '<h3>' . esc_html__( 'Global overlay and settings', 'merchboard' ) . '</h3>';

		if ( $settings['done'] ) {
			echo '<p>' . esc_html__( 'Imported already: the global overlay, the settings and the fonts are not imported again.', 'merchboard' ) . '</p>';
		} else {
			echo '<ul class="ul-disc">';
			printf( '<li>%s</li>', esc_html( self::global_label( $report->global_overlay['status'], $report->global_overlay['sounds'] ) ) );
			printf(
				'<li>%s</li>',
				esc_html(
					null === $settings['sound_max_bytes'] || null === $settings['sound_max_seconds']
						? __( 'Sound limits: not valid in MerchAlert; the current limits stay.', 'merchboard' )
						/* translators: 1: largest file, e.g. "10 MB", 2: seconds. */
						: sprintf( __( 'Sound limits: %1$s and %2$d seconds.', 'merchboard' ), size_format( $settings['sound_max_bytes'] ), $settings['sound_max_seconds'] )
				)
			);
			printf(
				'<li>%s</li>',
				esc_html(
					$settings['username_enabled']
						? __( 'Username at checkout: switched on.', 'merchboard' )
						: __( 'Username at checkout: switched off.', 'merchboard' )
				)
			);
			/* translators: %d: number of fonts. */
			printf( '<li>%s</li>', esc_html( sprintf( __( 'Approved fonts: %d.', 'merchboard' ), $settings['fonts'] ) ) );
			printf(
				'<li>%s</li>',
				esc_html(
					$settings['api_key']
						? __( 'Google Fonts API key: moved, unless one is saved here already.', 'merchboard' )
						: __( 'Google Fonts API key: none in MerchAlert.', 'merchboard' )
				)
			);
			echo '</ul>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( MerchAlertImportActions::ACTION ) . '">';
		echo '<input type="hidden" name="fingerprint" value="' . esc_attr( $report->fingerprint() ) . '">';
		wp_nonce_field( MerchAlertImportActions::ACTION );
		echo '<p><label><input type="checkbox" name="backup" value="1" required> ' . esc_html__( 'I have taken a backup of the site, and MerchAlert is deactivated, not deleted.', 'merchboard' ) . '</label></p>';
		submit_button( __( 'Import', 'merchboard' ), 'primary', 'submit', false );
		echo '</form>';
	}

	/**
	 * What happens to a creator.
	 *
	 * @param string $status Status.
	 */
	private static function status_label( string $status ): string {
		return match ( $status ) {
			MerchAlertImportReport::NEW         => __( 'Imported (new overlay)', 'merchboard' ),
			MerchAlertImportReport::REPLACE     => __( 'Imported (replaces the creator\'s current overlay)', 'merchboard' ),
			MerchAlertImportReport::IMPORTED    => __( 'Imported already: only missing alerts and chat messages are added', 'merchboard' ),
			MerchAlertImportReport::NO_CATEGORY => __( 'Skipped: no category in MerchAlert', 'merchboard' ),
			MerchAlertImportReport::NO_MATCH    => __( 'Skipped: the category belongs to no creator here', 'merchboard' ),
			MerchAlertImportReport::NO_OVERLAY  => __( 'Skipped: no overlay in MerchAlert', 'merchboard' ),
			MerchAlertImportReport::DUPLICATE   => __( 'Skipped: another MerchAlert creator is imported into the same creator', 'merchboard' ),
			default                             => __( 'Skipped: the overlay id is used here already', 'merchboard' ),
		};
	}

	/**
	 * What happens to the global overlay.
	 *
	 * @param string $status Status.
	 * @param int    $sounds Sounds that stay.
	 */
	private static function global_label( string $status, int $sounds ): string {
		return match ( $status ) {
			MerchAlertImportReport::NONE     => __( 'Global overlay: none in MerchAlert.', 'merchboard' ),
			MerchAlertImportReport::ID_TAKEN => __( 'Global overlay: skipped, its id is used by a creator here.', 'merchboard' ),
			MerchAlertImportReport::IMPORTED => __( 'Global overlay: imported already.', 'merchboard' ),
			/* translators: %d: number of sounds. */
			MerchAlertImportReport::REPLACE  => sprintf( __( 'Global overlay: replaces the current one with MerchAlert\'s URL and settings (%d sounds), switched off.', 'merchboard' ), $sounds ),
			/* translators: %d: number of sounds. */
			default                          => sprintf( __( 'Global overlay: imported with MerchAlert\'s URL and settings (%d sounds), switched off.', 'merchboard' ), $sounds ),
		};
	}
}
