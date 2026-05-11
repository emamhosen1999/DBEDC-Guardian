/**
 * HeroUI → Radix UI compatibility shim.
 * Re-exports Radix Themes primitives under HeroUI names so un-migrated files
 * keep compiling. Props are loosely mapped; unknown HeroUI props are dropped.
 */
import React from 'react';
import {
  Box, Button as RxBtn, Card as RxCard, Checkbox as RxCheckbox,
  Dialog, DropdownMenu, Flex, Heading, IconButton as RxIconBtn,
  ScrollArea as RxScroll, Select as RxSelect, Separator, Skeleton as RxSkeleton,
  Switch as RxSwitch, Tabs as RxTabs, Text, TextArea as RxTextArea,
  TextField, Tooltip as RxTooltip, Badge as RxBadge, RadioGroup as RxRadio,
  Slider as RxSlider, Table,
} from '@radix-ui/themes';

// ─── helpers ─────────────────────────────────────────────────────────────────
const heroVariant = (v) =>
  v === 'flat' ? 'soft' : v === 'bordered' ? 'outline' : v === 'light' ? 'ghost' : v || 'solid';
const heroSize = (s) => s === 'lg' ? '3' : s === 'sm' ? '1' : s === 'xs' ? '1' : '2';

// ─── Button ──────────────────────────────────────────────────────────────────
export const Button = React.forwardRef(({
  children, variant, color, size, isDisabled, disabled, isLoading,
  startContent, endContent, onPress, onClick, style, href, as: Tag, ...rest
}, ref) => {
  const p = { ref, variant: heroVariant(variant), color, size: heroSize(size), disabled: isDisabled || disabled, onClick: onPress || onClick, style };
  if (href || Tag === 'a') return <RxBtn {...p} asChild><a href={href}>{startContent}{children}{endContent}</a></RxBtn>;
  return <RxBtn {...p}>{startContent}{children}{endContent}</RxBtn>;
});
Button.displayName = 'Button';

// ─── Card ────────────────────────────────────────────────────────────────────
export const Card = React.forwardRef(({ children, shadow, isPressable, onPress, style, ...rest }, ref) => (
  <RxCard ref={ref} style={style} onClick={onPress}>{children}</RxCard>
));
Card.displayName = 'Card';
export const CardBody   = ({ children, ...r }) => <Box p="3" {...r}>{children}</Box>;
export const CardHeader = ({ children, ...r }) => <Box px="3" pt="3" {...r}>{children}</Box>;
export const CardFooter = ({ children, ...r }) => <Box px="3" pb="3" {...r}>{children}</Box>;

// ─── Input ───────────────────────────────────────────────────────────────────
export const Input = React.forwardRef(({
  label, placeholder, value, onChange, onValueChange, type, size, variant,
  startContent, endContent, isDisabled, disabled, errorMessage, isInvalid,
  isClearable, description, classNames, className, style, ...rest
}, ref) => (
  <Flex direction="column" gap="1" style={style}>
    {label && <Text as="label" size="1" weight="medium">{label}</Text>}
    <TextField.Root
      ref={ref} placeholder={placeholder} value={value} type={type}
      size={heroSize(size)} disabled={isDisabled || disabled}
      color={isInvalid ? 'red' : undefined}
      onChange={e => { onChange?.(e); onValueChange?.(e.target.value); }}
    >
      {startContent && <TextField.Slot>{startContent}</TextField.Slot>}
      {endContent && <TextField.Slot side="right">{endContent}</TextField.Slot>}
    </TextField.Root>
    {description && <Text size="1" color="gray">{description}</Text>}
    {isInvalid && errorMessage && <Text size="1" color="red">{errorMessage}</Text>}
  </Flex>
));
Input.displayName = 'Input';

