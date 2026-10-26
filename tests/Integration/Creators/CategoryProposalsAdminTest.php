<?php
/**
 * The administrator's "Category proposals" page (phase 28, step 28.4, ADR-077).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Creators;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Creators\Admin\CategoryProposalActions;
use MerchBoard\Modules\Creators\Admin\CategoryProposalsPage;
use MerchBoard\Modules\Creators\Admin\ProposalImageDownload;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Creators\Proposals\CategoryProposal;
use MerchBoard\Modules\Creators\Proposals\CategoryProposalRepository;
use MerchBoard\Modules\Creators\Proposals\CategoryProposals;
use MerchBoard\Modules\Creators\Proposals\ProposalField;
use MerchBoard\Modules\Creators\Proposals\ProposalFiles;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\WooCommerceCategoryProfile;
use MerchBoard\Tests\Integration\Alerts\AlertImagesTest;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Integration\WooCommerce\CreatorPricingAdminTest;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use WP_Term;

/**
 * The page lists every creator's pending proposals with the value now and
 * the proposed one; the forms approve, reject with a note, or approve all,
 * each behind the capability and a nonce bound to the proposal or creator;
 * proposed images are shown only to administrators.
 */
#[CoversClass( CategoryProposalsPage::class )]
#[CoversClass( CategoryProposalActions::class )]
#[CoversClass( ProposalImageDownload::class )]
final class CategoryProposalsAdminTest extends IntegrationTestCase {

	private int $nova;

	private int $nova_user;

	private int $term;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Fixtures/ccmerch-kategoribannere.php';
		$this->install();
		register_taxonomy( 'product_cat', 'product' );
		add_filter( 'wp_die_handler', array( CreatorPricingAdminTest::class, 'die_handler' ) );
		add_filter( 'merchboard_category_profile_target', fn(): WooCommerceCategoryProfile => new WooCommerceCategoryProfile( new CreatorCategoryRepository( $this->wpdb ) ) );

