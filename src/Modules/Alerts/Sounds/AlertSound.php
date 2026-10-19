<?php
/**
 * An uploaded alert sound.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\Sounds;

defined( 'ABSPATH' ) || exit;

/**
 * A sound file in the Media Library that belongs to a creator.
 */
final readonly class AlertSound {

	/**
	 * Constructor.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $url           File URL on this site.
	 * @param string $name          File name.
	 * @param int    $seconds       Length in whole seconds.
	 */
	public function __construct( public int $attachment_id, public string $url, public string $name, public int $seconds ) {
	}

	/**
	 * Data for the dashboard.
	 *
	 * @return array{url: string, name: string, seconds: int}
	 */
	public function to_array(): array {
		return array(
			'url'     => $this->url,
			'name'    => $this->name,
			'seconds' => $this->seconds,
		);
	}
}
