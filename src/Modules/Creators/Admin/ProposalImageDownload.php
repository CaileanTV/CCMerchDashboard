<?php
/**
 * A proposed category image for the administrator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Admin;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Creators\Proposals\CategoryProposals;
use MerchBoard\Modules\Creators\Proposals\ProposalFiles;

defined( 'ABSPATH' ) || exit;

/**
 * `admin-post.php?action=merchboard_category_proposal_image&proposal=…&_wpnonce=…`:
 * the image of a proposal that is still waiting (phase 28, step 28.4,
 * ADR-077), shown inline on the "Category proposals" page. Order of checks:
 * capability `merchboard_manage_creators` (403) → nonce bound to the
 * proposal (403) → a waiting file (404). Sent as the image with `nosniff`
 * and `no-store`; never a public URL. No AJAX, REST or `nopriv` route.
 */
final class ProposalImageDownload {

	public const ACTION = 'merchboard_category_proposal_image';

	/**
	 * Constructor.
	 *
	 * @param CategoryProposals $proposals Proposals.
	 * @param ProposalFiles     $files     Proposed images.
	 */
	public function __construct( private CategoryProposals $proposals, private ProposalFiles $files ) {
	}

	/**
	 * Nonce action of one proposal's image.
	 *
	 * @param int $proposal_id Proposal id.
	 */
	public static function nonce_action( int $proposal_id ): string {
		return self::ACTION . '_' . $proposal_id;
	}

	/**
	 * The image's URL for an administrator.
	 *
	 * @param int $proposal_id Proposal id.
	 */
	public static function url( int $proposal_id ): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'proposal' => $proposal_id,
				'_wpnonce' => wp_create_nonce( self::nonce_action( $proposal_id ) ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Hooks the handler (logged-in users only).
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
	}

	/**
	 * Sends the image of the current request, or an error page.
	 */
	public function handle_request(): void {
		// The nonce is verified in response() before any input is used.
		$response = $this->response( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified and every field validated in response().

		if ( 200 !== $response['status'] ) {
			wp_die( esc_html( $response['body'] ), '', array( 'response' => (int) $response['status'] ) );
		}

		nocache_headers();

		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}

		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- An image the server validated, sent with its type and nosniff.
		exit;
	}

	/**
	 * The response to a request: the image, or a status with a message.
	 *
	 * @param array<mixed> $input Unslashed GET data.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	public function response( array $input ): array {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			return self::failure( 403, __( 'Sorry, you are not allowed to manage creators.', 'merchboard' ) );
		}

		$value       = $input['proposal'] ?? '';
		$proposal_id = is_scalar( $value ) && 1 === preg_match( '/^[1-9]\d{0,18}$/', (string) $value ) ? (int) $value : 0;
		$nonce       = is_scalar( $input['_wpnonce'] ?? null ) ? (string) $input['_wpnonce'] : '';

		if ( 0 === $proposal_id || '' === $nonce || false === wp_verify_nonce( $nonce, self::nonce_action( $proposal_id ) ) ) {
			return self::failure( 403, __( 'The link you followed has expired. Please try again.', 'merchboard' ) );
		}

		$file = $this->proposals->find( $proposal_id )?->file();
		$path = null === $file ? null : $this->files->path( $file );
		$body = null === $path ? false : file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of at most 5 MB.

		if ( null === $file || false === $body ) {
			return self::failure( 404, __( 'The image is no longer waiting for approval.', 'merchboard' ) );
		}

		return array(
			'status'  => 200,
			'headers' => array(
				'Content-Type'           => $file->mime,
				'Content-Disposition'    => 'inline',
				'Cache-Control'          => 'private, no-store',
				'X-Content-Type-Options' => 'nosniff',
			),
			'body'    => $body,
		);
	}

	/**
	 * A failed response.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Message.
	 * @return array{status: int, headers: array<string, string>, body: string}
	 */
	private static function failure( int $status, string $message ): array {
		return array(
			'status'  => $status,
			'headers' => array(),
			'body'    => $message,
		);
	}
}
