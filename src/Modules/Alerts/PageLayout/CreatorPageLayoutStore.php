<?php
/**
 * Storage of the creator page's setup.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Alerts\PageLayout;

use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Modules\Alerts\AlertSettingsException;
use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;

defined( 'ABSPATH' ) || exit;

/**
 * The setup of the creator's overlay settings page (step 25.9.6) in one
 * option, not autoloaded and read past WordPress' option cache; the default
 * setup while there is none. One for every creator; a change is audited
 * (`alert_creator_page_changed`).
 */
final class CreatorPageLayoutStore {

	/**
	 * The option.
	 */
	public const OPTION = 'merchboard_alert_creator_page';

	/**
	 * Constructor.
	 *
	 * @param AuditTrail $audit Audit log.
	 */
	public function __construct( private AuditTrail $audit ) {
	}

	/**
	 * The setup.
	 */
	public function get(): CreatorPageLayout {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$stored = get_option( self::OPTION, null );

		return is_array( $stored ) ? CreatorPageLayout::from_array( $stored ) : CreatorPageLayout::defaults();
	}

	/**
	 * Saves a setup (normalized); audited when it changes.
	 *
	 * @param mixed $values Submitted setup.
	 * @throws DatabaseException When it cannot be stored (or AlertSettingsException without the alerts capability).
	 */
	public function save( mixed $values ): CreatorPageLayout {
		self::authorize();

		$layout = CreatorPageLayout::from_array( $values );

		if ( $layout->to_array() === $this->get()->to_array() ) {
			return $layout;
		}

		update_option( self::OPTION, $layout->to_array(), false );

		if ( $this->get()->to_array() !== $layout->to_array() ) {
			throw new DatabaseException( 'Could not save the creator page setup.' );
		}

		$this->audit->record( AuditAction::AlertCreatorPageChanged, null, array( 'reset' => false ) );

		return $layout;
	}

	/**
	 * Goes back to the default setup; audited when there was another.
	 *
	 * @throws DatabaseException When it cannot be stored.
	 * @throws AlertSettingsException When the current user lacks the alerts capability.
	 */
	public function reset(): CreatorPageLayout {
		self::authorize();

		wp_cache_delete( self::OPTION, 'options' );

		if ( null !== get_option( self::OPTION, null ) ) {
			delete_option( self::OPTION );
			$this->audit->record( AuditAction::AlertCreatorPageChanged, null, array( 'reset' => true ) );
		}

		return CreatorPageLayout::defaults();
	}

	/**
	 * Requires the alerts capability (independent of the UI).
	 *
	 * @throws AlertSettingsException When the current user lacks it.
	 */
	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_ALERTS ) ) {
			throw new AlertSettingsException( AlertSettingsException::NOT_AUTHORIZED );
		}
	}
}