// ─── Textarea ────────────────────────────────────────────────────────────────
export const Textarea = React.forwardRef(({
  label, placeholder, value, onChange, onValueChange, isDisabled, disabled,
  errorMessage, isInvalid, description, classNames, minRows, style, ...rest
}, ref) => (
  <Flex direction="column" gap="1" style={style}>
    {label && <Text as="label" size="1" weight="medium">{label}</Text>}
    <RxTextArea ref={ref} placeholder={placeholder} value={value} disabled={isDisabled || disabled}
      rows={minRows || 3}
      onChange={e => { onChange?.(e); onValueChange?.(e.target.value); }} />
    {isInvalid && errorMessage && <Text size="1" color="red">{errorMessage}</Text>}
    {description && <Text size="1" color="gray">{description}</Text>}
  </Flex>
));
Textarea.displayName = 'Textarea';

// ─── Select ──────────────────────────────────────────────────────────────────
export const Select = ({ label, children, selectedKeys, onSelectionChange, defaultSelectedKeys, isDisabled, disabled, placeholder, size, style, ...rest }) => {
  const value = selectedKeys instanceof Set ? [...selectedKeys][0] : selectedKeys;
  const defaultValue = defaultSelectedKeys instanceof Set ? [...defaultSelectedKeys][0] : defaultSelectedKeys;
  return (
    <Flex direction="column" gap="1" style={style}>
      {label && <Text as="label" size="1" weight="medium">{label}</Text>}
      <RxSelect.Root value={value} defaultValue={defaultValue} disabled={isDisabled || disabled} onValueChange={v => onSelectionChange?.(new Set([v]))}>
        <RxSelect.Trigger placeholder={placeholder} style={{ width: '100%' }} />
        <RxSelect.Content>{children}</RxSelect.Content>
      </RxSelect.Root>
    </Flex>
  );
};
export const SelectItem = ({ children, key: k, value, ...rest }) => (
  <RxSelect.Item value={value ?? k ?? String(children)}>{children}</RxSelect.Item>
);
export const SelectSection = ({ children, title, ...rest }) => (
  <RxSelect.Group>
    {title && <RxSelect.Label>{title}</RxSelect.Label>}
    {children}
  </RxSelect.Group>
);

// ─── Modal / Dialog ──────────────────────────────────────────────────────────
export const Modal = ({ children, isOpen, onClose, onOpenChange, size, placement, scrollBehavior, isDismissable = true, ...rest }) => (
  <Dialog.Root open={isOpen} onOpenChange={v => { if (!v) onClose?.(); onOpenChange?.(v); }}>
    {children}
  </Dialog.Root>
);
export const ModalContent = ({ children, ...rest }) => (
  <Dialog.Content style={{ maxWidth: 520 }}>
    {typeof children === 'function' ? children(() => {}) : children}
  </Dialog.Content>
);
export const ModalHeader = ({ children, ...rest }) => <Dialog.Title>{children}</Dialog.Title>;
export const ModalBody = ({ children, ...rest }) => <Box py="2">{children}</Box>;
export const ModalFooter = ({ children, ...rest }) => <Flex justify="end" gap="2" pt="2">{children}</Flex>;
export const useDisclosure = (initial = false) => {
  const [isOpen, setIsOpen] = React.useState(initial);
  return { isOpen, onOpen: () => setIsOpen(true), onClose: () => setIsOpen(false), onOpenChange: setIsOpen };
};

// ─── Dropdown ────────────────────────────────────────────────────────────────
export const Dropdown = ({ children, ...rest }) => <DropdownMenu.Root>{children}</DropdownMenu.Root>;
export const DropdownTrigger = ({ children, ...rest }) => <DropdownMenu.Trigger>{children}</DropdownMenu.Trigger>;
export const DropdownMenu_ = ({ children, onAction, 'aria-label': al, ...rest }) => (
  <DropdownMenu.Content>{children}</DropdownMenu.Content>
);
export { DropdownMenu_ as DropdownMenu };
export const DropdownItem = ({ children, key: k, color, startContent, endContent, description, onPress, onClick, ...rest }) => (
  <DropdownMenu.Item color={color} onClick={onPress || onClick}>
    {startContent}{children}{endContent}
  </DropdownMenu.Item>
);
export const DropdownSection = ({ children, title, showDivider, ...rest }) => (
  <>
    {title && <DropdownMenu.Label>{title}</DropdownMenu.Label>}
    {children}
    {showDivider && <DropdownMenu.Separator />}
  </>
);

