<?php
/**
 * The settings and embroidery packs of the product designer.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\ProductDesigner\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\ProductDesigner\DesignerException;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoAccount;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulToken;
use MerchBoard\Modules\ProductDesigner\ThreadPacks;

defined( 'ABSPATH' ) || exit;

/**
 * The kill switches of the product designer (ADR-080), Printful (ADR-081)
 * and Gelato (ADR-082), and the embroidery packs, as tabs of the designer's
 * page (DesignerMenu) for users with `merchboard_manage_designer`. All
 * switches are off by default. Read-only rendering with its own form, nonce and
 * notices; every change goes through DesignerSettingsActions. Everything is
 * escaped at output.
 */
final class DesignerSettingsSection {

	/**
	 * Constructor.
	 *
	 * @param PrintfulToken|null $token  The Printful token (step 30.12).
	 * @param GelatoAccount|null $gelato The Gelato key and store (step 30.13).
	 */
	public function __construct( private ?PrintfulToken $token = null, private ?GelatoAccount $gelato = null ) {
	}

	/**
	 * Message and type for an outcome code; null for unknown codes.
	 *
	 * @param string $code Outcome code.
	 * @return array{0: string, 1: string}|null [message, 'success'|'info'|'error']
	 */
	public static function result_message( string $code ): ?array {
		return match ( $code ) {
			'saved'                           => array( __( 'The product designer settings were saved.', 'merchboard' ), 'success' ),
			'no_changes'                      => array( __( 'No changes were made to the product designer settings.', 'merchboard' ), 'info' ),
			DesignerException::NOT_AUTHORIZED => array( __( 'You are not allowed to change these settings.', 'merchboard' ), 'error' ),
			DesignerException::PRINTFUL_TOKEN => array( __( 'The Printful token was not saved: use the token as Printful shows it (letters, digits, - and _).', 'merchboard' ), 'error' ),
			DesignerException::GELATO_ACCOUNT => array( __( 'The Gelato settings were not saved: use the API key as Gelato shows it and the store id from the store\'s address (a UUID).', 'merchboard' ), 'error' ),
			DesignerException::THREAD_PACK    => array( __( 'The embroidery pack was not saved: it needs a name and at least one thread, each thread a unique code, a name and a colour, and a limit between 1 and the number of threads.', 'merchboard' ), 'error' ),
			DesignerException::THREAD_PACK_IN_USE => array( __( 'A base product uses this embroidery pack, so it cannot be deleted.', 'merchboard' ), 'error' ),
			'pack_deleted'                    => array( __( 'The embroidery pack was deleted.', 'merchboard' ), 'success' ),
			'error'                           => array( __( 'A technical error occurred. The product designer settings were not changed.', 'merchboard' ), 'error' ),
			default                           => null,
		};
	}

	/**
	 * The notice of the last change.
	 */
	private static function render_notice(): void {
		// Read-only outcome code; nothing is changed by this request.
		$code   = isset( $_GET[ DesignerSettingsActions::RESULT_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ DesignerSettingsActions::RESULT_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = self::result_message( $code );

		if ( null !== $notice ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[1] ), esc_html( $notice[0] ) );
		}
	}

