<?php
/**
 * The notifications the administrator gets.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications\Catalog;

use Closure;
use MerchBoard\Modules\Creators\Proposals\ProposalField;
use MerchBoard\Modules\Creators\Proposals\ProposalLabels;
use MerchBoard\Modules\Notifications\EmailMessage;
use MerchBoard\Modules\Notifications\NotificationData;
use MerchBoard\Modules\Notifications\NotificationType;

defined( 'ABSPATH' ) || exit;

/**
 * The administrator's notification types (ADR-079): e-mail only, to the
 * site's admin e-mail (the owner's choice). The link is the slug of the
 * admin page where the administrator acts.
 */
final class AdminTypes {

	/**
	 * All administrator types.
	 *
	 * @return list<NotificationType>
	 */
	public static function all(): array {
		return array(
			self::payout_requested(),
			self::proposal_submitted(),
			self::product_removal_requested(),
			self::order_decision(),
			self::sync_failed(),
			self::reconciliation_failed(),
			self::adjustment_failed(),
			self::gift_card_coupon(),
			self::code_reset(),
			self::payout_cancelled(),
		);
	}

	/**
	 * An administrator type.
	 *
	 * @param string               $key      Key.
	 * @param Closure              $label    Admin name.
	 * @param Closure              $describe When it is sent.
	 * @param bool                 $email    On by default.
	 * @param string               $page     Admin page slug.
	 * @param array<string, mixed> $sample   Sample data.
	 * @param Closure              $text     One-line summary (the log and preview).
	 * @param Closure              $message  E-mail.
	 *
	 * @phpstan-param Closure(): string $label
	 * @phpstan-param Closure(): string $describe
	 * @phpstan-param Closure(NotificationData): string $text
	 * @phpstan-param Closure(NotificationData): EmailMessage $message
	 */
	private static function type( string $key, Closure $label, Closure $describe, bool $email, string $page, array $sample, Closure $text, Closure $message ): NotificationType {
		return new NotificationType( 'admin_' . $key, NotificationType::ADMIN, 'admin', $label, $describe, $email, false, false, $page, $sample, $text, $message );
	}

	/**
	 * The method of a payout in words.
	 *
	 * @param NotificationData $d Data with `method`.
	 */
	private static function method( NotificationData $d ): string {
		return match ( $d->text( 'method' ) ) {
			'paypal'    => __( 'PayPal', 'merchboard' ),
			'gift_card' => __( 'Gift card', 'merchboard' ),
			'bank'      => __( 'Bank transfer', 'merchboard' ),
			default     => '',
		};
	}

	/**
	 * The names of a submission's fields (`field`: keys separated by commas).
	 *
	 * @param NotificationData $d Data with `field`.
	 */
	private static function fields( NotificationData $d ): string {
		$names = array();

		foreach ( explode( ',', $d->text( 'field' ) ) as $key ) {
			$field   = ProposalField::tryFrom( trim( $key ) );
			$names[] = null === $field ? trim( $key ) : ProposalLabels::field( $field );
		}

		return implode( ', ', array_filter( $names ) );
	}

	/**
	 * A plain administrator e-mail: no greeting, the facts and a button.
	 *
	 * @param string                            $subject Subject.
	 * @param string                            $heading Heading.
	 * @param string                            $text    Paragraph.
	 * @param list<array{0: string, 1: string}> $details Info box.
	 * @param string                            $button  Button label.
	 */
	private static function message( string $subject, string $heading, string $text, array $details, string $button ): EmailMessage {
		return new EmailMessage(
			$subject,
			$heading,
			array( $text ),
			array_values( array_filter( $details, static fn( array $line ): bool => '' !== $line[1] ) ),
			null,
			null,
			$button,
			'',
			__( 'This message was sent by Merch Dashboard.', 'merchboard' ),
			false
		);
	}

