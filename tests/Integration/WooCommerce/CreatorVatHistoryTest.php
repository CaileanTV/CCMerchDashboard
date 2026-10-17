<?php
/**
 * History of the creators' VAT status on a real database (ADR-062 K06).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\WooCommerce;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Security\ProtectedProductMeta;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorMarkupRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorPricingSettings;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatHistoryRepository;
use MerchBoard\Modules\WooCommerce\Pricing\CreatorVatRepository;
use MerchBoard\Modules\WooCommerce\Pricing\PricingException;
use MerchBoard\Modules\WooCommerce\Pricing\ProductMarkupRepository;
use MerchBoard\Modules\WooCommerce\ProductCreatorLink;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\Support\PostMetaPricingProductStore;
use MerchBoard\Tests\Integration\Support\PostMetaProductStore;
use ReflectionClass;
use ReflectionMethod;
use WP_User;

/**
 * Registrations through CreatorPricingSettings with a fixed clock
 * (2026-10-17 12:00 UTC; the test site's calendar is UTC). Synthetic data
 * only; the VAT model is not validated by an auditor or the Danish Tax
 * Agency (ADR-062, reservations).
 */
final class CreatorVatHistoryTest extends IntegrationTestCase {

	private CreatorPricingSettings $settings;

	private CreatorVatHistoryRepository $history;

	private CreatorVatRepository $vat;

	private int $alpha;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$creators      = new CreatorRepository( $this->wpdb );
		$this->alpha   = $creators->create( 'VAT History Alpha', CreatorStatus::Active )->id;
		$this->vat     = new CreatorVatRepository( $this->wpdb );
		$this->history = new CreatorVatHistoryRepository( $this->wpdb );
		$audit         = $this->service( AuditTrail::class );
		$transaction   = $this->service( Transaction::class );
		$links         = new ProductCreatorLink( new PostMetaProductStore(), $creators, $audit, $transaction, $this->service( ProtectedProductMeta::class ) );