	/**
	 * Renders the settings.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			return;
		}

		$fields = array(
			Settings::DESIGNER_ENABLED => array(
				__( 'Product designer', 'merchboard' ),
				__( 'Creators can design their own products', 'merchboard' ),
				__( 'Off by default. New products and changes always wait for an administrator\'s approval. Products that were not made in the designer are never changed by it.', 'merchboard' ),
				Settings::designer_enabled(),
			),
			Settings::PRINTFUL_ENABLED => array(
				__( 'Printful', 'merchboard' ),
				__( 'Use Printful for base products and mockups', 'merchboard' ),
				__( 'Off by default. Only the catalog and the mockup generator are used; orders are not sent to Printful.', 'merchboard' ),
				Settings::printful_enabled(),
			),
			Settings::GELATO_ENABLED   => array(
				__( 'Gelato', 'merchboard' ),
				__( 'Use Gelato for base products and mockups', 'merchboard' ),
				__( 'Off by default. Only the catalog and mockups from your Gelato templates are used; orders are not sent to Gelato.', 'merchboard' ),
				Settings::gelato_enabled(),
			),
		);

		self::render_notice();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( DesignerSettingsActions::SAVE_ACTION ) . '">';
		wp_nonce_field( DesignerSettingsActions::SAVE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $fields as $key => [ $title, $label, $help, $on ] ) {
			echo '<tr><th scope="row">' . esc_html( $title ) . '</th><td>';
			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . checked( $on, true, false ) . '> ' . esc_html( $label ) . '</label>';
			echo '<p class="description">' . esc_html( $help ) . '</p>';
			echo '</td></tr>';
		}

		$configured = null !== $this->token && $this->token->configured();

		echo '<tr><th scope="row"><label for="merchboard-printful-token">' . esc_html__( 'Printful API token', 'merchboard' ) . '</label></th><td>';
		echo '<input type="password" id="merchboard-printful-token" name="printful_token" value="" class="regular-text" autocomplete="off"> ';
		echo '<span>' . esc_html( $configured ? __( 'A token is stored.', 'merchboard' ) : __( 'No token yet.', 'merchboard' ) ) . '</span>';

		if ( $configured ) {
			echo '<br><label><input type="checkbox" name="printful_token_remove" value="1"> ' . esc_html__( 'Remove the stored token', 'merchboard' ) . '</label>';
		}

		echo '<p class="description">' . esc_html__( 'A private token from the Printful Developer Portal with as few scopes as possible (the catalog and the mockup generator need no store access). It is stored encrypted and never shown again; leave the field empty to keep the stored one.', 'merchboard' ) . '</p>';
		echo '<details style="margin-top:8px"><summary>' . esc_html__( 'How to set up Printful', 'merchboard' ) . '</summary><ol>';

		foreach (
			array(
				__( 'Create an account at printful.com and a store of the type "Manual order platform / API" that is not connected to a web shop, so nothing is published or sold through Printful.', 'merchboard' ),
				__( 'In Printful\'s Developer Portal (developers.printful.com), create a private token with access to that one store and as few scopes as possible. Do not choose orders, sync products or webhooks: Merch Dashboard never creates orders or products at Printful.', 'merchboard' ),
				__( 'Paste the token above, switch Printful on and save.', 'merchboard' ),
				__( 'Import catalog products under Merch Dashboard → Product designer → Base products ("Import from Printful"), check them, set the base price and activate them.', 'merchboard' ),
				__( 'Printful fetches print files from a short-lived link on this site, so mockups from Printful only work on a site Printful can reach (not on a local test site).', 'merchboard' ),
			) as $step
		) {
			echo '<li>' . esc_html( $step ) . '</li>';
		}

		echo '</ol></details>';
		echo '</td></tr>';
		$this->render_gelato();
		echo '</tbody></table>';
		submit_button( __( 'Save product designer settings', 'merchboard' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * The embroidery packs: one form per pack and one for a new pack.
	 */
	public function render_packs(): void {
		if ( ! current_user_can( Capabilities::MANAGE_DESIGNER ) ) {
			return;
		}

		self::render_notice();

		echo '<p class="description">' . esc_html__( 'A pack holds the thread colours creators may use and the most of them on one embroidered side. Choose a pack on each embroidered side of a base product. A change counts from the next time a design is saved; products in the shop and sent designs do not change.', 'merchboard' ) . '</p>';

		foreach ( ThreadPacks::all() as $pack ) {
			$this->render_pack( $pack );
		}

		$this->render_pack( null );
	}

