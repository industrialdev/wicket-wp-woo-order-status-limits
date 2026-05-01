/**
 * OSL Admin — Order Status Limits rules table UI.
 *
 * Reads a JSON value from the hidden textarea, renders an interactive
 * FROM/TO table, and writes changes back to the textarea so the standard
 * settings form POST captures the updated value.
 */
( function () {
	'use strict';

	var statuses        = ( window.oslData && window.oslData.statuses )        || {};
	var textRemove      = ( window.oslData && window.oslData.textRemove )      || 'Remove';
	var textImportError = ( window.oslData && window.oslData.textImportError ) || 'Import failed: invalid JSON.';
	var exportFilename  = ( window.oslData && window.oslData.exportFilename )  || 'osl-rules.json';

	var tbody;
	var jsonTextarea;

	/**
	 * Build an <option> list for a status <select>.
	 *
	 * @param {string} selectedValue Currently selected status slug.
	 * @returns {string} HTML string.
	 */
	function buildOptions( selectedValue ) {
		return Object.keys( statuses ).map( function ( slug ) {
			var selected = slug === selectedValue ? ' selected' : '';
			return '<option value="' + escAttr( slug ) + '"' + selected + '>' +
				escHtml( statuses[ slug ] ) + '</option>';
		} ).join( '' );
	}

	/**
	 * Create and return a single table row element for a rule.
	 *
	 * @param {{ from: string, to: string }} rule
	 * @returns {HTMLTableRowElement}
	 */
	function createRow( rule ) {
		var tr = document.createElement( 'tr' );

		var fromOptions = buildOptions( rule.from || '' );
		var toOptions   = buildOptions( rule.to || '' );

		tr.innerHTML =
			'<td><select class="osl-from">' + fromOptions + '</select></td>' +
			'<td><select class="osl-to">' + toOptions + '</select></td>' +
			'<td><button type="button" class="button osl-remove-row">' + escHtml( textRemove ) + '</button></td>';

		tr.querySelector( '.osl-from' ).addEventListener( 'change', syncJson );
		tr.querySelector( '.osl-to' ).addEventListener( 'change', syncJson );
		tr.querySelector( '.osl-remove-row' ).addEventListener( 'click', function () {
			tr.parentNode.removeChild( tr );
			syncJson();
		} );

		return tr;
	}

	/**
	 * Read the current table rows and write the JSON representation
	 * back to the hidden textarea.
	 */
	function syncJson() {
		var rows  = tbody.querySelectorAll( 'tr' );
		var rules = [];

		rows.forEach( function ( row ) {
			var from = row.querySelector( '.osl-from' );
			var to   = row.querySelector( '.osl-to' );
			if ( from && to ) {
				rules.push( { from: from.value, to: to.value } );
			}
		} );

		jsonTextarea.value = JSON.stringify( rules );
	}

	/**
	 * Render the initial table rows from the current rules array.
	 *
	 * @param {Array} rules Array of {from, to} objects.
	 */
	function renderRows( rules ) {
		tbody.innerHTML = '';
		rules.forEach( function ( rule ) {
			tbody.appendChild( createRow( rule ) );
		} );
		syncJson();
	}

	/**
	 * Minimal HTML escaping for option values.
	 *
	 * @param {string} str
	 * @returns {string}
	 */
	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	/**
	 * Escape a string for use in an HTML attribute.
	 *
	 * @param {string} str
	 * @returns {string}
	 */
	function escAttr( str ) {
		return String( str ).replace( /"/g, '&quot;' );
	}

	/**
	 * Trigger a download of the current rules as a JSON file.
	 */
	function exportRules() {
		var json = jsonTextarea.value.trim() || '[]';
		var blob = new Blob( [ json ], { type: 'application/json' } );
		var url  = URL.createObjectURL( blob );
		var a    = document.createElement( 'a' );
		a.href     = url;
		a.download = exportFilename;
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		URL.revokeObjectURL( url );
	}

	/**
	 * Read a JSON file from a file input, validate it, and replace the rules table.
	 *
	 * @param {File} file
	 */
	function importRules( file ) {
		var reader = new FileReader();
		reader.onload = function ( e ) {
			var parsed;
			try {
				parsed = JSON.parse( e.target.result );
			} catch ( err ) {
				alert( textImportError );
				return;
			}

			if ( ! Array.isArray( parsed ) ) {
				alert( textImportError );
				return;
			}

			var valid = parsed.filter( function ( rule ) {
				return rule && typeof rule.from === 'string' && typeof rule.to === 'string';
			} );

			if ( valid.length === 0 ) {
				alert( textImportError );
				return;
			}

			renderRows( valid );
		};
		reader.readAsText( file );
	}

	/**
	 * Initialise the rules UI once the DOM is ready.
	 */
	function init() {
		tbody        = document.getElementById( 'osl-rules-tbody' );
		jsonTextarea = document.getElementById( 'osl-rules-json' );
		var addBtn    = document.getElementById( 'osl-add-rule' );
		var exportBtn = document.getElementById( 'osl-export-rules' );
		var importBtn = document.getElementById( 'osl-import-rules' );
		var importFile = document.getElementById( 'osl-import-file' );

		if ( ! tbody || ! jsonTextarea || ! addBtn ) {
			return;
		}

		var savedValue = jsonTextarea.value.trim();
		var rules = [];

		if ( savedValue ) {
			try {
				rules = JSON.parse( savedValue );
			} catch ( e ) {
				rules = [];
			}
		}

		renderRows( Array.isArray( rules ) ? rules : [] );

		addBtn.addEventListener( 'click', function () {
			tbody.appendChild( createRow( { from: '', to: '' } ) );
			syncJson();
		} );

		if ( exportBtn ) {
			exportBtn.addEventListener( 'click', exportRules );
		}

		if ( importBtn && importFile ) {
			// Move the file input outside the form so it never participates in
			// form submission and cannot change the form's effective encoding.
			document.body.appendChild( importFile );

			importBtn.addEventListener( 'click', function () {
				importFile.value = '';
				importFile.click();
			} );
			importFile.addEventListener( 'change', function () {
				if ( importFile.files && importFile.files[0] ) {
					importRules( importFile.files[0] );
				}
			} );
		}

		// Guarantee the hidden textarea is current right before the form submits,
		// regardless of how the user interacted with the table.
		var form = jsonTextarea.closest( 'form' );
		if ( form ) {
			form.addEventListener( 'submit', syncJson );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} () );
