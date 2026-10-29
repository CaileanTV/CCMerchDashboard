<?php
/**
 * Tests for the counted order statuses setting: service, handler and section.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Modules\WooCommerce\Admin;

use Brain\Monkey\Functions;
use MerchBoard\Core\Audit\AuditRepository;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Database\TransactionSupport;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\WooCommerce\Admin\SalesLedgerSettingsActions;
use MerchBoard\Modules\WooCommerce\Admin\SalesLedgerSettingsSection;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatusException;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatuses;
use MerchBoard\Modules\WooCommerce\Sales\CountedOrderStatusSettings;
use MerchBoard\Tests\Unit\Support\FakeDatabase;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\WpDie;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass( CountedOrderStatusSettings::class )]
#[CoversClass( CountedOrderStatusException::class )]
#[CoversClass( SalesLedgerSettingsActions::class )]
#[CoversClass( SalesLedgerSettingsSection::class )]
final class SalesLedgerSettingsTest extends TestCase {

	/**
	 * WooCommerce' registered statuses plus one from another plugin.
	 */
	private const REGISTERED = array(
		'wc-pending'        => 'Pending payment',
		'wc-processing'     => 'Processing',
		'wc-on-hold'        => 'On hold',
		'wc-completed'      => 'Completed',
		'wc-cancelled'      => 'Cancelled',
		'wc-refunded'       => 'Refunded',
		'wc-failed'         => 'Failed',
		'wc-checkout-draft' => 'Draft',
		'wc-shipped'        => 'Shipped',
	);

	private FakeDatabase $db;

	private InMemoryOptions $options;

	private RecordingLogger $logger;

	private CountedOrderStatusSettings $settings;

	/**
	 * What wc_get_order_statuses() returns; null when WooCommerce is unavailable.
	 *
	 * @var array<mixed>|null
	 */
	private ?array $registered = self::REGISTERED;

	/**
	 * Capabilities of the current user.
	 *
	 * @var array<int, string>
	 */
	private array $caps = array( Capabilities::MANAGE_SETTINGS );

	/**
	 * Nonce actions verified, in order.
	 *
	 * @var list<string>
	 */
	private array $nonces = array();

	protected function setUp(): void {
		parent::setUp();
		$this->db       = new FakeDatabase();
		$this->options  = InMemoryOptions::install();
		$this->logger   = new RecordingLogger();
		$this->settings = new CountedOrderStatusSettings(
			new AuditTrail( new AuditRepository( $this->db ) ),
			new Transaction( $this->db, new TransactionSupport( $this->db ) ),
			new CountedOrderStatuses(),
			fn(): ?array => $this->registered
		);

		Functions\when( 'current_user_can' )->alias( fn( string $cap ): bool => in_array( $cap, $this->caps, true ) );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'current_time' )->justReturn( '2026-09-30 12:00:00' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_verify_nonce' )->alias(
			function ( string $nonce, string $action ): int|false {
				$this->nonces[] = $action;

				return 'valid:' . $action === $nonce ? 1 : false;
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function ( string $message, string $title = '', array $args = array() ): void {
				throw new WpDie( $message, (int) ( $args['response'] ?? 500 ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $s ): string => trim( strip_tags( $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
		Functions\when( 'sanitize_key' )->alias( static fn( string $s ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ) );
	}

	protected function tearDown(): void {
		unset( $_GET[ SalesLedgerSettingsActions::RESULT_ARG ] );
		parent::tearDown();
	}

	public function test_choices_are_the_registered_allowed_statuses_in_woocommerce_order(): void {
		self::assertSame(
			array(
				array(
					'status'     => 'processing',
					'label'      => 'Processing',
					'registered' => true,
					'selected'   => true,
				),
				array(
					'status'     => 'on-hold',
					'label'      => 'On hold',
					'registered' => true,
					'selected'   => false,
				),
				array(
					'status'     => 'completed',
					'label'      => 'Completed',
					'registered' => true,
					'selected'   => true,
				),
				array(
					'status'     => 'shipped',
					'label'      => 'Shipped',
					'registered' => true,
					'selected'   => false,
				),
			),
			$this->settings->choices()
		);
	}

	public function test_registered_and_third_party_statuses_can_be_saved_sorted_and_audited(): void {
		self::assertTrue( $this->settings->save( array( 'wc-shipped', 'on-hold', 'completed', 'on-hold' ) ) );

		self::assertSame( array( 'completed', 'on-hold', 'shipped' ), Settings::counted_order_statuses() );
		$audit = $this->db->rows( 'merchboard_audit_log' );
		self::assertCount( 1, $audit );
		self::assertSame( 'settings_updated', $audit[0]['action'] );
		self::assertNull( $audit[0]['creator_id'] );
		self::assertSame( '{"setting":"counted_order_statuses","previous_value":["processing","completed"],"value":["completed","on-hold","shipped"]}', $audit[0]['context'] );
	}

	public function test_saving_keeps_the_other_settings(): void {
		$this->options->options[ Settings::OPTION ] = array(
			Settings::DELETE_DATA_ON_UNINSTALL => true,
			Settings::DASHBOARD_SLUG           => 'studio',
		);

		$this->settings->save( array( 'completed' ) );

		self::assertTrue( Settings::delete_data_on_uninstall() );
		self::assertSame( 'studio', Settings::dashboard_slug() );
	}

	/**
	 * The same statuses in another order, with duplicates or with the prefix.
	 *
	 * @return array<string, array{list<string>}>
	 */
	public static function same_as_default(): array {
		return array(
			'stored order'    => array( array( 'processing', 'completed' ) ),
			'sorted'          => array( array( 'completed', 'processing' ) ),
			'duplicates'      => array( array( 'completed', 'processing', 'completed' ) ),
			'with the prefix' => array( array( 'wc-processing', 'completed', 'processing' ) ),
		);
	}

	/**
	 * The same list never causes a change or an audit record.
	 *
	 * @param string[] $statuses Submitted statuses.
	 */
	#[DataProvider( 'same_as_default' )]
	public function test_an_unchanged_list_writes_nothing( array $statuses ): void {
		self::assertFalse( $this->settings->save( $statuses ) );

		self::assertSame( array(), $this->options->updates );
		self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
	}

	public function test_saving_the_stored_list_again_is_no_change(): void {
		$this->settings->save( array( 'shipped', 'completed' ) );
		$this->options->updates = array();

		self::assertFalse( $this->settings->save( array( 'completed', 'wc-shipped' ) ) );
		self::assertSame( array(), $this->options->updates );
		self::assertCount( 1, $this->db->rows( 'merchboard_audit_log' ) );
	}

	/**
	 * Every denied status.
	 *
	 * @return array<string, array{string}>
	 */
	public static function denied(): array {
		return array_combine( CountedOrderStatuses::DENIED, array_map( static fn( string $s ): array => array( $s ), CountedOrderStatuses::DENIED ) );
	}

	#[DataProvider( 'denied' )]
	public function test_denied_statuses_are_rejected_before_anything_is_written( string $status ): void {
		$this->assert_rejected( array( 'completed', $status ), CountedOrderStatusException::NOT_ALLOWED );
		$this->assert_rejected( array( 'completed', 'wc-' . $status ), CountedOrderStatusException::NOT_ALLOWED );
	}

	/**
	 * Statuses that are neither registered nor already counted.
	 *
	 * @return array<string, array{string}>
	 */
	public static function unknown(): array {
		return array(
			'unregistered' => array( 'ghost' ),
			'malformed'    => array( 'Completed' ),
			'empty string' => array( '' ),
			'only prefix'  => array( 'wc-' ),
		);
	}

	#[DataProvider( 'unknown' )]
	public function test_unknown_statuses_are_rejected( string $status ): void {
		$this->assert_rejected( array( 'completed', $status ), CountedOrderStatusException::NOT_ALLOWED );
	}

	public function test_an_empty_list_is_rejected_before_audit_and_option(): void {
		$this->assert_rejected( array(), CountedOrderStatusException::EMPTY_LIST );
	}

	public function test_a_counted_status_that_is_no_longer_registered_is_kept_and_can_be_removed_but_not_added_again(): void {
		$this->options->options[ Settings::OPTION ] = array( Settings::COUNTED_ORDER_STATUSES => array( 'legacy-paid', 'completed' ) );

		$choices = $this->settings->choices();
		self::assertSame(
			array(
				'status'     => 'legacy-paid',
				'label'      => 'legacy-paid',
				'registered' => false,
				'selected'   => true,
			),
			end( $choices )
		);

		self::assertFalse( $this->settings->save( array( 'completed', 'legacy-paid' ) ), 'Kept: no change.' );
		self::assertTrue( $this->settings->save( array( 'completed' ) ), 'Removed.' );
		self::assertSame( array( 'completed' ), Settings::counted_order_statuses() );

		$this->assert_rejected( array( 'completed', 'legacy-paid' ), CountedOrderStatusException::NOT_ALLOWED );
	}

	public function test_a_registered_status_that_was_unregistered_can_be_chosen_again_once_registered(): void {
		$this->options->options[ Settings::OPTION ] = array( Settings::COUNTED_ORDER_STATUSES => array( 'completed' ) );
		$this->registered                           = array( 'wc-completed' => 'Completed' );
		$this->assert_rejected( array( 'completed', 'shipped' ), CountedOrderStatusException::NOT_ALLOWED );

		$this->registered = self::REGISTERED;

		self::assertTrue( $this->settings->save( array( 'completed', 'shipped' ) ) );
	}

	public function test_manipulated_denied_values_are_shown_as_the_effective_default_and_cleaned_on_save(): void {
		$this->options->options[ Settings::OPTION ] = array( Settings::COUNTED_ORDER_STATUSES => array( 'pending', 'trash' ) );

		$selected = array_column( array_filter( $this->settings->choices(), static fn( array $c ): bool => $c['selected'] ), 'status' );
		self::assertSame( array( 'processing', 'completed' ), $selected );

		self::assertTrue( $this->settings->save( array( 'completed', 'processing' ) ) );
		self::assertSame( array( 'completed', 'processing' ), Settings::counted_order_statuses() );
		self::assertSame( '{"setting":"counted_order_statuses","previous_value":["pending","trash"],"value":["completed","processing"]}', $this->db->rows( 'merchboard_audit_log' )[0]['context'] );
	}

	public function test_without_woocommerce_nothing_can_be_chosen_or_saved(): void {
		$this->registered = null;

		$this->assert_rejected( array( 'completed' ), CountedOrderStatusException::UNAVAILABLE );

		try {
			$this->settings->choices();
			self::fail( 'Expected CountedOrderStatusException.' );
		} catch ( CountedOrderStatusException $e ) {
			self::assertSame( CountedOrderStatusException::UNAVAILABLE, $e->reason() );
		}
	}

	public function test_the_default_source_is_woocommerce(): void {
		Functions\when( 'wc_get_order_statuses' )->justReturn( array( 'wc-completed' => 'Completed' ) );
		$settings = new CountedOrderStatusSettings(
			new AuditTrail( new AuditRepository( $this->db ) ),
			new Transaction( $this->db, new TransactionSupport( $this->db ) ),
			new CountedOrderStatuses()
		);

		self::assertSame(
			array( 'completed' => true, 'processing' => false ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array_column( $settings->choices(), 'registered', 'status' ),
			'The default "processing" counts but is not registered in this stub.'
		);
	}

	public function test_unsupported_registrations_are_reported_and_never_offered(): void {
		$this->registered = array(
			'wc-processing'                     => 'Processing',
			'wc-completed'                      => 'Completed',
			'wc-awaiting_shipment'              => 'Awaiting shipment',
			'wc-' . str_repeat( 'a', 17 )       => 'Seventeen',
			'wc-Bad Key'                        => 'Bad',
			'wc-Shipped'                        => 'Capital',
			'on-hold'                           => '',
			7                                   => 'Seven',
			'wc-'                               => 'Empty',
			'wc-wc-double'                      => 'Double prefix',
			'wc-' . str_repeat( 'b', 18 )       => 'Eighteen',
			'wc-' . str_repeat( 'c', 18 ) . 'X' => 'Long and capital',
		);

		self::assertSame(
			array(
				array( 'processing', 'Processing' ),
				array( 'completed', 'Completed' ),
				array( 'awaiting_shipment', 'Awaiting shipment' ),
				array( str_repeat( 'a', 17 ), 'Seventeen' ),
			),
			array_map( static fn( array $c ): array => array( $c['status'], $c['label'] ), $this->settings->choices() )
		);
		self::assertSame(
			array(
				array( 'wc-Bad Key', 'Bad', CountedOrderStatusSettings::UNSUPPORTED_FORMAT ),
				array( 'wc-Shipped', 'Capital', CountedOrderStatusSettings::UNSUPPORTED_FORMAT ),
				array( 'on-hold', 'on-hold', CountedOrderStatusSettings::UNSUPPORTED_NO_PREFIX ),
				array( '7', 'Seven', CountedOrderStatusSettings::UNSUPPORTED_NO_PREFIX ),
				array( 'wc-', 'Empty', CountedOrderStatusSettings::UNSUPPORTED_FORMAT ),
				array( 'wc-wc-double', 'Double prefix', CountedOrderStatusSettings::UNSUPPORTED_FORMAT ),
				array( 'wc-' . str_repeat( 'b', 18 ), 'Eighteen', CountedOrderStatusSettings::UNSUPPORTED_TOO_LONG ),
				array( 'wc-' . str_repeat( 'c', 18 ) . 'X', 'Long and capital', CountedOrderStatusSettings::UNSUPPORTED_FORMAT ),
			),
			array_map( static fn( array $u ): array => array( $u['status'], $u['label'], $u['reason'] ), $this->settings->unsupported() )
		);

		foreach ( array( 'Shipped', 'wc-Shipped', 'on-hold', '7', str_repeat( 'b', 18 ), 'wc-double' ) as $status ) {
			$this->assert_rejected( array( 'completed', $status ), CountedOrderStatusException::NOT_ALLOWED );
		}
	}

	public function test_underscore_statuses_are_saved_unchanged_and_audited(): void {
		$this->registered = array_merge( self::REGISTERED, array( 'wc-awaiting_shipment' => 'Awaiting shipment' ) );

		self::assertTrue( $this->settings->save( array( 'wc-awaiting_shipment', 'completed' ) ) );

		self::assertSame( array( 'awaiting_shipment', 'completed' ), Settings::counted_order_statuses() );
		self::assertSame( '{"setting":"counted_order_statuses","previous_value":["processing","completed"],"value":["awaiting_shipment","completed"]}', $this->db->rows( 'merchboard_audit_log' )[0]['context'] );
		self::assertTrue( ( new CountedOrderStatuses() )->counts( 'awaiting_shipment' ) );
		self::assertFalse( $this->settings->save( array( 'completed', 'awaiting_shipment' ) ), 'Same list: no change.' );
		$this->assert_rejected( array( 'awaiting-shipment' ), CountedOrderStatusException::NOT_ALLOWED );
	}

	public function test_a_stored_status_registered_only_without_prefix_is_kept_as_not_registered(): void {
		$this->options->options[ Settings::OPTION ] = array( Settings::COUNTED_ORDER_STATUSES => array( 'legacy_paid', 'completed' ) );
		$this->registered                           = array_merge( self::REGISTERED, array( 'legacy_paid' => 'Legacy paid' ) );

		$kept = array_values( array_filter( $this->settings->choices(), static fn( array $c ): bool => 'legacy_paid' === $c['status'] ) );

		self::assertCount( 1, $kept );
		self::assertFalse( $kept[0]['registered'] );
		self::assertSame( array( 'legacy_paid' ), array_column( $this->settings->unsupported(), 'status' ) );
		self::assertFalse( $this->settings->save( array( 'legacy_paid', 'completed' ) ), 'An existing valid value is kept.' );
	}

	public function test_the_service_requires_the_settings_capability(): void {
		$this->caps = array( Capabilities::MANAGE_CREATORS, 'manage_options' );

		$this->assert_rejected( array( 'completed' ), CountedOrderStatusException::NOT_AUTHORIZED );
	}

	public function test_a_failed_option_write_rolls_back_the_audit_record(): void {
		Functions\when( 'update_option' )->justReturn( false );

		try {
			$this->settings->save( array( 'completed' ) );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException $e ) {
			self::assertSame( 'Could not save settings.', $e->getMessage() );
		}

		self::assertSame( array(), $this->db->rows( 'merchboard_audit_log' ) );
		self::assertSame( array( 'processing', 'completed' ), Settings::counted_order_statuses() );
	}

	public function test_a_failed_audit_write_leaves_the_option_untouched(): void {
		$this->db->engines['merchboard_audit_log'] = 'MyISAM';

		try {
			$this->settings->save( array( 'completed' ) );
			self::fail( 'Expected DatabaseException.' );
		} catch ( DatabaseException ) {
			self::assertSame( array(), $this->options->updates );
		}
	}

	public function test_handler_saves_with_capability_and_nonce(): void {
		$url = $this->actions()->save( $this->form( array( 'wc-on-hold', 'completed' ) ) );

		self::assertSame( 'https://shop.test/wp-admin/admin.php?page=merchboard-settings&tab=sales&merchboard_sales_ledger_result=saved', $url );
		self::assertSame( array( 'completed', 'on-hold' ), Settings::counted_order_statuses() );
		self::assertSame( array( SalesLedgerSettingsActions::SAVE_ACTION ), $this->nonces );
	}

	public function test_handler_checks_the_capability_before_the_nonce(): void {
		$this->caps = array( 'read' );

		try {
			$this->actions()->save( array( '_wpnonce' => 'forged' ) );
			self::fail( 'Expected WpDie.' );
		} catch ( WpDie $e ) {
			self::assertSame( 403, $e->response );
			self::assertStringContainsString( 'not allowed', $e->getMessage() );
		}

		self::assertSame( array(), $this->nonces, 'The nonce is never looked at without the capability.' );
	}

	/**
	 * Nonces that must be rejected.
	 *
	 * @return array<string, array{string|null}>
	 */
	public static function bad_nonces(): array {
		return array(
			'missing'        => array( null ),
			'forged'         => array( 'forged' ),
			'settings form'  => array( 'valid:merchboard_save_settings' ),
			'dashboard slug' => array( 'valid:merchboard_save_dashboard_slug' ),
		);
	}

	#[DataProvider( 'bad_nonces' )]
	public function test_handler_rejects_missing_invalid_and_foreign_nonces( ?string $nonce ): void {
		$form = $this->form( array( 'completed' ) );
		unset( $form['_wpnonce'] );

		if ( null !== $nonce ) {
			$form['_wpnonce'] = $nonce;
		}

		try {
			$this->actions()->save( $form );
			self::fail( 'Expected WpDie.' );
		} catch ( WpDie $e ) {
			self::assertSame( 403, $e->response );
			self::assertStringContainsString( 'expired', $e->getMessage() );
		}

		self::assertSame( array(), $this->options->updates );
	}

	/**
	 * Submissions and their outcome codes.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function submissions(): array {
		return array(
			'no checkbox'        => array( array(), 'empty' ),
			'not an array'       => array( array( 'counted_order_statuses' => 'completed' ), 'empty' ),
			'nested values'      => array( array( 'counted_order_statuses' => array( array( 'completed' ) ) ), 'empty' ),
			'denied'             => array( array( 'counted_order_statuses' => array( 'completed', 'refunded' ) ), 'not_allowed' ),
			'markup is stripped' => array( array( 'counted_order_statuses' => array( '<b>on-hold</b>' ) ), 'saved' ),
			'unchanged'          => array( array( 'counted_order_statuses' => array( 'completed', 'processing' ) ), 'no_changes' ),
		);
	}

	/**
	 * Outcomes are reported with the section's own query argument.
	 *
	 * @param array<string, mixed> $fields  Submitted fields.
	 * @param string               $outcome Outcome code.
	 */
	#[DataProvider( 'submissions' )]
	public function test_handler_reports_outcomes_with_its_own_argument( array $fields, string $outcome ): void {
		$url = $this->actions()->save( array_merge( array( '_wpnonce' => 'valid:' . SalesLedgerSettingsActions::SAVE_ACTION ), $fields ) );

		self::assertStringEndsWith( '&merchboard_sales_ledger_result=' . $outcome, $url );
		self::assertStringNotContainsString( 'merchboard_notice', $url, 'The Settings module notices are not used.' );
	}

	public function test_handler_logs_technical_failures_and_reports_a_neutral_error(): void {
		$this->db->engines['merchboard_audit_log'] = 'MyISAM';

		$url = $this->actions()->save( $this->form( array( 'completed' ) ) );

		self::assertStringEndsWith( 'merchboard_sales_ledger_result=error', $url );
		self::assertSame( array( 'error' ), array_column( $this->logger->entries, 'level' ) );
		self::assertSame( array( 'processing', 'completed' ), Settings::counted_order_statuses() );
	}

	public function test_handler_registers_its_own_admin_post_action(): void {
		$this->actions()->register();

		self::assertNotFalse( has_action( 'admin_post_merchboard_save_counted_order_statuses' ) );
	}

	public function test_section_renders_its_own_form_and_marks_unregistered_statuses(): void {
		$this->options->options[ Settings::OPTION ] = array( Settings::COUNTED_ORDER_STATUSES => array( 'legacy-paid', 'completed' ) );
		$this->registered                           = array_merge( self::REGISTERED, array( 'wc-evil' => '<script>x</script>' ) );

		$html = $this->render_section();

		self::assertStringContainsString( '<h2 id="merchboard-sales-ledger">Sales ledger</h2>', $html );
		self::assertStringContainsString( 'name="action" value="merchboard_save_counted_order_statuses"', $html );
		self::assertStringContainsString( 'nonce:merchboard_save_counted_order_statuses', $html );
		self::assertStringContainsString( 'value="completed" checked', $html );
		self::assertStringContainsString( 'value="on-hold">', $html );
		self::assertStringContainsString( 'value="legacy-paid" checked', $html );
		self::assertMatchesRegularExpression( '/legacy-paid<\/code> <strong>\(not registered\)<\/strong>/', $html );
		self::assertSame( 1, substr_count( $html, 'not registered)' ), 'Only the unregistered status is marked.' );
		self::assertStringNotContainsString( 'value="pending"', $html );
		self::assertStringNotContainsString( 'value="refunded"', $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
		self::assertStringContainsString( 'only to future synchronizations', $html );
		self::assertStringContainsString( 'Nothing is recalculated now', $html );
		self::assertStringNotContainsString( 'Not supported', $html, 'No empty "Not supported" row.' );
	}

	public function test_section_lists_unsupported_statuses_without_a_checkbox(): void {
		$this->registered = array_merge(
			self::REGISTERED,
			array(
				'wc-awaiting_shipment'        => 'Awaiting shipment',
				'wc-Shipped'                  => 'Capital <b>',
				'dispatched'                  => 'No prefix',
				'wc-' . str_repeat( 'b', 18 ) => 'Too long',
			)
		);

		$html = $this->render_section();

		self::assertStringContainsString( 'value="awaiting_shipment">', $html, 'Underscore statuses can be chosen.' );
		self::assertStringContainsString( '<th scope="row">Not supported</th>', $html );
		self::assertStringContainsString( 'Capital &lt;b&gt; <code>wc-Shipped</code> – only lowercase letters, digits, &quot;_&quot; and &quot;-&quot; are supported', $html );
		self::assertStringContainsString( 'No prefix <code>dispatched</code> – the key does not start with &quot;wc-&quot;', $html );
		self::assertStringContainsString( 'Too long <code>wc-' . str_repeat( 'b', 18 ) . '</code> – longer than 17 characters without &quot;wc-&quot;', $html );
		self::assertStringNotContainsString( 'value="Shipped"', $html );
		self::assertStringNotContainsString( 'value="wc-Shipped"', $html );
		self::assertStringNotContainsString( 'value="dispatched"', $html );
		self::assertStringNotContainsString( 'value="' . str_repeat( 'b', 18 ) . '"', $html );
	}

	public function test_section_without_woocommerce_shows_a_notice_and_no_form(): void {
		$this->registered = null;

		$html = $this->render_section();

		self::assertStringContainsString( 'Sales ledger', $html );
		self::assertStringContainsString( 'WooCommerce is not available', $html );
		self::assertStringNotContainsString( '<form', $html );
	}

	public function test_section_is_empty_without_the_capability(): void {
		$this->caps = array( Capabilities::MANAGE_CREATORS );

		self::assertSame( '', $this->render_section() );
	}

	public function test_section_shows_only_whitelisted_outcomes(): void {
		$_GET[ SalesLedgerSettingsActions::RESULT_ARG ] = 'not_allowed';
		self::assertStringContainsString( 'notice-error inline', $this->render_section() );

		$_GET[ SalesLedgerSettingsActions::RESULT_ARG ] = 'saved';
		self::assertStringContainsString( 'apply from the next synchronization', $this->render_section() );

		$_GET[ SalesLedgerSettingsActions::RESULT_ARG ] = '<script>';
		self::assertStringNotContainsString( 'notice-', $this->render_section() );

		foreach ( array( 'saved', 'no_changes', 'not_authorized', 'unavailable', 'empty', 'not_allowed', 'error' ) as $code ) {
			self::assertNotNull( SalesLedgerSettingsSection::result_message( $code ), $code );
		}

		self::assertNull( SalesLedgerSettingsSection::result_message( 'unknown' ) );
	}

	public function test_section_hooks_into_the_settings_page(): void {
		( new SalesLedgerSettingsSection( $this->settings ) )->register();

		self::assertNotFalse( has_action( 'merchboard_settings_sections' ) );
	}

	/**
	 * A rejected save changes nothing.
	 *
	 * @param string[] $statuses Submitted statuses.
	 * @param string   $reason   Expected reason.
	 */
	private function assert_rejected( array $statuses, string $reason ): void {
		$before = $this->options->options;
		$audit  = count( $this->db->rows( 'merchboard_audit_log' ) );

		try {
			$this->settings->save( $statuses );
			self::fail( 'Expected CountedOrderStatusException.' );
		} catch ( CountedOrderStatusException $e ) {
			self::assertSame( $reason, $e->reason() );
		}

		self::assertSame( $before, $this->options->options );
		self::assertCount( $audit, $this->db->rows( 'merchboard_audit_log' ) );
	}

	private function actions(): SalesLedgerSettingsActions {
		return new SalesLedgerSettingsActions( $this->settings, $this->logger );
	}

	/**
	 * A valid submission.
	 *
	 * @param string[] $statuses Checked statuses.
	 * @return array<string, mixed>
	 */
	private function form( array $statuses ): array {
		return array(
			'_wpnonce'                                 => 'valid:' . SalesLedgerSettingsActions::SAVE_ACTION,
			SalesLedgerSettingsActions::FIELD_STATUSES => $statuses,
		);
	}

	private function render_section(): string {
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( string $action ): void {
				echo '<input type="hidden" name="_wpnonce" value="nonce:' . $action . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);
		Functions\when( 'checked' )->alias( static fn( mixed $a, mixed $b ): string => $a === $b ? ' checked' : '' );
		Functions\when( 'submit_button' )->alias(
			static function ( string $text ): void {
				echo '<button>' . $text . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);

		ob_start();

		try {
			( new SalesLedgerSettingsSection( $this->settings ) )->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}
}