	/**
	 * The form of a pack.
	 *
	 * @param array{id: string, name: string, max: int, threads: list<array{code: string, name: string, hex: string}>}|null $pack The pack; null for a new one.
	 */
	private function render_pack( ?array $pack ): void {
		$threads = array_merge( $pack['threads'] ?? array(), array_fill( 0, null === $pack ? 6 : 3, array( 'code' => '', 'name' => '', 'hex' => '#000000' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		/* translators: 1: pack name, 2: number of threads. */
		$title = null === $pack ? __( 'New embroidery pack', 'merchboard' ) : sprintf( __( '%1$s (%2$d threads)', 'merchboard' ), $pack['name'], count( $pack['threads'] ) );

		echo '<details class="card" style="max-width:none;margin-bottom:1em"><summary><strong>' . esc_html( $title ) . '</strong></summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( DesignerSettingsActions::PACK_ACTION ) . '"><input type="hidden" name="pack_id" value="' . esc_attr( $pack['id'] ?? '' ) . '">';
		wp_nonce_field( DesignerSettingsActions::PACK_ACTION );
		echo '<p><label>' . esc_html__( 'Name', 'merchboard' ) . ' <input type="text" name="pack_name" maxlength="60" value="' . esc_attr( $pack['name'] ?? '' ) . '"></label> ';
		echo '<label>' . esc_html__( 'Most threads on one side', 'merchboard' ) . ' <input type="number" name="pack_max" min="1" max="' . esc_attr( (string) ThreadPacks::MAX_THREADS ) . '" step="1" style="width:5rem" value="' . esc_attr( (string) ( $pack['max'] ?? 6 ) ) . '"></label></p>';
		echo '<table class="widefat striped" style="max-width:40rem"><thead><tr><th>' . esc_html__( 'Code', 'merchboard' ) . '</th><th>' . esc_html__( 'Name', 'merchboard' ) . '</th><th>' . esc_html__( 'Colour', 'merchboard' ) . '</th><th>' . esc_html__( 'Remove', 'merchboard' ) . '</th></tr></thead><tbody>';

		foreach ( $threads as $index => $thread ) {
			$field = static fn( string $name ): string => 'threads[' . $index . '][' . $name . ']';

			echo '<tr><td><input type="text" maxlength="16" style="width:6rem" name="' . esc_attr( $field( 'code' ) ) . '" value="' . esc_attr( $thread['code'] ) . '"></td>';
			echo '<td><input type="text" maxlength="40" name="' . esc_attr( $field( 'name' ) ) . '" value="' . esc_attr( $thread['name'] ) . '"></td>';
			echo '<td><input type="color" name="' . esc_attr( $field( 'hex' ) ) . '" value="' . esc_attr( $thread['hex'] ) . '"></td>';
			echo '<td>' . ( '' === $thread['code'] ? '' : '<input type="checkbox" name="' . esc_attr( $field( 'remove' ) ) . '" value="1">' ) . '</td></tr>';
		}

		echo '</tbody></table><p><button type="submit" class="button button-secondary">' . esc_html__( 'Save embroidery pack', 'merchboard' ) . '</button>';

		if ( null !== $pack ) {
			echo ' <button type="submit" name="do" value="delete" class="button-link button-link-delete">' . esc_html__( 'Delete embroidery pack', 'merchboard' ) . '</button>';
		}

		echo '</p></form></details>';
	}

	/**
	 * The Gelato API key and store id with the setup guide (step 30.13).
	 */
	private function render_gelato(): void {
		$stored = null !== $this->gelato && null !== $this->gelato->key();
		$store  = null === $this->gelato ? null : $this->gelato->store();

		echo '<tr><th scope="row"><label for="merchboard-gelato-key">' . esc_html__( 'Gelato API key', 'merchboard' ) . '</label></th><td>';
		echo '<input type="password" id="merchboard-gelato-key" name="gelato_key" value="" class="regular-text" autocomplete="off"> ';
		echo '<span>' . esc_html( $stored ? __( 'A key is stored.', 'merchboard' ) : __( 'No key yet.', 'merchboard' ) ) . '</span>';

		if ( $stored ) {
			echo '<br><label><input type="checkbox" name="gelato_key_remove" value="1"> ' . esc_html__( 'Remove the stored key', 'merchboard' ) . '</label>';
		}

		echo '<p class="description">' . esc_html__( 'An API key from Gelato\'s dashboard. It is stored encrypted and never shown again; leave the field empty to keep the stored one.', 'merchboard' ) . '</p>';
		echo '<p><label for="merchboard-gelato-store">' . esc_html__( 'Gelato store id', 'merchboard' ) . '</label><br>';
		echo '<input type="text" id="merchboard-gelato-store" name="gelato_store" value="' . esc_attr( (string) $store ) . '" class="regular-text" autocomplete="off" pattern="[0-9a-fA-F-]{36}"></p>';
		echo '<details style="margin-top:8px"><summary>' . esc_html__( 'How to set up Gelato', 'merchboard' ) . '</summary><ol>';

		foreach (
			array(
				__( 'Create an account at gelato.com and a store that is only used through the API and is not connected to a web shop or sales channel, so nothing is published or sold through Gelato. Products made for mockups stay in that store.', 'merchboard' ),
				__( 'Create an API key in Gelato\'s dashboard (Developer → API keys) and copy the store id from the store\'s address.', 'merchboard' ),
				__( 'Paste the key and the store id above, switch Gelato on and save.', 'merchboard' ),
				__( 'In Gelato\'s dashboard, make a template per product: the product, the colours and sizes creators may choose, and one image placeholder per side. Import it under Merch Dashboard → Product designer → Base products ("Import from Gelato") with its template id, check it, set the base price and activate it.', 'merchboard' ),
				__( 'Gelato fetches print files from a short-lived link on this site, so mockups from Gelato only work on a site Gelato can reach (not on a local test site).', 'merchboard' ),
			) as $step
		) {
			echo '<li>' . esc_html( $step ) . '</li>';
		}

		echo '</ol></details>';
		echo '</td></tr>';
	}
}
