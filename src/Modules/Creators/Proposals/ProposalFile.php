<?php
/**
 * A proposed image waiting for approval.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Proposals;

defined( 'ABSPATH' ) || exit;

/**
 * A file in the non-public proposals folder (ProposalFiles): a random name
 * of 32 hex characters with its extension, its type and dimensions.
 */
final class ProposalFile {

	/**
	 * A stored file name.
	 */
	public const NAME_PATTERN = '/^[a-f0-9]{32}\.(jpg|png|webp)$/';

	/**
	 * Constructor.
	 *
	 * @param string $file   File name (NAME_PATTERN).
	 * @param string $mime   MIME type.
	 * @param int    $width  Width in pixels.
	 * @param int    $height Height in pixels.
	 */
	public function __construct(
		public readonly string $file,
		public readonly string $mime,
		public readonly int $width,
		public readonly int $height
	) {
	}

	/**
	 * The value stored on the proposal.
	 */
	public function to_json(): string {
		return (string) wp_json_encode(
			array(
				'file'   => $this->file,
				'mime'   => $this->mime,
				'width'  => $this->width,
				'height' => $this->height,
			)
		);
	}

	/**
	 * A stored value, or null when it is not a valid file value.
	 *
	 * @param string $json Stored value.
	 */
	public static function from_json( string $json ): ?self {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || ! is_string( $data['file'] ?? null ) || 1 !== preg_match( self::NAME_PATTERN, $data['file'] ) || ! is_string( $data['mime'] ?? null ) || ! is_int( $data['width'] ?? null ) || ! is_int( $data['height'] ?? null ) ) {
			return null;
		}

		return new self( $data['file'], $data['mime'], $data['width'], $data['height'] );
	}
}
