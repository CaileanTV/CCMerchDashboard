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
 * A sound file in the Media Library: a creator's own upload or a sound of
 * the administrator's library.
 */
final readonly class AlertSound {

	/**
	 * Constructor.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $url           File URL on this site.
	 * @param string $name          File name.
	 * @param int    $seconds       Length in whole seconds.
	 * @param bool   $library       Whether it is a library sound.
	 */
	public function __construct( public int $attachment_id, public string $url, public string $name, public int $seconds, public bool $library = false ) {
	}

	/**
	 * Data for the dashboard; `library_id` is the library sound's id, 0 for
	 * the creator's own file.
	 *
	 * @return array{url: string, name: string, seconds: int, library_id: int}
	 */
	public function to_array(): array {
		return array(
			'url'        => $this->url,
			'name'       => $this->name,
			'seconds'    => $this->seconds,
			'library_id' => $this->library ? $this->attachment_id : 0,
		);
	}
}
