import type { ButtonHTMLAttributes } from 'react';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';

export interface ButtonProps extends ButtonHTMLAttributes< HTMLButtonElement > {
	readonly variant?: ButtonVariant;
	readonly size?: 'sm' | 'md';
	/** Shows a busy state and blocks further clicks. */
	readonly isBusy?: boolean;
}

/**
 * Native `<button>` (keyboard and screen reader support for free), type
 * "button" unless a form submit is requested explicitly.
 */
export function Button( {
	variant = 'primary',
	size = 'md',
	isBusy = false,
	className,
	type = 'button',
	disabled,
	children,
	...rest
}: ButtonProps ) {
	const classes = [
		'merchboard-button',
		`merchboard-button--${ variant }`,
		`merchboard-button--${ size }`,
		className,
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<button
			{ ...rest }
			type={ type }
			className={ classes }
			disabled={ disabled === true || isBusy }
			aria-busy={ isBusy || undefined }
		>
			{ children }
		</button>
	);
}
