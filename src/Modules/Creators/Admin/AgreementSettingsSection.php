<?php
/**
 * Settings → Agreement: the creators' agreement and its versions.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\Legal\CompanyDetails;
use MerchBoard\Modules\Creators\Agreement\Agreement;
use MerchBoard\Modules\Creators\Agreement\AgreementException;
use MerchBoard\Modules\Creators\Agreement\AgreementRepository;
use MerchBoard\Modules\Settings\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * The tab "Agreement" (ADR-092): the draft in a text area, publishing it as
 * a new version (material or not), the company details that fill the
 * placeholders and the published versions with their acceptances. Saving
 * goes through admin-post with a nonce; requires
 * `merchboard_manage_creators`. Everything is escaped.
 */
final class AgreementSettingsSection {

	public const TAB = 'agreement';

	public const ACTION = 'merchboard_agreement';

	public const RESULT_ARG = 'merchboard_agreement';

	/**
	 * Constructor.
	 *
	 * @param Agreement           $agreement  The agreement.
	 * @param AgreementRepository $agreements Versions and acceptances.
	 * @param Logger              $logger     Technical log.
	 */
	public function __construct( private Agreement $agreement, private AgreementRepository $agreements, private Logger $logger ) {
	}

	/**
	 * Registers the tab and the form handler.
	 */
	public function register(): void {
		SettingsPage::add_tab( self::TAB, static fn(): string => __( 'Agreement', 'merchboard' ) );
		add_action( 'merchboard_settings_sections', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * The tab's URL with an outcome code.
	 *
	 * @param string $result Outcome code ('' for none).
	 */
	public static function url( string $result = '' ): string {
		$url = SettingsPage::url( '', self::TAB );

		return '' === $result ? $url : add_query_arg( array( self::RESULT_ARG => $result ), $url );
	}

	/**
	 * Handles the form and redirects back.
	 */
	public function handle_request(): void {
		check_admin_referer( self::ACTION );
		// The nonce is checked above; the values are sanitized by the service.
		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Agreement (wp_kses_post).
		wp_safe_redirect( self::url( $this->handle( $input ) ) );
		exit;
	}

	/**
	 * Saves the draft or publishes it.
	 *
	 * @param array<mixed> $input Form input (unslashed).
	 * @return string Outcome code.
	 */
	public function handle( array $input ): string {
		$text = is_string( $input['text'] ?? null ) ? $input['text'] : '';

		try {
			if ( isset( $input['publish'] ) ) {
				$material = is_string( $input['material'] ?? null ) ? $input['material'] : '';
				$this->agreement->publish( $text, '1' === $material );

				return 'published';
			}

			$this->agreement->save_draft( $text );

			return 'saved';
		} catch ( AgreementException $e ) {
			return $e->reason();
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The agreement could not be saved.', $e->context() );

			return 'error';
		}
	}

	/**
	 * Renders the tab.
	 *
	 * @param mixed $tab The open settings tab (only its own renders).
	 */
	public function render( mixed $tab = self::TAB ): void {
		if ( self::TAB !== $tab || ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			return;
		}

		// Read-only notice code; nothing is changed by this request.
		$code   = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::message( $code );

		echo '<h2>' . esc_html__( 'Agreement for creators who sign up', 'merchboard' ) . '</h2>';

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}

		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The text is a draft written from your earlier cooperation agreement; it is not legal advice. Have it reviewed before you publish it. Creators who sign up accept the published version; existing creators and creators you create yourself do not.', 'merchboard' ) . '</p></div>';

		try {
			$current  = $this->agreement->current();
			$versions = $this->agreements->versions();
			$draft    = $this->agreement->draft();
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The agreement could not be read.', $e->context() );
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The agreement could not be read. Try again later.', 'merchboard' ) . '</p></div>';

			return;
		}

		if ( null === $current ) {
			echo '<p><strong>' . esc_html__( 'Not published yet: creators cannot sign up until a version is published.', 'merchboard' ) . '</strong></p>';
		} else {
			echo '<p><strong>' . esc_html(
				sprintf(
					/* translators: 1: version number, 2: date. */
					__( 'Published: version %1$d of %2$s.', 'merchboard' ),
					$current->version,
					(string) wp_date( (string) get_option( 'date_format' ), $current->published_at->getTimestamp() )
				)
			) . '</strong></p>';
		}

		$missing = Agreement::missing_placeholders( $draft );

		echo '<h3>' . esc_html__( 'Company details', 'merchboard' ) . '</h3>';
		echo '<p>' . esc_html__( 'The placeholders in the text are filled from the company details when creators read it. A version can only be published when each placeholder in it has a value.', 'merchboard' ) . ' <a href="' . esc_url( SettingsPage::url() . '#merchboard-company' ) . '">' . esc_html__( 'Edit the company details', 'merchboard' ) . '</a></p>';
		echo '<table class="widefat striped" style="max-width:60rem"><tbody>';

		foreach ( CompanyDetails::values() as $placeholder => $value ) {
			echo '<tr><th scope="row"><code>' . esc_html( $placeholder ) . '</code></th><td>' . ( '' === $value ? '<em>' . esc_html__( 'Not registered', 'merchboard' ) . '</em>' : esc_html( $value ) ) . '</td></tr>';
		}

		echo '</tbody></table>';

		if ( array() !== $missing ) {
			/* translators: %s: the placeholders without a value, e.g. "[CVR], [EMAIL]". */
			echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( __( 'Fill in the company details for these placeholders before you publish: %s', 'merchboard' ), implode( ', ', $missing ) ) ) . '</p></div>';
		}

		echo '<h3>' . esc_html__( 'Text', 'merchboard' ) . '</h3>';
		echo '<p>' . esc_html__( 'HTML is allowed (headings, paragraphs, lists, links); scripts are removed. "Save draft" keeps your changes without creators seeing them; "Publish" makes the text the next version.', 'merchboard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<label for="merchboard-agreement-text" class="screen-reader-text">' . esc_html__( 'Agreement text', 'merchboard' ) . '</label>';
		echo '<textarea id="merchboard-agreement-text" name="text" rows="24" class="large-text code" lang="da">' . esc_textarea( $draft ) . '</textarea>';

		if ( null !== $current ) {
			echo '<p><label><input type="checkbox" name="material" value="1"> ' . esc_html__( 'This is a material change: creators who signed up must accept the new version again before they can request a payout. Without it, they are only told about the update.', 'merchboard' ) . '</label></p>';
		}

		echo '<p>';
		submit_button( __( 'Save draft', 'merchboard' ), 'secondary', 'save', false );
		echo ' ';
		submit_button( null === $current ? __( 'Publish version 1', 'merchboard' ) : __( 'Publish as a new version', 'merchboard' ), 'primary', 'publish', false );
		echo '</p></form>';

		echo '<h3>' . esc_html__( 'Published versions', 'merchboard' ) . '</h3>';

		if ( array() === $versions ) {
			echo '<p>' . esc_html__( 'None yet.', 'merchboard' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped" style="max-width:60rem"><thead><tr><th>' . esc_html__( 'Version', 'merchboard' ) . '</th><th>' . esc_html__( 'Published', 'merchboard' ) . '</th><th>' . esc_html__( 'Change', 'merchboard' ) . '</th><th>' . esc_html__( 'Accepted by', 'merchboard' ) . '</th></tr></thead><tbody>';

		foreach ( $versions as $row ) {
			$version = $row['version'];
			echo '<tr><td>' . esc_html( (string) $version->version ) . '</td>';
			echo '<td>' . esc_html( (string) wp_date( (string) get_option( 'date_format' ), $version->published_at->getTimestamp() ) ) . '</td>';
			echo '<td>' . esc_html( $version->material ? __( 'Must be accepted', 'merchboard' ) : __( 'Minor update', 'merchboard' ) ) . '</td>';
			/* translators: %d: number of creators. */
			echo '<td>' . esc_html( sprintf( _n( '%d creator', '%d creators', $row['acceptances'], 'merchboard' ), $row['acceptances'] ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The message of an outcome code.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null Message and notice type.
	 */
	private static function message( string $code ): ?array {
		return match ( $code ) {
			'saved'                             => array( __( 'The draft was saved. Creators do not see it until you publish it.', 'merchboard' ), 'success' ),
			'published'                         => array( __( 'The agreement was published as a new version.', 'merchboard' ), 'success' ),
			AgreementException::EMPTY_TEXT      => array( __( 'Not published: the text is empty.', 'merchboard' ), 'error' ),
			AgreementException::PLACEHOLDERS    => array( __( 'Not published: fill in the company details for every placeholder in the text first.', 'merchboard' ), 'error' ),
			AgreementException::NOT_AUTHORIZED  => array( __( 'You are not allowed to manage creators.', 'merchboard' ), 'error' ),
			'error'                             => array( __( 'A technical error occurred. Nothing was published; try again later.', 'merchboard' ), 'error' ),
			default                             => null,
		};
	}
}
