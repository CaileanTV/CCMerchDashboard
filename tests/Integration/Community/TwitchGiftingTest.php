<?php
/**
 * Twitch gifting: settings, the gift's statuses and the draws (ADR-107).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Community;

use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Access\CreatorContext;
use MerchBoard\Modules\Community\Rest\MeTwitchGiftingController;
use MerchBoard\Modules\Community\Dashboard\CommunityDashboardSection;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatCipher;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
use MerchBoard\Modules\Alerts\Chatbot\CustomerSessionStore;
use MerchBoard\Modules\Alerts\Chatbot\TwitchCustomerSession;
use MerchBoard\Modules\Alerts\Rest\ShopTwitchController;
use MerchBoard\Modules\Community\Gifting\GiftClaim;
use MerchBoard\Modules\Community\Admin\GiftingTestModeSection;
use MerchBoard\Modules\Community\Gifting\Gift;
use MerchBoard\Modules\Community\Gifting\GiftCodes;
use MerchBoard\Modules\Community\Gifting\GiftDraws;
use MerchBoard\Modules\Community\Gifting\GiftingSettingsRepository;
use MerchBoard\Modules\Community\Gifting\GiftRepository;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\FakeGiftCodes;
use MerchBoard\Tests\Unit\Support\RecordingLogger;

/**
 * The chat, Twitch's live status and the job queue are recorded closures.
 * Nova has a connected Twitch channel; Orion has none.
 */
final class TwitchGiftingTest extends IntegrationTestCase {

	/**
	 * Messages sent, as [creator, text].
	 *
	 * @var list<array{0: int, 1: string}>
	 */
	private array $sent = array();

	/**
	 * Jobs queued, as [hook, args, delay].
	 *
	 * @var list<array{0: string, 1: array<mixed>, 2: int}>
	 */
	private array $jobs = array();

	/**
	 * Reminder jobs queued, as [args, delay].
	 *
	 * @var list<array{0: array<mixed>, 1: int}>
	 */
	private array $reminders = array();

	/**
	 * Events, as [event, gift id, data].
	 *
	 * @var list<array{0: string, 1: int, 2: array<string, string>}>
	 */
	private array $events = array();

	private bool $live = true;

	private int $nova;

	private int $orion;

	private int $order = 9000;

	private GiftRepository $gifts;

	private GiftingSettingsRepository $settings;

	private GiftDraws $draws;

