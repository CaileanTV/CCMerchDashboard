<?php
/**
 * Reads alerts out with Google Cloud Text-to-Speech.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Tts;

use Closure;
use MerchBoard\Core\Logging\Logger;
use MerchBoard\Modules\Alerts\AlertEvent;
use MerchBoard\Modules\Alerts\Overlay\OverlaySettings;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * ADR-089: the read-out of an alert is an MP3 that Google Cloud
 * Text-to-Speech makes of the alert's text, when the feed renders the
 * alert. Only `POST /v1/text:synthesize` with a standard voice of
 * TtsVoices. The file is stored in `uploads/merchboard-alerts/tts/` under a
 * hash of voice and text, so the same text is made once; files older than
 * two days are deleted whenever a new one is made. A failure, the monthly
 * limit or a look without read-out gives no read-out: the alert is shown
 * without it. Neither the text nor the key is logged.
 */
class AlertTts {

	/**
	 * Google's endpoint.
	 */
	public const ENDPOINT = 'https://texttospeech.googleapis.com/v1/text:synthesize';

	/**
	 * Longest text read out, in characters.
	 */
	public const MAX_CHARS = 200;

	/**
	 * How long a file is kept, in seconds.
	 */
	public const KEEP = 2 * DAY_IN_SECONDS;

	/**
	 * The folder below the uploads folder.
	 */
	public const FOLDER = 'merchboard-alerts/tts';

	/**
	 * Most new read-outs a creator's test alerts may make in an hour (30.15
	 * review): tests cannot use up the shared monthly limit.
	 */
	public const TESTS_PER_HOUR = 10;

	/**
	 * Most seconds one request spends making read-outs; the rest of its
	 * alerts are shown without (30.15 review: a slow Google must not stall
	 * the feed, which answers within 20 s).
	 */
	public const BUDGET = 6.0;

	/**
	 * After a failure no read-out is made for this many seconds.
	 */
	public const PAUSE = 5 * MINUTE_IN_SECONDS;

	/**
	 * Seconds this request spent making read-outs.
	 *
	 * @var float
	 */
	private float $spent = 0.0;

	/**
	 * POSTs JSON: URL, body, API key (sent as the `X-Goog-Api-Key` header,
	 * never in the URL) → [status, body] or null on a network error.
	 *
	 * @var Closure(string, string, string): (array{0: int, 1: string}|null)
	 */
	private Closure $post;

