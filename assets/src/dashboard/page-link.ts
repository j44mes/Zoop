/**
 * Admin URL for one page workspace.
 *
 * @param postId WordPress post id.
 */
export function pageWorkspaceUrl( postId: number ): string {
	return `admin.php?page=seostudio&view=page&post=${ postId }`;
}
