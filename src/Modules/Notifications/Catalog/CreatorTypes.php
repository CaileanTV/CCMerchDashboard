<?php
/**
 * The notifications a creator gets.
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
 * The creator's notification types (ADR-079, docs/features/notifications.md).
 * E-mails never carry gift card codes, discount codes, bank details or other
 * secrets: they say what happened and link to the dashboard.
 *
 * Every e-mail's button leads to the type's dashboard path; the renderer
 * turns the path into the dashboard's URL.
 */
final class CreatorTypes {

	/**
	 * All creator types.
	 *
	 * @return list<NotificationType>
	 */
	public static function all(): array {
		return array(
			self::sale_new(),
			self::sale_reversed(),
			self::balance_ready(),
			self::payout_requested(),
			self::payout_cancelled(),
			self::payout_completed(),
			self::payout_rejected(),
			self::payout_gift_card(),
			self::settlement_corrected(),
			self::payout_adjusted(),
			self::proposal_approved(),
			self::proposal_rejected(),
			self::code_issued(),
			self::code_reset(),
			self::code_ended(),
			self::account_welcome(),
			self::account_status(),
			self::pricing_changed(),
			self::vat_changed(),
			self::billing_details_changed(),
			self::product_linked(),
			self::product_removal_completed(),
			self::product_removal_declined(),
			self::impersonation_started(),
			self::news_published(),
			self::chatbot_lost_access(),
			self::webhook_paused(),
			self::youtube_terms_renewed(),
			self::api_key_changed(),
		);
	}

	/**
	 * A creator type.
	 *
	 * @param string               $key       Key.
	 * @param string               $group     Group.
	 * @param Closure              $label     Admin name.
	 * @param Closure              $describe  When it is sent.
	 * @param bool                 $email     E-mail by default.
	 * @param bool                 $dashboard Bell by default.
	 * @param bool                 $important The creator cannot turn the e-mail off.
	 * @param string               $path      Dashboard path.
	 * @param array<string, mixed> $sample    Sample data.
	 * @param Closure              $text      Dashboard line.
	 * @param Closure              $message   E-mail.
	 *
	 * @phpstan-param Closure(): string $label
	 * @phpstan-param Closure(): string $describe
	 * @phpstan-param Closure(NotificationData): string $text
	 * @phpstan-param Closure(NotificationData): EmailMessage $message
	 */
	private static function type( string $key, string $group, Closure $label, Closure $describe, bool $email, bool $dashboard, bool $important, string $path, array $sample, Closure $text, Closure $message ): NotificationType {
		return new NotificationType( $key, NotificationType::CREATOR, $group, $label, $describe, $email, $dashboard, $important, $path, $sample, $text, $message );
	}

	/**
	 * The name of a proposal's field (`field` is the field's key), in the
	 * language of the reader.
	 *
	 * @param NotificationData $d Data.
	 */
	private static function field( NotificationData $d ): string {
		$field = ProposalField::tryFrom( $d->text( 'field' ) );

		return null === $field ? $d->text( 'field' ) : ProposalLabels::field( $field );
	}

	/**
	 * "Thanks for creating with us" closing line.
	 */
	private static function closing(): string {
		return __( 'Thank you for creating with us.', 'merchboard' );
	}