	protected function setUp(): void {
		parent::setUp();
		$this->install();
		// Both community features are off by default (ADR-108).
		Settings::save( array_merge( Settings::all(), array( Settings::COMMUNITY_TWITCH_SUB_DISCOUNT_ENABLED => true, Settings::COMMUNITY_TWITCH_GIFTING_ENABLED => true ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.

		$creators    = new CreatorRepository( $this->wpdb );
		$this->nova  = $creators->create( 'Nova', CreatorStatus::Active )->id;
		$this->orion = $creators->create( 'Orion', CreatorStatus::Active )->id;
		$channels    = new ChatbotChannelRepository( $this->wpdb );
		$channels->connect( $this->nova, ChatPlatform::Twitch, '4711', 'nova' );

		$this->gifts    = new GiftRepository( $this->wpdb, $this->service( Transaction::class ) );
		$this->settings = new GiftingSettingsRepository(
			$this->service( CreatorMetaStore::class ),
			$this->service( AuditTrail::class ),
			$this->service( Transaction::class ),
			static fn( int $creator_id ): bool => $channels->find( $creator_id, ChatPlatform::Twitch )->connected()
		);
		$this->draws    = new GiftDraws(
			$this->gifts,
			$this->settings,
			new GiftCodes( $this->gifts, new RecordingLogger() ),
			function ( int $creator_id, string $message ): bool {
				$this->sent[] = array( $creator_id, $message );

				return true;
			},
			fn(): bool => $this->live,
			static fn( string $token ): string => 'https://shop.test/?merchboard_gift_claim=' . $token,
			static fn(): string => 'Stream tee',
			function ( string $hook, array $args, int $delay ): bool {
				if ( GiftDraws::REMIND_HOOK === $hook ) {
					$this->reminders[] = array( $args, $delay );
				} else {
					$this->jobs[] = array( $hook, $args, $delay );
				}

				return true;
			},
			function ( string $event, Gift $gift, array $data ): void {
				$this->events[] = array( $event, $gift->id, $data );
			},
			new RecordingLogger()
		);
		$this->draws->register();
	}

	protected function tearDown(): void {
		delete_transient( 'merchboard_gift_run_' . $this->nova );
		delete_transient( 'merchboard_gift_tried_' . $this->nova );
		$this->execute( 'DELETE FROM %i WHERE option_name LIKE %s', $this->wpdb->options, '%merchboard_gift_confirm_%' );
		$this->execute( 'DELETE FROM %i WHERE option_name LIKE %s', $this->wpdb->options, '%merchboard_gift_reveal_%' );
		$this->execute( 'DELETE FROM %i WHERE option_name LIKE %s', $this->wpdb->options, '%merchboard_gift_remind_%' );
		remove_all_actions( 'merchboard_twitch_chat_message' );
		remove_all_actions( GiftDraws::DRAW_HOOK );
		remove_all_actions( GiftDraws::EXPIRE_HOOK );
		remove_all_actions( GiftDraws::CONFIRM_HOOK );
		remove_all_actions( GiftDraws::REMIND_HOOK );
		delete_option( Settings::OPTION );
		parent::tearDown();
	}

	public function test_gifting_needs_a_connected_channel_and_is_audited(): void {
		try {
			$this->settings->save( $this->orion, array( 'window' => 10 ) + $this->values( true ) );
			self::fail( 'Orion has no channel.' );
		} catch ( TwitchSubDiscountInvalid $e ) {
			self::assertSame(
				array(
					'enabled' => 'not_connected',
					'window'  => 'invalid',
				),
				$e->fields()
			);
		}

		$saved = $this->settings->save( $this->nova, array( 'window' => 45 ) + $this->values( true ) );

		self::assertTrue( $saved->open() );
		self::assertSame( 45, $this->settings->find( $this->nova )->window );
		$audit = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT action, context FROM %i ORDER BY id DESC LIMIT 1', $this->table( Tables::AUDIT_LOG ) ), ARRAY_A );
		self::assertSame( 'twitch_gifting_changed', $audit['action'] ?? null );
		self::assertSame( array( 'changed_fields' => array( 'enabled', 'window' ) ), json_decode( (string) ( $audit['context'] ?? '' ), true ) );

		$before = (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table( Tables::AUDIT_LOG ) ) );
		$paused = $this->settings->set_paused( $this->nova, true );
		self::assertFalse( $paused->open() );
		self::assertTrue( $this->settings->find( $this->nova )->paused );
		self::assertSame( $before, (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table( Tables::AUDIT_LOG ) ) ), 'A pause from the chat is not audited as the creator\'s change.' );
	}

	public function test_an_administrator_sets_the_test_mode_which_the_creators_form_keeps(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$this->settings->save( $this->nova, $this->values( true ) );
		$creators = new CreatorRepository( $this->wpdb );
		$section  = new GiftingTestModeSection( $this->settings, $creators, new RecordingLogger() );
		$form     = fn( int $creator_id, bool $on ): array => array(
			'creator_id' => (string) $creator_id,
			'_wpnonce'   => wp_create_nonce( GiftingTestModeSection::nonce_action( $creator_id ) ),
		) + ( $on ? array( GiftingTestModeSection::FIELD => '1' ) : array() );

		$url = $section->handle( $form( $this->nova, true ) );
		self::assertStringContainsString( GiftingTestModeSection::RESULT_ARG . '=on', $url );
		self::assertStringEndsWith( '#merchboard-gifting-test-mode', $url );
		self::assertTrue( $this->settings->find( $this->nova )->test_mode );
		$audit = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT action, actor_user_id, context FROM %i ORDER BY id DESC LIMIT 1', $this->table( Tables::AUDIT_LOG ) ), ARRAY_A );
		self::assertSame( 'twitch_gifting_changed', $audit['action'] ?? null );
		self::assertSame( '1', (string) ( $audit['actor_user_id'] ?? '' ), 'The administrator is the actor.' );
		self::assertSame( array( 'changed_fields' => array( 'test_mode' ) ), json_decode( (string) ( $audit['context'] ?? '' ), true ) );
		self::assertStringContainsString( '=no_changes', $section->handle( $form( $this->nova, true ) ) );

		$this->settings->save( $this->nova, array( 'window' => 90 ) + $this->values( true ) );
		$this->settings->set_paused( $this->nova, true );
		self::assertTrue( $this->settings->find( $this->nova )->test_mode, 'The creator\'s own form and a pause keep the test mode.' );

		$creator = $creators->find( $this->nova );
		self::assertNotNull( $creator );
		ob_start();
		$section->render( $creator );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'id="merchboard-gifting-test-mode"', $html );
		self::assertStringContainsString( 'notice-warning', $html, 'The page warns while the test mode is on.' );
		self::assertMatchesRegularExpression( '/name="' . GiftingTestModeSection::FIELD . '" value="1"\s+checked/', $html );

		self::assertStringContainsString( '=off', $section->handle( $form( $this->nova, false ) ) );
		self::assertFalse( $this->settings->find( $this->nova )->test_mode );
		self::assertStringContainsString( '=not_found', $section->handle( $form( 999999, true ) ) );
		self::assertNull( GiftingTestModeSection::result_message( '<script>' ) );

		wp_set_current_user( $this->create_user( 'gifting-test-subscriber' ) );
		ob_start();
		$section->render( $creator );
		self::assertSame( '', (string) ob_get_clean(), 'Only for users who manage creators.' );
		wp_set_current_user( 1 );
	}

