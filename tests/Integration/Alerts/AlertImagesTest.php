<?php
/**
 * Integration tests for alert text fill images.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Alerts;

use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Modules\Alerts\Admin\AlertsAdminActions;
use MerchBoard\Modules\Alerts\Admin\AlertsAdminPage;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Images\AlertImage;
use MerchBoard\Modules\Alerts\Images\AlertImageException;
use MerchBoard\Modules\Alerts\Images\AlertImageLibrary;
use MerchBoard\Modules\Alerts\Images\AlertImages;
use MerchBoard\Modules\Alerts\Images\ImageFiles;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlay;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\OverlayId;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Phase 25 step 6.10: a creator's text fill images are real images within
 * the limits, only ever the creator's own files or library images, and a
 * deleted image leaves no fill behind.
 */
#[CoversClass( AlertImages::class )]
#[CoversClass( AlertImage::class )]
#[CoversClass( AlertImageException::class )]
#[CoversClass( AlertImageLibrary::class )]
#[CoversClass( ImageFiles::class )]
final class AlertImagesTest extends IntegrationTestCase {

	private AlertOverlayRepository $overlays;

	private int $creator;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$this->overlays = new AlertOverlayRepository( $this->wpdb );
		$this->creator  = ( new CreatorRepository( $this->wpdb ) )->create( 'Nova', CreatorStatus::Active )->id;
	}

	protected function tearDown(): void {
		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $attachment ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short query.
			wp_delete_attachment( (int) $attachment, true );
		}

		parent::tearDown();
	}

	/**
	 * Image files with a test upload move.
	 */
	public static function image_files(): ImageFiles {
		// Uploads in tests are not HTTP uploads: is_uploaded_file() is false.
		return new ImageFiles(
			static function ( array $file, array $overrides ): array {
				require_once ABSPATH . 'wp-admin/includes/file.php';

				return wp_handle_sideload( $file, $overrides );
			}
		);
	}

	/**
	 * The library as the module builds it.
	 */
	private function library(): AlertImageLibrary {
		return new AlertImageLibrary( self::image_files(), $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
	}

	/**
	 * The service as the module builds it.
	 *
	 * @param AlertImageLibrary|null $library Library.
	 */
	private function images( ?AlertImageLibrary $library = null ): AlertImages {
		return new AlertImages( $this->overlays, self::image_files(), $library );
	}

	/**
	 * The bytes of a PNG of the given size (header and an empty image; enough
	 * for the type and size checks).
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 */
	public static function png( int $width, int $height ): string {
		$chunk = static fn( string $type, string $data ): string => pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );

		return "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $width, $height, 8, 2, 0, 0, 0 ) ) . $chunk( 'IDAT', (string) gzcompress( str_repeat( "\0" . str_repeat( "\0\0\0", $width ), $height ) ) ) . $chunk( 'IEND', '' );
	}

	/**
	 * An upload of some content.
	 *
	 * @phpstan-param non-empty-string $name
	 *
	 * @param string $content Bytes.
	 * @param string $name    File name.
	 * @return array{name: non-empty-string, type: non-empty-string, tmp_name: non-empty-string, error: int<0, 8>, size: int<0, max>}
	 */
	public static function upload( string $content, string $name = 'stars.png' ): array {
		return AlertSoundsTest::upload( $content, $name );
	}

	public function test_an_own_image_is_stored_described_and_shown(): void {
		$images = $this->images();
		$image  = $images->upload( $this->creator, self::upload( self::png( 8, 4 ) ) );

		self::assertSame( 8, $image->width );
		self::assertSame( 4, $image->height );
		self::assertSame( 'stars.png', $image->name );
		self::assertSame( (string) $this->creator, get_post_meta( $image->attachment_id, AlertImages::OWNER_META, true ) );

		$description = $images->describe( $this->creator );
		self::assertSame( array( $image->to_array() ), $description['own'] );
		self::assertSame( ImageFiles::MAX_PIXELS, $description['max_pixels'] );
		self::assertSame( AlertImages::MAX_OWN, $description['max_own'] );

		$fill = array(
			'type'   => 'image',
			'preset' => 'rainbow',
			'image'  => $image->attachment_id,
			'motion' => 'pan',
		);
		self::assertSame(
			array(
				'kind'   => 'image',
				'url'    => $image->url,
				'width'  => 8,
				'height' => 4,
				'motion' => 'pan',
			),
			AlertImages::look( $images, $this->creator, $fill )
		);
		self::assertNull( AlertImages::look( null, $this->creator, $fill ), 'Without images, only presets are shown.' );
		self::assertSame(
			array(
				'kind'   => 'preset',
				'preset' => 'gold',
				'motion' => 'none',
			),
			AlertImages::look(
				null,
				$this->creator,
				array(
					'type'   => 'preset',
					'preset' => 'gold',
					'image'  => 0,
					'motion' => 'none',
				)
			)
		);
	}

	/**
	 * Refused uploads.
	 *
	 * @return array<string, array{0: string, 1: non-empty-string, 2: string}>
	 */
	public static function refused(): array {
		return array(
			'php file'       => array( '<?php echo 1;', 'x.php', AlertImageException::INVALID_TYPE ),
			'text named png' => array( 'not an image', 'x.png', AlertImageException::INVALID_TYPE ),
			'too wide'       => array( self::png( ImageFiles::MAX_PIXELS + 1, 1 ), 'wide.png', AlertImageException::TOO_BIG ),
			'svg (scripts)'  => array( '<svg xmlns="http://www.w3.org/2000/svg"/>', 'x.svg', AlertImageException::INVALID_TYPE ),
		);
	}

	/**
	 * A refused upload leaves no file and no attachment.
	 *
	 * @phpstan-param non-empty-string $name
	 *
	 * @param string $content Bytes.
	 * @param string $name    File name.
	 * @param string $reason  Expected reason.
	 */
	#[DataProvider( 'refused' )]
	public function test_refused_files_leave_nothing_behind( string $content, string $name, string $reason ): void {
		try {
			$this->images()->upload( $this->creator, self::upload( $content, $name ) );
			self::fail( 'Expected AlertImageException.' );
		} catch ( AlertImageException $e ) {
			self::assertSame( $reason, $e->reason() );
			self::assertSame( 'file', $e->field() );
		}

		self::assertSame( array(), get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short query.
		$dir = wp_upload_dir()['path'];
		self::assertSame( array(), is_dir( $dir ) ? array_values( array_filter( (array) scandir( $dir ), static fn( $file ): bool => is_string( $file ) && ! in_array( $file, array( '.', '..' ), true ) ) ) : array() );
	}

	public function test_an_image_with_the_wrong_extension_is_kept_as_its_real_type(): void {
		$image = $this->images()->upload( $this->creator, self::upload( self::png( 2, 2 ), 'photo.jpg' ) );

		self::assertSame( 'image/png', get_post_mime_type( $image->attachment_id ) );
		self::assertStringEndsWith( '.png', $image->url, 'WordPress gives the file its real extension.' );
	}

	public function test_a_creator_has_at_most_max_own_images(): void {
		$images = $this->images();

		for ( $i = 0; $i < AlertImages::MAX_OWN; $i++ ) {
			$images->upload( $this->creator, self::upload( self::png( 2, 2 ) ) );
		}

		try {
			$images->upload( $this->creator, self::upload( self::png( 2, 2 ) ) );
			self::fail( 'Expected AlertImageException.' );
		} catch ( AlertImageException $e ) {
			self::assertSame( AlertImageException::OWN_FULL, $e->reason() );
		}

		self::assertCount( AlertImages::MAX_OWN, $images->own( $this->creator ) );
	}

	public function test_another_creators_image_is_never_used_listed_or_deleted(): void {
		$other  = ( new CreatorRepository( $this->wpdb ) )->create( 'Orion', CreatorStatus::Active )->id;
		$images = $this->images();
		$theirs = $images->upload( $other, self::upload( self::png( 2, 2 ) ) );

		self::assertNull( $images->find( $this->creator, $theirs->attachment_id ) );
		self::assertSame( array(), $images->own( $this->creator ) );

		$fills = OverlaySettings::from_array(
			array(
				'text_fills' => array(
					'main_text' => array(
						'type'   => 'image',
						'image'  => $theirs->attachment_id,
						'motion' => 'pan',
					),
				),
			)
		)->text_fills();
		$clean = $images->clean( $this->creator, $fills );
		self::assertSame( 'none', $clean['main_text']['type'] );
		self::assertSame( 0, $clean['main_text']['image'] );
		self::assertSame( 'pan', $clean['main_text']['motion'] );
		self::assertSame( $fills, $images->clean( $other, $fills ), 'Their own image stays.' );

		try {
			$images->remove( $this->creator, $theirs->attachment_id );
			self::fail( 'Expected AlertImageException.' );
		} catch ( AlertImageException $e ) {
			self::assertSame( AlertImageException::UNKNOWN_IMAGE, $e->reason() );
			self::assertSame( 'image', $e->field() );
		}

		self::assertNotNull( get_post( $theirs->attachment_id ), 'Orion\'s file is kept.' );
	}

	public function test_deleting_an_own_image_clears_the_fills_that_used_it(): void {
		$images = $this->images();
		$image  = $images->upload( $this->creator, self::upload( self::png( 2, 2 ) ) );
		$this->overlay(
			array(
				'text_fills' => array(
					'main_text'      => array(
						'type'   => 'image',
						'image'  => $image->attachment_id,
						'motion' => 'fall',
					),
					'secondary_text' => array(
						'type'   => 'preset',
						'preset' => 'gold',
					),
				),
			)
		);

		$images->remove( $this->creator, $image->attachment_id );

		$fills = $this->overlay()->settings->text_fills();
		self::assertSame(
			array(
				'type'   => 'none',
				'preset' => 'rainbow',
				'image'  => 0,
				'motion' => 'fall',
			),
			$fills['main_text']
		);
		self::assertSame( 'preset', $fills['secondary_text']['type'], 'Other fills are kept.' );
		self::assertNull( get_post( $image->attachment_id ) );
	}

	public function test_the_library_is_managed_only_by_administrators_and_audited(): void {
		$library = $this->library();
		$named   = $library->add( self::upload( self::png( 4, 4 ), 'galaxy.png' ), '  Galakse <b>blå</b> ' );
		$plain   = $library->add( self::upload( self::png( 4, 4 ), 'clouds.png' ) );

		self::assertTrue( $named->library );
		self::assertSame( 'Galakse blå', $named->name );
		self::assertSame( 'clouds', $plain->name, 'Without a name: the file name.' );
		self::assertSame( array( 'clouds', 'Galakse blå' ), array_map( static fn( AlertImage $image ): string => $image->name, $library->all() ) );

		self::assertTrue( $library->rename( $plain->attachment_id, 'Skyer' ) );
		self::assertFalse( $library->rename( $plain->attachment_id, 'Skyer' ), 'Unchanged.' );
		self::assertSame( 'Skyer', $library->find( $plain->attachment_id )?->name );

		// Creators use library images but never delete them.
		$images = $this->images( $library );
		self::assertSame( $plain->attachment_id, $images->find( $this->creator, $plain->attachment_id )?->attachment_id );
		self::assertCount( 2, $images->describe( $this->creator )['library'] );

		try {
			$images->remove( $this->creator, $plain->attachment_id );
			self::fail( 'Expected AlertImageException.' );
		} catch ( AlertImageException $e ) {
			self::assertSame( AlertImageException::UNKNOWN_IMAGE, $e->reason() );
		}

		wp_set_current_user( $this->create_user( 'image-subscriber' ) );

		foreach ( array( fn() => $library->add( self::upload( self::png( 2, 2 ) ) ), fn() => $library->remove( $named->attachment_id ), fn() => $library->rename( $named->attachment_id, 'X' ) ) as $change ) {
			try {
				$change();
				self::fail( 'Expected AlertImageException.' );
			} catch ( AlertImageException $e ) {
				self::assertSame( AlertImageException::NOT_AUTHORIZED, $e->reason() );
			}
		}

		wp_set_current_user( 1 );
		self::assertFalse( $library->remove( 999999 ), 'Not a library image.' );
		self::assertTrue( $library->remove( $named->attachment_id ) );
		self::assertNull( $images->find( $this->creator, $named->attachment_id ), 'A removed image is no longer shown.' );

		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertSame( array( 'alert_image_library_added', 'alert_image_library_added', 'alert_image_library_renamed', 'alert_image_library_removed' ), array_column( $audit, 'action' ) );
		self::assertSame( array( 'name' => 'galaxy.png' ), json_decode( (string) $audit[0]['context'], true ) );
	}

	public function test_the_admin_tab_uploads_lists_renames_and_removes_library_images(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$library = $this->library();
		$fonts   = new AlertFonts( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$actions = new AlertsAdminActions( $fonts, new RecordingLogger(), null, $library );

		$url = $actions->process(
			array(
				'action'   => AlertsAdminActions::IMAGE_ADD_ACTION,
				'name'     => 'Galakse',
				'_wpnonce' => wp_create_nonce( AlertsAdminActions::IMAGE_ADD_ACTION ),
			),
			array( 'file' => self::upload( self::png( 4, 4 ), 'galaxy.png' ) )
		);
		self::assertStringContainsString( 'tab=images', $url );
		self::assertStringContainsString( AlertsAdminActions::RESULT_ARG . '=image_added', $url );

		$refused = $actions->process(
			array(
				'action'   => AlertsAdminActions::IMAGE_ADD_ACTION,
				'_wpnonce' => wp_create_nonce( AlertsAdminActions::IMAGE_ADD_ACTION ),
			),
			array( 'file' => self::upload( 'not an image', 'x.png' ) )
		);
		self::assertStringContainsString( AlertsAdminActions::RESULT_ARG . '=image_invalid_type', $refused );
		self::assertSame( 'Choose a PNG, JPG, GIF or WebP image.', AlertsAdminPage::result_message( 'image_invalid_type' )[0] ?? null );

		$image = $library->all()[0] ?? null;
		self::assertNotNull( $image );

		$_GET['tab'] = 'images';
		ob_start();
		( new AlertsAdminPage( $fonts, null, $library ) )->render();
		$html = (string) ob_get_clean();
		unset( $_GET['tab'] );

		self::assertStringContainsString( 'enctype="multipart/form-data"', $html );
		self::assertStringContainsString( 'name="name" value="Galakse"', $html );
		self::assertStringContainsString( 'src="' . esc_url( $image->url ) . '"', $html );
		self::assertStringContainsString( 'value="' . AlertsAdminActions::IMAGE_REMOVE_ACTION . '"', $html );

		$nonce = static fn( string $action ): string => wp_create_nonce( AlertsAdminActions::nonce_action( $action, (string) $image->attachment_id ) );

		$url = $actions->process(
			array(
				'action'   => AlertsAdminActions::IMAGE_RENAME_ACTION,
				'image'    => (string) $image->attachment_id,
				'name'     => 'Stjerner',
				'_wpnonce' => $nonce( AlertsAdminActions::IMAGE_RENAME_ACTION ),
			)
		);
		self::assertStringContainsString( AlertsAdminActions::RESULT_ARG . '=image_renamed', $url );
		self::assertSame( 'Stjerner', $library->find( $image->attachment_id )?->name );

		$url = $actions->process(
			array(
				'action'   => AlertsAdminActions::IMAGE_REMOVE_ACTION,
				'image'    => (string) $image->attachment_id,
				'_wpnonce' => $nonce( AlertsAdminActions::IMAGE_REMOVE_ACTION ),
			)
		);
		self::assertStringContainsString( AlertsAdminActions::RESULT_ARG . '=image_removed', $url );
		self::assertSame( array(), $library->all() );
	}

	/**
	 * Nova's overlay with settings.
	 *
	 * @param array<string, mixed> $settings Raw settings.
	 */
	private function overlay( array $settings = array() ): AlertOverlay {
		$existing = $this->overlays->find_for_creator( $this->creator );

		if ( null !== $existing ) {
			$this->overlays->update_settings( $existing->id, OverlaySettings::from_array( array_merge( $existing->settings->to_array(), $settings ) ) );
		} else {
			$this->overlays->create( $this->creator, OverlayId::generate(), OverlaySettings::from_array( $settings ) );
		}

		$overlay = $this->overlays->find_for_creator( $this->creator );
		self::assertNotNull( $overlay );

		return $overlay;
	}
}
