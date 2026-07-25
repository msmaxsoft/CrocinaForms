( function ( $ ) {
	'use strict';

	function insertShortcode( shortcode ) {
		if ( window.tinymce && window.tinymce.activeEditor && ! window.tinymce.activeEditor.isHidden() ) {
			window.tinymce.activeEditor.execCommand( 'mceInsertContent', false, shortcode );
			return;
		}

		if ( window.wp && window.wp.data && window.wp.blocks && window.wp.blockEditor ) {
			var block = window.wp.blocks.createBlock( 'core/shortcode', {
				text: shortcode,
			} );
			window.wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
			return;
		}

		var active = document.activeElement;
		if ( active && 'TEXTAREA' === active.tagName ) {
			var start = active.selectionStart;
			var end   = active.selectionEnd;
			var value = active.value;
			active.value = value.slice( 0, start ) + shortcode + value.slice( end );
			active.focus();
			active.setSelectionRange( start + shortcode.length, start + shortcode.length );
			return;
		}

		if ( window.CrocinaEditor && window.CrocinaEditor.i18n && window.CrocinaEditor.i18n.prompt ) {
			window.prompt( window.CrocinaEditor.i18n.prompt, shortcode );
			return;
		}

		window.alert( shortcode );
	}

	function buildPromptMessage( forms ) {
		if ( ! forms || ! forms.length ) {
			return '';
		}

		var list = forms.map( function ( form ) {
			return form.id + ' - ' + form.label;
		} );

		var message = list.join( '\n' );
		var promptLabel = window.CrocinaEditor && window.CrocinaEditor.i18n && window.CrocinaEditor.i18n.prompt ? window.CrocinaEditor.i18n.prompt : 'Select form ID:%s';

		return promptLabel.replace( '%s', '\n' + message );
	}

	$( document ).ready( function () {
		$( document ).on( 'click', '.crocina-insert-form', function () {
			var forms = [];
			var payload = $( this ).attr( 'data-forms' );
			if ( payload ) {
				try {
					forms = JSON.parse( payload );
				} catch ( error ) {
					forms = [];
				}
			}

			if ( ! forms.length ) {
				if ( window.CrocinaEditor && window.CrocinaEditor.i18n && window.CrocinaEditor.i18n.no_forms ) {
					alert( window.CrocinaEditor.i18n.no_forms );
				} else {
					alert( 'No Crocina forms are available.' );
				}
				return;
			}

			var promptMessage = buildPromptMessage( forms );
			var defaultId = forms[0].id;
			var id = window.prompt( promptMessage, defaultId );
			if ( ! id ) {
				return;
			}

			var shortcode = '[crocina_form id="' + id.trim() + '"]';
			insertShortcode( shortcode );
		} );
	} );
}( jQuery ) );
