<?php
/**
 * Integration tests for the creator's thank-you note on the packing slip (phase 58).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Community;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Community\Rest\MeThankYouNoteController;
use MerchBoard\Modules\Community\ThankYouNote\ThankYouNoteRepository;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The creator's note through `/me/thank-you-note`.
 */
final class ThankYouNoteTest extends IntegrationTestCase {

	/**
	 * The creator's user.
	 *
	 * @var int
	 */
	private int $user;

	/**
	 * The creator.
	 *
	 * @var int
	 */
	private int $nova;

	/**
	 * The notes.
	 *
	 * @var ThankYouNoteRepository
	 */
	private ThankYouNoteRepository $notes;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$this->user  = $this->create_user( 'thank-you-nova' );
		$this->nova  = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $this->user )->id;
		$this->notes = new ThankYouNoteRepository( $this->service( CreatorMetaStore::class ), $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_the_creator_writes_a_note_that_is_validated_and_audited_without_its_text(): void {
		$controller = new MeThankYouNoteController( $this->service( CreatorContext::class ), $this->notes, new RecordingLogger() );

		wp_set_current_user( 1 );
		self::assertInstanceOf( WP_Error::class, $controller->permission(), 'Only a creator.' );
		wp_set_current_user( $this->user );
		self::assertTrue( $controller->permission() );

		$empty = $controller->get_note();
		self::assertInstanceOf( WP_REST_Response::class, $empty );
		self::assertSame(
			array(
				'enabled' => false,
				'text'    => '',
				'limits'  => array(
					'max_text'  => 300,
					'max_lines' => 6,
				),
			),
			$empty->get_data()
		);
		self::assertSame( 'no-store, private', $empty->get_headers()['Cache-Control'] ?? null );

		$refused = $controller->save( $this->json( array( 'enabled' => true, 'text' => 'See more at https://shop.example' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertInstanceOf( WP_Error::class, $refused );
		self::assertSame( array( 'text' => 'has_link' ), $refused->get_error_data()['fields'] ?? null );

		$saved = $controller->save( $this->json( array( 'enabled' => true, 'text' => "  Tak fordi du støtter mig! <b>❤</b>\r\nKh. Nova  \n\n" ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertInstanceOf( WP_REST_Response::class, $saved );
		self::assertSame( array( true, "Tak fordi du støtter mig! ❤\nKh. Nova" ), array( $saved->get_data()['enabled'] ?? null, $saved->get_data()['text'] ?? null ), 'Plain text; line breaks kept.' );

		$context = json_decode( (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT context FROM %i WHERE action = %s ORDER BY id DESC LIMIT 1', $this->table( Tables::AUDIT_LOG ), 'thank_you_note_changed' ) ), true );
		self::assertIsArray( $context );
		self::assertSame( array( 'enabled', 'text' ), $context['changed_fields'] ?? null );
		self::assertStringNotContainsString( 'Tak fordi', (string) wp_json_encode( $context ), 'Never the text.' );

		// The same values again change nothing.
		$controller->save( $this->json( array( 'enabled' => true, 'text' => "Tak fordi du støtter mig! ❤\nKh. Nova" ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertSame( 1, $this->audits() );

		// Off keeps the text for later and prints nothing.
		$off = $this->notes->save( $this->nova, array( 'enabled' => false, 'text' => "Tak fordi du støtter mig! ❤\nKh. Nova" ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertSame( array( false, '' ), array( $off->enabled, $off->printed() ) );
		self::assertSame( "Tak fordi du støtter mig! ❤\nKh. Nova", $this->notes->find( $this->nova )->text );
		self::assertSame( 2, $this->audits() );

		// Plain text, not HTML: a heart stays a heart.
		self::assertSame( 'Tak <3 & > alt', $this->notes->save( $this->nova, array( 'enabled' => true, 'text' => "Tak <3 & > alt\x07" ) )->text ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		self::assertSame( 300, mb_strlen( $this->notes->save( $this->nova, array( 'enabled' => true, 'text' => str_repeat( '<3', 150 ) ) )->text ), 'Not longer on the server than in the form.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
	}

	public function test_invalid_notes_are_refused(): void {
		foreach ( array(
			array( array( 'enabled' => 'yes' ), array( 'enabled' => 'required' ) ),
			array( array( 'enabled' => true, 'text' => '' ), array( 'text' => 'required' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
			array( array( 'enabled' => true, 'text' => str_repeat( 'a', 301 ) ), array( 'text' => 'too_long' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
			array( array( 'enabled' => true, 'text' => "1\n2\n3\n4\n5\n6\n7" ), array( 'text' => 'too_many_lines' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
			array( array( 'enabled' => true, 'text' => 'Find me on www.example.dk' ), array( 'text' => 'has_link' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
			array( array( 'enabled' => true, 'text' => array( 'x' ) ), array( 'text' => 'invalid' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		) as [ $values, $fields ] ) {
			try {
				$this->notes->save( $this->nova, $values );
				self::fail( 'Refused: ' . (string) wp_json_encode( $values ) );
			} catch ( TwitchSubDiscountInvalid $e ) {
				self::assertSame( $fields, $e->fields() );
			}
		}

		self::assertSame( 0, $this->audits() );
		self::assertNull( $this->service( CreatorMetaStore::class )->get( $this->nova, CreatorMetaStore::THANK_YOU_NOTE ) );
	}

	public function test_the_note_is_deleted_with_the_creator_s_meta(): void {
		$this->notes->save( $this->nova, array( 'enabled' => true, 'text' => 'Tak!' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.

		self::assertGreaterThanOrEqual( 1, $this->service( CreatorMetaStore::class )->delete_all( $this->nova ) );
		self::assertSame( '', $this->notes->find( $this->nova )->text );
	}

	/**
	 * The audited changes of the note.
	 */
	private function audits(): int {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE action = %s', $this->table( Tables::AUDIT_LOG ), 'thank_you_note_changed' ) );
	}

	/**
	 * A JSON request to the route.
	 *
	 * @param array<string, mixed> $body Body.
	 */
	private function json( array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/merchboard/v1' . MeThankYouNoteController::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return $request;
	}
}
