<?php
/**
 * Settings use cases.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Settings;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditTrail;
use MerchBoard\Core\Database\DatabaseException;
use MerchBoard\Core\Database\Transaction;
use MerchBoard\Core\Security\Capabilities;
use MerchBoard\Core\Settings\Settings;
use MerchBoard\Modules\Dashboard\Branding\LogoAttachment;

defined( 'ABSPATH' ) || exit;

/**
 * Changes plugin settings (ADR-025, ADR-026).
 *
 * - Authorization: `merchboard_manage_settings`, independent of the UI.
 * - Enabling permanent deletion additionally requires the typed confirmation
 *   word, checked server-side; disabling never needs it (always safe).
 * - The dashboard slug is validated server-side (format, reserved paths,
 *   conflicts with existing content).
 * - The logo is only an attachment id, validated server-side (ADR-042).
 * - Every change writes a `settings_updated` audit record. The record is
 *   written first and the option last in one transaction: if the option
 *   cannot be saved, the audit record is rolled back (fail closed without a
 *   transactional engine, ADR-024).
 */
final class SettingsService {

	/**
	 * Word an administrator must type to enable permanent deletion.
	 */
	public const DELETION_CONFIRMATION = 'DELETE';

	/**
	 * Constructor.
	 *
	 * @param AuditTrail       $audit       Audit trail.
	 * @param Transaction      $transaction Database transaction.
	 * @param SlugAvailability $slugs       Dashboard slug conflict check.
	 * @param LogoAttachment   $logos       Logo attachment validation (ADR-042).
	 */
	public function __construct( private AuditTrail $audit, private Transaction $transaction, private SlugAvailability $slugs, private LogoAttachment $logos = new LogoAttachment() ) {
	}

	/**
	 * Sets the Merch Dashboard logo to a Media Library attachment, or back to
	 * the built-in default with 0 (ADR-042). Only the attachment id is
	 * stored. The attachment must pass LogoAttachment (PNG/JPEG/WebP, no SVG,
	 * size bounds, same origin), checked here, server-side.
	 *
	 * @param int $attachment_id Attachment id; 0 = default logo.
	 * @return bool Whether the setting changed.
	 * @throws SettingsException When not authorized or the attachment is not usable.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function set_logo_attachment_id( int $attachment_id ): bool {
		$this->authorize();

		$settings = Settings::all();
		$previous = $settings[ Settings::LOGO_ATTACHMENT_ID ];
		$id       = max( 0, $attachment_id );

		if ( $id > 0 ) {
			$this->assert_usable_logo( $id );
		}

		if ( $previous === $id ) {
			return false;
		}

		$settings[ Settings::LOGO_ATTACHMENT_ID ] = $id;

		$this->save(
			$settings,
			array(
				'setting'        => Settings::LOGO_ATTACHMENT_ID,
				'previous_value' => $previous,
				'value'          => $id,
			)
		);

		return true;
	}

	/**
	 * Requires an attachment that may be the logo.
	 *
	 * @param int $id Attachment id.
	 * @throws SettingsException When it may not.
	 */
	private function assert_usable_logo( int $id ): void {
		$reason = match ( $this->logos->problem( $id ) ) {
			null                            => null,
			LogoAttachment::WRONG_TYPE      => SettingsException::LOGO_WRONG_TYPE,
			LogoAttachment::WRONG_SIZE      => SettingsException::LOGO_WRONG_SIZE,
			LogoAttachment::FOREIGN_URL     => SettingsException::LOGO_FOREIGN_URL,
			default                         => SettingsException::LOGO_NOT_FOUND,
		};

		if ( null !== $reason ) {
			throw new SettingsException( $reason );
		}
	}

