( function ( $ ) {
	'use strict';

	/* Defensive check — if jQuery is not ready, wait for DOMContentLoaded. */
	if ( 'function' !== typeof $ ) {
		return;
	}

	function renderOptionRow( value, $list, sync ) {
		var $row = $( '<div class="crocina-option-row"><span class="crocina-option-handle dashicons dashicons-menu" aria-hidden="true"></span><input type="text" value="" /><button type="button" class="crocina-option-remove dashicons dashicons-no-alt" aria-label="Remove option"></button></div>' );
		$row.find( 'input' ).val( value );
		$row.find( 'input' ).on( 'input', function () {
			sync();
		} );
		$row.find( '.crocina-option-remove' ).on( 'click', function () {
			$row.remove();
			sync();
		} );
		$list.append( $row );
		return $row;
	}

	$( function () {
		var choiceTypes = [ 'select', 'radio', 'checkbox' ];
		var fieldTypeIcons = {
			'text': 'dashicons-editor-textcolor',
			'textarea': 'dashicons-editor-expand',
			'email': 'dashicons-email-alt',
			'tel': 'dashicons-phone',
			'number': 'dashicons-analytics',
			'url': 'dashicons-admin-links',
			'file': 'dashicons-media-default',
			'select': 'dashicons-arrow-down-alt2',
			'radio': 'dashicons-radio-button-checked',
			'checkbox': 'dashicons-yes'
		};
		var undoStack = loadUndoRedoStack( 'undo' );
		var redoStack = loadUndoRedoStack( 'redo' );

		function saveUndoRedoStacks() {
			try {
				window.sessionStorage.setItem( 'crocina_undo', JSON.stringify( undoStack.slice( -50 ) ) );
				window.sessionStorage.setItem( 'crocina_redo', JSON.stringify( redoStack.slice( -50 ) ) );
			} catch ( e ) {}
		}

		function loadUndoRedoStack( name ) {
			try {
				var raw = window.sessionStorage.getItem( 'crocina_' + name );
				return raw ? JSON.parse( raw ) : [];
			} catch ( e ) {
				return [];
			}
		}


		var $cards      = $( '.crocina-field-cards' );
		var $template   = $( '#crocina-field-template' );
		var $emptyState = $( '.crocina-empty-state' );

		function updateEmptyState() {
			if ( ! $emptyState.length ) {
				return;
			}
			var hasCards = $cards.find( '.crocina-field-card' ).not( '.is-template' ).length > 0;
			$emptyState.toggleClass( 'is-hidden', hasCards );
			updateUndoRedoState();
		}

		function slugifyLabel( label ) {
			return $.trim( label )
				.toLowerCase()
				.replace( /\s+/g, '_' )
				.replace( /[^a-z0-9_-]/g, '' );
		}

		function initOptionBuilder( $card ) {
			var $builder = $card.find( '.crocina-options-builder' );
			if ( ! $builder.length ) {
				return;
			}

			var $list   = $builder.find( '.crocina-options-list' );
			var $hidden = $builder.find( '.crocina-field-options-hidden' );
			var $type   = $card.find( 'select[name="crocina_field_type[]"]' );

			var sync = function () {
				var values = [];
				$list.find( 'input' ).each( function () {
					var val = $.trim( $( this ).val() );
					if ( val ) {
						values.push( val );
					}
				} );
				$hidden.val( values.join( ', ' ) ).trigger( 'change' );
				queueLivePreview();
			};

			var rebuild = function () {
				$list.empty();
				var initial = $.trim( $hidden.val() );
				if ( initial ) {
					$.each( initial.split( ',' ), function ( index, value ) {
						var cleaned = $.trim( value );
						if ( cleaned ) {
							renderOptionRow( cleaned, $list, sync );
						}
					} );
				}
				sync();
			};

			if ( $.fn.sortable ) {
				$list.sortable({
					handle: '.crocina-option-handle',
					axis: 'y',
					update: sync,
					stop: sync,
				}).disableSelection();
			}

			var toggleBuilder = function () {
				var show = choiceTypes.indexOf( $type.val() ) !== -1;
				$builder.toggle( show );
			};

			$type.on( 'change', function () {
				toggleBuilder();
				$card.attr( 'data-field-type', $type.val() );
				var newIcon = fieldTypeIcons[ $type.val() ] || 'dashicons-editor-textcolor';
				$card.find( '.crocina-field-card-icon' ).attr( 'class', 'crocina-field-card-icon dashicons ' + newIcon );
				queueLivePreview();
			} );

			$builder.on( 'click', '.crocina-option-add', function () {
				renderOptionRow( '', $list, sync );
			} );

			rebuild();
			toggleBuilder();
		}

		function updateHeader( $card ) {
			if ( ! $card.length ) {
				return;
			}

			var label = $.trim( $card.find( 'input[name="crocina_field_label[]"]' ).val() );
			var slug  = $.trim( $card.find( 'input[name="crocina_field_slug[]"]' ).val() );
			var newFieldLabel = ( window.crocinaField && crocinaField.i18n && crocinaField.i18n.newField ) ? crocinaField.i18n.newField : '';
			var autoKeyLabel = ( window.crocinaField && crocinaField.i18n && crocinaField.i18n.autoKey ) ? crocinaField.i18n.autoKey : '';

			$card.find( '.crocina-field-card-label' ).text( label || newFieldLabel );
			$card.find( '.crocina-field-card-key' ).text( slug || autoKeyLabel );
		}



		function attachFieldActions( $card ) {
			var $body = $card.find( '.crocina-field-card-body' );
			var $widthSelect = $card.find( 'select[name="crocina_field_width[]"]' );
			var $slugInput = $card.find( 'input[name="crocina_field_slug[]"]' );

			$card.on( 'input', 'input[name="crocina_field_label[]"], input[name="crocina_field_slug[]"]', function ( event ) {
				if ( $( event.target ).is( 'input[name="crocina_field_label[]"]' ) && $slugInput.length && ! $.trim( $slugInput.val() ) ) {
					var suggested = slugifyLabel( $( event.target ).val() );
					if ( suggested ) {
						$slugInput.val( suggested );
					}
				}
				updateHeader( $card );
			} );

			if ( $widthSelect.length ) {
				var updateWidthClass = function () {
					var width = $widthSelect.val() || '1-1';
					$card.removeClass( 'crocina-col-1-1 crocina-col-1-2 crocina-col-1-3' ).addClass( 'crocina-col-' + width );
				};
				$widthSelect.on( 'change', updateWidthClass );
				updateWidthClass();
			}

			updateHeader( $card );
			initOptionBuilder( $card );
			$card.addClass( 'is-open' );
			$body.show();
		}

		/* Delegate toggle/remove to handle existing cards loaded from saved data. */
		$( document ).on( 'click', '.crocina-field-toggle', function () {
			var $card = $( this ).closest( '.crocina-field-card' );
			var $body = $card.find( '.crocina-field-card-body' );
			if ( $card.hasClass( 'is-open' ) ) {
				$card.removeClass( 'is-open' );
				$body.hide();
			} else {
				$card.addClass( 'is-open' );
				$body.show();
			}
		} );

		$( document ).on( 'click', '.crocina-remove-field', function () {
			pushUndoState();
			var $card = $( this ).closest( '.crocina-field-card' );
			$card.remove();
			updateEmptyState();
			updateUndoRedoState();
			queueLivePreview();
		} );

		function syncRequiredCheckbox( $checkbox ) {
			var $wrapper = $checkbox.closest( '.crocina-field-required' );
			var $hidden = $wrapper.find( '.crocina-field-required-value' );
			if ( ! $hidden.length ) {
				return;
			}

			$hidden.val( $checkbox.is( ':checked' ) ? '1' : '0' );
		}

		/* Live preview removed — keep queueLivePreview as a no-op for BC. */
		function queueLivePreview() {}

		$( document ).on( 'submit', '.crocina-inbox-table-wrapper form', function ( event ) {
			var action = $( this ).find( 'select[name="action"]' ).val() || $( this ).find( 'select[name="action2"]' ).val() || '';
			if ( action === 'delete' ) {
				var count = $( this ).find( 'input[name="log_id[]"]:checked' ).length;
				if ( count && ! window.confirm( count + ' log(s) will be permanently deleted. Continue?' ) ) {
					event.preventDefault();
				}
			}
		} );

		/* Initialize option builders for existing saved cards on page load. */
		$( '.crocina-field-card' ).not( '.is-template' ).each( function () {
			initOptionBuilder( $( this ) );
		} );

		updateUndoRedoState();

		function addFieldCard( type ) {
			if ( ! $template.length ) {
				return null;
			}
			var $clone = $( $template.html() ).appendTo( $cards );
			var $typeSelect = $clone.find( 'select[name="crocina_field_type[]"]' );
			if ( $typeSelect.length ) {
				$typeSelect.val( type || 'text' ).trigger( 'change' );
			}
			attachFieldActions( $clone );
			$clone.find( 'input[name="crocina_field_label[]"]' ).focus();
			updateEmptyState();
			queueLivePreview();
			return $clone;
		}

		if ( $.fn.sortable && $cards.length ) {
			$cards.sortable({
				handle: '.crocina-field-card-handle',
				items: '> .crocina-field-card:not(.is-template)',
				axis: 'y',
				placeholder: 'crocina-field-card-placeholder',
				forcePlaceholderSize: true,
			update: function() {
				updateEmptyState();
				queueLivePreview();
			}
		}).disableSelection();
		}

		if ( $.fn.droppable && $cards.length ) {
			$cards.droppable({
				accept: '.crocina-add-btn',
				hoverClass: 'is-drop-target',
				drop: function ( event, ui ) {
					var type = ui.draggable.data( 'type' ) || 'text';
					addFieldCard( type );
				}
			});
		}

		if ( $.fn.draggable ) {
			$( '.crocina-add-btn' ).draggable({
				helper: 'clone',
				revert: 'invalid',
				appendTo: 'body',
				start: function () {
					$( '.crocina-builder-canvas' ).addClass( 'is-dragging' );
				},
				stop: function () {
					$( '.crocina-builder-canvas' ).removeClass( 'is-dragging' );
				}
			});
		}

		$( document ).on( 'click', '.crocina-add-field', function () {
			addFieldCard( 'text' );
		} );

		$( document ).on( 'click', '.crocina-add-btn', function () {
			var type = $( this ).data( 'type' ) || 'text';
			addFieldCard( type );
		} );

		$( document ).on( 'change', '.crocina-field-required-checkbox', function () {
			syncRequiredCheckbox( $( this ) );
		} );

		/**
		 * Fade out the theme preview, run a callback that modifies its
		 * appearance (template/theme classes), then fade back in.
		 *
		 * @param {Function} callback Mutates the preview element (already
		 *                             selected as $('#crocina-theme-preview')).
		 * @return {void}
		 */
		function fadeThemePreview( callback, label ) {
			var $preview = $( '#crocina-theme-preview' );
			if ( ! $preview.length || ! callback ) {
				return;
			}
			$preview.css( 'opacity', 0 );

			/* Show a temporary overlay with the label (e.g. "Theme: Classic"). */
			var $overlay = $preview.find( '.crocina-preview-overlay' );
			if ( $overlay.length && label ) {
				$overlay.text( label ).css( 'opacity', 1 );
				/* Fade out the overlay after 1 second. */
				window.setTimeout( function () {
					$overlay.css( 'opacity', 0 );
				}, 1000 );
			}

			window.setTimeout( function () {
				callback();
				$preview.css( 'opacity', 1 );
			}, 200 );
		}

		/* Wire the template switcher buttons in the builder header. */
		$( document ).on( 'click', '.crocina-template-switcher-btn', function () {
			var $btn = $( this );
			var template = $btn.data( 'template' ) || 'default';

			/* Update active state on all switcher buttons. */
			$( '.crocina-template-switcher-btn' ).removeClass( 'is-active' );
			$btn.addClass( 'is-active' );

			/* Sync the hidden crocina_template dropdown in the design meta box. */
			var $select = $( 'select[name="crocina_template"]' );
			if ( $select.length && $select.val() !== template ) {
				$select.val( template ).trigger( 'change' );
			}

			var $preview = $( '#crocina-theme-preview' );
			fadeThemePreview( function () {
				$preview.removeClass( 'crocina-form-template-default crocina-form-template-card crocina-form-template-minimal crocina-form-template-bordered crocina-form-template-shadow' )
					.addClass( 'crocina-form-template-' + template );
			}, 'Template: ' + $.trim( $btn.text() ) );
		} );

		/* -------------------------------------------------------------- */
		/*  Template thumbnail modal — open, close, and select handlers    */
		/* -------------------------------------------------------------- */
		$( document ).on( 'click', '.crocina-browse-templates', function () {
			var $modal = $( '#crocina-template-modal' );
			if ( $modal.length ) {
				$modal.addClass( 'is-open' );
			}
		} );

		$( document ).on( 'click', '.crocina-template-modal-close', function () {
			$( '#crocina-template-modal' ).removeClass( 'is-open' );
		} );

		$( document ).on( 'click', '#crocina-template-modal', function ( event ) {
			if ( $( event.target ).is( '#crocina-template-modal' ) ) {
				$( '#crocina-template-modal' ).removeClass( 'is-open' );
			}
		} );

		$( document ).on( 'click', '.crocina-template-thumb', function () {
			var $thumb = $( this );
			var template = $thumb.data( 'template' ) || 'default';

			/* Update is-selected state on all thumbs. */
			$( '.crocina-template-thumb' )
				.removeClass( 'is-selected' )
				.attr( 'aria-pressed', 'false' );
			$thumb
				.addClass( 'is-selected' )
				.attr( 'aria-pressed', 'true' );

			/* Sync the crocina_template dropdown and trigger change. */
			var $select = $( 'select[name="crocina_template"]' );
			if ( $select.length && $select.val() !== template ) {
				$select.val( template ).trigger( 'change' );
			}

			/* Close the modal. */
			$( '#crocina-template-modal' ).removeClass( 'is-open' );
		} );

		/* Wire the theme dropdown to update the live preview class. */
		$( document ).on( 'change', 'select[name="crocina_theme"]', function () {
			var $select = $( this );
			var $preview = $( '#crocina-theme-preview' );
			if ( $preview.length ) {
				$preview.removeClass( 'crocina-theme-modern crocina-theme-classic crocina-theme-minimal' )
					.addClass( 'crocina-theme-' + $select.val() );
			}
		} );





		function setIconPickerValue( icon, label ) {
			var $input = $( '.crocina-button-icon-input' );
			var $trigger = $( '.crocina-icon-picker-trigger' );
			if ( ! $input.length || ! $trigger.length ) {
				return;
			}

			$input.val( icon || '' ).trigger( 'change' );
			var $icon = $trigger.find( '.dashicons' );
			var $label = $trigger.find( '.crocina-icon-picker-label' );
			var iconClass = icon ? icon : 'dashicons-minus';
			var labelText = label ? label : ( $trigger.data( 'empty-label' ) || 'No icon' );
			$icon.attr( 'class', 'dashicons ' + iconClass );
			$label.text( labelText );
		}

		$( document ).on( 'click', '.crocina-icon-picker-trigger', function () {
			var $modal = $( '.crocina-icon-picker-modal' );
			if ( ! $modal.length ) {
				return;
			}
			$modal.addClass( 'is-open' );
			$modal.find( '.crocina-icon-picker-search-input' ).val( '' ).trigger( 'input' ).focus();
		} );

		$( document ).on( 'click', '.crocina-icon-picker-close', function () {
			$( '.crocina-icon-picker-modal' ).removeClass( 'is-open' );
		} );

		$( document ).on( 'click', '.crocina-icon-picker-modal', function ( event ) {
			if ( $( event.target ).is( '.crocina-icon-picker-modal' ) ) {
				$( '.crocina-icon-picker-modal' ).removeClass( 'is-open' );
			}
		} );

		$( document ).on( 'input', '.crocina-icon-picker-search-input', function () {
			var query = $( this ).val().toLowerCase();
			$( '.crocina-icon-picker-option' ).each( function () {
				var $option = $( this );
				var label = ( $option.data( 'label' ) || '' ).toLowerCase();
				var icon = ( $option.data( 'icon' ) || '' ).toLowerCase();
				var match = label.indexOf( query ) !== -1 || icon.indexOf( query ) !== -1;
				$option.toggle( match );
			} );
		} );

		$( document ).on( 'click', '.crocina-icon-picker-option', function () {
			var $option = $( this );
			setIconPickerValue( $option.data( 'icon' ) || '', $option.data( 'label' ) || '' );
			$( '.crocina-icon-picker-modal' ).removeClass( 'is-open' );
		} );

		$( document ).on( 'click', '.crocina-icon-picker-clear', function ( event ) {
			event.preventDefault();
			setIconPickerValue( '', 'No icon' );
		} );

		$( document ).on( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				$( '.crocina-icon-picker-modal, #crocina-template-modal' ).removeClass( 'is-open' );
			}
		} );

		function snapshotBuilderState() {
			var cards = [];
			$cards.find( '.crocina-field-card' ).not( '.is-template' ).each( function () {
				var $card = $( this );
				cards.push( {
					label: $card.find( 'input[name="crocina_field_label[]"]' ).val() || '',
					slug: $card.find( 'input[name="crocina_field_slug[]"]' ).val() || '',
					type: $card.find( 'select[name="crocina_field_type[]"]' ).val() || 'text',
					placeholder: $card.find( 'input[name="crocina_field_placeholder[]"]' ).val() || '',
					helper: $card.find( 'input[name="crocina_field_helper[]"]' ).val() || '',
					options: $card.find( 'input.crocina-field-options-hidden' ).val() || '',
					required: $card.find( 'input.crocina-field-required-value' ).val() || '0',
					width: $card.find( 'select[name="crocina_field_width[]"]' ).val() || '1-1'
				} );
			} );
			return JSON.stringify( cards );
		}

		function restoreBuilderState( stateJson ) {
			var cards = JSON.parse( stateJson );
			$cards.find( '.crocina-field-card' ).not( '.is-template' ).remove();
			$.each( cards, function ( i, card ) {
				var $clone = $( $template.html() ).appendTo( $cards );
				$clone.find( 'input[name="crocina_field_label[]"]' ).val( card.label );
				$clone.find( 'input[name="crocina_field_slug[]"]' ).val( card.slug );
				var $typeSelect = $clone.find( 'select[name="crocina_field_type[]"]' );
				$typeSelect.val( card.type || 'text' ).trigger( 'change' );
				$clone.find( 'input[name="crocina_field_placeholder[]"]' ).val( card.placeholder );
				$clone.find( 'input[name="crocina_field_helper[]"]' ).val( card.helper );
				$clone.find( 'input.crocina-field-options-hidden' ).val( card.options );
				var $reqHidden = $clone.find( 'input.crocina-field-required-value' );
				$reqHidden.val( card.required );
				if ( card.required === '1' ) {
					$clone.find( '.crocina-field-required-checkbox' ).prop( 'checked', true );
				}
				$clone.find( 'select[name="crocina_field_width[]"]' ).val( card.width || '1-1' ).trigger( 'change' );
				attachFieldActions( $clone );
			} );
			updateEmptyState();
			queueLivePreview();
		}

		function pushUndoState() {
			undoStack.push( snapshotBuilderState() );
			if ( undoStack.length > 50 ) {
				undoStack.shift();
			}
			redoStack = [];
			saveUndoRedoStacks();
			updateUndoRedoState();
		}

		function undo() {
			if ( undoStack.length === 0 ) {
				return;
			}
			redoStack.push( snapshotBuilderState() );
			restoreBuilderState( undoStack.pop() );
			saveUndoRedoStacks();
			updateUndoRedoState();
		}

		function redo() {
			if ( redoStack.length === 0 ) {
				return;
			}
			undoStack.push( snapshotBuilderState() );
			restoreBuilderState( redoStack.pop() );
			saveUndoRedoStacks();
			updateUndoRedoState();
		}

		function updateUndoRedoState() {
			$( '.crocina-undo-btn' ).prop( 'disabled', undoStack.length === 0 );
			$( '.crocina-redo-btn' ).prop( 'disabled', redoStack.length === 0 );
		}

		$( document ).on( 'click', '.crocina-undo-btn', function () {
			undo();
		} );

		$( document ).on( 'click', '.crocina-redo-btn', function () {
			redo();
		} );

		$( document ).on( 'click', '.crocina-quick-save', function () {
			$( '#publish' ).trigger( 'click' );
		} );

		$( document ).on( 'keydown', function ( event ) {
			if ( event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA' || event.target.tagName === 'SELECT' ) {
				return;
			}
			if ( ( event.ctrlKey || event.metaKey ) && event.key === 'z' && ! event.shiftKey ) {
				event.preventDefault();
				undo();
			}
			if ( ( event.ctrlKey || event.metaKey ) && ( event.key === 'y' || ( event.key === 'z' && event.shiftKey ) ) ) {
				event.preventDefault();
				redo();
			}
			if ( ( event.ctrlKey || event.metaKey ) && event.key === 's' ) {
				event.preventDefault();
				$( '#publish' ).trigger( 'click' );
			}
		} );

		/* -------------------------------------------------------------- */
		/*  Template dropdown → live preview + switcher buttons sync      */
		/* -------------------------------------------------------------- */
		$( document ).on( 'change', 'select[name="crocina_template"]', function () {
			var template = $( this ).val() || 'default';

			/* Update the switcher button active state. */
			$( '.crocina-template-switcher-btn' )
				.removeClass( 'is-active' )
				.filter( '[data-template="' + template + '"]' )
				.addClass( 'is-active' );

			/* Fade live preview to show the new template. */
			var $preview = $( '#crocina-theme-preview' );
			if ( $preview.length ) {
				fadeThemePreview( function () {
					$preview.removeClass(
						'crocina-form-template-default crocina-form-template-card ' +
						'crocina-form-template-minimal crocina-form-template-bordered ' +
						'crocina-form-template-shadow'
					).addClass( 'crocina-form-template-' + template );
				}, 'Template: ' + $( this ).find( 'option:selected' ).text() );
			}
		} );

		$( document ).on( 'click', '.crocina-add-field, .crocina-add-btn', function () {
			pushUndoState();
		} );

		$cards.on( 'sortupdate', function () {
			pushUndoState();
		} );

		updateEmptyState();

		/**
		 * Tab state manager — remembers the last active tab across page
		 * refreshes via sessionStorage, with URL hash as secondary fallback.
		 * Uses nav-tab-wrapper / tab-content selectors matching the current
		 * WordPress-standard settings template.
		 */
		function initSettingsTabs() {
			var $tabs     = $( '.nav-tab-wrapper a' );
			var $sections = $( '.tab-content' );
			if ( ! $tabs.length || ! $sections.length ) {
				return;
			}

			var STORAGE_KEY = 'crocina_active_tab';

			var activateTab = function ( target, save ) {
				$tabs.removeClass( 'nav-tab-active' );
				$sections.hide();
				$tabs.filter( '[href="' + target + '"]' ).addClass( 'nav-tab-active' );
				$( target ).show();

				if ( save !== false ) {
					try {
						window.sessionStorage.setItem( STORAGE_KEY, target );
					} catch ( e ) {}
				}

				if ( window.history && window.history.replaceState ) {
					window.history.replaceState( null, '', target );
				} else {
					window.location.hash = target;
				}
			};

			/* Determine which tab to show on load:
			 *   1. sessionStorage saved state
			 *   2. URL hash (if it points to an existing section)
			 *   3. First tab (default)
			 */
			var initial = null;
			try {
				var saved = window.sessionStorage.getItem( STORAGE_KEY );
				if ( saved && $sections.filter( saved ).length ) {
					initial = saved;
				}
			} catch ( e ) {}

			if ( ! initial ) {
				initial = window.location.hash && $sections.filter( window.location.hash ).length
					? window.location.hash
					: $tabs.first().attr( 'href' );
			}
			activateTab( initial, false ); /* Don't re-save on initial load. */

			$tabs.on( 'click', function ( event ) {
				event.preventDefault();
				var target = $( this ).attr( 'href' );
				if ( ! target ) {
					return;
				}
				activateTab( target );
			} );
		}

		initSettingsTabs();

		function initExportTools() {
			var $exportWrap = $( '.crocina-export-modern' );
			if ( ! $exportWrap.length ) {
				return;
			}

			var $copyBtn = $exportWrap.find( '.crocina-copy-btn' );
			var $exportStatus = $exportWrap.find( '.crocina-export-status' );
			var $importForm = $exportWrap.find( '.crocina-import-form' );
			var $importStatus = $exportWrap.find( '.crocina-import-status' );

			var setStatus = function ( $el, message, isError ) {
				if ( ! $el.length ) {
					return;
				}
				$el.text( message || '' );
				$el.toggleClass( 'is-error', !! isError );
			};

			$copyBtn.on( 'click', function () {
				var target = $( this ).data( 'target' );
				var $textarea = target ? $( target ) : $();
				if ( ! $textarea.length ) {
					return;
				}

				var value = $textarea.val();
				if ( ! value ) {
					return;
				}

				copyToClipboard( value, function () {
					setStatus( $exportStatus, $exportWrap.data( 'copy-ok' ) || 'Copied.' );
				}, function () {
					setStatus( $exportStatus, $exportWrap.data( 'copy-fail' ) || 'Copy failed.', true );
				} );
			} );

			$importForm.on( 'submit', function ( event ) {
				var $payload = $importForm.find( 'textarea[name="crocina_import_payload"]' );
				var raw = $payload.val();
				if ( ! raw ) {
					setStatus( $importStatus, $importForm.data( 'invalid-json' ) || 'Invalid JSON.', true );
					event.preventDefault();
					return;
				}

				try {
					JSON.parse( raw );
					setStatus( $importStatus, '' );
				} catch ( err ) {
					setStatus( $importStatus, $importForm.data( 'invalid-json' ) || 'Invalid JSON.', true );
					event.preventDefault();
				}
			} );
		}

		initExportTools();

		/* -------------------------------------------------------------- */
		/*  Inline form export/import — builder page card                  */
		/* -------------------------------------------------------------- */
		$( document ).on( 'click', '.crocina-export-form-btn', function () {
			var $btn = $( this );
			var formId = $btn.data( 'form-id' );
			var nonce = $btn.data( 'nonce' );
			var ajaxUrl = window.ajaxurl || '';
			var $card = $btn.closest( '#crocina-export-card' );
			var $area = $card.find( '.crocina-export-inline-area' );
			var $textarea = $card.find( '.crocina-export-inline-json' );
			var originalText = $btn.text();

			if ( ! formId || ! nonce ) {
				return;
			}

			$btn.prop( 'disabled', true ).text( 'Exporting…' );

			$.post( ajaxUrl, {
				action: 'crocina_export_form',
				nonce: nonce,
				form_id: formId
			} ).done( function ( response ) {
				if ( response && response.success && response.data && response.data.json ) {
					$textarea.val( response.data.json );
					/* Store a slugified filename for the download button. */
					var title = ( response.data.form_title || 'crocina-form-export' )
						.toLowerCase()
						.replace( /\s+/g, '-' )
						.replace( /[^a-z0-9-]/g, '' );
					$area.data( 'filename', title + '.json' );
					$area.slideDown( 200 );
				} else {
					alert( ( response && response.data && response.data.message ) || 'Export failed.' );
				}
			} ).fail( function () {
				alert( 'Request failed.' );
			} ).always( function () {
				$btn.prop( 'disabled', false ).text( originalText );
			} );
		} );

		$( document ).on( 'click', '.crocina-copy-json-btn', function () {
			var $card = $( this ).closest( '#crocina-export-card' );
			var $textarea = $card.find( '.crocina-export-inline-json' );
			var value = $textarea.val();

			if ( ! value ) {
				return;
			}

			copyToClipboard( value, function () {
				alert( 'Copied to clipboard.' );
			}, function () {
				alert( 'Copy failed.' );
			} );
		} );

		$( document ).on( 'click', '.crocina-download-json-btn', function () {
			var $card = $( this ).closest( '#crocina-export-card' );
			var $textarea = $card.find( '.crocina-export-inline-json' );
			var $area = $card.find( '.crocina-export-inline-area' );
			var value = $textarea.val();

			if ( ! value ) {
				return;
			}

			var filename = $area.data( 'filename' ) || 'crocina-form-export.json';
			var blob = new Blob( [ value ], { type: 'application/json;charset=utf-8' } );
			var url = URL.createObjectURL( blob );
			var link = document.createElement( 'a' );
			link.href = url;
			link.download = filename;
			$( 'body' ).append( link );
			link.click();
			window.setTimeout( function () {
				$( link ).remove();
				URL.revokeObjectURL( url );
			}, 100 );
		} );

		$( document ).on( 'click', '.crocina-import-single-btn', function () {
			var $btn = $( this );
			var formId = $btn.data( 'form-id' );
			var $card = $btn.closest( '#crocina-export-card' );
			var $textarea = $card.find( '.crocina-import-inline-json' );
			var $status = $card.find( '.crocina-import-status' );
			var raw = $textarea.val();
			var nonce = $textarea.data( 'import-nonce' );
			var ajaxUrl = window.ajaxurl || '';

			if ( ! raw ) {
				$status.text( 'Please paste JSON first.' ).addClass( 'is-error' );
				return;
			}

			/* Validate JSON client-side before sending. */
			try {
				JSON.parse( raw );
			} catch ( err ) {
				$status.text( 'Invalid JSON.' ).addClass( 'is-error' );
				return;
			}

			$btn.prop( 'disabled', true );
			$status.text( 'Importing…' ).removeClass( 'is-error is-success' );

			$.post( ajaxUrl, {
				action: 'crocina_import_form',
				nonce: nonce,
				form_id: formId,
				payload: raw
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$status.text( response.data.message || 'Import successful.' ).addClass( 'is-success' );
					window.setTimeout( function () {
						window.location.reload();
					}, 1500 );
				} else {
					$status.text( ( response && response.data && response.data.message ) || 'Import failed.' ).addClass( 'is-error' );
				}
			} ).fail( function () {
				$status.text( 'Request failed.' ).addClass( 'is-error' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		/* -------------------------------------------------------------- */
		/*  Watermark live preview — refreshes the preview image whenever   */
		/* -------------------------------------------------------------- */
		var watermarkPreviewTimer = null;
		var watermarkPreviewBase  = null;

		function refreshWatermarkPreview() {
			var $img = $( '#crocina-watermark-preview-img' );
			if ( ! $img.length ) {
				return;
			}

			// Cache the base URL (without query string) on first call.
			if ( null === watermarkPreviewBase ) {
				var src = $img.attr( 'src' ) || '';
				var qIdx = src.indexOf( '?' );
				watermarkPreviewBase = qIdx > -1 ? src.substring( 0, qIdx ) : src;
			}

			var params = {
				type:          $( 'select[name="crocina_forms_settings[watermark_type]"]' ).val() || 'text',
				text:          $( 'input[data-watermark-preview="text"]' ).val() || $( 'input[name="crocina_forms_settings[watermark_text]"]' ).val() || '',
				position:      $( 'select[name="crocina_forms_settings[watermark_position]"]' ).val() || 'bottom-right',
				opacity:       $( 'input[data-watermark-preview="opacity"]' ).val() || 40,
				font_size:     $( 'input[data-watermark-preview="font_size"]' ).val() || 24,
				color:         $( 'input[data-watermark-preview="color"]' ).val() || '#ffffff',
				logo_id:       $( '#crocina-watermark-logo-id' ).val() || 0,
				logo_max_width: $( 'input[data-watermark-preview="logo_max_width"]' ).val() || 120,
				logo_opacity:  $( 'input[data-watermark-preview="logo_opacity"]' ).val() || 60,
				sample_id:    $( '#crocina-watermark-sample-id' ).val() || 0,
				t:             Date.now()
			};

			var qs = $.param( params );
			$img.attr( 'src', watermarkPreviewBase + '?' + qs );
		}

		/* Show/hide text vs logo fields based on watermark type. */
		$( document ).on( 'change', 'select[name="crocina_forms_settings[watermark_type]"]', function () {
			var val = $( this ).val();
			var showText = val === 'text' || val === 'both';
			var showLogo = val === 'logo' || val === 'both';
			$( '#crocina-watermark-text-fields' ).toggle( showText );
			$( '#crocina-watermark-logo-fields' ).toggle( showLogo );
			$( '#crocina-watermark-logo-upload' ).toggle( showLogo );
		} );

		$( document ).on( 'input change',
			'select[name="crocina_forms_settings[watermark_position]"], ' +
			'select[name="crocina_forms_settings[watermark_type]"], ' +
			'input[data-watermark-preview="text"], ' +
			'input[data-watermark-preview="opacity"], ' +
			'input[data-watermark-preview="font_size"], ' +
			'input[data-watermark-preview="color"], ' +
			'input[data-watermark-preview="logo_max_width"], ' +
			'input[data-watermark-preview="logo_opacity"]',
			function () {
				window.clearTimeout( watermarkPreviewTimer );
				watermarkPreviewTimer = window.setTimeout( refreshWatermarkPreview, 500 );
			}
		);

		/* -------------------------------------------------------------- */
		/*  Watermark logo — media library upload handler                 */
		/* -------------------------------------------------------------- */
		$( document ).on( 'click', '#crocina-logo-upload-btn', function ( event ) {
			event.preventDefault();

			var frame = wp.media({
				title:    'Select Logo',
				library:  { type: 'image/png' },
				button:   { text: 'Use as Logo' },
				multiple: false
			});

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();

				$( '#crocina-watermark-logo-id' ).val( attachment.id );
				$( '#crocina-logo-preview-img' ).attr( 'src', attachment.url );
				$( '#crocina-logo-filename' ).text( attachment.filename || '' );
				$( '#crocina-logo-preview' ).show();
				$( '#crocina-logo-upload-btn' ).hide();

				refreshWatermarkPreview();
			} );

			frame.open();
		} );

		$( document ).on( 'click', '#crocina-logo-remove', function () {
			$( '#crocina-watermark-logo-id' ).val( 0 );
			$( '#crocina-logo-preview' ).hide();
			$( '#crocina-logo-upload-btn' ).show();
			refreshWatermarkPreview();
		} );

		/* -------------------------------------------------------------- */
		/*  Watermark sample image — media library upload handler        */
		/* -------------------------------------------------------------- */
		$( document ).on( 'click', '#crocina-sample-upload-btn', function ( event ) {
			event.preventDefault();

			var frame = wp.media({
				title:    'Select Sample Image',
				library:  { type: 'image' },
				button:   { text: 'Use as Sample' },
				multiple: false
			});

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();

				$( '#crocina-watermark-sample-id' ).val( attachment.id );
				$( '#crocina-sample-preview-img' ).attr( 'src', attachment.url );
				$( '#crocina-sample-filename' ).text( attachment.filename || '' );
				$( '#crocina-sample-preview' ).show();
				$( '#crocina-sample-upload-btn' ).hide();

				refreshWatermarkPreview();
			} );

			frame.open();
		} );

		$( document ).on( 'click', '#crocina-sample-remove', function () {
			$( '#crocina-watermark-sample-id' ).val( 0 );
			$( '#crocina-sample-preview' ).hide();
			$( '#crocina-sample-upload-btn' ).show();
			refreshWatermarkPreview();
		} );

		/* -------------------------------------------------------------- */
		/*  Batch watermark — chunked AJAX processing for existing images */
		/* -------------------------------------------------------------- */
		var batchRunning = false;

		function batchWatermarkChunk( $bar, $status, $results, nonce, isFirst ) {
			var ajaxUrl = window.ajaxurl || '';
			var data = {
				action: 'crocina_batch_watermark',
				nonce: nonce,
				chunk: 5
			};
			if ( isFirst ) {
				data.scan = 1;
			}

			$.post( ajaxUrl, data ).done( function ( response ) {
				if ( ! response || ! response.success ) {
					var msg = ( response && response.data && response.data.message ) || 'Request failed.';
					$status.text( 'Error: ' + msg ).css( 'color', '#d63638' );
					batchRunning = false;
					$( '#crocina-batch-start' ).prop( 'disabled', false ).text( $( '#crocina-batch-start' ).data( 'default-text' ) || 'Apply Watermark to All Images' );
					return;
				}

				var data = response.data;

				if ( data.total === 0 ) {
					$status.text( data.message || 'No images found.' );
					$bar.css( 'width', '100%' );
					batchRunning = false;
					$( '#crocina-batch-start' ).prop( 'disabled', false ).text( $( '#crocina-batch-start' ).data( 'default-text' ) || 'Apply Watermark to All Images' );
					return;
				}

				var pct = Math.min( 100, Math.round( ( data.processed / data.total ) * 100 ) );
				$bar.css( 'width', pct + '%' );
				$status.text( data.processed + ' / ' + data.total + ' images (' + data.success + ' ok)' );

				// Show cumulative failures inline.
				if ( data.failed && data.failed.length > 0 ) {
					var failList = data.failed.join( '<br>' );
					$results.html( '<div class="crocina-batch-errors">' + failList + '</div>' );
				}

				if ( data.done ) {
					$bar.css( 'width', '100%' );
					$status.text( data.message || 'Complete.' );
					batchRunning = false;
					$( '#crocina-batch-start' ).prop( 'disabled', false ).text( $( '#crocina-batch-start' ).data( 'default-text' ) || 'Apply Watermark to All Images' );
				} else {
					// Continue with next chunk after a short delay.
					window.setTimeout( function () {
						batchWatermarkChunk( $bar, $status, $results, nonce, false );
					}, 300 );
				}
			} ).fail( function () {
				$status.text( 'Network error — batch aborted.' ).css( 'color', '#d63638' );
				batchRunning = false;
				$( '#crocina-batch-start' ).prop( 'disabled', false ).text( $( '#crocina-batch-start' ).data( 'default-text' ) || 'Apply Watermark to All Images' );
			} );
		}

		$( document ).on( 'click', '#crocina-batch-start', function () {
			if ( batchRunning ) {
				return;
			}

			var $btn = $( this );
			var nonce = $btn.data( 'nonce' );
			var $progress = $( '#crocina-batch-progress' );
			var $bar = $( '#crocina-batch-bar-fill' );
			var $status = $( '#crocina-batch-status' );
			var $results = $( '#crocina-batch-results' );

			$btn.prop( 'disabled', true ).text( $btn.data( 'running-text' ) || 'Processing…' );
			$progress.show();
			$bar.css( 'width', '0%' );
			$status.text( 'Scanning media library…' ).css( 'color', '' );
			$results.empty();

			batchRunning = true;
			batchWatermarkChunk( $bar, $status, $results, nonce, true );
		} );

		$( document ).on( 'click', '.crocina-partial-export-btn', function () {
			var $wrap = $( '.crocina-export-modern' );
			var $status = $wrap.find( '.crocina-export-status' );
			var $textarea = $( '#crocina-export-json' );
			var ajaxUrl = $wrap.data( 'partial-url' );
			var nonce = $wrap.data( 'partial-nonce' );
			var categories = [];
			$wrap.find( 'input[name="crocina_export_cats[]"]:checked' ).each( function () {
				categories.push( $( this ).val() );
			} );

			if ( ! categories.length ) {
				$status.text( 'Please select at least one category.' ).addClass( 'is-error' );
				return;
			}

			$.post( ajaxUrl, {
				action: 'crocina_partial_export',
				nonce: nonce,
				categories: categories
			} ).done( function ( response ) {
				if ( response && response.success && response.data && response.data.json ) {
					$textarea.val( response.data.json );
					$status.text( 'Partial export generated. Copy or download below.' ).removeClass( 'is-error' );
				} else {
					$status.text( ( response && response.data && response.data.message ) || 'Export failed.' ).addClass( 'is-error' );
				}
			} ).fail( function () {
				$status.text( 'Request failed.' ).addClass( 'is-error' );
			} );
		} );

		/**
		 * Render the quick-view field list.
		 *
		 * Prefers a structured `fieldsData` array (label/value pairs) so each
		 * value is inserted as a text node (no HTML parsing) for defense in
		 * depth. Falls back to the pre-escaped server markup only when the
		 * structured data is unavailable.
		 *
		 * @param {jQuery} $body The quick-view body container.
		 * @param {Object} data  The AJAX response data object.
		 * @return {void}
		 */
		function renderQuickViewFields( $body, data ) {
			if ( ! $body.length ) {
				return;
			}

			$body.empty();

			if ( data && $.isArray( data.fieldsData ) ) {
				$.each( data.fieldsData, function ( index, row ) {
					var $item = $( '<div class="crocina-quick-view-row"></div>' );
					$( '<span class="crocina-quick-view-label"></span>' ).text( ( row && row.label ) || '' ).appendTo( $item );
					$( '<span class="crocina-quick-view-value"></span>' ).text( ( row && row.value ) || '' ).appendTo( $item );
					$body.append( $item );
				} );
				return;
			}

			// Fallback: server-escaped markup string.
			$body.html( ( data && data.fields ) || '' );
		}

		var $inbox = $( '.crocina-inbox-modern' );
		if ( $inbox.length ) {

			var ajaxUrl = window.ajaxurl || $inbox.data( 'ajax-url' );
			var markNonce = $inbox.data( 'mark-read-nonce' );
			var quickNonce = $inbox.data( 'quick-view-nonce' );
			var $quickModal = $( '.crocina-quick-view-modal' );

			var markRowRead = function ( logId ) {
				var $row = $( 'tr' ).has( '[data-log-id="' + logId + '"]' );
				$row.removeClass( 'is-unread' ).addClass( 'is-read' );
				$row.find( '.crocina-inbox-badge' )
					.removeClass( 'is-unread' )
					.addClass( 'is-read' )
					.text( $row.find( '.crocina-inbox-badge' ).data( 'read-label' ) || 'Read' );
				$row.find( '.crocina-mark-read' ).remove();
			};

			$( document ).on( 'click', '.crocina-mark-read', function ( event ) {
				event.preventDefault();
				var logId = $( this ).data( 'log-id' );
				if ( ! logId || ! ajaxUrl ) {
					return;
				}
				$.post( ajaxUrl, {
					action: 'crocina_mark_read',
					nonce: markNonce,
					log_id: logId
				} ).done( function ( response ) {
					if ( response && response.success ) {
						markRowRead( logId );
					}
				} );
			} );

			$( document ).on( 'click', '.crocina-quick-view', function ( event ) {
				event.preventDefault();
				var logId = $( this ).data( 'log-id' );
				if ( ! logId || ! ajaxUrl || ! $quickModal.length ) {
					return;
				}
				$.post( ajaxUrl, {
					action: 'crocina_quick_view_log',
					nonce: quickNonce,
					log_id: logId
				} ).done( function ( response ) {
					if ( response && response.success && response.data ) {
						$quickModal.find( '.crocina-quick-view-title' ).text( response.data.form || '' );
						$quickModal.find( '.crocina-quick-view-date' ).text( response.data.date || '' );
						$quickModal.find( '.crocina-quick-view-ip' ).text( response.data.ip || '' );
						$quickModal.find( '.crocina-quick-view-page' ).attr( 'href', response.data.page || '#' ).text( response.data.page || '' );
						// The server returns markup for the fields list; render it
						// as text nodes per row where possible for defense in depth.
						renderQuickViewFields( $quickModal.find( '.crocina-quick-view-body' ), response.data );

						$quickModal.addClass( 'is-open' );
						markRowRead( logId );
					}
				} );
			} );

			$( document ).on( 'click', '.crocina-quick-view-close', function () {
				$quickModal.removeClass( 'is-open' );
			} );

			$( document ).on( 'click', '.crocina-quick-view-modal', function ( event ) {
				if ( $( event.target ).is( '.crocina-quick-view-modal' ) ) {
					$quickModal.removeClass( 'is-open' );
				}
			} );
		}

		$( '#crocina-select-all' ).on( 'change', function () {
			var checked = $( this ).prop( 'checked' );
			$( 'input[name="form_ids[]"]' ).prop( 'checked', checked );
		} );

		$( document ).on( 'click', '.crocina-quick-edit', function () {
			var $icon = $( this );
			var formId = $icon.data( 'form-id' );
			var nonce = $icon.data( 'nonce' );
			var $row = $icon.closest( 'tr' );
			var $titleCell = $row.find( 'td:eq(1)' );
			var $titleLink = $titleCell.find( '.row-title' );
			var currentTitle = $titleLink.text();

			if ( $row.find( '.crocina-quick-edit-input' ).length ) {
				return;
			}

			var $input = $( '<input type="text" class="crocina-quick-edit-input regular-text" value="' + currentTitle.replace( /"/g, '&quot;' ) + '" />' );
			var $saveBtn = $( '<button type="button" class="button button-small crocina-quick-edit-save">' + 'OK' + '</button>' );
			var $cancelBtn = $( '<button type="button" class="button-link crocina-quick-edit-cancel">' + 'Cancel' + '</button>' );

			$titleLink.hide();
			$titleCell.find( '.crocina-quick-edit' ).hide();
			$titleCell.append( $input, ' ', $saveBtn, ' ', $cancelBtn );
			$input.focus().select();

			function cancelEdit() {
				$input.remove();
				$saveBtn.remove();
				$cancelBtn.remove();
				$titleLink.show();
				$titleCell.find( '.crocina-quick-edit' ).show();
			}

			$cancelBtn.on( 'click', cancelEdit );

			$saveBtn.on( 'click', function () {
				var newTitle = $.trim( $input.val() );
				if ( ! newTitle || newTitle === currentTitle ) {
					cancelEdit();
					return;
				}
				$.post( window.ajaxurl || '', {
					action: 'crocina_quick_edit_title',
					form_id: formId,
					nonce: nonce,
					title: newTitle
				} ).done( function ( response ) {
					if ( response && response.success ) {
						$titleLink.text( response.data.title );
					}
					cancelEdit();
				} );
			} );

			$input.on( 'keydown', function ( event ) {
				if ( event.key === 'Enter' ) {
					$saveBtn.trigger( 'click' );
				} else if ( event.key === 'Escape' ) {
					cancelEdit();
				}
			} );
		} );

		$( '#crocina-dashboard-bulk-form' ).on( 'submit', function ( event ) {
			var action = $( this ).find( 'select[name="crocina_bulk_action"]' ).val();
			var count = $( this ).find( 'input[name="form_ids[]"]:checked' ).length;
			if ( ! action || ! count ) {
				event.preventDefault();
				return;
			}
			if ( action === 'delete' && ! window.confirm( count + ' form(s) will be permanently deleted. Continue?' ) ) {
				event.preventDefault();
			}
			if ( action === 'duplicate' && ! window.confirm( count + ' form(s) will be duplicated. Continue?' ) ) {
				event.preventDefault();
			}
		} );

		/* -------------------------------------------------------------- */
		/*  Test notification — send a sample to all enabled channels     */
		/* -------------------------------------------------------------- */
		$( document ).on( 'click', '.crocina-test-notification-btn', function () {
			var $btn = $( this );
			var formId = $btn.data( 'form-id' );
			var nonce = $btn.data( 'nonce' );
			var ajaxUrl = window.ajaxurl || '';
			var $results = $( '.crocina-test-notification-results' );
			var originalText = $btn.html();

			if ( ! formId || ! nonce ) {
				return;
			}

			$btn.prop( 'disabled', true );
			$results.html( '<span class="crocina-test-channel-result is-loading">' + 'Sending…' + '</span>' );

			$.post( ajaxUrl, {
				action: 'crocina_test_notification',
				nonce: nonce,
				form_id: formId
			} ).done( function ( response ) {
				if ( response && response.success && response.data && response.data.summary ) {
					var html = '';
					$.each( response.data.summary, function ( channel, info ) {
						var cls = info.success ? 'is-success' : 'is-error';
						var icon = info.success ? 'dashicons-yes-alt' : 'dashicons-warning';
						html += '<span class="crocina-test-channel-result ' + cls + '">';
						html += '<span class="dashicons ' + icon + '" aria-hidden="true"></span>';
						html += '<strong>' + $( '<div>' ).text( info.label || channel ).html() + '</strong>';
						html += '<small>' + $( '<div>' ).text( info.message || '' ).html() + '</small>';
						html += '</span>';
					} );
					$results.html( html );
				} else {
					var msg = ( response && response.data && response.data.message ) || 'Request failed.';
					$results.html( '<span class="crocina-test-channel-result is-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span>' + $( '<div>' ).text( msg ).html() + '</span>' );
				}
			} ).fail( function () {
				$results.html( '<span class="crocina-test-channel-result is-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span>Request failed.</span>' );
			} ).always( function () {
				$btn.prop( 'disabled', false ).html( originalText );
			} );
		} );

		/* -------------------------------------------------------------- */
		/*  Event Log — Refresh & Clear button handlers                   */
		/* -------------------------------------------------------------- */
		$( document ).on( 'click', '#crocina-refresh-log', function () {
			window.location.reload();
		} );

		$( document ).on( 'click', '#crocina-clear-log', function () {
			var $btn = $( this );
			var confirmMsg = $btn.data( 'confirm' ) || 'Are you sure?';
			if ( ! window.confirm( confirmMsg ) ) {
				return;
			}

			var ajaxUrl = window.ajaxurl || '';
			var nonce = $btn.data( 'nonce' ) || '';
			var $result = $( '#crocina-event-log-result' );

			$btn.prop( 'disabled', true ).text( $btn.data( 'loading-text' ) || 'Clearing...' );
			$result.removeClass( 'is-visible is-success is-error' ).text( '' );

			$.post( ajaxUrl, {
				action: 'crocina_clear_event_log',
				nonce: nonce
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$result.text( response.data.message || 'Log cleared.' )
						.addClass( 'is-visible is-success' );
					window.setTimeout( function () {
						window.location.reload();
					}, 800 );
				} else {
					$result.text( ( response && response.data && response.data.message ) || 'Failed to clear log.' )
						.addClass( 'is-visible is-error' );
				}
			} ).fail( function () {
				$result.text( 'Request failed.' ).addClass( 'is-visible is-error' );
			} ).always( function () {
				$btn.prop( 'disabled', false ).text( $btn.data( 'default-text' ) || 'Clear log' );
			} );
		} );

		/* -------------------------------------------------------------- */
		/*  System Status — real-time refresh button                      */
		/* -------------------------------------------------------------- */
		$( document ).on( 'click', '#crocina-system-refresh', function () {
			var $btn = $( this );
			var nonce = $btn.data( 'nonce' );
			var ajaxUrl = window.ajaxurl || '';
			var $content = $( '#crocina-system-content' );
			var $ts = $( '#crocina-system-timestamp' );

			$btn.prop( 'disabled', true );
			$btn.find( '.dashicons-update' ).addClass( 'crocina-system-refresh-spin' );

			/* Helper functions for rendering status indicators */
			var updateBadge = function( $el, pct ) {
				$el.text( pct + '%' ).removeClass( 'crocina-system-badge-ok crocina-system-badge-warn crocina-system-badge-poor' );
				if ( pct >= 80 ) { $el.addClass( 'crocina-system-badge-ok' ); }
				else if ( pct >= 50 ) { $el.addClass( 'crocina-system-badge-warn' ); }
				else { $el.addClass( 'crocina-system-badge-poor' ); }
			};
			var renderStatus = function( val ) {
				if ( val ) return '<span class="crocina-status-dot crocina-status-ok"></span><span style="color:#00a32a;font-weight:600;">Yes</span>';
				return '<span class="crocina-status-dot crocina-status-err"></span><span style="color:#d63638;font-weight:600;">No</span>';
			};

			$.post( ajaxUrl, {
				action: 'crocina_system_refresh',
				nonce: nonce
			} ).done( function ( response ) {
				if ( response && response.success && response.data ) {
					var d = response.data;
					var ts = d.timestamp ? new Date( d.timestamp * 1000 ).toLocaleTimeString() : '';
					$ts.css( 'color', '' ).text( 'Last updated: ' + ts );

					/* ---- Overview cards ---- */
					if ( d.total_forms !== undefined )
						$( '#tab-system .crocina-system-card:eq(0) .crocina-system-card-value' ).text( d.total_forms.toLocaleString() );
					if ( d.total_logs !== undefined )
						$( '.crocina-system-card-db .crocina-system-card-value' ).text( d.total_logs.toLocaleString() );
					if ( d.unread_logs !== undefined )
						$( '.crocina-system-card-unread .crocina-system-card-value' ).text( d.unread_logs.toLocaleString() );
					if ( d.cache_backend !== undefined ) {
						var bl = d.cache_backend === 'persistent' ? 'Persistent' : 'Default';
						$( '.crocina-system-card-value-cache' ).text( bl );
						$( '.crocina-cache-backend-label' ).text( d.cache_backend === 'persistent' ? 'Persistent (Redis/Memcached)' : 'Default (transient fallback)' );
					}

					/* ---- Cache Performance Bars ---- */
					if ( d.cache_stats ) {
						var s = d.cache_stats;
						var settT = ( s.settings_db_calls || 0 ) + ( s.settings_cache_hits || 0 );
						var settP = settT > 0 ? Math.round( ( s.settings_cache_hits || 0 ) / settT * 100 ) : 0;
						var desB = s.design_preloaded || 0;
						var desI = s.design_db_calls || 0;
						var desT = desB + desI;
						var desP = desT > 0 ? Math.round( desB / desT * 100 ) : 0;

						updateBadge( $( '.crocina-metric-card:eq(0) .crocina-system-badge' ), settP );
						$( '.crocina-metric-card:eq(0) .crocina-progress-fill' ).css( 'width', settP + '%' );
						$( '.crocina-metric-card:eq(0) .crocina-metric-details strong:eq(0)' ).text( s.settings_db_calls || 0 );
						$( '.crocina-metric-card:eq(0) .crocina-metric-details strong:eq(1)' ).text( s.settings_cache_hits || 0 );

						updateBadge( $( '.crocina-metric-card:eq(1) .crocina-system-badge' ), desP );
						$( '.crocina-metric-card:eq(1) .crocina-progress-fill' ).css( 'width', desP + '%' );
						$( '.crocina-metric-card:eq(1) .crocina-metric-details strong:eq(0)' ).text( desB );
						$( '.crocina-metric-card:eq(1) .crocina-metric-details strong:eq(1)' ).text( desI );
					}

					/* ---- Capabilities ---- */
					if ( d.capabilities ) {
						$( '#crocina-cap-grid .crocina-cap-loader' ).each( function () {
							var $row = $( this ).closest( '.crocina-cap-row' );
							var cap = $.trim( $row.find( '.crocina-cap-name' ).text() );
							var val = d.capabilities[ cap ];
							$( this ).html( renderStatus( val ) ).addClass( 'loaded' );
						} );
					}

					/* ---- REST API ---- */
					if ( d.rest_health && $.isArray( d.rest_health ) ) {
						$( '#crocina-rest-grid .crocina-rest-loader' ).each( function ( idx ) {
							var entry = d.rest_health[ idx ];
							var ok = entry && entry.status === 'registered';
							$( this ).html( renderStatus( ok ) ).addClass( 'loaded' );
						} );
					}

					/* ---- Error Log Guard ---- */
					if ( d.wp_debug !== undefined ) {
						var loaderIdx = 0;
						$( '#crocina-err-grid .crocina-err-loader' ).each( function () {
							var val;
							if ( loaderIdx === 0 ) val = d.wp_debug;
							else if ( loaderIdx === 1 ) val = d.wp_debug_log;
							else if ( loaderIdx === 2 ) val = d.wp_debug_display;
							else val = null; /* status note */

							if ( val !== null ) {
								$( this ).html( renderStatus( val ) ).addClass( 'loaded' );
								}
						loaderIdx++;
					} );
				}

					/* ---- Environment ---- */
					if ( d.environment ) {
						$( '#crocina-env-grid .crocina-env-value[data-env-key]' ).each( function () {
							var $val = $( this );
							var key = $val.data( 'env-key' );
							var raw = d.environment[ key ];
							if ( raw !== undefined ) {
								if ( key === 'object_cache' ) {
									$val.text( raw ? 'Active' : 'Inactive' );
								} else if ( key === 'max_execution' ) {
									$val.text( raw + 's' );
								} else {
									$val.text( raw );
								}
							}
						} );
					}

				} else {
					$ts.text( 'Refresh failed.' ).css( 'color', '#d63638' );
					$ts.text( 'Refresh failed.' ).css( 'color', '#d63638' );
					window.setTimeout( function () {
						$ts.css( 'color', '' );
					}, 3000 );
				}
			} ).fail( function () {
				$ts.text( 'Request failed.' ).css( 'color', '#d63638' );
				window.setTimeout( function () {
					$ts.css( 'color', '' );
				}, 3000 );
			} ).always( function () {
				$btn.prop( 'disabled', false );
				$btn.find( '.dashicons-update' ).removeClass( 'crocina-system-refresh-spin' );
			} );
		} );

		/* -------------------------------------------------------------- */
		/*  Per-channel test buttons (settings page)                      */
		/* -------------------------------------------------------------- */
		$( document ).on( 'click', '.crocina-test-channel-btn', function () {
			var $btn = $( this );
			var channel = $btn.data( 'channel' );
			var nonce = $btn.data( 'nonce' );
			var $result = $( '.crocina-channel-test-result[data-channel="' + channel + '"]' );
			var ajaxUrl = window.ajaxurl || '';
			var originalText = $btn.text();

			if ( ! channel || ! nonce || ! $result.length ) {
				return;
			}

			$btn.prop( 'disabled', true ).text( 'Sending…' );
			$result.html( '<span class="crocina-channel-test-loading">' + 'Sending…' + '</span>' ).removeClass( 'is-success is-error is-detail' );

			$.post( ajaxUrl, {
				action: 'crocina_test_channel',
				nonce: nonce,
				channel: channel
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$result.html( '<div class="crocina-channel-test-msg"><span class="crocina-channel-test-ok dashicons dashicons-yes-alt"></span> ' + ( response.data.message || 'Sent.' ) + '</div>' )
						.addClass( 'is-success' );
				} else {
					var errMsg = ( response && response.data && response.data.message ) || 'Failed.';
					var detailHtml = '';
					// Build help tips based on error message content.
					if ( errMsg.indexOf( 'token' ) !== -1 || errMsg.indexOf( 'chat' ) !== -1 || errMsg.indexOf( 'empty' ) !== -1 ) {
						detailHtml = '<div class="crocina-channel-test-detail">'
							+ '<p><strong>Possible causes:</strong></p>'
							+ '<ul>'
							+ '<li>Make sure you have entered the <strong>token</strong> and <strong>Chat ID</strong> in the fields above.</li>'
							+ '<li>Click the guide link above for step-by-step instructions on how to get them.</li>'
							+ '<li>After filling the fields, click <strong>Save Settings</strong> before testing.</li>'
							+ '</ul></div>';
					} else if ( errMsg.indexOf( '401' ) !== -1 || errMsg.indexOf( '403' ) !== -1 || errMsg.indexOf( 'Unauthorized' ) !== -1 ) {
						detailHtml = '<div class="crocina-channel-test-detail">'
							+ '<p><strong>Authentication error:</strong></p>'
							+ '<ul>'
							+ '<li>Your <strong>token</strong> may be invalid or expired. Generate a new one from the bot service.</li>'
							+ '<li>For WhatsApp, temporary tokens from Meta expire — generate a permanent token.</li>'
							+ '</ul></div>';
					} else if ( errMsg.indexOf( 'timeout' ) !== -1 || errMsg.indexOf( 'timed out' ) !== -1 || errMsg.indexOf( 'cURL' ) !== -1 ) {
						detailHtml = '<div class="crocina-channel-test-detail">'
							+ '<p><strong>Network error:</strong></p>'
							+ '<ul>'
							+ '<li>The request timed out. Check if the endpoint is reachable from your server.</li>'
							+ '<li>Some hosts block outbound connections — contact your hosting provider.</li>'
							+ '<li>If you are in Iran, you may need a VPN for WhatsApp/Meta services.</li>'
							+ '</ul></div>';
					} else if ( errMsg.indexOf( 'endpoint' ) !== -1 || errMsg.indexOf( 'URL' ) !== -1 || errMsg.indexOf( 'not allowed' ) !== -1 ) {
						detailHtml = '<div class="crocina-channel-test-detail">'
							+ '<p><strong>Endpoint issue:</strong></p>'
							+ '<ul>'
							+ '<li>Check the <strong>endpoint URL</strong> — it must start with <code>https://</code>.</li>'
							+ '<li>For Telegram: <code>https://api.telegram.org</code></li>'
							+ '<li>For Bale: <code>https://tapi.bale.ai</code></li>'
							+ '<li>For Rubika: <code>https://botapi.rubika.ir/v3/</code></li>'
							+ '</ul></div>';
					} else {
						detailHtml = '<div class="crocina-channel-test-detail">'
							+ '<p><strong>Steps to fix:</strong></p>'
							+ '<ul>'
							+ '<li>Verify your <strong>token</strong>, <strong>Chat ID</strong>, and <strong>endpoint</strong> are correct.</li>'
							+ '<li>Click <strong>Save Settings</strong> before testing.</li>'
							+ '<li>Check the <strong>Channel Error History</strong> section below for more details.</li>'
							+ '<li>Enable <code>WP_DEBUG</code> in wp-config.php for detailed PHP error logs.</li>'
							+ '</ul></div>';
					}
					$result.html( '<div class="crocina-channel-test-msg"><span class="crocina-channel-test-err dashicons dashicons-warning"></span> <strong>Error:</strong> ' + errMsg + '</div>' + detailHtml )
						.addClass( 'is-error is-detail' );
				}
			} ).fail( function ( jqXHR ) {
				var errText = 'Request failed.';
				if ( jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
					errText = jqXHR.responseJSON.data.message;
				} else if ( jqXHR && jqXHR.statusText ) {
					errText = 'HTTP ' + jqXHR.status + ': ' + jqXHR.statusText;
				}
				var detailHtml = '<div class="crocina-channel-test-detail">'
					+ '<p><strong>Server communication error.</strong></p>'
					+ '<ul>'
					+ '<li>Check your server error logs for more details.</li>'
					+ '<li>Ensure WordPress AJAX (admin-ajax.php) is working properly.</li>'
					+ '<li>Check your browser console for any JavaScript errors.</li>'
					+ '</ul></div>';
				$result.html( '<div class="crocina-channel-test-msg"><span class="crocina-channel-test-err dashicons dashicons-warning"></span> <strong>Error:</strong> ' + errText + '</div>' + detailHtml )
					.addClass( 'is-error is-detail' );
			} ).always( function () {
				$btn.prop( 'disabled', false ).text( originalText );
			} );
		} );
	} );
}( jQuery ) );

