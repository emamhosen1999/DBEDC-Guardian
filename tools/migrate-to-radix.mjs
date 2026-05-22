/**
 * One-time codemod: @/compat/heroui -> @radix-ui/themes + common HeroUI prop renames.
 * Run: node tools/migrate-to-radix.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const jsRoot = path.join(root, 'resources', 'js');

const HEROUI_TO_RADIX = {
    Button: 'Button',
    IconButton: 'IconButton',
    Box: 'Box',
    Flex: 'Flex',
    Grid: 'Grid',
    Text: 'Text',
    Heading: 'Heading',
    Card: 'Card',
    Separator: 'Separator',
    Divider: 'Separator',
    Spinner: 'Spinner',
    Skeleton: 'Skeleton',
    Badge: 'Badge',
    Chip: 'Badge',
    Progress: 'Progress',
    Dialog: 'Dialog',
    Modal: 'Dialog',
    ModalContent: 'Dialog.Content',
    ModalHeader: 'Dialog.Title',
    ModalBody: 'Box',
    ModalFooter: 'Flex',
    AlertDialog: 'AlertDialog',
    Select: 'Select',
    SelectItem: 'Select.Item',
    TextField: 'TextField',
    Input: 'TextField',
    Textarea: 'TextArea',
    Checkbox: 'Checkbox',
    Switch: 'Switch',
    RadioGroup: 'RadioGroup',
    Radio: 'Radio',
    Avatar: 'Avatar',
    ScrollArea: 'ScrollArea',
    Table: 'Table',
    Tabs: 'Tabs',
    Tab: 'Tabs.Trigger',
    Tooltip: 'Tooltip',
    DropdownMenu: 'DropdownMenu',
    Accordion: 'Accordion',
    AccordionItem: 'Accordion.Item',
    Pagination: 'Flex',
    CardHeader: 'Box',
    CardBody: 'Box',
    CardFooter: 'Box',
    Callout: 'Callout',
};

function walk(dir, acc = []) {
    for (const name of fs.readdirSync(dir)) {
        const p = path.join(dir, name);
        if (name === 'compat' || name === 'node_modules') continue;
        const st = fs.statSync(p);
        if (st.isDirectory()) walk(p, acc);
        else if (/\.(jsx|js)$/.test(name)) acc.push(p);
    }
    return acc;
}

function migrateFile(filePath) {
    let src = fs.readFileSync(filePath, 'utf8');
    if (!src.includes('@/compat/heroui') && !src.includes('@mui/')) return false;

    if (src.includes('@mui/')) return false; // MUI files handled manually

    if (!src.includes('@/compat/heroui')) return false;

    const importMatch = src.match(/import\s*\{([^}]+)\}\s*from\s*['"]@\/compat\/heroui['"];?/g);
    if (!importMatch) return false;

    const symbols = new Set();
    for (const block of importMatch) {
        const inner = block.match(/\{([^}]+)\}/)[1];
        inner.split(',').forEach((part) => {
            const m = part.trim().match(/(?:\w+\s+as\s+)?(\w+)/);
            if (m) symbols.add(m[1]);
        });
    }

    const radixSymbols = new Set();
    for (const sym of symbols) {
        const mapped = HEROUI_TO_RADIX[sym] || sym;
        mapped.split('.').forEach((s) => radixSymbols.add(s));
        radixSymbols.add(sym);
    }

    const radixList = [...radixSymbols].filter((s) =>
        ['Button', 'IconButton', 'Box', 'Flex', 'Grid', 'Text', 'Heading', 'Card', 'Separator',
            'Spinner', 'Skeleton', 'Badge', 'Progress', 'Dialog', 'AlertDialog', 'Select', 'TextField',
            'TextArea', 'Checkbox', 'Switch', 'RadioGroup', 'Radio', 'Avatar', 'ScrollArea', 'Table',
            'Tabs', 'Tooltip', 'DropdownMenu', 'Accordion', 'Callout', 'Inset', 'Strong', 'Em'].includes(s)
        || s.startsWith('Dialog.') || s.startsWith('Select.') || s.startsWith('Tabs.') || s.startsWith('Accordion.')
    );

    const unique = [...new Set(radixList)].sort();

    src = src.replace(/import\s*\{[^}]*\}\s*from\s*['"]@\/compat\/heroui['"];?\n?/g, '');
    const radixImport = `import {\n    ${unique.join(',\n    ')},\n} from '@radix-ui/themes';\n`;
    src = radixImport + src;

    src = src.replace(/\bonPress=/g, 'onClick=');
    src = src.replace(/\bisDisabled=/g, 'disabled=');
    src = src.replace(/\bisLoading=/g, 'loading=');
    src = src.replace(/\bcolor="danger"/g, 'color="red"');
    src = src.replace(/\bcolor="default"/g, 'color="gray"');
    src = src.replace(/\bvariant="bordered"/g, 'variant="outline"');
    src = src.replace(/\bvariant="flat"/g, 'variant="soft"');

    fs.writeFileSync(filePath, src);
    return true;
}

let n = 0;
for (const f of walk(jsRoot)) {
    if (migrateFile(f)) {
        n++;
        console.log('migrated', path.relative(root, f));
    }
}
console.log(`Done. ${n} files updated.`);