// ─── Tooltip ─────────────────────────────────────────────────────────────────
export const Tooltip = ({ children, content, placement, ...rest }) => (
  <RxTooltip content={content}>{children}</RxTooltip>
);

// ─── Badge ───────────────────────────────────────────────────────────────────
export const Badge = ({ children, content, color, variant, size, shape, placement, ...rest }) => {
  const inner = content !== undefined ? content : children;
  return <RxBadge color={color} variant={heroVariant(variant)} size={heroSize(size)}>{inner}</RxBadge>;
};

// ─── Chip ────────────────────────────────────────────────────────────────────
export const Chip = ({ children, color, variant, size, startContent, endContent, onClose, ...rest }) => (
  <RxBadge color={color} variant={heroVariant(variant)} size={heroSize(size)}>
    {startContent}{children}{endContent}
  </RxBadge>
);

// ─── Avatar ──────────────────────────────────────────────────────────────────
export const Avatar = ({ src, name, size, color, isBordered, showFallback, fallback, ...rest }) => {
  const s = heroSize(size);
  const initials = name ? name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase() : '?';
  return src
    ? <img src={src} alt={name || 'avatar'} style={{ width: s === '3' ? 40 : s === '1' ? 24 : 32, height: s === '3' ? 40 : s === '1' ? 24 : 32, borderRadius: '50%', objectFit: 'cover' }} />
    : <Flex align="center" justify="center" style={{ width: s === '3' ? 40 : s === '1' ? 24 : 32, height: s === '3' ? 40 : s === '1' ? 24 : 32, borderRadius: '50%', background: 'var(--accent-9)', color: '#fff', fontSize: 13, fontWeight: 600 }}>{initials}</Flex>;
};
Avatar.displayName = 'Avatar';
export const AvatarGroup = ({ children, max, ...rest }) => (
  <Flex align="center" gap="1">{Array.isArray(children) ? children.slice(0, max ?? children.length) : children}</Flex>
);

// ─── Divider ─────────────────────────────────────────────────────────────────
export const Divider = ({ orientation, className, ...rest }) => (
  <Separator orientation={orientation || 'horizontal'} size="4" />
);

// ─── ScrollShadow ────────────────────────────────────────────────────────────
export const ScrollShadow = ({ children, className, style, hideScrollBar, size, ...rest }) => (
  <RxScroll style={style}>{children}</RxScroll>
);

// ─── Tabs ────────────────────────────────────────────────────────────────────
export const Tabs = ({ children, selectedKey, defaultSelectedKey, onSelectionChange, variant, color, size, fullWidth, ...rest }) => (
  <RxTabs.Root value={selectedKey} defaultValue={defaultSelectedKey} onValueChange={onSelectionChange}>
    {children}
  </RxTabs.Root>
);
export const Tab = ({ children, key: k, title, ...rest }) => (
  <RxTabs.Content value={k ?? title}>{children}</RxTabs.Content>
);
export const TabsContent = RxTabs.Content;

// ─── Accordion ───────────────────────────────────────────────────────────────
export const Accordion = ({ children, ...rest }) => <Box>{children}</Box>;
export const AccordionItem = ({ children, title, subtitle, startContent, ...rest }) => (
  <Box style={{ borderBottom: '1px solid var(--gray-a4)' }}>
    <details>
      <summary style={{ padding: '8px 0', cursor: 'pointer', listStyle: 'none', display: 'flex', alignItems: 'center', gap: 8 }}>
        {startContent}{title}{subtitle && <Text size="1" color="gray">{subtitle}</Text>}
      </summary>
      <Box pb="2">{children}</Box>
    </details>
  </Box>
);

