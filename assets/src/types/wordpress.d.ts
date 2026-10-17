declare module '@wordpress/api-fetch' {
	export default function apiFetch< T >( options: {
		path: string;
		method?: string;
		data?: unknown;
	} ): Promise< T >;
}

declare module '@wordpress/components' {
	interface ControlProps {
		label: string;
		help?: string;
		className?: string;
		id?: string;
	}

	export function Button( props: {
		variant?: 'primary' | 'secondary' | 'tertiary' | 'link';
		disabled?: boolean;
		onClick?: () => void;
		children?: import( 'react' ).ReactNode;
	} ): JSX.Element;

	export function TextControl(
		props: ControlProps & {
			value: string;
			onChange: ( value: string ) => void;
		}
	): JSX.Element;

	export function TextareaControl(
		props: ControlProps & {
			value: string;
			onChange: ( value: string ) => void;
		}
	): JSX.Element;

	export function SelectControl(
		props: ControlProps & {
			value: string;
			options: Array< { label: string; value: string } >;
			onChange: ( value: string ) => void;
		}
	): JSX.Element;

	export function CheckboxControl(
		props: ControlProps & {
			checked: boolean;
			onChange: ( value: boolean ) => void;
		}
	): JSX.Element;

	export function Notice( props: {
		status?: 'info' | 'warning' | 'success' | 'error';
		isDismissible?: boolean;
		children?: import( 'react' ).ReactNode;
	} ): JSX.Element;
}

declare module '@wordpress/data' {
	export function useSelect< T >(
		selector: ( select: ( store: string ) => unknown ) => T,
		deps: readonly unknown[]
	): T;
}

declare module '@wordpress/editor' {
	export function PluginSidebar( props: {
		name: string;
		title: string;
		icon?: string;
		children?: import( 'react' ).ReactNode;
	} ): JSX.Element;
}

declare module '@wordpress/plugins' {
	export function registerPlugin(
		name: string,
		settings: { render: () => JSX.Element }
	): void;
}
