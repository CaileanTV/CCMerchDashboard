<?php
/**
 * Reads and saves a creator's merch ad overlay.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Community\MerchAd;

use Closure;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Community\TwitchExtension\TwitchExtensionSettings;
use MerchBoard\Modules\Community\TwitchSubs\TwitchSubDiscountInvalid;
use MerchBoard\Modules\Creators\CreatorMetaStore;

defined( 'ABSPATH' ) || exit;

/**
 * The creator's ad in creator meta `merch_ad` (ADR-113). The creator's form
 * is validated and audited (changed field names only); the overlay id is
 * made on the first save and can be renewed; "show now" and the
 * background-free images are stored without an audit entry.
 */
final class MerchAdRepository {

	/**
	 * Settings read in this request, by creator.
	 *
	 * @var array<int, MerchAdSettings>
	 */
	private array $cache = array();

	/**
	 * Whether a product is one of the creator's.
	 *
	 * @var Closure(int, int): bool
	 */
	private Closure $owns;

	/**
	 * A saved selection as the products that count (ADR-120).
	 *
	 * @var Closure(int, list<int>): list<int>
	 */
	private Closure $selection;

	/**
	 * Constructor.
	 *
	 * @phpstan-param Closure(int, int): bool $owns
	 * @phpstan-param (Closure(int, list<int>): list<int>)|null $selection
	 *
	 * @param CreatorMetaStore $meta        Creator meta.
	 * @param AuditTrail       $audit       Audit trail.
	 * @param Transaction      $transaction Transactions.
	 * @param Closure          $owns        Whether a product (2nd) is one of the creator's (1st).
	 * @param Closure|null     $selection   A creator's (1st) saved selection (2nd) as the products that count; as saved by default.
	 */
	public function __construct( private CreatorMetaStore $meta, private AuditTrail $audit, private Transaction $transaction, Closure $owns, ?Closure $selection = null ) {
		$this->owns      = $owns;
		$this->selection = $selection ?? static fn( int $creator_id, array $ids ): array => array_values( array_filter( $ids, 'is_int' ) );
	}

	/**
	 * A creator's settings.
	 *
	 * @param int $creator_id Creator.
	 */
	public function find( int $creator_id ): MerchAdSettings {
		if ( ! isset( $this->cache[ $creator_id ] ) ) {
			$stored = $this->meta->get( $creator_id, CreatorMetaStore::MERCH_AD );

			if ( is_array( $stored ) && is_array( $stored['product_ids'] ?? null ) ) {
				$stored['product_ids'] = $this->selected( $creator_id, $stored['product_ids'] );
			}

			$this->cache[ $creator_id ] = MerchAdSettings::from_stored( $stored );
		}

		return $this->cache[ $creator_id ];
	}

	/**
	 * The creator whose overlay has this id, or null.
	 *
	 * @param string $overlay_id The id in the OBS link.
	 * @throws \MerchBoard\Core\Database\DatabaseException When creator meta cannot be read.
	 */
	public function creator_for( string $overlay_id ): ?int {
		if ( 1 !== preg_match( '/^[0-9a-f]{32}$/', $overlay_id ) ) {
			return null;
		}

		foreach ( $this->meta->creator_ids_with( CreatorMetaStore::MERCH_AD ) as $creator_id ) {
			if ( hash_equals( $this->find( $creator_id )->overlay_id, $overlay_id ) ) {
				return $creator_id;
			}
		}

		return null;
	}