	public function test_the_claim_link_opens_a_page_whose_button_logs_in_and_gives_the_code(): void {
		$this->settings->save( $this->nova, array( 'confirm' => false ) + $this->values( true ) );
		$gift = $this->gift( null );
		$this->draws->paid( $gift );
		$this->chat( '111', 'viewer1', '!enter' );
		$this->close_window( $gift->id );
		$token = (string) $this->gifts->find( $gift->id )?->claim_token;

		$store   = new class() implements CustomerSessionStore {
			/**
			 * Stored values.
			 *
			 * @var array<string, mixed>
			 */
			public array $values = array();

			/**
			 * A stored value.
			 *
			 * @param string $key Key.
			 */
			public function get( string $key ): mixed {
				return $this->values[ $key ] ?? null;
			}

			/**
			 * Stores a value.
			 *
			 * @param string $key   Key.
			 * @param mixed  $value Value.
			 */
			public function set( string $key, mixed $value ): void {
				$this->values[ $key ] = $value;
			}
		};
		$session = new TwitchCustomerSession( $store, new ChatCipher( 'shop-twitch-customer' ) );
		$codes   = new FakeGiftCodes( $this->gifts );
		$claimed = array();
		$claim   = new GiftClaim(
			$this->gifts,
			$codes,
			$session,
			static fn( int $product_id ): array => array(
				'name'  => 'Stream tee',
				'url'   => 'https://shop.test/product/' . $product_id . '/',
				'image' => '',
			),
			function ( string $event, Gift $gift, array $data ) use ( &$claimed ): void {
				$claimed[] = array( $event, $gift->id, $data );
			},
			new RecordingLogger()
		);
		$page    = ShopTwitchController::target_url( 'claim:' . $token );
		$button  = ShopTwitchController::target_url( 'redeem:' . $token );

		$view = $claim->view( $token );
		self::assertSame( 'login', $view['state'], 'The link opens the page; nothing happens before the click.' );
		self::assertSame( 'viewer1', $view['winner'] );
		self::assertSame( $button, $view['redeem'] );
		self::assertStringContainsString( ShopTwitchController::REDEEM_QUERY . '=1', $button );
		$html = $claim->render( $view );
		self::assertStringContainsString( 'Stream tee', $html );
		self::assertStringContainsString( '@viewer1', $html );
		self::assertStringContainsString( 'href="' . esc_url( $button ) . '"', $html );
		self::assertSame( 'invalid', $claim->view( str_repeat( 'a', 32 ) )['state'] );
		self::assertStringContainsString( 'no longer valid', $claim->render( $claim->view( str_repeat( 'a', 32 ) ) ) );

		$login = $claim->redeem( $token );
		self::assertStringContainsString( '/shop/twitch/login', urldecode( $login ), 'The button asks for the Twitch login first.' );
		self::assertStringContainsString( 'target=redeem:' . $token, urldecode( $login ), 'The login comes back to the button.' );
		self::assertSame( 0, $codes->created );

		$session->store( '999', 'someone', 'token-other', 3600, 0 );
		self::assertSame( 'other', $claim->view( $token )['state'] );
		self::assertSame( $page, $claim->redeem( $token ), 'Another account goes back to the page, which offers to log out.' );
		self::assertSame( 0, $codes->created );

		$session->store( '111', 'viewer1', 'token-winner', 3600, 0 );
		self::assertSame( 'redeem', $claim->view( $token )['state'] );
		self::assertSame( 'https://shop.test/product/501/', $claim->redeem( $token ) );
		self::assertSame( Gift::CLAIMED, $this->gifts->find( $gift->id )?->status );
		self::assertSame( array( array( 'claimed', $gift->id, array( 'code' => 'gave-test' . $gift->id ) ) ), $claimed );

		self::assertTrue( $claim->view( $token )['claimed'] );
		self::assertSame( 'https://shop.test/product/501/', $claim->redeem( $token ), 'The winner can come back for the code.' );
		self::assertSame( 1, $codes->created, 'The code is made once.' );
	}

