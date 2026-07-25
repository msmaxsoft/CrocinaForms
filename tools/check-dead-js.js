#!/usr/bin/env node
/**
 * Dead JS Functions Checker — Crocina Forms
 *
 * Extracts all named function declarations, variable-assigned functions,
 * and method definitions from assets/*.js, then cross-references them
 * against all .php/.js/.css files to find functions that are defined
 * but never called or referenced elsewhere in the codebase.
 *
 * IMPORTANT: Only parses standalone .js files in assets/.  Functions
 * defined inside inline <script> tags in .php templates are NOT parsed
 * and must be checked manually or added to the ALWAYS_ALLOW set.
 *
 * Accuracy: Uses occurrence counting — a function is "used" only when
 * its name appears MORE times than its definition declarations.  This
 * avoids false negatives where the definition itself is counted as a
 * reference.
 *
 * Usage:
 *   node tools/check-dead-js.js
 *   node tools/check-dead-js.js --json         machine-readable output
 *   node tools/check-dead-js.js --fail-on       exit 1 on any dead function
 *   node tools/check-dead-js.js --allow=foo     ignore functions starting with "foo"
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

// ---- 1. Extract all function definitions from JS files ----

/**
 * Parse JS source text and return a structure:
 *   {
 *     definitions: Map<fnName, Set<locString>>,
 *     definitionCounts: Map<fnName, number>   // occurrences in definition positions
 *   }
 *
 * Catches these patterns:
 *   - function fnName(…) { … }
 *   - var fnName = function(…) { … }
 *   - var fnName = (…) => … | (…) => {
 *   - name: function(…) { … }             (object methods)
 */
