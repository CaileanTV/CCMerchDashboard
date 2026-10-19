<?php
/**
 * Integration tests for alert sounds.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Tests\Integration\Alerts;

use DateTimeImmutable;
use DateTimeZone;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlay;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\OverlayId;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Modules\Alerts\Sounds\AlertSoundException;
use MerchBoard\Modules\Alerts\Sounds\AlertSounds;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ADR-065 pkt. 3: a creator's sounds are real audio within the limits, are
 * only ever the creator's own files, and a replaced or removed sound's file
 * is deleted.
 */
#[CoversClass( AlertSounds::class )]
#[CoversClass( AlertSoundException::class )]
final class AlertSoundsTest extends IntegrationTestCase {

	private const HOODIES = 12;

	private const CHILD = 13;

	private AlertOverlayRepository $overlays;

	private AlertSounds $sounds;

	private int $creator;

	protected function setUp(): void {
		parent::setUp();
		$this->install();

		$this->overlays = new AlertOverlayRepository( $this->wpdb );
		$this->sounds   = self::sounds( $this->overlays );
		$this->creator  = ( new CreatorRepository( $this->wpdb ) )->create( 'Nova', CreatorStatus::Active )->id;
	}

	protected function tearDown(): void {
		foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $attachment ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short query.
			wp_delete_attachment( (int) $attachment, true );
		}