	public function test_a_gift_is_recorded_once_per_order(): void {
		$first  = $this->gifts->create( $this->nova, 1042, 501, '249.0000', '50.0000', 'DKK', 'Mads' );
		$second = $this->gifts->create( $this->nova, 1042, 501, '249.0000', '50.0000', 'DKK', null );

		self::assertNotNull( $first );
		self::assertSame( 'Mads', $first->buyer_name );
		self::assertSame( Gift::WAITING, $first->status );
		self::assertNull( $second, 'The second request for the same order does not start the gift again.' );
		self::assertCount( 1, $this->rows( Tables::GIFTS ) );
	}

	public function test_a_gift_paid_while_live_is_announced_and_drawn(): void {
		$this->settings->save( $this->nova, $this->values( true ) );
		$gift = $this->gift( 'Mads' );

		$this->draws->paid( $gift );

		self::assertSame( array( array( 'bought', $gift->id, array() ) ), $this->events );
		self::assertSame( array( $this->nova, '🎁 Mads har givet Stream tee! Skriv !enter inden for 60 sekunder for at være med i lodtrækningen.' ), $this->sent[0] ?? null );
		self::assertSame( array( GiftDraws::DRAW_HOOK, array( $gift->id ), 61 ), $this->jobs[0] ?? null );
		self::assertSame( Gift::ENTERING, $this->gifts->find( $gift->id )?->status );

		$this->chat( '111', 'viewer1', '!enter' );
		self::assertSame( '✅ Med i lodtrækningen om Stream tee: @viewer1', $this->sent[1][1] ?? null, 'The first entrant is confirmed at once.' );
		$this->chat( '222', 'viewer2', ' !ENTER ' );
		$this->chat( '111', 'viewer1', '!enter' );
		$this->chat( '333', 'viewer3', 'hej' );
		self::assertSame( array( 111, 222 ), array_keys( (array) $this->gifts->find( $gift->id )?->entrants ), 'Each account once.' );
		self::assertCount( 2, $this->sent, 'Within 10 seconds the next entrants wait.' );
		self::assertSame( array( GiftDraws::CONFIRM_HOOK, array( $gift->id ) ), array_slice( $this->jobs[1] ?? array(), 0, 2 ), 'A job confirms them.' );
		self::assertCount( 2, $this->jobs, 'One job for the waiting entrants.' );
		$this->draws->confirm_job( $gift->id );
		self::assertCount( 2, $this->sent, 'The job does not confirm before it is time.' );

		$this->draws->draw( $gift->id );
		self::assertSame( Gift::ENTERING, $this->gifts->find( $gift->id )?->status, 'Not before the window closes.' );

		$this->end_window( $gift->id );
		$this->draws->draw( $gift->id );
		self::assertSame( '✅ Med i lodtrækningen om Stream tee: @viewer2 · Allerede med: @viewer1', $this->sent[2][1] ?? null, 'The rest are confirmed when the entries close.' );
		self::assertSame( '⏳ Tilmeldingen er lukket! Vinderen af Stream tee findes om 10 sekunder …', $this->sent[3][1] ?? null );
		self::assertSame( Gift::ENTERING, $this->gifts->find( $gift->id )?->status, 'No winner yet.' );
		self::assertSame( array( GiftDraws::DRAW_HOOK, array( $gift->id ), 11 ), $this->jobs[2] ?? null, 'A job reveals the winner.' );
		$this->as_stored( $gift->id );
		$this->chat( '444', 'viewer4', '!enter' );
		$this->draws->draw( $gift->id );
		self::assertCount( 4, $this->sent, 'Late entries and an early job change nothing, also in a later request.' );
		self::assertCount( 3, $this->jobs, 'The entries are closed once.' );
		self::assertArrayNotHasKey( 444, (array) $this->gifts->find( $gift->id )?->entrants );

		$this->reveal_now( $gift->id );
		$this->draws->draw( $gift->id );

		$won = $this->gifts->find( $gift->id );
		self::assertNotNull( $won );
		self::assertSame( Gift::WON, $won->status );
		self::assertContains( $won->winner_login, array( 'viewer1', 'viewer2' ) );
		self::assertSame( array(), $won->entrants, 'The entrants are forgotten at the draw.' );
		self::assertSame( 1, $won->draws );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', (string) $won->claim_token );
		self::assertStringContainsString( 'https://shop.test/?merchboard_gift_claim=' . $won->claim_token, $this->sent[4][1] ?? '' );
		self::assertSame( GiftDraws::EXPIRE_HOOK, $this->jobs[3][0] ?? null );
		self::assertSame( array( 'won', $gift->id, array( 'winner' => (string) $won->winner_login ) ), $this->events[1] ?? null );
		self::assertSame( $won->id, $this->gifts->find_by_token( (string) $won->claim_token )?->id );

		$this->draws->draw( $gift->id );
		self::assertCount( 5, $this->sent, 'A second job draws nothing.' );
	}