// ─── Switch ──────────────────────────────────────────────────────────────────
export const Switch = ({ children, isSelected, defaultSelected, onValueChange, onChange, isDisabled, color, size, ...rest }) => (
  <Flex align="center" gap="2">
    <RxSwitch checked={isSelected} defaultChecked={defaultSelected} disabled={isDisabled}
      onCheckedChange={v => { onValueChange?.(v); onChange?.(v); }} color={color} />
    {children && <Text size="2">{children}</Text>}
  </Flex>
);

// ─── Checkbox ────────────────────────────────────────────────────────────────
export const Checkbox = ({ children, isSelected, defaultSelected, onValueChange, onChange, isDisabled, color, size, isIndeterminate, ...rest }) => (
  <Flex as="label" align="center" gap="2">
    <RxCheckbox checked={isSelected} defaultChecked={defaultSelected} disabled={isDisabled}
      onCheckedChange={v => { onValueChange?.(v); onChange?.(v); }} color={color} />
    {children && <Text size="2">{children}</Text>}
  </Flex>
);

// ─── Skeleton ────────────────────────────────────────────────────────────────
export const Skeleton = ({ children, isLoaded, className, style, ...rest }) =>
  isLoaded ? <>{children}</> : <RxSkeleton style={style}>{children || <Box style={{ height: 20, ...style }} />}</RxSkeleton>;

// ─── Spinner ─────────────────────────────────────────────────────────────────
export const Spinner = ({ size, color, label, ...rest }) => (
  <Flex align="center" gap="2">
    <Box style={{
      width: size === 'sm' ? 16 : size === 'lg' ? 32 : 24,
      height: size === 'sm' ? 16 : size === 'lg' ? 32 : 24,
      border: '2px solid var(--accent-a6)',
      borderTop: '2px solid var(--accent-9)',
      borderRadius: '50%',
      animation: 'spin 0.75s linear infinite',
    }} />
    {label && <Text size="1" color="gray">{label}</Text>}
  </Flex>
);

// ─── Progress ────────────────────────────────────────────────────────────────
export const Progress = ({ value, color, size, label, showValueLabel, minValue = 0, maxValue = 100, ...rest }) => {
  const pct = Math.min(100, Math.max(0, ((value - minValue) / (maxValue - minValue)) * 100));
  return (
    <Flex direction="column" gap="1">
      {label && <Flex justify="between"><Text size="1">{label}</Text>{showValueLabel && <Text size="1">{Math.round(pct)}%</Text>}</Flex>}
      <Box style={{ height: size === 'sm' ? 4 : 8, background: 'var(--gray-a5)', borderRadius: 99, overflow: 'hidden' }}>
        <Box style={{ width: `${pct}%`, height: '100%', background: `var(--${color ?? 'accent'}-9)`, transition: 'width 300ms' }} />
      </Box>
    </Flex>
  );
};

// ─── Slider ──────────────────────────────────────────────────────────────────
export const Slider = ({ value, defaultValue, onChange, onChangeEnd, minValue = 0, maxValue = 100, step = 1, label, color, isDisabled, ...rest }) => (
  <Flex direction="column" gap="1">
    {label && <Text size="1" weight="medium">{label}</Text>}
    <RxSlider value={[value ?? defaultValue ?? minValue]} min={minValue} max={maxValue} step={step} disabled={isDisabled}
      onValueChange={([v]) => onChange?.(v)} onValueCommit={([v]) => onChangeEnd?.(v)} color={color} />
  </Flex>
);

// ─── Table ───────────────────────────────────────────────────────────────────
export const Table_ = ({ children, 'aria-label': al, selectionMode, onSelectionChange, selectedKeys, classNames, ...rest }) => (
  <Table.Root>{children}</Table.Root>
);
export { Table_ as Table };
export const TableHeader = ({ children, columns, ...rest }) => <Table.Header>{children}</Table.Header>;
export const TableBody = ({ children, items, emptyContent, loadingContent, isLoading, ...rest }) => {
  if (isLoading && loadingContent) return <Table.Body><Table.Row><Table.Cell colSpan={99}>{loadingContent}</Table.Cell></Table.Row></Table.Body>;
  const hasChildren = React.Children.count(children) > 0 || (items && items.length > 0);
  if (!hasChildren && emptyContent) return <Table.Body><Table.Row><Table.Cell colSpan={99}><Flex align="center" justify="center" py="6">{emptyContent}</Flex></Table.Cell></Table.Row></Table.Body>;
  return <Table.Body>{children}</Table.Body>;
};
export const TableRow = ({ children, ...rest }) => <Table.Row>{children}</Table.Row>;
export const TableCell = ({ children, ...rest }) => <Table.Cell>{children}</Table.Cell>;
export const TableColumn = ({ children, ...rest }) => <Table.ColumnHeaderCell>{children}</Table.ColumnHeaderCell>;

