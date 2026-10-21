import { renderToStaticMarkup } from 'react-dom/server';
import { AssessmentList } from '../../assets/src/shared/ui/AssessmentList';
import { CategoryBar } from '../../assets/src/shared/ui/CategoryBar';
import { RunProgress } from '../../assets/src/shared/ui/RunProgress';
import { ScoreRing } from '../../assets/src/shared/ui/ScoreRing';
import { SnippetPreview } from '../../assets/src/shared/ui/SnippetPreview';
import { SocialPreview } from '../../assets/src/shared/ui/SocialPreview';
import { TrafficLight } from '../../assets/src/shared/ui/TrafficLight';
import { VariableInput } from '../../assets/src/shared/ui/VariableInput';

describe( 'shared UI', () => {
	it( 'renders an empty score and a scored ring', () => {
		expect(
			renderToStaticMarkup(
				<ScoreRing value={ null } denominator={ null } />
			)
		).toContain( 'Not analysed yet' );
		expect(
			renderToStaticMarkup(
				<ScoreRing
					value={ null }
					denominator={ null }
					label="Not enough evidence yet"
				/>
			)
		).toContain( 'Not enough evidence yet' );
		expect(
			renderToStaticMarkup(
				<ScoreRing
					value={ null }
					denominator={ null }
					label="Not enough evidence yet"
				/>
			)
		).toContain( 'is-unavailable' );
		expect(
			renderToStaticMarkup( <ScoreRing value={ 0 } denominator={ 80 } /> )
		).toContain( '>0<' );
		expect(
			renderToStaticMarkup(
				<ScoreRing value={ 82 } denominator={ 100 } />
			)
		).toContain( '82' );
	} );

	it( 'names every traffic-light rating', () => {
		const html = renderToStaticMarkup(
			<TrafficLight rating="green" label="SEO" testId="seo-light" />
		);
		expect( html ).toContain( 'Good' );
		expect( html ).toContain( 'data-rating="green"' );
		expect(
			renderToStaticMarkup( <TrafficLight rating={ null } label="SEO" /> )
		).toContain( 'Not analysed yet' );
	} );

	it( 'expands an assessment with its recommendation', () => {
		const html = renderToStaticMarkup(
			<AssessmentList
				items={ [
					{
						id: 'title.entire_keyphrase',
						category: 'title',
						status: 'fail',
						earned: 0,
						max: 6,
						recommendation:
							'Include the full keyphrase in the title.',
						explanation:
							'The full keyphrase is missing from the title.',
					},
				] }
			/>
		);
		expect( html ).toContain( 'Target phrase in SEO title' );
		expect( html ).toContain( 'data-assessment="title.entire_keyphrase"' );
		expect( html ).toContain( 'Include the full keyphrase in the title.' );
		expect( html ).toContain( 'Poor' );
		expect( html ).toContain( 'What Zoop found:' );
	} );

	it( 'does not call a skipped check “not analysed yet”', () => {
		const html = renderToStaticMarkup(
			<AssessmentList
				items={ [
					{
						id: 'code.stylesheet',
						status: 'not_checked',
						explanation:
							'A full-page fetch is required before the stylesheet check can run.',
					},
				] }
			/>
		);
		expect( html ).toContain( 'Not checked in this mode' );
		expect( html ).not.toContain( 'Not analysed yet' );
	} );

	it( 'shows category progress, previews, variables, and a run', () => {
		expect(
			renderToStaticMarkup(
				<CategoryBar label="title" earned={ 6 } max={ 10 } />
			)
		).toContain( 'title' );
		expect(
			renderToStaticMarkup(
				<SnippetPreview
					title="Rank tracker"
					url="https://example.test/rank-tracker"
					description="A guide."
					viewport="mobile"
				/>
			)
		).toContain( 'Rank tracker' );
		expect(
			renderToStaticMarkup(
				<SocialPreview title="Rank tracker" description="A guide." />
			)
		).toContain( 'No image' );
		expect(
			renderToStaticMarkup(
				<VariableInput
					id="title"
					label="SEO title"
					value="%%title%%"
					onChange={ () => undefined }
				/>
			)
		).toContain( '%%sitename%%' );
		expect(
			renderToStaticMarkup( <RunProgress processed={ 1 } total={ 4 } /> )
		).toContain( '1 of 4' );
	} );
} );