	public function test_gifting_switched_off_for_the_shop_is_a_pause(): void {
		$this->settings->save( $this->nova, $this->values( true ) );
		$open = $this->gift( 'Mads' );
		$this->draws->paid( $open );
		$this->chat( '111', 'viewer1', '!enter' );
		self::assertSame( Gift::ENTERING, $this->gifts->find( $open->id )?->status );

		Settings::save( array_merge( Settings::all(), array( Settings::COMMUNITY_TWITCH_GIFTING_ENABLED => false ) ) );
		$sent  = count( $this->sent );
		$later = $this->gift( null );
		$this->draws->paid( $later );
		$this->chat( '1', 'nova', '!gift start', array( 'broadcaster' ) );
		self::assertSame( Gift::WAITING, $this->gifts->find( $later->id )?->status, 'No new window.' );
		self::assertCount( $sent, $this->sent, 'Nothing new in the chat, also not for !gift start.' );

		$this->chat( '222', 'viewer2', '!enter' );
		self::assertArrayHasKey( 222, (array) $this->gifts->find( $open->id )?->entrants, 'The open window goes on.' );
		$this->close_window( $open->id );
		$won = $this->gifts->find( $open->id );
		self::assertSame( Gift::WON, $won?->status, 'The open window is drawn.' );
		self::assertSame( Gift::WAITING, $this->gifts->find( $later->id )?->status, 'The next gift waits for gifting to be switched on.' );

		self::assertSame( 404, $this->error_status( ( new MeTwitchGiftingController( $this->service( CreatorContext::class ), $this->settings, $this->gifts, $this->draws, static fn(): ?string => null, static fn(): bool => true, static fn(): ?bool => null, static fn(): string => '', new RecordingLogger() ) )->permission() ) );
		$sections = CommunityDashboardSection::add_section( array( array( 'id' => 'profile' ) ) );
		self::assertSame( array( 'community-twitch-discount', 'profile' ), array_column( (array) $sections, 'id' ), 'Only the switched-on feature is in the menu.' );

		Settings::save( array_merge( Settings::all(), array( Settings::COMMUNITY_TWITCH_SUB_DISCOUNT_ENABLED => false ) ) );
		self::assertSame( array( array( 'id' => 'profile' ) ), CommunityDashboardSection::add_section( array( array( 'id' => 'profile' ) ) ), 'No group without features.' );
	}

	public function test_the_chat_is_reminded_at_half_a_quarter_and_ten_seconds_before_the_entries_close(): void {
		$this->settings->save( $this->nova, $this->values( true ) );
		$gift = $this->gift( null );
		$this->draws->paid( $gift );

		// A window of 60 seconds: reminders with 30, 15 and 10 seconds left.
		self::assertSame(
			array(
				array( array( $gift->id ), 30 ),
				array( array( $gift->id ), 45 ),
				array( array( $gift->id ), 50 ),
			),
			$this->reminders
		);
		$sent = count( $this->sent );

		$this->draws->remind_job( $gift->id );
		self::assertCount( $sent, $this->sent, 'Not before it is time.' );

		// A late job: one message with the seconds really left, for both reminders missed.
		$this->window_left( $gift->id, 12 );
		$this->chat( '333', 'viewer3', 'hej' );
		self::assertCount( $sent + 1, $this->sent );
		self::assertMatchesRegularExpression( '/^⏰ 1[12] sekunder tilbage! Skriv !enter for at være med i lodtrækningen om Stream tee\.$/u', $this->sent[ $sent ][1] ?? '' );
		$this->draws->remind_job( $gift->id );
		self::assertCount( $sent + 1, $this->sent, 'Each reminder once.' );

		$this->window_left( $gift->id, 9 );
		$this->draws->remind_job( $gift->id );
		self::assertCount( $sent + 2, $this->sent );
		self::assertStringStartsWith( '⏰ ', $this->sent[ $sent + 1 ][1] ?? '' );
		$this->chat( '333', 'viewer3', 'hej' );
		self::assertCount( $sent + 2, $this->sent, 'The last reminder was sent.' );

		$this->end_window( $gift->id );
		$this->draws->remind_job( $gift->id );
		self::assertCount( $sent + 2, $this->sent, 'No reminder after the window.' );
	}