// ─── Navbar (passthrough structure) ─────────────────────────────────────────
export const Navbar = ({ children, ...rest }) => <Box as="nav" style={{ display: 'flex', alignItems: 'center', width: '100%' }}>{children}</Box>;
export const NavbarBrand = ({ children, ...rest }) => <Box style={{ flex: 1 }}>{children}</Box>;
export const NavbarContent = ({ children, justify, ...rest }) => <Flex align="center" gap="2" style={{ marginLeft: justify === 'end' ? 'auto' : undefined }}>{children}</Flex>;
export const NavbarItem = ({ children, ...rest }) => <Box>{children}</Box>;
export const NavbarMenuToggle = ({ onClick, ...rest }) => <button onClick={onClick} style={{ background: 'none', border: 'none', cursor: 'pointer' }}>☰</button>;

// ─── Pagination ──────────────────────────────────────────────────────────────
export const Pagination = ({ total, page, onChange, color, size, showControls = true, boundaries = 1, siblings = 1, ...rest }) => {
  const btnSize = size === 'sm' ? '1' : '2';
  const pages = [];
  const range = (start, end) => Array.from({ length: end - start + 1 }, (_, i) => start + i);
  const leftBound = Math.max(1, page - siblings);
  const rightBound = Math.min(total, page + siblings);
  const leftPages = range(1, Math.min(boundaries, leftBound - 2));
  const rightPages = range(Math.max(total - boundaries + 1, rightBound + 2), total);
  const middlePages = range(leftBound, rightBound);
  const showLeftDots = leftBound - boundaries > 2;
  const showRightDots = total - rightBound - boundaries > 1;
  return (
    <Flex align="center" gap="1" wrap="wrap">
      <RxBtn variant="ghost" size={btnSize} disabled={page <= 1} onClick={() => onChange?.(page - 1)}>‹</RxBtn>
      {leftPages.map(p => <RxBtn key={p} variant={p === page ? 'solid' : 'ghost'} size={btnSize} onClick={() => onChange?.(p)}>{p}</RxBtn>)}
      {showLeftDots && <Text size="1" color="gray">…</Text>}
      {middlePages.map(p => <RxBtn key={p} variant={p === page ? 'solid' : 'ghost'} size={btnSize} onClick={() => onChange?.(p)}>{p}</RxBtn>)}
      {showRightDots && <Text size="1" color="gray">…</Text>}
      {rightPages.map(p => <RxBtn key={p} variant={p === page ? 'solid' : 'ghost'} size={btnSize} onClick={() => onChange?.(p)}>{p}</RxBtn>)}
      <RxBtn variant="ghost" size={btnSize} disabled={page >= total} onClick={() => onChange?.(page + 1)}>›</RxBtn>
    </Flex>
  );
};

// ─── RadioGroup ──────────────────────────────────────────────────────────────
export const RadioGroup = ({ children, label, value, defaultValue, onValueChange, isDisabled, orientation, ...rest }) => (
  <RxRadio.Root value={value} defaultValue={defaultValue} disabled={isDisabled} onValueChange={onValueChange} style={{ display: 'flex', flexDirection: orientation === 'horizontal' ? 'row' : 'column', gap: 8 }}>
    {label && <Text size="2" weight="medium">{label}</Text>}
    {children}
  </RxRadio.Root>
);
export const Radio = ({ children, value, ...rest }) => (
  <Flex as="label" align="center" gap="2">
    <RxRadio.Item value={value} />
    <Text size="2">{children}</Text>
  </Flex>
);

