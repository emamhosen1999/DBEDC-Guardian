import { describe, it, expect } from 'vitest';
import { transformSource } from './card-to-panel.mjs';

describe('card-to-panel transform', () => {
  it('swaps Card tags for Panel', () => {
    const out = transformSource(`<Card><CardBody>x</CardBody></Card>`);
    expect(out).toContain('<Panel>');
    expect(out).toContain('<Panel.Body>');
    expect(out).not.toMatch(/<Card[ >]/);
  });

  it('strips Card from a shared radix import and adds the Panel import', () => {
    const out = transformSource(`import { Box, Card, Flex } from '@radix-ui/themes';\n<Card>a</Card>`);
    expect(out).toContain(`import { Box, Flex } from '@radix-ui/themes';`);
    expect(out).toContain(`import { Panel } from '@/Components/ui/Panel';`);
  });

  it('drops the radix import line when Card was its only member', () => {
    const out = transformSource(`import { Card } from '@radix-ui/themes';\n<Card>a</Card>`);
    expect(out).not.toContain('@radix-ui/themes');
    expect(out).toContain(`import { Panel } from '@/Components/ui/Panel';`);
  });

  it('does not duplicate an existing Panel import', () => {
    const out = transformSource(
      `import { Panel } from '@/Components/ui/Panel';\nimport { Card } from '@radix-ui/themes';\n<Card>a</Card>`
    );
    expect(out.match(/Components\/ui\/Panel/g)).toHaveLength(1);
  });

  it('handles self-closing cards', () => {
    expect(transformSource(`<Card className="x" />`)).toContain('<Panel className="x" />');
  });

  it('also strips Card from a nextui import', () => {
    const out = transformSource(`import { Card, Button } from '@nextui-org/react';\n<Card>a</Card>`);
    expect(out).toContain(`import { Button } from '@nextui-org/react';`);
    expect(out).toContain(`import { Panel } from '@/Components/ui/Panel';`);
  });

  it('leaves non-card sources untouched', () => {
    const src = `import { Box } from '@radix-ui/themes';\n<Box/>`;
    expect(transformSource(src)).toBe(src);
  });
});