	/**
	 * A creator requested a payout.
	 */
	private static function payout_requested(): NotificationType {
		return self::type(
			'payout_requested',
			static fn(): string => __( 'New payout request', 'merchboard' ),
			static fn(): string => __( 'When a creator requests a payout that must be processed.', 'merchboard' ),
			true,
			'merchboard-payouts',
			array(
				'creator'  => 'Nova',
				'amount'   => '1250.00',
				'currency' => 'DKK',
				'method'   => 'bank',
			),
			/* translators: 1: creator name, 2: amount. */
			static fn( NotificationData $d ): string => sprintf( __( '%1$s requested a payout of %2$s.', 'merchboard' ), $d->text( 'creator' ), $d->money( 'amount' ) ),
			static fn( NotificationData $d ): EmailMessage => self::message(
				/* translators: %s: creator name. */
				sprintf( __( 'New payout request from %s', 'merchboard' ), $d->text( 'creator' ) ),
				__( 'New payout request', 'merchboard' ),
				__( 'A creator requested a payout. It waits for you under Payouts.', 'merchboard' ),
				array(
					array( __( 'Creator', 'merchboard' ), $d->text( 'creator' ) ),
					array( __( 'Amount', 'merchboard' ), $d->money( 'amount' ) ),
					array( __( 'Method', 'merchboard' ), self::method( $d ) ),
				),
				__( 'Open Payouts', 'merchboard' )
			)
		);
	}

	/**
	 * A creator asked to have a legacy product removed (ADR-083).
	 */
	private static function product_removal_requested(): NotificationType {
		return self::type(
			'product_removal_requested',
			static fn(): string => __( 'Product removal requested', 'merchboard' ),
			static fn(): string => __( 'When a creator asks to have a product removed that was not made in the product designer.', 'merchboard' ),
			true,
			'merchboard-product-removals',
			array(
				'creator' => 'Nova',
				'product' => 'Logo Hoodie',
			),
			/* translators: 1: creator name, 2: product name. */
			static fn( NotificationData $d ): string => sprintf( __( '%1$s asked to have %2$s removed.', 'merchboard' ), $d->text( 'creator' ), $d->text( 'product' ) ),
			static fn( NotificationData $d ): EmailMessage => self::message(
				/* translators: %s: creator name. */
				sprintf( __( 'Product removal requested by %s', 'merchboard' ), $d->text( 'creator' ) ),
				__( 'Product removal requested', 'merchboard' ),
				__( 'A creator asked to have a product removed. Nothing happens to the product until you have dealt with it in WooCommerce.', 'merchboard' ),
				array(
					array( __( 'Creator', 'merchboard' ), $d->text( 'creator' ) ),
					array( __( 'Product', 'merchboard' ), $d->text( 'product' ) ),
				),
				__( 'Open Removal requests', 'merchboard' )
			)
		);
	}

	/**
	 * A creator proposed a change to their category.
	 */
	private static function proposal_submitted(): NotificationType {
		return self::type(
			'proposal_submitted',
			static fn(): string => __( 'New category proposal', 'merchboard' ),
			static fn(): string => __( 'When a creator proposes a change to their category that must be approved.', 'merchboard' ),
			true,
			'merchboard-category-proposals',
			array(
				'creator' => 'Nova',
				'field'   => 'banner,description',
			),
			/* translators: 1: creator name, 2: the changed field. */
			static fn( NotificationData $d ): string => sprintf( __( '%1$s proposed a new %2$s.', 'merchboard' ), $d->text( 'creator' ), self::fields( $d ) ),
			static fn( NotificationData $d ): EmailMessage => self::message(
				/* translators: %s: creator name. */
				sprintf( __( 'New category proposal from %s', 'merchboard' ), $d->text( 'creator' ) ),
				__( 'New category proposal', 'merchboard' ),
				__( 'A creator proposed a change to their category. Nothing changes in the shop until you approve it.', 'merchboard' ),
				array(
					array( __( 'Creator', 'merchboard' ), $d->text( 'creator' ) ),
					array( __( 'Change', 'merchboard' ), self::fields( $d ) ),
				),
				__( 'Open Category proposals', 'merchboard' )
			)
		);
	}

