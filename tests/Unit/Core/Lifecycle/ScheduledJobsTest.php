<?php
/**
 * Tests for the cancellation of backfill jobs on deactivation and uninstall.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Unit\Core\Lifecycle;

use Brain\Monkey\Functions;
use MerchBoard\Core\Database\SchemaEraser;
use MerchBoard\Core\Lifecycle\Deactivator;
use MerchBoard\Core\Lifecycle\ScheduledJobs;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerApply;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerRepricing;
use MerchBoard\Modules\ProductDesigner\Designs\DesignerRetirement;
use MerchBoard\Modules\ProductDesigner\Providers\GelatoMockups;
use MerchBoard\Modules\ProductDesigner\Providers\PrintfulMockups;
use MerchBoard\Core\Lifecycle\Uninstaller;
use MerchBoard\Modules\WooCommerce\Sales\SalesBackfillScheduler;
use MerchBoard\Tests\Unit\Support\InMemoryOptions;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use MerchBoard\Tests\Unit\Support\RecordingWpdb;
use MerchBoard\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass( ScheduledJobs::class )]
#[CoversClass( Deactivator::class )]
#[CoversClass( Uninstaller::class )]
final class ScheduledJobsTest extends TestCase {

	public function test_core_names_the_same_hook_as_the_module(): void {
		self::assertSame( SalesBackfillScheduler::HOOK, ScheduledJobs::SALES_BACKFILL_HOOK );
		self::assertSame( array( DesignerApply::HOOK, DesignerRepricing::HOOK, DesignerRetirement::HOOK, PrintfulMockups::HOOK, GelatoMockups::HOOK ), ScheduledJobs::DESIGNER_HOOKS );
	}

	public function test_only_the_backfill_hook_is_cancelled(): void {
		Functions\expect( 'as_unschedule_all_actions' )->once()->with( 'merchboard_sales_backfill' );

		ScheduledJobs::cancel_sales_backfill();
	}

	public function test_a_failing_cancel_never_throws(): void {
		Functions\expect( 'as_unschedule_all_actions' )->once()->andThrow( new RuntimeException( 'Store down.' ) );

		ScheduledJobs::cancel_sales_backfill();

		$this->addToAssertionCount( 1 );
	}

	public function test_deactivation_cancels_the_backfill_jobs_and_keeps_data(): void {
		Functions\expect( 'delete_option' )->once()->with( 'rewrite_rules' );
		Functions\expect( 'as_unschedule_all_actions' )->once()->with( 'merchboard_sales_backfill' );

		( new Deactivator() )->deactivate();
	}

	public function test_uninstall_cancels_the_backfill_jobs_also_without_the_opt_in(): void {
		InMemoryOptions::install();
		Functions\expect( 'as_unschedule_all_actions' )->once()->with( 'merchboard_sales_backfill' );
		Functions\expect( 'delete_post_meta_by_key' )->never();

		$logger = new RecordingLogger();
		$wpdb   = new RecordingWpdb();

		self::assertFalse( ( new Uninstaller( $wpdb, new SchemaEraser( $wpdb, $logger ), $logger ) )->run() );
	}
}
