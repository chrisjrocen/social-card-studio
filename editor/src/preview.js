/**
 * DOM preview renderer.
 *
 * Implements the browser half of SPEC.md §2.3: the same Card Document, drawn as
 * absolutely-positioned DOM at half scale, using the same layout engine the server
 * uses. It is explicitly a preview — on Generate the editor throws it away and shows
 * the real server render.
 */

import { layout, canvasMetrics } from './layout.js';

const TOKEN_PATTERN = /\{\{\s*([a-z][a-z0-9_]*(?:\s*\|\s*[a-z][a-z0-9_]*)*)\s*\}\}/g;

/** Substitutes tokens, matching TokenResolver::apply(). */
export function applyTokens( template, tokens ) {
	if ( ! template ) {
		return '';
	}

	return String( template )
		.replace( TOKEN_PATTERN, ( _match, names ) => {
			for ( const name of names.split( '|' ) ) {
				const value = tokens[ name.trim() ];
				if ( value ) {
					return value;
				}
			}
			return '';
		} )
		.replace( /\s+/gu, ' ' )
		.trim();
}

/** Whether a value is a lone `{{token}}`, matching Schema::is_color_token(). */
function isColorToken( value ) {
	return /^\{\{[a-z][a-z0-9_]*(\|[a-z][a-z0-9_]*)*\}\}$/.test( String( value || '' ) );
}

function resolveColor( value, tokens ) {
	if ( ! value ) {
		return '';
	}
	return isColorToken( value ) ? applyTokens( value, tokens ) : String( value );
}

/** Converts #RRGGBBAA to rgba(), which CSS understands everywhere. */
function cssColor( value ) {
	const hex = String( value || '' ).trim();

	if ( /^#[0-9a-f]{8}$/i.test( hex ) ) {
		const r = parseInt( hex.slice( 1, 3 ), 16 );
		const g = parseInt( hex.slice( 3, 5 ), 16 );
		const b = parseInt( hex.slice( 5, 7 ), 16 );
		const a = parseInt( hex.slice( 7, 9 ), 16 ) / 255;
		return `rgba(${ r },${ g },${ b },${ a.toFixed( 4 ) })`;
	}

	return hex;
}

function fontFor( fonts, role, weight ) {
	const key = `${ role || 'body' }-${ weight || 400 }`;
	const entry = fonts[ key ] || fonts[ `${ role || 'body' }-400` ];

	return {
		family: entry?.family || 'system-ui',
		weight: entry?.weight || weight || 400,
		url: entry?.url || '',
	};
}

/** Builds the CSS gradient for a gradient layer. */
function gradientCss( layer, tokens ) {
	const stops = ( layer.stops || [] )
		.slice()
		.sort( ( a, b ) => a.at - b.at )
		.map( ( stop ) => `${ cssColor( resolveColor( stop.color, tokens ) ) } ${ ( stop.at * 100 ).toFixed( 2 ) }%` );

	if ( stops.length < 2 ) {
		return '';
	}

	// The server snaps to quarter turns, so the preview must too or the two disagree.
	const angle = ( ( ( layer.angle ?? 90 ) % 360 ) + 360 ) % 360;
	const snapped = ( Math.round( angle / 90 ) * 90 ) % 360;
	const direction = { 0: 'to right', 90: 'to bottom', 180: 'to left', 270: 'to top' }[ snapped ] || 'to bottom';

	return `linear-gradient(${ direction }, ${ stops.join( ', ' ) })`;
}

function place( element, box, scale ) {
	element.style.position = 'absolute';
	element.style.left = `${ box.x * scale }px`;
	element.style.top = `${ box.y * scale }px`;
	element.style.width = `${ box.w * scale }px`;
	element.style.height = `${ box.h * scale }px`;
}

function specFromLayer( layer ) {
	const size = layer.size || {};

	return {
		box: layer.box,
		sizeMin: size.min ?? 16,
		sizeMax: size.max ?? 64,
		autofit: size.autofit !== false,
		lineHeight: layer.line_height ?? 1.2,
		tracking: layer.tracking ?? 0,
		align: layer.align || 'left',
		verticalAlign: layer.vertical_align || 'top',
		maxLines: layer.max_lines ?? 3,
		overflow: layer.overflow || 'ellipsis',
		transform: layer.transform || 'none',
	};
}

/**
 * Draws a Card Document into a container element.
 *
 * @param {HTMLElement} container Element to draw into. Its contents are replaced.
 * @param {Object}      document_ The Card Document.
 * @param {Object}      tokens    Resolved token map.
 * @param {Object}      options   { images, fonts, scale }.
 * @return {Object} { ok, error, lines } — lines is per text layer, for parity checks.
 */
