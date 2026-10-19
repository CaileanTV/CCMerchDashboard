<?php
/**
 * An uploaded alert image.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Images;

defined( 'ABSPATH' ) || exit;

/**
 * An image file in the Media Library that fills alert text (phase 25, step
 * 6.10): a creator's own upload or an image of the administrator's library.
 */
final readonly class AlertImage {

	/**
	 * Constructor.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $url           File URL on this site.
	 * @param string $name          Name (the library's name, else the file name).
	 * @param int    $width         Width in pixels.
	 * @param int    $height        Height in pixels.
	 * @param bool   $library       Whether it is a library image.
	 */
	public function __construct( public int $attachment_id, public string $url, public string $name, public int $width, public int $height, public bool $library = false ) {
	}

	/**
	 * Data for the dashboard.
	 *
	 * @return array{id: int, url: string, name: string, width: int, height: int}
	 */
	public function to_array(): array {
		return array(
			'id'     => $this->attachment_id,
			'url'    => $this->url,
			'name'   => $this->name,
			'width'  => $this->width,
			'height' => $this->height,
		);
	}
}
