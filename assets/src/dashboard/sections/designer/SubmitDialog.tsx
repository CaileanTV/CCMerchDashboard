/**
 * "Send for approval" as a dialog (ADR-098): the order of the colours, the
 * product's main image in the shop and a summary, then a confirmation when
 * the creator's profit is 0.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import type { DesignerBase } from '../../api/designer';
import { Button } from '../../components/Button';
import { Dialog } from '../../components/Dialog';
import { designName } from './ColorDesigns';
import { designsOf, partOf, shifted } from './design-model';
import type { DesignDocument } from './design-model';
import { ImagePicker } from './ImagePicker';
import type { ImageChoice } from './ImagePicker';
import { IconButton } from './LayerList';

export interface ColorOrderProps {
	readonly base: DesignerBase;
	readonly colors: readonly string[];
	readonly onColors: ( value: readonly string[] ) => void;
	/** The whole design: each colour's design is named when there are colour designs. */
	readonly design?: DesignDocument;
}

/**
 * The chosen colours in the shop's order, with arrows to move them.
 *
 * @param props Props.
 */
export function ColorOrder( props: ColorOrderProps ) {
	const chosen = props.colors.flatMap( ( key ) =>
		props.base.colors.filter( ( color ) => color.key === key )
	);
	const named =
		props.design !== undefined && designsOf( props.design ).length > 0;
	const move = ( index: number, step: -1 | 1 ) =>
		props.onColors(
			shifted(
				chosen.map( ( item ) => item.key ),
				index,
				step
			)
		);

	return (
		<div className="merchboard-product-designer__order">
			<span className="merchboard-field__label">
				{ __( 'Order of the colours', 'merchboard' ) }
			</span>
			<p className="merchboard-product-designer__hint">
				{ __(
					'The shop shows the colours in this order. The first is the one customers see first.',
					'merchboard'
				) }
			</p>
			<ol>
				{ chosen.map( ( color, index ) => (
					<li key={ color.key }>
						<span
							className="merchboard-product-designer__swatch merchboard-product-designer__swatch--small"
							style={ { background: color.hex } }
							aria-hidden="true"
						/>
						<span>{ color.name }</span>
						{ named && props.design !== undefined ? (
							<span className="merchboard-product-designer__hint">
								{ designName(
									props.design,
									partOf( props.design, color.key )
								) }
							</span>
						) : null }
						<IconButton
							icon="up"
							label={ sprintf(
								/* translators: %s: colour name. */ __(
									'Move %s up',
									'merchboard'
								),
								color.name
							) }
							disabled={ index === 0 }
							onClick={ () => move( index, -1 ) }
						/>
						<IconButton
							icon="down"
							label={ sprintf(
								/* translators: %s: colour name. */ __(
									'Move %s down',
									'merchboard'
								),
								color.name
							) }
							disabled={ index === chosen.length - 1 }
							onClick={ () => move( index, 1 ) }
						/>
					</li>
				) ) }
			</ol>
		</div>
	);
}

export interface SubmitDialogProps {
	readonly base: DesignerBase;
	readonly colors: readonly string[];
	readonly onColors: ( value: readonly string[] ) => void;
	readonly design?: DesignDocument;
	/** The mockups the main image can be (ADR-096). */
	readonly images: readonly ImageChoice[];
	/** The main image; null for the first colour's first side. */
	readonly image: string | null;
	readonly onImage: ( value: string ) => void;
	/** Draws a choice of the main image (`data:`). */
	readonly imageDraw?: ( value: string ) => Promise< string | null >;
	/** Changes when what imageDraw() shows changes. */
	readonly imageDrawKey?: unknown;
	/** How long the design must be still before the images are drawn (ms). */
	readonly imageDrawDelay?: number;
	/** Files, price and notes shown above the buttons. */
	readonly summary: ReactNode;
	/** Whether the creator's profit is 0: "Send" asks first. */
	readonly zeroProfit: boolean;
	/** The profit 0 as money, e.g. "0,00 kr.". */
	readonly zeroText: string;
	readonly onSend: () => void;
	readonly onCancel: () => void;
	/** Back from the profit question: the creator sets a profit. */
	readonly onSetProfit: () => void;
}

/**
 * The dialog before a design is sent for approval.
 *
 * @param props Props.
 */
export function SubmitDialog( props: SubmitDialogProps ) {
	const [ step, setStep ] = useState< 'choose' | 'zero-profit' >( 'choose' );

	if ( step === 'zero-profit' ) {
		return (
			<Dialog
				key="zero-profit"
				title={ __( 'Send without profit?', 'merchboard' ) }
				closeLabel={ __( 'Back and set a profit', 'merchboard' ) }
				onClose={ props.onSetProfit }
				actions={
					<Button onClick={ props.onSend }>
						{ __( 'Yes, send without profit', 'merchboard' ) }
					</Button>
				}
			>
				<p>
					{ sprintf(
						/* translators: %s: the profit 0 as money, e.g. "0,00 kr.". */ __(
							'Your profit is %s. Customers pay only the price without profit, and you earn nothing on the product. Do you want to put the product up without profit?',
							'merchboard'
						),
						props.zeroText
					) }
				</p>
			</Dialog>
		);
	}

	return (
		<Dialog
			key="choose"
			wide
			title={ __( 'Send for approval', 'merchboard' ) }
			closeLabel={ __( 'Cancel', 'merchboard' ) }
			onClose={ props.onCancel }
			actions={
				<Button
					onClick={ () =>
						props.zeroProfit
							? setStep( 'zero-profit' )
							: props.onSend()
					}
				>
					{ __( 'Send', 'merchboard' ) }
				</Button>
			}
		>
			<div className="merchboard-product-designer__stack">
				{ props.colors.length > 1 ? (
					<ColorOrder
						base={ props.base }
						colors={ props.colors }
						onColors={ props.onColors }
						{ ...( props.design === undefined
							? {}
							: { design: props.design } ) }
					/>
				) : null }
				{ props.images.length > 1 ? (
					<section className="merchboard-product-designer__main-image">
						<h3 className="merchboard-field__label">
							{ __(
								'Main product image in the shop',
								'merchboard'
							) }
						</h3>
						<p className="merchboard-product-designer__hint">
							{ __(
								'Tap an image to make it the product’s main image in the shop.',
								'merchboard'
							) }
						</p>
						<ImagePicker
							choices={ props.images }
							selected={ props.image }
							onSelect={ props.onImage }
							{ ...( props.imageDraw === undefined
								? {}
								: { draw: props.imageDraw } ) }
							drawKey={ props.imageDrawKey }
							{ ...( props.imageDrawDelay === undefined
								? {}
								: { delay: props.imageDrawDelay } ) }
						/>
					</section>
				) : null }
				<div>{ props.summary }</div>
			</div>
		</Dialog>
	);
}
