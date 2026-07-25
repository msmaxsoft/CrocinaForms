#!/usr/bin/env node
/**
 * Remove Dead CSS — Crocina Forms
 *
 * Reads the dead CSS report (from check-dead-css.js --json) and removes
 * the corresponding rules from admin.css.  Handles:
 *   - Full rule removal when ALL selectors are dead
 *   - Partial selector removal when SOME selectors are dead (comma-separated)
 *   - Nested @media / @supports blocks (kept if any sub-rule survives)
 *   - Multi-line selectors
 *
 * Usage:
 *   node tools/check-dead-css.js --json > dead-report.json
 *   node tools/remove-dead-css.js dead-report.json
 *   node tools/remove-dead-css.js dead-report.json --dry-run   (preview only)
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');

const args = process.argv.slice(2);
const reportFile = args.find(a => !a.startsWith('--'));
const DRY_RUN = args.includes('--dry-run');

if (!reportFile) {
  console.error('Usage: node tools/remove-dead-css.js <report.json> [--dry-run]');
  process.exit(1);
}

// ---- Load report ----
let report;
try {
  report = JSON.parse(fs.readFileSync(reportFile, 'utf8'));
} catch (e) {
  console.error('Failed to read report:', e.message);
  process.exit(1);
}

// ---- Build lookup sets ----
const adminDead = new Map(); // selector => line(s)
for (const d of report.dead_selectors) {
  const adminLocs = d.locations.filter(l => l.startsWith('admin.css'));
  if (adminLocs.length > 0) {
    adminDead.set(d.selector, adminLocs);
  }
}

if (adminDead.size === 0) {
  console.log('No dead selectors found in admin.css.');
  process.exit(0);
}

console.log(`Found ${adminDead.size} dead selectors in admin.css\n`);

const cssFile = path.join(ROOT, 'assets/admin.css');
const original = fs.readFileSync(cssFile, 'utf8');

// ---------------------------------------------------------------------------
//  Parse CSS into a flat list of "rule objects".  Each rule is either:
//    1) A top-level declaration (selector + properties)
//    2) An @-rule with nested rules (e.g. @media { .foo { } .bar { } })
//    3) Trailing whitespace / comments after the last block
// ---------------------------------------------------------------------------
function parseRules(css) {
  const rules = [];
  let depth = 0;
  let start = 0;

  for (let i = 0; i < css.length; i++) {
    if (css[i] === '{') {
      if (depth === 0) {
        const before = css.slice(start, i).trim();
        rules.push({
          type: before.startsWith('@') ? 'at-rule' : 'rule',
          headerRaw: css.slice(start, i + 1),   // includes the opening {
          headerStart: start,
          headerEnd: i + 1,
          bodyStart: i + 1,
          _headerText: before,
        });
      }
      depth++;
    } else if (css[i] === '}') {
      depth--;
      if (depth === 0 && rules.length > 0) {
        const last = rules[rules.length - 1];
        if (!last.raw) {
          last.raw = css.slice(last.headerStart, i + 1);
          last.end = i + 1;
          last.bodyRaw = css.slice(last.bodyStart, i);
        }
        // Advance start past the closing brace so the next rule
        // starts where this one ends.
        start = i + 1;
      }
    }
  }

  // Trailing content
  if (start < css.length) {
    const trailing = css.slice(start).trim();
    if (trailing) {
      rules.push({ type: 'trailing', raw: css.slice(start), start, end: css.length });
    }
  }

  return rules;
}

// ---------------------------------------------------------------------------
//  Extract class-name selectors from a rule's selector text
// ---------------------------------------------------------------------------
function getSelectorClasses(ruleHeader) {
  const classes = [];
  // Extract the selector part (before any @media ... or {)
  const re = /\.([\w-]+)/g;
  let m;
  while ((m = re.exec(ruleHeader)) !== null) {
    const name = m[1];
    // Skip numeric-only
    if (/^\d/.test(name)) continue;
    classes.push(name);
  }
  return classes;
}

// ---------------------------------------------------------------------------
//  Rebuild a rule with only the selectors that don't contain dead classes
// ---------------------------------------------------------------------------
function rebuildRule(raw, deadClasses) {
  const braceIdx = raw.indexOf('{');
  if (braceIdx === -1) return raw;

  const selectorPart = raw.slice(0, braceIdx);
  const declarationPart = raw.slice(braceIdx);

  const selectors = selectorPart.split(',').map(s => s.trim()).filter(Boolean);
  if (selectors.length <= 1) return null; // single-selector rule that is dead

  const kept = selectors.filter(sel => {
    return !deadClasses.some(dc => {
      // Check if the selector contains .dead-class-name
      const re = new RegExp('\\\\.' + dc.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![\\w-])');
      return re.test(sel);
    });
  });

  if (kept.length === 0) return null;
  return kept.join(',\n') + declarationPart;
}

// ---------------------------------------------------------------------------
//  Process a single rule: return 'remove', 'keep', or a replacement string
// ---------------------------------------------------------------------------
function processRule(rule) {
  if (rule.type === 'trailing') return 'keep';

  if (rule.type === 'at-rule') {
    // Split the body into sub-rules
    const body = rule.bodyRaw;
    const subRules = parseRules(body);
    const keptParts = [];

    for (const sr of subRules) {
      const result = processRule(sr);
      if (result === 'keep') {
        keptParts.push(sr.raw);
      } else if (typeof result === 'string' && result !== 'remove') {
        keptParts.push(result);
      }
      // 'remove' = skip
    }

    if (keptParts.length === 0) return 'remove';

    // Rebuild the @-rule with kept sub-rules
    const header = rule.headerRaw;
    return header + '\n' + keptParts.join('\n') + '\n}';
  }

  // Top-level rule
  const classes = getSelectorClasses(rule.headerRaw);
  if (classes.length === 0) return 'keep'; // non-class selector (element, id, etc.)

  const deadInRule = classes.filter(c => adminDead.has(c));
  const aliveInRule = classes.filter(c => !adminDead.has(c));

  if (deadInRule.length === 0) return 'keep'; // no dead selectors here

  if (aliveInRule.length === 0) {
    // ALL selectors are dead
    const lineNum = (original.slice(0, rule.headerStart).match(/\n/g) || []).length + 1;
    console.log(`  🗑️  Remove at line ${lineNum}: .${deadInRule[0]}${deadInRule.length > 1 ? ' +' + (deadInRule.length - 1) : ''}`);
    return 'remove';
  }

  // SOME are dead — partial removal
  const lineNum = (original.slice(0, rule.headerStart).match(/\n/g) || []).length + 1;
  console.log(`  ✂️  Partial at line ${lineNum}: removing .${deadInRule.join(', .')} from multi-selector rule`);
  const rebuilt = rebuildRule(rule.raw, deadInRule);
  if (!rebuilt) return 'remove';
  return rebuilt;
}

// ---------------------------------------------------------------------------
//  Main processing
// ---------------------------------------------------------------------------
const rules = parseRules(original);
const outputParts = [];
let removedCount = 0;
let partialCount = 0;

for (const rule of rules) {
  if (rule.type === 'trailing') {
    outputParts.push(rule.raw);
    continue;
  }

  const result = processRule(rule);

  if (result === 'remove') {
    removedCount++;
  } else if (typeof result === 'string' && result !== 'keep') {
    partialCount++;
    outputParts.push(result);
  } else {
    // 'keep' — output unchanged
    outputParts.push(rule.raw);
  }
}

if (DRY_RUN) {
  console.log(`\n── DRY RUN ──`);
  console.log(`Would remove ${removedCount} full rule blocks`);
  console.log(`Would partially modify ${partialCount} blocks`);
  console.log(`Total dead selectors targeted: ${adminDead.size}`);
  process.exit(0);
}

// ---- Write result ----
const result = outputParts.join('');
const originalSize = original.length;
const resultSize = result.length;

fs.writeFileSync(cssFile, result, 'utf8');

console.log(`\n── Done ──`);
console.log(`Removed ${removedCount} full rule blocks`);
console.log(`Partially cleaned ${partialCount} blocks`);
console.log(`Size: ${(originalSize / 1024).toFixed(1)}KB → ${(resultSize / 1024).toFixed(1)}KB (-${((1 - resultSize / originalSize) * 100).toFixed(1)}%)`);

// Re-run dead CSS check to verify
console.log(`\n── Verifying with dead CSS checker ──`);
const { execSync } = require('child_process');
try {
  const verify = execSync('node tools/check-dead-css.js --json', { cwd: ROOT, encoding: 'utf8' });
  const verifyReport = JSON.parse(verify);
  const adminRemaining = verifyReport.dead_selectors.filter(d =>
    d.locations.some(l => l.startsWith('admin.css'))
  ).length;
  if (adminRemaining === 0) {
    console.log(`✅ All ${adminDead.size} dead selectors successfully removed.`);
  } else {
    console.log(`⚠️  ${adminRemaining} dead selectors remain in admin.css (may be inside @-rules).`);
  }
} catch (e) {
  console.log('⚠️  Could not auto-verify. Run manually: node tools/check-dead-css.js');
}