// ─── Breadcrumbs ─────────────────────────────────────────────────────────────
export const Breadcrumbs = ({ children, ...rest }) => (
  <Flex as="nav" aria-label="Breadcrumb" align="center" gap="1" style={{ fontSize: 13 }}>
    {children}
  </Flex>
);
export const BreadcrumbItem = ({ children, href, isCurrent, ...rest }) => (
  <>
    {isCurrent
      ? <Text size="2" color={isCurrent ? undefined : 'gray'}>{children}</Text>
      : <><a href={href} style={{ color: 'var(--accent-11)', textDecoration: 'none' }}><Text size="2" color="accent">{children}</Text></a><Text size="2" color="gray" mx="1">/</Text></>
    }
  </>
);

// ─── Link ────────────────────────────────────────────────────────────────────
export const Link = ({ children, href, color, isExternal, ...rest }) => (
  <a href={href} target={isExternal ? '_blank' : undefined} rel={isExternal ? 'noopener noreferrer' : undefined}
    style={{ color: `var(--${color ?? 'accent'}-11)`, textDecoration: 'none' }}>{children}</a>
);

// ─── Miscellaneous passthroughs ──────────────────────────────────────────────
export const Code = ({ children, color, size, ...rest }) => (
  <Text as="code" size={heroSize(size)} style={{ fontFamily: 'monospace', background: 'var(--gray-a3)', padding: '1px 4px', borderRadius: 'var(--radius-1)' }}>{children}</Text>
);
export const Image = ({ src, alt, width, height, className, style, ...rest }) => (
  <img src={src} alt={alt} width={width} height={height} style={style} className={className} />
);
export const User = ({ name, description, avatarProps, ...rest }) => (
  <Flex align="center" gap="2">
    <Avatar {...(avatarProps || {})} name={name} />
    <Flex direction="column"><Text size="2" weight="medium">{name}</Text>{description && <Text size="1" color="gray">{description}</Text>}</Flex>
  </Flex>
);
export const Popover = ({ children, ...rest }) => <>{children}</>;
export const PopoverTrigger = ({ children, ...rest }) => <>{children}</>;
export const PopoverContent = ({ children, ...rest }) => <RxCard>{children}</RxCard>;
export const DatePicker = ({ label, ...rest }) => <Input label={label} type="date" {...rest} />;
export const DateRangePicker = ({ label, ...rest }) => <Input label={label} type="date" {...rest} />;
export const Listbox = ({ children, ...rest }) => <Box>{children}</Box>;
export const ListboxItem = ({ children, key: k, ...rest }) => <Box style={{ padding: '4px 8px', cursor: 'pointer' }}>{children}</Box>;
export const cn = (...classes) => classes.filter(Boolean).join(' ');

// ─── ButtonGroup ─────────────────────────────────────────────────────────────
export const ButtonGroup = ({ children, variant, radius, className, style, ...rest }) => (
  <Flex gap="0" style={{ display: 'inline-flex', ...style }} {...rest}>{children}</Flex>
);

// ─── Spacer ──────────────────────────────────────────────────────────────────
export const Spacer = ({ x = 1, y = 1, ...rest }) => (
  <Box style={{ width: `${x * 4}px`, height: `${y * 4}px`, flexShrink: 0 }} />
);

// ─── NavbarMenu / NavbarMenuItem ─────────────────────────────────────────────
export const NavbarMenu = ({ children, ...rest }) => (
  <Box style={{ position: 'absolute', top: '100%', left: 0, right: 0, background: 'var(--color-panel-solid)', borderBottom: '1px solid var(--gray-a4)', padding: 'var(--space-3)', zIndex: 100 }}>
    {children}
  </Box>
);
export const NavbarMenuItem = ({ children, isActive, ...rest }) => (
  <Box style={{ padding: '6px 0', color: isActive ? 'var(--accent-11)' : undefined }}>{children}</Box>
);

