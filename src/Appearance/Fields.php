<?php
/**
 * Meta keys for a search-appearance proposal.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Appearance;

/**
 * Proposal text stays separate from the title and description Zoop prints.
 */
final class Fields {
	public const TITLE = '_seostudio_title_proposal';

	public const DESCRIPTION = '_seostudio_description_proposal';

	public const SAVED = '_seostudio_appearance_saved';

	public const PREVIOUS_TITLE = '_seostudio_title_previous';

	public const PREVIOUS_DESCRIPTION = '_seostudio_description_previous';

	public const PREVIOUS_SAVED = '_seostudio_appearance_previous_saved';

	public const PREVIOUS_TARGET = '_seostudio_appearance_previous_target';

	public const SUGGESTION_BASELINE_TITLE = '_seostudio_suggestion_baseline_title';

	public const SUGGESTION_BASELINE_DESCRIPTION = '_seostudio_suggestion_baseline_description';

	public const SUGGESTION_BASELINE_SAVED = '_seostudio_suggestion_baseline_saved';
}