/* -------------------------------------------------------------- */
/*  Channel Error History — load and refresh from events.log      */
/* -------------------------------------------------------------- */
function copyToClipboard( text, onSuccess, onFail ) {
	if ( navigator.clipboard && navigator.clipboard.writeText ) {
		navigator.clipboard.writeText( text ).then( onSuccess ).catch( onFail );
	} else {
		var $temp = jQuery( '<textarea style="position:fixed;left:-9999px;">' ).val( text ).appendTo( 'body' );
		$temp[0].select();
		try {
			document.execCommand( 'copy' );
			onSuccess();
		} catch ( err ) {
			onFail();
		}
		$temp.remove();
	}
}

function escHtml( s ) { var d = document.createElement( "d" ); d.appendChild( document.createTextNode( s ) ); return d.innerHTML; }

$( document ).on( 'click', '#crocina-err-history-refresh', function () {
	var $btn = $( this );
	var $list = $( '#crocina-err-history-list' );
	var $filter = $( '#crocina-err-history-filter' );
	var nonce = $btn.data( 'nonce' );
	var channel = $filter.val() || '';

	$btn.prop( 'disabled', true );
	$list.html( '<p class="description" style="margin:0;text-align:center;">Loading…</p>' );

	$.get( window.ajaxurl, {
		action: 'crocina_get_channel_errors',
		nonce: nonce,
		filter: channel,
		limit: 100
	}, function ( resp ) {
		if ( ! resp.success || ! resp.data || ! resp.data.errors ) {
			$list.html( '<p class="description" style="margin:0;text-align:center;color:#d63638;">Failed to load error log.</p>' );
			$btn.prop( 'disabled', false );
			return;
		}

		var errors = resp.data.errors;
		if ( errors.length === 0 ) {
			$list.html( '<p class="description" style="margin:0;text-align:center;">No channel errors found.</p>' );
			$btn.prop( 'disabled', false );
			return;
		}

		var html = '';
		for ( var i = 0; i < errors.length; i++ ) {
			var e = errors[ i ];
			
			html += '<div class="crocina-err-row">';
			html += '<span class="crocina-err-time">' + escHtml( e.timestamp ) + '</span>';
			html += ' <span class="crocina-err-channel crocina-err-channel-' + escHtml( e.channel ) + '">[' + escHtml( e.channel ) + ']</span>';
			html += ' <span class="crocina-err-origin">(' + escHtml( e.origin ) + ')</span>';
			html += ' <span class="crocina-err-msg">' + escHtml( e.message ) + '</span>';
			if ( e.context && Object.keys( e.context ).length > 0 ) {
				html += ' <span class="crocina-err-ctx">' + escHtml( JSON.stringify( e.context ) ) + '</span>';
			}
			html += '</div>';
		}

		$list.html( html );
		$btn.prop( 'disabled', false );
	} ).fail( function () {
		$list.html( '<p class="description" style="margin:0;text-align:center;color:#d63638;">Request failed.</p>' );
		$btn.prop( 'disabled', false );
	} );
} );