function extractFunctionsFromJS(filePath) {
  const raw = fs.readFileSync(filePath, 'utf8');
  const definitions = new Map();   // fnName => Set of loc strings
  const defCounts = new Map();     // fnName => count of definition occurrences

  // Strip comments so we don't match commented-out code.
  const code = raw
    .replace(/\/\/.*$/gm, '')
    .replace(/\/\*[\s\S]*?\*\//g, '');

  const record = (name, idx) => {
    const line = (raw.slice(0, idx).match(/\n/g) || []).length + 1;
    const loc = `${path.basename(filePath)}:${line}`;
    if (!definitions.has(name)) definitions.set(name, new Set());
    definitions.get(name).add(loc);
    defCounts.set(name, (defCounts.get(name) || 0) + 1);
  };

  // 1) function fnName(…)
  let re = /function\s+([a-zA-Z_$][\w$]*)\s*\(/g;
  let m;
  while ((m = re.exec(code)) !== null) {
    record(m[1], m.index);
  }

  // 2) var|let|const fnName = function[(…)]
  re = /(?:var|let|const)\s+([a-zA-Z_$][\w$]*)\s*=\s*function\s*(?:[a-zA-Z_$][\w$]*)?\s*\(/g;
  while ((m = re.exec(code)) !== null) {
    record(m[1], m.index);
  }

  // 3) var|let|const fnName = … =>
  //    Matches both (args) =>  and  singleArg =>
  re = /(?:var|let|const)\s+([a-zA-Z_$][\w$]*)\s*=\s*(?:\([^)]*\)|[a-zA-Z_$][\w$]*)\s*=>/g;
  while ((m = re.exec(code)) !== null) {
    record(m[1], m.index);
  }

  // 4) name: function(…)   (object methods)
  //    Skip common jQuery/Promise method names to avoid noise.
  const SKIP_METHODS = new Set([
    'done','fail','always','then','catch','finally',
    'success','error','complete','statusCode',
    'on','off','one','trigger','bind','unbind',
  ]);
  re = /([a-zA-Z_$][\w$]*)\s*:\s*function\s*\(/g;
  while ((m = re.exec(code)) !== null) {
    if (!SKIP_METHODS.has(m[1])) {
      record(m[1], m.index);
    }
  }

  return { definitions, definitionCounts: defCounts };
}

// ---- 2. Scan codebase for total occurrence count of each token ----

function scanCodebaseForOccurrences(definitionNames) {
  const extensions = new Set(['.php', '.js', '.css', '.json']);
  const skipDirs = new Set(['node_modules', '.git', '.freebuff', 'vendor', 'tests']);
  // Use a simple counter for each function name across all files.
  const occurrenceCounts = new Map(); // fnName => count

  // Initialise all definition names at 0.
  for (const name of definitionNames) {
    occurrenceCounts.set(name, 0);
  }

  const files = [];
  function walkDir(dir) {
    let entries;
    try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch { return; }
    for (const entry of entries) {
      if (entry.name.startsWith('.') || skipDirs.has(entry.name)) continue;
      if (entry.name === 'check-dead-js.js') continue;
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        walkDir(full);
      } else if (entry.isFile() && extensions.has(path.extname(entry.name))) {
        files.push(full);
      }
    }
  }
  walkDir(ROOT);

  // Build a regex that matches ANY of the definition names as whole words.
  const names = [...definitionNames]
    .filter(n => n.length > 1)             // skip single-char tokens
    .sort((a, b) => b.length - a.length);  // longest first for greedy match
  if (names.length === 0) return occurrenceCounts;

  // Escape special regex characters in function names.
  const escaped = names.map(n => n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
  const pattern = new RegExp('\\b(' + escaped.join('|') + ')\\b', 'g');

  for (const file of files) {
    const content = fs.readFileSync(file, 'utf8');

    // Strip comments for PHP and JS files to avoid false positives from
    // commented-out code.
    let searchText = content;
    const ext = path.extname(file);
    if (ext === '.php' || ext === '.js') {
      searchText = content
        .replace(/\/\/.*$/gm, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/#.*$/gm, '');
    }

    let match;
    while ((match = pattern.exec(searchText)) !== null) {
      const name = match[1];
      occurrenceCounts.set(name, (occurrenceCounts.get(name) || 0) + 1);
    }
  }

  return occurrenceCounts;
}

// ---- 3. Functions always allowed ----

const ALWAYS_ALLOW = new Set([
  // Standard built-in callbacks / patterns
  'done', 'fail', 'always', 'then', 'catch', 'finally', 'pipe', 'progress',
  'resolve', 'reject', 'notify', 'promise', 'state',
  'success', 'error', 'complete', 'statusCode',
  'on', 'off', 'one', 'trigger', 'bind', 'unbind', 'live', 'die',
  'delegate', 'undelegate',
  'ajax', 'get', 'post', 'getJSON', 'getScript',
  'ready', 'load', 'resize', 'scroll', 'unload',
]);

// ---- Main ----

const jsFiles = ['assets/admin.js', 'assets/form.js'];
const allDefinitions = new Map();   // fnName => Set of locations
const allDefCounts = new Map();      // fnName => definition-occurrence count

for (const f of jsFiles) {
  const fp = path.join(ROOT, f);
  if (!fs.existsSync(fp)) {
    console.warn(`⚠  File not found: ${f}`);
    continue;
  }
  const { definitions, definitionCounts } = extractFunctionsFromJS(fp);
  for (const [name, locs] of definitions) {
    if (!allDefinitions.has(name)) allDefinitions.set(name, new Set());
    for (const loc of locs) allDefinitions.get(name).add(loc);
    allDefCounts.set(name, (allDefCounts.get(name) || 0) + (definitionCounts.get(name) || 0));
  }
}

// Scan the codebase for total occurrences of each function name.
const occurrenceCounts = scanCodebaseForOccurrences(new Set(allDefinitions.keys()));

// Classify: a function is "used" if total occurrences > definition occurrences.
// The definition itself accounts for at least 1 occurrence.  Any occurrence
// beyond that means something calls/references the function.
const dead = [];
const used = [];
for (const [name, locs] of allDefinitions) {
  // Always allow list
  if (ALWAYS_ALLOW.has(name)) continue;
  // Allow prefixes
  if (ALLOW_PREFIXES.some(p => name.startsWith(p))) continue;

  const defCount = allDefCounts.get(name) || 0;
  const totalCount = occurrenceCounts.get(name) || 0;

  if (totalCount > defCount) {
    used.push({ function: name, locations: [...locs], calls: totalCount - defCount });
  } else {
    dead.push({ function: name, locations: [...locs], defCount });
  }
}

// ---- Output ----
if (FORMAT_JSON) {
  console.log(JSON.stringify({
    total: allDefinitions.size,
    used: used.length,
    dead: dead.length,
    dead_functions: dead.map(d => ({
      function: d.function,
      locations: d.locations,
      definition_occurrences: d.defCount,
    })),
  }, null, 2));
} else {
  const SEP = '─'.repeat(60);
  console.log(`\n  ${SEP}`);
  console.log('  🧹  Crocina Forms — Dead JS Functions Report');
  console.log(`  ${SEP}\n`);
  console.log(`  Total JS functions found:       ${allDefinitions.size}`);
  console.log(`  Referenced in codebase:          ${used.length}`);
  console.log(`  Potentially dead:                ${dead.length}\n`);

  if (used.length > 0 && !FORMAT_JSON) {
    console.log(`  ${SEP}`);
    console.log('  USED FUNCTIONS (verified by call count)');
    console.log(`  ${SEP}\n`);
    for (const u of used.slice(0, 20)) {
      console.log(`  ✅  ${u.function}() — ${u.calls} call(s) beyond definition`);
    }
    if (used.length > 20) {
      console.log(`\n  ... and ${used.length - 20} more. Use --json for the full list.\n`);
    }
  }

  if (dead.length > 0) {
    console.log(`\n  ${SEP}`);
    console.log('  POTENTIALLY DEAD FUNCTIONS (cross-reference with grep to confirm)');
    console.log(`  ${SEP}`);
    for (const d of dead) {
      console.log(`\n  ❌  ${d.function}()`);
      for (const loc of d.locations) {
        console.log(`      Defined: ${loc}`);
      }
    }
    console.log(`\n  ${SEP}\n`);
    console.log('  Tips:');
    console.log('  • "Dead" means the function name appears exactly as many');
    console.log('    times as its definition — no external calls found.');
    console.log('  • Confirm with: grep -rn "functionName" includes/ assets/ templates/');
    console.log('  • Exclude a prefix: --allow=crocina_debug');
    console.log(`  ${SEP}\n`);
  } else {
    console.log('  ✅  No potentially dead functions found!\n');
    console.log(`  ${SEP}\n`);
  }
}

if (FAIL_ON_WARN && dead.length > 0) {
  process.exit(1);
}
