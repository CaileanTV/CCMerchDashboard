<?php
/**
 * Tests for the read-only audit log: repository reads, filters, formatter,
 * access and menu.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\Admin;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditEntry;
use MerchBoard\Core\Audit\AuditFilter;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Admin\AuditContextLabels;
use MerchBoard\Modules\Admin\AuditLogFormatter;
use MerchBoard\Modules\Admin\AuditLogPage;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Tests\Unit\Support\CreatorAdminEnvironment;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\WpDie;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( AuditRepository::class )]
#[CoversClass( AuditFilter::class )]
#[CoversClass( AuditLogPage::class )]
#[CoversClass( AuditLogFormatter::class )]
final class AuditLogTest extends TestCase {

	use CreatorAdminEnvironment;

	private AuditRepository $audit;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_creator_admin();
		$this->audit = new AuditRepository( $this->db );

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'sanitize_key' )->alias( static fn( string $s ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ) );
		Functions\when( 'wp_die' )->alias(
			static function ( string $message, string $title = '', array $args = array() ): void {
				throw new WpDie( $message, (int) ( $args['response'] ?? 500 ) );
			}
		);
	}

	public function test_paginate_returns_the_requested_page_newest_first_with_the_total(): void {
		$this->seed( 25 );

		$first = $this->audit->paginate( new AuditFilter(), 1, 20 );
		$last  = $this->audit->paginate( new AuditFilter(), 2, 20 );

		self::assertSame( 25, $first['total'] );
		self::assertCount( 20, $first['items'] );
		self::assertSame( 25, $first['items'][0]->id, 'Newest first.' );
		self::assertSame( array( 5, 4, 3, 2, 1 ), array_map( static fn( AuditEntry $e ): int => $e->id, $last['items'] ) );
		self::assertSame( array(), $this->audit->paginate( new AuditFilter(), 99, 20 )['items'], 'Beyond the last page: empty, no error.' );
	}

	public function test_paginate_bounds_page_and_page_size_and_never_selects_the_whole_table(): void {
		$this->seed( 3 );

		self::assertCount( 1, $this->audit->paginate( new AuditFilter(), -5, 0 )['items'], 'Page >= 1, size >= 1.' );
		self::assertCount( 3, $this->audit->paginate( new AuditFilter(), 1, 100000 )['items'] );

		$wpdb = new \MerchBoard\Tests\Unit\Support\RecordingWpdb();
		( new AuditRepository( $wpdb ) )->paginate( new AuditFilter(), 3, 100000 );
		self::assertStringEndsWith( 'ORDER BY id DESC LIMIT %d OFFSET %d', $wpdb->prepared[1]['query'] );
		self::assertSame( array( 'wp_merchboard_audit_log', 100, 200 ), $wpdb->prepared[1]['args'], 'Capped at 100 per page, offset from the page.' );
	}

	public function test_filters_are_applied_as_placeholders(): void {
		$this->seed( 4 );
		$this->db->data['wp_merchboard_audit_log'][4]['action']     = 'creator_deactivated';
		$this->db->data['wp_merchboard_audit_log'][4]['created_at'] = '2026-09-25 10:00:00';

		self::assertSame( 1, $this->audit->paginate( new AuditFilter( AuditAction::CreatorDeactivated ), 1, 20 )['total'] );
		self::assertSame( 2, $this->audit->paginate( new AuditFilter( null, 2 ), 1, 20 )['total'] );
		self::assertSame( 1, $this->audit->paginate( new AuditFilter( null, null, '2026-09-25 00:00:00', '2026-09-26 00:00:00' ), 1, 20 )['total'] );
		self::assertSame( 0, $this->audit->paginate( new AuditFilter( AuditAction::SettingsUpdated, 1 ), 1, 20 )['total'] );

		$wpdb = new \MerchBoard\Tests\Unit\Support\RecordingWpdb();
		( new AuditRepository( $wpdb ) )->paginate( new AuditFilter( AuditAction::CreatorCreated, 7, '2026-01-01 00:00:00', '2026-02-01 00:00:00' ), 1, 20 );
		self::assertSame( 'SELECT COUNT(*) FROM %i WHERE 1=1 AND action = %s AND creator_id = %d AND created_at >= %s AND created_at < %s', $wpdb->prepared[0]['query'] );
		self::assertSame( array( 'wp_merchboard_audit_log', 'creator_created', 7, '2026-01-01 00:00:00', '2026-02-01 00:00:00' ), $wpdb->prepared[0]['args'] );
	}

	public function test_filter_rejects_invalid_shapes(): void {
		foreach ( array( array( null, 0 ), array( null, -1 ), array( null, null, "2026-01-01' OR 1=1" ), array( null, null, null, '2026-01-01' ) ) as $args ) {
			try {
				new AuditFilter( ...$args );
				self::fail( 'Expected InvalidArgumentException.' );
			} catch ( InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	/**
	 * Request values that must be ignored.
	 *
	 * @return array<string, array{array<string, string>, string}>
	 */
	public static function invalid_requests(): array {
		return array(
			'unknown event'         => array( array( 'event' => 'creator_teleported' ), 'event' ),
			'sql in event'          => array( array( 'event' => "creator_created' OR 1=1 --" ), 'event' ),
			'wildcard event'        => array( array( 'event' => 'creator_%' ), 'event' ),
			'creator zero'          => array( array( 'creator' => '0' ), 'creator' ),
			'negative creator'      => array( array( 'creator' => '-3' ), 'creator' ),
			'sql in creator'        => array( array( 'creator' => '1 OR 1=1' ), 'creator' ),
			'wildcard creator'      => array( array( 'creator' => '%' ), 'creator' ),
			'overflow date'         => array( array( 'from' => '2026-02-31' ), 'from' ),
			'other date format'     => array( array( 'to' => '24.09.2026' ), 'to' ),
			'sql in date'           => array( array( 'from' => "2026-01-01' OR '1'='1" ), 'from' ),
			'period in wrong order' => array(
				array(
					'from' => '2026-09-25',
					'to'   => '2026-09-24',
				),
				'period',
			),
		);
	}

	/**
	 * Invalid values are dropped and reported, never used.
	 *
	 * @param array<string, string> $input   Request.
	 * @param string                $invalid Reported argument.
	 */
	#[DataProvider( 'invalid_requests' )]
	public function test_invalid_filter_values_are_ignored( array $input, string $invalid ): void {
		$request = AuditLogPage::filter_from_request( $input, new DateTimeZone( 'UTC' ) );

		self::assertTrue( $request['filter']->is_empty() );
		self::assertSame( array(), $request['values'] );
		self::assertContains( $invalid, $request['invalid'] );
	}

	public function test_valid_filters_and_day_boundaries_in_the_site_time_zone(): void {
		$request = AuditLogPage::filter_from_request(
			array(
				'event'   => 'creator_activated',
				'creator' => '12',
				'from'    => '2026-09-24',
				'to'      => '2026-09-24',
				'ignored' => 'x',
			),
			new DateTimeZone( 'Europe/Copenhagen' )
		);

		self::assertSame( array(), $request['invalid'] );
		self::assertSame( AuditAction::CreatorActivated, $request['filter']->action );
		self::assertSame( 12, $request['filter']->creator_id );
		self::assertSame( '2026-09-23 22:00:00', $request['filter']->from, 'Local midnight (UTC+2) in UTC.' );
		self::assertSame( '2026-09-24 22:00:00', $request['filter']->until, 'Exclusive end: the next local midnight.' );
		self::assertSame(
			array(
				'event'   => 'creator_activated',
				'creator' => '12',
				'from'    => '2026-09-24',
				'to'      => '2026-09-24',
			),
			$request['values']
		);
	}

	public function test_non_scalar_input_is_ignored(): void {
		$request = AuditLogPage::filter_from_request( array( 'event' => array( 'x' ), 'creator' => array( 1 ), 'from' => array() ), new DateTimeZone( 'UTC' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		self::assertTrue( $request['filter']->is_empty() );
	}

	public function test_every_action_has_a_label(): void {
		foreach ( AuditAction::cases() as $action ) {
			self::assertNotSame( '', AuditLogFormatter::action_label( $action ) );
		}
	}

	public function test_details_are_readable_and_never_raw_json(): void {
		$entry = $this->entry(
			AuditAction::CreatorUserUnlinked,
			array(
				'user_id'         => 42,
				'reason'          => 'user_deleted',
				'previous_status' => 'active',
				'changed_fields'  => array( 'display_name' ),
				'unknown <b>key'  => str_repeat( 'x', 150 ),
				'nested'          => array( 'email' => 'someone@example.test' ),
			)
		);

		$lines = AuditLogFormatter::details( $entry, new AuditContextLabels( array( 42 => 'Nova (nova)' ) ) );

		self::assertSame( 'WordPress user: Nova (nova)', $lines[0] );
		self::assertSame( 'Reason: the WordPress user was deleted', $lines[1] );
		self::assertSame( 'Previous status: Active', $lines[2] );
		self::assertSame( 'Changed: display name', $lines[3] );
		self::assertSame( 'unknownbkey: ' . str_repeat( 'x', 100 ) . '…', $lines[4], 'Unknown keys sanitized, values truncated.' );
		self::assertSame( 'nested: …', $lines[5], 'Nested data is not shown.' );
		self::assertStringNotContainsString( '@', implode( ' ', $lines ) );
		self::assertStringNotContainsString( '{', implode( ' ', $lines ) );
	}

	public function test_deleted_users_and_settings_changes_are_described(): void {
		self::assertSame(
			array( 'WordPress user: #7 (no longer exists)' ),
			AuditLogFormatter::details( $this->entry( AuditAction::CreatorUserLinked, array( 'user_id' => 7 ) ), new AuditContextLabels() )
		);
		self::assertSame(
			array( 'Setting: delete all data when the plugin is deleted', 'New value: enabled' ),
			AuditLogFormatter::details(
				$this->entry(
					AuditAction::SettingsUpdated,
					array(
						'setting' => 'delete_data_on_uninstall',
						'enabled' => true,
					)
				),
				new AuditContextLabels()
			)
		);
		self::assertSame( array( 'WordPress user: none', 'Status: Inactive' ), AuditLogFormatter::details( $this->entry( AuditAction::CreatorCreated, array( 'user_id' => null, 'status' => 'inactive' ) ), new AuditContextLabels() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_backfill_summaries_are_readable_counts(): void {
		$context = array(
			'run_id'              => '0f8b8c3e-2b0a-4c1e-9d7a-1a2b3c4d5e6f',
			'trigger_user_id'     => 42,
			'scanned'             => 12,
			'registered'          => 7,
			'skipped_history'     => 2,
			'skipped_not_counted' => 0,
			'skipped_no_creator'  => 1,
			'skipped_missing'     => 0,
			'skipped_busy'        => 0,
			'failed'              => 2,
			'rows'                => array(
				'sale'       => 7,
				'refund'     => 3,
				'reversal'   => 0,
				'correction' => 0,
			),
			'failures'            => array(
				'lock_timeout'     => 1,
				'negative_balance' => 1,
			),
		);

		self::assertSame( 'Sales ledger backfill completed', AuditLogFormatter::action_label( AuditAction::SalesBackfillCompleted ) );
		self::assertSame(
			array(
				'Run: <abbr title="0f8b8c3e-2b0a-4c1e-9d7a-1a2b3c4d5e6f">0f8b8c3e</abbr>',
				'Triggered by: Nova (nova)',
				'Orders scanned: 12',
				'Registered: 7 · Already in the ledger: 2 · Not counted: 0 · No creator: 1 · Not found: 0 · Busy: 0 · Failed: 2',
				'Rows written: Sale: 7 · Refund: 3 · Reversal: 0 · Correction: 0',
				'Failures by code: lock_timeout: 1, negative_balance: 1',
			),
			AuditLogFormatter::details( $this->entry( AuditAction::SalesBackfillCompleted, $context ), new AuditContextLabels( array( 42 => 'Nova (nova)' ) ) )
		);

		$context['failures'] = array();
		self::assertContains( 'Failures by code: none', AuditLogFormatter::details( $this->entry( AuditAction::SalesBackfillCompleted, $context ), new AuditContextLabels() ) );
	}

	public function test_a_malformed_backfill_summary_falls_back_to_plain_lines(): void {
		$lines = AuditLogFormatter::details(
			$this->entry(
				AuditAction::SalesBackfillCompleted,
				array(
					'registered' => '7',
					'rows'       => array( 'x' => 1 ),
				)
			),
			new AuditContextLabels()
		);

		self::assertSame( array( 'registered: 7', 'rows: …' ), $lines, 'Nothing is guessed from invalid counts.' );
	}

	public function test_counted_order_statuses_are_shown_as_flat_lists(): void {
		self::assertSame(
			array( 'Setting: counted order statuses', 'Previous value: completed, processing', 'New value: completed, on-hold, processing' ),
			AuditLogFormatter::details(
				$this->entry(
					AuditAction::SettingsUpdated,
					array(
						'setting'        => 'counted_order_statuses',
						'previous_value' => array( 'completed', 'processing' ),
						'value'          => array( 'completed', 'on-hold', 'processing' ),
					)
				),
				new AuditContextLabels()
			)
		);
	}

	/**
	 * Setting values and how they are shown.
	 *
	 * @return array<string, array{mixed, string}>
	 */
	public static function setting_values(): array {
		return array(
			'empty list'        => array( array(), '—' ),
			'scalars and bools' => array( array( 'a', 2, true ), 'a, 2, true' ),
			'underscore slugs'  => array( array( 'awaiting_shipment', 'completed' ), 'awaiting_shipment, completed' ),
			'nested list'       => array( array( 'a', array( 'b' ) ), '…' ),
			'null in a list'    => array( array( 'a', null ), '…' ),
			'associative array' => array( array( 'x' => 'a' ), '…' ),
			'scalar unchanged'  => array( 'creator-dashboard', 'creator-dashboard' ),
			'long list capped'  => array( array_fill( 0, 40, 'processing' ), mb_substr( implode( ', ', array_fill( 0, 40, 'processing' ) ), 0, 100 ) . '…' ),
			'<b>markup</b>'     => array( array( '<b>x</b>' ), '&lt;b&gt;x&lt;/b&gt;' ),
		);
	}

	#[DataProvider( 'setting_values' )]
	public function test_setting_values_show_only_flat_lists( mixed $value, string $shown ): void {
		$lines = AuditLogFormatter::details(
			$this->entry(
				AuditAction::SettingsUpdated,
				array(
					'previous_value' => $value,
					'value'          => $value,
				)
			),
			new AuditContextLabels()
		);

		self::assertSame( array( 'Previous value: ' . $shown, 'New value: ' . $shown ), $lines );
	}

	public function test_other_keys_still_hide_every_array(): void {
		$lines = AuditLogFormatter::details(
			$this->entry( AuditAction::SettingsUpdated, array( 'nested' => array( 'a', 'b' ) ) ),
			new AuditContextLabels()
		);

		self::assertSame( array( 'nested: …' ), $lines, 'Only previous_value and value show lists.' );
	}

	public function test_product_link_details_are_readable(): void {
		$labels = new AuditContextLabels(
			array( 5 => 'Ada (ada)' ),
			array( 3 => 'Studio Beta' ),
			array(
				10 => array(
					'name' => 'Tee',
					'url'  => 'https://shop.test/wp-admin/post.php?post=10&action=edit',
				),
				11 => array(
					'name' => 'Mug',
					'url'  => null,
				),
			),
			array(
				16 => 'Alpha',
				17 => 'Beta',
			)
		);
		$lines  = AuditLogFormatter::details(
			$this->entry(
				AuditAction::ProductCreatorLinked,
				array(
					'product_id'          => 10,
					'previous_creator_id' => 3,
					'source'              => 'category',
					'previous_source'     => 'admin',
					'reason'              => 'category_moved',
					'category_ids'        => array( 16, 17, 99 ),
					'trigger_user_id'     => 5,
					'run_id'              => '0b9f2c4e-1d2a-4c3b-8e5f-6a7b8c9d0e1f',
					'previous_link'       => 'invalid',
				)
			),
			$labels
		);

		self::assertSame(
			array(
				'Product: <a href="https://shop.test/wp-admin/post.php?post=10&action=edit">Tee (#10)</a>',
				'Previous creator: Studio Beta',
				'Origin: Product category',
				'Previous origin: Manual',
				'Reason: the product moved to another creator&#039;s category',
				'Product categories: Alpha (#16), Beta (#17), #99',
				'Triggered by: Ada (ada)',
				'Run: <abbr title="0b9f2c4e-1d2a-4c3b-8e5f-6a7b8c9d0e1f">0b9f2c4e</abbr>',
				'Previous link: invalid',
			),
			$lines,
			'esc_url is a pass-through stub here.'
		);

		$gone = AuditLogFormatter::details( $this->entry( AuditAction::ProductCreatorUnlinked, array( 'product_id' => 11, 'previous_creator_id' => 404, 'trigger_user_id' => 6 ) ), $labels ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::assertSame( array( 'Product: Mug (#11)', 'Previous creator: Creator #404 (not found)', 'Triggered by: #6 (no longer exists)' ), $gone, 'No link without edit_product; deleted creator and user.' );
		self::assertSame( array( 'Product: #12', 'Previous creator: none' ), AuditLogFormatter::details( $this->entry( AuditAction::ProductCreatorLinked, array( 'product_id' => 12, 'previous_creator_id' => null ) ), $labels ), 'A deleted product is "#id".' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Reason and trigger codes with their labels.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function reasons(): array {
		return array(
			'category_added'    => array( 'category_added', 'the product was added to a mapped category' ),
			'category_removed'  => array( 'category_removed', 'the product left its mapped category' ),
			'category_moved'    => array( 'category_moved', 'the product moved to another creator&#039;s category' ),
			'mapping_created'   => array( 'mapping_created', 'a category mapping was created' ),
			'mapping_removed'   => array( 'mapping_removed', 'a category mapping was removed' ),
			'creator_activated' => array( 'creator_activated', 'the creator was activated' ),
			'term_deleted'      => array( 'term_deleted', 'the product category was deleted' ),
			'ambiguous'         => array( 'ambiguous', 'categories of several creators (ambiguous)' ),
			'admin'             => array( 'admin', 'an administrator' ),
		);
	}

	#[DataProvider( 'reasons' )]
	public function test_every_decided_reason_code_has_a_label( string $code, string $label ): void {
		self::assertSame( array( 'Reason: ' . $label ), AuditLogFormatter::details( $this->entry( AuditAction::ProductCreatorUnlinked, array( 'reason' => $code ) ), new AuditContextLabels() ) );
	}

	public function test_mapping_details_sources_and_choices_are_readable(): void {
		$labels = new AuditContextLabels( array(), array(), array(), array( 16 => 'Alpha' ) );

		self::assertSame(
			array( 'Product category: Alpha (#16)', 'Reason: an administrator', 'Choice: Remove category-managed links' ),
			AuditLogFormatter::details( $this->entry( AuditAction::CreatorCategoryUnlinked, array( 'term_id' => 16, 'reason' => 'admin', 'choice' => 'remove_links' ) ), $labels ) // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
		self::assertSame(
			array( 'Product category: #18', 'Reason: the product category was deleted', 'Choice: Keep as manual links' ),
			AuditLogFormatter::details( $this->entry( AuditAction::CreatorCategoryUnlinked, array( 'term_id' => 18, 'reason' => 'term_deleted', 'choice' => 'keep_as_manual' ) ), $labels ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'A deleted category is "#id".'
		);
		self::assertSame(
			array( 'Origin: Manual', 'Previous origin: Product category' ),
			AuditLogFormatter::details( $this->entry( AuditAction::ProductCreatorLinked, array( 'source' => 'admin', 'previous_source' => 'category' ) ), $labels ) // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
	}

	public function test_unknown_codes_and_hostile_values_are_escaped_and_never_fatal(): void {
		$labels = new AuditContextLabels(
			array( 5 => 'Eve <b>(eve)</b>' ),
			array( 3 => 'Studio <script>' ),
			array(
				10 => array(
					'name' => 'Tee <img src=x>',
					'url'  => null,
				),
			),
			array( 16 => 'Cat "quoted" <i>' )
		);
		$lines  = AuditLogFormatter::details(
			$this->entry(
				AuditAction::ProductCreatorLinked,
				array(
					'product_id'          => 10,
					'previous_creator_id' => 3,
					'term_id'             => 16,
					'trigger_user_id'     => 5,
					'reason'              => 'new_<b>reason',
					'source'              => 'backfill<script>',
					'choice'              => array( 'nested' ),
					'trigger'             => 'something_else',
					'scope'               => '<i>x</i>',
					'run_id'              => '"><script>alert(1)</script>',
				)
			),
			$labels
		);
		$html   = implode( "\n", $lines );

		foreach ( array( '<b>', '<script>', '<img', '<i>' ) as $tag ) {
			self::assertStringNotContainsString( $tag, $html, $tag );
		}
		self::assertStringContainsString( 'Reason: new_&lt;b&gt;reason', $html, 'Unknown code shown as it is, escaped.' );
		self::assertStringContainsString( 'Origin: backfill&lt;script&gt;', $html );
		self::assertStringContainsString( 'Choice: …', $html, 'Nested data is not shown.' );
		self::assertStringContainsString( 'Trigger: something_else', $html );
		self::assertStringContainsString( 'Product: Tee &lt;img src=x&gt; (#10)', $html );
		self::assertStringContainsString( 'Product category: Cat &quot;quoted&quot; &lt;i&gt; (#16)', $html );
		self::assertStringContainsString( 'title="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html );
	}

	public function test_category_sync_counts_are_one_line_in_the_result_order(): void {
		$context = array(
			'run_id'           => '0b9f2c4e-1d2a-4c3b-8e5f-6a7b8c9d0e1f',
			'dry_run'          => false,
			'trigger'          => 'full_sync',
			'trigger_user_id'  => 5,
			'scope'            => 'all',
			'linked'           => 12,
			'moved'            => 3,
			'unlinked'         => 2,
			'unchanged'        => 85,
			'skipped_manual'   => 4,
			'ambiguous'        => 1,
			'skipped_inactive' => 0,
			'errors'           => 0,
			'total'            => 107,
		);

		self::assertSame(
			array(
				'Run: <abbr title="0b9f2c4e-1d2a-4c3b-8e5f-6a7b8c9d0e1f">0b9f2c4e</abbr>',
				'Dry run: no',
				'Trigger: full reconciliation',
				'Triggered by: #5 (no longer exists)',
				'Scope: all relevant products',
				'Linked: 12 · Moved: 3 · Removed: 2 · Unchanged: 85 · Manual: 4 · Ambiguous: 1 · Inactive: 0 · Errors: 0',
				'Products evaluated: 107',
			),
			AuditLogFormatter::details( $this->entry( AuditAction::CategorySyncCompleted, $context ), new AuditContextLabels() )
		);

		$scoped = array(
			'scope'      => 'mapping',
			'term_id'    => 16,
			'creator_id' => 3,
		) + $context;
		$lines  = AuditLogFormatter::details( $this->entry( AuditAction::CategorySyncCompleted, $scoped ), new AuditContextLabels( array(), array( 3 => 'Studio Beta' ), array(), array( 16 => 'Alpha' ) ) );
		self::assertSame( array( 'Scope: the products of one mapping', 'Product category: Alpha (#16)', 'Creator: Studio Beta' ), array_slice( $lines, 0, 3 ) );

		unset( $context['errors'] );
		self::assertContains( 'linked: 12', AuditLogFormatter::details( $this->entry( AuditAction::CategorySyncCompleted, $context ), new AuditContextLabels() ), 'Incomplete counts are not merged.' );
	}

	public function test_context_ids_collect_every_referenced_id_for_bulk_lookups(): void {
		$entry = $this->entry(
			AuditAction::CategorySyncCompleted,
			array(
				'user_id'             => 7,
				'trigger_user_id'     => 5,
				'product_id'          => 10,
				'previous_creator_id' => 3,
				'creator_id'          => 4,
				'term_id'             => 16,
				'category_ids'        => array( 17, 'x', -1, 18 ),
			)
		);

		self::assertSame( array( 7, 5 ), AuditLogFormatter::context_user_ids( $entry ) );
		self::assertSame(
			array(
				'users'      => array( 7, 5 ),
				'creators'   => array( 3, 4 ),
				'products'   => array( 10 ),
				'categories' => array( 16, 17, 18 ),
			),
			AuditLogFormatter::context_ids( $entry )
		);
		self::assertSame(
			array(
				'users'      => array(),
				'creators'   => array(),
				'products'   => array(),
				'categories' => array(),
			),
			AuditLogFormatter::context_ids( $this->entry( AuditAction::ProductCreatorLinked, array( 'product_id' => '10', 'category_ids' => 'x' ) ) ) // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
	}

	public function test_page_requires_merchboard_view_logs(): void {
		foreach ( array( array( Capabilities::MANAGE ), array( Capabilities::MANAGE_CREATORS ), array( Capabilities::MANAGE_SETTINGS ), array( Capabilities::ACCESS_DASHBOARD ), array( 'manage_options', 'manage_woocommerce' ) ) as $caps ) {
			$this->caps = $caps;

			try {
				$this->page()->render();
				self::fail( 'Expected WpDie for ' . implode( ',', $caps ) );
			} catch ( WpDie $e ) {
				self::assertSame( 403, $e->response );
			}
		}
	}

	public function test_menu_entry_uses_merchboard_view_logs(): void {
		$registered = array();
		Functions\when( 'add_submenu_page' )->alias(
			static function ( string $parent_slug, string $page_title, string $menu_title, string $capability, string $slug ) use ( &$registered ): string {
				$registered = array( $parent_slug, $capability, $slug );
				return 'hook';
			}
		);

		$this->page()->add_menu();

		self::assertSame( array( 'merchboard', Capabilities::VIEW_LOGS, 'merchboard-audit-log' ), $registered );
	}

	/**
	 * Adds audit records with increasing time (creator 1 for odd, 2 for even ids).
	 *
	 * @param int $count Number of records.
	 */
	private function seed( int $count ): void {
		for ( $i = 1; $i <= $count; $i++ ) {
			$this->audit->append( AuditAction::CreatorCreated, 1, 1 === $i % 2 ? 1 : 2, array(), sprintf( '2026-09-24 10:%02d:00', $i ) );
		}
	}

	/**
	 * An in-memory audit entry.
	 *
	 * @param AuditAction          $action  Action.
	 * @param array<string, mixed> $context Context.
	 */
	private function entry( AuditAction $action, array $context ): AuditEntry {
		return new AuditEntry( 1, $action, 1, 1, $context, new DateTimeImmutable( '2026-09-24 10:00:00', new DateTimeZone( 'UTC' ) ) );
	}

	private function page(): AuditLogPage {
		return new AuditLogPage( $this->audit, new CreatorRepository( $this->db ), $this->users, new RecordingLogger() );
	}
}
