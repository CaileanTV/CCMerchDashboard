import { Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/** The overlay's variables, in the order they are explained. */
export const OVERLAY_VARIABLES: readonly string[] = [
	'$UserName',
	'$Product',
	'$Quantity',
	'$Variation',
	'$Category',
	'$Price',
	'$LineTotal',
	'$Total',
];

/**
 * What a variable shows; null for one without an explanation.
 *
 * @param variable Variable, e.g. "$Price".
 */
export function describeVariable( variable: string ): string | null {
	switch ( variable ) {
		case '$UserName':
			return __(
				'The username the customer chose to show at checkout, or "Anonymous". Never the customer\'s real name.',
				'merchboard'
			);
		case '$Name':
		case '$FirstName':
			return __(
				'The same as $UserName (from the old alert plugin).',
				'merchboard'
			);
		case '$Product':
			return __( "The product's name.", 'merchboard' );
		case '$Quantity':
			return __( 'How many of the product were bought.', 'merchboard' );
		case '$Variation':
			return __(
				'The chosen variation, e.g. "Size: L"; empty for a product without variations.',
				'merchboard'
			);
		case '$Category':
			return __(
				"The product's categories, separated by commas.",
				'merchboard'
			);
		case '$Price':
			return __(
				'What the customer paid for one item, with VAT and after any discount, e.g. "249,00 kr.".',
				'merchboard'
			);
		case '$LineTotal':
			return __(
				'What the customer paid for this product in all: the price × the quantity.',
				'merchboard'
			);
		case '$Total':
			return __(
				'What the customer paid for everything from you in the order.',
				'merchboard'
			);
		case '$Products':
			return __(
				'Every product from you in the order, written as Merch Dashboard set it up (one message per order).',
				'merchboard'
			);
		case '$ItemCount':
			return __(
				'How many items from you the order has in all (one message per order).',
				'merchboard'
			);
		default:
			return null;
	}
}

/**
 * "What the variables mean": each variable with what it shows, folded up
 * (the project owner's wish 2026-10-23).
 *
 * @param props           Props.
 * @param props.variables The variables to explain, in order.
 */
export function TemplateVariables( {
	variables,
}: {
	readonly variables: readonly string[];
} ) {
	const explained = variables.filter(
		( variable ) => describeVariable( variable ) !== null
	);

	if ( explained.length === 0 ) {
		return null;
	}

	return (
		<details className="merchboard-alerts__variables">
			<summary>{ __( 'What the variables mean', 'merchboard' ) }</summary>
			<dl>
				{ explained.map( ( variable ) => (
					<Fragment key={ variable }>
						<dt>
							<code>{ variable }</code>
						</dt>
						<dd>{ describeVariable( variable ) }</dd>
					</Fragment>
				) ) }
			</dl>
		</details>
	);
}