	/**
	 * An order with a creator code needs a decision (open case, K2).
	 */
	private static function order_decision(): NotificationType {
		return self::type(
			'order_decision',
			static fn(): string => __( 'Order needs a decision', 'merchboard' ),
			static fn(): string => __( 'When an order with a creator\'s own discount code concerns sales that are already settled (open case).', 'merchboard' ),
			true,
			'merchboard-order-history',
			array(
				'order_number' => '1042',
				'creator'      => 'Nova',
			),
			/* translators: 1: order number, 2: creator name. */
			static fn( NotificationData $d ): string => sprintf( __( 'Order #%1$s with %2$s\'s discount code needs a decision.', 'merchboard' ), $d->text( 'order_number' ), $d->text( 'creator' ) ),
			static fn( NotificationData $d ): EmailMessage => self::message(
				/* translators: %s: order number. */
				sprintf( __( 'Order #%s needs a decision', 'merchboard' ), $d->text( 'order_number' ) ),
				__( 'An order needs a decision', 'merchboard' ),
				__( 'An order used a creator\'s own discount code, but the creator\'s sales in it are already settled. It is an open case until you decide.', 'merchboard' ),
				array(
					array( __( 'Order', 'merchboard' ), '#' . $d->text( 'order_number' ) ),
					array( __( 'Creator', 'merchboard' ), $d->text( 'creator' ) ),
				),
				__( 'Open Order history', 'merchboard' )
			)
		);
	}

	/**
	 * An order could not be synchronized to the sales ledger.
	 */
	private static function sync_failed(): NotificationType {
		return self::type(
			'sync_failed',
			static fn(): string => __( 'Order could not be counted', 'merchboard' ),
			static fn(): string => __( 'When an order could not be added to the creators\' sales.', 'merchboard' ),
			true,
			'merchboard-order-history',
			array(
				'order_number' => '1042',
				'reason'       => 'unknown_creator',
			),
			/* translators: %s: order number. */
			static fn( NotificationData $d ): string => sprintf( __( 'Order #%s could not be counted in the creators\' sales.', 'merchboard' ), $d->text( 'order_number' ) ),
			static fn( NotificationData $d ): EmailMessage => self::message(
				/* translators: %s: order number. */
				sprintf( __( 'Order #%s could not be counted', 'merchboard' ), $d->text( 'order_number' ) ),
				__( 'An order could not be counted', 'merchboard' ),
				__( 'An order could not be added to the creators\' sales. It is tried again when the order is saved; the log has the details.', 'merchboard' ),
				array(
					array( __( 'Order', 'merchboard' ), '#' . $d->text( 'order_number' ) ),
					array( __( 'Reason', 'merchboard' ), $d->text( 'reason' ) ),
				),
				__( 'Open Order history', 'merchboard' )
			)
		);
	}

	/**
	 * A category reconciliation was given up.
	 */
	private static function reconciliation_failed(): NotificationType {
		return self::type(
			'reconciliation_failed',
			static fn(): string => __( 'Category reconciliation failed', 'merchboard' ),
			static fn(): string => __( 'When the automatic linking of products by category gives up after its retries.', 'merchboard' ),
			true,
			'merchboard-creators',
			array( 'run' => 'b2c41f' ),
			static fn(): string => __( 'An automatic category reconciliation was given up.', 'merchboard' ),
			static fn( NotificationData $d ): EmailMessage => self::message(
				__( 'A category reconciliation failed', 'merchboard' ),
				__( 'Category reconciliation failed', 'merchboard' ),
				__( 'Linking products to creators by category failed three times and was given up. Run a full reconciliation under Creators when the cause is fixed; the log has the details.', 'merchboard' ),
				array( array( __( 'Run', 'merchboard' ), $d->text( 'run' ) ) ),
				__( 'Open Creators', 'merchboard' )
			)
		);
	}

	/**
	 * An adjustment of an earlier settlement failed.
	 */
	private static function adjustment_failed(): NotificationType {
		return self::type(
			'adjustment_failed',
			static fn(): string => __( 'Adjustment failed', 'merchboard' ),
			static fn(): string => __( 'When an adjustment of a creator\'s earlier settlements could not be carried out.', 'merchboard' ),
			true,
			'merchboard-payout-recalculations',
			array(
				'creator'   => 'Nova',
				'reference' => 'R2-000003',
			),
			/* translators: %s: creator name. */
			static fn( NotificationData $d ): string => sprintf( __( 'An adjustment for %s failed.', 'merchboard' ), $d->text( 'creator' ) ),
			static fn( NotificationData $d ): EmailMessage => self::message(
				/* translators: %s: creator name. */
				sprintf( __( 'An adjustment for %s failed', 'merchboard' ), $d->text( 'creator' ) ),
				__( 'An adjustment failed', 'merchboard' ),
				__( 'An adjustment of earlier settlements could not be carried out. Nothing was changed for this creator; see the recalculation for the reason.', 'merchboard' ),
				array(
					array( __( 'Creator', 'merchboard' ), $d->text( 'creator' ) ),
					array( __( 'Reference', 'merchboard' ), $d->text( 'reference' ) ),
				),
				__( 'Open Recalculations', 'merchboard' )
			)
		);
	}