export function renderPreview( container, document_, tokens, options = {} ) {
	const scale = options.scale ?? 0.5;
	const images = options.images || {};
	const fonts = options.fonts || {};
	const canvas = document_.canvas || { w: 1200, h: 630, bg: '#000000' };

	// replaceChildren rather than innerHTML: nothing here is ever built from a string,
	// and every piece of author text below goes in through textContent.
	container.replaceChildren();
	container.style.position = 'relative';
	container.style.overflow = 'hidden';
	container.style.width = `${ canvas.w * scale }px`;
	container.style.height = `${ canvas.h * scale }px`;
	container.style.background = cssColor( resolveColor( canvas.bg, tokens ) ) || '#000';
	container.style.borderRadius = '3px';

	const metrics = canvasMetrics();
	const lines = {};

	for ( const layer of document_.layers || [] ) {
		if ( layer.hidden ) {
			continue;
		}

		try {
			if ( layer.type === 'image' ) {
				const source = layer.source || '';
				const name = source.replace( /[{}]/g, '' ).split( '|' )[ 0 ];
				const url = images[ name ];

				if ( ! url ) {
					continue;
				}

				const img = window.document.createElement( 'img' );
				img.src = url;
				img.alt = '';
				place( img, layer.box, scale );
				img.style.objectFit = layer.fit === 'contain' ? 'contain' : 'cover';
				img.style.opacity = String( layer.opacity ?? 1 );

				if ( layer.shape === 'circle' ) {
					img.style.borderRadius = '50%';
				} else if ( layer.shape === 'rounded' ) {
					img.style.borderRadius = `${ ( layer.radius ?? 0 ) * scale }px`;
				}

				if ( layer.effects?.grayscale ) {
					img.style.filter = 'grayscale(1)';
				}

				container.appendChild( img );
				continue;
			}

			if ( layer.type === 'gradient' ) {
				const div = window.document.createElement( 'div' );
				place( div, layer.box, scale );
				div.style.backgroundImage = gradientCss( layer, tokens );
				container.appendChild( div );
				continue;
			}

			if ( layer.type === 'shape' ) {
				const div = window.document.createElement( 'div' );
				place( div, layer.box, scale );
				div.style.background = cssColor( resolveColor( layer.fill, tokens ) );
				div.style.opacity = String( layer.opacity ?? 1 );

				if ( layer.shape === 'circle' ) {
					div.style.borderRadius = '50%';
				} else if ( layer.shape === 'rounded-rect' ) {
					div.style.borderRadius = `${ ( layer.radius ?? 0 ) * scale }px`;
				}

				container.appendChild( div );
				continue;
			}

			if ( layer.type === 'text' ) {
				const text = applyTokens( layer.content, tokens );

				// SPEC §6.3 step 5: an empty layer is collapsed, not drawn.
				if ( ! text ) {
					continue;
				}

				const font = fontFor( fonts, layer.font?.role, layer.font?.weight );
				const spec = specFromLayer( layer );
				const result = layout( text, spec, font, metrics, false );

				if ( result.collapsed ) {
					continue;
				}

				lines[ layer.id ] = {
					size: result.size,
					lines: result.lines.map( ( line ) => line.text ),
				};

				for ( const line of result.lines ) {
					const span = window.document.createElement( 'span' );
					span.textContent = line.text;
					span.style.position = 'absolute';
					span.style.left = `${ line.x * scale }px`;
					// The engine gives a baseline; CSS positions a box, so the span is
					// placed by its top edge and the baseline is reached with a matching
					// line-height.
					span.style.top = `${ ( line.baseline - result.size ) * scale }px`;
					span.style.lineHeight = `${ result.size * scale }px`;
					span.style.fontFamily = `'${ font.family }', system-ui, sans-serif`;
					span.style.fontWeight = String( font.weight );
					span.style.fontSize = `${ result.size * scale }px`;
					span.style.letterSpacing = `${ spec.tracking * result.size * scale }px`;
					span.style.color = cssColor( resolveColor( layer.color, tokens ) ) || '#fff';
					span.style.whiteSpace = 'pre';

					if ( layer.shadow?.blur ) {
						span.style.textShadow = `${ ( layer.shadow.x ?? 0 ) * scale }px ${ ( layer.shadow.y ?? 0 ) * scale }px ${ ( layer.shadow.blur ?? 0 ) * scale }px ${ cssColor( layer.shadow.color ) }`;
					}

					container.appendChild( span );
				}
			}
		} catch ( error ) {
			// One bad layer must not blank the whole preview.
			return { ok: false, error: error.message, lines };
		}
	}

	return { ok: true, error: '', lines };
}
