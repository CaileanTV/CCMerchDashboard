<?php
/**
 * User suggestions for the creator form (admin-ajax.php).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Admin;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\UserAccounts;

defined( 'ABSPATH' ) || exit;

/**
 * `wp_ajax_merchboard_search_users`: suggestions for the WordPress user field
 * so the selector scales to sites with many users (phase 10).
 *
 * - capability `merchboard_manage_creators` and a nonce, checked server-side;
 * - searches login and display name only (never e-mail), at least two
 *   characters, at most MAX_RESULTS results;
 * - returns only login and "Display Name (login)" (what the form already
 *   shows); users linked to a creator are left out;
 * - suggestions are a convenience: the saved value is validated again by
 *   CreatorAdminActions and CreatorService.
 */
final class CreatorUserSearch {

	/**
	 * The ajax action and nonce action.
	 */
	public const ACTION = 'merchboard_search_users';

	/**
	 * Maximum number of suggestions.
	 */
	public const MAX_RESULTS = 20;

	/**
	 * Constructor.
	 *
	 * @param UserAccounts      $users    User search.
	 * @param CreatorRepository $creators Linked users (excluded).
	 */
	public function __construct( private UserAccounts $users, private CreatorRepository $creators ) {
	}

	/**
	 * Registers the ajax handler (logged-in users only; no nopriv handler).
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * WordPress entry point.
	 */
	public function handle_request(): void {
		// The nonce is verified in search() before the term is used.
		$result = $this->search( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and term sanitized in search().

		if ( 200 === $result['status'] ) {
			wp_send_json_success( $result['data'] );
		}

		wp_send_json_error( null, $result['status'] );
	}

	/**
	 * Suggestions for the request.
	 *
	 * @param array<mixed> $input Unslashed request data (`term`, `nonce`).
	 * @return array{status: int, data: list<array{login: string, label: string}>}
	 */
	public function search( array $input ): array {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			return array(
				'status' => 403,
				'data'   => array(),
			);
		}

		$nonce = is_scalar( $input['nonce'] ?? null ) ? (string) $input['nonce'] : '';

		if ( 1 !== wp_verify_nonce( $nonce, self::ACTION ) ) {
			return array(
				'status' => 403,
				'data'   => array(),
			);
		}

		$term = sanitize_text_field( is_scalar( $input['term'] ?? null ) ? (string) $input['term'] : '' );

		try {
			$linked = $this->creators->linked_user_ids();
		} catch ( DatabaseException ) {
			$linked = array();
		}

		$suggestions = array();

		foreach ( $this->users->search( $term, self::MAX_RESULTS ) as $user ) {
			if ( ! in_array( $user['id'], $linked, true ) ) {
				$suggestions[] = array(
					'login' => $user['login'],
					'label' => $user['label'],
				);
			}
		}

		return array(
			'status' => 200,
			'data'   => $suggestions,
		);
	}
}