	/**
	 * A payout gift card was refused as a coupon on an order.
	 */
	private static function gift_card_coupon(): NotificationType {
		return self::type(
			'gift_card_coupon',
			static fn(): string => __( 'Payout gift card refused as a coupon', 'merchboard' ),
			static fn(): string => __( 'When a gift card from a payout is refused as a coupon on an order in wp-admin or the REST API.', 'merchboard' ),
			true,
			'merchboard-payouts',
			array( 'order_number' => '1042' ),
			/* translators: %s: order number. */
			static fn( NotificationData $d ): string => sprintf( __( 'A payout gift card was refused as a coupon on order #%s.', 'merchboard' ), $d->text( 'order_number' ) ),
			static fn( NotificationData $d ): EmailMessage => self::message(
				__( 'A payout gift card was refused as a coupon', 'merchboard' ),
				__( 'Gift card refused as a coupon', 'merchboard' ),
				__( 'Someone tried to use a gift card from a creator payout as a coupon on an order, and it was refused. The log has the details.', 'merchboard' ),
				array( array( __( 'Order', 'merchboard' ), '#' . $d->text( 'order_number' ) ) ),
				__( 'Open Payouts', 'merchboard' )
			)
		);
	}

	/**
	 * A creator reset their discount code.
	 */
	private static function code_reset(): NotificationType {
		return self::type(
			'code_reset',
			static fn(): string => __( 'Creator reset their discount code', 'merchboard' ),
			static fn(): string => __( 'When a creator replaces their own discount code in the dashboard.', 'merchboard' ),
			false,
			'merchboard-creators',
			array( 'creator' => 'Nova' ),
			/* translators: %s: creator name. */
			static fn( NotificationData $d ): string => sprintf( __( '%s reset their discount code.', 'merchboard' ), $d->text( 'creator' ) ),
			static fn( NotificationData $d ): EmailMessage => self::message(
				/* translators: %s: creator name. */
				sprintf( __( '%s reset their discount code', 'merchboard' ), $d->text( 'creator' ) ),
				__( 'A discount code was reset', 'merchboard' ),
				__( 'A creator replaced their own discount code. The old code no longer works.', 'merchboard' ),
				array( array( __( 'Creator', 'merchboard' ), $d->text( 'creator' ) ) ),
				__( 'Open Creators', 'merchboard' )
			)
		);
	}

	/**
	 * A creator cancelled a payout request.
	 */
	private static function payout_cancelled(): NotificationType {
		return self::type(
			'payout_cancelled',
			static fn(): string => __( 'Creator cancelled a payout request', 'merchboard' ),
			static fn(): string => __( 'When a creator cancels a payout request before it is processed.', 'merchboard' ),
			false,
			'merchboard-payouts',
			array(
				'creator'  => 'Nova',
				'amount'   => '1250.00',
				'currency' => 'DKK',
			),
			/* translators: 1: creator name, 2: amount. */
			static fn( NotificationData $d ): string => sprintf( __( '%1$s cancelled a payout request of %2$s.', 'merchboard' ), $d->text( 'creator' ), $d->money( 'amount' ) ),
			static fn( NotificationData $d ): EmailMessage => self::message(
				/* translators: %s: creator name. */
				sprintf( __( '%s cancelled a payout request', 'merchboard' ), $d->text( 'creator' ) ),
				__( 'A payout request was cancelled', 'merchboard' ),
				__( 'A creator cancelled a payout request. It is no longer waiting for you.', 'merchboard' ),
				array(
					array( __( 'Creator', 'merchboard' ), $d->text( 'creator' ) ),
					array( __( 'Amount', 'merchboard' ), $d->money( 'amount' ) ),
				),
				__( 'Open Payouts', 'merchboard' )
			)
		);
	}
}
