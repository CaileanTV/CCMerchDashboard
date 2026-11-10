<?php
/**
 * The draws of gifts in the creator's Twitch chat.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\Gifting;

use Closure;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-107 pkt. 5 and 7:
 *
 * - A gift paid while the creator is live (gifting open) starts at once:
 *   the bot announces it and opens the `!enter` window. One window at a time
 *   per creator; the next waits for the current draw.
 * - Gifts paid after the stream and gifts to draw again wait until the
 *   creator or a moderator types `!gift start` (or the dashboard's button);
 *   then the bot runs them one after the other.
 * - A gift whose window closed without entrants waits; it is not opened
 *   again by itself (the next `!gift start` tries it again), so the chat is
 *   never flooded with the same gift.
 * - `!gift pause` / `!gift resume` (creator or moderator) pause and resume.
 * - When the window closes, a random entrant (`random_int`, each Twitch
 *   account once, the buyer too) wins and gets the claim link; nobody: the
 *   gift waits again.
 * - A win not claimed within 24 hours waits for the next draw; after the
 *   third draw the buyer gets the code.
 *
 * Every change is a conditional update in GiftRepository, so a job, a
 * webhook delivery and a click can never move a gift twice. Times run out
 * through Action Scheduler jobs and, as a fallback, on every chat message.
 */
final class GiftDraws {

	/**
	 * Job: close a window and draw.
	 */
	public const DRAW_HOOK = 'merchboard_gift_draw';

	/**
	 * Job: a claim deadline ran out.
	 */
	public const EXPIRE_HOOK = 'merchboard_gift_expire';

	/**
	 * Action fired with a gift's events (event, Gift, data): `bought`,
	 * `won`, `claimed` (data `code`) and `returned` (data `code`).
	 */
	public const EVENT_HOOK = 'merchboard_gift_event';

	/**
	 * Minutes a gift paid during a draw is still "fresh" and joins the run.
	 */
	private const FRESH_MINUTES = 30;

	/**
	 * Hours a started run lasts.
	 */
	private const RUN_HOURS = 6;

	/**
	 * Sends a chat message in a creator's channel.
	 *
	 * @var Closure(int, string): bool
	 */
	private Closure $send;

	/**
	 * Whether a creator is live now.
	 *
	 * @var Closure(int): bool
	 */
	private Closure $live;

	/**
	 * The claim link of a token.
	 *
	 * @var Closure(string): string
	 */
	private Closure $claim_url;

	/**
	 * A product's name.
	 *
	 * @var Closure(int): string
	 */
	private Closure $product_name;

	/**
	 * Queues a job (hook, args, delay in seconds).
	 *
	 * @var Closure(string, array<mixed>, int): bool
	 */
	private Closure $enqueue;

