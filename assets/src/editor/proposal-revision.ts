let proposalRevision = '0';

/**
 * Revision loaded with this editor. The native post save sends it on the same request.
 *
 * @param value Revision stored with the post when the editor opened.
 */
export function setProposalRevision( value: string ): void {
	proposalRevision = value;
}

export function currentProposalRevision(): string {
	return proposalRevision;
}

/**
 * Header added to the native post or page save. Autosaves are left alone.
 *
 * @param path   Request path.
 * @param method HTTP method.
 */
export function proposalRevisionHeaders(
	path: string,
	method: string
): Record< string, string > | null {
	const nativeSave =
		( method === 'POST' || method === 'PUT' ) &&
		/\/wp\/v2\/(posts|pages)\/\d+(?:\?|$)/.test( path ) &&
		! path.includes( '/autosaves' );
	if ( ! nativeSave ) {
		return null;
	}
	return { 'X-Zoop-Proposal-Revision': currentProposalRevision() };
}
