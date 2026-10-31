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
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\Tables;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Alerts\Admin\AlertsAdminActions;
use MerchBoard\Modules\Alerts\Admin\AlertsAdminPage;
use MerchBoard\Modules\Alerts\Admin\AlertsSettingsActions;
use MerchBoard\Modules\Alerts\Admin\CreatorSoundLimitsActions;
use MerchBoard\Modules\Alerts\Admin\CreatorSoundLimitsSection;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\AlertSettingsException;
use MerchBoard\Modules\Alerts\Fonts\AlertFonts;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlay;
use MerchBoard\Modules\Alerts\Overlay\AlertOverlayRepository;
use MerchBoard\Modules\Alerts\Overlay\OverlayId;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use MerchBoard\Modules\Alerts\Sounds\AlertSoundException;
use MerchBoard\Modules\Alerts\Sounds\AlertSoundLibrary;
use MerchBoard\Modules\Alerts\Sounds\AlertSounds;
use MerchBoard\Modules\Alerts\Sounds\CreatorSoundLimits;
use MerchBoard\Modules\Alerts\Sounds\SoundFiles;
use MerchBoard\Modules\Creators\CreatorMetaStore;
use MerchBoard\Modules\Creators\CreatorRepository;
use MerchBoard\Modules\Creators\CreatorStatus;
use MerchBoard\Tests\Integration\IntegrationTestCase;
use MerchBoard\Tests\Unit\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ADR-065 pkt. 3: a creator's sounds are real audio within the limits, are
 * only ever the creator's own files, and a replaced or removed sound's file
 * is deleted.
 */
