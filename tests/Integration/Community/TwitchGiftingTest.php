<?php
/**
 * Twitch gifting: settings, the gift's statuses and the draws (ADR-107).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Community;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Alerts\Chatbot\ChatbotChannelRepository;
use MerchBoard\Modules\Alerts\Chatbot\ChatPlatform;
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
				$this->jobs[] = array( $hook, $args, $delay );

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
		remove_all_actions( 'merchboard_twitch_chat_message' );
		remove_all_actions( GiftDraws::DRAW_HOOK );
		remove_all_actions( GiftDraws::EXPIRE_HOOK );
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
		$this->chat( '222', 'viewer2', ' !ENTER ' );
		$this->chat( '111', 'viewer1', '!enter' );
		$this->chat( '333', 'viewer3', 'hej' );
		self::assertSame( array( 111, 222 ), array_keys( (array) $this->gifts->find( $gift->id )?->entrants ), 'Each account once.' );

		$this->draws->draw( $gift->id );
		self::assertSame( Gift::ENTERING, $this->gifts->find( $gift->id )?->status, 'Not before the window closes.' );

		$this->end_window( $gift->id );
		$this->draws->draw( $gift->id );

		$won = $this->gifts->find( $gift->id );
		self::assertNotNull( $won );
		self::assertSame( Gift::WON, $won->status );
		self::assertContains( $won->winner_login, array( 'viewer1', 'viewer2' ) );
		self::assertSame( array(), $won->entrants, 'The entrants are forgotten at the draw.' );
		self::assertSame( 1, $won->draws );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', (string) $won->claim_token );
		self::assertStringContainsString( 'https://shop.test/?merchboard_gift_claim=' . $won->claim_token, $this->sent[1][1] ?? '' );
		self::assertSame( GiftDraws::EXPIRE_HOOK, $this->jobs[1][0] ?? null );
		self::assertSame( array( 'won', $gift->id, array( 'winner' => (string) $won->winner_login ) ), $this->events[1] ?? null );
		self::assertSame( $won->id, $this->gifts->find_by_token( (string) $won->claim_token )?->id );

		$this->draws->draw( $gift->id );
		self::assertCount( 2, $this->sent, 'A second job draws nothing.' );
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
		$this->end_window( $first->id );
		$this->draws->draw( $first->id );

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
		$this->end_window( $gift->id );
		$this->draws->draw( $gift->id );

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
	 * Ends a gift's window now.
	 *
	 * @param int $id Gift.
	 */
	private function end_window( int $id ): void {
		$this->execute( 'UPDATE %i SET window_ends = %s WHERE id = %d', $this->table( Tables::GIFTS ), gmdate( 'Y-m-d H:i:s', time() - 1 ), $id );
	}
}