// ─── Tab list wrapper (HeroUI uses Tabs for both list and panels) ─────────────
export const TabList = ({ children, ...rest }) => <RxTabs.List>{children}</RxTabs.List>;
export const TabTrigger = ({ children, value, ...rest }) => <RxTabs.Trigger value={value}>{children}</RxTabs.Trigger>;


// ─── CheckboxGroup ───────────────────────────────────────────────────────────
export const CheckboxGroup = ({ children, label, value, onValueChange, isDisabled, orientation, ...rest }) => (
  <Flex direction="column" gap="2">
    {label && <Text size="2" weight="medium">{label}</Text>}
    <Flex direction={orientation === 'horizontal' ? 'row' : 'column'} gap="2">{children}</Flex>
  </Flex>
);

// ─── ToggleButton / misc ─────────────────────────────────────────────────────
export const ToggleButton = ({ children, isSelected, onValueChange, ...rest }) => (
  <RxBtn variant={isSelected ? 'solid' : 'ghost'} onClick={() => onValueChange?.(!isSelected)} {...rest}>{children}</RxBtn>
);


// ─── Autocomplete stub ───────────────────────────────────────────────────────
export const Autocomplete = ({ label, placeholder, onInputChange, onSelectionChange, children, defaultInputValue, inputValue, ...rest }) => (
  <Input label={label} placeholder={placeholder} value={inputValue ?? defaultInputValue} onValueChange={onInputChange} {...rest} />
);
export const AutocompleteItem = ({ children, key: k, value, ...rest }) => null;

// ─── NumberInput ─────────────────────────────────────────────────────────────
export const NumberInput = (props) => <Input {...props} type="number" />;

// ─── Form ────────────────────────────────────────────────────────────────────
export const Form = ({ children, onSubmit, ...rest }) => <form onSubmit={onSubmit} {...rest}>{children}</form>;

// ─── CircularProgress ────────────────────────────────────────────────────────
export const CircularProgress = ({ value = 0, maxValue = 100, minValue = 0, color, size, label, showValueLabel, ...rest }) => {
  const pct = Math.min(100, Math.max(0, ((value - minValue) / (maxValue - minValue)) * 100));
  const r = size === 'sm' ? 14 : size === 'lg' ? 26 : 20;
  const c = 2 * Math.PI * r;
  const dash = (pct / 100) * c;
  return (
    <Flex direction="column" align="center" gap="1">
      <svg width={r * 2 + 8} height={r * 2 + 8} viewBox={`0 0 ${r * 2 + 8} ${r * 2 + 8}`}>
        <circle cx={r + 4} cy={r + 4} r={r} fill="none" stroke="var(--gray-a5)" strokeWidth="4" />
        <circle cx={r + 4} cy={r + 4} r={r} fill="none" stroke={`var(--${color ?? 'accent'}-9)`} strokeWidth="4"
          strokeDasharray={`${dash} ${c}`} strokeLinecap="round"
          transform={`rotate(-90 ${r + 4} ${r + 4})`} style={{ transition: 'stroke-dasharray 300ms' }} />
        {showValueLabel && <text x="50%" y="50%" dominantBaseline="middle" textAnchor="middle" fontSize={r * 0.55} fill="currentColor">{Math.round(pct)}%</text>}
      </svg>
      {label && <Text size="1" color="gray">{label}</Text>}
    </Flex>
  );
};

// ─── Calendar stub ───────────────────────────────────────────────────────────
export const Calendar = ({ value, onChange, ...rest }) => (
  <Box style={{ border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-2)', padding: 'var(--space-3)' }}>
    <input type="date" value={value} onChange={e => onChange?.(e.target.value)}
      style={{ border: 'none', background: 'transparent', fontSize: 14, color: 'inherit' }} />
  </Box>
);

// ─── RangeCalendar stub ──────────────────────────────────────────────────────
export const RangeCalendar = ({ value, onChange, ...rest }) => <Calendar value={value?.start} onChange={v => onChange?.({ start: v, end: v })} />;

// ─── TimeInput stub ──────────────────────────────────────────────────────────
export const TimeInput = (props) => <Input {...props} type="time" />;
