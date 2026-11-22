/**
 * The request for a collection of designs (ADR-120): a new one with a name,
 * or designs added to one of the creator's own.
 */
import { useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { requestCollection } from '../../api/collections';
import type { CollectionsState } from '../../api/collections';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Dialog } from '../../components/Dialog';

/** Fewest designs of a new collection: one design is no choice. */
const MIN_NEW = 2;

/** Most designs of one request, as the server takes. */
const MAX_DESIGNS = 50;

/**
 * Why the request was refused.
 *
 * @param reason The server's reason code.
 */
function refusedText( reason: string | undefined ): string {
	switch ( reason ) {
		case 'product_not_usable':
			return __(
				'One of the products can no longer be collected. Close this window and try again.',
				'merchboard'
			);
		case 'product_requested':
			return __(
				'One of the products is already waiting in another request.',
				'merchboard'
			);
		case 'collection_unknown':
			return __(
				'The collection can no longer be changed here. Close this window and try again.',
				'merchboard'
			);
		default:
			return __(
				'The request could not be sent. Try again in a minute.',
				'merchboard'
			);
	}
}

export interface CollectionDialogProps {
	readonly state: CollectionsState;
	/** Called with the new state when the request is sent. */
	readonly onSent: ( state: CollectionsState ) => void;
	readonly onClose: () => void;
}

/**
 * The dialog.
 *
 * @param props         Props.
 * @param props.state   What can be chosen.
 * @param props.onSent  Takes the new state.
 * @param props.onClose Closes the dialog.
 */
export function CollectionDialog( {
	state,
	onSent,
	onClose,
}: CollectionDialogProps ) {
	// With too few products for a new one only an addition is possible.
	const canCreate = state.products.length >= MIN_NEW;
	const canAdd = state.collections.length > 0;
	const [ adding, setAdding ] = useState( ! canCreate && canAdd );
	const [ name, setName ] = useState( '' );
	const [ collectionId, setCollectionId ] = useState( 0 );
	const [ chosen, setChosen ] = useState< readonly number[] >( [] );
	const [ busy, setBusy ] = useState( false );
	const [ failed, setFailed ] = useState< string | null >( null );
	const id = useId();

	const ready =
		chosen.length <= MAX_DESIGNS &&
		( adding
			? collectionId > 0 && chosen.length > 0
			: name.trim() !== '' && chosen.length >= MIN_NEW );

	const toggle = ( productId: number ) =>
		setChosen( ( current ) =>
			current.includes( productId )
				? current.filter( ( item ) => item !== productId )
				: [ ...current, productId ]
		);

	const send = async () => {
		setBusy( true );
		setFailed( null );

		const result = await requestCollection(
			adding
				? { collectionId, productIds: chosen }
				: { name: name.trim(), productIds: chosen }
		);

		setBusy( false );

		if ( result.ok ) {
			onSent( result.data );
		} else {
			setFailed( refusedText( result.error.reason ) );
		}
	};

	return (
		<Dialog
			title={ __( 'Request a collection', 'merchboard' ) }
			closeLabel={ __( 'Cancel', 'merchboard' ) }
			onClose={ onClose }
			actions={
				<Button
					onClick={ () => void send() }
					isBusy={ busy }
					disabled={ ! ready || busy }
				>
					{ __( 'Send request', 'merchboard' ) }
				</Button>
			}
		>
			<p>
				{ __(
					'A collection is one product page where customers choose between your designs. Prices and your profit stay the same. While it is in the shop, the designs are not shown one by one. Nothing changes until we have approved your request.',
					'merchboard'
				) }
			</p>
			{ failed === null ? null : <Alert tone="danger">{ failed }</Alert> }
			{ canCreate && canAdd ? (
				<fieldset className="merchboard-field">
					<legend className="merchboard-field__label">
						{ __( 'What do you want?', 'merchboard' ) }
					</legend>
					<label htmlFor={ `${ id }-new` }>
						<input
							id={ `${ id }-new` }
							type="radio"
							name={ `${ id }-kind` }
							checked={ ! adding }
							onChange={ () => setAdding( false ) }
						/>{ ' ' }
						{ __( 'A new collection', 'merchboard' ) }
					</label>{ ' ' }
					<label htmlFor={ `${ id }-add` }>
						<input
							id={ `${ id }-add` }
							type="radio"
							name={ `${ id }-kind` }
							checked={ adding }
							onChange={ () => setAdding( true ) }
						/>{ ' ' }
						{ __( 'Add to one of my collections', 'merchboard' ) }
					</label>
				</fieldset>
			) : null }
			{ adding ? (
				<div className="merchboard-field">
					<label
						className="merchboard-field__label"
						htmlFor={ `${ id }-collection` }
					>
						{ __( 'Collection', 'merchboard' ) }
					</label>
					<select
						id={ `${ id }-collection` }
						className="merchboard-input merchboard-collection__target"
						value={ collectionId }
						onChange={ ( event ) =>
							setCollectionId( Number( event.target.value ) )
						}
					>
						<option value={ 0 } disabled>
							{ __( 'Choose a collection', 'merchboard' ) }
						</option>
						{ state.collections.map( ( collection ) => (
							<option
								key={ collection.id }
								value={ collection.id }
							>
								{ collection.name }
							</option>
						) ) }
					</select>
				</div>
			) : (
				<div className="merchboard-field">
					<label
						className="merchboard-field__label"
						htmlFor={ `${ id }-name` }
					>
						{ __( 'Name of the collection', 'merchboard' ) }
					</label>
					<input
						id={ `${ id }-name` }
						className="merchboard-input merchboard-collection__name"
						value={ name }
						maxLength={ 191 }
						autoComplete="off"
						onChange={ ( event ) => setName( event.target.value ) }
					/>
				</div>
			) }
			<fieldset className="merchboard-field">
				<legend className="merchboard-field__label">
					{ __( 'Designs', 'merchboard' ) }
				</legend>
				<ul className="merchboard-collection__designs">
					{ state.products.map( ( product ) => (
						<li key={ product.id }>
							<label htmlFor={ `${ id }-${ product.id }` }>
								<input
									id={ `${ id }-${ product.id }` }
									type="checkbox"
									value={ product.id }
									checked={ chosen.includes( product.id ) }
									onChange={ () => toggle( product.id ) }
								/>{ ' ' }
								{ product.name }
							</label>
						</li>
					) ) }
				</ul>
				<p className="merchboard-field__help">
					{ adding
						? sprintf(
								/* translators: %d: the most designs. */
								__(
									'Choose the designs to add, at most %d.',
									'merchboard'
								),
								MAX_DESIGNS
							)
						: sprintf(
								/* translators: 1: the fewest designs, 2: the most designs. */
								__(
									'Choose %1$d to %2$d designs of the same kind of product.',
									'merchboard'
								),
								MIN_NEW,
								MAX_DESIGNS
							) }
				</p>
			</fieldset>
		</Dialog>
	);
}
