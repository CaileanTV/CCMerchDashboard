/**
 * The confirmation before a designer product is deleted: a tick and a word,
 * so it cannot happen by a slip of the hand.
 */
import { useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Dialog } from '../../components/Dialog';

export interface ConfirmDeleteProps {
	/** The product's title. */
	readonly title: string;
	/** Whether it has been in the shop. */
	readonly inShop: boolean;
	/** Whether a deleted design is deleted for good. */
	readonly permanent?: boolean;
	/** Resolves to whether it was deleted; the dialog stays open if not. */
	readonly onConfirm: () => Promise< boolean >;
	readonly onClose: () => void;
}

/**
 * What deleting does.
 *
 * @param inShop    Whether it has been in the shop.
 * @param permanent Whether it is deleted for good.
 */
function message( inShop: boolean, permanent: boolean ): string {
	if ( permanent ) {
		return inShop
			? __(
					'The design is removed for good and cannot be brought back. Orders that have been placed keep their files.',
					'merchboard'
				)
			: __(
					'The design and its files are removed for good and cannot be brought back.',
					'merchboard'
				);
	}

	return inShop
		? __(
				'The product leaves the shop at once and moves to your deleted designs, where you can see it again. Orders that have been placed are not affected.',
				'merchboard'
			)
		: __(
				'The design moves to your deleted designs, where you can see it again or delete it for good.',
				'merchboard'
			);
}

/**
 * The dialog.
 *
 * @param props           Props.
 * @param props.title     The product's title.
 * @param props.inShop    Whether it has been in the shop.
 * @param props.permanent Whether it is deleted for good.
 * @param props.onConfirm Deletes it.
 * @param props.onClose   Closes the dialog.
 */
export function ConfirmDelete( props: ConfirmDeleteProps ) {
	const word = __( 'DELETE', 'merchboard' );
	const label = props.permanent
		? __( 'Delete for good', 'merchboard' )
		: __( 'Delete product', 'merchboard' );
	const [ ticked, setTicked ] = useState( false );
	const [ typed, setTyped ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ failed, setFailed ] = useState( false );
	const inputId = useId();
	const tickId = useId();

	const confirm = async () => {
		setBusy( true );
		setFailed( false );

		if ( ! ( await props.onConfirm() ) ) {
			setBusy( false );
			setFailed( true );
		}
	};

	return (
		<Dialog
			title={ sprintf(
				/* translators: %s: product title. */
				__( 'Delete %s?', 'merchboard' ),
				props.title
			) }
			closeLabel={ __( 'Cancel', 'merchboard' ) }
			onClose={ props.onClose }
			actions={
				<Button
					variant="danger"
					onClick={ () => void confirm() }
					isBusy={ busy }
					disabled={
						! ticked || typed.trim().toUpperCase() !== word || busy
					}
				>
					{ label }
				</Button>
			}
		>
			<p>{ message( props.inShop, props.permanent === true ) }</p>
			{ failed ? (
				<Alert tone="danger">
					{ __(
						'The product could not be deleted. If it is on its way to the shop, try again in a minute.',
						'merchboard'
					) }
				</Alert>
			) : null }
			<p>
				<input
					id={ tickId }
					type="checkbox"
					checked={ ticked }
					onChange={ () => setTicked( ! ticked ) }
				/>{ ' ' }
				<label htmlFor={ tickId }>
					{ __( 'Yes, I want to delete this.', 'merchboard' ) }
				</label>
			</p>
			<div className="merchboard-field">
				<label htmlFor={ inputId } className="merchboard-field__label">
					{ sprintf(
						/* translators: %s: the word to type. */
						__( 'Type %s to confirm', 'merchboard' ),
						word
					) }
				</label>
				<input
					id={ inputId }
					className="merchboard-input"
					value={ typed }
					autoComplete="off"
					onChange={ ( event ) => setTyped( event.target.value ) }
				/>
			</div>
		</Dialog>
	);
}
