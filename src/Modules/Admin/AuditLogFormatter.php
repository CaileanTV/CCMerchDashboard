<?php
/**
 * Human-readable audit records.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Admin;

use MerchBoard\Core\Audit\AuditAction;
use MerchBoard\Core\Audit\AuditEntry;
use MerchBoard\Modules\Creators\CreatorStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Turns audit records into plain text for the admin screens (phase 11).
 *
 * Context is never dumped as raw JSON: known keys (the shapes AuditTrail
 * callers write, docs/DATABASE.md) get a readable line; unknown keys are shown
 * only as "key: value" for short scalar values, and nested data as a
 * placeholder. Returned strings are plain text: callers escape them.
 */
final class AuditLogFormatter {

	/**
	 * Longest scalar value shown for an unknown context key.
	 */
	private const MAX_VALUE_LENGTH = 100;

	/**
	 * Label of an action.
	 *
	 * @param AuditAction $action Action.
	 */
	public static function action_label( AuditAction $action ): string {
		return match ( $action ) {
			AuditAction::CreatorCreated      => __( 'Creator created', 'merchboard' ),
			AuditAction::CreatorUpdated      => __( 'Creator updated', 'merchboard' ),
			AuditAction::CreatorActivated    => __( 'Creator activated', 'merchboard' ),
			AuditAction::CreatorDeactivated  => __( 'Creator deactivated', 'merchboard' ),
			AuditAction::CreatorUserLinked   => __( 'WordPress user linked', 'merchboard' ),
			AuditAction::CreatorUserUnlinked => __( 'WordPress user unlinked', 'merchboard' ),
			AuditAction::SettingsUpdated     => __( 'Settings changed', 'merchboard' ),
			AuditAction::ProductCreatorLinked   => __( 'Product linked', 'merchboard' ),
			AuditAction::ProductCreatorUnlinked => __( 'Product unlinked', 'merchboard' ),
			AuditAction::CreatorCategoryLinked   => __( 'Product category mapped', 'merchboard' ),
			AuditAction::CreatorCategoryUnlinked => __( 'Product category unmapped', 'merchboard' ),
			AuditAction::CategorySyncCompleted   => __( 'Category reconciliation completed', 'merchboard' ),
		};
	}

	/**
	 * Readable details of a record's context.
	 *
	 * @param AuditEntry         $entry       Record.
	 * @param array<int, string> $user_labels "Display Name (login)" by user id (existing users only).
	 * @return list<string> Plain-text lines.
	 */
	public static function details( AuditEntry $entry, array $user_labels ): array {
		$lines = array();

		foreach ( $entry->context as $key => $value ) {
			$lines[] = self::line( (string) $key, $value, $user_labels );
		}

		return $lines;
	}

	/**
	 * User ids mentioned in a record's context (to look up labels in bulk).
	 *
	 * @param AuditEntry $entry Record.
	 * @return list<int>
	 */
	public static function context_user_ids( AuditEntry $entry ): array {
		$user_id = $entry->context['user_id'] ?? null;

		return is_int( $user_id ) && $user_id > 0 ? array( $user_id ) : array();
	}

	/**
	 * One context line.
	 *
	 * @param string             $key         Context key.
	 * @param mixed              $value       Context value.
	 * @param array<int, string> $user_labels User labels.
	 */
	private static function line( string $key, mixed $value, array $user_labels ): string {
		switch ( $key ) {
			case 'user_id':
				return null === $value
					? __( 'WordPress user: none', 'merchboard' )
					/* translators: %s: user label or "#id". */
					: sprintf( __( 'WordPress user: %s', 'merchboard' ), self::user( $value, $user_labels ) );
			case 'status':
				/* translators: %s: creator status. */
				return sprintf( __( 'Status: %s', 'merchboard' ), self::status( $value ) );
			case 'previous_status':
				/* translators: %s: creator status. */
				return sprintf( __( 'Previous status: %s', 'merchboard' ), self::status( $value ) );
			case 'changed_fields':
				/* translators: %s: comma-separated field names. */
				return sprintf( __( 'Changed: %s', 'merchboard' ), self::fields( $value ) );
			case 'reason':
				return 'user_deleted' === $value
					? __( 'Reason: the WordPress user was deleted', 'merchboard' )
					/* translators: %s: reason code. */
					: sprintf( __( 'Reason: %s', 'merchboard' ), self::scalar( $value ) );
			case 'setting':
				return match ( $value ) {
					'delete_data_on_uninstall' => __( 'Setting: delete all data when the plugin is deleted', 'merchboard' ),
					'dashboard_slug'           => __( 'Setting: creator dashboard URL slug', 'merchboard' ),
					/* translators: %s: setting name. */
					default                    => sprintf( __( 'Setting: %s', 'merchboard' ), self::scalar( $value ) ),
				};
			case 'previous_value':
				/* translators: %s: previous setting value. */
				return sprintf( __( 'Previous value: %s', 'merchboard' ), self::scalar( $value ) );
			case 'value':
				/* translators: %s: new setting value. */
				return sprintf( __( 'New value: %s', 'merchboard' ), self::scalar( $value ) );
			case 'enabled':
				return true === $value ? __( 'New value: enabled', 'merchboard' ) : __( 'New value: disabled', 'merchboard' );
		}

		return sanitize_key( $key ) . ': ' . self::scalar( $value );
	}

	/**
	 * A user reference: its label while the user exists, else "#id (deleted)".
	 *
	 * @param mixed              $value       User id.
	 * @param array<int, string> $user_labels User labels.
	 */
	private static function user( mixed $value, array $user_labels ): string {
		if ( ! is_int( $value ) ) {
			return self::scalar( $value );
		}

		/* translators: %d: user id. */
		return $user_labels[ $value ] ?? sprintf( __( '#%d (no longer exists)', 'merchboard' ), $value );
	}

	/**
	 * A status value.
	 *
	 * @param mixed $value Status.
	 */
	private static function status( mixed $value ): string {
		$status = is_string( $value ) ? CreatorStatus::tryFrom( $value ) : null;

		if ( null === $status ) {
			return self::scalar( $value );
		}

		return CreatorStatus::Active === $status ? __( 'Active', 'merchboard' ) : __( 'Inactive', 'merchboard' );
	}

	/**
	 * A list of field names.
	 *
	 * @param mixed $value Field names.
	 */
	private static function fields( mixed $value ): string {
		if ( ! is_array( $value ) ) {
			return self::scalar( $value );
		}

		$names = array_map( static fn( mixed $field ): string => is_string( $field ) ? sanitize_key( $field ) : '…', $value );

		return implode(
			', ',
			array_map(
				static fn( string $field ): string => match ( $field ) {
					'display_name' => __( 'display name', 'merchboard' ),
					'bio'          => __( 'bio', 'merchboard' ),
					'social_links' => __( 'social links', 'merchboard' ),
					default        => $field,
				},
				$names
			)
		);
	}

	/**
	 * A short scalar value; nested data is not shown.
	 *
	 * @param mixed $value Value.
	 */
	private static function scalar( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( null === $value ) {
			return '—';
		}

		if ( ! is_scalar( $value ) ) {
			return '…';
		}

		$text = (string) $value;

		return mb_strlen( $text ) > self::MAX_VALUE_LENGTH ? mb_substr( $text, 0, self::MAX_VALUE_LENGTH ) . '…' : $text;
	}
}