	public function test_the_creator_can_switch_the_confirmations_off(): void {
		$this->settings->save( $this->nova, array( 'confirm' => false ) + $this->values( true ) );
		self::assertFalse( $this->settings->find( $this->nova )->confirm );
		$gift = $this->gift( null );
		$this->draws->paid( $gift );

		$this->chat( '111', 'viewer1', '!enter' );
		$this->close_window( $gift->id );

		self::assertCount( 3, $this->sent, 'Only the announcement, the closing and the winner.' );
		self::assertStringContainsString( 'Tilmeldingen er lukket', $this->sent[1][1] ?? '' );
		self::assertStringContainsString( '@viewer1 vandt', $this->sent[2][1] ?? '' );

		try {
			$this->settings->save( $this->nova, array( 'confirm' => 'yes' ) + $this->values( true ) );
			self::fail( 'Expected TwitchSubDiscountInvalid.' );
		} catch ( TwitchSubDiscountInvalid $e ) {
			self::assertSame( array( 'confirm' => 'required' ), $e->fields() );
		}

		$this->settings->save( $this->nova, $this->values( true ) );
		self::assertFalse( $this->settings->find( $this->nova )->confirm, 'A form without the field keeps it.' );
	}

	public function test_nobody_entering_lets_the_gift_wait_without_flooding_the_chat(): void {
		$this->settings->save( $this->nova, $this->values( true ) );
		$gift = $this->gift( null );
		$this->draws->paid( $gift );
		self::assertStringContainsString( 'En anonym seer', $this->sent[0][1] ?? '' );

		$this->end_window( $gift->id );
		$this->chat( '111', 'viewer1', 'hello' );

		self::assertSame( Gift::WAITING, $this->gifts->find( $gift->id )?->status );
		self::assertSame( 'Ingen skrev !enter, så gaven venter til næste lodtrækning.', $this->sent[1][1] ?? null );
		self::assertCount( 2, $this->sent, 'The same gift is not announced again by itself.' );

		$this->chat( '111', 'viewer1', '!gift start' );
		self::assertCount( 2, $this->sent, 'Only the creator and moderators start gifts.' );

		$this->chat( '999', 'mod', '!gift start', array( 'moderator' ) );
		self::assertSame( Gift::ENTERING, $this->gifts->find( $gift->id )?->status, '!gift start tries it again.' );
		self::assertCount( 3, $this->sent );
	}

	public function test_gifts_paid_while_offline_wait_for_the_start(): void {
		$this->settings->save( $this->nova, $this->values( true ) );
		$this->live = false;
		$first      = $this->gift( 'Mads' );
		$second     = $this->gift( 'Ida' );
		$this->draws->paid( $first );
		$this->draws->paid( $second );

		self::assertSame( array(), $this->sent );
		self::assertFalse( $this->draws->start( $this->nova ), 'Not live: nothing starts.' );

		$this->live = true;
		self::assertTrue( $this->draws->start( $this->nova ) );
		self::assertSame( Gift::ENTERING, $this->gifts->find( $first->id )?->status );
		self::assertSame( Gift::WAITING, $this->gifts->find( $second->id )?->status, 'One window at a time.' );

		$this->chat( '111', 'viewer1', '!enter' );
		$this->close_window( $first->id );

		self::assertSame( Gift::WON, $this->gifts->find( $first->id )?->status );
		self::assertSame( Gift::ENTERING, $this->gifts->find( $second->id )?->status, 'The run starts the next gift.' );
	}

	public function test_pause_closes_the_shop_and_tells_the_chat(): void {
		$this->settings->save( $this->nova, $this->values( true ) );

		$this->chat( '4711', 'nova', '!gift pause', array( 'broadcaster' ) );
		self::assertTrue( $this->settings->find( $this->nova )->paused );
		self::assertSame( array( $this->nova, 'Gaver er sat på pause.' ), $this->sent[0] ?? null );

		$this->draws->paid( $this->gift( null ) );
		self::assertCount( 1, $this->sent, 'No draw starts while paused.' );

		$this->chat( '4711', 'nova', '!gift resume', array( 'broadcaster' ) );
		self::assertFalse( $this->settings->find( $this->nova )->paused );
		self::assertSame( 'Gaver er i gang igen.', $this->sent[1][1] ?? null );
	}

