/**
 * Editor-facing details of a blocked published-page check.
 *
 * Reason codes stay on the API response and are not copied into this shape.
 */

export interface FetchFailureDetail {
	message: string;
	retainedNotice: string;
	guidanceUrl: string;
	retry: boolean;
}

interface FetchErrorData {
	retained_notice?: unknown;
	fetch_guidance_url?: unknown;
}

function text( value: unknown ): string {
	return typeof value === 'string' ? value : '';
}

/**
 * Read the safe fields from a failed analyse request.
 *
 * Returns null for any other failure, so a missing phrase does not offer a retry.
 *
 * @param error Rejected analyse request.
 */
export function fetchFailureDetail(
	error: unknown
): FetchFailureDetail | null {
	if ( ! error || typeof error !== 'object' || ! ( 'code' in error ) ) {
		return null;
	}
	const code = String( ( error as { code: unknown } ).code );
	if (
		code !== 'seostudio_fetch_blocked' &&
		code !== 'seostudio_fetch_failed'
	) {
		return null;
	}
	const data =
		'data' in error && error.data && typeof error.data === 'object'
			? ( error.data as FetchErrorData )
			: {};
	const message =
		'message' in error
			? text( ( error as { message: unknown } ).message )
			: '';

	return {
		message,
		retainedNotice: text( data.retained_notice ),
		guidanceUrl: text( data.fetch_guidance_url ),
		retry: true,
	};
}
