// © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt.
// dev/unused.js (local tool, never shipped): lists, or removes, the top-level declarations of src/app.jsx that nothing reaches.
//   NODE_PATH=dev/node_modules node dev/unused.js            → list (name, line, size)
//   NODE_PATH=dev/node_modules node dev/unused.js --remove   → remove them (repeats until nothing more is unused)
// Reachability starts at every top-level statement that is not a plain declaration (they run when the app loads) and at
// declarations whose value is not a function, object, array or plain value (they could do something when created).
// References are counted by name, ignoring scope, so a name used anywhere keeps its declaration (it errs on keeping).
const fs = require('fs'), path = require('path');
const Babel = require('@babel/standalone');
const FILE = path.resolve(__dirname, '..', 'src', 'app.jsx');
const PURE = new Set(['FunctionExpression', 'ArrowFunctionExpression', 'ObjectExpression', 'ArrayExpression', 'StringLiteral',
  'NumericLiteral', 'BooleanLiteral', 'NullLiteral', 'TemplateLiteral', 'ClassExpression']);

function analyse(src) {
  const ast = Babel.transform(src, { ast: true, code: false, babelrc: false, configFile: false, parserOpts: { plugins: ['jsx'] } }).ast;
  const items = [];
  for (const st of ast.program.body) {
    let names = [], pure = false;
    if (st.type === 'FunctionDeclaration' || st.type === 'ClassDeclaration') { names = [st.id.name]; pure = true; }
    else if (st.type === 'VariableDeclaration' && st.declarations.every(d => d.id.type === 'Identifier')) {
      names = st.declarations.map(d => d.id.name); pure = st.declarations.every(d => !d.init || PURE.has(d.init.type));
    }
    const refs = new Set(); walk(st, refs, names);
    items.push({ st, names, pure, refs, start: st.start, end: st.end, line: st.loc.start.line });
  }
  return items;
}
function walk(n, refs, own) {
  if (!n || typeof n.type !== 'string') return;
  if (n.type === 'Identifier' || n.type === 'JSXIdentifier') refs.add(n.name);
  for (const k of Object.keys(n)) {
    if (k === 'loc' || k === 'start' || k === 'end' || k === 'leadingComments' || k === 'trailingComments') continue;
    const v = n[k];
    if (Array.isArray(v)) v.forEach(x => walk(x, refs, own)); else if (v && typeof v.type === 'string') walk(v, refs, own);
  }
}
function unused(items) {
  const byName = new Map(); items.forEach(it => it.names.forEach(nm => byName.set(nm, it)));
  const seen = new Set(), todo = items.filter(it => !it.pure || it.names.length === 0);
  todo.forEach(it => seen.add(it));
  while (todo.length) {
    const it = todo.pop();
    for (const r of it.refs) { const d = byName.get(r); if (d && d !== it && !seen.has(d)) { seen.add(d); todo.push(d); } }
  }
  return items.filter(it => !seen.has(it));
}
let src = fs.readFileSync(FILE, 'utf8');
if (process.argv.includes('--remove')) {
  let total = 0, round = 0;
  for (;;) {
    const dead = unused(analyse(src)); if (!dead.length) break; round++; total += dead.length;
    // cut whole lines: from the start of the declaration's line to the end of its last line, with comments right above it
    dead.sort((a, b) => b.start - a.start);
    for (const it of dead) {
      let s = src.lastIndexOf('\n', it.start - 1) + 1; let e = src.indexOf('\n', it.end); e = e < 0 ? src.length : e + 1;
      if (src.slice(s, it.start).trim() !== '') s = it.start;   // something else on the same line before it: keep that
      if (src.slice(it.end, e).trim() !== '' && !/^\s*(\/\/.*|\/\*.*\*\/\s*)?$/.test(src.slice(it.end, e))) e = it.end;
      src = src.slice(0, s) + src.slice(e);
    }
    console.log('round ' + round + ': removed ' + dead.length + ' — ' + dead.map(d => d.names.join('/')).join(', '));
  }
  fs.writeFileSync(FILE, src); console.log('removed ' + total + ' top-level declarations');
} else {
  const dead = unused(analyse(src)).sort((a, b) => a.line - b.line);
  for (const d of dead) console.log(String(d.line).padStart(6), String(d.end - d.start).padStart(7), d.names.join(', '));
  console.log(dead.length + ' unused top-level declarations (' + dead.reduce((a, d) => a + d.end - d.start, 0) + ' bytes); repeat after removing them for the next layer');
}
