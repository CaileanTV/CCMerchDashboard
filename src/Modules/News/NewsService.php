<?php
/**
 * Writing and publishing news messages.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\News;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * The administrator's news (ADR-072), only with `merchboard_manage_creators`:
 *
 * - a draft is written, changed and deleted;
 * - publishing shows it to every active creator once (published_at is set
 *   the first time only); a published message can still be corrected,
 *   which does not show it again;
 * - withdrawing hides it from the creators.
 *
 * Publishing, changing a published message, withdrawing and deleting are
 * audited with the id only, never the text.
 */
class NewsService {

	/**
	 * Constructor.
	 *
	 * @param NewsRepository $news        Messages.
	 * @param AuditTrail     $audit       Audit log.
	 * @param Transaction    $transaction Transactions.
	 */
	public function __construct( private NewsRepository $news, private AuditTrail $audit, private Transaction $transaction ) {
	}

	/**
	 * Writes a draft.
	 *
	 * @param string $title Title.
	 * @param string $body  HTML.
	 * @return int Id.
	 * @throws NewsException When not authorized or the text is invalid.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function create( string $title, string $body ): int {
		self::authorize();
		[ $title, $body ] = self::text( $title, $body );

		return $this->news->insert( $title, $body, get_current_user_id() );
	}

	/**
	 * Changes a message's title and body.
	 *
	 * @param int    $id    Id.
	 * @param string $title Title.
	 * @param string $body  HTML.
	 * @throws NewsException When not authorized, unknown or the text is invalid.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function update( int $id, string $title, string $body ): void {
		self::authorize();
		$message          = $this->require( $id );
		[ $title, $body ] = self::text( $title, $body );

		$this->transaction->run(
			function () use ( $message, $title, $body ): void {
				$this->news->update_text( $message->id, $title, $body );

				if ( NewsMessage::DRAFT !== $message->status ) {
					$this->audit->record( AuditAction::NewsUpdated, null, array( 'news_id' => $message->id ) );
				}
			}
		);
	}

	/**
	 * Publishes a draft or a withdrawn message.
	 *
	 * @param int $id Id.
	 * @throws NewsException When not authorized, unknown or published already.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function publish( int $id ): void {
		self::authorize();
		$message = $this->require( $id );

		if ( NewsMessage::PUBLISHED === $message->status ) {
			self::reject( NewsException::NOT_DRAFT );
		}

		$this->transaction->run(
			function () use ( $message ): void {
				$this->news->set_status( $message->id, NewsMessage::PUBLISHED, $message->published_at ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
				$this->audit->record( AuditAction::NewsPublished, null, array( 'news_id' => $message->id ) );
			}
		);
	}

	/**
	 * Hides a published message from the creators.
	 *
	 * @param int $id Id.
	 * @throws NewsException When not authorized, unknown or not published.
	 * @throws DatabaseException When it cannot be stored.
	 */
	public function withdraw( int $id ): void {
		self::authorize();
		$message = $this->require( $id );

		if ( NewsMessage::PUBLISHED !== $message->status ) {
			self::reject( NewsException::NOT_PUBLISHED );
		}

		$this->transaction->run(
			function () use ( $message ): void {
				$this->news->set_status( $message->id, NewsMessage::WITHDRAWN );
				$this->audit->record( AuditAction::NewsWithdrawn, null, array( 'news_id' => $message->id ) );
			}
		);
	}

	/**
	 * Deletes a draft (a published or withdrawn message stays as history).
	 *
	 * @param int $id Id.
	 * @throws NewsException When not authorized, unknown or not a draft.
	 * @throws DatabaseException When it cannot be deleted.
	 */
	public function delete( int $id ): void {
		self::authorize();
		$message = $this->require( $id );

		if ( NewsMessage::DRAFT !== $message->status ) {
			self::reject( NewsException::NOT_DRAFT );
		}

		$this->transaction->run(
			function () use ( $message ): void {
				$this->news->delete( $message->id );
				$this->audit->record( AuditAction::NewsDeleted, null, array( 'news_id' => $message->id ) );
			}
		);
	}

	/**
	 * A message.
	 *
	 * @param int $id Id.
	 * @throws NewsException When it does not exist.
	 * @throws DatabaseException When the database cannot be read.
	 */
	private function require( int $id ): NewsMessage {
		$message = $id > 0 ? $this->news->find( $id ) : null;

		if ( null === $message ) {
			self::reject( NewsException::UNKNOWN );
		}

		return $message;
	}

	/**
	 * A valid title and body.
	 *
	 * @param string $title Title.
	 * @param string $body  HTML.
	 * @return array{0: string, 1: string}
	 * @throws NewsException When the title or the body is empty.
	 */
	private static function text( string $title, string $body ): array {
		$title = NewsHtml::title( $title );
		$body  = NewsHtml::clean( $body );

		if ( '' === $title ) {
			self::reject( NewsException::INVALID_TITLE );
		}

		if ( ! NewsHtml::has_text( $body ) ) {
			self::reject( NewsException::INVALID_BODY );
		}

		return array( $title, $body );
	}

	/**
	 * Requires the capability (independent of the UI).
	 *
	 * @throws NewsException When the current user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CREATORS ) ) {
			self::reject( NewsException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Refuses a change.
	 *
	 * @param string $reason NewsException constant.
	 * @throws NewsException Always.
	 */
	private static function reject( string $reason ): never {
		throw new NewsException( $reason );
	}
}
