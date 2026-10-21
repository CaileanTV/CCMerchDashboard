<?php
/**
 * Admin tab: the YouTube chatbot's privacy policy and terms.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Admin;

use MerchBoard\Modules\Alerts\Legal\CompanyDetails;
use MerchBoard\Modules\Alerts\Legal\YouTubeLegal;
use MerchBoard\Modules\Settings\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Merch Dashboard → Alerts → YouTube terms (phase 25, step 8.5, ADR-071):
 * the privacy policy and terms in English and Danish, their public URLs for
 * Google's OAuth consent screen, and whether they are published. Saving goes
 * through ChatbotAdminActions. Read-only rendering; everything is escaped
 * (the texts in their text areas).
 */
final class YouTubeLegalTab {

	/**
	 * Constructor.
	 *
	 * @param YouTubeLegal $legal The texts.
	 */
	public function __construct( private YouTubeLegal $legal ) {
	}

	/**
	 * Renders the tab.
	 */
	public function render(): void {
		$placeholders = $this->legal->placeholders();

		echo '<h2>' . esc_html__( 'Privacy policy and terms of the YouTube chatbot', 'merchboard' ) . '</h2>';
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Google requires a privacy policy and terms of service for the YouTube app. The texts below are a draft that describes what the chatbot does; they are not legal advice. Review them, fill in the company details and publish them.', 'merchboard' ) . '</p></div>';

		if ( $this->legal->published() ) {
			echo '<p><strong>' . esc_html(
				sprintf(
					/* translators: 1: version number, 2: date. */
					__( 'Published: version %1$d of %2$s. Creators and the bot must have accepted this version; until then no YouTube messages are sent to them.', 'merchboard' ),
					$this->legal->version(),
					(string) wp_date( (string) get_option( 'date_format' ), $this->legal->published_at() )
				)
			) . '</strong></p>';
		} else {
			echo '<p><strong>' . esc_html__( 'Draft: the pages are not public yet, and YouTube cannot be used.', 'merchboard' ) . '</strong> ';

			if ( array() === $placeholders ) {
				echo esc_html__( 'Save the texts to publish them.', 'merchboard' ) . '</p>';
			} else {
				/* translators: %s: the placeholders without a value, e.g. "[COMPANY], [EMAIL]". */
				echo esc_html( sprintf( __( 'Fill in the company details for these placeholders, then save the texts to publish them: %s', 'merchboard' ), implode( ', ', $placeholders ) ) ) . '</p>';
			}
		}

		if ( $this->legal->published() && array() !== $placeholders ) {
			/* translators: %s: the placeholders without a value, e.g. "[COMPANY], [EMAIL]". */
			echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( __( 'The public pages show these placeholders, because their company details are empty: %s', 'merchboard' ), implode( ', ', $placeholders ) ) ) . '</p></div>';
		}

		echo '<h3>' . esc_html__( 'Company details', 'merchboard' ) . '</h3>';
		echo '<p>' . esc_html__( 'The placeholders in the texts are filled from the settings when the pages are shown; a changed detail needs no new version.', 'merchboard' ) . ' <a href="' . esc_url( SettingsPage::url() . '#merchboard-company' ) . '">' . esc_html__( 'Edit the company details', 'merchboard' ) . '</a></p>';
		echo '<table class="widefat striped" style="max-width:60rem"><tbody>';

		foreach ( CompanyDetails::values() as $placeholder => $value ) {
			echo '<tr><th scope="row"><code>' . esc_html( $placeholder ) . '</code></th><td>' . ( '' === $value ? '<em>' . esc_html__( 'Not registered', 'merchboard' ) . '</em>' : esc_html( $value ) ) . '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Public pages', 'merchboard' ) . '</h3>';
		echo '<p>' . esc_html__( 'Enter these URLs in the Google Cloud console (Google Auth Platform → Branding): the app home page, the privacy policy and the terms of service. Add the site\'s domain as an authorized domain. Then publish the app (Audience → "In production"), so logins no longer expire after 7 days.', 'merchboard' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:60rem"><thead><tr><th>' . esc_html__( 'Page', 'merchboard' ) . '</th><th>English</th><th>Dansk</th></tr></thead><tbody>';

		foreach ( array(
			'home'    => __( 'App home page', 'merchboard' ),
			'privacy' => __( 'Privacy policy', 'merchboard' ),
			'terms'   => __( 'Terms of service', 'merchboard' ),
		) as $page => $label ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th>';

			foreach ( YouTubeLegal::LANGUAGES as $language ) {
				$url = $this->legal->url( $page, $language );
				echo '<td><code>' . esc_html( $url ) . '</code>' . ( $this->legal->published() ? ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open', 'merchboard' ) . '</a>' : '' ) . '</td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<h3>' . esc_html__( 'Texts', 'merchboard' ) . '</h3>';
		echo '<p>' . esc_html__( 'HTML is allowed (headings, paragraphs, lists, links); scripts are removed. Keep the placeholders [COMPANY], [CVR], [ADDRESS] and [EMAIL]: they are filled from the company details.', 'merchboard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( ChatbotAdminActions::LEGAL_ACTION ) . '">';
		wp_nonce_field( ChatbotAdminActions::LEGAL_ACTION );

		foreach ( array(
			'privacy' => array(
				'en' => __( 'Privacy policy (English)', 'merchboard' ),
				'da' => __( 'Privacy policy (Danish)', 'merchboard' ),
			),
			'terms'   => array(
				'en' => __( 'Terms of service (English)', 'merchboard' ),
				'da' => __( 'Terms of service (Danish)', 'merchboard' ),
			),
		) as $document => $languages ) {
			foreach ( $languages as $language => $label ) {
				$key = YouTubeLegal::key( $document, $language );
				echo '<p><label for="merchboard-legal-' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label></p>';
				echo '<textarea id="merchboard-legal-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" rows="14" class="large-text code" lang="' . esc_attr( $language ) . '">' . esc_textarea( $this->legal->text( $document, $language ) ) . '</textarea>';
			}
		}

		if ( $this->legal->published() ) {
			echo '<p><label><input type="checkbox" name="renew" value="1"> ' . esc_html__( 'This is a change that matters: everyone must accept the texts again (a new version). Without it, the change is a correction of the current version.', 'merchboard' ) . '</label></p>';
		}

		submit_button( $this->legal->published() ? __( 'Save', 'merchboard' ) : __( 'Save and publish', 'merchboard' ) );
		echo '</form>';
	}
}
