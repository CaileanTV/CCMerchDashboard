<?php
/**
 * Creator box on the WordPress user profile screen.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorRepository;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * On user-edit.php / profile.php (hooks `edit_user_profile`,
 * `show_user_profile`), shows administrators with
 * `merchboard_manage_creators` whether the user is linked to a creator, with
 * a link to edit it or to create a creator for this user. Nothing is shown to
 * anyone else, and nothing is changed from here. Only the creator's business
 * name and status are shown; no additional user data.
 */
final class UserProfileSection {

	/**
	 * Constructor.
	 *
	 * @param CreatorRepository $creators Creator storage (reads only).
	 */
	public function __construct( private CreatorRepository $creators ) {
	}

	/**
	 * Registers the profile hooks.
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
	}

	/**
	 * Renders the box.
	 *
	 * @param mixed $user Hook argument: the profile's WP_User.
	 */
	public function render( mixed $user ): void {
		if ( ! $user instanceof WP_User || $user->ID < 1 || ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			return;
		}

		try {
			$creator = $this->creators->find_by_user_id( $user->ID );
		} catch ( DatabaseException ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Merch Dashboard', 'merchboard' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'Creator', 'merchboard' ) . '</th><td>';

		if ( null === $creator ) {
			echo esc_html__( 'This user is not linked to a creator.', 'merchboard' ) . ' ';
			echo '<a href="' . esc_url( CreatorsAdminPage::url( array( 'action' => 'new', 'user_id' => $user->ID ) ) ) . '">' . esc_html__( 'Create a creator for this user', 'merchboard' ) . '</a>'; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		} else {
			echo wp_kses(
				sprintf(
					/* translators: 1: creator name (link), 2: status. */
					esc_html__( 'Linked to %1$s (%2$s).', 'merchboard' ),
					'<a href="' . esc_url( CreatorsAdminPage::edit_url( $creator->id ) ) . '">' . esc_html( $creator->display_name ) . '</a>',
					esc_html( CreatorsAdminPage::status_label( $creator->status ) )
				),
				array( 'a' => array( 'href' => true ) )
			);
		}

		echo '</td></tr></tbody></table>';
	}
}