#[CoversClass( AlertSounds::class )]
#[CoversClass( AlertSoundException::class )]
#[CoversClass( AlertSoundLibrary::class )]
#[CoversClass( SoundFiles::class )]
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

		delete_option( Settings::OPTION );
		parent::tearDown();
	}

	/**
	 * Sound files with a test upload move.
	 */
	public static function sound_files(): SoundFiles {
		// Uploads in tests are not HTTP uploads: is_uploaded_file() is false.
		return new SoundFiles(
			static function ( array $file, array $overrides ): array {
				require_once ABSPATH . 'wp-admin/includes/file.php';

				return wp_handle_sideload( $file, $overrides );
			}
		);
	}

	/**
	 * The sound library as the module builds it.
	 *
	 * @param AuditTrail  $audit       Audit trail.
	 * @param Transaction $transaction Transaction.
	 */
	public static function library( AuditTrail $audit, Transaction $transaction ): AlertSoundLibrary {
		return new AlertSoundLibrary( self::sound_files(), $audit, $transaction );
	}

	/**
	 * The service as the module builds it.
	 *
	 * @param AlertOverlayRepository  $overlays Overlays.
	 * @param AlertSoundLibrary|null  $library  Library.
	 * @param CreatorSoundLimits|null $limits   The creators' own limits.
	 */
	public static function sounds( AlertOverlayRepository $overlays, ?AlertSoundLibrary $library = null, ?CreatorSoundLimits $limits = null ): AlertSounds {
		return new AlertSounds(
			$overlays,
			static fn(): array => array( self::HOODIES => 'Hoodies' ),
			self::sound_files(),
			$library,
			static fn( int $term_id ): array => self::CHILD === $term_id ? array( self::HOODIES ) : array(),
			$limits
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
		self::assertSame( array( SoundFiles::max_bytes(), 30 ), array( $audio['max_bytes'], $audio['max_seconds'] ), 'The default limits.' );

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
			'long'  => self::wav_upload( SoundFiles::max_seconds() + 1, $name ),
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

	public function test_the_administrators_limits_apply_to_new_uploads_and_stored_sounds_stay(): void {
		$this->overlay();
		$stored = $this->sounds->upload( $this->creator, 0, self::wav_upload( 3 ) );
		self::assertNotNull( $stored );

		Settings::save(
			array_merge(
				Settings::all(),
				array(
					Settings::SOUND_MAX_BYTES   => 102400,
					Settings::SOUND_MAX_SECONDS => 2,
				)
			)
		);
		$library = self::library( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );

		foreach ( array(
			array( fn() => $this->sounds->upload( $this->creator, 0, self::wav_upload( 3 ) ), AlertSoundException::TOO_LONG ),
			array( static fn() => $library->add( self::wav_upload( 3, 'chime.wav' ) ), AlertSoundException::TOO_LONG ),
			array( fn() => $this->sounds->upload( $this->creator, 0, self::wav_upload( 13 ) ), AlertSoundException::TOO_LARGE ),
		) as [ $upload, $reason ] ) {
			try {
				$upload();
				self::fail( 'Expected AlertSoundException.' );
			} catch ( AlertSoundException $e ) {
				self::assertSame( $reason, $e->reason() );
			}
		}

		$audio = $this->sounds->describe( $stored );
		self::assertSame( array( 102400, 2 ), array( $audio['max_bytes'], $audio['max_seconds'] ), 'The dashboard shows the new limits.' );
		self::assertSame( 3, $audio['default']['seconds'] ?? null, 'A stored sound stays, though it is longer now.' );
		self::assertSame( array(), $library->all() );
		self::assertSame( 'The sound is longer than 2 seconds.', AlertsAdminPage::result_message( AlertSoundException::TOO_LONG )[0] ?? null );
		self::assertSame( 'The file is larger than 100 KB.', AlertsAdminPage::result_message( AlertSoundException::TOO_LARGE )[0] ?? null );
	}

	public function test_a_creators_own_limits_apply_to_their_uploads_only_and_are_audited(): void {
		$creators = new CreatorRepository( $this->wpdb );
		$limits   = new CreatorSoundLimits( new CreatorMetaStore( $this->wpdb ), $creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$library  = self::library( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$sounds   = self::sounds( $this->overlays, $library, $limits );
		$other    = $creators->create( 'Orion', CreatorStatus::Active )->id;
		$this->overlay();
		$this->overlay( $other );
		Settings::save( array_merge( Settings::all(), array( Settings::SOUND_MAX_SECONDS => 2 ) ) );

		self::assertSame(
			array(
				'max_bytes'   => null,
				'max_seconds' => null,
			),
			$limits->own( $this->creator ),
			'None: the site-wide limits.'
		);
		self::assertTrue( $limits->set( $this->creator, 102400, 5 ) );
		self::assertFalse( $limits->set( $this->creator, 102400, 5 ), 'Unchanged.' );

		$overlay = $sounds->upload( $this->creator, 0, self::wav_upload( 3 ) );
		self::assertSame( 3, $overlay ? $sounds->describe( $overlay )['default']['seconds'] ?? null : null, 'Longer than the site-wide limit, within the creator\'s own.' );
		self::assertSame( array( 102400, 5 ), $overlay ? array( $sounds->describe( $overlay )['max_bytes'], $sounds->describe( $overlay )['max_seconds'] ) : null, 'The dashboard shows the creator\'s own limits.' );

		foreach ( array(
			array( fn() => $sounds->upload( $this->creator, 0, self::wav_upload( 13 ) ), AlertSoundException::TOO_LARGE ),
			array( fn() => $sounds->upload( $other, 0, self::wav_upload( 3 ) ), AlertSoundException::TOO_LONG ),
			array( static fn() => $library->add( self::wav_upload( 3, 'chime.wav' ) ), AlertSoundException::TOO_LONG ),
		) as [ $upload, $reason ] ) {
			try {
				$upload();
				self::fail( 'Expected AlertSoundException.' );
			} catch ( AlertSoundException $e ) {
				self::assertSame( $reason, $e->reason(), 'Smaller than the creator\'s own; the other creator and the library keep the site-wide limits.' );
			}
		}

		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertSame( array( 'alert_creator_sound_limits_changed', 'alert_creator_sound_limits_changed' ), array_column( $audit, 'action' ) );
		self::assertSame( (string) $this->creator, (string) $audit[0]['creator_id'] );
		self::assertSame(
			array(
				'setting'        => 'sound_max_bytes',
				'previous_value' => null,
				'value'          => 102400,
			),
			json_decode( (string) $audit[0]['context'], true )
		);

		self::assertTrue( $limits->set( $this->creator, null, 5 ) );
		self::assertSame( SoundFiles::max_bytes(), $limits->for_creator( $this->creator )->max_bytes, 'An empty limit is the site-wide one again.' );
		self::assertTrue( $limits->set( $this->creator, null, null ) );
		self::assertNull( ( new CreatorMetaStore( $this->wpdb ) )->get( $this->creator, CreatorMetaStore::ALERT_SOUND_LIMITS ), 'Nothing is kept.' );

		foreach ( array(
			array( $this->creator, 102399, null, AlertSettingsException::INVALID_SOUND_SIZE ),
			array( $this->creator, null, 3601, AlertSettingsException::INVALID_SOUND_LENGTH ),
			array( 999999, null, 5, AlertSettingsException::CREATOR_NOT_FOUND ),
		) as [ $creator_id, $bytes, $seconds, $reason ] ) {
			try {
				$limits->set( $creator_id, $bytes, $seconds );
				self::fail( 'Expected AlertSettingsException.' );
			} catch ( AlertSettingsException $e ) {
				self::assertSame( $reason, $e->reason() );
			}
		}

		wp_set_current_user( $this->create_user( 'limits-subscriber' ) );

		try {
			$limits->set( $this->creator, null, 5 );
			self::fail( 'Expected AlertSettingsException.' );
		} catch ( AlertSettingsException $e ) {
			self::assertSame( AlertSettingsException::NOT_AUTHORIZED, $e->reason() );
		}

		wp_set_current_user( 1 );
	}

	public function test_the_creators_edit_page_sets_and_shows_their_own_limits(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$creators = new CreatorRepository( $this->wpdb );
		$limits   = new CreatorSoundLimits( new CreatorMetaStore( $this->wpdb ), $creators, $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$actions  = new CreatorSoundLimitsActions( $limits, new RecordingLogger() );
		$form     = fn( string $size, string $length ): array => array(
			'creator_id'                                   => (string) $this->creator,
			'_wpnonce'                                     => wp_create_nonce( CreatorSoundLimitsActions::nonce_action( $this->creator ) ),
			AlertsSettingsActions::FIELD_SOUND_SIZE        => $size,
			AlertsSettingsActions::FIELD_SOUND_SIZE_UNIT   => 'MB',
			AlertsSettingsActions::FIELD_SOUND_LENGTH      => $length,
			AlertsSettingsActions::FIELD_SOUND_LENGTH_UNIT => 'minutes',
		);

		$url = $actions->handle( $form( '', '2' ) );
		self::assertStringContainsString( CreatorSoundLimitsActions::RESULT_ARG . '=saved', $url );
		self::assertStringContainsString( 'creator=' . $this->creator, $url );
		self::assertStringEndsWith( '#merchboard-creator-sound-limits', $url );
		self::assertSame(
			array(
				'max_bytes'   => null,
				'max_seconds' => 120,
			),
			$limits->own( $this->creator ),
			'An empty field keeps the site-wide limit.'
		);
		self::assertStringContainsString( '=invalid_sound_size', $actions->handle( $form( '600', '2' ) ) );
		self::assertStringContainsString( '=no_changes', $actions->handle( $form( '', '2' ) ) );
		self::assertNotNull( CreatorSoundLimitsSection::result_message( 'creator_not_found' ) );
		self::assertNull( CreatorSoundLimitsSection::result_message( '<script>' ) );

		$creator = $creators->find( $this->creator );
		self::assertNotNull( $creator );
		ob_start();
		( new CreatorSoundLimitsSection( $limits ) )->render( $creator );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'id="merchboard-creator-sound-limits"', $html );
		self::assertStringContainsString( 'name="sound_max_size" value=""', $html, 'Empty: the site-wide limit.' );
		self::assertStringContainsString( 'name="sound_max_length" value="2"', $html );
		self::assertStringContainsString( 'value="' . CreatorSoundLimitsActions::ACTION . '"', $html );

		wp_set_current_user( $this->create_user( 'section-subscriber' ) );
		ob_start();
		( new CreatorSoundLimitsSection( $limits ) )->render( $creator );
		self::assertSame( '', (string) ob_get_clean(), 'Only for users who manage alerts.' );
		wp_set_current_user( 1 );
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

	public function test_the_library_is_managed_only_by_administrators_and_audited(): void {
		$library = self::library( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$sound   = $library->add( self::wav_upload( 1, 'chime.wav' ) );

		self::assertTrue( $sound->library );
		self::assertSame( 'chime', $sound->name, 'Without a name: the file name.' );
		self::assertSame( array( $sound->attachment_id ), array_map( static fn( $item ): int => $item->attachment_id, $library->all() ) );
		self::assertNull( $library->find( 999999 ) );

		$audit = $this->rows( Tables::AUDIT_LOG );
		self::assertSame( array( 'alert_sound_library_added' ), array_column( $audit, 'action' ) );
		self::assertSame( array( 'name' => 'chime.wav' ), json_decode( (string) $audit[0]['context'], true ) );

		wp_set_current_user( $this->create_user( 'library-subscriber' ) );

		foreach ( array( static fn() => $library->add( self::wav_upload( 1 ) ), static fn() => $library->remove( $sound->attachment_id ) ) as $change ) {
			try {
				$change();
				self::fail( 'Expected AlertSoundException.' );
			} catch ( AlertSoundException $e ) {
				self::assertSame( AlertSoundException::NOT_AUTHORIZED, $e->reason() );
			}
		}

		wp_set_current_user( 1 );
		self::assertFalse( $library->remove( 999999 ), 'Not a library sound.' );
		self::assertTrue( $library->remove( $sound->attachment_id ) );
		self::assertNull( get_post( $sound->attachment_id ) );
		self::assertSame( array( 'alert_sound_library_added', 'alert_sound_library_removed' ), array_column( $this->rows( Tables::AUDIT_LOG ), 'action' ) );
	}

	public function test_library_sounds_have_names_the_administrator_chooses(): void {
		$library = self::library( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$named   = $library->add( self::wav_upload( 1, 'chime.wav' ), '  Kasse <b>ding</b>  ' );
		$plain   = $library->add( self::wav_upload( 1, 'bell.wav' ) );

		self::assertSame( 'Kasse ding', $named->name, 'Plain text, trimmed.' );
		self::assertSame( 'bell', $plain->name, 'Without a name: the file name.' );

		$name = static fn( int $id ): ?string => $library->find( $id )?->name;

		self::assertTrue( $library->rename( $plain->attachment_id, 'Klokke' ) );
		self::assertFalse( $library->rename( $plain->attachment_id, 'Klokke' ), 'Unchanged.' );
		self::assertSame( 'Klokke', $name( $plain->attachment_id ) );
		self::assertSame( str_repeat( 'x', AlertSoundLibrary::NAME_MAX ), $library->rename( $named->attachment_id, str_repeat( 'x', 80 ) ) ? $name( $named->attachment_id ) : null );

		self::assertTrue( $library->rename( $plain->attachment_id, '' ) );
		self::assertSame( 'bell', $name( $plain->attachment_id ), 'An empty name gives the file name again.' );

		$renamed = array_values( array_filter( $this->rows( Tables::AUDIT_LOG ), static fn( array $row ): bool => 'alert_sound_library_renamed' === $row['action'] ) );
		self::assertCount( 3, $renamed );
		self::assertSame(
			array(
				'name'          => 'Klokke',
				'previous_name' => 'bell',
			),
			json_decode( (string) $renamed[0]['context'], true )
		);

		try {
			$library->rename( 999999, 'X' );
			self::fail( 'Expected AlertSoundException.' );
		} catch ( AlertSoundException $e ) {
			self::assertSame( AlertSoundException::UNKNOWN_SOUND, $e->reason() );
		}

		// Creators see the name.
		$this->overlay();
		$sounds = self::sounds( $this->overlays, $library );
		self::assertContains( 'bell', array_column( $sounds->describe( $this->overlay() )['library'], 'name' ) );
	}

	public function test_creators_choose_library_sounds_which_they_never_delete(): void {
		$library = self::library( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$sounds  = self::sounds( $this->overlays, $library );
		$chime   = $library->add( self::wav_upload( 1, 'chime.wav' ) );
		$this->overlay();

		self::assertSame( array( 'id' => $chime->attachment_id, 'url' => $chime->url, 'name' => 'chime', 'seconds' => 1 ), $sounds->describe( $this->overlay() )['library'][0] ?? null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short expectation.

		$overlay = $sounds->choose( $this->creator, self::HOODIES, $chime->attachment_id );
		self::assertNotNull( $overlay );
		self::assertTrue( $overlay->settings->to_array()['audio_enabled'], 'The first sound switches sounds on.' );
		self::assertSame( $chime->attachment_id, $sounds->describe( $overlay )['categories'][0]['sound']['library_id'] ?? null );
		self::assertSame( $chime->url, $sounds->url_for( $overlay, $this->event( array( self::HOODIES ) ) ) );

		// Replacing and removing never delete the library file.
		$sounds->upload( $this->creator, self::HOODIES, self::wav_upload( 1 ) );
		$sounds->remove( $this->creator, self::HOODIES );
		self::assertNotNull( get_post( $chime->attachment_id ) );

		// Own uploads of another creator are not library sounds.
		$own = $sounds->upload( $this->creator, 0, self::wav_upload( 1 ) )?->settings->to_array()['audio_default_sound'] ?? 0;

		foreach ( array( $own, 999999 ) as $attachment ) {
			try {
				$sounds->choose( $this->creator, self::HOODIES, $attachment );
				self::fail( 'Expected AlertSoundException.' );
			} catch ( AlertSoundException $e ) {
				self::assertSame( AlertSoundException::UNKNOWN_SOUND, $e->reason() );
				self::assertSame( 'library', $e->field() );
			}
		}

		// A removed library sound is no longer played.
		$sounds->choose( $this->creator, 0, $chime->attachment_id );
		$library->remove( $chime->attachment_id );
		$after = $this->overlay();
		self::assertNull( $sounds->describe( $after )['default'] );
		self::assertSame( '', $sounds->url_for( $after, $this->event( array() ) ) );
	}

	public function test_the_admin_tab_uploads_lists_and_removes_library_sounds(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$library = self::library( $this->service( AuditTrail::class ), $this->service( Transaction::class ) );
		$actions = new AlertsAdminActions( new AlertFonts( $this->service( AuditTrail::class ), $this->service( Transaction::class ) ), new RecordingLogger(), $library );

		$url = $actions->process(
			array(
				'action'   => AlertsAdminActions::SOUND_ADD_ACTION,
				'_wpnonce' => wp_create_nonce( AlertsAdminActions::SOUND_ADD_ACTION ),
			),
			array( 'file' => self::wav_upload( 1, 'chime.wav' ) )
		);
		self::assertStringContainsString( 'tab=sounds', $url );
		self::assertStringContainsString( AlertsAdminActions::RESULT_ARG . '=sound_added', $url );

		$refused = $actions->process(
			array(
				'action'   => AlertsAdminActions::SOUND_ADD_ACTION,
				'_wpnonce' => wp_create_nonce( AlertsAdminActions::SOUND_ADD_ACTION ),
			),
			array( 'file' => self::upload( 'not audio', 'x.php' ) )
		);
		self::assertStringContainsString( AlertsAdminActions::RESULT_ARG . '=invalid_type', $refused );

		$sound = $library->all()[0] ?? null;
		self::assertNotNull( $sound );

		$_GET['tab'] = 'sounds';
		ob_start();
		( new AlertsAdminPage( $library ) )->render();
		$html = (string) ob_get_clean();
		unset( $_GET['tab'] );

		self::assertStringContainsString( 'enctype="multipart/form-data"', $html );
		self::assertStringContainsString( 'name="name" value="chime"', $html, 'The name can be changed in place.' );
		self::assertStringContainsString( 'value="' . AlertsAdminActions::SOUND_REMOVE_ACTION . '"', $html );
		self::assertNotNull( AlertsAdminPage::result_message( 'library_full' ) );

		$url = $actions->process(
			array(
				'action'   => AlertsAdminActions::SOUND_RENAME_ACTION,
				'sound'    => (string) $sound->attachment_id,
				'name'     => 'Kasse',
				'_wpnonce' => wp_create_nonce( AlertsAdminActions::nonce_action( AlertsAdminActions::SOUND_RENAME_ACTION, (string) $sound->attachment_id ) ),
			)
		);
		self::assertStringContainsString( AlertsAdminActions::RESULT_ARG . '=sound_renamed', $url );
		self::assertSame( 'Kasse', $library->find( $sound->attachment_id )?->name );

		$url = $actions->process(
			array(
				'action'   => AlertsAdminActions::SOUND_REMOVE_ACTION,
				'sound'    => (string) $sound->attachment_id,
				'_wpnonce' => wp_create_nonce( AlertsAdminActions::nonce_action( AlertsAdminActions::SOUND_REMOVE_ACTION, (string) $sound->attachment_id ) ),
			)
		);
		self::assertStringContainsString( AlertsAdminActions::RESULT_ARG . '=sound_removed', $url );
		self::assertSame( array(), $library->all() );
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
