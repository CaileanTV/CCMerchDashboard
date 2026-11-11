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
 * - The bot confirms the entrants (the creator can switch it off): the
 *   first at once, then the new ones and those who typed `!enter` again in
 *   one message at most every 10 seconds, and the rest before the winner.
 * - When the window closes, the bot says that the entries are closed and
 *   reveals the winner REVEAL_SECONDS later (always after that message,
 *   also when a job runs late).
 * - At the reveal, a random entrant (`random_int`, each Twitch
 *   account once, the buyer too) wins and gets the claim link; nobody: the
 *   gift waits again.
 * - A win not claimed within 24 hours waits for the next draw; after the
 *   third draw (or when gifting is off) the buyer gets the code.
 * - Switching gifting off gives the gifts not won yet back to their buyers;
 *   deleting the creator gives back every gift not claimed yet.
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
	 * Job: confirm the entrants waiting for a confirmation.
	 */
	public const CONFIRM_HOOK = 'merchboard_gift_confirm';

	/**
	 * Seconds between two confirmations in the chat.
	 */
	public const CONFIRM_SECONDS = 10;

	/**
	 * Seconds between "entries are closed" and the winner.
	 */
	public const REVEAL_SECONDS = 10;

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
		add_action( self::CONFIRM_HOOK, array( $this, 'confirm_job' ) );
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
		$staff   = is_array( $badges ) && array() !== array_intersect( array( 'broadcaster', 'lead_moderator', 'moderator' ), $badges );

		try {
			$this->catch_up();

			if ( '!enter' === $command ) {
				$open = $this->gifts->for_creator( $creator_id, array( Gift::ENTERING ), 1 );

				if ( array() !== $open ) {
					$again = isset( $open[0]->entrants[ $chatter_id ] );

					if ( $again || $this->gifts->add_entrant( $open[0]->id, $chatter_id, $login ) ) {
						$this->confirm( $open[0], $again ? $login : null, false );
					}
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
	 * Job: confirms the entrants waiting for a confirmation.
	 *
	 * @param mixed $gift_id Gift.
	 */
	public function confirm_job( mixed $gift_id ): void {
		try {
			$gift = is_numeric( $gift_id ) ? $this->gifts->find( (int) $gift_id ) : null;

			if ( null !== $gift && Gift::ENTERING === $gift->status ) {
				$this->confirm( $gift, null, false );
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'Gift entrants could not be confirmed.', $e->context() );
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
				// Another request opened a window just now: one at a time.
				return false;
			}

			$tried[] = $gift->id;
			set_transient( self::tried_key( $creator_id ), $tried, self::RUN_HOURS * HOUR_IN_SECONDS );
			delete_transient( self::confirm_key( $gift->id ) );
			delete_transient( self::reveal_key( $gift->id ) );

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
		if ( array() !== $gift->entrants ) {
			$reveal = get_transient( self::reveal_key( $gift->id ) );

			if ( ! is_int( $reveal ) ) {
				$this->closing( $gift );

				return;
			}

			if ( time() < $reveal ) {
				return;
			}
		}

		// The entrants still waiting for a confirmation hear it before the winner.
		$this->confirm( $gift, null, true );
		delete_transient( self::confirm_key( $gift->id ) );
		delete_transient( self::reveal_key( $gift->id ) );

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
	 * The window ran out: the entrants waiting for a confirmation hear it,
	 * the bot says that the entries are closed, and a job reveals the winner
	 * REVEAL_SECONDS later. Once per window (under the gift's named lock).
	 *
	 * @param Gift $gift A gift whose window ran out, with entrants.
	 * @throws DatabaseException When the gift cannot be read.
	 */
	private function closing( Gift $gift ): void {
		$this->confirm( $gift, null, true );

		if ( ! $this->gifts->lock( $gift->id, 2 ) ) {
			return;
		}

		try {
			if ( is_int( get_transient( self::reveal_key( $gift->id ) ) ) ) {
				return;
			}

			set_transient( self::reveal_key( $gift->id ), time() + self::REVEAL_SECONDS, HOUR_IN_SECONDS );
			$settings = $this->settings->find( $gift->creator_id );
			( $this->send )( $gift->creator_id, GiftTexts::closed( $settings->language, ( $this->product_name )( $gift->product_id ), self::REVEAL_SECONDS ) );
			( $this->enqueue )( self::DRAW_HOOK, array( $gift->id ), self::REVEAL_SECONDS + 1 );
		} finally {
			$this->gifts->unlock( $gift->id );
		}
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

		if ( $gift->draws < Gift::MAX_DRAWS && $this->settings->find( $gift->creator_id )->enabled ) {
			$this->gifts->wait_again( $gift->id, Gift::WON );

			return;
		}

		$this->give_back( $gift );
	}

	/**
	 * Gives a gift back to its buyer with the code (after the last draw,
	 * when gifting is switched off, or when the creator is deleted).
	 *
	 * @param Gift $gift A gift that is waiting, in its window or won.
	 * @return bool Whether this request gave it back.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function give_back( Gift $gift ): bool {
		$code = $this->codes->create( $gift );

		if ( $this->gifts->return_to_buyer( $gift->id, $gift->status, $code['id'] ) ) {
			( $this->notify )( 'returned', $gift, array( 'code' => $code['code'] ) );

			return true;
		}

		// Another request moved it first; its code is not needed.
		wp_delete_post( $code['id'], true );

		return false;
	}

	/**
	 * Gives back a creator's gifts that are not won yet (gifting switched
	 * off) or not claimed yet as well (`$won`, the creator is deleted).
	 *
	 * @param int  $creator_id Creator.
	 * @param bool $won        Also won gifts.
	 * @return int Gifts given back.
	 * @throws DatabaseException When they cannot be read or stored.
	 */
	public function give_back_open( int $creator_id, bool $won ): int {
		$given = 0;

		foreach ( $this->gifts->for_creator( $creator_id, $won ? array( Gift::WAITING, Gift::ENTERING, Gift::WON ) : array( Gift::WAITING, Gift::ENTERING ), 500 ) as $gift ) {
			$given += $this->give_back( $gift ) ? 1 : 0;
		}

		return $given;
	}

	/**
	 * Confirms a window's entrants in the chat when the creator wants it: the
	 * entrants after those confirmed already, and those who typed `!enter`
	 * again, in one message at most every CONFIRM_SECONDS (`$now`: at once,
	 * before the draw). Otherwise a job confirms them when it is time. Under
	 * the gift's named lock; a request that cannot get it in time leaves the
	 * confirmation to the next one (the entrant is in anyway).
	 *
	 * @param Gift        $gift  A gift in its window (as read just now).
	 * @param string|null $again The login of a viewer who typed `!enter` again.
	 * @param bool        $now   Confirm now.
	 * @throws DatabaseException When the gift cannot be read.
	 */
	private function confirm( Gift $gift, ?string $again, bool $now ): void {
		$settings = $this->settings->find( $gift->creator_id );

		if ( ! $settings->confirm || ! $this->gifts->lock( $gift->id, 2 ) ) {
			return;
		}

		try {
			$fresh = $this->gifts->find( $gift->id );
			$state = self::confirm_state( $gift->id );

			// After the window only the closing confirms (`!enter` is refused then).
			if ( null === $fresh || ( ! $now && ( Gift::ENTERING !== $fresh->status || ( null !== $fresh->window_ends && $fresh->window_ends < GiftRepository::now() ) ) ) ) {
				return;
			}

			// At the draw the gift is still as read before it was closed.
			$entrants = $now ? $gift->entrants : $fresh->entrants;

			if ( null !== $again && ! in_array( $again, $state['again'], true ) ) {
				$state['again'][] = $again;
			}

			$new   = array_values( array_map( 'strval', array_slice( $entrants, $state['done'] ) ) );
			$later = array_values( array_diff( $state['again'], $new ) );

			if ( array() === $new && array() === $later ) {
				return;
			}

			if ( $now || time() >= $state['next'] ) {
				( $this->send )( $gift->creator_id, GiftTexts::entered( $settings->language, ( $this->product_name )( $gift->product_id ), $new, $later ) );
				$state = array(
					'done'  => count( $entrants ),
					'again' => array(),
					'next'  => time() + self::CONFIRM_SECONDS,
					'job'   => 0,
				);
			} elseif ( $state['job'] !== $state['next'] ) {
				( $this->enqueue )( self::CONFIRM_HOOK, array( $gift->id ), max( 1, $state['next'] - time() ) );
				$state['job'] = $state['next'];
			}

			set_transient( self::confirm_key( $gift->id ), $state, HOUR_IN_SECONDS );
		} finally {
			$this->gifts->unlock( $gift->id );
		}
	}

	/**
	 * A window's confirmations so far: entrants confirmed, logins that typed
	 * `!enter` again, when the next message may go out and the job queued.
	 *
	 * @param int $gift_id Gift.
	 * @return array{done: int, again: list<string>, next: int, job: int}
	 */
	private static function confirm_state( int $gift_id ): array {
		$state = get_transient( self::confirm_key( $gift_id ) );
		$state = is_array( $state ) ? $state : array();
		$again = is_array( $state['again'] ?? null ) ? $state['again'] : array();

		return array(
			'done'  => is_int( $state['done'] ?? null ) ? $state['done'] : 0,
			'again' => array_values( array_filter( $again, 'is_string' ) ),
			'next'  => is_int( $state['next'] ?? null ) ? $state['next'] : 0,
			'job'   => is_int( $state['job'] ?? null ) ? $state['job'] : 0,
		);
	}

	/**
	 * The transient of a closed window's reveal time.
	 *
	 * @param int $gift_id Gift.
	 */
	private static function reveal_key( int $gift_id ): string {
		return 'merchboard_gift_reveal_' . $gift_id;
	}

	/**
	 * The transient of a window's confirmations.
	 *
	 * @param int $gift_id Gift.
	 */
	private static function confirm_key( int $gift_id ): string {
		return 'merchboard_gift_confirm_' . $gift_id;
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