	public function test_an_unclaimed_win_waits_for_the_next_draw(): void {
		$this->settings->save( $this->nova, $this->values( true ) );
		$gift = $this->gift( null );
		$this->draws->paid( $gift );
		$this->chat( '111', 'viewer1', '!enter' );
		$this->close_window( $gift->id );

		$this->draws->expire( $gift->id );
		self::assertSame( Gift::WON, $this->gifts->find( $gift->id )?->status, 'Not before the deadline.' );

		$this->execute( 'UPDATE %i SET claim_deadline = %s WHERE id = %d', $this->table( Tables::GIFTS ), gmdate( 'Y-m-d H:i:s', time() - 5 ), $gift->id );
		$this->draws->expire( $gift->id );

		$waiting = $this->gifts->find( $gift->id );
		self::assertSame( Gift::WAITING, $waiting->status );
		self::assertSame( 1, $waiting->draws );
		self::assertNull( $waiting->winner_login );
		self::assertNull( $waiting->claim_token, 'The old link no longer works.' );
	}

	public function test_a_refund_cancels_only_a_gift_without_a_winner(): void {
		$waiting  = $this->gift( null );
		$entering = $this->gift( null );
		$won      = $this->gift( null );
		$this->gifts->open_window( $entering->id, gmdate( 'Y-m-d H:i:s', time() + 60 ) );
		$this->gifts->open_window( $won->id, gmdate( 'Y-m-d H:i:s', time() + 60 ) );
		$this->gifts->win( $won->id, '111', 'viewer1', gmdate( 'Y-m-d H:i:s', time() + 3600 ), str_repeat( 'a', 32 ) );

		self::assertTrue( $this->gifts->cancel( $waiting->id ) );
		self::assertTrue( $this->gifts->cancel( $entering->id ) );
		self::assertFalse( $this->gifts->cancel( $won->id ), 'Never after the draw (ADR-107 pkt. 9).' );
		self::assertSame( array( Gift::CANCELLED, Gift::CANCELLED, Gift::WON ), array_column( $this->rows( Tables::GIFTS ), 'status' ) );
	}

	public function test_privacy_and_creator_deletion(): void {
		$gift = $this->gift( 'Mads' );
		$this->gifts->open_window( $gift->id, gmdate( 'Y-m-d H:i:s', time() + 60 ) );
		$this->gifts->win( $gift->id, '111', 'viewer1', gmdate( 'Y-m-d H:i:s', time() + 3600 ), str_repeat( 'b', 32 ) );

		self::assertSame( 1, $this->gifts->anonymize( array( $gift->order_id ) ) );
		$row = $this->rows( Tables::GIFTS )[0];
		self::assertSame( array( null, null ), array( $row['buyer_name'], $row['winner_login'] ) );

		$cancelled = $this->gift( null );
		$this->gifts->cancel( $cancelled->id );

		self::assertSame( 1, $this->gifts->delete_for_creator( $this->nova ), 'Only finished gifts go; a won gift is given back first.' );
		self::assertSame( array( $gift->id ), array_map( 'intval', array_column( $this->rows( Tables::GIFTS ), 'id' ) ) );
	}

	public function test_switching_off_and_the_last_draw_give_gifts_back(): void {
		$this->settings->save( $this->nova, $this->values( true ) );
		$this->live = false;
		$waiting    = $this->gift( 'Mads' );
		$won        = $this->gift( null );
		$this->gifts->open_window( $won->id, gmdate( 'Y-m-d H:i:s', time() + 60 ) );
		$this->gifts->win( $won->id, '111', 'viewer1', gmdate( 'Y-m-d H:i:s', time() - 5 ), str_repeat( 'c', 32 ) );

		$this->settings->save( $this->nova, $this->values( false ) );
		$codes = new FakeGiftCodes( $this->gifts );
		$draws = $this->draws_with( $codes );

		self::assertSame( 1, $draws->give_back_open( $this->nova, false ), 'Not won yet: given back.' );
		self::assertSame( Gift::RETURNED, $this->gifts->find( $waiting->id )?->status );
		self::assertSame( Gift::WON, $this->gifts->find( $won->id )?->status );

		$draws->expire( $won->id );
		$returned = $this->gifts->find( $won->id );
		self::assertSame( Gift::RETURNED, $returned->status, 'Gifting is off: an unclaimed win goes back instead of waiting.' );
		self::assertNull( $returned->claim_token );
		self::assertSame( array( 'returned', 'returned' ), array_column( $this->events, 0 ) );
		self::assertSame( array( 'gave-test' . $waiting->id, 'gave-test' . $won->id ), array_map( static fn( array $event ): string => $event[2]['code'] ?? '', $this->events ) );
		self::assertSame( 2, $codes->created );
	}

