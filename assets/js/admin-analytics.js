/**
 * ADC Marketing — Analytics operațional (admin enhancer, no tracking).
 *
 * Scope: the filter controls of templates/admin/analytics-dashboard.php, which
 * are rendered by the Dashboard tab (ADC_Admin_Tab_Dashboard); the settings form
 * of the same template belongs to the Analytics tab (ADC_Admin_Tab_Analytics).
 * The shell still wraps every tab in a <form>, so the guard below stays needed.
 * The dashboard works without JavaScript (interval presets and channel/currency
 * filters are plain GET links); this script only keeps the "Aplică filtrele"
 * link in sync with the controls and stops Enter inside a filter field from
 * submitting the surrounding settings form.
 *
 * Deliberately absent: no fetch/XHR/beacon, no cookies, no localStorage or
 * sessionStorage, no third-party library, no front-end code path.
 */
( function () {
	'use strict';

	var APPLY_ID = 'adc-an-apply';
	var ROOT_SELECTOR = '[data-adc-analytics]';
	var FILTER_SELECTOR = '[data-adc-an-filter]';
	// Hint text comes from PHP (wp_localize_script -> window.adcAnalyticsI18n).
	var I18N = window.adcAnalyticsI18n || {};

	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn, { once: true } );
		} else {
			fn();
		}
	}

	/**
	 * Read the current value of one filter control.
	 */
	function valueOf( el ) {
		var raw = el.value == null ? '' : String( el.value );
		return raw.trim();
	}

	/**
	 * Rebuild the apply URL from the link's own href plus the control values.
	 * Unknown or out-of-range values are simply dropped from the query; the
	 * server-side filter parser remains the only authority.
	 */
	function syncApplyLink( root, apply ) {
		var url;

		try {
			url = new window.URL( apply.getAttribute( 'href' ) || window.location.href, window.location.href );
		} catch ( error ) {
			return;
		}

		var from = '';
		var to = '';

		root.querySelectorAll( FILTER_SELECTOR ).forEach( function ( el ) {
			var key = el.getAttribute( 'data-adc-an-filter' );
			var value = valueOf( el );

			if ( ! key ) {
				return;
			}

			if ( key === 'from' ) {
				from = value;
				return;
			}

			if ( key === 'to' ) {
				to = value;
				return;
			}

			if ( value === '' || ( key === 'product' && Number( value ) <= 0 ) ) {
				url.searchParams.delete( key );
				return;
			}

			url.searchParams.set( key, value );
		} );

		if ( from && to ) {
			url.searchParams.set( 'range', 'custom' );
			url.searchParams.set( 'from', from );
			url.searchParams.set( 'to', to );
		} else if ( from || to ) {
			// One date only is not a range: keep the preset the admin already chose.
			url.searchParams.delete( 'from' );
			url.searchParams.delete( 'to' );
		}

		apply.setAttribute( 'href', url.toString() );
	}

	/**
	 * Visual + announced hint that the controls differ from the applied report.
	 * Uses a data attribute only: `aria-dirty` is not a valid ARIA name.
	 */
	function flagUnapplied( apply, dirty ) {
		apply.classList.toggle( 'is-dirty', !! dirty );
		apply.setAttribute( 'data-adc-dirty', dirty ? 'true' : 'false' );

		var hint = apply.querySelector( '.adc-an-dirty-hint' );

		if ( hint ) {
			hint.textContent = dirty ? ( I18N.dirtyHint || '' ) : '';
		}
	}

	ready( function () {
		var root = document.querySelector( ROOT_SELECTOR );

		if ( ! root ) {
			return;
		}

		var apply = document.getElementById( APPLY_ID );

		if ( ! apply ) {
			return;
		}

		var controls = root.querySelectorAll( FILTER_SELECTOR );

		if ( ! controls.length ) {
			return;
		}

		controls.forEach( function ( el ) {
			var handler = function () {
				syncApplyLink( root, apply );
				flagUnapplied( apply, true );
			};

			el.addEventListener( 'change', handler );

			if ( el.type === 'date' || el.type === 'number' ) {
				el.addEventListener( 'input', function () {
					syncApplyLink( root, apply );
					flagUnapplied( apply, true );
				} );

				// Enter in a filter field must navigate the report, not save settings.
				el.addEventListener( 'keydown', function ( event ) {
					if ( event.key === 'Enter' ) {
						event.preventDefault();
						window.location.href = apply.getAttribute( 'href' );
					}
				} );
			}
		} );

		// The apply link is an <a>: never let a click bubble into the settings form.
		apply.addEventListener( 'click', function ( event ) {
			if ( event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
				return;
			}
			flagUnapplied( apply, false );
		} );
	} );
}() );
