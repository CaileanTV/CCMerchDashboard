<?php
/**
 * The public sign-up page below the dashboard's slug.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Signup;

use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\Agreement\Agreement;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Dashboard\DashboardRoute;
use MerchBoard\Modules\Dashboard\ShellController;

defined( 'ABSPATH' ) || exit;

/**
 * `/<dashboard-slug>/opret` (ADR-091): answered on `template_redirect`
 * before the dashboard shell, because it is the one dashboard page that
 * does not require a login. A plain server-rendered form (no scripts) with
 * the shell's security headers; a POST goes to the same address with a
 * nonce and is answered with Post/Redirect/Get. A creator who is logged in
 * goes to the dashboard. While sign-up is switched off or no agreement is
 * published the page answers 404.
 */
final class SignupPage {

	public const PATH = 'opret';

	public const ACTION = 'merchboard_signup';

	/**
	 * Constructor.
	 *
	 * @param DashboardRoute    $route     The dashboard's route.
	 * @param CreatorSignup     $signup    Sign-ups.
	 * @param Agreement         $agreement The agreement.
	 * @param CreatorRepository $creators  Creators.
	 * @param Logger            $logger    Technical log.
	 */
	public function __construct( private DashboardRoute $route, private CreatorSignup $signup, private Agreement $agreement, private CreatorRepository $creators, private Logger $logger ) {
	}

	/**
	 * The page's URL.
	 */
	public function url(): string {
		return $this->route->url( self::PATH );
	}

	/**
	 * WordPress entry point (`template_redirect`, before the shell).
	 */
	public function handle_request(): void {
		if ( ! $this->route->is_request() || self::PATH !== $this->route->path() ) {
			return;
		}

		foreach ( ShellController::security_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}

		nocache_headers();
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$page   = $this->respond( 'POST' === $method );

		if ( isset( $page['redirect'] ) && is_string( $page['redirect'] ) ) {
			wp_safe_redirect( $page['redirect'] );
			exit;
		}

		status_header( is_int( $page['status'] ?? null ) ? $page['status'] : 200 );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		$merchboard_view = $page;
		require dirname( __DIR__, 4 ) . '/templates/signup/signup.php';
		exit;
	}

	/**
	 * What the page answers: a redirect, or the template's view data.
	 *
	 * @param bool $posted Whether the form was sent.
	 * @return array<string, mixed>
	 */
	public function respond( bool $posted ): array {
		$common = array(
			'site_name' => get_bloginfo( 'name' ),
			'action'    => $this->url(),
		);

		try {
			$user_id = get_current_user_id();

			if ( $user_id > 0 && null !== $this->creators->find_by_user_id( $user_id ) ) {
				return array( 'redirect' => $this->route->url() );
			}

			$current = $this->agreement->current();

			if ( null === $current || ! $this->signup->open() ) {
				return array_merge( $common, array( 'status' => 404, 'state' => 'closed' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short.
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'The sign-up page could not be shown.', $e->context() );

			return array_merge( $common, array( 'status' => 503, 'state' => 'error' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short.
		}

		// Read-only state from the redirect after a sign-up.
		if ( ! $posted && isset( $_GET['sent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
			return array_merge( $common, array( 'state' => 'sent' ) );
		}

		$form = array_merge(
			$common,
			array(
				'state'     => 'form',
				'logged_in' => $user_id > 0,
				'agreement' => Agreement::rendered( $current->body ),
				'version'   => $current->version,
				'started'   => CreatorSignup::form_token(),
				'nonce'     => wp_create_nonce( self::ACTION ),
				'values'    => array(),
				'error'     => '',
			)
		);

		if ( ! $posted ) {
			return $form;
		}

		// The values are sanitized by CreatorSignup.
		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce is verified below; CreatorSignup sanitizes.

		if ( ! wp_verify_nonce( is_string( $input['_wpnonce'] ?? null ) ? $input['_wpnonce'] : '', self::ACTION ) ) {
			return array_merge( $form, array( 'error' => SignupException::EXPIRED_FORM ) );
		}

		try {
			$outcome = $this->signup->submit( $input, self::address() );
		} catch ( SignupException $e ) {
			return array_merge(
				$form,
				array(
					'error'  => $e->reason(),
					'values' => array(
						'name'    => is_string( $input['name'] ?? null ) ? sanitize_text_field( $input['name'] ) : '',
						'email'   => is_string( $input['email'] ?? null ) ? sanitize_email( $input['email'] ) : '',
						'channel' => is_string( $input['channel'] ?? null ) ? esc_url_raw( $input['channel'] ) : '',
					),
				)
			);
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A sign-up could not be stored.', $e->context() );

			return array_merge( $form, array( 'error' => 'error' ) );
		}

		return array( 'redirect' => 'linked' === $outcome ? $this->route->url() : add_query_arg( 'sent', '1', $this->url() ) );
	}

	/**
	 * The visitor's address as the web server saw it (never a forwarded
	 * header, which anyone can set). Only a keyed hash of it is kept.
	 */
	private static function address(): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return '' === $address ? 'unknown' : $address;
	}
}
