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
	 * POSTs JSON: URL, body → [status, body] or null on a network error.
	 *
	 * @var Closure(string, string): (array{0: int, 1: string}|null)
	 */
	private Closure $post;

	/**
	 * Constructor.
	 *
	 * @param TtsSettings  $settings The switch, key and limit.
	 * @param Logger       $logger   Logger.
	 * @param Closure|null $post     HTTP (tests); defaults to wp_remote_post() with a 5 s timeout.
	 * @phpstan-param (Closure(string, string): (array{0: int, 1: string}|null))|null $post
	 */
	public function __construct( private TtsSettings $settings, private Logger $logger, ?Closure $post = null ) {
		$this->post = $post ?? static function ( string $url, string $body ): ?array {
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => 5,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => $body,
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
		$tts  = $look->to_array()['tts'];
		$text = mb_substr( trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' ), 0, self::MAX_CHARS );

		if ( ! $tts['enabled'] || '' === $text || ! $this->settings->available() ) {
			return '';
		}

		if ( $tts['min_amount'] > 0 && ( null === $event->line_total || (float) $event->line_total < $tts['min_amount'] ) ) {
			return '';
		}

		try {
			return $this->file( TtsVoices::clean( $tts['voice'] ), $text );
		} catch ( Throwable $e ) {
			$this->logger->error( 'An alert could not be read out.', array( 'error' => $e::class ) );

			return '';
		}
	}

	/**
	 * The URL of a stored read-out, made first when needed; '' when it
	 * cannot be made.
	 *
	 * @param string $voice Voice.
	 * @param string $text  Text.
	 */
	private function file( string $voice, string $text ): string {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$name = hash( 'sha256', $voice . '|' . $text ) . '.mp3';
		$dir  = trailingslashit( $uploads['basedir'] ) . self::FOLDER;
		$path = $dir . '/' . $name;
		$url  = trailingslashit( $uploads['baseurl'] ) . self::FOLDER . '/' . $name;

		if ( is_file( $path ) ) {
			return $url;
		}

		$chars = mb_strlen( $text );

		if ( ! $this->settings->allows( $chars ) ) {
			return '';
		}

		$audio = $this->synthesize( $voice, $text );

		if ( null === $audio || ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$this->settings->count( $chars );
		self::clean( $dir );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A new file in the plugin's own uploads folder.
		return false === file_put_contents( $path, $audio ) ? '' : $url;
	}

	/**
	 * The MP3 of a text, or null.
	 *
	 * @param string $voice Voice.
	 * @param string $text  Text.
	 */
	private function synthesize( string $voice, string $text ): ?string {
		$key = $this->settings->key();

		if ( null === $key ) {
			return null;
		}

		$answer = ( $this->post )(
			add_query_arg( 'key', rawurlencode( $key ), self::ENDPOINT ),
			(string) wp_json_encode(
				array(
					'input'       => array( 'text' => $text ),
					'voice'       => array(
						'languageCode' => TtsVoices::VOICES[ $voice ] ?? 'da-DK',
						'name'         => $voice,
					),
					'audioConfig' => array( 'audioEncoding' => 'MP3' ),
				)
			)
		);

		if ( null === $answer || 200 !== $answer[0] ) {
			$this->logger->warning( 'Google Text-to-Speech refused a read-out.', array( 'status' => null === $answer ? 0 : $answer[0] ) );

			return null;
		}

		$data  = json_decode( $answer[1], true );
		$audio = is_array( $data ) && is_string( $data['audioContent'] ?? null ) ? base64_decode( $data['audioContent'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Google sends the MP3 as base64.

		return false === $audio || '' === $audio ? null : $audio;
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

		if ( is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The plugin's own empty folder.
			rmdir( $dir );
		}
	}
}