		$this->settings = new CreatorPricingSettings(
			$creators,
			new CreatorMarkupRepository( $this->wpdb ),
			new ProductMarkupRepository( $this->wpdb ),
			$links,
			new PostMetaPricingProductStore(),
			$audit,
			$transaction,
			$this->vat,
			$this->history,
			static fn(): DateTimeImmutable => new DateTimeImmutable( '2026-10-17 12:00:00', new DateTimeZone( 'UTC' ) )
		);
	}

	public function test_registrations_are_added_and_never_overwritten(): void {
		self::assertFalse( $this->vat->find( $this->alpha ), 'New creators are not VAT registered.' );
		self::assertSame( array(), $this->history->for_creator( $this->alpha ), 'No history is invented.' );

		self::assertSame( 'vat_saved', $this->record( true, '2026-03-01', '2026-03-05', 'CVR 12345678', 'Registered at SKAT.' ) );
		self::assertSame( 'vat_saved', $this->record( false, '2026-09-01', '2026-09-03', 'CVR lookup 2026-09-03', "Deregistered.\nConfirmed by the creator." ) );

		$entries = $this->history->for_creator( $this->alpha );
		self::assertCount( 2, $entries );
		self::assertSame( array( false, '2026-09-01', '2026-09-03', 'CVR lookup 2026-09-03', "Deregistered.\nConfirmed by the creator." ), array( $entries[0]->vat_registered, $entries[0]->effective_from, $entries[0]->checked_on, $entries[0]->reference, $entries[0]->reason ), 'Most recently recorded first.' );
		self::assertSame( array( true, '2026-03-01', '2026-03-05' ), array( $entries[1]->vat_registered, $entries[1]->effective_from, $entries[1]->checked_on ), 'The earlier registration is kept unchanged.' );
		self::assertSame( get_current_user_id(), $entries[0]->recorded_by );
		self::assertSame( '2026-10-17 12:00:00', $entries[0]->recorded_at->format( 'Y-m-d H:i:s' ), 'Recorded at is separate from the effective and check dates.' );
		self::assertFalse( $this->vat->find( $this->alpha ), 'The current status is the one in force today.' );

		self::assertSame( 'vat_unchanged', $this->record( false, '2026-09-01', '2026-09-03', 'CVR lookup 2026-09-03', "Deregistered.\r\nConfirmed by the creator." ), 'Identical to the last one (line breaks normalised): nothing is recorded.' );
		self::assertSame( 2, $this->count_rows( Tables::CREATOR_VAT_HISTORY ) );
	}

	public function test_the_status_on_a_date_follows_the_effective_dates_and_corrections(): void {
		$this->record( true, '2026-03-01', '2026-03-05', 'CVR 12345678', 'Registered at SKAT.' );
		$this->record( false, '2026-09-01', '2026-09-03', 'CVR lookup', 'Deregistered.' );

		self::assertNull( $this->history->at( $this->alpha, '2026-02-28' ), 'Before the first registration: no history applies.' );
		self::assertTrue( $this->history->at( $this->alpha, '2026-03-01' )?->vat_registered, 'From the effective date, inclusive.' );
		self::assertTrue( $this->history->at( $this->alpha, '2026-08-31' )?->vat_registered );
		self::assertFalse( $this->history->at( $this->alpha, '2026-09-01' )?->vat_registered );

		// A correction of the first registration: same date, recorded later, wins.
		self::assertSame( 'vat_recorded', $this->record( false, '2026-03-01', '2026-10-17', 'SKAT letter 2026-10-16', 'Correction: the registration was refused.' ) );
		$may = $this->history->at( $this->alpha, '2026-05-01' );
		self::assertNotNull( $may );
		self::assertSame( array( false, 'Correction: the registration was refused.' ), array( $may->vat_registered, $may->reason ) );
		self::assertCount( 3, $this->history->for_creator( $this->alpha ), 'The corrected registration is kept.' );
	}

	public function test_a_registration_with_an_earlier_date_leaves_the_current_status_unchanged(): void {
		self::assertSame( 'vat_saved', $this->record( true, '2026-06-01', '2026-06-02', 'CVR 12345678', 'Registered.' ) );
		self::assertTrue( $this->vat->find( $this->alpha ) );

		// Not registered before June: recorded, but the June registration applies today.
		self::assertSame( 'vat_recorded', $this->record( false, '2026-01-01', '2026-06-02', 'CVR 12345678', 'Not registered before June.' ) );
		self::assertTrue( $this->vat->find( $this->alpha ), 'The current status is unchanged.' );
		self::assertFalse( $this->history->at( $this->alpha, '2026-05-31' )?->vat_registered );

		$audit = $this->audit_contexts( 'creator_vat_history_recorded' );
		self::assertCount( 2, $audit );
		self::assertSame( array( true, false ), array( $audit[1]['current_vat_registered'] ?? null, $audit[1]['current_changed'] ?? null ) );
		self::assertCount( 1, $this->audit_contexts( 'creator_vat_status_changed' ), 'Only the change of the current status.' );
	}

	public function test_the_audit_records_the_registration_without_its_texts(): void {
		$this->record( true, '2026-03-01', '2026-03-05', 'CVR 12345678 (private)', 'Reason with personal details.' );

		$history = $this->audit_contexts( 'creator_vat_history_recorded' );
		$changed = $this->audit_contexts( 'creator_vat_status_changed' );

		self::assertCount( 1, $history );
		self::assertSame(
			array(
				'entry_id'               => $this->history->latest( $this->alpha )->id ?? null,
				'vat_registered'         => true,
				'effective_from'         => '2026-03-01',
				'checked_on'             => '2026-03-05',
				'has_reference'          => true,
				'has_reason'             => true,
				'current_vat_registered' => true,
				'current_changed'        => true,
			),
			$history[0]
		);
		self::assertSame( array( array( 'previous_vat_registered' => false, 'vat_registered' => true ) ), $changed ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertStringNotContainsString( 'private', (string) wp_json_encode( $history ) );
		self::assertStringNotContainsString( 'personal', (string) wp_json_encode( $history ) );
	}

	public function test_dates_and_texts_are_validated_and_nothing_is_written_when_refused(): void {
		$cases = array(
			array( PricingException::INVALID_EFFECTIVE_DATE, '2026-10-18', '2026-10-17', 'Ref', 'Reason', 'An effective date in the future.' ),
			array( PricingException::INVALID_EFFECTIVE_DATE, '2026-02-30', '2026-10-17', 'Ref', 'Reason', 'Not a date.' ),
			array( PricingException::INVALID_EFFECTIVE_DATE, '17-10-2026', '2026-10-17', 'Ref', 'Reason', 'Another format.' ),
			array( PricingException::INVALID_EFFECTIVE_DATE, '1999-12-31', '2026-10-17', 'Ref', 'Reason', 'Before 2000.' ),
			array( PricingException::INVALID_EFFECTIVE_DATE, '', '2026-10-17', 'Ref', 'Reason', 'Missing.' ),
			array( PricingException::INVALID_CHECK_DATE, '2026-10-01', '2026-10-18', 'Ref', 'Reason', 'A check date in the future.' ),
			array( PricingException::INVALID_CHECK_DATE, '2026-10-01', '2026-13-01', 'Ref', 'Reason', 'Not a date.' ),
			array( PricingException::INVALID_REFERENCE, '2026-10-01', '2026-10-17', '  ', 'Reason', 'Empty.' ),
			array( PricingException::INVALID_REFERENCE, '2026-10-01', '2026-10-17', str_repeat( 'ø', 192 ), 'Reason', 'Too long.' ),
			array( PricingException::INVALID_REFERENCE, '2026-10-01', '2026-10-17', "Ref\nbreak", 'Reason', 'A line break.' ),
			array( PricingException::INVALID_REASON, '2026-10-01', '2026-10-17', 'Ref', '', 'Empty.' ),
			array( PricingException::INVALID_REASON, '2026-10-01', '2026-10-17', 'Ref', str_repeat( 'a', 501 ), 'Too long.' ),
			array( PricingException::INVALID_REASON, '2026-10-01', '2026-10-17', 'Ref', "Reason\x07", 'A control character.' ),
		);

		foreach ( $cases as list( $code, $effective, $checked, $reference, $reason, $message ) ) {
			try {
				$this->settings->record_vat_status( $this->alpha, true, $effective, $checked, $reference, $reason );
				self::fail( 'Expected a refusal: ' . $message );
			} catch ( PricingException $e ) {
				self::assertSame( $code, $e->reason(), $message );
			}
		}

		self::assertSame( 'vat_saved', $this->record( true, '2026-10-17', '2026-10-17', str_repeat( 'ø', 191 ), str_repeat( 'a', 500 ) ), 'Today and the longest texts are accepted.' );
		self::assertSame( 1, $this->count_rows( Tables::CREATOR_VAT_HISTORY ) );
		self::assertCount( 1, $this->audit_contexts( 'creator_vat_history_recorded' ) );
	}

	public function test_only_the_pricing_capability_may_register_and_unknown_creators_are_refused(): void {
		$this->refused( PricingException::CREATOR_NOT_FOUND, 999999 );
		$this->refused( PricingException::CREATOR_NOT_FOUND, 0 );

		$editor = $this->create_user( 'mb-k06-history-editor' );
		( new WP_User( $editor ) )->set_role( 'editor' );
		wp_set_current_user( $editor );
		self::assertFalse( current_user_can( Capabilities::MANAGE_CREATOR_PRICING ) );
		$this->refused( PricingException::NOT_AUTHORIZED, $this->alpha );

		self::assertSame( 0, $this->count_rows( Tables::CREATOR_VAT_HISTORY ) );
		self::assertFalse( $this->vat->find( $this->alpha ) );
		self::assertSame( array(), $this->audit_contexts( 'creator_vat_history_recorded' ) );
	}

	public function test_the_history_can_only_be_added_to(): void {
		$methods = array_map( static fn( ReflectionMethod $method ): string => $method->getName(), ( new ReflectionClass( CreatorVatHistoryRepository::class ) )->getMethods( ReflectionMethod::IS_PUBLIC ) );
		sort( $methods );

		self::assertSame( array( '__construct', 'at', 'for_creator', 'insert', 'latest', 'lock_creator' ), $methods, 'No method changes or deletes a registration.' );
	}

	/**
	 * Records a registration for Alpha.
	 *
	 * @param bool   $vat       VAT registered.
	 * @param string $effective Applies from.
	 * @param string $checked   Checked on.
	 * @param string $reference Documentation reference.
	 * @param string $reason    Reason.
	 */
	private function record( bool $vat, string $effective, string $checked, string $reference, string $reason ): string {
		return $this->settings->record_vat_status( $this->alpha, $vat, $effective, $checked, $reference, $reason );
	}

	/**
	 * Asserts a refused registration.
	 *
	 * @param string $code       Expected code.
	 * @param int    $creator_id Creator.
	 */
	private function refused( string $code, int $creator_id ): void {
		try {
			$this->settings->record_vat_status( $creator_id, true, '2026-10-01', '2026-10-17', 'Ref', 'Reason' );
			self::fail( 'Expected a refusal: ' . $code );
		} catch ( PricingException $e ) {
			self::assertSame( $code, $e->reason() );
		}
	}

	/**
	 * Contexts of the audit records of an action, oldest first.
	 *
	 * @param string $action Action.
	 * @return list<array<string, mixed>>
	 */
	private function audit_contexts( string $action ): array {
		$rows = $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT context FROM %i WHERE action = %s ORDER BY id', $this->table( Tables::AUDIT_LOG ), $action ) );

		return array_map( static fn( mixed $context ): array => (array) json_decode( (string) $context, true ), $rows );
	}
}