	/**
	 * Constructor.
	 *
	 * @param TtsSettings  $settings The switch, key and limit.
	 * @param Logger       $logger   Logger.
	 * @param Closure|null $post     HTTP (tests); defaults to wp_remote_post() with a 5 s timeout.
	 * @param Closure|null $live     Whether a creator is live in the shop; the read-out opens only then (ADR-091). Null: always.
	 * @phpstan-param (Closure(string, string, string): (array{0: int, 1: string}|null))|null $post
	 * @phpstan-param (Closure(int): bool)|null $live
	 */
	public function __construct( private TtsSettings $settings, private Logger $logger, ?Closure $post = null, private ?Closure $live = null ) {
		$this->post = $post ?? static function ( string $url, string $body, string $key ): ?array {
			$response = wp_safe_remote_post(
				$url,
				array(
					'timeout'     => 5,
					'redirection' => 0,
					'headers'     => array(
						'Content-Type'   => 'application/json',
						'X-Goog-Api-Key' => $key,
					),
					'body'        => $body,
				)
			);

			return is_wp_error( $response ) ? null : array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) );
		};
	}

	/**
	 * The URL of an alert's read-out, or '' for none.
	 *
	 * @param OverlaySettings $look  The settings the alert is shown with.
	 * @param AlertEvent      $event Alert.
	 * @param string          $text  The alert's text.
	 */
	public function url_for( OverlaySettings $look, AlertEvent $event, string $text ): string {
		self::clean_hourly();

		$tts  = $look->to_array()['tts'];
		$text = mb_substr( trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' ), 0, self::MAX_CHARS );

		if ( ! $tts['enabled'] || '' === $text || ! $this->settings->available() ) {
			return '';
		}

		if ( null !== $this->live && ! ( $this->live )( $event->creator_id ) ) {
			return '';
		}

		if ( $tts['min_amount'] > 0 && ( null === $event->line_total || (float) $event->line_total < $tts['min_amount'] ) ) {
			return '';
		}

		try {
			return $this->file( TtsVoices::clean( $tts['voice'] ), $text, 0 === $event->id ? $event->creator_id : null );
		} catch ( Throwable $e ) {
			$this->logger->error( 'An alert could not be read out.', array( 'error' => $e::class ) );

			return '';
		}
	}

	/**
	 * The URL of a stored read-out, made first when needed; '' when it
	 * cannot be made.
	 *
	 * @param string   $voice Voice.
	 * @param string   $text  Text.
	 * @param int|null $test  The creator of a test alert; null for a purchase.
	 */
	private function file( string $voice, string $text, ?int $test ): string {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		// Keyed with the site's secret, so a file cannot be found by guessing an alert's text (30.15 review).
		$name = hash_hmac( 'sha256', $voice . '|' . $text, wp_salt( 'nonce' ) ) . '.mp3';
		$dir  = trailingslashit( $uploads['basedir'] ) . self::FOLDER;
		$path = $dir . '/' . $name;
		$url  = trailingslashit( $uploads['baseurl'] ) . self::FOLDER . '/' . $name;

		if ( is_file( $path ) ) {
			return $url;
		}

		global $wpdb;

		// One site-wide, non-waiting lock protects the shared allowance, test
		// counters and cache misses, including across PHP workers/servers.
		$lock = 'merchboard_tts_' . md5( DB_NAME . '|' . $wpdb->options );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A database mutex cannot be cached.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return '';
		}

		try {
			// Another worker may have filled this file or changed the counters
			// since this request populated its WordPress option cache.
			if ( is_file( $path ) ) {
				return $url;
			}
			foreach ( array( TtsSettings::USAGE_OPTION, TtsSettings::OPTION ) as $option ) {
				wp_cache_delete( $option, 'options' );
			}
			wp_cache_delete( 'notoptions', 'options' );
			if ( null !== $test ) {
				wp_cache_delete( '_transient_merchboard_tts_tests_' . $test, 'options' );
				wp_cache_delete( '_transient_timeout_merchboard_tts_tests_' . $test, 'options' );
			}

			return $this->generate( $voice, $text, $test, $dir, $path, $url );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release the connection's mutex.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Makes an uncached read-out while holding the site's TTS lock.
	 *
	 * @param string   $voice Voice.
	 * @param string   $text  Text.
	 * @param int|null $test  Test creator.
	 * @param string   $dir   Cache directory.
	 * @param string   $path  Cache file.
	 * @param string   $url   Cache URL.
	 */
	private function generate( string $voice, string $text, ?int $test, string $dir, string $path, string $url ): string {
		$chars = mb_strlen( $text );

		if ( ! $this->settings->allows( $chars ) || $this->spent >= self::BUDGET || false !== get_transient( 'merchboard_tts_paused' ) || ( null !== $test && ! self::test_allowed( $test ) ) ) {
			return '';
		}

		// Reserve durably before contacting the paid provider. A crashed or
		// ambiguous request keeps its reservation rather than overspending.
		$previous = get_option( TtsSettings::USAGE_OPTION, array() );
		if ( ! $this->settings->count( $chars ) ) {
			return '';
		}

		$refused      = false;
		$start        = microtime( true );
		$audio        = $this->synthesize( $voice, $text, $refused );
		$this->spent += microtime( true ) - $start;

		if ( null === $audio ) {
			if ( $refused ) {
				update_option( TtsSettings::USAGE_OPTION, $previous, false );
			}
			set_transient( 'merchboard_tts_paused', 1, self::PAUSE );
			return '';
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		// No listing of the folder on servers that list folders.
		if ( ! is_file( $dir . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The plugin's own folder.
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}

		// Publish only complete files: cache hits do not take the generation lock.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A new file in the plugin's own uploads folder.
		if ( false === file_put_contents( $path . '.tmp', $audio ) ) {
			wp_delete_file( $path . '.tmp' );
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic publication in the same directory.
		if ( ! rename( $path . '.tmp', $path ) ) {
			wp_delete_file( $path . '.tmp' );
			return '';
		}

		return $url;
	}

	/**
	 * The MP3 of a text, or null.
	 *
	 * @param string $voice Voice.
	 * @param string $text  Text.
	 * @param bool   $refused Whether the provider definitively rejected the request.
	 */
	private function synthesize( string $voice, string $text, bool &$refused ): ?string {
		$key = $this->settings->key();

		if ( null === $key ) {
			$refused = true;
			return null;
		}

		$answer = ( $this->post )(
			self::ENDPOINT,
			(string) wp_json_encode(
				array(
					'input'       => array( 'text' => $text ),
					'voice'       => array(
						'languageCode' => TtsVoices::VOICES[ $voice ] ?? 'da-DK',
						'name'         => $voice,
					),
					'audioConfig' => array( 'audioEncoding' => 'MP3' ),
				)
			),
			$key
		);

		if ( null === $answer || 200 !== $answer[0] ) {
			$refused = null !== $answer && $answer[0] >= 400 && $answer[0] < 500;
			$this->logger->warning( 'Google Text-to-Speech refused a read-out.', array( 'status' => null === $answer ? 0 : $answer[0] ) );

			return null;
		}

		$data  = json_decode( $answer[1], true );
		$audio = is_array( $data ) && is_string( $data['audioContent'] ?? null ) ? base64_decode( $data['audioContent'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Google sends the MP3 as base64.

		return false === $audio || '' === $audio ? null : $audio;
	}

	/**
	 * Whether a creator's test alerts may make another read-out this hour;
	 * counts it when they may.
	 *
	 * @param int $creator_id Creator.
	 */
	private static function test_allowed( int $creator_id ): bool {
		$key   = 'merchboard_tts_tests_' . $creator_id;
		$count = get_transient( $key );
		$count = is_numeric( $count ) ? max( 0, (int) $count ) : 0;

		if ( $count >= self::TESTS_PER_HOUR ) {
			return false;
		}

		return set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Deletes old read-outs once an hour, also while read-out is off (ADR-089:
	 * the files hold customers' usernames).
	 */
	private static function clean_hourly(): void {
		if ( false !== get_transient( 'merchboard_tts_cleaned' ) ) {
			return;
		}

		set_transient( 'merchboard_tts_cleaned', 1, HOUR_IN_SECONDS );
		$uploads = wp_upload_dir( null, false );

		if ( empty( $uploads['error'] ) ) {
			self::clean( trailingslashit( $uploads['basedir'] ) . self::FOLDER );
		}
	}

	/**
	 * Deletes read-outs older than KEEP.
	 *
	 * @param string $dir The folder.
	 */
	private static function clean( string $dir ): void {
		foreach ( self::files( $dir ) as $file ) {
			$time = filemtime( $file );

			if ( false !== $time && $time < time() - self::KEEP ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * The read-outs in a folder.
	 *
	 * @param string $dir The folder.
	 * @return list<string>
	 */
	private static function files( string $dir ): array {
		$files = glob( $dir . '/*.mp3' );

		return false === $files ? array() : $files;
	}

	/**
	 * Deletes every read-out and the folder (uninstall).
	 */
	public static function remove_all(): void {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . self::FOLDER;

		foreach ( self::files( $dir ) as $file ) {
			wp_delete_file( $file );
		}

		if ( is_file( $dir . '/index.php' ) ) {
			wp_delete_file( $dir . '/index.php' );
		}

		if ( is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The plugin's own empty folder.
			rmdir( $dir );
		}
	}
}
