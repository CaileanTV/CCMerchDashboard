<?php
/**
 * A creator's proposals for their product category (phase 28, ADR-077).
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Creators;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorService;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Modules\Creators\Proposals\CategoryProfile;
use MerchBoard\Modules\Creators\Proposals\CategoryProposal;
use MerchBoard\Modules\Creators\Proposals\CategoryProposalException;
use MerchBoard\Modules\Creators\Proposals\CategoryProposalRepository;
use MerchBoard\Modules\Creators\Proposals\CategoryProposals;
use MerchBoard\Modules\Creators\Proposals\CategoryProposalsCleanup;
use MerchBoard\Modules\Creators\Proposals\ProposalField;
use MerchBoard\Modules\Creators\Proposals\ProposalFile;
use MerchBoard\Modules\Creators\Proposals\ProposalFiles;
use MerchBoard\Modules\Creators\Proposals\ProposalStatus;
use MerchBoard\Modules\WooCommerce\CreatorCategoryRepository;
use MerchBoard\Modules\WooCommerce\WooCommerceCategoryProfile;
use MerchBoard\Tests\Integration\Alerts\AlertImagesTest;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_Term;

/**
 * The creator proposes, the administrator decides: nothing reaches the
 * category or the creator's name before an approval; the banner plugin's
 * term meta and the category's own fields are written then, and images wait
 * outside the Media Library until they are approved.
 */
#[CoversClass( CategoryProposals::class )]
#[CoversClass( CategoryProposalRepository::class )]
#[CoversClass( CategoryProposal::class )]
#[CoversClass( CategoryProposalsCleanup::class )]
#[CoversClass( CategoryProfile::class )]
#[CoversClass( ProposalFiles::class )]
#[CoversClass( ProposalFile::class )]
#[CoversClass( WooCommerceCategoryProfile::class )]
final class CategoryProposalsTest extends IntegrationTestCase {

	private int $nova;

	private int $nova_user;

	private int $term;

	/**
	 * Category ids `edited_product_cat` fired for (the banner plugin's hook).
	 *
	 * @var list<int>
	 */
	private array $edited = array();

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Fixtures/ccmerch-kategoribannere.php';
		$this->install();
		register_taxonomy( 'product_cat', 'product' );

		$term = wp_insert_term( 'Nova Merch', 'product_cat', array( 'description' => '<p>Old <b>story</b></p>' ) );
		self::assertIsArray( $term );
		$this->term      = (int) $term['term_id'];
		$this->nova_user = $this->create_user( 'nova' );
		$this->nova      = $this->service( CreatorService::class )->create( 'Nova', CreatorStatus::Active, $this->nova_user )->id;
		( new CreatorCategoryRepository( $this->wpdb ) )->insert( $this->term, $this->nova, 1 );
		update_term_meta( $this->term, 'ccm_social_kick', 'https://kick.com/nova' );

