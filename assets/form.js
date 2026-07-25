( function () {
	'use strict';

	function getFormNotice( form ) {
		return form ? form.querySelector( '.crocina-notice' ) : null;
	}

	function getParameter( name ) {
		var regex = new RegExp( '(?:\\?|&)' + name + '=([^&]*)' );
		var match = window.location.search.match( regex );
		return match ? decodeURIComponent( match[1].replace( /\+/g, ' ' ) ) : '';
	}

	function noticeForm( form, status, message, meta ) {
		if ( ! form || ! status || ! message ) {
			return;
		}

		var existing = getFormNotice( form );
		if ( existing ) {
			existing.remove();
		}

		var notice = document.createElement( 'div' );
		notice.className = 'crocina-notice ' + ( 'sent' === status ? 'crocina-notice-success' : 'crocina-notice-error' );
		notice.setAttribute( 'tabindex', '-1' );
		notice.setAttribute( 'role', 'status' );
		notice.textContent = message;
		if ( meta ) {
			var metaRow = document.createElement( 'div' );
			metaRow.className = 'crocina-notice-meta';
			metaRow.textContent = meta;
			notice.appendChild( metaRow );
		}
		form.insertBefore( notice, form.firstChild );
		notice.focus( { preventScroll: false } );
		notice.scrollIntoView( { block: 'start' } );
	}

	function handleAjaxSubmit( form, formWrapper ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( '1' !== form.getAttribute( 'data-crocina-ajax' ) ) {
				return;
			}

			if ( ! window.CrocinaForms || ! CrocinaForms.ajaxUrl ) {
				return;
			}

			event.preventDefault();

			/* Client-side file-size check — reject oversized files before any network request. */
			var fileInputs = form.querySelectorAll( 'input[type="file"]' );
			var oversized = [];
			fileInputs.forEach( function ( fi ) {
				var f = fi.files && fi.files[0];
				if ( ! f ) {
					return;
				}
				var maxBytes = getMaxUploadBytes( fi );
				if ( isFinite( maxBytes ) && f.size > maxBytes ) {
					oversized.push( f.name );
				}
			} );
			if ( oversized.length ) {
				noticeForm( formWrapper, 'error', 'The following files exceed the maximum upload size: ' + oversized.join( ', ' ) + '.' );
				setLoadingState( false );
				return;
			}

			var submitButton = form.querySelector( '.crocina-button' );
			var submitLabel = submitButton ? submitButton.querySelector( '.crocina-button__label' ) : null;
			var originalLabel = submitLabel ? submitLabel.textContent : '';
			var formIdField = form.querySelector( 'input[name="crocina_form_id"]' );
			var formId = formIdField ? formIdField.value : '';


			function setLoadingState( isLoading ) {
				if ( ! submitButton ) {
					return;
				}
				if ( isLoading ) {
					submitButton.disabled = true;
					submitButton.classList.add( 'is-loading' );
					if ( submitLabel ) {
						var fallbackText = ( window.CrocinaForms && CrocinaForms.i18n && CrocinaForms.i18n.sending ) ? CrocinaForms.i18n.sending : 'Sending...';
						submitLabel.textContent = submitButton.getAttribute( 'data-loading-text' ) || fallbackText;
					}
					if ( formWrapper ) {
						formWrapper.classList.add( 'crocina-is-loading' );
						formWrapper.setAttribute( 'aria-busy', 'true' );
					}
				} else {
					submitButton.disabled = false;
					submitButton.classList.remove( 'is-loading' );
					if ( submitLabel ) {
						submitLabel.textContent = originalLabel;
					}
					if ( formWrapper ) {
						formWrapper.classList.remove( 'crocina-is-loading' );
						formWrapper.removeAttribute( 'aria-busy' );
					}
				}
			}

			var $progressContainer = form.querySelector( '.crocina-upload-progress' );
			var $progressFill = $progressContainer ? $progressContainer.querySelector( '.crocina-upload-progress-fill' ) : null;
			var $progressPct = $progressContainer ? $progressContainer.querySelector( '.crocina-upload-progress-pct' ) : null;
			var hasFileFields = form.querySelector( 'input[type="file"]' ) !== null;

			function updateProgress( pct ) {
				if ( $progressFill ) { $progressFill.style.width = pct + '%'; }
				if ( $progressPct ) { $progressPct.textContent = pct + '%'; }
			}

			function showProgress() {
				if ( $progressContainer ) { $progressContainer.style.display = ''; }
				updateProgress( 0 );
			}

			function hideProgress() {
				if ( $progressContainer ) { $progressContainer.style.display = 'none'; }
				updateProgress( 0 );
			}

			setLoadingState( true );

			function submitFormViaXHR( formData ) {
				var xhr = new XMLHttpRequest();

				xhr.upload.addEventListener( 'progress', function ( evt ) {
					if ( evt.lengthComputable ) {
						var pct = Math.round( ( evt.loaded / evt.total ) * 100 );
						updateProgress( pct );
					}
				} );

				xhr.addEventListener( 'load', function () {
					var result;
					try { result = JSON.parse( xhr.responseText ); } catch ( e ) { result = null; }

					hideProgress();

					if ( ! result || ! result.data ) {
						setLoadingState( false );
						return;
					}
					var meta = result.data.submitted_at_jalali ? ( 'Submitted at: ' + result.data.submitted_at_jalali ) : '';
					if ( result.data.debug ) {
						var debugParts = [];
						if ( result.data.debug.timestamp ) { debugParts.push( 'Time: ' + result.data.debug.timestamp ); }
						if ( result.data.debug.ip ) { debugParts.push( 'IP: ' + result.data.debug.ip ); }
						if ( debugParts.length ) {
							meta = meta ? meta + ' | ' + debugParts.join( ' | ' ) : debugParts.join( ' | ' );
						}
					}
					if ( result.success ) {
						form.reset();
						form.querySelectorAll( '.crocina-file-preview' ).forEach( function ( el ) { el.innerHTML = ''; } );
						noticeForm( formWrapper, 'sent', result.data.message, meta );
					} else {
						noticeForm( formWrapper, 'error', result.data.message, meta );
					}
					setLoadingState( false );
				} );

				xhr.addEventListener( 'error', function () {
					hideProgress();
					setLoadingState( false );
					var errorText = ( window.CrocinaForms && CrocinaForms.i18n && CrocinaForms.i18n.submitError ) ? CrocinaForms.i18n.submitError : 'Unable to submit the form.';
					noticeForm( formWrapper, 'error', errorText );
				} );

				xhr.addEventListener( 'abort', function () {
					hideProgress();
					setLoadingState( false );
				} );

				xhr.open( 'POST', CrocinaForms.ajaxUrl );
				xhr.withCredentials = true;
				xhr.send( formData );
			}

			function submitForm() {
				var formData = new FormData( form );
				formData.append( 'action', 'crocina_submit_form' );

				if ( hasFileFields ) {
					showProgress();
					submitFormViaXHR( formData );
					return;
				}

				fetch( CrocinaForms.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData
				} )
					.then( function ( response ) { return response.json(); } )
					.then( function ( result ) {
						if ( ! result || ! result.data ) {
							setLoadingState( false );
							return;
						}
						var meta = result.data.submitted_at_jalali ? ( 'Submitted at: ' + result.data.submitted_at_jalali ) : '';
						if ( result.data.debug ) {
							var debugParts = [];
							if ( result.data.debug.timestamp ) {
								debugParts.push( 'Time: ' + result.data.debug.timestamp );
							}
							if ( result.data.debug.ip ) {
								debugParts.push( 'IP: ' + result.data.debug.ip );
							}
							if ( debugParts.length ) {
								meta = meta ? meta + ' | ' + debugParts.join( ' | ' ) : debugParts.join( ' | ' );
							}
						}
						if ( result.success ) {
							form.reset();
							form.querySelectorAll( '.crocina-file-preview' ).forEach( function ( el ) {
								el.innerHTML = '';
							} );
							noticeForm( formWrapper, 'sent', result.data.message, meta );
						} else {
							noticeForm( formWrapper, 'error', result.data.message, meta );
						}
						setLoadingState( false );
					} )
					.catch( function () {
						var errorText = ( window.CrocinaForms && CrocinaForms.i18n && CrocinaForms.i18n.submitError ) ? CrocinaForms.i18n.submitError : 'Unable to submit the form.';
						noticeForm( formWrapper, 'error', errorText );
						setLoadingState( false );
					} );
			}

			// Refresh nonces first so forms served from a full-page cache still submit
			// with valid, non-expired security tokens. Fall back to the printed nonces
			// if the refresh request fails for any reason.
			if ( formId ) {
				var nonceData = new FormData();
				nonceData.append( 'action', 'crocina_get_nonce' );
				nonceData.append( 'form_id', formId );

				fetch( CrocinaForms.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: nonceData
				} )
					.then( function ( response ) { return response.json(); } )
					.then( function ( result ) {
						if ( result && result.success && result.data ) {
							var nonceField = form.querySelector( 'input[name="_crocina_nonce"]' );
							var ajaxNonceField = form.querySelector( 'input[name="_crocina_ajax_nonce"]' );
							if ( nonceField && result.data.nonce ) {
								nonceField.value = result.data.nonce;
							}
							if ( ajaxNonceField && result.data.ajax_nonce ) {
								ajaxNonceField.value = result.data.ajax_nonce;
							}
						}
						submitForm();
					} )
					.catch( function () {
						submitForm();
					} );
			} else {
				submitForm();
			}
		} );
	}


	/**
	 * Read the max upload size (in bytes) from the innermost form's
	 * data-crocina-max-upload attribute. Returns Infinity when unset so
	 * that the check gracefully passes for forms without the attribute.
	 */
	function getMaxUploadBytes( input ) {
		var form = input && input.closest( '.crocina-form__inner, form' );
		if ( ! form ) {
			return Infinity;
		}
		var val = form.getAttribute( 'data-crocina-max-upload' );
		if ( ! val ) {
			return Infinity;
		}
		var bytes = parseInt( val, 10 );
		return bytes > 0 ? bytes : Infinity;
	}

	/**
	 * Format a byte count into a human-readable string (e.g. "2.5 MB").
	 */
	function formatFileSize( bytes ) {
		if ( bytes < 1024 ) {
			return bytes + ' B';
		}
		if ( bytes < 1024 * 1024 ) {
			return ( bytes / 1024 ).toFixed( 0 ) + ' KB';
		}
		return ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
	}

	/**
	 * Validate the file size of a file input. If the file exceeds the
	 * server-side limit (read from data-crocina-max-upload), show an
	 * inline error in the preview area and clear the input.
	 * Returns true when the file is OK, false when it was rejected.
	 */
	function validateFileSize( input ) {
		var file = input.files && input.files[0];
		if ( ! file ) {
			return true;
		}

		var maxBytes = getMaxUploadBytes( input );
		if ( ! isFinite( maxBytes ) ) {
			return true;
		}

		if ( file.size <= maxBytes ) {
			return true;
		}

		/* File is too large — show error and clear the input. */
		var preview = input.parentNode.nextElementSibling;
		if ( preview && preview.classList && preview.classList.contains( 'crocina-file-preview' ) ) {
			var maxHuman = formatFileSize( maxBytes );
			preview.innerHTML = '<div class="crocina-file-error">' + escapeHTML( 'File is too large. Maximum size is ' + maxHuman + '.' ) + '</div>';
		}

		/* Clear the file input so the oversized file won't be submitted. */
		var dt = new DataTransfer();
		input.files = dt.files;

		return false;
	}

	function handleFilePreview( event ) {
		var input = event.target;

		/* Reject oversized files before showing any preview. */
		if ( ! validateFileSize( input ) ) {
			return;
		}

		var preview = input.parentNode.nextElementSibling;
		if ( ! preview || ! preview.classList || ! preview.classList.contains( 'crocina-file-preview' ) ) {
			return;
		}

		var file = input.files && input.files[0];
		if ( ! file ) {
			preview.innerHTML = '';
			return;
		}

		// Show file name and size for non-image files.
		if ( file.type.indexOf( 'image/' ) !== 0 ) {
			var size = formatFileSize( file.size );
			preview.innerHTML = '<div class="crocina-file-info"><span class="dashicons dashicons-media-default" aria-hidden="true"></span> <span class="crocina-file-name">' + escapeHTML( file.name ) + '</span> <span class="crocina-file-size">(' + size + ')</span></div>';
			return;
		}

		// Image file — show a thumbnail via FileReader.
		var reader = new FileReader();
		reader.addEventListener( 'load', function () {
			preview.innerHTML = '<img src="' + reader.result + '" class="crocina-file-thumb" alt="' + escapeHTML( file.name ) + '" />';
		} );
		reader.readAsDataURL( file );
	}

	function escapeHTML( str ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	function handleDragDrop( formElement ) {
		var dropZones = formElement.querySelectorAll( '.crocina-drop-zone' );

		dropZones.forEach( function ( zone ) {
			var input = zone.querySelector( 'input[type="file"]' );
			if ( ! input ) {
				return;
			}

			/* Highlight drop zone on dragover */
			zone.addEventListener( 'dragenter', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				zone.classList.add( 'is-dragover' );
			} );

			zone.addEventListener( 'dragover', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				zone.classList.add( 'is-dragover' );
			} );

			zone.addEventListener( 'dragleave', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				zone.classList.remove( 'is-dragover' );
			} );

			zone.addEventListener( 'drop', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				zone.classList.remove( 'is-dragover' );

				var files = event.dataTransfer && event.dataTransfer.files;
				if ( ! files || files.length === 0 ) {
					return;
				}

				/* Validate file size before assigning to input. */
				var droppedFile = files[0];
				var maxBytes = getMaxUploadBytes( input );
				if ( isFinite( maxBytes ) && droppedFile.size > maxBytes ) {
					var preview = input.parentNode.nextElementSibling;
					if ( preview && preview.classList && preview.classList.contains( 'crocina-file-preview' ) ) {
						preview.innerHTML = '<div class="crocina-file-error">' + escapeHTML( 'File is too large. Maximum size is ' + formatFileSize( maxBytes ) + '.' ) + '</div>';
					}
					return;
				}

				/* Assign the dropped file to the file input (works in modern browsers). */
				var dt = new DataTransfer();
				dt.items.add( droppedFile );
				input.files = dt.files;

				/* Trigger the existing preview handler. */
				var changeEvent = new Event( 'change', { bubbles: true } );
				input.dispatchEvent( changeEvent );
			} );
		} );

		/* Click on the drop zone opens the file picker. */
		dropZones.forEach( function ( zone ) {
			zone.addEventListener( 'click', function () {
				var fileInput = zone.querySelector( 'input[type="file"]' );
				if ( fileInput ) {
					fileInput.click();
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var status = getParameter( 'crocina_status' );
		var message = getParameter( 'crocina_form_message' );
		var formId = getParameter( 'crocina_form_id' );

		if ( status && message && formId ) {
			noticeForm( document.querySelector( '.crocina-form-' + formId ), status, message );
			return;
		}

		var forms = document.querySelectorAll( '.crocina-form' );
		forms.forEach( function ( formElement ) {
			var dataStatus = formElement.getAttribute( 'data-crocina-status' );
			var dataMessage = formElement.getAttribute( 'data-crocina-message' );
			if ( dataStatus && dataMessage ) {
				noticeForm( formElement, dataStatus, dataMessage );
			}
			var inner = formElement.querySelector( '.crocina-form__inner' );
			if ( inner ) {
				handleAjaxSubmit( inner, formElement );
				handleDragDrop( formElement );
			}
		} );

		// Image/file preview — delegated event covers dynamically added forms.
		document.addEventListener( 'change', function ( event ) {
			if ( event.target.matches( '.crocina-field input[type="file"]' ) ) {
				handleFilePreview( event );
			}
		} );
	} );
} )();