	/**
	 * Draws with other gift codes.
	 *
	 * @param GiftCodes $codes Gift codes.
	 */
	private function draws_with( GiftCodes $codes ): GiftDraws {
		return new GiftDraws(
			$this->gifts,
			$this->settings,
			$codes,
			function ( int $creator_id, string $message ): bool {
				$this->sent[] = array( $creator_id, $message );

				return true;
			},
			fn(): bool => $this->live,
			static fn( string $token ): string => 'https://shop.test/?merchboard_gift_claim=' . $token,
			static fn(): string => 'Stream tee',
			static fn(): bool => true,
			function ( string $event, Gift $gift, array $data ): void {
				$this->events[] = array( $event, $gift->id, $data );
			},
			new RecordingLogger()
		);
	}

	/**
	 * Settings values.
	 *
	 * @param bool $enabled On.
	 * @return array<string, mixed>
	 */
	private function values( bool $enabled ): array {
		return array(
			'enabled'  => $enabled,
			'paused'   => false,
			'window'   => 60,
			'language' => 'da',
		);
	}

	/**
	 * A paid gift of Nova's.
	 *
	 * @param string|null $buyer The buyer's name.
	 */
	private function gift( ?string $buyer ): Gift {
		$gift = $this->gifts->create( $this->nova, ++$this->order, 501, '249.0000', '50.0000', 'DKK', $buyer );
		self::assertNotNull( $gift );

		return $gift;
	}

	/**
	 * A chat message in Nova's channel.
	 *
	 * @phpstan-param list<string> $badges
	 *
	 * @param string   $chatter Chatter's Twitch id.
	 * @param string   $login   Chatter's login.
	 * @param string   $text    Message.
	 * @param string[] $badges  Badges.
	 */
	private function chat( string $chatter, string $login, string $text, array $badges = array() ): void {
		do_action( 'merchboard_twitch_chat_message', $this->nova, $chatter, $login, $text, $badges );
	}

	/**
	 * Ends a gift's window, closes the entries and reveals the winner.
	 *
	 * @param int $id Gift.
	 */
	private function close_window( int $id ): void {
		$this->end_window( $id );
		$this->draws->draw( $id );
		$this->reveal_now( $id );
		$this->draws->draw( $id );
	}

	/**
	 * Lets the 10 seconds before a winner run out.
	 *
	 * @param int $id Gift.
	 */
	private function reveal_now( int $id ): void {
		set_transient( 'merchboard_gift_reveal_' . $id, (string) ( time() - 1 ), HOUR_IN_SECONDS );
	}

	/**
	 * The reveal time as a later request reads it without an object cache:
	 * the options table gives it back as a string.
	 *
	 * @param int $id Gift.
	 */
	private function as_stored( int $id ): void {
		$reveal = get_transient( 'merchboard_gift_reveal_' . $id );
		self::assertIsInt( $reveal );
		set_transient( 'merchboard_gift_reveal_' . $id, (string) $reveal, HOUR_IN_SECONDS );
	}

	/**
	 * Lets a gift's window end in a number of seconds.
	 *
	 * @param int $id      Gift.
	 * @param int $seconds Seconds left.
	 */
	private function window_left( int $id, int $seconds ): void {
		$this->execute( 'UPDATE %i SET window_ends = %s WHERE id = %d', $this->table( Tables::GIFTS ), gmdate( 'Y-m-d H:i:s', time() + $seconds ), $id );
	}

	/**
	 * Ends a gift's window now.
	 *
	 * @param int $id Gift.
	 */
	private function end_window( int $id ): void {
		$this->execute( 'UPDATE %i SET window_ends = %s WHERE id = %d', $this->table( Tables::GIFTS ), gmdate( 'Y-m-d H:i:s', time() - 1 ), $id );
	}

	/**
	 * The HTTP status of a refused permission check (0 when it was allowed).
	 *
	 * @param mixed $result A permission callback's result.
	 */
	private function error_status( mixed $result ): int {
		$data = $result instanceof \WP_Error ? $result->get_error_data() : null;

		return is_array( $data ) && is_int( $data['status'] ?? null ) ? $data['status'] : 0;
	}
}