	/**
	 * Tells about a gift (`bought`, `won`, `claimed`, `returned`).
	 *
	 * @var Closure(string, Gift, array<string, string>): void
	 */
	private Closure $notify;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int, string): bool $send
	 * @phpstan-param Closure(int): bool $live
	 * @phpstan-param Closure(string): string $claim_url
	 * @phpstan-param Closure(int): string $product_name
	 * @phpstan-param Closure(string, array<mixed>, int): bool $enqueue
	 * @phpstan-param Closure(string, Gift, array<string, string>): void $notify
	 *
	 * @param GiftRepository            $gifts        Gifts.
	 * @param GiftingSettingsRepository $settings     The creators' gifting settings.
	 * @param GiftCodes                 $codes        Gift codes.
	 * @param Closure                   $send         Sends a chat message in a creator's channel; whether it went out.
	 * @param Closure                   $live         Whether a creator is live now.
	 * @param Closure                   $claim_url    The claim link of a token.
	 * @param Closure                   $product_name A product's name.
	 * @param Closure                   $enqueue      Queues a job.
	 * @param Closure                   $notify       Tells the creator, the buyer or the administrator.
	 * @param Logger                    $logger       Technical log.
	 */
	public function __construct(
		private GiftRepository $gifts,
		private GiftingSettingsRepository $settings,
		private GiftCodes $codes,
		Closure $send,
		Closure $live,
		Closure $claim_url,
		Closure $product_name,
		Closure $enqueue,
		Closure $notify,
		private Logger $logger
	) {
		$this->send         = $send;
		$this->live         = $live;
		$this->claim_url    = $claim_url;
		$this->product_name = $product_name;
		$this->enqueue      = $enqueue;
		$this->notify       = $notify;
	}

	/**
	 * Hooks the draws into the chat and the jobs.
	 */
	public function register(): void {
		add_action( 'merchboard_twitch_chat_message', array( $this, 'chat' ), 10, 5 );
		add_action( self::DRAW_HOOK, array( $this, 'draw' ) );
		add_action( self::EXPIRE_HOOK, array( $this, 'expire' ) );
	}

	/**
	 * A gift was paid: the creator hears about it, and it starts when the
	 * creator is live and no other window is open.
	 *
	 * @param Gift $gift The new gift.
	 */
	public function paid( Gift $gift ): void {
		( $this->notify )( 'bought', $gift, array() );

		try {
			$this->next( $gift->creator_id );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A paid gift could not be started.', $e->context() );
		}
	}

	/**
	 * The creator or a moderator starts the waiting gifts (`!gift start` or
	 * the dashboard's button).
	 *
	 * @param int $creator_id Creator.
	 * @return bool Whether a gift started (or one is already running).
	 * @throws DatabaseException When the gifts cannot be read.
	 */
	public function start( int $creator_id ): bool {
		set_transient( self::run_key( $creator_id ), '1', self::RUN_HOURS * HOUR_IN_SECONDS );
		// A new run tries every waiting gift again.
		delete_transient( self::tried_key( $creator_id ) );

		if ( array() !== $this->gifts->for_creator( $creator_id, array( Gift::ENTERING ), 1 ) ) {
			return true;
		}

		return $this->next( $creator_id );
	}

	/**
	 * Pauses or resumes gifting; the chat is told.
	 *
	 * @param int  $creator_id Creator.
	 * @param bool $paused     Paused.
	 * @throws DatabaseException When the settings cannot be stored.
	 */
	public function pause( int $creator_id, bool $paused ): void {
		$settings = $this->settings->set_paused( $creator_id, $paused );
		( $this->send )( $creator_id, GiftTexts::text( $settings->language, $paused ? 'paused' : 'resumed' ) );
	}

	/**
	 * `merchboard_twitch_chat_message`: `!enter`, and `!gift start|pause|resume`
	 * from the creator or a moderator; due windows and deadlines are handled
	 * on the way.
	 *
	 * @param mixed $creator_id Creator.
	 * @param mixed $chatter_id Chatter's Twitch id.
	 * @param mixed $login      Chatter's login.
	 * @param mixed $text       Message.
	 * @param mixed $badges     Badge set ids.
	 */
	public function chat( mixed $creator_id, mixed $chatter_id, mixed $login, mixed $text, mixed $badges ): void {
		if ( ! is_int( $creator_id ) || ! is_string( $chatter_id ) || ! is_string( $login ) || ! is_string( $text ) ) {
			return;
		}

		$command = strtolower( (string) preg_replace( '/\s+/', ' ', trim( $text ) ) );
		$staff   = is_array( $badges ) && array() !== array_intersect( array( 'broadcaster', 'moderator' ), $badges );

		try {
			$this->catch_up();

			if ( '!enter' === $command ) {
				$open = $this->gifts->for_creator( $creator_id, array( Gift::ENTERING ), 1 );

				if ( array() !== $open ) {
					$this->gifts->add_entrant( $open[0]->id, $chatter_id, $login );
				}

				return;
			}

			if ( ! $staff ) {
				return;
			}

			if ( '!gift pause' === $command || '!gift resume' === $command ) {
				$this->pause( $creator_id, '!gift pause' === $command );
			} elseif ( '!gift start' === $command && ! $this->start( $creator_id ) ) {
				( $this->send )( $creator_id, GiftTexts::text( $this->settings->find( $creator_id )->language, 'none' ) );
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A gift command could not be handled.', $e->context() );
		}
	}

	/**
	 * Job: closes a window and draws a winner.
	 *
	 * @param mixed $gift_id Gift.
	 */
	public function draw( mixed $gift_id ): void {
		try {
			$gift = is_numeric( $gift_id ) ? $this->gifts->find( (int) $gift_id ) : null;

			if ( null === $gift || Gift::ENTERING !== $gift->status || ( null !== $gift->window_ends && $gift->window_ends > GiftRepository::now() ) ) {
				return;
			}

			$this->close( $gift );
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A gift could not be drawn.', $e->context() );
		}
	}

	/**
	 * Job: a claim deadline ran out.
	 *
	 * @param mixed $gift_id Gift.
	 */
	public function expire( mixed $gift_id ): void {
		try {
			$gift = is_numeric( $gift_id ) ? $this->gifts->find( (int) $gift_id ) : null;

			if ( null !== $gift ) {
				$this->lapse( $gift );
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A gift deadline could not be handled.', $e->context() );
		}
	}

	/**
	 * Closes due windows and lapses due wins (fallback for missed jobs).
	 *
	 * @throws DatabaseException When the gifts cannot be read.
	 */
	public function catch_up(): void {
		foreach ( $this->gifts->due( Gift::ENTERING ) as $gift ) {
			$this->close( $gift );
		}

		foreach ( $this->gifts->due( Gift::WON ) as $gift ) {
			$this->lapse( $gift );
		}
	}

	/**
	 * Starts the creator's next gift when the creator is live, gifting is
	 * open and no window is open: a fresh gift (paid in the last half hour,
	 * never drawn), or any waiting gift during a started run.
	 *
	 * @param int $creator_id Creator.
	 * @return bool Whether a gift started.
	 * @throws DatabaseException When the gifts cannot be read or changed.
	 */
	private function next( int $creator_id ): bool {
		$settings = $this->settings->find( $creator_id );

		if ( ! $settings->open() || array() !== $this->gifts->for_creator( $creator_id, array( Gift::ENTERING ), 1 ) || ! ( $this->live )( $creator_id ) ) {
			return false;
		}

		$run   = '1' === get_transient( self::run_key( $creator_id ) );
		$fresh = gmdate( 'Y-m-d H:i:s', time() - self::FRESH_MINUTES * MINUTE_IN_SECONDS );
		$tried = self::tried( $creator_id );

		foreach ( $this->gifts->for_creator( $creator_id, array( Gift::WAITING ), 50 ) as $gift ) {
			if ( in_array( $gift->id, $tried, true ) || ( ! $run && ( 0 !== $gift->draws || $gift->created_at < $fresh ) ) ) {
				continue;
			}

			$ends = gmdate( 'Y-m-d H:i:s', time() + $settings->window );

			if ( ! $this->gifts->open_window( $gift->id, $ends ) ) {
				continue;
			}

			$tried[] = $gift->id;
			set_transient( self::tried_key( $creator_id ), $tried, self::RUN_HOURS * HOUR_IN_SECONDS );

			( $this->send )( $creator_id, GiftTexts::announce( $settings->language, $gift->buyer_name, ( $this->product_name )( $gift->product_id ), $settings->window ) );
			( $this->enqueue )( self::DRAW_HOOK, array( $gift->id ), $settings->window + 1 );

			return true;
		}

		if ( $run ) {
			delete_transient( self::run_key( $creator_id ) );
		}

		return false;
	}

	/**
	 * Closes a window: draws a winner, or the gift waits again; then the
	 * next gift may start.
	 *
	 * @param Gift $gift A gift in its window.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function close( Gift $gift ): void {
		$language = $this->settings->find( $gift->creator_id )->language;
		$ids      = array_keys( $gift->entrants );

		if ( array() === $ids ) {
			if ( $this->gifts->wait_again( $gift->id, Gift::ENTERING ) ) {
				( $this->send )( $gift->creator_id, GiftTexts::text( $language, 'nobody' ) );
			}
		} else {
			$winner   = (string) $ids[ random_int( 0, count( $ids ) - 1 ) ];
			$login    = $gift->entrants[ $winner ];
			$token    = bin2hex( random_bytes( 16 ) );
			$deadline = gmdate( 'Y-m-d H:i:s', time() + Gift::CLAIM_HOURS * HOUR_IN_SECONDS );

			if ( $this->gifts->win( $gift->id, $winner, $login, $deadline, $token ) ) {
				( $this->send )( $gift->creator_id, GiftTexts::winner( $language, $login, ( $this->product_name )( $gift->product_id ), ( $this->claim_url )( $token ) ) );
				( $this->enqueue )( self::EXPIRE_HOOK, array( $gift->id ), Gift::CLAIM_HOURS * HOUR_IN_SECONDS + 5 );
				( $this->notify )( 'won', $gift, array( 'winner' => $login ) );
			}
		}

		$this->next( $gift->creator_id );
	}

	/**
	 * A win nobody claimed in time: the gift waits for the next draw, or
	 * after the last draw the buyer gets the code.
	 *
	 * @param Gift $gift A gift.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function lapse( Gift $gift ): void {
		if ( Gift::WON !== $gift->status || null === $gift->claim_deadline || $gift->claim_deadline > GiftRepository::now() ) {
			return;
		}

		if ( $gift->draws < Gift::MAX_DRAWS ) {
			$this->gifts->wait_again( $gift->id, Gift::WON );

			return;
		}

		$code = $this->codes->create( $gift );

		if ( $this->gifts->return_to_buyer( $gift->id, Gift::WON, $code['id'] ) ) {
			( $this->notify )( 'returned', $gift, array( 'code' => $code['code'] ) );
		}
	}

	/**
	 * The gifts whose window opened since the last `!gift start`.
	 *
	 * @param int $creator_id Creator.
	 * @return list<int>
	 */
	private static function tried( int $creator_id ): array {
		$tried = get_transient( self::tried_key( $creator_id ) );

		return is_array( $tried ) ? array_values( array_filter( $tried, 'is_int' ) ) : array();
	}

	/**
	 * The transient of the gifts tried since the last `!gift start`.
	 *
	 * @param int $creator_id Creator.
	 */
	private static function tried_key( int $creator_id ): string {
		return 'merchboard_gift_tried_' . $creator_id;
	}

	/**
	 * The transient of a creator's started run.
	 *
	 * @param int $creator_id Creator.
	 */
	private static function run_key( int $creator_id ): string {
		return 'merchboard_gift_run_' . $creator_id;
	}
}
