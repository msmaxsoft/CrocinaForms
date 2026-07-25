( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.blockEditor || ! wp.components || ! wp.element || ! wp.i18n || ! wp.hooks ) {
		return;
	}

	var el       = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef    = wp.element.useRef;
	var __       = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var registerBlockStyle = wp.blocks.registerBlockStyle;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody  = wp.components.PanelBody;
var TabPanel      = wp.components.TabPanel;
var SelectControl = wp.components.SelectControl;
var TextControl  = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var RangeControl  = wp.components.RangeControl;
	var Placeholder   = wp.components.Placeholder;
	var Spinner       = wp.components.Spinner;
	var Dashicon      = wp.components.Dashicon;
	var ColorPicker   = wp.components.ColorPicker;
	var ColorIndicator = wp.components.ColorIndicator;
	var ServerSideRender = wp.serverSideRender;
	var apiFetch    = wp.apiFetch;

	/**
	 * Generate a primary-colour palette (50, 100, 200, 300, 400) from a hex base.
	 * Uses HSL manipulation so templates can reference lightweight shades for
	 * backgrounds, borders, focus rings, and active states without extra CSS.
	 *
	 * @param {string} hex Hex colour (e.g. "#0e64b7").
	 * @return {Object} Map of CSS custom-property name to value.
	 */
	function generatePrimaryPalette( hex ) {
		var h, s, l;
		hex = hex.replace( '#', '' );
		if ( hex.length !== 6 ) return {};

		var r = parseInt( hex.substr( 0, 2 ), 16 ) / 255;
		var g = parseInt( hex.substr( 2, 2 ), 16 ) / 255;
		var b = parseInt( hex.substr( 4, 2 ), 16 ) / 255;

		var mx = Math.max( r, g, b );
		var mn = Math.min( r, g, b );
		var delta = mx - mn;

		l = ( mx + mn ) / 2;

		if ( delta === 0 ) {
			h = 0;
			s = 0;
		} else {
			s = l > 0.5 ? delta / ( 2 - mx - mn ) : delta / ( mx + mn );
			if ( mx === r ) {
				h = 60 * ( ( ( g - b ) / delta ) % 6 );
			} else if ( mx === g ) {
				h = 60 * ( ( b - r ) / delta + 2 );
			} else {
				h = 60 * ( ( r - g ) / delta + 4 );
			}
		}

		if ( h < 0 ) h += 360;

		var satPct = Math.round( s * 100 );
		var shades = [ 50, 100, 200, 300, 400 ];
		var lightness = { '50': 0.92, '100': 0.84, '200': 0.65, '300': 0.45, '400': 0.25 };
		var result = {};

		for ( var i = 0; i < shades.length; i++ ) {
			var name = shades[ i ];
			var L = Math.max( 0.05, Math.min( 0.95, lightness[ name ] ) );
			result[ '--crocina-primary-' + name ] = 'hsl(' + Math.round( h ) + ', ' + satPct + '%, ' + Math.round( L * 100 ) + '%)';
		}

		return result;
	}

	/* Field type label map */
	var fieldTypeLabels = {
		'text':     __( 'Text', 'crocina-forms' ),
		'email':    __( 'Email', 'crocina-forms' ),
		'textarea': __( 'Textarea', 'crocina-forms' ),
		'number':   __( 'Number', 'crocina-forms' ),
		'tel':      __( 'Phone', 'crocina-forms' ),
		'url':      __( 'URL', 'crocina-forms' ),
		'select':   __( 'Select', 'crocina-forms' ),
		'checkbox': __( 'Checkbox', 'crocina-forms' ),
		'radio':    __( 'Radio', 'crocina-forms' ),
		'file':     __( 'File', 'crocina-forms' ),
	};

	var fieldTypeIcons = {
		'text':     'editor-textcolor',
		'email':    'email',
		'textarea': 'editor-paragraph',
		'number':   'calculator',
		'tel':      'phone',
		'url':      'admin-links',
		'select':   'menu',
		'checkbox': 'yes',
		'radio':    'marker',
		'file':     'media-document',
	};

	var blockIcon = el( 'svg', { width: 20, height: 20, viewBox: '0 0 20 20', fill: 'none' },
		el( 'path', {
			d: 'M3 4h14v2H3V4zm0 4h14v2H3V8zm0 4h10v2H3v-2zm0 4h14v2H3v-2z',
			fill: 'currentColor'
		} )
	);

	/* ------------------------------------------------------------------ */
	/*  Template preset visuals                                            */
	/* ------------------------------------------------------------------ */
	var templateOptions = [
		{ value: 'default',  label: __( 'Default', 'crocina-forms' ) },
		{ value: 'card',     label: __( 'Card', 'crocina-forms' ) },
		{ value: 'minimal',  label: __( 'Minimal', 'crocina-forms' ) },
		{ value: 'bordered', label: __( 'Bordered', 'crocina-forms' ) },
		{ value: 'shadow',   label: __( 'Shadow', 'crocina-forms' ) },
	];
	var templatePreviewIcons = {
		default:  'editor-alignleft',
		card:     'welcome-widgets-menus',
		minimal:  'editor-aligncenter',
		bordered: 'editor-table',
		shadow:   'cloud',
	};

	/* ------------------------------------------------------------------ */
	/*  Viewport options for responsive preview                            */
	/* ------------------------------------------------------------------ */
	var viewportOptions = [
		{ value: 'full',   label: __( 'Desktop', 'crocina-forms' ), icon: 'desktop',  width: 0 },
		{ value: 'auto',   label: __( 'Auto-fit', 'crocina-forms' ),  icon: 'screenoptions', width: 'auto' },
		{ value: 'tablet', label: __( 'Tablet', 'crocina-forms' ),  icon: 'tablet',   width: 768 },
		{ value: 'mobile', label: __( 'Mobile', 'crocina-forms' ),  icon: 'smartphone', width: 375 },
	];

	var viewportLabels = { full: 'Desktop', auto: 'Auto-fit', tablet: 'Tablet', mobile: 'Mobile' };
	var viewportWidths = { full: 0, auto: 'auto', tablet: 768, mobile: 375 };
	var viewportIcons  = { full: 'desktop', auto: 'screenoptions', tablet: 'tablet', mobile: 'smartphone' };

	/* ------------------------------------------------------------------ */
	/*  Theme options                                                      */
	/* ------------------------------------------------------------------ */
	var themeOptions = [
		{ value: 'modern',  label: __( 'Modern', 'crocina-forms' ),  icon: 'lightbulb' },
		{ value: 'classic', label: __( 'Classic', 'crocina-forms' ), icon: 'backup' },
		{ value: 'minimal', label: __( 'Minimal', 'crocina-forms' ), icon: 'editor-removeformatting' },
	];

	var themePreviewIcons = {
		modern:  'lightbulb',
		classic: 'backup',
		minimal: 'editor-removeformatting',
	};

	registerBlockType( 'crocina-forms/form-selector', {
		title:       __( 'Crocina Form', 'crocina-forms' ),
		description: __( 'Insert a Crocina contact form into your content with custom display options.', 'crocina-forms' ),
		icon:        blockIcon,
		category:    'widgets',
		keywords:    [ 'crocina', 'form', 'contact', 'cf7' ],
		example:     {
			attributes: {
				formId: 0,
			},
		},
		supports: {
			html:        false,
			align:       [ 'wide', 'full', 'center' ],
			reusable:    false,
			customClassName: true,
		},
		attributes: {
			formId: {
				type:    'number',
				default: 0,
			},
			previewHeight: {
				type:    'number',
				default: 440,
			},
			showHeader: {
				type:    'boolean',
				default: true,
			},
			showFooter: {
				type:    'boolean',
				default: true,
			},
			template: {
				type:    'string',
				default: 'default',
			},
			theme: {
				type:    'string',
				default: 'modern',
			},
			viewport: {
				type:    'string',
				default: 'full',
			},
			primaryColor: {
				type:    'string',
				default: '#0e64b7',
			},
			usePrimaryColor: {
				type:    'boolean',
				default: false,
			},
		},

		edit: function ( props ) {
			var formId        = props.attributes.formId;
			var previewHeight = props.attributes.previewHeight;
			var showHeader    = props.attributes.showHeader;
			var showFooter    = props.attributes.showFooter;
			var template      = props.attributes.template;
			var theme         = props.attributes.theme;
			var viewport      = props.attributes.viewport;
			var primaryColor  = props.attributes.primaryColor;
			var usePrimaryColor = props.attributes.usePrimaryColor;
			var className    = props.attributes.className || '';
			var setAttributes = props.setAttributes;
			var forms        = [];
			var isLoading    = true;
			var loadError    = null;

			/* Form list state */
			var state = useState( { forms: [], loading: true, error: null } );
			var formsState = state[0];
			var setFormsState = state[1];

			useEffect( function () {
				apiFetch( { path: '/crocina/v1/forms' } )
					.then( function ( response ) {
						if ( response && response.forms ) {
							setFormsState( { forms: response.forms, loading: false, error: null } );
						} else {
							setFormsState( { forms: [], loading: false, error: __( 'Invalid response.', 'crocina-forms' ) } );
						}
					} )
					.catch( function () {
						var local = window.CrocinaBlockData && window.CrocinaBlockData.forms
							? window.CrocinaBlockData.forms
							: [];
						setFormsState( { forms: local, loading: false, error: local.length ? null : __( 'No forms available.', 'crocina-forms' ) } );
					} );
			}, [] );

			forms     = formsState.forms;
			isLoading = formsState.loading;
			loadError = formsState.error;

			/* Fetch the form's default template from the single-form endpoint
			   whenever formId changes.  Only applies the result when the
			   template attribute is still the block default ('default'), so
			   any explicit user choice is never overwritten on page reload.  */
			useEffect( function () {
				if ( ! formId || template !== 'default' ) {
					return;
				}
				var path = '/crocina/v1/forms/' + formId;
				apiFetch( { path: path } )
					.then( function ( response ) {
						if ( response && response.design && response.design.template ) {
							var tpl = response.design.template;
							var allowed = [ 'default', 'card', 'minimal', 'bordered', 'shadow' ];
							if ( allowed.indexOf( tpl ) !== -1 && tpl !== template ) {
								setAttributes( { template: tpl } );
							}
						}
					} )
					.catch( function () {
						/* Silently fall back to the block's default. */
					} );
			}, [ formId, template ] );

			/* Form fields state */
			var fieldsState = useState( { fields: null, loading: false, error: null } );
			var formFieldsState = fieldsState[0];
			var setFormFieldsState = fieldsState[1];

			useEffect( function () {
				if ( ! formId ) {
					setFormFieldsState( { fields: null, loading: false, error: null } );
					return;
				}
				setFormFieldsState( { fields: null, loading: true, error: null } );
				var path = '/crocina/v1/forms/' + formId + '/fields';
				apiFetch( { path: path } )
					.then( function ( response ) {
						if ( response && response.fields ) {
							setFormFieldsState( { fields: response.fields, loading: false, error: null } );
						} else {
							setFormFieldsState( { fields: null, loading: false, error: __( 'No fields found.', 'crocina-forms' ) } );
						}
					} )
					.catch( function ( err ) {
						setFormFieldsState( { fields: null, loading: false, error: err.message || __( 'Failed to load fields.', 'crocina-forms' ) } );
					} );
			}, [ formId ] );

			/* Forward sync: WordPress Block Style → template attribute */
			useEffect( function () {
				var match = className.match( /is-style-(\S+)/ );
				if ( match && match[1] !== 'default' && match[1] !== template ) {
					var candidates = [ 'card', 'minimal', 'bordered', 'shadow' ];
					if ( candidates.indexOf( match[1] ) !== -1 ) {
						setAttributes( { template: match[1] } );
					}
				}
			}, [ className ] );

			/* Reverse sync: template attribute → className (is-style-{name})
			   Keeps the wrapper’s is-style-* classes in sync when the user
			   changes the template via the InspectorControls grid, preventing
			   stale classes (e.g. is-style-card lingering after switching back
			   to Default) from applying the wrong CSS rules.                */
			useEffect( function () {
				var current = className || '';
				var cleaned = current.replace( /is-style-\S+/g, '' ).replace( /\s{2,}/g, ' ' ).trim();

				if ( template === 'default' ) {
					/* Explicitly add is-style-default so WordPress’s built-in
					   style manager does not fight us and old is-style-*
					   classes do not stay around. */
					cleaned += ( cleaned ? ' ' : '' ) + 'is-style-default';
				} else {
					cleaned += ( cleaned ? ' ' : '' ) + 'is-style-' + template;
				}

				if ( cleaned !== current ) {
					setAttributes( { className: cleaned } );
				}
			}, [ template ] );

			var options = [
				{ value: 0, label: __( '\u2014 Select a form \u2014', 'crocina-forms' ) },
			];
			for ( var i = 0; i < forms.length; i++ ) {
				options.push( {
					value: forms[ i ].id,
					label: forms[ i ].title,
				} );
			}

			var selectedLabel = '';
			for ( var j = 0; j < forms.length; j++ ) {
				if ( forms[ j ].id === formId ) {
					selectedLabel = forms[ j ].title;
					break;
				}
			}

			/* -------------------------------------------------------------- */
			/*  Field groups state                                            */
			/* -------------------------------------------------------------- */

			/* groups: array of { name: string, fields: array } representing
			   the logical grouping of fields. Fields with no group (or group '')
			   are placed in a virtual "Uncategorized" group at the end.       */
			var groupsState = useState( [] );
			var groups = groupsState[0];
			var setGroups = groupsState[1];

			/* Track which group headers are collapsed/open. */
			var collapsedState = useState( {} );
			var collapsedGroups = collapsedState[0];
			var setCollapsedGroups = collapsedState[1];

			/* Empty groups — names of groups that have been created but have
			   no fields assigned yet.  Merged into `groups` during render.   */
			var emptyGroupsState = useState( [] );
			var emptyGroups = emptyGroupsState[0];
			var setEmptyGroups = emptyGroupsState[1];

			/* Build groups from orderedFields whenever they change. */
			var buildGroups = function ( fields ) {
				if ( ! fields || ! fields.length ) {
					setGroups( [] );
					return;
				}
				var groupMap = {};
				var order = [];
				for ( var gi = 0; gi < fields.length; gi++ ) {
					var f = fields[ gi ];
					var gName = f.group || '';
					if ( ! groupMap[ gName ] ) {
						groupMap[ gName ] = [];
						order.push( gName );
					}
					groupMap[ gName ].push( f );
				}
				var result = [];
				for ( var oj = 0; oj < order.length; oj++ ) {
					if ( order[ oj ] !== '' ) {
						result.push( { name: order[ oj ], fields: groupMap[ order[ oj ] ] } );
					}
				}
				/* Put uncategorized at the end. */
				if ( groupMap[''] ) {
					result.push( { name: '', fields: groupMap[''] } );
				}
				setGroups( result );
			};

			/* Rebuild groups whenever orderedFields change. */
			useEffect( function () {
				buildGroups( orderedFields );
			}, [ orderedFields ] );

			/* Merge empty-group names into the derived groups array.
			   Empty groups (no fields yet) are appended after named groups
			   but before Uncategorized. */
			var mergedGroups = ( function () {
				var result = groups.slice();
				var existingNames = {};
				for ( var mi = 0; mi < result.length; mi++ ) {
					existingNames[ result[ mi ].name ] = true;
				}
				for ( var mj = 0; mj < emptyGroups.length; mj++ ) {
					if ( ! existingNames[ emptyGroups[ mj ] ] ) {
						result.push( { name: emptyGroups[ mj ], fields: [] } );
					}
				}
				return result;
			} )();

			/* Assign a field to a different group. */
			var assignFieldToGroup = function ( fieldIdx, newGroup ) {
				pushHistory( orderedFields );
				var updated = orderedFields.slice();
				if ( updated[ fieldIdx ] ) {
					updated[ fieldIdx ] = Object.assign( {}, updated[ fieldIdx ], { group: newGroup || '' } );
				}
				setOrderedFields( updated );
				persistFieldOrder( updated );
			};

			/* Create a new group by name. Just store the name in emptyGroups.
			   The group appears immediately and fields can be assigned to it.
			   No dummy field is created — the group is purely a UI concept
			   until at least one field has its `group` property set.           */
			var handleAddGroup = function ( name ) {
				if ( ! name || ! name.trim() ) {
					return;
				}
				var trimmed = name.trim();
				/* Check in both derived groups and empty groups. */
				for ( var ag = 0; ag < mergedGroups.length; ag++ ) {
					if ( mergedGroups[ ag ].name === trimmed ) {
						return;
					}
				}
				setEmptyGroups( function ( prev ) {
					return prev.concat( [ trimmed ] );
				} );
			};

			/* Rename a group. */
			var handleRenameGroup = function ( oldName, newName ) {
				if ( ! newName || ! newName.trim() || oldName === newName.trim() ) {
					return;
				}
				var trimmed = newName.trim();
				var updated = orderedFields.map( function ( f ) {
					if ( f.group === oldName ) {
						return Object.assign( {}, f, { group: trimmed } );
					}
					return f;
				} );
				pushHistory( orderedFields );
				setOrderedFields( updated );
				persistFieldOrder( updated );
			};

			/* Delete a group — move its fields to uncategorized.
			   If the group has no fields (empty group), just remove
			   it from the empty groups list.                           */
			var handleDeleteGroup = function ( name ) {
				/* Check if it's an empty group. */
				var hasFields = false;
				for ( var dg = 0; dg < orderedFields.length; dg++ ) {
					if ( orderedFields[ dg ].group === name ) {
						hasFields = true;
						break;
					}
				}
				if ( ! hasFields ) {
					/* Just remove from empty groups. */
					setEmptyGroups( function ( prev ) {
						return prev.filter( function ( g ) { return g !== name; } );
					} );
					return;
				}
				var updated = orderedFields.map( function ( f ) {
					if ( f.group === name ) {
						return Object.assign( {}, f, { group: '' } );
					}
					return f;
				} );
				pushHistory( orderedFields );
				setOrderedFields( updated );
				persistFieldOrder( updated );
			};

			/* -------------------------------------------------------------- */
			/*  Group-level drag-and-drop                                     */
			/* -------------------------------------------------------------- */
			var groupDragState = useState( null ); // { fromGroupIdx: int }
			var groupDragInfo = groupDragState[0];
			var setGroupDragInfo = groupDragState[1];

			var handleGroupDragStart = function ( gIdx, event ) {
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData( 'text/plain', String( gIdx ) );
				setGroupDragInfo( { fromGroupIdx: gIdx } );
			};

			var handleGroupDragOver = function ( event ) {
				event.preventDefault();
				event.dataTransfer.dropEffect = 'move';
			};

			var handleGroupDrop = function ( toGIdx, event ) {
				event.preventDefault();
				var fromGIdx = groupDragInfo ? groupDragInfo.fromGroupIdx : parseInt( event.dataTransfer.getData( 'text/plain' ), 10 );
				setGroupDragInfo( null );

				if ( fromGIdx === toGIdx || isNaN( fromGIdx ) || null === fromGIdx ) {
					return;
				}

				/* Extract fields of the dragged group and the target group. */
				var fromGroup = mergedGroups[ fromGIdx ];
				var toGroup   = mergedGroups[ toGIdx ];
				if ( ! fromGroup || ! toGroup ) {
					return;
				}

				/* Rebuild orderedFields by moving all fields of fromGroup
				   to the position before the first field of toGroup.
				   This effectively moves the entire group as a block.      */
				var allFields = orderedFields.slice();

				/* Find the global indices of the dragged group's fields. */
				var fromIndices = [];
				var toIndices   = [];
				for ( var hi = 0; hi < allFields.length; hi++ ) {
					if ( allFields[ hi ].group === fromGroup.name ) {
						fromIndices.push( hi );
					} else if ( allFields[ hi ].group === toGroup.name ) {
						toIndices.push( hi );
					}
				}

				if ( ! fromIndices.length ) {
					return;
				}

				/* Extract the dragged fields from the array (in reverse
				   order to preserve indices during splice).             */
				var extracted = [];
				for ( var ri = fromIndices.length - 1; ri >= 0; ri-- ) {
					extracted.unshift( allFields.splice( fromIndices[ ri ], 1 )[0] );
				}

				/* Determine insert position: before the first field of
				   the target group.  Adjust indices since we removed
				   fields above.  If the dragged group was before the
				   target, the target indices shift by -fromIndices.length. */
				var insertAt = toIndices.length > 0 ? toIndices[0] : allFields.length;
				if ( fromGIdx < toGIdx ) {
					insertAt -= fromIndices.length;
				}
				insertAt = Math.max( 0, Math.min( insertAt, allFields.length ) );

				/* Splice the extracted fields at the insert position. */
				allFields.splice.apply( allFields, [ insertAt, 0 ].concat( extracted ) );

				pushHistory( orderedFields );
				setOrderedFields( allFields );
				persistFieldOrder( allFields );
			};

			var handleGroupDragEnd = function () {
				setGroupDragInfo( null );
			};

			/* Toggle group collapse. */
			var toggleGroupCollapse = function ( name ) {
				setCollapsedGroups( function ( prev ) {
					var next = Object.assign( {}, prev );
					if ( next[ name ] ) {
						delete next[ name ];
					} else {
						next[ name ] = true;
					}
					return next;
				} );
			};

			/* -------------------------------------------------------------- */
			/*  Drag-and-drop state for field reordering                      */
			/* -------------------------------------------------------------- */
			var dragDropState = useState( null ); // { dragIndex: null, overIndex: null, fromGroup: null }
			var dragInfo = dragDropState[0];
			var setDragInfo = dragDropState[1];

			/* Keep a local copy of fields that users can reorder via drag. */
			var orderedState = useState( [] );
			var orderedFields = orderedState[0];
			var setOrderedFields = orderedState[1];

			/* -------------------------------------------------------------- */
			/*  Undo/redo history stack for field reordering                  */
			/* -------------------------------------------------------------- */

			/* historyStack: array of field-order snapshots (deep copies).
			   historyIndex: current position in the stack (-1 = base).
			   The base snapshot (initial load) is index 0.               */
			var historyState = useState( { stack: [], index: -1 } );
			var historyInfo = historyState[0];
			var setHistoryInfo = historyState[1];

			var canUndo = historyInfo.index >= 0;
			var canRedo = historyInfo.index < historyInfo.stack.length - 1;

			/* Push a snapshot of the current field order onto the history.
			   Truncates any future entries when a new action happens after
			   an undone state (standard undo/redo behaviour).             */
			var pushHistory = function ( snapshot ) {
				setHistoryInfo( function ( prev ) {
					var newStack = prev.stack.slice( 0, prev.index + 1 );
					newStack.push( JSON.parse( JSON.stringify( snapshot ) ) );
					/* Keep at most 50 entries to avoid excessive memory. */
					if ( newStack.length > 50 ) {
						newStack.shift();
					}
					return { stack: newStack, index: newStack.length - 1 };
				} );
			};

			/* Reset history when a new form is selected. */
			useEffect( function () {
				setHistoryInfo( { stack: [], index: -1 } );
			}, [ formId ] );

			/* Seed the base snapshot when fields first load. */
			useEffect( function () {
				if ( formFieldsState.fields && formFieldsState.fields.length ) {
					setOrderedFields( formFieldsState.fields.slice() );
				}
			}, [ formFieldsState.fields ] );

			var handleDragStart = function ( idx, event ) {
				setDragInfo( { dragIndex: idx, overIndex: idx } );
				event.dataTransfer.effectAllowed = 'move';
				event.dataTransfer.setData( 'text/plain', String( idx ) );
			};

			var handleDragOver = function ( idx, event ) {
				event.preventDefault();
				event.dataTransfer.dropEffect = 'move';
				if ( dragInfo && dragInfo.dragIndex !== idx ) {
					setDragInfo( { dragIndex: dragInfo.dragIndex, overIndex: idx } );
				}
			};

			var handleDragLeave = function () {
				if ( dragInfo && null !== dragInfo.overIndex ) {
					setDragInfo( { dragIndex: dragInfo.dragIndex, overIndex: null } );
				}
			};

			/* -------------------------------------------------------------- */
			/*  Persist field order to the server (extracted for reuse        */
			/*  by drag-drop, undo, and redo).                               */
			/* -------------------------------------------------------------- */
			var persistFieldOrder = function ( fieldsToSave ) {
				if ( ! formId || ! fieldsToSave || ! fieldsToSave.length ) {
					return;
				}
				apiFetch( {
					path: '/crocina/v1/forms/' + formId + '/reorder-fields',
					method: 'POST',
					data: { fields: fieldsToSave },
				} ).then( function ( response ) {
					if ( response && response.fields ) {
						setOrderedFields( response.fields );
						setFormFieldsState( {
							fields: response.fields,
							loading: false,
							error: null,
						} );
					}
				} ).catch( function () {
					/* Silently fail — the local order still applies visually
					   and will be re-sent on the next action. */
				} );
			};

			var handleDrop = function ( idx, event ) {
				event.preventDefault();
				var fromIdx = dragInfo ? dragInfo.dragIndex : parseInt( event.dataTransfer.getData( 'text/plain' ), 10 );
				if ( fromIdx === idx || isNaN( fromIdx ) || null === fromIdx ) {
					setDragInfo( null );
					return;
				}
				/* Push a snapshot of the CURRENT order before changing it,
				   so undo can restore it.                                   */
				pushHistory( orderedFields );

				var newOrder = orderedFields.slice();
				var moved = newOrder.splice( fromIdx, 1 )[0];
				newOrder.splice( idx, 0, moved );
				setOrderedFields( newOrder );
				setDragInfo( null );

				persistFieldOrder( newOrder );
			};

			/* Undo: restore the previous snapshot from history. */
			var handleUndo = function () {
				setHistoryInfo( function ( prev ) {
					if ( prev.index < 0 ) {
						return prev;
					}
					var snapshot = prev.stack[ prev.index ];
					var newIndex = prev.index - 1;
					setOrderedFields( snapshot );
					persistFieldOrder( snapshot );
					return { stack: prev.stack, index: newIndex };
				} );
			};

			/* Redo: restore the next snapshot from history. */
			var handleRedo = function () {
				setHistoryInfo( function ( prev ) {
					if ( prev.index >= prev.stack.length - 1 ) {
						return prev;
					}
					var newIndex = prev.index + 1;
					var snapshot = prev.stack[ newIndex ];
					setOrderedFields( snapshot );
					persistFieldOrder( snapshot );
					return { stack: prev.stack, index: newIndex };
				} );
			};

			var handleDragEnd = function () {
				setDragInfo( null );
			};

			/* Expanded field detail state */
			var expandedState = useState( null );
			var expandedField = expandedState[0];
			var setExpandedField = expandedState[1];

			/* -------------------------------------------------------------- */
			/*  Keyboard shortcut: Ctrl+Z (undo), Ctrl+Shift+Z / Ctrl+Y      */
			/*  (redo).  Uses useRef for stable callback references.         */
			/* -------------------------------------------------------------- */
			var undoRef = useRef( handleUndo );
			var redoRef = useRef( handleRedo );
			undoRef.current = handleUndo;
			redoRef.current = handleRedo;

			useEffect( function () {
				var onKeyDown = function ( event ) {
					var isCtrl = event.ctrlKey || event.metaKey;
					if ( ! isCtrl ) {
						return;
					}
					if ( event.key === 'z' && ! event.shiftKey ) {
						event.preventDefault();
						undoRef.current();
					} else if ( ( event.key === 'z' && event.shiftKey ) || event.key === 'y' ) {
						event.preventDefault();
						redoRef.current();
					}
				};
				document.addEventListener( 'keydown', onKeyDown );
				return function () {
					document.removeEventListener( 'keydown', onKeyDown );
				};
			}, [] );

			var handleFieldToggle = function ( idx ) {
				setExpandedField( expandedField === idx ? null : idx );
			};

			/* -------------------------------------------------------------- */
			/*  Build fields panel content                                     */
			/* -------------------------------------------------------------- */
			var fieldsContent = null;

			if ( formId && formFieldsState.loading ) {
				fieldsContent = el( 'div', { className: 'crocina-fields-loading' },
					el( Spinner, null ),
					el( 'span', null, __( 'Loading fields\u2026', 'crocina-forms' ) )
				);
			} else if ( formId && formFieldsState.error ) {
				fieldsContent = el( 'div', { className: 'crocina-fields-error' },
					el( Dashicon, { icon: 'warning' } ),
					el( 'span', null, formFieldsState.error )
				);
			} else if ( formId && orderedFields && orderedFields.length ) {
				fieldsContent = el(
					'div',
					{ className: 'crocina-fields-list' },
					el(
						'div',
						{ className: 'crocina-fields-count' },
						el( 'span', { className: 'crocina-fields-count-label' },
							__( 'Fields', 'crocina-forms' ) + ': ' + orderedFields.length
						),
						el( 'span', { className: 'crocina-fields-undo-btns' },
							el( 'button', {
								className: 'crocina-undo-btn' + ( canUndo ? '' : ' is-disabled' ),
								title: __( 'Undo (Ctrl+Z)', 'crocina-forms' ),
								disabled: ! canUndo,
								onClick: handleUndo,
								type: 'button',
							},
								el( Dashicon, { icon: 'undo' } )
							),
							el( 'button', {
								className: 'crocina-redo-btn' + ( canRedo ? '' : ' is-disabled' ),
								title: __( 'Redo (Ctrl+Shift+Z)', 'crocina-forms' ),
								disabled: ! canRedo,
								onClick: handleRedo,
								type: 'button',
							},
								el( Dashicon, { icon: 'redo' } )
							)
						)
					),
					el(
						'div',
						{ className: 'crocina-fields-drag-hint', 'aria-hidden': 'true' },
						el( Dashicon, { icon: 'screenoptions' } ),
						__( 'Drag to reorder', 'crocina-forms' )
					),
					/* Add Group button */
					el(
						'div',
						{ className: 'crocina-add-group-area' },
						el( 'button', {
							className: 'crocina-add-group-btn',
							type: 'button',
							onClick: function () {
								var name = prompt( __( 'Enter group name:', 'crocina-forms' ) );
								if ( name && name.trim() ) {
									handleAddGroup( name );
								}
							},
						},
							el( Dashicon, { icon: 'plus' } ),
							__( 'Add Group', 'crocina-forms' )
						)
					),
					/* Render groups */
					mergedGroups.map( function ( group, gIdx ) {
						var isUncategorized = group.name === '';
						var groupName = isUncategorized ? __( 'Uncategorized', 'crocina-forms' ) : group.name;
						var isCollapsed = collapsedGroups[ group.name ];
						var isDraggedGroup = groupDragInfo && groupDragInfo.fromGroupIdx === gIdx;
						var grpClass = 'crocina-field-group crocina-field-group-draggable';
						if ( isUncategorized ) {
							grpClass += ' crocina-field-group-uncategorized';
						}
						if ( isCollapsed ) {
							grpClass += ' is-collapsed';
						}
						if ( isDraggedGroup ) {
							grpClass += ' is-group-dragging';
						}

						return el(
							'div',
							{
								className: grpClass,
								key: 'group-' + gIdx,
								onDragOver: handleGroupDragOver,
						},
						/* Group header (draggable) */
						el(
							'div',
							{
								className: 'crocina-group-header',
								draggable: ! isUncategorized,
								onDragStart: function ( event ) { handleGroupDragStart( gIdx, event ); },
								onDrop:      function ( event ) { handleGroupDrop( gIdx, event ); },
								onDragEnd:   handleGroupDragEnd,
							},
								/* Collapse toggle */
								el( 'button', {
									className: 'crocina-group-collapse-btn',
									type: 'button',
									onClick: function () { toggleGroupCollapse( group.name ); },
									title: isCollapsed ? __( 'Expand', 'crocina-forms' ) : __( 'Collapse', 'crocina-forms' ),
								},
									el( Dashicon, {
										icon: isCollapsed ? 'arrow-right' : 'arrow-down',
									} )
								),
								/* Group name — inline editable for named groups */
								isUncategorized
									? el( 'span', { className: 'crocina-group-name' }, groupName )
									: el( TextControl, {
										value:    group.name,
										onChange: function ( newName ) {
											if ( newName !== group.name ) {
												handleRenameGroup( group.name, newName );
											}
										},
										className: 'crocina-group-name-input',
										placeholder: __( 'Group name', 'crocina-forms' ),
									} ),
								/* Field count */
								el( 'span', { className: 'crocina-group-count' },
									group.fields.length
								),
								/* Delete group button (only for named groups) */
								isUncategorized
									? null
									: el( 'button', {
										className: 'crocina-group-delete-btn',
										type: 'button',
										title: __( 'Delete group (fields move to Uncategorized)', 'crocina-forms' ),
										onClick: function () {
											if ( confirm( __( 'Delete this group? Fields will move to Uncategorized.', 'crocina-forms' ) ) ) {
												handleDeleteGroup( group.name );
											}
										},
									},
										el( Dashicon, { icon: 'trash' } )
									)
							),
							/* Group fields */
							isCollapsed
								? null
								: el(
									'div',
									{ className: 'crocina-group-fields' },
									group.fields.map( function ( field, fIdx ) {
										/* Find global index for drag-and-drop and expand state. */
										var globalIdx = orderedFields.indexOf( field );
										var typeLabel = fieldTypeLabels[ field.type ] || field.type;
										var typeIcon  = fieldTypeIcons[ field.type ] || 'admin-generic';
										var isExpanded = expandedField === globalIdx;
										var rowClass  = 'crocina-field-row crocina-field-row-draggable';
										if ( dragInfo && dragInfo.dragIndex === globalIdx ) {
											rowClass += ' is-dragging';
										}
										if ( dragInfo && dragInfo.overIndex === globalIdx && dragInfo.dragIndex !== globalIdx ) {
											rowClass += ' is-drag-over';
										}
										if ( isExpanded ) {
											rowClass += ' is-expanded';
										}

										/* Build expandable details section */
										var placeholder  = field.placeholder || '';
										var helperText   = field.helper_text || '';
										var detailsEl    = null;

										if ( isExpanded ) {
											detailsEl = el(
												'div',
												{ className: 'crocina-field-details' },
												el(
													'div',
													{ className: 'crocina-field-details-item' },
													el( 'span', { className: 'crocina-field-details-label' },
														__( 'Placeholder', 'crocina-forms' )
													),
													el( 'span', { className: 'crocina-field-details-value' },
														placeholder ? placeholder : el( 'span', { className: 'crocina-field-details-empty' }, '\u2014' )
													)
												),
												el(
													'div',
													{ className: 'crocina-field-details-item' },
													el( 'span', { className: 'crocina-field-details-label' },
														__( 'Helper text', 'crocina-forms' )
													),
													el( 'span', { className: 'crocina-field-details-value' },
														helperText ? helperText : el( 'span', { className: 'crocina-field-details-empty' }, '\u2014' )
													)
												),
												/* Show file upload hint when field type is file */
												field.type === 'file'
													? el(
														'div',
														{ className: 'crocina-field-details-item' },
														el( 'span', { className: 'crocina-field-details-label' },
															__( 'Options', 'crocina-forms' )
														),
														el( 'span', { className: 'crocina-field-details-note' },
															__( 'Upload field', 'crocina-forms' )
														)
													)
													: null,
												/* Show options for select / checkbox / radio */
												( field.type === 'select' || field.type === 'checkbox' || field.type === 'radio' )
													? el(
														'div',
														{ className: 'crocina-field-details-item' },
														el( 'span', { className: 'crocina-field-details-label' },
															__( 'Options', 'crocina-forms' )
														),
														el( 'span', { className: 'crocina-field-details-options' },
															field.options ? field.options : el( 'span', { className: 'crocina-field-details-empty' }, '\u2014' )
														)
													)
													: null,
												/* Group selector */
												el(
													'div',
													{ className: 'crocina-field-details-item' },
													el( 'span', { className: 'crocina-field-details-label' },
														__( 'Group', 'crocina-forms' )
													),
													el( SelectControl, {
														value:    field.group || '',
														options:  ( function () {
															var opts = [ { value: '', label: __( 'No group', 'crocina-forms' ) } ];
															for ( var gg = 0; gg < groups.length; gg++ ) {
																if ( groups[ gg ].name ) {
																	opts.push( { value: groups[ gg ].name, label: groups[ gg ].name } );
																}
															}
															return opts;
														} )(),
														onChange: function ( newGroup ) {
															assignFieldToGroup( globalIdx, newGroup );
														},
														__nextHasNoMarginBottom: true,
													} )
												)
											);
										}

										return el(
											'div',
											{
												className: rowClass,
												key: 'f-' + globalIdx,
												draggable: true,
												onDragStart: function ( event ) { handleDragStart( globalIdx, event ); },
												onDragOver:  function ( event ) { handleDragOver( globalIdx, event ); },
												onDrop:      function ( event ) { handleDrop( globalIdx, event ); },
												onDragEnd:   handleDragEnd,
												onDragLeave: handleDragLeave,
											},
											el( 'div', { className: 'crocina-field-row-handle' },
												el( Dashicon, { icon: 'menu' } )
											),
											el( 'div', { className: 'crocina-field-icon' },
												el( Dashicon, { icon: typeIcon } )
											),
											el( 'div', {
													className: 'crocina-field-info crocina-field-info-clickable',
													onClick: function () { handleFieldToggle( globalIdx ); },
													role: 'button',
													tabIndex: 0,
													onKeyDown: function ( event ) {
														if ( event.key === 'Enter' || event.key === ' ' ) {
															event.preventDefault();
															handleFieldToggle( globalIdx );
														}
													},
													'aria-expanded': isExpanded,
												},
												el( 'div', { className: 'crocina-field-label' },
													field.label || field.slug,
													field.required
														? el( 'span', { className: 'crocina-field-required' }, '*' )
														: null
												),
												el( 'div', { className: 'crocina-field-meta' },
													el( 'span', { className: 'crocina-field-type-badge' }, typeLabel )
												)
											),
											el( 'div', {
													className: 'crocina-field-expand-icon',
													onClick: function () { handleFieldToggle( globalIdx ); },
													role: 'button',
													tabIndex: 0,
													onKeyDown: function ( event ) {
														if ( event.key === 'Enter' || event.key === ' ' ) {
															event.preventDefault();
															handleFieldToggle( globalIdx );
														}
													},
												},
												el( Dashicon, {
													icon: isExpanded ? 'arrow-up-alt2' : 'arrow-down-alt2',
												} )
											),
											detailsEl
										);
									} )
								)
						);
					} )
				);
			} else if ( formId ) {
				fieldsContent = el( 'div', { className: 'crocina-fields-empty' },
					el( 'span', null, __( 'No fields configured for this form.', 'crocina-forms' ) )
				);
			} else {
				fieldsContent = el( 'div', { className: 'crocina-fields-empty' },
					el( 'span', null, __( 'Select a form to see its fields.', 'crocina-forms' ) )
				);
			}

			/* -------------------------------------------------------------- */
			/*  Inspector sidebar \u2014 nested panels                             */
			/* -------------------------------------------------------------- */
			var inspector = el(
				InspectorControls,
				{ key: 'inspector' },

				/* Form selection */
				el(
					PanelBody,
					{
						title:    __( 'Form Settings', 'crocina-forms' ),
						initialOpen: true,
					},
					el( SelectControl, {
						label:    __( 'Choose a form', 'crocina-forms' ),
						value:    isLoading ? 0 : formId,
						options:  isLoading ? [ { value: 0, label: __( 'Loading\u2026', 'crocina-forms' ) } ] : options,
						disabled: isLoading,
						onChange: function ( value ) {
							setAttributes( {
								formId: parseInt( value, 10 ),
								template: 'default',
							} );
						},
						__nextHasNoMarginBottom: true,
					} )
				),

				/* Form Fields panel */
				el(
					PanelBody,
					{
						title:    __( 'Form Fields', 'crocina-forms' ),
						initialOpen: true,
						className: 'crocina-fields-panel',
					},
					fieldsContent
				),

				/* Style — template, theme, and primary color grouped together.
				   Uses a TabPanel so the user can switch between Template and
				   Theme grids, saving sidebar space while keeping both fully
				   interactive with their own visual grids and color swatches. */
				el(
					PanelBody,
					{
						title:    __( 'Style', 'crocina-forms' ),
						initialOpen: true,
					},
					/* Primary color row with live swatch (always visible) */
					el(
						'div',
						{ className: 'crocina-color-field crocina-style-primary' },
						el( 'label', { className: 'crocina-color-label' },
							el( ColorIndicator, { colorValue: primaryColor } ),
							__( 'Primary color', 'crocina-forms' )
						),
						el( ColorPicker, {
							color: primaryColor,
							onChange: function ( value ) {
								var newColor = value && typeof value === 'object' ? ( value.hex || value ) : value;
								setAttributes( { primaryColor: newColor } );
							},
							enableAlpha: false,
							defaultValue: '#0e64b7',
						} )
					),
					/* Tabs: Template | Theme */
					el( TabPanel, {
						className: 'crocina-style-tabs',
						tabs: [
							{
								name: 'template',
								title: el( 'span', {},
									el( Dashicon, { icon: 'layout' } ),
									' ' + __( 'Template', 'crocina-forms' )
								),
							},
							{
								name: 'theme',
								title: el( 'span', {},
									el( Dashicon, { icon: 'art' } ),
									' ' + __( 'Theme', 'crocina-forms' )
								),
							},
						],
						initialTabName: 'template',
					}, function ( tab ) {
						if ( tab.name === 'template' ) {
							return el( 'div', { className: 'crocina-tab-content' },
								el( 'div', { className: 'crocina-template-grid' },
									templateOptions.map( function ( tpl ) {
										var isActive = template === tpl.value;
										return el(
											'button',
											{
												className: 'crocina-template-option' + ( isActive ? ' is-active' : '' ),
												type: 'button',
												onClick: function () {
													setAttributes( { template: tpl.value } );
												},
												'aria-pressed': isActive,
												key: tpl.value,
											},
											el( Dashicon, { icon: templatePreviewIcons[ tpl.value ] || 'admin-generic' } ),
											el( 'div', { className: 'crocina-template-label-row' },
												el( 'span', { className: 'crocina-template-label' }, tpl.label ),
												el( ColorIndicator, { colorValue: primaryColor } )
											)
										);
									} )
								)
							);
						}
						/* theme tab */
						return el( 'div', { className: 'crocina-tab-content' },
							el( 'div', { className: 'crocina-template-grid' },
								themeOptions.map( function ( tpl ) {
									var isActive = theme === tpl.value;
									return el(
										'button',
										{
											className: 'crocina-template-option' + ( isActive ? ' is-active' : '' ),
											type: 'button',
											onClick: function () {
												setAttributes( { theme: tpl.value } );
											},
											'aria-pressed': isActive,
											key: 'theme-' + tpl.value,
										},
										el( Dashicon, { icon: themePreviewIcons[ tpl.value ] || 'admin-generic' } ),
										el( 'div', { className: 'crocina-template-label-row' },
											el( 'span', { className: 'crocina-template-label' }, tpl.label ),
											el( ColorIndicator, { colorValue: primaryColor } )
										)
									);
								} )
							)
						);
					} )
				),

				/* Display settings */
				el(
					PanelBody,
					{
						title:    __( 'Display Options', 'crocina-forms' ),
						initialOpen: false,
					},
					el( RangeControl, {
						label:    __( 'Preview height (px)', 'crocina-forms' ),
						value:    previewHeight,
						onChange: function ( value ) {
							setAttributes( { previewHeight: value } );
						},
						min:  200,
						max:  1200,
						step: 20,
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
					} ),
					el( ToggleControl, {
						label:    __( 'Show preview header', 'crocina-forms' ),
						help:     showHeader
							? __( 'Display the form name bar in the editor preview.', 'crocina-forms' )
							: __( 'Hide the form name bar in the editor preview.', 'crocina-forms' ),
						checked:  showHeader,
						onChange: function ( value ) {
							setAttributes( { showHeader: value } );
						},
						__nextHasNoMarginBottom: true,
					} ),
					el( ToggleControl, {
						label:    __( 'Show form footer', 'crocina-forms' ),
						help:     showFooter
							? __( 'Display the submit button area.', 'crocina-forms' )
							: __( 'Hide the submit button area.', 'crocina-forms' ),
						checked:  showFooter,
						onChange: function ( value ) {
							setAttributes( { showFooter: value } );
						},
						__nextHasNoMarginBottom: true,
					} ),
					el( ToggleControl, {
						label:    __( 'Use theme primary color for button', 'crocina-forms' ),
						help:     usePrimaryColor
							? __( 'Submit button takes its background from the primary color.', 'crocina-forms' )
							: __( 'Submit button uses its own background color setting.', 'crocina-forms' ),
						checked:  usePrimaryColor,
						onChange: function ( value ) {
							setAttributes( { usePrimaryColor: value } );
						},
						__nextHasNoMarginBottom: true,
					} ),
					/* Viewport selector */
					el( SelectControl, {
						label:    __( 'Responsive preview', 'crocina-forms' ),
						value:    viewport,
						options:  viewportOptions.map( function ( vp ) {
							return {
								value: vp.value,
								label: vp.label + ( vp.width && vp.width !== 'auto' ? ' (' + vp.width + 'px)' : '' ),
							};
						} ),
						onChange: function ( value ) {
							setAttributes( { viewport: value } );
						},
						__nextHasNoMarginBottom: true,
					} ),
					/* Reset to defaults button */
					el(
						'div',
						{ className: 'crocina-reset-section' },
						el( 'button', {
							className: 'crocina-reset-btn',
							type: 'button',
							title: __( 'Reset all block settings to their default values.', 'crocina-forms' ),
							onClick: function () {
								if ( confirm( __( 'Reset all settings to defaults? This action cannot be undone.', 'crocina-forms' ) ) ) {
									setAttributes( {
										template:      'default',
										theme:         'modern',
										viewport:      'full',
										primaryColor:  '#0e64b7',
										showHeader:    true,
										showFooter:    true,
										previewHeight: 440,
										usePrimaryColor: false,
									} );
								}
							},
						},
							el( Dashicon, { icon: 'image-rotate' } ),
							__( 'Reset to defaults', 'crocina-forms' )
						)
					),
				),


			);

			/* -------------------------------------------------------------- */
			/*  Loading state                                                  */
			/* -------------------------------------------------------------- */
			if ( isLoading ) {
				return el( 'div', { key: 'wrapper' },
					inspector,
					el(
						Placeholder,
						{
							icon:  blockIcon,
							label: __( 'Crocina Form', 'crocina-forms' ),
							instructions: __( 'Loading available forms\u2026', 'crocina-forms' ),
						},
						el( Spinner, null )
					)
				);
			}

			/* -------------------------------------------------------------- */
			/*  Error state \u2014 no forms available                               */
			/* -------------------------------------------------------------- */
			if ( loadError && ! forms.length ) {
				return el( 'div', { key: 'wrapper' },
					inspector,
					el(
						Placeholder,
						{
							icon:  blockIcon,
							label: __( 'Crocina Form', 'crocina-forms' ),
							instructions: loadError,
						}
					)
				);
			}

			/* -------------------------------------------------------------- */
			/*  No form selected \u2014 show placeholder with dropdown              */
			/* -------------------------------------------------------------- */
			if ( ! formId ) {
				return el( 'div', { key: 'wrapper' },
					inspector,
					el(
						Placeholder,
						{
							icon:  blockIcon,
							label: __( 'Crocina Form', 'crocina-forms' ),
							instructions: __( 'Choose which form to display.', 'crocina-forms' ),
						},
						el( SelectControl, {
							label:    __( 'Choose a form', 'crocina-forms' ),
							value:    formId,
							options:  options,
							onChange: function ( value ) {
							setAttributes( {
								formId: parseInt( value, 10 ),
								template: 'default',
							} );
							},
							__nextHasNoMarginBottom: true,
						} )
					)
				);
			}

			/* -------------------------------------------------------------- */
			/*  Form selected \u2014 show preview with optional header & controls   */
			/* -------------------------------------------------------------- */
			var blockContent = null;

			if ( showHeader ) {
				blockContent = el(
					'div',
					{
						className: 'crocina-block-preview',
						key: 'preview',
					},
					el(
						'div',
						{ className: 'crocina-block-preview-header' },
						el( Dashicon, { icon: 'feedback' } ),
						el( 'span', { className: 'crocina-block-preview-title' },
							__( 'Crocina Form', 'crocina-forms' )
						),
						el( 'span', { className: 'crocina-block-preview-name' }, selectedLabel )
					),
					el(
						'div',
						{ className: 'crocina-block-preview-badge' },
						el( 'span', {
							className: 'crocina-block-preview-template',
							title: __( 'Current template. Change it in the Template panel on the left.', 'crocina-forms' ),
						},
							__( 'Template:', 'crocina-forms' ) + ' ' +
							( templateOptions.filter( function ( t ) { return t.value === template; } )[0] || {} ).label ||
							template
						),
						previewHeight !== 440
							? el( 'span', {
								className: 'crocina-block-preview-height',
								title: __( 'Preview height for the editor. Adjust it in the Display Options panel.', 'crocina-forms' ),
							},
								__( 'H:', 'crocina-forms' ) + ' ' + previewHeight + 'px'
							)
							: null,
						el( 'span', {
							className: 'crocina-block-preview-theme',
							title: __( 'Current visual theme. Change it in the Theme panel on the left.', 'crocina-forms' ),
						},
							__( 'Theme:', 'crocina-forms' ) + ' ' +
							( themeOptions.filter( function ( t ) { return t.value === theme; } )[0] || {} ).label ||
							theme
						),
						viewport !== 'full' && viewport !== 'auto'
							? el( 'span', {
								className: 'crocina-block-preview-viewport',
								title: __( 'Responsive preview mode for testing different screen sizes.', 'crocina-forms' ),
							},
								el( Dashicon, { icon: viewportIcons[ viewport ] || 'desktop' } ),
								' ' + ( viewportLabels[ viewport ] || viewport )
							)
							: null
					)
				);
			}

			var viewportWidth = viewportWidths[ viewport ];
			var hasFrame = viewport === 'auto' || ( typeof viewportWidth === 'number' && viewportWidth > 0 );

			var previewStyle = {
				padding: '0.75rem',
				border:  '1px dashed #ccd0d4',
				borderRadius: '4px',
				background: '#f9f9f9',
			};

			var frameStyle = hasFrame
				? {
						maxWidth: viewport === 'auto' ? '100%' : ( typeof viewportWidth === 'number' ? viewportWidth + 'px' : '100%' ),
						marginLeft: 'auto',
						marginRight: 'auto',
						border: '1px solid #e0e0e0',
						borderRadius: '8px',
						overflow: 'hidden',
						boxShadow: '0 2px 12px rgba(0,0,0,0.08)',
						background: '#fff',
					}
				: {};

			var palette = generatePrimaryPalette( primaryColor );
			var formContainerStyle = {
				maxHeight: previewHeight + 'px',
				overflowY: 'auto',
				'--crocina-theme-primary': primaryColor,
			};
			/* Merge palette shades into the style object. */
			for ( var p in palette ) {
				if ( palette.hasOwnProperty( p ) ) {
					formContainerStyle[ p ] = palette[ p ];
				}
			}

			return el( 'div', { key: 'wrapper' },
				inspector,
				el(
					'div',
					{
						className: 'crocina-block-frontend-preview',
						style: previewStyle,
					},
					blockContent,
					el(
						'div',
						{
							className: 'crocina-viewport-frame' + ( hasFrame ? ' crocina-viewport-active' : '' ),
							style:  frameStyle,
						},
						el(
							'div',
							{
								className: 'crocina-block-form-container crocina-form-template-' + template,
								style:     formContainerStyle,
							},
							el( ServerSideRender, {
								block: 'crocina-forms/form-selector',
								attributes: props.attributes,
								loading: el( Placeholder, null, el( Spinner, null ) ),
							} )
						)
					)
				)
			);
		},

		save: function () {
			return null;
		},
	} );

	/* ------------------------------------------------------------------ */
	/*  Register Block Styles \u2014 WordPress native style selector for         */
	/*  template variants. These appear in the block toolbar dropdown and   */
	/*  add `is-style-{name}` class to the block wrapper.                   */
	/*                                                                        */
	/*  Each style declares an isActive callback that reads the block’s       */
	/*  `template` attribute directly, so the Style Picker stays in sync      */
	/*  without needing to reverse-sync via className.                        */
	/*  Works alongside the custom InspectorControls template grid.           */
	/* ------------------------------------------------------------------ */
	registerBlockStyle( 'crocina-forms/form-selector', {
		name: 'default',
		label: __( 'Default', 'crocina-forms' ),
		isDefault: true,
		isActive: function( attributes ) {
			return attributes.template === 'default';
		},
	} );
	registerBlockStyle( 'crocina-forms/form-selector', {
		name: 'card',
		label: __( 'Card', 'crocina-forms' ),
		isActive: function( attributes ) {
			return attributes.template === 'card';
		},
	} );
	registerBlockStyle( 'crocina-forms/form-selector', {
		name: 'minimal',
		label: __( 'Minimal', 'crocina-forms' ),
		isActive: function( attributes ) {
			return attributes.template === 'minimal';
		},
	} );
	registerBlockStyle( 'crocina-forms/form-selector', {
		name: 'bordered',
		label: __( 'Bordered', 'crocina-forms' ),
		isActive: function( attributes ) {
			return attributes.template === 'bordered';
		},
	} );
	registerBlockStyle( 'crocina-forms/form-selector', {
		name: 'shadow',
		label: __( 'Shadow', 'crocina-forms' ),
		isActive: function( attributes ) {
			return attributes.template === 'shadow';
		},
	} );

} )( window.wp );
