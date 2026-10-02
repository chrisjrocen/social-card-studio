/**
 * Design screen live preview.
 *
 * Every preview is drawn by the server's renderer from the unsaved form values, so
 * what is shown is exactly what would be published. Requests are debounced, stale
 * ones are aborted, results are cached per input set, and gallery thumbnails are
 * drawn two at a time so a slow host is not handed six renders at once.
 */
( function ( $ ) {
	'use strict';

	const data = window.scstudioDesign;
	const form = document.getElementById( 'scstudio-design-form' );

	if ( ! data || ! form || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	const { __, sprintf } = window.wp.i18n;
	const apiFetch = window.wp.apiFetch;

	const DEBOUNCE_MS = 300;
	const THUMB_CONCURRENCY = 2;

	const cache = new Map();
	const postSelect = document.getElementById( 'scstudio-preview-post' );
	const slots = {
		post: document.querySelector( '.scstudio-preview[data-card="post"]' ),
		site: document.querySelector( '.scstudio-preview[data-card="site"]' ),
	};
	const controllers = { post: null, site: null };
	const shown = { post: '', site: '' };

	let timer = 0;
	let thumbGeneration = 0;

	/**
	 * The current form values that affect a render.
	 *
	 * @return {Object} Values.
	 */
	function values() {
		const checked = form.querySelector( 'input[name="default_template"]:checked' );
		const perType = {};

		form.querySelectorAll( 'select[data-post-type]' ).forEach( ( select ) => {
			perType[ select.dataset.postType ] = select.value;
		} );

		return {
			postId: parseInt( postSelect ? postSelect.value : '0', 10 ) || 0,
			defaultTemplate: checked ? checked.value : '',
			perType,
			siteTemplate: form.elements.site_template ? form.elements.site_template.value : '',
			siteHeadline: form.elements.site_headline ? form.elements.site_headline.value : '',
			accent: form.elements.accent ? form.elements.accent.value : '',
			quality: form.elements.quality ? parseInt( form.elements.quality.value, 10 ) || 0 : 0,
			altPattern: form.elements.alt_text_pattern ? form.elements.alt_text_pattern.value : '',
		};
	}

	/**
	 * Fetches a render, from the cache when the same inputs were drawn before.
	 *
	 * @param {Object}      body   Request body.
	 * @param {AbortSignal} signal Abort signal.
	 * @return {Promise<Object>} Preview payload.
	 */
	function render( body, signal ) {
		const key = JSON.stringify( body );

		if ( cache.has( key ) ) {
			return Promise.resolve( cache.get( key ) );
		}

		return apiFetch( { path: data.path, method: 'POST', data: body, signal } ).then( ( result ) => {
			// Only successes are cached, so a transient failure is retried next time.
			if ( result && result.ok ) {
				cache.set( key, result );
			}

			return result;
		} );
	}

	/**
	 * Selected preview post, from the localised list.
	 *
	 * @param {number} id Post ID.
	 * @return {Object|null} Post data.
	 */
	function postById( id ) {
		return data.posts.find( ( post ) => post.id === id ) || null;
	}

	/**
	 * Shows a payload in a large preview frame.
	 *
	 * @param {Element} slot   Figure element.
	 * @param {Object}  result Preview payload.
	 */
	function paint( slot, result ) {
		const img = slot.querySelector( 'img' );
		const status = slot.querySelector( '.scstudio-preview__status' );
		const alt = slot.querySelector( '.scstudio-preview__alt' );

		slot.classList.remove( 'is-loading' );

		if ( ! result || ! result.ok ) {
			img.hidden = true;
			status.hidden = false;
			status.textContent = sprintf(
				/* translators: %s: error message. */
				__( 'This preview could not be drawn: %s', 'social-card-studio' ),
				( result && result.error ) || __( 'unknown error', 'social-card-studio' )
			);
			alt.textContent = '';

			return;
		}

		img.src = result.image;
		img.alt = result.alt || '';
		img.hidden = false;
		status.hidden = true;
		alt.textContent = sprintf(
			/* translators: 1: alt text, 2: file size in KB. */
			__( 'Alt text: %1$s · %2$s KB', 'social-card-studio' ),
			result.alt || '—',
			Math.round( ( result.bytes || 0 ) / 1024 )
		);
	}

	/**
	 * Redraws one large preview if its inputs changed.
	 *
	 * @param {string} card 'post' or 'site'.
	 * @param {Object} body Request body.
	 */
	function updateSlot( card, body ) {
		const slot = slots[ card ];
		const key = JSON.stringify( body );

		if ( ! slot || key === shown[ card ] ) {
			return;
		}

		shown[ card ] = key;

		if ( controllers[ card ] ) {
			controllers[ card ].abort();
		}

		const controller = new window.AbortController();
		controllers[ card ] = controller;
		slot.classList.add( 'is-loading' );

		render( body, controller.signal )
			.then( ( result ) => {
				if ( controllers[ card ] === controller ) {
					paint( slot, result );
				}
			} )
			.catch( ( error ) => {
				if ( error && 'AbortError' === error.name ) {
					return;
				}

				// Let the next change retry rather than treating this input set as shown.
				shown[ card ] = '';
				paint( slot, { ok: false, error: error && error.message } );
			} );
	}

	/**
	 * Labels the post preview with which design it shows, and why.
	 *
	 * @param {Object} v Form values.
	 */
	function updateBadges( v ) {
		const slot = slots.post;

		if ( ! slot ) {
			return;
		}

		const badge = slot.querySelector( '.scstudio-preview__badge' );
		const note = slot.querySelector( '.scstudio-preview__note' );
		const post = postById( v.postId );
		const override = post ? v.perType[ post.type ] || '' : '';

		badge.hidden = '' === override;
		badge.textContent = override
			? sprintf(
				/* translators: 1: post type name, 2: design name. */
				__( '%1$s use %2$s', 'social-card-studio' ),
				data.types[ post.type ] || post.type,
				data.templates[ override ] || override
			)
			: '';

		const own = post ? post.ownTemplate : '';

		note.hidden = '' === own;
		note.textContent = own
			? sprintf(
				/* translators: %s: design name. */
				__( 'This post uses its own design (%s), chosen in the editor. Changes here will not affect it.', 'social-card-studio' ),
				data.templates[ own ] || own
			)
			: '';
	}

	/**
	 * Redraws the gallery thumbnails whose inputs changed, two at a time.
	 *
	 * @param {Object} v Form values.
	 */
	function updateThumbs( v ) {
		const generation = ++thumbGeneration;
		const queue = [];

		form.querySelectorAll( '.scstudio-gallery__thumb' ).forEach( ( thumb ) => {
			// Thumbnails only depend on the post, the accent and their own template.
			const body = { card: 'post', post_id: v.postId, template: thumb.dataset.template, accent: v.accent };
			const key = JSON.stringify( body );

			if ( thumb.dataset.key === key ) {
				return;
			}

			thumb.dataset.key = key;
			thumb.classList.add( 'is-loading' );
			queue.push( { thumb, body } );
		} );

		const next = () => {
			const job = queue.shift();

			// A newer round has started; leave the rest to it.
			if ( ! job || generation !== thumbGeneration ) {
				return Promise.resolve();
			}

			return render( job.body )
				.then( ( result ) => {
					job.thumb.classList.remove( 'is-loading' );
					job.thumb.replaceChildren();

					if ( result && result.ok ) {
						const img = document.createElement( 'img' );
						img.src = result.image;
						img.alt = '';
						job.thumb.appendChild( img );
					} else {
						job.thumb.textContent = __( 'Could not draw', 'social-card-studio' );
					}
				} )
				.catch( () => {
					job.thumb.classList.remove( 'is-loading' );
					job.thumb.dataset.key = '';
					job.thumb.textContent = __( 'Could not draw', 'social-card-studio' );
				} )
				.then( next );
		};

		for ( let i = 0; i < THUMB_CONCURRENCY; i++ ) {
			next();
		}
	}

	/**
	 * Redraws everything whose inputs changed.
	 */
	function update() {
		const v = values();

		updateBadges( v );

		updateSlot( 'post', {
			card: 'post',
			post_id: v.postId,
			default_template: v.defaultTemplate,
			per_type_template: v.perType,
			accent: v.accent,
			quality: v.quality,
			alt_text_pattern: v.altPattern,
		} );

		updateSlot( 'site', {
			card: 'site',
			site_template: v.siteTemplate,
			site_headline: v.siteHeadline,
			accent: v.accent,
			quality: v.quality,
			alt_text_pattern: v.altPattern,
		} );

		updateThumbs( v );
	}

	/**
	 * Debounces update() so typing does not fire a render per keystroke.
	 */
	function schedule() {
		window.clearTimeout( timer );
		timer = window.setTimeout( update, DEBOUNCE_MS );
	}

	if ( ! data.canRender ) {
		document.querySelectorAll( '.scstudio-preview__status' ).forEach( ( status ) => {
			status.textContent = __( 'Previews are unavailable until an image library is installed.', 'social-card-studio' );
		} );

		return;
	}

	document.querySelectorAll( '.scstudio-preview__status' ).forEach( ( status ) => {
		status.textContent = __( 'Drawing preview…', 'social-card-studio' );
	} );

	if ( postSelect && 0 === data.posts.length ) {
		postSelect.disabled = true;
	}

	form.addEventListener( 'input', schedule );
	form.addEventListener( 'change', schedule );

	if ( postSelect ) {
		postSelect.addEventListener( 'change', schedule );
	}

	if ( $ && $.fn.wpColorPicker ) {
		$( '.scstudio-color' ).wpColorPicker( {
			// The picker fires before it writes the input, so read the colour it reports.
			change( event, ui ) {
				event.target.value = ui.color.toString();
				schedule();
			},
			clear: schedule,
		} );
	}

	update();
}( window.jQuery ) );