		$term = wp_insert_term( 'Nova Merch', 'product_cat', array( 'description' => 'Old story' ) );
		self::assertIsArray( $term );
		$this->term      = (int) $term['term_id'];
		$this->nova_user = $this->create_user( 'nova' );
		$this->nova      = $this->service( CreatorService::class )->create( 'Nova <b>Live</b>', CreatorStatus::Active, $this->nova_user )->id;
		( new CreatorCategoryRepository( $this->wpdb ) )->insert( $this->term, $this->nova, 1 );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_die_handler', array( CreatorPricingAdminTest::class, 'die_handler' ) );
		remove_all_filters( 'merchboard_category_profile_target' );
		$_GET = array();

		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $attachment ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short query.
			wp_delete_attachment( (int) $attachment, true );
		}

		wp_delete_term( $this->term, 'product_cat' );
		unregister_taxonomy( 'product_cat' );
		ProposalFiles::remove_all();
		parent::tearDown();
	}

	public function test_the_page_shows_now_and_proposed_side_by_side(): void {
		$ids = $this->propose();

		$html = $this->render_page();

		self::assertStringContainsString( 'Nova &lt;b&gt;Live&lt;/b&gt;', $html, 'Names are escaped.' );
		self::assertStringContainsString( 'Old story', $html );
		self::assertStringContainsString( '<td>New story</td>', $html, 'The description is plain text.' );
		self::assertStringContainsString( 'href="https://www.instagram.com/nova"', $html );
		self::assertStringContainsString( 'admin-post.php?action=merchboard_category_proposal_image&#038;proposal=' . $ids['banner'], $html );
		self::assertStringContainsString( 'Standard banner', $html );
		self::assertStringContainsString( 'name="action" value="' . CategoryProposalActions::APPROVE_ALL . '"', $html );
		self::assertSame( 4, substr_count( $html, 'value="' . CategoryProposalActions::REJECT . '"' ) );
		self::assertSame( 4, ( new CategoryProposalRepository( $this->wpdb ) )->pending_count() );

		// The creator's edit page links to the proposals.
		ob_start();
		$this->page()->render_creator_section( $this->creator() );
		self::assertStringContainsString( '4 proposals from the creator are waiting for approval.', (string) ob_get_clean() );
	}

	public function test_approve_and_reject_with_a_note(): void {
		$ids     = $this->propose();
		$actions = $this->actions();

		self::assertStringContainsString( CategoryProposalsPage::RESULT_ARG . '=approved', $actions->process( $this->signed( CategoryProposalActions::APPROVE, $ids['description'] ) ) );
		self::assertSame( 'New story', $this->term_object()->description );

		self::assertStringContainsString( CategoryProposalsPage::RESULT_ARG . '=rejected', $actions->process( $this->signed( CategoryProposalActions::REJECT, $ids['social_instagram'] ) + array( CategoryProposalActions::NOTE_FIELD => 'Not your <b>account</b>' ) ) );
		self::assertSame( '', get_term_meta( $this->term, 'ccm_social_instagram', true ) );
		self::assertSame( 'Not your account', $this->proposals()->rejected( $this->nova )['social_instagram']->reason );

		// The same proposal again: no longer pending.
		self::assertStringContainsString( CategoryProposalsPage::RESULT_ARG . '=proposal_not_pending', $actions->process( $this->signed( CategoryProposalActions::APPROVE, $ids['description'] ) ) );
		self::assertSame( array( 'The proposal is no longer waiting, e.g. because the creator withdrew or replaced it.', 'warning' ), CategoryProposalsPage::result_message( 'proposal_not_pending' ) );
	}

	public function test_approve_all_writes_every_proposal_of_the_creator(): void {
		$ids = $this->propose();

		self::assertStringContainsString( 'name="' . CategoryProposalActions::SHOWN_FIELD . '" value="' . implode( ',', $ids ) . '"', $this->render_page(), 'The form carries the proposals it shows.' );
		self::assertStringContainsString( CategoryProposalsPage::RESULT_ARG . '=approved_all', $this->actions()->process( $this->signed( CategoryProposalActions::APPROVE_ALL, $this->nova ) + array( CategoryProposalActions::SHOWN_FIELD => implode( ',', $ids ) ) ) );

		self::assertSame( array(), $this->proposals()->pending( $this->nova ) );
		self::assertSame( 'Nova Live', $this->creator()->display_name );
		self::assertSame( array( 'Nova Live', 'New story' ), array( $this->term_object()->name, $this->term_object()->description ) );
		self::assertGreaterThan( 0, (int) get_term_meta( $this->term, 'ccm_banner_id', true ) );
		self::assertStringContainsString( 'No proposals are waiting.', $this->render_page() );
	}

	public function test_approve_all_never_approves_a_proposal_the_page_did_not_show(): void {
		$ids = $this->propose();

		// After the page was rendered, the creator replaces the description.
		wp_set_current_user( $this->nova_user );
		$this->proposals()->propose( $this->creator(), array( 'description' => 'Swapped \\o/ story' ) );
		wp_set_current_user( 1 );

		self::assertStringContainsString( CategoryProposalsPage::RESULT_ARG . '=approved_some', $this->actions()->process( $this->signed( CategoryProposalActions::APPROVE_ALL, $this->nova ) + array( CategoryProposalActions::SHOWN_FIELD => implode( ',', $ids ) ) ) );
		self::assertSame( array( 'description' ), array_keys( $this->proposals()->pending( $this->nova ) ), 'The new description still waits.' );
		self::assertSame( 'Old story', $this->term_object()->description );

		// Without the list (an old form), nothing is approved.
		self::assertStringContainsString( CategoryProposalsPage::RESULT_ARG . '=proposal_not_pending', $this->actions()->process( $this->signed( CategoryProposalActions::APPROVE_ALL, $this->nova ) ) );

		$pending = $this->proposals()->pending( $this->nova );
		$this->actions()->process( $this->signed( CategoryProposalActions::APPROVE, ( $pending['description'] ?? self::fail( 'No description.' ) )->id ) );
		self::assertSame( 'Swapped \\o/ story', $this->term_object()->description, 'A backslash survives wp_update_term().' );
	}

	public function test_the_proposed_image_is_shown_only_to_an_administrator_with_a_valid_link(): void {
		$ids      = $this->propose();
		$download = new ProposalImageDownload( $this->proposals(), new ProposalFiles() );
		$query    = array(
			'proposal' => (string) $ids['banner'],
			'_wpnonce' => wp_create_nonce( ProposalImageDownload::nonce_action( $ids['banner'] ) ),
		);

		$response = $download->response( $query );
		self::assertSame( 200, $response['status'] );
		self::assertSame( array( 'image/png', 'nosniff', 'private, no-store' ), array( $response['headers']['Content-Type'], $response['headers']['X-Content-Type-Options'], $response['headers']['Cache-Control'] ) );
		self::assertStringStartsWith( "\x89PNG", $response['body'] );

		self::assertSame( 403, $download->response( array( 'proposal' => (string) $ids['banner'] ) + array( '_wpnonce' => 'x' ) )['status'] );
		self::assertSame( 404, $download->response( array( 'proposal' => (string) $ids['description'], '_wpnonce' => wp_create_nonce( ProposalImageDownload::nonce_action( $ids['description'] ) ) ) )['status'], 'A text proposal has no image.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		wp_set_current_user( $this->nova_user );
		self::assertSame( 403, $download->response( $query )['status'], 'Never to the creator through admin-post.' );
	}

	public function test_without_the_capability_or_a_valid_nonce_nothing_is_decided(): void {
		$ids = $this->propose();

		wp_set_current_user( $this->nova_user );
		$this->assert_dies( 'not allowed to manage creators', $this->signed( CategoryProposalActions::APPROVE, $ids['description'] ) );

		wp_set_current_user( 1 );
		$this->assert_dies( 'link you followed has expired', array( 'action' => CategoryProposalActions::APPROVE, 'proposal_id' => (string) $ids['description'], '_wpnonce' => wp_create_nonce( CategoryProposalActions::nonce_action( CategoryProposalActions::APPROVE, $ids['banner'] ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assert_dies( 'link you followed has expired', array_merge( $this->signed( CategoryProposalActions::REJECT, $ids['description'] ), array( 'action' => CategoryProposalActions::APPROVE ) ) );

		self::assertCount( 4, $this->proposals()->pending( $this->nova ) );
		self::assertSame( 'Old story', $this->term_object()->description );
	}

	/**
	 * Nova's proposals: name, description, Instagram and a banner.
	 *
	 * @return array<string, int> Proposal ids by field.
	 */
	private function propose(): array {
		wp_set_current_user( $this->nova_user );
		$ids = array();

		foreach ( $this->proposals()->propose(
			$this->creator(),
			array(
				'display_name'     => 'Nova Live',
				'description'      => 'New <i>story</i>',
				'social_instagram' => '@nova',
			)
		) as $proposal ) {
			$ids[ $proposal->field->value ] = $proposal->id;
		}

		$ids['banner'] = $this->proposals()->propose_image( $this->creator(), ProposalField::Banner, AlertImagesTest::upload( AlertImagesTest::png( 1400, 300 ), 'banner.png' ) )->id;
		wp_set_current_user( 1 );

		return $ids;
	}

	/**
	 * The service with the WooCommerce category and a test upload move.
	 */
	private function proposals(): CategoryProposals {
		return new CategoryProposals(
			new CategoryProposalRepository( $this->wpdb ),
			// Uploads in tests are not HTTP uploads: is_uploaded_file() is false.
			new ProposalFiles( static fn( string $from, string $to ): bool => rename( $from, $to ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Test upload move.
			$this->service( CreatorRepository::class ),
			$this->service( AuditTrail::class ),
			$this->service( Transaction::class ),
			$this->wpdb,
			fn(): WooCommerceCategoryProfile => new WooCommerceCategoryProfile( new CreatorCategoryRepository( $this->wpdb ) ),
			static fn(): DateTimeImmutable => new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * The page as the module builds it.
	 */
	private function page(): CategoryProposalsPage {
		return new CategoryProposalsPage( $this->proposals(), new CategoryProposalRepository( $this->wpdb ), $this->service( CreatorRepository::class ), $this->service( Logger::class ) );
	}

	/**
	 * The actions as the module builds them.
	 */
	private function actions(): CategoryProposalActions {
		return new CategoryProposalActions( $this->proposals(), $this->service( Logger::class ) );
	}

	/**
	 * The page's HTML.
	 */
	private function render_page(): string {
		ob_start();
		$this->page()->render();

		return (string) ob_get_clean();
	}

	/**
	 * A form submission with its nonce.
	 *
	 * @param string $action Action.
	 * @param int    $id     Proposal id, or the creator id for APPROVE_ALL.
	 * @return array<string, string>
	 */
	private function signed( string $action, int $id ): array {
		return array(
			'action'   => $action,
			CategoryProposalActions::APPROVE_ALL === $action ? 'creator_id' : 'proposal_id' => (string) $id,
			'_wpnonce' => wp_create_nonce( CategoryProposalActions::nonce_action( $action, $id ) ),
		);
	}

	/**
	 * Asserts that processing dies with a message.
	 *
	 * @param string               $message Part of the message.
	 * @param array<string, mixed> $input   Input.
	 */
	private function assert_dies( string $message, array $input ): void {
		try {
			$this->actions()->process( $input );
			self::fail( 'Did not die.' );
		} catch ( RuntimeException $e ) {
			self::assertStringContainsString( $message, $e->getMessage() );
		}
	}

	/**
	 * Nova now.
	 */
	private function creator(): \MerchBoard\Modules\Creators\Creator {
		$creator = $this->service( CreatorRepository::class )->find( $this->nova );
		self::assertNotNull( $creator );

		return $creator;
	}

	/**
	 * The category now.
	 */
	private function term_object(): WP_Term {
		clean_term_cache( $this->term, 'product_cat' );
		$term = get_term( $this->term, 'product_cat' );
		self::assertInstanceOf( WP_Term::class, $term );

		return $term;
	}
}
