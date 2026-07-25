#!/usr/bin/env node
/**
 * Dead CSS Checker — Crocina Forms
 *
 * Extracts CSS class selectors from assets/*.css using simple regex,
 * then cross-references them against all .php/.js/.css files to find
 * selectors never referenced in the codebase.
 *
 * Usage:
 *   node tools/check-dead-css.js
 *   node tools/check-dead-css.js --json        machine-readable output
 *   node tools/check-dead-css.js --fail-on      exit 1 on any dead selector
 *   node tools/check-dead-css.js --allow=foo    ignore selectors starting with "foo"
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');

// ---- CLI args ----
const args = process.argv.slice(2);
const FORMAT_JSON = args.includes('--json');
const FAIL_ON_WARN = args.includes('--fail-on');
const ALLOW_PREFIXES = [];
const allowArg = args.find(a => a.startsWith('--allow='));
if (allowArg) {
  ALLOW_PREFIXES.push(...allowArg.slice(8).split(','));
}

/**
 * Strip CSS comments /* ... *​/ from a string so they don't produce false
 * positives in selector extraction.
 */
function stripCSSComments(css) {
  return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

// ---- 1. Extract all CSS class selectors from files ----
function extractSelectorsFromCSS(filePath) {
  const raw = fs.readFileSync(filePath, 'utf8');
  const css = stripCSSComments(raw);
  const selectors = new Map(); // className => Set of "file:line"

  // Match rule selectors: anything before the first {
  const rulePattern = /([^{]+)\{/g;
  let m;
  while ((m = rulePattern.exec(css)) !== null) {
    const selectorText = m[1].trim();
    // Skip @-rules and keyframe selector lists
    if (selectorText.startsWith('@') || selectorText.startsWith('from') ||
        selectorText.startsWith('to') || /^\d+%/.test(selectorText)) {
      continue;
    }
    // Strip url() content from the selector text so tokens inside
    // data: URIs (like "w3.org") are not falsely extracted as classes.
    const urlFreeSelector = selectorText.replace(/url\s*\([^)]*\)/gi, '');

    // Extract individual .class names (ignore pseudo, attr, combinators)
    const classMatches = urlFreeSelector.match(/\.([\w-]+)/g);
    if (!classMatches) continue;

    for (const cm of classMatches) {
      const name = cm.slice(1); // remove leading dot
      // Skip numeric-only (CSS values like 1rem were already parsed as .1rem)
      if (/^\d/.test(name)) continue;
      // Calculate the source line by counting newlines up to this match
      const line = (raw.slice(0, m.index).match(/\n/g) || []).length + 1;
      const loc = `${path.basename(filePath)}:${line}`;
      if (!selectors.has(name)) {
        selectors.set(name, new Set());
      }
      selectors.get(name).add(loc);
    }
  }
  return selectors;
}

// ---- 2. Scan codebase for CSS class references ----
function scanCodebase() {
  const extensions = new Set(['.php', '.js', '.css', '.json']);
  const skipDirs = new Set(['node_modules', '.git', '.freebuff', 'vendor', 'tests', 'tools']);
  const usedSelectors = new Set();

  const files = [];
  function walkDir(dir) {
    let entries;
    try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch { return; }
    for (const entry of entries) {
      if (entry.name.startsWith('.') || skipDirs.has(entry.name)) continue;
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        walkDir(full);
      } else if (entry.isFile() && extensions.has(path.extname(entry.name))) {
        files.push(full);
      }
    }
  }
  walkDir(ROOT);

  for (const file of files) {
    const content = fs.readFileSync(file, 'utf8');

    // Match class names in: class="foo", class='foo', className="foo", className={'foo'}
    const attrMatches = content.match(/class(?:Name)?\s*=\s*(?:"([^"]*)"|'([^']*)'|`[^`]*`)/gi) || [];
    for (const attr of attrMatches) {
      // Extract inner content between quotes
      const inner = attr.replace(/^class(?:Name)?\s*=\s*['"`]/, '').replace(/['"`]$/, '');
      for (const word of inner.split(/\s+/)) {
        if (word && word.indexOf('$') === -1 && word.indexOf('{') === -1) {
          usedSelectors.add(word);
        }
      }
    }

    // Match JS: .addClass('foo'), .removeClass('foo bar'), .toggleClass('foo'), .hasClass('foo')
    // Captures ALL space-separated class names from a single string.
    const classMethodMatches = content.match(/\.(?:addClass|removeClass|toggleClass|hasClass)\s*\(\s*["']([\w-]+(?:\s+[\w-]+)*)["']\s*\)/g) || [];
    for (const ref of classMethodMatches) {
      const match = ref.match(/["']([\w-]+(?:\s+[\w-]+)*)["']/);
      if (match) {
        match[1].split(/\s+/).forEach(function (cls) {
          if (cls) usedSelectors.add(cls);
        });
      }
    }

    // Match JS: classList.add('foo'), classList.remove('foo'), etc.
    const classListMatches = content.match(/classList\.(?:add|remove|toggle|contains)\s*\(\s*["']([\w-]+)["']\s*\)/g) || [];
    for (const ref of classListMatches) {
      const match = ref.match(/["']([\w-]+)["']/);
      if (match) usedSelectors.add(match[1]);
    }

    // Match JS: closest('.foo'), find('.foo'), filter('.foo')
    const cssQueryRefs = content.match(/\.(?:closest|find|filter|not|children|parent|siblings|prev|next|prevAll|nextAll)\s*\(\s*["']\.?([\w-]+)["']\s*\)/g) || [];
    for (const ref of cssQueryRefs) {
      const match = ref.match(/["']\.?([\w-]+)["']/);
      if (match) usedSelectors.add(match[1]);
    }

    // Match jQuery-like: $('.foo'), jQuery('.foo'), $(container).find('.foo')
    const jQueryRefs = content.match(/['"]\.([\w-]+)['"]\)/g) || [];
    for (const ref of jQueryRefs) {
      const match = ref.match(/\.([\w-]+)/);
      if (match) usedSelectors.add(match[1]);
    }

    // Match JSX object-property syntax:  className: 'foo',  className: "foo"
    // Also catches concatenation:  className: 'crocina-field-row' + (cond ? ...)
    // by extracting the initial string literal before the + operator.
    const jsxClassNameMatches = content.match(/className\s*:\s*['"]([\w-]+(?:\s+[\w-]+)*)['"]/g) || [];
    for (const ref of jsxClassNameMatches) {
      const match = ref.match(/['"]([\w-]+(?:\s+[\w-]+)*)['"]/);
      if (match) {
        match[1].split(/\s+/).forEach(function (cls) {
          if (cls) usedSelectors.add(cls);
        });
      }
    }

    // Match element.className = 'foo' assignments (used in form.js for notices).
    const classNameAssignMatches = content.match(/\.className\s*=\s*['"]([\w-]+(?:\s+[\w-]+)*)['"]/g) || [];
    for (const ref of classNameAssignMatches) {
      const match = ref.match(/['"]([\w-]+(?:\s+[\w-]+)*)['"]/);
      if (match) {
        match[1].split(/\s+/).forEach(function (cls) {
          if (cls) usedSelectors.add(cls);
        });
      }
    }
  }

  return usedSelectors;
}

// ---- Core classes always allowed ----
const ALWAYS_ALLOW = new Set([
  // WordPress admin
  'wp-admin', 'wp-core-ui', 'wp-menu', 'wp-menu-image', 'wp-submenu',
  'wp-submenu-head', 'wp-menu-name', 'wp-not-current-submenu',
  'wp-has-current-submenu', 'wp-first-item', 'wp-menu-separator',
  'wp-header-end', 'wp-heading-inline', 'wp-list-table', 'wrap',
  'wp-responsive-toggle',
  // Dashicons
  'dashicons',
  // Buttons
  'button', 'button-primary', 'button-secondary', 'button-small',
  'button-large', 'button-hero', 'button-link', 'button-danger',
  // Notices
  'notice', 'notice-success', 'notice-error', 'notice-warning',
  'notice-info', 'notice-dismiss', 'notice-alt', 'is-dismissible',
  'updated', 'error', 'warning', 'success', 'info',
  // Postbox
  'postbox', 'postbox-header', 'hndle', 'handlediv', 'inside',
  'postbox-container', 'meta-box-sortables',
  // Table
  'widefat', 'striped', 'tablenav', 'tablenav-pages',
  'displaying-num', 'pagination-links', 'paging-input', 'current-page',
  'total-pages', 'alignleft', 'alignright', 'aligncenter',
  // Nav tabs
  'nav-tab-wrapper', 'nav-tab', 'nav-tab-active',
  // Screen reader
  'screen-reader-text', 'screen-reader-shortcut',
  // Spinner
  'spinner', 'page-title-action', 'view-switch', 'add-new-h2',
  // Media modal
  'media-modal', 'media-modal-backdrop', 'media-frame',
  'media-frame-title', 'media-frame-menu', 'media-frame-content',
  'media-toolbar', 'media-toolbar-primary', 'media-toolbar-secondary',
  'media-button', 'media-frame-router', 'media-router',
  'media-selection', 'attachments', 'attachments-browser',
  // Common admin
  'clear', 'hidden', 'hide-if-js', 'hide-if-no-js', 'no-js',
  'description', 'subtitle', 'count', 'major-publishing-actions',
  'publishing-action', 'save-order', 'form-table',
  // jQuery UI
  'ui-sortable', 'ui-draggable', 'ui-droppable',
  'ui-sortable-handle', 'ui-draggable-handle', 'ui-state-highlight',
  'ui-state-default', 'ui-state-active', 'ui-state-hover',
  // Block editor
  'block-editor-block-list__block', 'block-editor-block-card',
  'block-editor-inspector-controls', 'block-editor-panel-body',
  'block-editor-block-styles', 'block-editor-block-styles__item',
  'block-editor-block-styles__item-text',
  'block-editor-block-icon', 'block-editor-block-variation-picker',
  'block-editor-writing-flow',
  'wp-block', 'wp-block-code', 'wp-block-image', 'wp-block-heading',
  'wp-block-list', 'wp-block-quote',
  // Components
  'components-panel', 'components-panel__body', 'components-panel__header',
  'components-panel__row', 'components-base-control',
  'components-base-control__label', 'components-base-control__help',
  'components-text-control__input', 'components-select-control__input',
  'components-button', 'components-tab-panel', 'components-tab-panel__tabs',
  'components-tab-panel__tab', 'components-color-palette',
  'components-color-picker', 'components-circular-option-picker',
  'components-range-control', 'components-placeholder',
  'components-sandbox', 'components-popover', 'components-dropdown',
  'components-menu-group', 'components-menu-item',
  'components-toolbar', 'components-toolbar-group',
  'components-form-toggle', 'components-toggle-control',
  'components-checkbox-control__input', 'components-radio-control__input',
  'components-notice', 'components-notice__content',
  'components-notice__actions', 'components-flex', 'components-flex-item',
  'components-flex-block', 'components-icon',
  // CodeMirror
  'CodeMirror', 'CodeMirror-scroll', 'CodeMirror-sizer',
  'CodeMirror-gutter', 'CodeMirror-gutters', 'CodeMirror-linenumber',
  'CodeMirror-lines', 'CodeMirror-code', 'CodeMirror-cursor',
  'CodeMirror-selected', 'CodeMirror-focused', 'CodeMirror-activeline',
  'CodeMirror-activeline-background', 'CodeMirror-matchingtag',
  'CodeMirror-matchingbracket', 'CodeMirror-hints', 'CodeMirror-hint',
  // States
  'is-active', 'is-disabled', 'is-focused', 'is-open', 'is-dragging',
  'is-drop-target', 'is-loading', 'is-small', 'is-unread',
  'is-visible', 'is-hidden', 'is-selected', 'is-expanded',
  'is-collapsed', 'is-valid', 'is-invalid', 'is-required',
  'is-error', 'is-warning', 'is-success', 'is-info',
  // Pseudo
  'active', 'focus', 'hover', 'disabled', 'checked', 'selected',
  'open', 'closed', 'expanded', 'collapsed', 'before', 'after',
  'first', 'last', 'odd', 'even',
  // Keyboard
  'key', 'kbd', 'shortcut',
  // Utility
  'items', 'theme', 'tags', 'search', 'filter', 'sort', 'order',
  'asc', 'desc',
  // Misc admin
  'post-php', 'post-new-php', 'term-php', 'user-edit-php',
  'options-general-php', 'admin-php',
  // Thickbox
  'thickbox', 'tb-close-icon',
  // Gutenberg block editor component classes (provided by WP core)
  'wp-block-crocina-forms-form-selector',
  'dashicons-before', 'dashicon',
  'is-style-default', 'is-style-card', 'is-style-minimal',
  'is-style-bordered', 'is-style-shadow',
  'components-panel__body-title',
  'components-base-control__field',
  'components-color-picker-wrapper',
  'components-color-picker__inputs-wrapper',
  'components-color-picker__body',
  'components-tab-panel__tabs-item',
  'component-color-indicator',
  // is-* state classes used via JSX concatenation: + (cond ? ' is-drag-over' : '')
  'is-drag-over', 'is-group-dragging',
]);

// ---- Main ----
const cssFiles = ['assets/admin.css', 'assets/form.css', 'assets/block.css'];
const allSelectors = new Map(); // name => Set of locations

for (const f of cssFiles) {
  const fp = path.join(ROOT, f);
  if (!fs.existsSync(fp)) {
    console.warn(`⚠  File not found: ${f}`);
    continue;
  }
  const fileSelectors = extractSelectorsFromCSS(fp);
  for (const [name, locs] of fileSelectors) {
    if (!allSelectors.has(name)) allSelectors.set(name, new Set());
    for (const loc of locs) allSelectors.get(name).add(loc);
  }
}

const usedSelectors = scanCodebase();

// Classify
const dead = [];
const used = [];
for (const [name, locs] of allSelectors) {
  // Skip numeric-only
  if (/^\d+(?:\.\d+)?$/.test(name)) continue;
  if (/^\d/.test(name)) continue;
  // Always allow list
  if (ALWAYS_ALLOW.has(name)) continue;
  // Allow prefixes
  if (ALLOW_PREFIXES.some(p => name.startsWith(p))) continue;

  const locArr = [...locs];
  if (usedSelectors.has(name)) {
    used.push({ selector: name, locations: locArr });
  } else {
    dead.push({ selector: name, locations: locArr });
  }
}

// ---- Output ----
if (FORMAT_JSON) {
  console.log(JSON.stringify({
    total: allSelectors.size,
    used: used.length,
    dead: dead.length,
    dead_selectors: dead.map(d => ({
      selector: d.selector,
      locations: d.locations,
    })),
  }, null, 2));
} else {
  const SEP = '─'.repeat(60);
  console.log(`\n  ${SEP}`);
  console.log('  🧹  Crocina Forms — Dead CSS Report');
  console.log(`  ${SEP}\n`);
  console.log(`  Total CSS selectors extracted:  ${allSelectors.size}`);
  console.log(`  Used in codebase:               ${used.length}`);
  console.log(`  Potentially dead:               ${dead.length}\n`);

  if (dead.length > 0) {
    console.log(`  ${SEP}`);
    console.log('  POTENTIALLY DEAD SELECTORS (cross-reference with grep to confirm)');
    console.log(`  ${SEP}\n`);
    for (const d of dead.slice(0, 40)) {
      console.log(`  ❌  .${d.selector}`);
      console.log(`      In: ${d.locations.join(', ')}`);
    }
    if (dead.length > 40) {
      console.log(`\n  ... and ${dead.length - 40} more. Use --json for the full list.\n`);
    }
    console.log(`  ${SEP}\n`);
    console.log('  Tips:');
    console.log('  • Exclude false positives: --allow=crocina-debug');
    console.log('  • Confirm dead: grep -rn "selector-name" includes/ assets/ templates/');
    console.log(`  ${SEP}\n`);
  } else {
    console.log('  ✅  No potentially dead selectors found!\n');
    console.log(`  ${SEP}\n`);
  }
}

if (FAIL_ON_WARN && dead.length > 0) {
  process.exit(1);
}