		parent::tearDown();
	}

	/**
	 * The service as the module builds it, with a test upload move.
	 *
	 * @param AlertOverlayRepository $overlays Overlays.
	 */
	public static function sounds( AlertOverlayRepository $overlays ): AlertSounds {
		return new AlertSounds(
			$overlays,
			static fn(): array => array( self::HOODIES => 'Hoodies' ),
			// Uploads in tests are not HTTP uploads: is_uploaded_file() is false.
			static function ( array $file, array $overrides ): array {
				require_once ABSPATH . 'wp-admin/includes/file.php';

				return wp_handle_sideload( $file, $overrides );
			},
			null,
			static fn( int $term_id ): array => self::CHILD === $term_id ? array( self::HOODIES ) : array()
		);
	}

	/**
	 * An upload of a mono 8-bit WAV file of silence.
	 *
	 * @phpstan-param non-empty-string $name
	 *
	 * @param int    $seconds Length.
	 * @param string $name    File name.
	 * @return array{name: non-empty-string, type: non-empty-string, tmp_name: non-empty-string, error: int<0, 8>, size: int<0, max>}
	 */
	public static function wav_upload( int $seconds, string $name = 'ding.wav' ): array {
		$rate = 8000;
		$data = str_repeat( "\x80", $rate * $seconds );
		$wav  = 'RIFF' . pack( 'V', 36 + strlen( $data ) ) . 'WAVEfmt ' . pack( 'VvvVVvv', 16, 1, 1, $rate, $rate, 1, 8 ) . 'data' . pack( 'V', strlen( $data ) ) . $data;

		return self::upload( $wav, $name );
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
	public static function upload( string $content, string $name ): array {
		$path = wp_tempnam( $name );

		if ( '' === $path ) {
			self::fail( 'No temporary file.' );
		}

		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		return array(
			'name'     => $name,
			'type'     => 'application/octet-stream',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $content ),
		);
	}

	public function test_a_default_sound_is_stored_as_the_creators_attachment_and_played(): void {
		$this->overlay();

		$overlay = $this->sounds->upload( $this->creator, 0, self::wav_upload( 2 ) );
		self::assertNotNull( $overlay );

		$id = $overlay->settings->to_array()['audio_default_sound'];
		self::assertGreaterThan( 0, $id );
		self::assertSame( (string) $this->creator, get_post_meta( $id, AlertSounds::OWNER_META, true ) );
		self::assertSame( 'audio/wav', get_post_mime_type( $id ) );

		$audio = $this->sounds->describe( $overlay );
		self::assertTrue( $audio['enabled'], 'The first sound switches sounds on.' );
		$default = $audio['default'];
		self::assertNotNull( $default );
		self::assertSame( 2, $default['seconds'] );
		self::assertStringEndsWith( '.wav', $default['url'] );
		self::assertSame(
			array(
				array(
					'term_id' => self::HOODIES,
					'name'    => 'Hoodies',
					'sound'   => null,
				),
			),
			$audio['categories']
		);
		self::assertSame( AlertSounds::MAX_SECONDS, $audio['max_seconds'] );

		$disabled = $this->overlay( $this->creator, array( 'audio_enabled' => false ) );
		self::assertSame( '', $this->sounds->url_for( $disabled, $this->event( array() ) ), 'Sounds are off.' );

		$enabled = $this->enable();
		self::assertSame( wp_get_attachment_url( $id ), $this->sounds->url_for( $enabled, $this->event( array( 99 ) ) ) );
	}

	public function test_a_category_sound_plays_for_the_category_and_its_children(): void {
		$this->overlay();
		$this->sounds->upload( $this->creator, 0, self::wav_upload( 1 ) );
		$this->enable();
		$overlay = $this->sounds->upload( $this->creator, self::HOODIES, self::wav_upload( 1, 'hoodie.wav' ) );
		self::assertNotNull( $overlay );

		$values   = $overlay->settings->to_array();
		$category = (string) wp_get_attachment_url( $values['audio_category_map'][ self::HOODIES ] );
		$default  = (string) wp_get_attachment_url( $values['audio_default_sound'] );

		self::assertSame( $category, $this->sounds->url_for( $overlay, $this->event( array( self::HOODIES ) ) ) );
		self::assertSame( $category, $this->sounds->url_for( $overlay, $this->event( array( 99, self::CHILD ) ) ), 'A subcategory plays its parent\'s sound.' );
		self::assertSame( $default, $this->sounds->url_for( $overlay, $this->event( array( 99 ) ) ) );
		self::assertSame( 'hoodie.wav', $this->sounds->describe( $overlay )['categories'][0]['sound']['name'] ?? null );
	}

	public function test_replacing_and_removing_delete_the_old_file(): void {
		$this->overlay();
		$first = $this->sounds->upload( $this->creator, 0, self::wav_upload( 1 ) )?->settings->to_array()['audio_default_sound'] ?? 0;
		$file  = (string) get_attached_file( $first );
		self::assertFileExists( $file );

		$this->overlay( $this->creator, array( 'audio_enabled' => false ) );
		$replaced = $this->sounds->upload( $this->creator, 0, self::wav_upload( 1 ) );
		self::assertNotNull( $replaced );
		self::assertFalse( $replaced->settings->to_array()['audio_enabled'], 'Replacing a sound keeps sounds off.' );
		$second = $replaced->settings->to_array()['audio_default_sound'];
		self::assertNotSame( $first, $second );
		self::assertNull( get_post( $first ), 'The replaced sound is deleted.' );
		self::assertFileDoesNotExist( $file );

		$overlay = $this->sounds->remove( $this->creator, 0 );
		self::assertSame( 0, $overlay?->settings->to_array()['audio_default_sound'] );
		self::assertNull( get_post( $second ) );
	}

	/**
	 * Refused uploads.
	 *
	 * @return array<string, array{string, non-empty-string}>
	 */
	public static function refused(): array {
		return array(
			'text named wav' => array( 'not audio at all', 'ding.wav' ),
			'php file'       => array( '<?php echo 1;', 'ding.php' ),
			'wav named mp3'  => array( 'wav', 'ding.mp3' ),
			'too long'       => array( 'long', 'long.wav' ),
		);
	}

	/**
	 * Every refused file leaves nothing behind.
	 *
	 * @phpstan-param non-empty-string $name
	 *
	 * @param string $content Content, or a marker for a generated WAV.
	 * @param string $name    File name.
	 */
	#[DataProvider( 'refused' )]
	public function test_refused_files_leave_nothing_behind( string $content, string $name ): void {
		$this->overlay();
		$upload = match ( $content ) {
			'wav'   => self::wav_upload( 1, $name ),
			'long'  => self::wav_upload( AlertSounds::MAX_SECONDS + 1, $name ),
			default => self::upload( $content, $name ),
		};
		$before = $this->files();

		try {
			$this->sounds->upload( $this->creator, 0, $upload );
			self::fail( 'Expected AlertSoundException.' );
		} catch ( AlertSoundException $e ) {
			self::assertContains( $e->reason(), array( AlertSoundException::INVALID_TYPE, AlertSoundException::UNREADABLE, AlertSoundException::TOO_LONG ) );

			if ( 'long' === $content ) {
				self::assertSame( AlertSoundException::TOO_LONG, $e->reason() );
			}
		}

		self::assertSame( array(), get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short query.
		self::assertSame( $before, $this->files(), 'No file is left in the uploads folder.' );
		self::assertSame( 0, $this->overlays->find_for_creator( $this->creator )?->settings->to_array()['audio_default_sound'] );
	}

	public function test_a_foreign_category_a_missing_file_and_no_overlay_are_refused(): void {
		self::assertNull( $this->sounds->upload( $this->creator, 0, self::wav_upload( 1 ) ), 'No overlay yet.' );
		$this->overlay();

		foreach (
			array(
				array( 77, self::wav_upload( 1 ), AlertSoundException::UNKNOWN_CATEGORY ),
				array( 0, array( 'error' => UPLOAD_ERR_NO_FILE ), AlertSoundException::NO_FILE ),
				array( 0, array_merge( self::wav_upload( 1 ), array( 'error' => UPLOAD_ERR_INI_SIZE ) ), AlertSoundException::TOO_LARGE ),
			) as [ $slot, $file, $reason ]
		) {
			try {
				$this->sounds->upload( $this->creator, $slot, $file );
				self::fail( 'Expected AlertSoundException.' );
			} catch ( AlertSoundException $e ) {
				self::assertSame( $reason, $e->reason() );
			}
		}

		self::assertSame( 'slot', ( new AlertSoundException( AlertSoundException::UNKNOWN_CATEGORY ) )->field() );
	}

	public function test_another_creators_file_is_never_played_listed_or_deleted(): void {
		$other = ( new CreatorRepository( $this->wpdb ) )->create( 'Orion', CreatorStatus::Active )->id;
		$this->overlay( $other );
		$id = $this->sounds->upload( $other, 0, self::wav_upload( 1 ) )?->settings->to_array()['audio_default_sound'] ?? 0;
		self::assertGreaterThan( 0, $id );

		// Nova's settings point at Orion's file (for example after a bad import).
		$overlay = $this->overlay( $this->creator, array( 'audio_enabled' => true, 'audio_default_sound' => $id ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short settings.
		self::assertNull( $this->sounds->describe( $overlay )['default'] );
		self::assertSame( '', $this->sounds->url_for( $overlay, $this->event( array() ) ) );

		$this->sounds->remove( $this->creator, 0 );
		self::assertNotNull( get_post( $id ), 'Orion\'s file is kept.' );
	}

	/**
	 * A creator's overlay with settings.
	 *
	 * @param int|null             $creator_id Creator (default: Nova).
	 * @param array<string, mixed> $settings   Raw settings.
	 */
	private function overlay( ?int $creator_id = null, array $settings = array() ): AlertOverlay {
		$creator_id ??= $this->creator;
		$existing     = $this->overlays->find_for_creator( $creator_id );

		if ( null !== $existing ) {
			$this->overlays->update_settings( $existing->id, OverlaySettings::from_array( array_merge( $existing->settings->to_array(), $settings ) ) );
		} else {
			$this->overlays->create( $creator_id, OverlayId::generate(), OverlaySettings::from_array( $settings ) );
		}

		$overlay = $this->overlays->find_for_creator( $creator_id );
		self::assertNotNull( $overlay );

		return $overlay;
	}

	/**
	 * Nova's overlay with sounds switched on.
	 */
	private function enable(): AlertOverlay {
		return $this->overlay( $this->creator, array( 'audio_enabled' => true ) );
	}

	/**
	 * An alert of Nova with product categories.
	 *
	 * @phpstan-param list<int> $categories
	 *
	 * @param int[] $categories Category term ids.
	 */
	private function event( array $categories ): AlertEvent {
		return new AlertEvent( $this->creator, 7, '7', 70, 10, 0, 'Hoodie', '', 1, 0, $categories, 'nova_fan', new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * The files in this month's uploads folder.
	 *
	 * @return list<string>
	 */
	private function files(): array {
		$dir   = wp_upload_dir()['path'];
		$files = is_dir( $dir ) ? scandir( $dir ) : array();

		return array_values( array_diff( false === $files ? array() : $files, array( '.', '..' ) ) );
	}
}
