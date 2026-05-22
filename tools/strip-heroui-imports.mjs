/**
 * Replace @/compat/heroui imports with @radix-ui/themes and common prop renames.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const targets = process.argv.slice(2);

const files = targets.length
    ? targets.map((f) => path.resolve(root, f))
    : [];

function walk(dir, acc = []) {
    for (const name of fs.readdirSync(dir)) {
        const p = path.join(dir, name);
        if (name === 'compat' || name === 'node_modules') continue;
        const st = fs.statSync(p);
        if (st.isDirectory()) walk(p, acc);
        else if (/\.(jsx|js)$/.test(name) && fs.readFileSync(p, 'utf8').includes('@/compat/heroui')) acc.push(p);
    }
    return acc;
}

const fileList = files.length ? files : walk(path.join(root, 'resources', 'js'));

const RADIX_DEFAULT = `import {
    Box, Flex, Grid, Text, Heading, Button, IconButton, Card, Separator,
    Dialog, AlertDialog, Select, TextField, TextArea, Checkbox, Switch,
    RadioGroup, Radio, Badge, Spinner, Skeleton, ScrollArea, Table,
    Tabs, Tooltip, DropdownMenu, Progress, Callout, Inset,
} from '@radix-ui/themes';\n`;

for (const filePath of fileList) {
    let src = fs.readFileSync(filePath, 'utf8');
    if (!src.includes('@/compat/heroui')) continue;

    src = src.replace(/import\s*\{[^}]*\}\s*from\s*['"]@\/compat\/heroui['"];?\n?/g, '');
    src = RADIX_DEFAULT + src;

    src = src.replace(/\bonPress=/g, 'onClick=');
    src = src.replace(/\bisDisabled=/g, 'disabled=');
    src = src.replace(/\bisLoading=/g, 'loading=');
    src = src.replace(/\bcolor="danger"/g, 'color="red"');
    src = src.replace(/\bcolor="default"/g, 'color="gray"');
    src = src.replace(/\bvariant="bordered"/g, 'variant="outline"');
    src = src.replace(/\bvariant="flat"/g, 'variant="soft"');
    src = src.replace(/\bModal\b/g, 'Dialog');
    src = src.replace(/\bModalContent\b/g, 'Dialog.Content');
    src = src.replace(/\bModalHeader\b/g, 'Dialog.Title');
    src = src.replace(/\bModalBody\b/g, 'Box');
    src = src.replace(/\bModalFooter\b/g, 'Flex');
    src = src.replace(/\bisOpen=/g, 'open=');
    src = src.replace(/\bDivider\b/g, 'Separator');
    src = src.replace(/\bChip\b/g, 'Badge');
    src = src.replace(/\bInput\b/g, 'TextField.Root');
    src = src.replace(/\bCircularProgress\b/g, 'Spinner');

    fs.writeFileSync(filePath, src);
    console.log('updated', path.relative(root, filePath));
}