		add_action( 'edited_product_cat', array( $this, 'record_edit' ), 30 );
		wp_set_current_user( $this->nova_user );
	}

	protected function tearDown(): void {
		remove_action( 'edited_product_cat', array( $this, 'record_edit' ), 30 );
		wp_set_current_user( 1 );

		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $attachment ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short query.
			wp_delete_attachment( (int) $attachment, true );
		}

		wp_delete_term( $this->term, 'product_cat' );
		unregister_taxonomy( 'product_cat' );
		ProposalFiles::remove_all();

		parent::tearDown();
	}

	/**
	 * Records the banner plugin's hook.
	 *
	 * @param int $term_id Term id.
	 */
	public function record_edit( int $term_id ): void {
		$this->edited[] = $term_id;
	}

	public function test_the_category_is_read_with_the_banner_plugins_values(): void {
		$category = $this->proposals()->category( $this->nova );

		self::assertNotNull( $category );
		self::assertSame( array( $this->term, 'Nova Merch', 'Old story', 0, '' ), array( $category->term_id, $category->name, $category->description, $category->image_id, $category->image_url ) );
		self::assertTrue( $category->banner_plugin );
		self::assertTrue( $category->default_banner(), 'Without an own banner the plugin\'s default banner shows.' );
		self::assertStringContainsString( 'Banner_nova-merch.png', $category->banner_url );
		self::assertSame( 'https://kick.com/nova', $category->socials['kick'] );
		self::assertSame( ProposalField::cases(), $this->proposals()->available_fields() );
	}

	public function test_proposals_wait_and_change_nothing_until_approved(): void {
		$proposals = $this->proposals()->propose(
			$this->creator(),
			array(
				'display_name'     => 'Nova Live',
				'description'      => "New story\n<script>x</script>line two",
				'social_instagram' => '@nova.live',
				'banner_link'      => 'twitch.tv/nova',
				'social_kick'      => 'https://kick.com/nova',
			)
		);

		self::assertSame( array( 'display_name', 'description', 'social_instagram', 'banner_link' ), array_map( static fn( CategoryProposal $p ): string => $p->field->value, $proposals ), 'An unchanged value is no proposal.' );
		self::assertSame( "New story\nline two", $proposals[1]->value );
		self::assertSame( 'https://www.instagram.com/nova.live', $proposals[2]->value );
		self::assertSame( 'https://twitch.tv/nova', $proposals[3]->value );
		self::assertSame( 'Nova', $this->creator()->display_name, 'The name waits too.' );
		self::assertSame( 'Nova Merch', $this->term_object()->name );
		self::assertSame( '', get_term_meta( $this->term, 'ccm_social_instagram', true ) );
		self::assertCount( 4, $this->audit( 'category_proposal_submitted' ) );

		// The same value again changes nothing; the current value withdraws.
		self::assertSame( array(), $this->proposals()->propose( $this->creator(), array( 'description' => "New story\nline two" ) ) );
		self::assertSame( array(), $this->proposals()->propose( $this->creator(), array( 'display_name' => 'Nova' ) ) );
		self::assertArrayNotHasKey( 'display_name', $this->proposals()->pending( $this->nova ) );
		self::assertCount( 1, $this->audit( 'category_proposal_withdrawn' ) );

		// A new value replaces the pending one.
		$this->proposals()->propose( $this->creator(), array( 'description' => 'Newer story' ) );
		$pending = $this->proposals()->pending( $this->nova );
		self::assertSame( array( 'social_instagram', 'banner_link', 'description' ), array_keys( $pending ), 'Oldest first.' );
		self::assertSame( 'Newer story', $pending['description']->value );
		self::assertSame( ProposalStatus::Replaced, ( new CategoryProposalRepository( $this->wpdb ) )->find( $proposals[1]->id )?->status );
	}

	public function test_invalid_fields_are_refused_together_and_nothing_is_stored(): void {
		try {
			$this->proposals()->propose(
				$this->creator(),
				array(
					'display_name'    => '   ',
					'description'     => str_repeat( 'a', CategoryProposals::DESCRIPTION_MAX_LENGTH + 1 ),
					'social_twitch'   => 'https://kick.com/nova',
					'banner_link'     => 'http://nova.example',
					'banner'          => 'https://evil.example/banner.png',
					'social_website'  => 'https://nova.example',
					'description_two' => 'x',
				)
			);
			self::fail( 'Not refused.' );
		} catch ( CategoryProposalException $e ) {
			self::assertSame(
				array(
					'display_name'    => CategoryProposalException::REQUIRED,
					'description'     => CategoryProposalException::TOO_LONG,
					'social_twitch'   => CategoryProposalException::INVALID_URL,
					'banner_link'     => CategoryProposalException::INVALID_URL,
					'banner'          => CategoryProposalException::INVALID,
					'social_website'  => CategoryProposalException::INVALID,
					'description_two' => CategoryProposalException::INVALID,
				),
				$e->errors()
			);
		}

		self::assertSame( 0, $this->count_rows( Tables::CATEGORY_PROPOSALS ) );
	}

	public function test_approval_writes_the_category_and_the_banner_plugins_meta(): void {
		$ids = $this->propose_all();

		wp_set_current_user( 1 );
		foreach ( $ids as $id ) {
			self::assertSame( ProposalStatus::Approved, $this->proposals()->approve( $id )->status );
		}

		$term = $this->term_object();
		self::assertSame( array( 'Nova Live', 'nova-merch', "New story\nline two" ), array( $term->name, $term->slug, $term->description ), 'The slug never changes.' );
		self::assertSame( 'Nova Live', $this->creator()->display_name );
		self::assertSame( 'https://www.instagram.com/nova.live', get_term_meta( $this->term, 'ccm_social_instagram', true ) );
		self::assertSame( array( 'https://twitch.tv/nova', '1' ), array( get_term_meta( $this->term, 'ccm_banner_link', true ), get_term_meta( $this->term, 'ccm_banner_new_tab', true ) ) );
		self::assertSame( '', get_term_meta( $this->term, 'ccm_social_kick', true ), 'An empty value removes the link.' );
		self::assertContains( $this->term, $this->edited, 'The banner plugin\'s hook saw the changes.' );
		self::assertSame( array(), $this->proposals()->pending( $this->nova ) );

		$approved = $this->audit( 'category_proposal_approved' );
		self::assertCount( 5, $approved );
		self::assertSame( $this->term, $approved[0]['term_id'] );
		self::assertSame( array( array( 'display_name' ) ), array_column( $this->audit( 'creator_updated' ), 'changed_fields' ) );
	}

	public function test_a_rejection_writes_nothing_and_the_creator_sees_the_note(): void {
		$ids = $this->propose_all();

		wp_set_current_user( 1 );
		$this->proposals()->reject( $ids[1], '  Please no links in the <b>text</b>  ' );

		self::assertSame( 'Nova Merch', $this->term_object()->name );
		self::assertSame( 'Old story', $this->proposals()->category( $this->nova )?->description );
		$rejected = $this->proposals()->rejected( $this->nova );
		self::assertSame( array( 'description' ), array_keys( $rejected ) );
		self::assertSame( 'Please no links in the text', $rejected['description']->reason );
		self::assertSame( 'Please no links in the text', $this->audit( 'category_proposal_rejected' )[0]['decision_note'] );

		// A decided proposal cannot be decided again; a new proposal hides the old rejection.
		self::assertSame( CategoryProposalException::NOT_PENDING, $this->refusal( fn() => $this->proposals()->approve( $ids[1] ) ) );
		wp_set_current_user( $this->nova_user );
		$this->proposals()->propose( $this->creator(), array( 'description' => 'Another story' ) );
		self::assertSame( array(), $this->proposals()->rejected( $this->nova ) );
	}

	public function test_an_image_waits_outside_the_media_library_until_approved(): void {
		$proposal = $this->proposals()->propose_image( $this->creator(), ProposalField::Banner, AlertImagesTest::upload( AlertImagesTest::png( 1400, 300 ), 'banner.png' ) );
		$file     = $proposal->file();

		self::assertNotNull( $file );
		self::assertMatchesRegularExpression( ProposalFile::NAME_PATTERN, $file->file );
		self::assertSame( array( 'image/png', 1400, 300 ), array( $file->mime, $file->width, $file->height ) );
		self::assertFileExists( ProposalFiles::directory() . '/' . $file->file );
		self::assertFileExists( ProposalFiles::directory() . '/.htaccess' );
		self::assertFileExists( ProposalFiles::directory() . '/index.php' );
		self::assertSame( array(), get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids' ) ), 'Not in the Media Library.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short query.

		wp_set_current_user( 1 );
		$this->proposals()->approve( $proposal->id );

		$banner = (int) get_term_meta( $this->term, 'ccm_banner_id', true );
		self::assertGreaterThan( 0, $banner );
		self::assertSame( (string) $this->nova, get_post_meta( $banner, ProposalFiles::MEDIA_META, true ) );
		self::assertFileDoesNotExist( ProposalFiles::directory() . '/' . $file->file, 'The waiting file is gone.' );
		self::assertFalse( $this->proposals()->category( $this->nova )?->default_banner() );

		// A new banner replaces the plugin's own image, which is deleted; the creator can remove it again.
		wp_set_current_user( $this->nova_user );
		$next = $this->proposals()->propose_image( $this->creator(), ProposalField::Banner, AlertImagesTest::upload( AlertImagesTest::png( 1300, 280 ), 'banner2.png' ) );
		wp_set_current_user( 1 );
		$this->proposals()->approve( $next->id );
		self::assertNull( get_post( $banner ), 'The replaced image the plugin added is deleted.' );

		wp_set_current_user( $this->nova_user );
		$removal = $this->proposals()->propose( $this->creator(), array( 'banner' => '' ) );
		wp_set_current_user( 1 );
		$this->proposals()->approve( $removal[0]->id );
		self::assertSame( '', get_term_meta( $this->term, 'ccm_banner_id', true ) );
		self::assertTrue( $this->proposals()->category( $this->nova )?->default_banner(), 'The default banner shows again.' );
	}

	public function test_an_image_of_the_wrong_size_or_type_is_refused_and_deleted(): void {
		$small = AlertImagesTest::upload( AlertImagesTest::png( 800, 200 ), 'small.png' );
		self::assertSame( CategoryProposalException::TOO_SMALL, $this->refusal( fn() => $this->proposals()->propose_image( $this->creator(), ProposalField::Banner, $small ) ) );
		self::assertSame( CategoryProposalException::INVALID_TYPE, $this->refusal( fn() => $this->proposals()->propose_image( $this->creator(), ProposalField::Image, AlertImagesTest::upload( 'GIF89a', 'logo.gif' ) ) ) );
		self::assertSame( CategoryProposalException::INVALID, $this->refusal( fn() => $this->proposals()->propose_image( $this->creator(), ProposalField::Description, $small ) ) );
		self::assertSame( CategoryProposalException::NOT_SQUARE, $this->refusal( fn() => $this->proposals()->propose_image( $this->creator(), ProposalField::Image, AlertImagesTest::upload( AlertImagesTest::png( 500, 400 ), 'logo.png' ) ) ), 'The logo is square.' );

		self::assertSame( array( '.htaccess', 'index.php' ), array_values( array_diff( (array) scandir( ProposalFiles::directory() ), array( '.', '..' ) ) ), 'No refused file stays.' );
		self::assertSame( 0, $this->count_rows( Tables::CATEGORY_PROPOSALS ) );
	}

	public function test_a_logo_larger_than_the_maximum_is_scaled_down(): void {
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/png' ) ) ) {
			self::markTestSkipped( 'No image editor (GD or Imagick) for PNG.' );
		}

		$file = $this->proposals()->propose_image( $this->creator(), ProposalField::Image, AlertImagesTest::upload( AlertImagesTest::png( 1200, 1200 ), 'logo.png' ) )->file();

		self::assertNotNull( $file );
		self::assertSame( array( ProposalFiles::LOGO_MAX_PIXELS, ProposalFiles::LOGO_MAX_PIXELS ), array( $file->width, $file->height ) );
		$size = wp_getimagesize( ProposalFiles::directory() . '/' . $file->file );
		self::assertIsArray( $size );
		self::assertSame( array( ProposalFiles::LOGO_MAX_PIXELS, ProposalFiles::LOGO_MAX_PIXELS ), array( $size[0], $size[1] ) );
	}

	public function test_only_the_creator_proposes_and_only_an_administrator_decides(): void {
		$other = $this->create_user( 'orion' );
		$ids   = $this->propose_all();

		wp_set_current_user( $other );
		self::assertSame( CategoryProposalException::NOT_AUTHORIZED, $this->refusal( fn() => $this->proposals()->propose( $this->creator(), array( 'description' => 'Hijack' ) ) ) );

		wp_set_current_user( $this->nova_user );
		self::assertSame( CategoryProposalException::NOT_AUTHORIZED, $this->refusal( fn() => $this->proposals()->approve( $ids[0] ) ) );
		self::assertSame( CategoryProposalException::NOT_AUTHORIZED, $this->refusal( fn() => $this->proposals()->reject( $ids[0], '' ) ) );

		wp_set_current_user( 1 );
		$this->service( CreatorService::class )->set_status( $this->nova, CreatorStatus::Inactive );
		wp_set_current_user( $this->nova_user );
		self::assertSame( CategoryProposalException::NOT_AUTHORIZED, $this->refusal( fn() => $this->proposals()->propose( $this->creator(), array( 'description' => 'x' ) ) ) );
		self::assertSame( 'Nova Merch', $this->term_object()->name );
	}

	public function test_without_a_category_only_the_name_can_be_approved(): void {
		$ids = $this->propose_all();
		( new CreatorCategoryRepository( $this->wpdb ) )->delete( $this->term, $this->nova );

		wp_set_current_user( 1 );
		self::assertSame( CategoryProposalException::NO_CATEGORY, $this->refusal( fn() => $this->proposals()->approve( $ids[1] ) ) );
		$this->proposals()->approve( $ids[0] );

		self::assertSame( 'Nova Live', $this->creator()->display_name );
		self::assertSame( 'Nova Merch', $this->term_object()->name, 'The former category is not touched.' );
		self::assertSame( 0, $this->audit( 'category_proposal_approved' )[0]['term_id'] );
	}

	public function test_deleting_the_creator_removes_proposals_and_waiting_files(): void {
		$this->propose_all();
		$file = $this->proposals()->propose_image( $this->creator(), ProposalField::Image, AlertImagesTest::upload( AlertImagesTest::png( 400, 400 ), 'logo.png' ) )->file();
		self::assertNotNull( $file );

		$removed = ( new CategoryProposalsCleanup( new CategoryProposalRepository( $this->wpdb ), new ProposalFiles() ) )->remove( $this->creator() );

		self::assertSame(
			array(
				'rows'  => 6,
				'files' => 1,
			),
			$removed
		);
		self::assertFileDoesNotExist( ProposalFiles::directory() . '/' . $file->file );
		self::assertSame( 0, $this->count_rows( Tables::CATEGORY_PROPOSALS ) );
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
	 * Nova's proposals: name, description, Instagram, banner link and the
	 * removal of the Kick link.
	 *
	 * @return list<int>
	 */
	private function propose_all(): array {
		return array_map(
			static fn( CategoryProposal $p ): int => $p->id,
			$this->proposals()->propose(
				$this->creator(),
				array(
					'display_name'     => 'Nova Live',
					'description'      => "New story\nline two",
					'social_instagram' => '@nova.live',
					'banner_link'      => 'twitch.tv/nova',
					'social_kick'      => '',
				)
			)
		);
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

	/**
	 * Why an action was refused.
	 *
	 * @param callable $action Action.
	 */
	private function refusal( callable $action ): string {
		try {
			$action();
		} catch ( CategoryProposalException $e ) {
			return $e->reason();
		}

		self::fail( 'Not refused.' );
	}

	/**
	 * The contexts of an audit action, oldest first.
	 *
	 * @param string $action Action.
	 * @return list<array<string, mixed>>
	 */
	private function audit( string $action ): array {
		$contexts = array();

		foreach ( $this->rows( Tables::AUDIT_LOG ) as $row ) {
			if ( $action === $row['action'] ) {
				$context    = json_decode( (string) $row['context'], true );
				$contexts[] = is_array( $context ) ? $context : array();
			}
		}

		return $contexts;
	}
}
