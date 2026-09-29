/**
 * The editor sidebar panel.
 *
 * Implements SPEC.md §14.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import {
	Button,
	CheckboxControl,
	Notice,
	PanelRow,
	SelectControl,
	Spinner,
	TextareaControl,
	TextControl,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { renderPreview } from './preview.js';

const data = window.scstudioEditor || {};

const STATUS_LABELS = {
	current: __( 'Up to date', 'social-card-studio' ),
	stale: __( 'Needs regenerating', 'social-card-studio' ),
	none: __( 'No card yet', 'social-card-studio' ),
	disabled: __( 'Switched off', 'social-card-studio' ),
};

const STATUS_COLOURS = {
	current: { background: '#edfaef', color: '#00450c' },
	stale: { background: '#fcf9e8', color: '#674600' },
	none: { background: '#f0f6fc', color: '#043959' },
	disabled: { background: '#f0f0f1', color: '#50575e' },
};

function StatusPill( { status } ) {
	const colours = STATUS_COLOURS[ status ] || STATUS_COLOURS.none;

	return (
		<span
			className="scstudio-pill"
			style={ {
				display: 'inline-block',
				padding: '1px 8px',
				borderRadius: '9px',
				fontSize: '11px',
				background: colours.background,
				color: colours.color,
			} }
		>
			{ STATUS_LABELS[ status ] || STATUS_LABELS.none }
		</span>
	);
}

export default function CardPanel() {
	const postId = data.postId || 0;

	const { postTitle } = useSelect(
		( select ) => ( {
			postTitle: select( editorStore )?.getEditedPostAttribute( 'title' ) || '',
		} ),
		[]
	);

	const overrides = data.state?.overrides || {};

	const [ headline, setHeadline ] = useState( overrides.headline || '' );
	const [ subhead, setSubhead ] = useState( overrides.subhead || '' );
	const [ altText, setAltText ] = useState( overrides.alt || data.state?.alt || '' );
	const [ template, setTemplate ] = useState( overrides.template || data.defaults?.template || '' );
	const [ disabled, setDisabled ] = useState( Boolean( data.state?.disabled ) );
	const [ showSubhead, setShowSubhead ] = useState( Boolean( overrides.subhead ) );
	const [ showAdvanced, setShowAdvanced ] = useState( false );

	const [ status, setStatus ] = useState( data.state?.status || 'none' );
	const [ cardUrl, setCardUrl ] = useState( data.state?.url || '' );
	const [ generated, setGenerated ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ copied, setCopied ] = useState( false );

	const previewRef = useRef( null );

	// The headline shown is what the card will actually use: the override if the
	// author typed one, otherwise the post title, exactly as {{share_headline|title}}
	// resolves on the server.
	const effectiveHeadline = headline || postTitle;
	const warnAt = data.defaults?.headlineWarnAt || 70;

	const tokens = useMemo(
		() => ( {
			...( data.tokens || {} ),
			title: postTitle,
			share_headline: effectiveHeadline,
			headline: effectiveHeadline,
			subhead,
		} ),
		[ postTitle, effectiveHeadline, subhead ]
	);

	// Live DOM preview, debounced so typing stays smooth.
	useEffect( () => {
		if ( generated || ! previewRef.current ) {
			return undefined;
		}

		const document_ = data.documents?.[ template ] || data.documents?.[ data.defaults?.template ];

		if ( ! document_ ) {
			return undefined;
		}

		const timer = setTimeout( () => {
			if ( previewRef.current ) {
				renderPreview( previewRef.current, document_, tokens, {
					images: data.images || {},
					fonts: data.fonts || {},
					scale: data.canvas?.scale || 0.5,
				} );
			}
		}, data.defaults?.previewDebounceMs || 250 );

		return () => clearTimeout( timer );
	}, [ tokens, template, generated ] );

	const request = useCallback( async ( path, body ) => {
		return apiFetch( {
			path,
			method: 'POST',
			data: { post_id: postId, ...body },
		} );
	}, [ postId ] );

	const onGenerate = useCallback( async () => {
		setBusy( true );
		setNotice( null );

		try {
			const result = await request( '/scstudio/v1/preview', {
				headline,
				subhead,
				template,
				alt: altText,
			} );

			if ( ! result.ok ) {
				setNotice( { type: 'error', text: result.error } );
			} else {
				// The DOM preview is discarded here, per SPEC §2.3: from now on the
				// author is looking at the real server render.
				setGenerated( result );

				if ( ! altText && result.alt ) {
					setAltText( result.alt );
				}
			}
		} catch ( error ) {
			setNotice( { type: 'error', text: error.message } );
		} finally {
			setBusy( false );
		}
	}, [ request, headline, subhead, template, altText ] );

	const onUseThis = useCallback( async () => {
		setBusy( true );
		setNotice( null );

		try {
			const result = await request( '/scstudio/v1/commit', {
				headline,
				subhead,
				template,
				alt: altText,
				disabled,
			} );

			setStatus( result.status || 'none' );
			setCardUrl( result.url || '' );
			setGenerated( null );

			setNotice(
				result.ok
					? { type: 'success', text: __( 'Card saved. It is live now, without waiting for the post to be updated.', 'social-card-studio' ) }
					: { type: 'error', text: result.error }
			);
		} catch ( error ) {
			setNotice( { type: 'error', text: error.message } );
		} finally {
			setBusy( false );
		}
	}, [ request, headline, subhead, template, altText, disabled ] );

	const onCopyUrl = useCallback( async () => {
		try {
			await window.navigator.clipboard.writeText( cardUrl );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} catch ( error ) {
			setNotice( { type: 'error', text: __( 'Could not copy the URL.', 'social-card-studio' ) } );
		}
	}, [ cardUrl ] );

	const headlineLength = effectiveHeadline.length;
	const overLength = headlineLength > warnAt;

	return (
		<div className="scstudio-panel">
			{ notice && (
				<Notice status={ notice.type } isDismissible onRemove={ () => setNotice( null ) }>
					{ notice.text }
				</Notice>
			) }

			<PanelRow>
				<StatusPill status={ disabled ? 'disabled' : status } />
			</PanelRow>

			<div style={ { marginBottom: '12px' } }>
				{ generated ? (
					<>
						<img
							src={ generated.image }
							alt={ generated.alt || __( 'Preview of the generated social card', 'social-card-studio' ) }
							width={ 600 }
							height={ 315 }
							style={ { width: '100%', height: 'auto', border: '1px solid #dcdcde', borderRadius: '3px' } }
						/>
						<p style={ { fontSize: '11px', color: '#646970', margin: '4px 0 0' } }>
							{ sprintf(
								/* translators: 1: engine name, 2: duration in ms, 3: file size in KB. */
								__( 'Rendered by %1$s in %2$d ms — %3$d KB', 'social-card-studio' ),
								generated.engine,
								generated.duration,
								Math.round( generated.bytes / 1024 )
							) }
						</p>
					</>
				) : (
					<>
						<div
							ref={ previewRef }
							role="img"
							aria-label={ __( 'Live preview of the social card. This is an approximation; use Generate to see the real image.', 'social-card-studio' ) }
							style={ { width: '100%', maxWidth: '600px' } }
						/>
						<p style={ { fontSize: '11px', color: '#646970', margin: '4px 0 0' } }>
							{ __( 'Live approximation. Generate to draw the real card.', 'social-card-studio' ) }
						</p>
					</>
				) }
			</div>

			<SelectControl
				label={ __( 'Template', 'social-card-studio' ) }
				value={ template }
				options={ data.templates || [] }
				onChange={ ( value ) => {
					setTemplate( value );
					setGenerated( null );
				} }
				__nextHasNoMarginBottom
			/>

			<TextControl
				label={ __( 'Headline', 'social-card-studio' ) }
				value={ headline }
				placeholder={ postTitle }
				onChange={ ( value ) => {
					setHeadline( value );
					setGenerated( null );
				} }
				help={
					overLength
						? sprintf(
								/* translators: 1: current length, 2: recommended maximum. */
								__( '%1$d characters. Past about %2$d the headline has to shrink to fit, which reads smaller in a feed.', 'social-card-studio' ),
								headlineLength,
								warnAt
						  )
						: sprintf(
								/* translators: 1: current length, 2: recommended maximum. */
								__( '%1$d of about %2$d characters. Leave empty to use the post title.', 'social-card-studio' ),
								headlineLength,
								warnAt
						  )
				}
				__nextHasNoMarginBottom
			/>

			<Button
				variant="link"
				onClick={ () => setShowSubhead( ! showSubhead ) }
				aria-expanded={ showSubhead }
				style={ { marginBottom: '8px' } }
			>
				{ showSubhead ? __( 'Hide subhead', 'social-card-studio' ) : __( 'Add a subhead', 'social-card-studio' ) }
			</Button>

			{ showSubhead && (
				<TextareaControl
					label={ __( 'Subhead', 'social-card-studio' ) }
					value={ subhead }
					rows={ 2 }
					onChange={ ( value ) => {
						setSubhead( value );
						setGenerated( null );
					} }
					help={ __( 'Only some templates show a subhead.', 'social-card-studio' ) }
					__nextHasNoMarginBottom
				/>
			) }

			<PanelRow>
				{ generated ? (
					<div style={ { display: 'flex', gap: '8px' } }>
						<Button variant="primary" onClick={ onUseThis } disabled={ busy }>
							{ __( 'Use this', 'social-card-studio' ) }
						</Button>
						<Button variant="tertiary" onClick={ () => setGenerated( null ) } disabled={ busy }>
							{ __( 'Discard', 'social-card-studio' ) }
						</Button>
						{ busy && <Spinner /> }
					</div>
				) : (
					<div style={ { display: 'flex', gap: '8px', alignItems: 'center' } }>
						<Button variant="primary" onClick={ onGenerate } disabled={ busy || disabled }>
							{ __( 'Generate', 'social-card-studio' ) }
						</Button>
						{ busy && <Spinner /> }
					</div>
				) }
			</PanelRow>

			{ /*
			  * AI section slot. M11 fills this with the three buttons from SPEC §14
			  * item 5, their cost estimates and the remaining workspace budget.
			  */ }
			<div className="scstudio-ai-slot" />

			<TextareaControl
				label={ __( 'Alt text', 'social-card-studio' ) }
				value={ altText }
				rows={ 2 }
				onChange={ setAltText }
				help={ __( 'Describes the card for screen readers and for people whose images do not load.', 'social-card-studio' ) }
				__nextHasNoMarginBottom
			/>

			<Button
				variant="link"
				onClick={ () => setShowAdvanced( ! showAdvanced ) }
				aria-expanded={ showAdvanced }
			>
				{ showAdvanced ? __( 'Hide advanced', 'social-card-studio' ) : __( 'Advanced', 'social-card-studio' ) }
			</Button>

			{ showAdvanced && (
				<div style={ { marginTop: '8px' } }>
					<CheckboxControl
						label={ __( 'No social card for this post', 'social-card-studio' ) }
						checked={ disabled }
						onChange={ setDisabled }
						help={ __( 'Whatever your SEO plugin or theme would have used is left alone.', 'social-card-studio' ) }
						__nextHasNoMarginBottom
					/>

					{ cardUrl && (
						<div style={ { display: 'flex', gap: '8px', marginTop: '8px' } }>
							<Button variant="secondary" href={ cardUrl } download target="_blank" rel="noreferrer">
								{ __( 'Download', 'social-card-studio' ) }
							</Button>
							<Button variant="secondary" onClick={ onCopyUrl }>
								{ copied ? __( 'Copied', 'social-card-studio' ) : __( 'Copy image URL', 'social-card-studio' ) }
							</Button>
						</div>
					) }
				</div>
			) }
		</div>
	);
}
