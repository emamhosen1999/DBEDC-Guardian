import fs from 'node:fs';
const p = 'resources/js/Pages/Administration/SystemMonitoringEnhanced.jsx';
let s = fs.readFileSync(p, 'utf8');

const reps = [
    [/<Typography variant="h4"([^>]*)>/g, '<Heading size="6"$1>'],
    [/<Typography variant="h3"([^>]*)>/g, '<Heading size="7"$1>'],
    [/<Typography variant="h6"([^>]*)>/g, '<Heading size="4"$1>'],
    [/<Typography variant="subtitle2"([^>]*)>/g, '<Text size="2" color="gray"$1>'],
    [/<Typography variant="caption"([^>]*)>/g, '<Text size="1" color="gray"$1>'],
    [/<Typography variant="body2"([^>]*)>/g, '<Text size="2"$1>'],
    [/<Grow in[^>]*>/g, '<Box>'],
    [/<\/Grow>/g, '</Box>'],
    [/<Paper([^>]*)>/g, '<Card$1>'],
    [/<\/Paper>/g, '</Card>'],
    [/<TableContainer>/g, '<ScrollArea scrollbars="horizontal"><Table.Root variant="surface">'],
    [/<\/TableContainer>/g, '</Table.Root></ScrollArea>'],
    [/<TableHead>/g, '<Table.Header>'],
    [/<\/TableHead>/g, '</Table.Header>'],
    [/<TableBody>/g, '<Table.Body>'],
    [/<\/TableBody>/g, '</Table.Body>'],
    [/<TableRow/g, '<Table.Row'],
    [/<\/TableRow>/g, '</Table.Row>'],
    [/<TableCell/g, '<Table.Cell'],
    [/<\/TableCell>/g, '</Table.Cell>'],
];
for (const [a, b] of reps) s = s.replace(a, b);

// Close Heading tags that were closed with </Text>
s = s.replace(/<Heading size="6"([^>]*)>([\s\S]*?)<\/Text>/g, '<Heading size="6"$1>$2</Heading>');
s = s.replace(/<Heading size="7"([^>]*)>([\s\S]*?)<\/Text>/g, '<Heading size="7"$1>$2</Heading>');
s = s.replace(/<Heading size="4"([^>]*)>([\s\S]*?)<\/Text>/g, '<Heading size="4"$1>$2</Heading>');

// Remaining Typography -> Text
s = s.replace(/<Typography([^>]*)>/g, '<Text$1>');
s = s.replace(/<\/Typography>/g, '</Text>');

if (!s.includes('Heading')) {
    s = s.replace(
        "from '@radix-ui/themes';\n",
        "from '@radix-ui/themes';\n"
    );
}
if (!s.match(/import[\s\S]*Heading/)) {
    s = s.replace(
        /(import \{[^}]*)(}\s*from '@radix-ui\/themes';)/,
        '$1, Heading, Callout$2'
    );
}

s = s.replace(/<Heading([^>]*)>([\s\S]*?)<\/Text>/g, '<Heading$1>$2</Heading>');

s = s.replace(/<Alert\s+([^>]*?)severity="[^"]*"\s*/g, '<Callout.Root ');
s = s.replace(/<Alert\s+/g, '<Callout.Root ');
s = s.replace(/<\/Alert>/g, '</Callout.Root>');
s = s.replace(/<Callout\.Root([^>]*)>([\s\S]*?)<\/Callout\.Root>/g, (m, attrs, body) => {
    const inner = body.trim();
    return `<Callout.Root${attrs}><Callout.Text>${inner}</Callout.Text></Callout.Root>`;
});

fs.writeFileSync(p, s);
console.log('SystemMonitoring typography fixed');
