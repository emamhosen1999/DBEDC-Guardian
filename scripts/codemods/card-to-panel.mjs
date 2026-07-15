// Codemod: swap @radix-ui/themes (and @nextui-org/react) <Card> usage for the
// flat <Panel> primitive. Pure string transform + a small CLI.
//   node scripts/codemods/card-to-panel.mjs <file...>
// transformSource is exported for unit testing.

const PANEL_IMPORT = `import { Panel } from '@/Components/ui/Panel';`;

export function transformSource(source) {
  let src = source;
  const hadCard = /<Card[\s/>]|<Card\.|<CardBody|<CardHeader/.test(src);

  // 1) Tag swaps — dotted + Body/Header variants first, then the bare tag.
  src = src
    .replace(/<Card\.Body/g, '<Panel.Body').replace(/<\/Card\.Body>/g, '</Panel.Body>')
    .replace(/<Card\.Header/g, '<Panel.Header').replace(/<\/Card\.Header>/g, '</Panel.Header>')
    .replace(/<CardBody/g, '<Panel.Body').replace(/<\/CardBody>/g, '</Panel.Body>')
    .replace(/<CardHeader/g, '<Panel.Header').replace(/<\/CardHeader>/g, '</Panel.Header>')
    .replace(/<Card(\s|>|\/)/g, '<Panel$1').replace(/<\/Card>/g, '</Panel>');

  // 2) Strip Card / CardBody / CardHeader from radix + nextui named imports.
  const stripFrom = (full, names, q, mod) => {
    const kept = names
      .split(',')
      .map((s) => s.trim())
      .filter((n) => n && !['Card', 'CardBody', 'CardHeader'].includes(n));
    if (kept.length === 0) return '';
    return `import { ${kept.join(', ')} } from ${q}${mod}${q};`;
  };
  src = src.replace(
    /import\s*\{([^}]*)\}\s*from\s*(['"])(@radix-ui\/themes|@nextui-org\/react)\2;?/g,
    (full, names, q, mod) => stripFrom(full, names, q, mod)
  );

  // Collapse a line left completely blank by a dropped import (not the first line).
  src = src.replace(/([^\n])\n[ \t]*\n[ \t]*\n/g, '$1\n\n');

  // 3) Inject the Panel import once if a Card tag was present and it's not already imported.
  if (hadCard && !src.includes('Components/ui/Panel')) {
    src = `${PANEL_IMPORT}\n${src}`;
  }
  return src;
}

if (process.argv[1] && process.argv[1].replace(/\\/g, '/').endsWith('card-to-panel.mjs')) {
  const fs = await import('node:fs');
  let changed = 0;
  for (const f of process.argv.slice(2)) {
    const before = fs.readFileSync(f, 'utf8');
    const after = transformSource(before);
    if (after !== before) {
      fs.writeFileSync(f, after);
      changed += 1;
      console.log('rewrote', f);
    }
  }
  console.log(`\n${changed} file(s) changed`);
}