	/**
	 * A new sale: one per order and creator.
	 */
	private static function sale_new(): NotificationType {
		return self::type(
			'sale_new',
			'sales',
			static fn(): string => __( 'New sale', 'merchboard' ),
			static fn(): string => __( 'When an order with the creator\'s products is paid; one per order.', 'merchboard' ),
			true,
			true,
			false,
			'sales',
			array(
				'order_number' => '1042',
				'currency'     => 'DKK',
				'items'        => array(
					array(
						'name'     => 'Logo Hoodie – Black, L',
						'quantity' => '1',
						'amount'   => '499.00',
					),
					array(
						'name'     => 'Classic Cap – Sand',
						'quantity' => '2',
						'amount'   => '298.00',
					),
				),
				'total'        => '797.00',
				'profit'       => '132.84',
			),
			static fn( NotificationData $d ): string => '' === $d->money( 'profit' )
				/* translators: %s: order number. */
				? sprintf( __( 'New sale in order #%s.', 'merchboard' ), $d->text( 'order_number' ) )
				/* translators: 1: order number, 2: the creator's profit. */
				: sprintf( __( 'New sale in order #%1$s: your profit is %2$s.', 'merchboard' ), $d->text( 'order_number' ), $d->money( 'profit' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: order number. */
				sprintf( __( 'New sale: order #%s', 'merchboard' ), $d->text( 'order_number' ) ),
				__( 'You made a sale', 'merchboard' ),
				/* translators: %s: order number. */
				array( sprintf( __( 'Your products were sold in order #%s. Here is what was bought:', 'merchboard' ), $d->text( 'order_number' ) ) ),
				array_values(
					array_filter(
						array(
							array( __( 'Sale', 'merchboard' ), $d->money( 'total' ) ),
							array( __( 'Your profit', 'merchboard' ), $d->money( 'profit' ) ),
						),
						static fn( array $line ): bool => '' !== $line[1]
					)
				),
				array(
					'head' => array( __( 'Product', 'merchboard' ), __( 'Quantity', 'merchboard' ), __( 'Price', 'merchboard' ) ),
					'rows' => array_map( static fn( array $row ): array => array( $row[0], '×' . $row[1], is_numeric( $row[2] ) ? NotificationData::format_money( (float) $row[2], $d->text( 'currency' ) ) : '' ), $d->rows( 'items', array( 'name', 'quantity', 'amount' ) ) ),
				),
				null,
				__( 'See your sales', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * A sale was refunded, cancelled or changed.
	 */
	private static function sale_reversed(): NotificationType {
		return self::type(
			'sale_reversed',
			'sales',
			static fn(): string => __( 'Sale refunded or cancelled', 'merchboard' ),
			static fn(): string => __( 'When an order with the creator\'s products is refunded, cancelled or changed after the sale.', 'merchboard' ),
			false,
			true,
			false,
			'sales',
			array(
				'order_number' => '1042',
				'amount'       => '-249.50',
				'currency'     => 'DKK',
			),
			/* translators: 1: order number, 2: changed amount (negative). */
			static fn( NotificationData $d ): string => sprintf( __( 'Order #%1$s was refunded or changed: %2$s.', 'merchboard' ), $d->text( 'order_number' ), $d->money( 'amount' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: order number. */
				sprintf( __( 'Order #%s was refunded or changed', 'merchboard' ), $d->text( 'order_number' ) ),
				__( 'A sale was changed', 'merchboard' ),
				array( __( 'An order with your products was refunded, cancelled or changed. Your sales and profit are updated.', 'merchboard' ) ),
				array(
					array( __( 'Order', 'merchboard' ), '#' . $d->text( 'order_number' ) ),
					array( __( 'Change in sales', 'merchboard' ), $d->money( 'amount' ) ),
				),
				null,
				null,
				__( 'See your sales', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * The balance that can be paid out has grown.
	 */
	private static function balance_ready(): NotificationType {
		return self::type(
			'balance_ready',
			'payouts',
			static fn(): string => __( 'Balance ready for payout', 'merchboard' ),
			static fn(): string => __( 'Once a day, when more of the creator\'s profit can be paid out.', 'merchboard' ),
			true,
			true,
			false,
			'payouts',
			array(
				'available' => '1250.00',
				'currency'  => 'DKK',
			),
			/* translators: %s: amount. */
			static fn( NotificationData $d ): string => sprintf( __( '%s is ready to be paid out.', 'merchboard' ), $d->money( 'available' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: amount. */
				sprintf( __( '%s is ready to be paid out', 'merchboard' ), $d->money( 'available' ) ),
				__( 'Your balance is ready', 'merchboard' ),
				array( __( 'More of your profit has passed the holding period and can now be paid out. You can request a payout in your dashboard.', 'merchboard' ) ),
				array( array( __( 'Ready for payout', 'merchboard' ), $d->money( 'available' ) ) ),
				null,
				null,
				__( 'Request a payout', 'merchboard' ),
				'',
				self::closing()
			)
		);
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
	 * A payout request was received.
	 */
	private static function payout_requested(): NotificationType {
		return self::type(
			'payout_requested',
			'payouts',
			static fn(): string => __( 'Payout request received', 'merchboard' ),
			static fn(): string => __( 'When the creator requests a payout.', 'merchboard' ),
			true,
			true,
			true,
			'payouts',
			array(
				'amount'   => '1250.00',
				'currency' => 'DKK',
				'method'   => 'bank',
			),
			/* translators: %s: amount. */
			static fn( NotificationData $d ): string => sprintf( __( 'We received your payout request of %s.', 'merchboard' ), $d->money( 'amount' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				__( 'We received your payout request', 'merchboard' ),
				__( 'Payout request received', 'merchboard' ),
				array( __( 'Thank you. We will process your request and let you know when the money is on its way.', 'merchboard' ) ),
				array_values(
					array_filter(
						array(
							array( __( 'Amount', 'merchboard' ), $d->money( 'amount' ) ),
							array( __( 'Method', 'merchboard' ), self::method( $d ) ),
						),
						static fn( array $line ): bool => '' !== $line[1]
					)
				),
				null,
				null,
				__( 'See your payouts', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * The creator cancelled a payout request.
	 */
	private static function payout_cancelled(): NotificationType {
		return self::type(
			'payout_cancelled',
			'payouts',
			static fn(): string => __( 'Payout request cancelled', 'merchboard' ),
			static fn(): string => __( 'When the creator cancels a payout request.', 'merchboard' ),
			false,
			true,
			false,
			'payouts',
			array(
				'amount'   => '1250.00',
				'currency' => 'DKK',
			),
			/* translators: %s: amount. */
			static fn( NotificationData $d ): string => sprintf( __( 'Your payout request of %s was cancelled.', 'merchboard' ), $d->money( 'amount' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				__( 'Your payout request was cancelled', 'merchboard' ),
				__( 'Payout request cancelled', 'merchboard' ),
				array( __( 'Your payout request was cancelled. The amount is part of your balance again.', 'merchboard' ) ),
				array( array( __( 'Amount', 'merchboard' ), $d->money( 'amount' ) ) ),
				null,
				null,
				__( 'See your payouts', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * A payout was made, with its settlement document.
	 */
	private static function payout_completed(): NotificationType {
		return self::type(
			'payout_completed',
			'payouts',
			static fn(): string => __( 'Payout completed', 'merchboard' ),
			static fn(): string => __( 'When an administrator completes a payout; the settlement document is issued at the same time.', 'merchboard' ),
			true,
			true,
			true,
			'payouts',
			array(
				'amount'          => '1250.00',
				'currency'        => 'DKK',
				'method'          => 'bank',
				'document_number' => 'AFR-000014',
			),
			/* translators: 1: amount, 2: settlement document number. */
			static fn( NotificationData $d ): string => sprintf( __( 'Your payout of %1$s is on its way. Settlement document %2$s is ready.', 'merchboard' ), $d->money( 'amount' ), $d->text( 'document_number' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: amount. */
				sprintf( __( 'Your payout of %s is on its way', 'merchboard' ), $d->money( 'amount' ) ),
				__( 'Your payout is on its way', 'merchboard' ),
				array( __( 'We have paid out your profit. Your settlement document is ready in your dashboard.', 'merchboard' ) ),
				array_values(
					array_filter(
						array(
							array( __( 'Amount', 'merchboard' ), $d->money( 'amount' ) ),
							array( __( 'Method', 'merchboard' ), self::method( $d ) ),
							array( __( 'Settlement document', 'merchboard' ), $d->text( 'document_number' ) ),
						),
						static fn( array $line ): bool => '' !== $line[1]
					)
				),
				null,
				null,
				__( 'See your payouts', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * A payout request was rejected.
	 */
	private static function payout_rejected(): NotificationType {
		return self::type(
			'payout_rejected',
			'payouts',
			static fn(): string => __( 'Payout request rejected', 'merchboard' ),
			static fn(): string => __( 'When an administrator rejects a payout request, with the reason.', 'merchboard' ),
			true,
			true,
			true,
			'payouts',
			array(
				'amount'   => '1250.00',
				'currency' => 'DKK',
				'reason'   => 'The PayPal address could not receive payments. Please request again with another address.',
			),
			/* translators: %s: amount. */
			static fn( NotificationData $d ): string => sprintf( __( 'Your payout request of %s was rejected.', 'merchboard' ), $d->money( 'amount' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				__( 'Your payout request was rejected', 'merchboard' ),
				__( 'Payout request rejected', 'merchboard' ),
				array( __( 'We could not complete your payout request. The amount is part of your balance again, and you can request a new payout.', 'merchboard' ) ),
				array( array( __( 'Amount', 'merchboard' ), $d->money( 'amount' ) ) ),
				null,
				'' === $d->text( 'reason' ) ? null : array( __( 'Reason', 'merchboard' ), $d->text( 'reason' ) ),
				__( 'See your payouts', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * A payout as a gift card is ready; the code is only in the dashboard.
	 */
	private static function payout_gift_card(): NotificationType {
		return self::type(
			'payout_gift_card',
			'payouts',
			static fn(): string => __( 'Gift card ready', 'merchboard' ),
			static fn(): string => __( 'When an administrator registers the gift card of a payout. The code is never in the e-mail.', 'merchboard' ),
			true,
			true,
			true,
			'payouts',
			array(
				'amount'   => '1250.00',
				'currency' => 'DKK',
			),
			/* translators: %s: amount. */
			static fn( NotificationData $d ): string => sprintf( __( 'Your gift card of %s is ready. See the code under Payouts.', 'merchboard' ), $d->money( 'amount' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				__( 'Your gift card is ready', 'merchboard' ),
				__( 'Your gift card is ready', 'merchboard' ),
				array( __( 'Your payout as a gift card is ready. For your security, the code is only shown in your dashboard.', 'merchboard' ) ),
				array( array( __( 'Value', 'merchboard' ), $d->money( 'amount' ) ) ),
				null,
				null,
				__( 'See your gift card', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * A settlement document was corrected.
	 */
	private static function settlement_corrected(): NotificationType {
		return self::type(
			'settlement_corrected',
			'payouts',
			static fn(): string => __( 'Settlement document corrected', 'merchboard' ),
			static fn(): string => __( 'When an administrator issues a correction of a settlement document.', 'merchboard' ),
			true,
			true,
			true,
			'payouts',
			array(
				'document_number'   => 'AFR-000012',
				'correction_number' => 'AFR-000012-K1',
				'difference'        => '-45.00',
				'currency'          => 'DKK',
				'reason'            => 'A refunded order was counted twice.',
			),
			/* translators: 1: document number, 2: correction number. */
			static fn( NotificationData $d ): string => sprintf( __( 'Settlement document %1$s was corrected (%2$s).', 'merchboard' ), $d->text( 'document_number' ), $d->text( 'correction_number' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: document number. */
				sprintf( __( 'Settlement document %s was corrected', 'merchboard' ), $d->text( 'document_number' ) ),
				__( 'A settlement document was corrected', 'merchboard' ),
				array( __( 'We issued a correction of one of your settlement documents. You can see both in your dashboard.', 'merchboard' ) ),
				array_values(
					array_filter(
						array(
							array( __( 'Document', 'merchboard' ), $d->text( 'document_number' ) ),
							array( __( 'Correction', 'merchboard' ), $d->text( 'correction_number' ) ),
							array( __( 'Difference', 'merchboard' ), $d->money( 'difference' ) ),
						),
						static fn( array $line ): bool => '' !== $line[1]
					)
				),
				null,
				'' === $d->text( 'reason' ) ? null : array( __( 'Reason', 'merchboard' ), $d->text( 'reason' ) ),
				__( 'See your payouts', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * An earlier settlement was adjusted.
	 */
	private static function payout_adjusted(): NotificationType {
		return self::type(
			'payout_adjusted',
			'payouts',
			static fn(): string => __( 'Earlier settlement adjusted', 'merchboard' ),
			static fn(): string => __( 'When an administrator adjusts earlier settlements; the amount is settled with the next payout.', 'merchboard' ),
			true,
			true,
			true,
			'payouts',
			array(
				'amount'    => '87.50',
				'currency'  => 'DKK',
				'reference' => 'R2-000003',
			),
			/* translators: %s: amount (positive or negative). */
			static fn( NotificationData $d ): string => sprintf( __( 'An earlier settlement was adjusted by %s.', 'merchboard' ), $d->money( 'amount' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				__( 'An earlier settlement was adjusted', 'merchboard' ),
				__( 'Settlement adjusted', 'merchboard' ),
				array( __( 'We recalculated earlier settlements. The adjustment is settled with your next payout.', 'merchboard' ) ),
				array_values(
					array_filter(
						array(
							array( __( 'Adjustment', 'merchboard' ), $d->money( 'amount' ) ),
							array( __( 'Reference', 'merchboard' ), $d->text( 'reference' ) ),
						),
						static fn( array $line ): bool => '' !== $line[1]
					)
				),
				null,
				null,
				__( 'See your payouts', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * A category proposal was approved.
	 */
	private static function proposal_approved(): NotificationType {
		return self::type(
			'proposal_approved',
			'profile',
			static fn(): string => __( 'Category proposal approved', 'merchboard' ),
			static fn(): string => __( 'When an administrator approves a change the creator proposed for their category.', 'merchboard' ),
			true,
			true,
			false,
			'profile',
			array( 'field' => 'banner' ),
			/* translators: %s: the changed field, e.g. "Banner". */
			static fn( NotificationData $d ): string => sprintf( __( 'Your proposal for %s was approved and is now in the shop.', 'merchboard' ), self::field( $d ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: the changed field. */
				sprintf( __( 'Your proposal for %s was approved', 'merchboard' ), self::field( $d ) ),
				__( 'Your proposal was approved', 'merchboard' ),
				array( __( 'Your change is approved and now shown in the shop.', 'merchboard' ) ),
				array( array( __( 'Change', 'merchboard' ), self::field( $d ) ) ),
				null,
				null,
				__( 'See your profile', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * A category proposal was rejected, with the note.
	 */
	private static function proposal_rejected(): NotificationType {
		return self::type(
			'proposal_rejected',
			'profile',
			static fn(): string => __( 'Category proposal rejected', 'merchboard' ),
			static fn(): string => __( 'When an administrator rejects a proposed change, with the note.', 'merchboard' ),
			true,
			true,
			false,
			'profile',
			array(
				'field' => 'image',
				'note'  => 'The logo must not show another brand. Please upload your own.',
			),
			/* translators: %s: the changed field. */
			static fn( NotificationData $d ): string => sprintf( __( 'Your proposal for %s was not approved.', 'merchboard' ), self::field( $d ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: the changed field. */
				sprintf( __( 'Your proposal for %s was not approved', 'merchboard' ), self::field( $d ) ),
				__( 'Your proposal was not approved', 'merchboard' ),
				array( __( 'Your change was not approved, so nothing changed in the shop. You are welcome to propose it again.', 'merchboard' ) ),
				array( array( __( 'Change', 'merchboard' ), self::field( $d ) ) ),
				null,
				'' === $d->text( 'note' ) ? null : array( __( 'Note from us', 'merchboard' ), $d->text( 'note' ) ),
				__( 'See your profile', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * The creator's first discount code; the code is only in the dashboard.
	 */
	private static function code_issued(): NotificationType {
		return self::type(
			'code_issued',
			'code',
			static fn(): string => __( 'Discount code created', 'merchboard' ),
			static fn(): string => __( 'When an administrator creates the creator\'s first discount code. The code is never in the e-mail.', 'merchboard' ),
			true,
			true,
			true,
			'profile',
			array(),
			static fn(): string => __( 'Your own discount code is ready. See it under Profile.', 'merchboard' ),
			static fn(): EmailMessage => new EmailMessage(
				__( 'Your own discount code is ready', 'merchboard' ),
				__( 'Your discount code is ready', 'merchboard' ),
				array(
					__( 'With your own discount code you can buy your own merchandise without paying for your profit.', 'merchboard' ),
					__( 'For your security, the code is only shown in your dashboard. Orders with your own code do not count in your sales and profit.', 'merchboard' ),
				),
				array(),
				null,
				null,
				__( 'See your discount code', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * The discount code was replaced; the old one no longer works.
	 */
	private static function code_reset(): NotificationType {
		return self::type(
			'code_reset',
			'code',
			static fn(): string => __( 'Discount code reset', 'merchboard' ),
			static fn(): string => __( 'When the creator\'s discount code is replaced by a new one (security message).', 'merchboard' ),
			true,
			true,
			true,
			'profile',
			array(),
			static fn(): string => __( 'Your discount code was reset. The old code no longer works.', 'merchboard' ),
			static fn(): EmailMessage => new EmailMessage(
				__( 'Your discount code was reset', 'merchboard' ),
				__( 'Your discount code was reset', 'merchboard' ),
				array(
					__( 'Your discount code was replaced by a new one, and the old code no longer works. You can see the new code in your dashboard.', 'merchboard' ),
					__( 'If you did not ask for this, please contact us.', 'merchboard' ),
				),
				array(),
				null,
				null,
				__( 'See your discount code', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * The discount code was ended.
	 */
	private static function code_ended(): NotificationType {
		return self::type(
			'code_ended',
			'code',
			static fn(): string => __( 'Discount code ended', 'merchboard' ),
			static fn(): string => __( 'When an administrator ends the creator\'s discount code.', 'merchboard' ),
			true,
			true,
			true,
			'profile',
			array(),
			static fn(): string => __( 'Your discount code was ended and no longer works.', 'merchboard' ),
			static fn(): EmailMessage => new EmailMessage(
				__( 'Your discount code was ended', 'merchboard' ),
				__( 'Your discount code was ended', 'merchboard' ),
				array( __( 'Your discount code no longer works. Please contact us if you need a new one.', 'merchboard' ) ),
				array(),
				null,
				null,
				__( 'Go to your dashboard', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * Welcome, with the way into the dashboard.
	 */
	private static function account_welcome(): NotificationType {
		return self::type(
			'account_welcome',
			'account',
			static fn(): string => __( 'Welcome', 'merchboard' ),
			static fn(): string => __( 'When a creator is created with a user, or a user is linked to a creator.', 'merchboard' ),
			true,
			false,
			true,
			'',
			array(),
			static fn(): string => __( 'Welcome to Merch Dashboard.', 'merchboard' ),
			static fn(): EmailMessage => new EmailMessage(
				__( 'Welcome to Merch Dashboard', 'merchboard' ),
				__( 'Welcome to Merch Dashboard', 'merchboard' ),
				array(
					__( 'Your creator account is ready. In your dashboard you follow your sales and profit, request payouts and keep your profile up to date.', 'merchboard' ),
					__( 'Log in with your usual user name and password.', 'merchboard' ),
				),
				array(),
				null,
				null,
				__( 'Open your dashboard', 'merchboard' ),
				'',
				__( 'We look forward to working with you.', 'merchboard' )
			)
		);
	}

	/**
	 * The account was deactivated or activated again.
	 */
	private static function account_status(): NotificationType {
		return self::type(
			'account_status',
			'account',
			static fn(): string => __( 'Account deactivated or activated', 'merchboard' ),
			static fn(): string => __( 'When an administrator deactivates the creator or activates them again.', 'merchboard' ),
			true,
			true,
			true,
			'',
			array( 'active' => false ),
			static fn( NotificationData $d ): string => $d->flag( 'active' ) ? __( 'Your creator account is active again.', 'merchboard' ) : __( 'Your creator account was deactivated.', 'merchboard' ),
			static fn( NotificationData $d ): EmailMessage => $d->flag( 'active' )
				? new EmailMessage(
					__( 'Your creator account is active again', 'merchboard' ),
					__( 'Your account is active again', 'merchboard' ),
					array( __( 'Your creator account is active again, and you can use your dashboard as before.', 'merchboard' ) ),
					array(),
					null,
					null,
					__( 'Open your dashboard', 'merchboard' ),
					'',
					self::closing()
				)
				: new EmailMessage(
					__( 'Your creator account was deactivated', 'merchboard' ),
					__( 'Your account was deactivated', 'merchboard' ),
					array( __( 'Your creator account was deactivated, so your dashboard is not available for now. Please contact us if you have questions.', 'merchboard' ) ),
					array(),
					null,
					null,
					'',
					'',
					self::closing()
				)
		);
	}

	/**
	 * The creator's profit share changed.
	 */
	private static function pricing_changed(): NotificationType {
		return self::type(
			'pricing_changed',
			'account',
			static fn(): string => __( 'Profit changed', 'merchboard' ),
			static fn(): string => __( 'When an administrator changes the creator\'s profit, by default or for a product.', 'merchboard' ),
			true,
			true,
			true,
			'products',
			array(
				'product'  => 'Logo Hoodie',
				'previous' => '20 %',
				'new'      => '25 %',
			),
			static fn( NotificationData $d ): string => '' === $d->text( 'product' )
				/* translators: 1: previous profit, 2: new profit. */
				? sprintf( __( 'Your profit was changed from %1$s to %2$s.', 'merchboard' ), $d->text( 'previous' ), $d->text( 'new' ) )
				/* translators: 1: product, 2: previous profit, 3: new profit. */
				: sprintf( __( 'Your profit on %1$s was changed from %2$s to %3$s.', 'merchboard' ), $d->text( 'product' ), $d->text( 'previous' ), $d->text( 'new' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				__( 'Your profit was changed', 'merchboard' ),
				__( 'Your profit was changed', 'merchboard' ),
				array( __( 'The change applies to sales from now on. Earlier sales keep their profit.', 'merchboard' ) ),
				array_values(
					array_filter(
						array(
							array( __( 'Product', 'merchboard' ), '' === $d->text( 'product' ) ? __( 'All your products (your standard)', 'merchboard' ) : $d->text( 'product' ) ),
							array( __( 'Before', 'merchboard' ), $d->text( 'previous' ) ),
							array( __( 'Now', 'merchboard' ), $d->text( 'new' ) ),
						),
						static fn( array $line ): bool => '' !== $line[1]
					)
				),
				null,
				null,
				__( 'See your products', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * The VAT status changed.
	 */
	private static function vat_changed(): NotificationType {
		return self::type(
			'vat_changed',
			'account',
			static fn(): string => __( 'VAT status changed', 'merchboard' ),
			static fn(): string => __( 'When an administrator changes whether the creator is VAT registered.', 'merchboard' ),
			true,
			true,
			true,
			'payouts',
			array( 'registered' => true ),
			static fn( NotificationData $d ): string => $d->flag( 'registered' ) ? __( 'You are now registered as VAT registered.', 'merchboard' ) : __( 'You are no longer registered as VAT registered.', 'merchboard' ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				__( 'Your VAT status was changed', 'merchboard' ),
				__( 'Your VAT status was changed', 'merchboard' ),
				array(
					$d->flag( 'registered' ) ? __( 'You are now registered as VAT registered.', 'merchboard' ) : __( 'You are no longer registered as VAT registered.', 'merchboard' ),
					__( 'This affects your payouts for sales from now on. Earlier sales are not changed.', 'merchboard' ),
				),
				array(),
				null,
				null,
				__( 'See your payouts', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * The billing details on settlement documents changed.
	 */
	private static function billing_details_changed(): NotificationType {
		return self::type(
			'billing_details_changed',
			'account',
			static fn(): string => __( 'Billing details changed', 'merchboard' ),
			static fn(): string => __( 'When an administrator changes the details shown on the creator\'s settlement documents.', 'merchboard' ),
			true,
			true,
			true,
			'payouts',
			array(),
			static fn(): string => __( 'The details on your settlement documents were changed.', 'merchboard' ),
			static fn(): EmailMessage => new EmailMessage(
				__( 'Your billing details were changed', 'merchboard' ),
				__( 'Your billing details were changed', 'merchboard' ),
				array(
					__( 'The details shown on your settlement documents (name, address and registration numbers) were changed. They are used on documents from now on.', 'merchboard' ),
					__( 'If something is wrong, please contact us.', 'merchboard' ),
				),
				array(),
				null,
				null,
				__( 'See your payouts', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * A request to remove a legacy product was handled (ADR-083).
	 */
	private static function product_removal_completed(): NotificationType {
		return self::type(
			'product_removal_completed',
			'account',
			static fn(): string => __( 'Product removal request handled', 'merchboard' ),
			static fn(): string => __( 'When an administrator has handled a request to remove a product.', 'merchboard' ),
			true,
			true,
			false,
			'products',
			array( 'product' => 'Logo Hoodie' ),
			/* translators: %s: product name. */
			static fn( NotificationData $d ): string => sprintf( __( 'We have handled your request to remove %s.', 'merchboard' ), $d->text( 'product' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: product name. */
				sprintf( __( 'Your request to remove %s is handled', 'merchboard' ), $d->text( 'product' ) ),
				__( 'Your request is handled', 'merchboard' ),
				/* translators: %s: product name. */
				array( sprintf( __( 'We have handled your request to remove %s.', 'merchboard' ), $d->text( 'product' ) ) ),
				array(),
				null,
				null,
				__( 'See your products', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * A request to remove a legacy product was declined, with the note (ADR-083).
	 */
	private static function product_removal_declined(): NotificationType {
		return self::type(
			'product_removal_declined',
			'account',
			static fn(): string => __( 'Product removal request declined', 'merchboard' ),
			static fn(): string => __( 'When an administrator declines a request to remove a product, with the note.', 'merchboard' ),
			true,
			true,
			false,
			'products',
			array(
				'product' => 'Logo Hoodie',
				'note'    => 'The product is part of a running campaign.',
			),
			/* translators: %s: product name. */
			static fn( NotificationData $d ): string => sprintf( __( 'We could not remove %s.', 'merchboard' ), $d->text( 'product' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: product name. */
				sprintf( __( 'We could not remove %s', 'merchboard' ), $d->text( 'product' ) ),
				__( 'Your request was not approved', 'merchboard' ),
				array( __( 'The product stays in the shop. You are welcome to ask again.', 'merchboard' ) ),
				array( array( __( 'Product', 'merchboard' ), $d->text( 'product' ) ) ),
				null,
				'' === $d->text( 'note' ) ? null : array( __( 'Note from us', 'merchboard' ), $d->text( 'note' ) ),
				__( 'See your products', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * A product was added to the creator's shop.
	 */
	private static function product_linked(): NotificationType {
		return self::type(
			'product_linked',
			'account',
			static fn(): string => __( 'New product in your shop', 'merchboard' ),
			static fn(): string => __( 'When a product is linked to the creator.', 'merchboard' ),
			false,
			true,
			false,
			'products',
			array( 'product' => 'Logo Hoodie' ),
			/* translators: %s: product name. */
			static fn( NotificationData $d ): string => sprintf( __( '%s is now one of your products.', 'merchboard' ), $d->text( 'product' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: product name. */
				sprintf( __( 'New product: %s', 'merchboard' ), $d->text( 'product' ) ),
				__( 'A new product in your shop', 'merchboard' ),
				/* translators: %s: product name. */
				array( sprintf( __( '%s is now one of your products, and its sales count as yours.', 'merchboard' ), $d->text( 'product' ) ) ),
				array(),
				null,
				null,
				__( 'See your products', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * An administrator opened the creator's dashboard ("view as creator").
	 */
	private static function impersonation_started(): NotificationType {
		return self::type(
			'impersonation_started',
			'account',
			static fn(): string => __( 'An administrator viewed your dashboard', 'merchboard' ),
			static fn(): string => __( 'When an administrator starts "View as creator" for the creator.', 'merchboard' ),
			false,
			true,
			false,
			'',
			array(),
			static fn(): string => __( 'An administrator viewed your dashboard, e.g. to help you.', 'merchboard' ),
			static fn(): EmailMessage => new EmailMessage(
				__( 'An administrator viewed your dashboard', 'merchboard' ),
				__( 'An administrator viewed your dashboard', 'merchboard' ),
				array( __( 'An administrator opened your dashboard as you see it, for example to help you. Changes made there are logged.', 'merchboard' ) ),
				array(),
				null,
				null,
				__( 'Open your dashboard', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * A news message was published.
	 */
	private static function news_published(): NotificationType {
		return self::type(
			'news_published',
			'news',
			static fn(): string => __( 'News from Merch Dashboard', 'merchboard' ),
			static fn(): string => __( 'When an administrator publishes news.', 'merchboard' ),
			false,
			true,
			false,
			'news',
			array( 'title' => 'New: your own banner on your category page' ),
			/* translators: %s: news title. */
			static fn( NotificationData $d ): string => sprintf( __( 'News: %s', 'merchboard' ), $d->text( 'title' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: news title. */
				sprintf( __( 'News: %s', 'merchboard' ), $d->text( 'title' ) ),
				$d->text( 'title' ),
				array( __( 'There is news from Merch Dashboard. Read it in your dashboard.', 'merchboard' ) ),
				array(),
				null,
				null,
				__( 'Read the news', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * The chatbot lost access to a channel.
	 */
	private static function chatbot_lost_access(): NotificationType {
		return self::type(
			'chatbot_lost_access',
			'alerts',
			static fn(): string => __( 'Chatbot lost access', 'merchboard' ),
			static fn(): string => __( 'When a platform refuses the chatbot\'s access to the creator\'s channel; once until it is connected again.', 'merchboard' ),
			true,
			true,
			false,
			'alerts',
			array( 'platform' => 'Twitch' ),
			/* translators: %s: platform, e.g. Twitch. */
			static fn( NotificationData $d ): string => sprintf( __( 'The chatbot lost access to your %s channel. Connect it again under Alerts.', 'merchboard' ), $d->text( 'platform' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: platform. */
				sprintf( __( 'The chatbot lost access to your %s channel', 'merchboard' ), $d->text( 'platform' ) ),
				__( 'Your chatbot needs to be connected again', 'merchboard' ),
				/* translators: %s: platform. */
				array( sprintf( __( '%s no longer lets the chatbot write in your channel, so purchases are not announced in your chat. Connect the channel again under Alerts.', 'merchboard' ), $d->text( 'platform' ) ) ),
				array(),
				null,
				null,
				__( 'Connect again', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * A webhook was paused after repeated failures.
	 */
	private static function webhook_paused(): NotificationType {
		return self::type(
			'webhook_paused',
			'alerts',
			static fn(): string => __( 'Webhook paused', 'merchboard' ),
			static fn(): string => __( 'When one of the creator\'s webhooks is paused after 10 failed deliveries in a row.', 'merchboard' ),
			true,
			true,
			false,
			'alerts',
			array( 'webhook' => 'SparkyBot' ),
			/* translators: %s: webhook name. */
			static fn( NotificationData $d ): string => sprintf( __( 'Your webhook "%s" was paused after 10 failed deliveries.', 'merchboard' ), $d->text( 'webhook' ) ),
			static fn( NotificationData $d ): EmailMessage => new EmailMessage(
				/* translators: %s: webhook name. */
				sprintf( __( 'Your webhook "%s" was paused', 'merchboard' ), $d->text( 'webhook' ) ),
				__( 'Your webhook was paused', 'merchboard' ),
				array( __( 'The last 10 deliveries to your webhook failed, so we paused it. Check the receiver, then resume the webhook under Alerts.', 'merchboard' ) ),
				array( array( __( 'Webhook', 'merchboard' ), $d->text( 'webhook' ) ) ),
				null,
				null,
				__( 'See your webhooks', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * New YouTube terms must be accepted.
	 */
	private static function youtube_terms_renewed(): NotificationType {
		return self::type(
			'youtube_terms_renewed',
			'alerts',
			static fn(): string => __( 'New YouTube terms', 'merchboard' ),
			static fn(): string => __( 'When an administrator renews the YouTube chatbot\'s terms, which every creator must accept again.', 'merchboard' ),
			true,
			true,
			false,
			'alerts',
			array(),
			static fn(): string => __( 'Please accept the new terms of the YouTube chatbot under Alerts.', 'merchboard' ),
			static fn(): EmailMessage => new EmailMessage(
				__( 'Please accept the new YouTube terms', 'merchboard' ),
				__( 'New terms for the YouTube chatbot', 'merchboard' ),
				array( __( 'The terms of the YouTube chatbot were updated. Until you accept them, the chatbot does not write in your YouTube chat.', 'merchboard' ) ),
				array(),
				null,
				null,
				__( 'Read and accept', 'merchboard' ),
				'',
				self::closing()
			)
		);
	}

	/**
	 * An API key was created, or revoked by an administrator.
	 */
	private static function api_key_changed(): NotificationType {
		return self::type(
			'api_key_changed',
			'alerts',
			static fn(): string => __( 'API key created or revoked', 'merchboard' ),
			static fn(): string => __( 'When an API key is created for the creator, or an administrator revokes their keys (security message).', 'merchboard' ),
			true,
			true,
			true,
			'alerts',
			array(
				'action' => 'created',
				'key'    => 'StreamBot',
			),
			static fn( NotificationData $d ): string => 'revoked' === $d->text( 'action' )
				? __( 'An administrator revoked your API keys.', 'merchboard' )
				/* translators: %s: key name. */
				: sprintf( __( 'A new API key "%s" was created.', 'merchboard' ), $d->text( 'key' ) ),
			static fn( NotificationData $d ): EmailMessage => 'revoked' === $d->text( 'action' )
				? new EmailMessage(
					__( 'Your API keys were revoked', 'merchboard' ),
					__( 'Your API keys were revoked', 'merchboard' ),
					array( __( 'An administrator revoked your API keys, so they no longer work. Please contact us if you have questions.', 'merchboard' ) ),
					array(),
					null,
					null,
					__( 'See your API keys', 'merchboard' ),
					'',
					self::closing()
				)
				: new EmailMessage(
					__( 'A new API key was created', 'merchboard' ),
					__( 'A new API key was created', 'merchboard' ),
					array(
						__( 'A new API key was created for your account.', 'merchboard' ),
						__( 'If you did not create it, revoke it under Alerts and contact us.', 'merchboard' ),
					),
					array( array( __( 'Key', 'merchboard' ), $d->text( 'key' ) ) ),
					null,
					null,
					__( 'See your API keys', 'merchboard' ),
					'',
					self::closing()
				)
		);
	}
}
