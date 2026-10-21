declare module '@wordpress/api-fetch' {
	interface ApiFetchOptions {
		path?: string;
		url?: string;
		method?: string;
		data?: unknown;
		headers?: Record< string, string > | Headers;
	}

	function apiFetch< T >( options: {
		path: string;
		method?: string;
		data?: unknown;
		headers?: Record< string, string >;
	} ): Promise< T >;

	namespace apiFetch {
		function use(
			middleware: (
				options: ApiFetchOptions,
				next: ( options: ApiFetchOptions ) => Promise< unknown >
			) => Promise< unknown >
		): void;
	}

	export default apiFetch;
}

declare module '@wordpress/core-data' {
	export function useEntityProp(
		kind: string,
		name: string,
		prop: string
	): [
		Record< string, unknown > | undefined,
		( value: Record< string, unknown > ) => void,
	];
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