	/**
	 * Changes the URL slug of the creator dashboard. The slug must be
	 * well-formed, not reserved (Settings::valid_slug()) and not used by the
	 * site's content or rewrite bases (SlugAvailability). After the change the
	 * stored rewrite rules are invalidated (`merchboard_dashboard_slug_changed`),
	 * so the new URL works and the old one stops working from the next request.
	 *
	 * @param string $slug New slug (surrounding spaces and slashes are ignored; case-insensitive).
	 * @return bool Whether the setting changed.
	 * @throws SettingsException When not authorized or the slug is invalid or in use.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function set_dashboard_slug( string $slug ): bool {
		$this->authorize();

		$slug     = strtolower( trim( $slug, " \t\n\r\0\x0B/" ) );
		$settings = Settings::all();
		$previous = $settings[ Settings::DASHBOARD_SLUG ];

		$this->assert_valid_slug( $slug );

		if ( $previous === $slug ) {
			return false;
		}

		$this->assert_slug_available( $slug );

		$settings[ Settings::DASHBOARD_SLUG ] = $slug;

		$this->save(
			$settings,
			array(
				'setting'        => Settings::DASHBOARD_SLUG,
				'previous_value' => $previous,
				'value'          => $slug,
			)
		);

		/**
		 * Fires after the dashboard slug changed (the Dashboard module then
		 * invalidates the stored rewrite rules).
		 *
		 * @param string $slug     New slug.
		 * @param string $previous Previous slug.
		 */
		do_action( 'merchboard_dashboard_slug_changed', $slug, $previous );

		return true;
	}

	/**
	 * Enables or disables "delete all data on uninstall".
	 *
	 * @param bool   $enabled      New value.
	 * @param string $confirmation Typed confirmation (required to enable).
	 * @return bool Whether the setting changed.
	 * @throws SettingsException When not authorized or the confirmation is missing.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	public function set_delete_data_on_uninstall( bool $enabled, string $confirmation = '' ): bool {
		$this->authorize();

		$settings = Settings::all();

		if ( $settings[ Settings::DELETE_DATA_ON_UNINSTALL ] === $enabled ) {
			return false;
		}

		if ( $enabled ) {
			$this->assert_confirmed( $confirmation );
		}

		$settings[ Settings::DELETE_DATA_ON_UNINSTALL ] = $enabled;

		$this->save(
			$settings,
			array(
				'setting' => Settings::DELETE_DATA_ON_UNINSTALL,
				'enabled' => $enabled,
			)
		);

		return true;
	}

	/**
	 * Writes the audit record first and the option last in one transaction.
	 *
	 * @param array<string, mixed> $settings New settings.
	 * @param array<string, mixed> $context  Audit context.
	 * @throws DatabaseException When the change cannot be stored (nothing is kept).
	 */
	private function save( array $settings, array $context ): void {
		$this->transaction->run(
			function () use ( $settings, $context ): void {
				$this->audit->record( AuditAction::SettingsUpdated, null, $context );

				if ( ! Settings::save( $settings ) ) {
					throw new DatabaseException( 'Could not save settings.' );
				}
			}
		);
	}

	/**
	 * Requires the settings capability.
	 *
	 * @throws SettingsException When the current user lacks it.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			throw new SettingsException( SettingsException::NOT_AUTHORIZED );
		}
	}

	/**
	 * Requires a well-formed, non-reserved slug.
	 *
	 * @param string $slug Normalized slug.
	 * @throws SettingsException When it is not.
	 */
	private function assert_valid_slug( string $slug ): void {
		if ( ! Settings::valid_slug( $slug ) ) {
			throw new SettingsException( SettingsException::INVALID_SLUG );
		}
	}

	/**
	 * Requires a slug the site does not use yet.
	 *
	 * @param string $slug Normalized slug.
	 * @throws SettingsException When it is in use.
	 */
	private function assert_slug_available( string $slug ): void {
		if ( ! $this->slugs->is_available( $slug ) ) {
			throw new SettingsException( SettingsException::SLUG_IN_USE );
		}
	}

	/**
	 * Requires the typed confirmation word.
	 *
	 * @param string $confirmation Typed confirmation.
	 * @throws SettingsException When it does not match.
	 */
	private function assert_confirmed( string $confirmation ): void {
		if ( self::DELETION_CONFIRMATION !== trim( $confirmation ) ) {
			throw new SettingsException( SettingsException::CONFIRMATION_REQUIRED );
		}
	}
}