/* Auto-refresh when channel filter changes */
$( document ).on( 'change', '#crocina-err-history-filter', function () {
	$( '#crocina-err-history-refresh' ).trigger( 'click' );
} );

/* -------------------------------------------------------------- */
/*  Mark all as read — Inbox page                                  */
/* -------------------------------------------------------------- */
$( document ).on( 'click', '.crocina-mark-all-read', function () {
	var $btn = $( this );
	var formId = $btn.data( 'form-id' ) || 0;
	var nonce = $btn.data( 'nonce' ) || '';
	var ajaxUrl = window.ajaxurl || '';

	if ( ! nonce ) {
		return;
	}

	$btn.prop( 'disabled', true ).text( 'Marking…' );

	$.post( ajaxUrl, {
		action: 'crocina_mark_all_read',
		nonce: nonce,
		form_id: formId
	} ).done( function ( response ) {
		if ( response && response.success && response.data ) {
			/* Remove all unread indicators from visible rows. */
			$( 'tr.is-unread' )
				.removeClass( 'is-unread' )
				.addClass( 'is-read' );
			$( 'tr.is-unread .crocina-inbox-badge' )
				.removeClass( 'is-unread' )
				.addClass( 'is-read' )
				.text( function () {
					return $( this ).data( 'read-label' ) || 'Read';
				} );
			$( 'tr.is-unread .crocina-mark-read' ).remove();

			/* Update the unread count badge. */
			var newUnread = response.data.total_unread || 0;
			$( '.crocina-inbox-unread-count' ).text( newUnread.toLocaleString() );

			$btn.text( response.data.message || 'Done' );
			$btn.prop( 'disabled', false );

			/* Hide the button if no unread remain. */
			if ( newUnread === 0 ) {
				$btn.hide();
			}
		} else {
			$btn.text( 'Failed' ).prop( 'disabled', false );
		}
	} ).fail( function () {
		$btn.text( 'Network error' ).prop( 'disabled', false );
	} );
} )( jQuery );
