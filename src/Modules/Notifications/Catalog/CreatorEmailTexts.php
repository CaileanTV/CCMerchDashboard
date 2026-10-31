<?php
/**
 * What creators read about their e-mails.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Notifications\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * The notification catalogue's names and descriptions are written for the
 * administrator. These are the same e-mails in the creator's own words, for
 * their choices on the profile page.
 */
final class CreatorEmailTexts {

	/**
	 * A notification's name and description, or null for one without.
	 *
	 * @param string $key Notification type.
	 * @return array{0: string, 1: string}|null
	 */
	public static function of( string $key ): ?array {
		return match ( $key ) {
			'sale_new'              => array( __( 'New sale', 'merchboard' ), __( 'When one of your products is sold.', 'merchboard' ) ),
			'sale_reversed'         => array( __( 'Sale refunded or cancelled', 'merchboard' ), __( 'When an order with your products is refunded, cancelled or changed.', 'merchboard' ) ),
			'balance_ready'         => array( __( 'Money ready for payout', 'merchboard' ), __( 'Once a day, when you have more profit you can have paid out.', 'merchboard' ) ),
			'payout_cancelled'      => array( __( 'Payout request cancelled', 'merchboard' ), __( 'When you cancel a payout request.', 'merchboard' ) ),
			'proposal_approved'     => array( __( 'Category proposal approved', 'merchboard' ), __( 'When we approve a change you proposed for your category.', 'merchboard' ) ),
			'proposal_rejected'     => array( __( 'Category proposal not approved', 'merchboard' ), __( 'When we cannot approve a change you proposed, with the reason.', 'merchboard' ) ),
			'product_linked'        => array( __( 'New product in your shop', 'merchboard' ), __( 'When a product is added to your shop.', 'merchboard' ) ),
			'impersonation_started' => array( __( 'We looked at your dashboard', 'merchboard' ), __( 'When our team opens your dashboard to help you.', 'merchboard' ) ),
			'news_published'        => array( __( 'News', 'merchboard' ), __( 'When we have news for you.', 'merchboard' ) ),
			'chatbot_lost_access'   => array( __( 'Your chatbot lost access', 'merchboard' ), __( 'When a platform stops your chatbot from reaching your channel. You get one e-mail until it is connected again.', 'merchboard' ) ),
			'webhook_paused'        => array( __( 'Your webhook was paused', 'merchboard' ), __( 'When one of your webhooks is paused because 10 deliveries in a row failed.', 'merchboard' ) ),
			'youtube_terms_renewed' => array( __( 'New YouTube terms', 'merchboard' ), __( 'When the terms of the YouTube chatbot change and you need to accept them again.', 'merchboard' ) ),
			'designer_submitted'    => array( __( 'Design sent for approval', 'merchboard' ), __( 'A receipt when you send a new product or a change for approval.', 'merchboard' ) ),
			'designer_approved'     => array( __( 'Your product is in the shop', 'merchboard' ), __( 'When we have approved your new product and it is in the shop.', 'merchboard' ) ),
			'designer_change_approved' => array( __( 'Your change is in the shop', 'merchboard' ), __( 'When we have approved a change of your product and it is in the shop.', 'merchboard' ) ),
			'designer_rejected'     => array( __( 'Design not approved', 'merchboard' ), __( 'When we cannot approve your design, with the reason.', 'merchboard' ) ),
			'designer_price_changed' => array( __( 'New price on your product', 'merchboard' ), __( 'When the base price changes, so the price in the shop changes too. Your profit stays the same.', 'merchboard' ) ),
			default                 => null,
		};
	}
}
