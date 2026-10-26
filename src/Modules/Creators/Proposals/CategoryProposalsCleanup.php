<?php
/**
 * The category proposals' part of deleting a creator.
 *
 * @package MerchBoard
 */

declare( strict_types=1 );

namespace MerchBoard\Modules\Creators\Proposals;

use MerchBoard\Modules\Creators\Creator;
use MerchBoard\Modules\Creators\CreatorDataCleaner;

defined( 'ABSPATH' ) || exit;

/**
 * Deleting a creator (ADR-076) deletes their category proposals and the
 * images still waiting (ADR-077). Approved images belong to the category
 * and stay. Idempotent.
 */
final class CategoryProposalsCleanup implements CreatorDataCleaner {

	/**
	 * Constructor.
	 *
	 * @param CategoryProposalRepository $proposals Storage.
	 * @param ProposalFiles              $files     Proposed images.
	 */
	public function __construct( private CategoryProposalRepository $proposals, private ProposalFiles $files ) {
	}

	/**
	 * The key in the audit record.
	 */
	public function key(): string {
		return 'proposals';
	}

	/**
	 * Nothing refuses it.
	 *
	 * @param Creator $creator The creator.
	 */
	public function check( Creator $creator ): void {
	}

	/**
	 * Deletes the proposals and their waiting images.
	 *
	 * @param Creator $creator The creator (inactive).
	 * @return array<string, int>
	 */
	public function remove( Creator $creator ): array {
		$files = 0;

		foreach ( $this->proposals->all_for( $creator->id ) as $proposal ) {
			$file = $proposal->file();

			if ( null !== $file && null !== $this->files->path( $file ) ) {
				$this->files->delete( $file );
				++$files;
			}
		}

		return array(
			'rows'  => $this->proposals->delete_for( $creator->id ),
			'files' => $files,
		);
	}
}
