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
use MerchBoard\Core\Settings\Settings;

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
	 * Job: reminds the chat of the seconds left to type `!enter`.
	 */
	public const REMIND_HOOK = 'merchboard_gift_remind';

	/**
	 * The last reminder: seconds before the entries close.
	 */
	public const LAST_REMINDER = 10;

	/**
	 * A window longer than this gets a reminder at three quarters left too.
	 */
	public const LONG_WINDOW = 75;

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
	 * Starts the clock of a window that just opened (GiftTicker), or null.
	 *
	 * @var (Closure(int): void)|null
	 */
	private ?Closure $tick;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int, string): bool $send
	 * @phpstan-param Closure(int): bool $live
	 * @phpstan-param Closure(string): string $claim_url
	 * @phpstan-param Closure(int): string $product_name
	 * @phpstan-param Closure(string, array<mixed>, int): bool $enqueue
	 * @phpstan-param Closure(string, Gift, array<string, string>): void $notify
	 * @phpstan-param (Closure(int): void)|null $tick
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
	 * @param Closure|null              $tick         Starts the clock of a window that just opened.
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
		private Logger $logger,
		?Closure $tick = null
	) {
		$this->send         = $send;
		$this->live         = $live;
		$this->claim_url    = $claim_url;
		$this->product_name = $product_name;
		$this->enqueue      = $enqueue;
		$this->notify       = $notify;
		$this->tick         = $tick;
	}

	/**
	 * When something is due next in a gift's window (a reminder, the closing
	 * or the winner), as a Unix time that may have passed already; null when
	 * the gift is not in a window (GiftTicker stops then).
	 *
	 * @param int $gift_id Gift.
	 * @throws DatabaseException When the gift cannot be read.
	 */
	public function due_at( int $gift_id ): ?int {
		$gift = $this->gifts->find( $gift_id );
		$ends = null === $gift || null === $gift->window_ends ? false : strtotime( $gift->window_ends . ' UTC' );

		if ( null === $gift || Gift::ENTERING !== $gift->status || false === $ends ) {
			return null;
		}

		if ( time() < $ends ) {
			$pending = get_transient( self::remind_key( $gift->id ) );
			$times   = array_map( static fn( int $mark ): int => $ends - $mark, is_array( $pending ) ? array_values( array_filter( $pending, 'is_int' ) ) : array() );

			return min( array_merge( array( $ends ), $times ) );
		}

		// The entries are closed, or close now; then the winner is revealed.
		return self::reveal_at( $gift->id ) ?? time();
	}

	/**
	 * Does what is due in a gift's window now: the reminder, the closing or
	 * the winner (each only when it is time; GiftTicker).
	 *
	 * @param int $gift_id Gift.
	 */
	public function advance( int $gift_id ): void {
		$this->remind_job( $gift_id );
		$this->draw( $gift_id );
	}

	/**
	 * Hooks the draws into the chat and the jobs.
	 */
	public function register(): void {
		add_action( 'merchboard_twitch_chat_message', array( $this, 'chat' ), 10, 5 );
		add_action( self::DRAW_HOOK, array( $this, 'draw' ) );
		add_action( self::EXPIRE_HOOK, array( $this, 'expire' ) );
		add_action( self::CONFIRM_HOOK, array( $this, 'confirm_job' ) );
		add_action( self::REMIND_HOOK, array( $this, 'remind_job' ) );
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

			// A reminder that is due goes out with any chat message too (a job can be late).
			$window = $this->gifts->for_creator( $creator_id, array( Gift::ENTERING ), 1 );

			if ( array() !== $window ) {
				$this->remind( $window[0] );
			}

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

			// While an administrator has gifting switched off (ADR-108), only an open window goes on.
			if ( ! $staff || ! Settings::twitch_gifting_enabled() ) {
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
	 * Job: reminds the chat of the seconds left in a window.
	 *
	 * @param mixed $gift_id Gift.
	 */
	public function remind_job( mixed $gift_id ): void {
		try {
			$gift = is_numeric( $gift_id ) ? $this->gifts->find( (int) $gift_id ) : null;

			if ( null !== $gift ) {
				$this->remind( $gift );
			}
		} catch ( DatabaseException $e ) {
			$this->logger->error( 'A gift reminder could not be sent.', $e->context() );
		}
	}

	/**
	 * The reminders of a window, in seconds left: at half the time, at a
	 * quarter and LAST_REMINDER seconds before the entries close, and in a
	 * window over LONG_WINDOW seconds also at three quarters. A share within
	 * 3 seconds of another reminder is left out, so a short window gets no
	 * reminders seconds apart.
	 *
	 * @param int $window The window in seconds.
	 * @return list<int> Seconds left, the first first.
	 */
	public static function reminders( int $window ): array {
		$marks = array( self::LAST_REMINDER );

		$shares = array( intdiv( $window + 1, 2 ), intdiv( $window + 2, 4 ) );

		if ( $window > self::LONG_WINDOW ) {
			$shares[] = intdiv( 3 * $window + 2, 4 );
		}

		foreach ( $shares as $mark ) {
			$near = array_filter( $marks, static fn( int $other ): bool => abs( $other - $mark ) <= 3 );

			if ( $mark > 0 && $mark < $window && array() === $near ) {
				$marks[] = $mark;
			}
		}

		$marks = array_values( array_filter( $marks, static fn( int $mark ): bool => $mark < $window ) );
		rsort( $marks );

		return $marks;
	}

	/**
	 * Sends the reminder that is due in an open window: one message with the
	 * seconds left, however many reminders were missed. Under the gift's
	 * named lock, so two requests never send the same reminder.
	 *
	 * @param Gift $gift A gift.
	 * @throws DatabaseException When the gift cannot be read.
	 */
	private function remind( Gift $gift ): void {
		$ends = null === $gift->window_ends ? false : strtotime( $gift->window_ends . ' UTC' );
		$left = false === $ends ? 0 : $ends - time();

		if ( Gift::ENTERING !== $gift->status || $left <= 0 ) {
			return;
		}

		$pending = get_transient( self::remind_key( $gift->id ) );
		$pending = is_array( $pending ) ? array_values( array_filter( $pending, 'is_int' ) ) : array();
		$due     = array_filter( $pending, static fn( int $mark ): bool => $left <= $mark );

		if ( array() === $due || ! $this->gifts->lock( $gift->id, 2 ) ) {
			return;
		}

		try {
			// Read again under the lock: another request may have sent it.
			$pending = get_transient( self::remind_key( $gift->id ) );
			$pending = is_array( $pending ) ? array_values( array_filter( $pending, 'is_int' ) ) : array();
			$rest    = array_values( array_filter( $pending, static fn( int $mark ): bool => $left > $mark ) );

			if ( count( $rest ) === count( $pending ) ) {
				return;
			}

			set_transient( self::remind_key( $gift->id ), $rest, HOUR_IN_SECONDS );
			$language = $this->settings->find( $gift->creator_id )->language;
			( $this->send )( $gift->creator_id, GiftTexts::reminder( $language, $left, ( $this->product_name )( $gift->product_id ) ) );
		} finally {
			$this->gifts->unlock( $gift->id );
		}
	}

	/**
	 * The transient of a window's reminders still to send.
	 *
	 * @param int $gift_id Gift.
	 */
	private static function remind_key( int $gift_id ): string {
		return 'merchboard_gift_remind_' . $gift_id;
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
	 * Starts the creator's next gift when gifting is switched on for the shop
	 * (ADR-108), the creator is live, gifting is open and no window is open: a fresh gift (paid in the last half hour,
	 * never drawn), or any waiting gift during a started run.
	 *
	 * @param int $creator_id Creator.
	 * @return bool Whether a gift started.
	 * @throws DatabaseException When the gifts cannot be read or changed.
	 */
	private function next( int $creator_id ): bool {
		$settings = $this->settings->find( $creator_id );

		if ( ! Settings::twitch_gifting_enabled() || ! $settings->open() || array() !== $this->gifts->for_creator( $creator_id, array( Gift::ENTERING ), 1 ) || ! ( $this->live )( $creator_id ) ) {
			return false;
		}

		$run   = '1' === get_transient( self::run_key( $creator_id ) );
		$fresh = gmdate( 'Y-m-d H:i:s', time() - self::FRESH_MINUTES * MINUTE_IN_SECONDS );
		$tried = self::tried( $creator_id );

		// The gifts of giveaways start only when their creator starts them (ADR-110).
		foreach ( $this->gifts->for_creator( $creator_id, array( Gift::WAITING ), 50, false ) as $gift ) {
			if ( in_array( $gift->id, $tried, true ) || ( ! $run && ( 0 !== $gift->draws || $gift->created_at < $fresh ) ) ) {
				continue;
			}

			if ( ! $this->open( $gift, $settings->window, GiftTexts::announce( $settings->language, $gift->buyer_name, ( $this->product_name )( $gift->product_id ), $settings->window ) ) ) {
				// Another request opened a window just now: one at a time.
				return false;
			}

			$tried[] = $gift->id;
			set_transient( self::tried_key( $creator_id ), $tried, self::RUN_HOURS * HOUR_IN_SECONDS );

			return true;
		}

		if ( $run ) {
			delete_transient( self::run_key( $creator_id ) );
		}

		return false;
	}

	/**
	 * Opens the `!enter` window of a waiting gift: the announcement, the draw
	 * and reminder jobs and the clock.
	 *
	 * @param Gift   $gift         A waiting gift.
	 * @param int    $window       The window in seconds.
	 * @param string $announcement The announcement in the chat.
	 * @return bool Whether this request opened it.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function open( Gift $gift, int $window, string $announcement ): bool {
		if ( ! $this->gifts->open_window( $gift->id, gmdate( 'Y-m-d H:i:s', time() + $window ) ) ) {
			return false;
		}

		delete_transient( self::confirm_key( $gift->id ) );
		delete_transient( self::reveal_key( $gift->id ) );
		$reminders = self::reminders( $window );
		set_transient( self::remind_key( $gift->id ), $reminders, HOUR_IN_SECONDS );

		( $this->send )( $gift->creator_id, $announcement );
		( $this->enqueue )( self::DRAW_HOOK, array( $gift->id ), $window + 1 );

		foreach ( $reminders as $left ) {
			( $this->enqueue )( self::REMIND_HOOK, array( $gift->id ), $window - $left );
		}

		// The jobs run once a minute at most; the clock keeps the reminders and the draw on time.
		if ( null !== $this->tick ) {
			( $this->tick )( $gift->id );
		}

		return true;
	}

	/**
	 * Starts the draw of a giveaway's gift in the chat (ADR-110; the creator
	 * pressed "Start in the chat"): only while the creator is live and no
	 * other window is open.
	 *
	 * @param Gift   $gift         The giveaway's waiting gift.
	 * @param int    $window       The window in seconds.
	 * @param string $announcement The announcement in the chat.
	 * @return string `started`, `not_live`, `busy` (another window is open) or `not_waiting`.
	 * @throws DatabaseException When it cannot be read or stored.
	 */
	public function start_giveaway( Gift $gift, int $window, string $announcement ): string {
		if ( ! $gift->giveaway() || Gift::WAITING !== $gift->status ) {
			return 'not_waiting';
		}

		if ( true !== ( $this->live )( $gift->creator_id ) ) {
			return 'not_live';
		}

		if ( array() !== $this->gifts->for_creator( $gift->creator_id, array( Gift::ENTERING ), 1 ) ) {
			return 'busy';
		}

		return $this->open( $gift, $window, $announcement ) ? 'started' : 'busy';
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
			$reveal = self::reveal_at( $gift->id );

			if ( null === $reveal ) {
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
		delete_transient( self::remind_key( $gift->id ) );

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
			if ( null !== self::reveal_at( $gift->id ) ) {
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

		if ( $gift->giveaway() ) {
			$this->lapse_giveaway( $gift );

			return;
		}

		if ( $gift->draws < Gift::MAX_DRAWS && $this->settings->find( $gift->creator_id )->enabled ) {
			$this->gifts->wait_again( $gift->id, Gift::WON );

			return;
		}

		$this->give_back( $gift );
	}

	/**
	 * A giveaway's gift whose time ran out (ADR-110): an open link expired
	 * (the giveaway ends unused), or a winner did not claim it, so it is drawn
	 * again, and after the last draw the creator gets an open link to pass on.
	 *
	 * @param Gift $gift A giveaway's gift past its claim deadline.
	 * @throws DatabaseException When it cannot be stored.
	 */
	private function lapse_giveaway( Gift $gift ): void {
		if ( $gift->open_link() ) {
			if ( $this->gifts->expire_link( $gift->id ) ) {
				( $this->notify )( 'giveaway_expired', $gift, array() );
			}

			return;
		}

		if ( $gift->draws < Gift::MAX_DRAWS ) {
			$this->gifts->wait_again( $gift->id, Gift::WON );

			return;
		}

		$deadline = gmdate( 'Y-m-d H:i:s', time() + GiftCodes::GIVEAWAY_DAYS * DAY_IN_SECONDS );

		if ( $this->gifts->open_link( $gift->id, Gift::WON, $deadline ) ) {
			( $this->enqueue )( self::EXPIRE_HOOK, array( $gift->id ), GiftCodes::GIVEAWAY_DAYS * DAY_IN_SECONDS + 5 );
			( $this->notify )( 'giveaway_link', $gift, array() );
		}
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

		// A giveaway's gift has no buyer; its giveaway handles it (ADR-110).
		foreach ( $this->gifts->for_creator( $creator_id, $won ? array( Gift::WAITING, Gift::ENTERING, Gift::WON ) : array( Gift::WAITING, Gift::ENTERING ), 500, false ) as $gift ) {
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
	 * When a closed window's winner is revealed, or null before the closing.
	 * Without an object cache the transient comes back from the options
	 * table as a string in a later request.
	 *
	 * @param int $gift_id Gift.
	 */
	private static function reveal_at( int $gift_id ): ?int {
		$reveal = get_transient( self::reveal_key( $gift_id ) );

		return is_int( $reveal ) || ( is_string( $reveal ) && ctype_digit( $reveal ) ) ? (int) $reveal : null;
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
