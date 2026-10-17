const allowed = [ 'phrases', 'checks', 'search', 'social', 'advanced' ];

/**
 * Choose a Zoop disclosure to open. The block editor removes the query argument
 * before the sidebar mounts, so the value printed with the page wins.
 *
 * @param configured Section localised by PHP.
 * @param search     Current location search string.
 */
export function requestedSection( configured: string, search: string ): string {
	const fromUrl =
		new URLSearchParams( search ).get( 'seostudio_section' ) || '';
	const candidate = configured || fromUrl;
	return allowed.includes( candidate ) ? candidate : '';
}