	/**
	 * Validates and saves the creator's form.
	 *
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $values     The fields of MerchAdSettings::form_fields().
	 * @throws TwitchSubDiscountInvalid With a code per invalid field (a failed write throws DatabaseException).
	 */
	public function save( int $creator_id, array $values ): MerchAdSettings {
		$errors = array();
		$ids    = $values['product_ids'] ?? null;
		$note   = $values['note'] ?? '';

		if ( ! is_bool( $values['enabled'] ?? null ) ) {
			$errors['enabled'] = 'required';
		}

		foreach ( array( 'show_names', 'glow', 'pill_gradient' ) as $field ) {
			if ( ! is_bool( $values[ $field ] ?? null ) ) {
				$errors[ $field ] = 'required';
			}
		}

		foreach ( array(
			'design'        => MerchAdSettings::DESIGNS,
			'enter'         => MerchAdSettings::ANIMATIONS,
			'leave'         => MerchAdSettings::ANIMATIONS,
			'theme'         => MerchAdSettings::THEMES,
			'pill_position' => MerchAdSettings::PILL_POSITIONS,
			'pill_size'     => MerchAdSettings::PILL_SIZES,
		) as $field => $allowed ) {
			if ( ! in_array( $values[ $field ] ?? null, $allowed, true ) ) {
				$errors[ $field ] = 'invalid';
			}
		}

		foreach ( array(
			'every'        => array( MerchAdSettings::MIN_EVERY, MerchAdSettings::MAX_EVERY ),
			'seconds'      => array( MerchAdSettings::MIN_SECONDS, MerchAdSettings::MAX_SECONDS ),
			'stack_spread' => array( 0, 100 ),
		) as $field => [ $min, $max ] ) {
			$value = $values[ $field ] ?? null;

			if ( ! is_int( $value ) || $value < $min || $value > $max ) {
				$errors[ $field ] = 'invalid';
			}
		}

		$accent = $values['accent'] ?? null;

		if ( ! is_string( $accent ) || 1 !== preg_match( '/^#[0-9a-fA-F]{6}$/', $accent ) ) {
			$errors['accent'] = 'invalid';
		}

		// A page opened before an update can still send a collection.
		$ids = is_array( $ids ) ? $this->selected( $creator_id, $ids ) : $ids;

		if ( ! is_array( $ids ) || ! array_is_list( $ids ) ) {
			$errors['product_ids'] = 'invalid';
		} elseif ( count( $ids ) > MerchAdSettings::MAX_PRODUCTS ) {
			$errors['product_ids'] = 'too_many';
		} else {
			foreach ( $ids as $id ) {
				if ( ! is_int( $id ) || $id < 1 || ! ( $this->owns )( $creator_id, $id ) ) {
					$errors['product_ids'] = 'not_owned';
					break;
				}
			}
		}

		if ( ! is_string( $note ) ) {
			$errors['note'] = 'invalid';
		} else {
			$note  = trim( sanitize_text_field( $note ) );
			$error = TwitchExtensionSettings::note_error( $note );

			if ( null !== $error ) {
				$errors['note'] = $error;
			}
		}

		if ( array() !== $errors || ! is_array( $ids ) ) {
			throw new TwitchSubDiscountInvalid( $errors );
		}

		$before   = $this->find( $creator_id );
		$settings = new MerchAdSettings(
			true === $values['enabled'],
			(string) $values['design'],
			array_values( array_unique( array_map( 'intval', $ids ) ) ),
			$note,
			(int) $values['every'],
			(int) $values['seconds'],
			(string) $values['enter'],
			(string) $values['leave'],
			strtolower( (string) $accent ),
			(string) $values['theme'],
			'' === $before->overlay_id ? self::new_id() : $before->overlay_id,
			$before->show_at,
			(string) $values['pill_position'],
			(string) $values['pill_size'],
			true === $values['show_names'],
			(int) $values['stack_spread'],
			true === $values['glow'],
			true === $values['pill_gradient']
		);

		$old     = $before->to_stored();
		$new     = $settings->to_stored();
		$changed = array_values( array_filter( MerchAdSettings::form_fields(), static fn( string $field ): bool => $old[ $field ] !== $new[ $field ] ) );

		if ( array() === $changed && $old === $new ) {
			return $settings;
		}

		return $this->store( $creator_id, $settings, $changed );
	}

	/**
	 * Gives the overlay a new id; the old OBS link stops working (audited).
	 *
	 * @param int $creator_id Creator.
	 * @throws \MerchBoard\Core\Database\DatabaseException When it cannot be stored.
	 */
	public function regenerate( int $creator_id ): MerchAdSettings {
		$settings             = clone $this->find( $creator_id );
		$settings->overlay_id = self::new_id();

		return $this->store( $creator_id, $settings, array( 'overlay_id' ) );
	}

	/**
	 * "Show now": the overlay shows the ad at its next check.
	 *
	 * @param int $creator_id Creator.
	 * @param int $now        Unix time.
	 * @throws \MerchBoard\Core\Database\DatabaseException When it cannot be stored.
	 */
	public function show_now( int $creator_id, int $now ): MerchAdSettings {
		$settings          = clone $this->find( $creator_id );
		$settings->show_at = $now;

		return $this->store( $creator_id, $settings, array() );
	}

	/**
	 * Stores settings, audited when the creator's fields changed.
	 *
	 * @phpstan-param list<string> $changed
	 *
	 * @param int             $creator_id Creator.
	 * @param MerchAdSettings $settings   New settings.
	 * @param string[]        $changed    Changed fields to audit; none: no audit entry.
	 * @throws \MerchBoard\Core\Database\DatabaseException When it cannot be stored.
	 */
	private function store( int $creator_id, MerchAdSettings $settings, array $changed ): MerchAdSettings {
		$this->transaction->run(
			function () use ( $creator_id, $settings, $changed ): void {
				$this->meta->set( $creator_id, CreatorMetaStore::MERCH_AD, $settings->to_stored() );

				if ( array() !== $changed ) {
					$this->audit->record( AuditAction::MerchAdChanged, $creator_id, array( 'changed_fields' => $changed ) );
				}
			}
		);

		$this->cache[ $creator_id ] = $settings;

		return $settings;
	}

	/**
	 * A new secret overlay id.
	 */
	private static function new_id(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * A selection without collections, which count as their designs.
	 *
	 * @param int          $creator_id Creator.
	 * @param array<mixed> $ids        As saved or sent.
	 * @return array<mixed> As it was when it is no list of ids.
	 */
	private function selected( int $creator_id, array $ids ): array {
		$whole = array_values( array_filter( $ids, 'is_int' ) );

		// A malformed or too long list is left to the validation.
		return count( $whole ) === count( $ids ) && array_is_list( $ids ) && count( $ids ) <= MerchAdSettings::MAX_PRODUCTS
			? array_slice( ( $this->selection )( $creator_id, $whole ), 0, MerchAdSettings::MAX_PRODUCTS )
			: $ids;
	}
}
