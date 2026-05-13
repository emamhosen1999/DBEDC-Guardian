/**
 * @/compat/heroui — Radix UI compat shim for legacy HeroUI API
 * Maps HeroUI component names + prop conventions to Radix UI.
 */
import React from 'react';
import {
    Button as RadixButton,
    Badge as RadixBadge,
    Card as RadixCard,
    Box,
    Flex,
    Text,
    Separator,
    Dialog,
    Select as RadixSelect,
    TextField,
    TextArea as RadixTextArea,
    Checkbox as RadixCheckbox,
    Switch as RadixSwitch,
    Avatar as RadixAvatar,
    RadioGroup as RadixRadioGroup,
    DropdownMenu,
    Table as RadixTable,
    ScrollArea,
    Link as RadixLink,
} from '@radix-ui/themes';
import * as RadixTooltip from '@radix-ui/react-tooltip';

// ─── Color / Variant Maps ─────────────────────────────────────────────────────
const colorMap = {
    primary: 'indigo', success: 'green', danger: 'red',
    warning: 'orange', default: 'gray', secondary: 'violet',
    info: 'cyan',
};
const mapColor = (c) => colorMap[c] || c || 'gray';

const btnVariantMap = {
    solid: 'solid', bordered: 'outline', flat: 'soft',
    ghost: 'ghost', light: 'ghost', faded: 'soft',
};
const mapBtnVariant = (v) => btnVariantMap[v] || 'solid';

const sizeMap = { sm: '1', md: '2', lg: '3', xs: '1' };
const mapSize = (s) => sizeMap[s] || '2';

// ─── Button ───────────────────────────────────────────────────────────────────
export const Button = ({
    onPress, onClick, isDisabled, disabled, isLoading, loading,
    isIconOnly, startContent, endContent, color, variant, size,
    radius, classNames, className, children, style, type, form, ...props
}) => (
    <RadixButton
        type={type || 'button'}
        form={form}
        onClick={onPress || onClick}
        disabled={isDisabled || disabled}
        loading={isLoading || loading}
        color={mapColor(color)}
        variant={mapBtnVariant(variant)}
        size={mapSize(size)}
        style={style}
        {...props}
    >
        {startContent && <span style={{ display: 'inline-flex', alignItems: 'center' }}>{startContent}</span>}
        {!isIconOnly && children}
        {isIconOnly && children}
        {endContent && <span style={{ display: 'inline-flex', alignItems: 'center' }}>{endContent}</span>}
    </RadixButton>
);

// ─── IconButton ───────────────────────────────────────────────────────────────
export const IconButton = ({ onPress, onClick, isDisabled, disabled, color, variant, size, children, ...props }) => (
    <RadixButton
        onClick={onPress || onClick}
        disabled={isDisabled || disabled}
        color={mapColor(color)}
        variant={mapBtnVariant(variant)}
        size={mapSize(size)}
        {...props}
    >
        {children}
    </RadixButton>
);

// ─── ButtonGroup ──────────────────────────────────────────────────────────────
export const ButtonGroup = ({ children, ...props }) => (
    <Flex gap="1" {...props}>{children}</Flex>
);

// ─── Chip / Badge ─────────────────────────────────────────────────────────────
export const Chip = ({ children, color, variant, size, startContent, endContent, radius, classNames, className, style, onClick, onClose, ...props }) => (
    <RadixBadge
        color={mapColor(color)}
        variant={variant === 'flat' ? 'soft' : variant === 'solid' ? 'solid' : variant === 'bordered' ? 'outline' : 'soft'}
        size={size === 'sm' ? '1' : '2'}
        style={style}
        onClick={onClick}
        {...props}
    >
        {startContent && <span style={{ display: 'inline-flex' }}>{startContent}</span>}
        {children}
        {endContent && <span style={{ display: 'inline-flex' }}>{endContent}</span>}
    </RadixBadge>
);

export const Badge = ({ children, color, variant, content, size, ...props }) => (
    <span style={{ position: 'relative', display: 'inline-flex' }}>
        {children}
        {content !== undefined && (
            <RadixBadge color={mapColor(color)} size="1" style={{ position: 'absolute', top: -4, right: -4 }}>
                {content}
            </RadixBadge>
        )}
    </span>
);

// ─── Divider / Separator ─────────────────────────────────────────────────────
export const Divider = ({ orientation, className, style, ...props }) => (
    <Separator orientation={orientation || 'horizontal'} size="4" style={style} />
);

// ─── Spinner ──────────────────────────────────────────────────────────────────
export const Spinner = ({ size, color, ...props }) => {
    const sz = size === 'sm' ? 16 : size === 'lg' ? 32 : 20;
    return (
        <span style={{ display: 'inline-flex', width: sz, height: sz, border: '2px solid var(--gray-a6)', borderTopColor: 'var(--accent-9)', borderRadius: '50%', animation: 'spin 0.7s linear infinite' }} aria-label="Loading" role="status">
            <style>{`@keyframes spin{to{transform:rotate(360deg)}}`}</style>
        </span>
    );
};

// ─── Skeleton ────────────────────────────────────────────────────────────────
export const Skeleton = ({ className, style, ...props }) => (
    <span style={{ display: 'block', background: 'var(--gray-a4)', borderRadius: 'var(--radius-2)', animation: 'pulse 1.5s ease-in-out infinite', ...style }} {...props}>
        <style>{`@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}`}</style>
        &nbsp;
    </span>
);

// ─── Progress ────────────────────────────────────────────────────────────────
export const Progress = ({ value, color, size, label, 'aria-label': ariaLabel, className, ...props }) => (
    <div role="progressbar" aria-label={ariaLabel || label} aria-valuenow={value || 0} aria-valuemin={0} aria-valuemax={100}
        style={{ width: '100%', height: size === 'sm' ? 4 : 8, background: 'var(--gray-a4)', borderRadius: 99, overflow: 'hidden', ...props.style }}>
        <div style={{ height: '100%', width: `${Math.min(100, value || 0)}%`, background: `var(--${mapColor(color)}-9)`, borderRadius: 99, transition: 'width 0.3s' }} />
    </div>
);

// ─── CircularProgress ────────────────────────────────────────────────────────
export const CircularProgress = ({ value, color, size, label, 'aria-label': ariaLabel, ...props }) => {
    const r = 18, c = 2 * Math.PI * r;
    const pct = Math.min(100, value || 0);
    return (
        <svg width={48} height={48} viewBox="0 0 44 44" aria-label={ariaLabel || label}>
            <circle cx={22} cy={22} r={r} fill="none" stroke="var(--gray-a4)" strokeWidth={4} />
            <circle cx={22} cy={22} r={r} fill="none" stroke={`var(--${mapColor(color)}-9)`} strokeWidth={4}
                strokeDasharray={c} strokeDashoffset={c - (c * pct) / 100}
                strokeLinecap="round" transform="rotate(-90 22 22)" />
        </svg>
    );
};

// ─── Card ────────────────────────────────────────────────────────────────────
export const Card = ({ children, shadow, radius, isHoverable, isPressable, onPress, className, style, ...props }) => (
    <RadixCard style={style} onClick={onPress} {...props}>{children}</RadixCard>
);
export const CardHeader = ({ children, className, style, ...props }) => (
    <Box px="3" pt="3" pb="1" style={style} {...props}>{children}</Box>
);
export const CardBody = ({ children, className, style, ...props }) => (
    <Box px="3" py="3" style={style} {...props}>{children}</Box>
);
export const CardFooter = ({ children, className, style, ...props }) => (
    <Box px="3" pb="3" pt="1" style={style} {...props}>{children}</Box>
);

// ─── Modal / Dialog ──────────────────────────────────────────────────────────
export const Modal = ({ isOpen, onClose, children, size, scrollBehavior, backdrop, isDismissable, isKeyboardDismissDisabled, ...props }) => (
    <Dialog.Root open={!!isOpen} onOpenChange={(open) => { if (!open && onClose) onClose(); }}>
        {children}
    </Dialog.Root>
);

export const ModalContent = ({ children }) => {
    const content = typeof children === 'function' ? children(() => {}) : children;
    return (
        <Dialog.Content maxWidth="600px">
            {content}
        </Dialog.Content>
    );
};

export const ModalHeader = ({ children, className, style, ...props }) => (
    <Dialog.Title style={style}>{children}</Dialog.Title>
);

export const ModalBody = ({ children, className, style, ...props }) => (
    <Box py="3" style={style}>{children}</Box>
);

export const ModalFooter = ({ children, className, style, ...props }) => (
    <Flex justify="end" gap="2" pt="3" style={{ borderTop: '1px solid var(--gray-a4)', ...style }}>
        {children}
    </Flex>
);

// ─── Tooltip ─────────────────────────────────────────────────────────────────
export const Tooltip = ({ children, content, placement, delay, color, ...props }) => (
    <RadixTooltip.Root delayDuration={delay || 300}>
        <RadixTooltip.Trigger asChild>
            {children}
        </RadixTooltip.Trigger>
        <RadixTooltip.Content side={placement || 'top'}>
            {typeof content === 'string' ? <Text size="1">{content}</Text> : content}
        </RadixTooltip.Content>
    </RadixTooltip.Root>
);

// ─── Table (compound) ────────────────────────────────────────────────────────
const TableContext = React.createContext({ columnsRef: null });

export const Table = ({ children, selectionMode, selectedKeys, onSelectionChange, isCompact, removeWrapper, isStriped, isHeaderSticky, classNames, radius, style, ...props }) => {
    const columnsRef = React.useRef([]);
    return (
        <TableContext.Provider value={{ columnsRef, selectionMode, selectedKeys, onSelectionChange }}>
            <RadixTable.Root variant="surface" style={style} {...props}>
                {children}
            </RadixTable.Root>
        </TableContext.Provider>
    );
};

export const TableHeader = ({ columns: cols, children }) => {
    const { columnsRef } = React.useContext(TableContext);
    if (typeof children === 'function' && cols) {
        if (columnsRef) columnsRef.current = cols;
        return (
            <RadixTable.Header>
                <RadixTable.Row>
                    {cols.map(col => children(col))}
                </RadixTable.Row>
            </RadixTable.Header>
        );
    }
    return <RadixTable.Header><RadixTable.Row>{children}</RadixTable.Row></RadixTable.Header>;
};

export const TableColumn = ({ children, key: colKey, uid, align, allowsSorting, className, style, ...props }) => (
    <RadixTable.ColumnHeaderCell style={{ textAlign: align || 'left', ...style }}>
        {children}
    </RadixTable.ColumnHeaderCell>
);

export const TableBody = ({ items, children, emptyContent, loadingContent, isLoading }) => {
    if (isLoading && loadingContent) {
        return <RadixTable.Body><RadixTable.Row><RadixTable.Cell colSpan={99}>{loadingContent}</RadixTable.Cell></RadixTable.Row></RadixTable.Body>;
    }
    if (typeof children === 'function' && items) {
        return (
            <RadixTable.Body>
                {items.length > 0
                    ? items.map(item => children(item))
                    : emptyContent
                        ? <RadixTable.Row><RadixTable.Cell colSpan={99}>{emptyContent}</RadixTable.Cell></RadixTable.Row>
                        : null}
            </RadixTable.Body>
        );
    }
    return <RadixTable.Body>{children}</RadixTable.Body>;
};

export const TableRow = ({ children, key: rowKey, className, style, ...props }) => {
    const { columnsRef } = React.useContext(TableContext);
    if (typeof children === 'function') {
        const cols = columnsRef?.current || [];
        return (
            <RadixTable.Row style={style}>
                {cols.map(col => {
                    const uid = col.uid || col.key || col;
                    return <React.Fragment key={uid}>{children(uid)}</React.Fragment>;
                })}
            </RadixTable.Row>
        );
    }
    return <RadixTable.Row style={style} {...props}>{children}</RadixTable.Row>;
};

export const TableCell = ({ children, className, style, ...props }) => (
    <RadixTable.Cell style={style}>{children}</RadixTable.Cell>
);

// ─── Select ──────────────────────────────────────────────────────────────────
export const Select = ({
    children, selectedKeys, onSelectionChange, value, onChange,
    renderValue, label, placeholder, variant, size, radius,
    classNames, isDisabled, disabled, defaultSelectedKeys,
    'aria-label': ariaLabel, style, ...props
}) => {
    const selectedKey = selectedKeys
        ? (selectedKeys instanceof Set ? Array.from(selectedKeys)[0] : selectedKeys[0]) || ''
        : (value || '');

    const handleChange = (newVal) => {
        if (onSelectionChange) onSelectionChange(new Set([newVal]));
        if (onChange) onChange(newVal);
    };

    return (
        <RadixSelect.Root
            value={selectedKey}
            onValueChange={handleChange}
            disabled={isDisabled || disabled}
            defaultValue={defaultSelectedKeys ? Array.from(defaultSelectedKeys)[0] : undefined}
        >
            <RadixSelect.Trigger
                variant={variant === 'bordered' ? 'surface' : variant === 'ghost' ? 'ghost' : 'classic'}
                placeholder={placeholder || label || 'Select...'}
                style={style}
                aria-label={ariaLabel || label}
            />
            <RadixSelect.Content>{children}</RadixSelect.Content>
        </RadixSelect.Root>
    );
};

export const SelectItem = ({ children, key: itemKey, value: itemValue, textValue, ...props }) => (
    <RadixSelect.Item value={String(itemKey || itemValue || children)}>
        {children}
    </RadixSelect.Item>
);

export const SelectSection = ({ children, title, ...props }) => (
    <RadixSelect.Group>
        {title && <RadixSelect.Label>{title}</RadixSelect.Label>}
        {children}
    </RadixSelect.Group>
);

// ─── Input ───────────────────────────────────────────────────────────────────
export const Input = ({
    label, placeholder, value, defaultValue, onChange,
    isInvalid, errorMessage, isDisabled, disabled, isReadOnly, readOnly,
    type, size, variant, radius, classNames, className,
    startContent, endContent, description, min, max, step,
    style, 'aria-label': ariaLabel, ...props
}) => (
    <Box style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        {label && <Text size="1" color="gray" as="label">{label}</Text>}
        <TextField.Root
            value={value}
            defaultValue={defaultValue}
            onChange={onChange}
            placeholder={placeholder || label || ''}
            disabled={isDisabled || disabled}
            readOnly={isReadOnly || readOnly}
            type={type || 'text'}
            min={min}
            max={max}
            step={step}
            aria-label={ariaLabel || label}
            variant={isInvalid ? 'surface' : (variant === 'bordered' ? 'surface' : variant === 'ghost' ? 'soft' : 'classic')}
            color={isInvalid ? 'red' : undefined}
            style={style}
        >
            {startContent && <TextField.Slot>{startContent}</TextField.Slot>}
            {endContent && <TextField.Slot side="right">{endContent}</TextField.Slot>}
        </TextField.Root>
        {isInvalid && errorMessage && <Text size="1" color="red">{errorMessage}</Text>}
        {description && !isInvalid && <Text size="1" color="gray">{description}</Text>}
    </Box>
);

// ─── Textarea ────────────────────────────────────────────────────────────────
export const Textarea = ({
    label, placeholder, value, defaultValue, onChange,
    isInvalid, errorMessage, isDisabled, disabled, rows, style, ...props
}) => (
    <Box style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        {label && <Text size="1" color="gray" as="label">{label}</Text>}
        <RadixTextArea
            value={value}
            defaultValue={defaultValue}
            onChange={onChange}
            placeholder={placeholder || label || ''}
            disabled={isDisabled || disabled}
            rows={rows}
            style={style}
        />
        {isInvalid && errorMessage && <Text size="1" color="red">{errorMessage}</Text>}
    </Box>
);

// ─── Checkbox ────────────────────────────────────────────────────────────────
export const Checkbox = ({ children, isSelected, checked, onValueChange, onChange, onCheckedChange, isDisabled, disabled, value, color, size, ...props }) => (
    <Flex align="center" gap="2" as="label">
        <RadixCheckbox
            checked={isSelected !== undefined ? isSelected : checked}
            onCheckedChange={onValueChange || onCheckedChange || onChange}
            disabled={isDisabled || disabled}
            value={value}
            color={mapColor(color)}
            size={sizeMap[size] || '2'}
        />
        {children && <Text size="2">{children}</Text>}
    </Flex>
);

// ─── Switch ───────────────────────────────────────────────────────────────────
export const Switch = ({ children, isSelected, checked, onValueChange, onChange, isDisabled, disabled, color, size, thumbIcon, classNames, ...props }) => (
    <Flex align="center" gap="2" as="label">
        <RadixSwitch
            checked={isSelected !== undefined ? isSelected : checked}
            onCheckedChange={onValueChange || onChange}
            disabled={isDisabled || disabled}
            color={mapColor(color)}
            size={sizeMap[size] || '2'}
        />
        {children && <Text size="2">{children}</Text>}
    </Flex>
);

// ─── RadioGroup / Radio ───────────────────────────────────────────────────────
export const RadioGroup = ({ children, value, onValueChange, onChange, isDisabled, disabled, label, orientation, ...props }) => (
    <RadixRadioGroup.Root
        value={value}
        onValueChange={onValueChange || onChange}
        disabled={isDisabled || disabled}
        orientation={orientation || 'vertical'}
    >
        {label && <Text size="2" weight="medium" mb="2">{label}</Text>}
        {children}
    </RadixRadioGroup.Root>
);

export const Radio = ({ children, value, isDisabled, disabled, description, ...props }) => (
    <Flex gap="2" align="center" as="label">
        <RadixRadioGroup.Item value={value} disabled={isDisabled || disabled} />
        <Box>
            <Text size="2">{children}</Text>
            {description && <Text size="1" color="gray">{description}</Text>}
        </Box>
    </Flex>
);

// ─── Avatar ───────────────────────────────────────────────────────────────────
export const Avatar = ({ src, name, size, radius, showFallback, fallback, isBordered, color, ...props }) => (
    <RadixAvatar
        src={src}
        fallback={fallback || name?.[0]?.toUpperCase() || '?'}
        size={sizeMap[size] || '3'}
        radius={radius === 'sm' ? 'small' : radius === 'full' ? 'full' : 'medium'}
        color={mapColor(color)}
        {...props}
    />
);

// ─── User (HeroUI compound Avatar+Name) ──────────────────────────────────────
export const User = ({ name, description, avatarProps, classNames, ...props }) => (
    <Flex align="center" gap="2">
        {avatarProps && <RadixAvatar src={avatarProps.src} fallback={name?.[0] || '?'} size="2" />}
        <Box>
            <Text size="2" weight="medium">{name}</Text>
            {description && <Text size="1" color="gray">{description}</Text>}
        </Box>
    </Flex>
);

// ─── ScrollShadow ─────────────────────────────────────────────────────────────
export const ScrollShadow = ({ children, className, style, orientation, size, ...props }) => (
    <div style={{ overflow: 'auto', ...style }} {...props}>{children}</div>
);

// ─── Pagination ───────────────────────────────────────────────────────────────
export const Pagination = ({ page, total, onChange, showControls, size, color, variant, radius, classNames, style, ...props }) => {
    if (!total || total <= 1) return null;
    return (
        <Flex gap="2" align="center" justify="center" style={style}>
            <RadixButton variant="outline" size="1" onClick={() => onChange && onChange(Math.max(1, page - 1))} disabled={page <= 1}>
                ‹ Prev
            </RadixButton>
            <Text size="2" color="gray">{page} / {total}</Text>
            <RadixButton variant="outline" size="1" onClick={() => onChange && onChange(Math.min(total, page + 1))} disabled={page >= total}>
                Next ›
            </RadixButton>
        </Flex>
    );
};

// ─── Dropdown (compat → DropdownMenu) ────────────────────────────────────────
export const Dropdown = ({ children, placement, ...props }) => (
    <DropdownMenu.Root>{children}</DropdownMenu.Root>
);
export const DropdownTrigger = ({ children, ...props }) => (
    <DropdownMenu.Trigger asChild>{children}</DropdownMenu.Trigger>
);
export { DropdownMenu };
export const DropdownItem = ({ children, key: itemKey, startContent, endContent, color, description, onPress, onClick, isDisabled, href, ...props }) => (
    <DropdownMenu.Item
        color={color === 'danger' ? 'red' : undefined}
        onClick={onPress || onClick}
        disabled={isDisabled}
        {...(href ? { asChild: true } : {})}
    >
        {startContent && <span style={{ display: 'inline-flex' }}>{startContent}</span>}
        {children}
    </DropdownMenu.Item>
);
export const DropdownSection = ({ children, title, showDivider, ...props }) => (
    <DropdownMenu.Group>
        {title && <DropdownMenu.Label>{title}</DropdownMenu.Label>}
        {children}
        {showDivider && <DropdownMenu.Separator />}
    </DropdownMenu.Group>
);

// ─── Accordion ───────────────────────────────────────────────────────────────
export const Accordion = ({ children, type, defaultExpandedKeys, selectedKeys, onSelectionChange, variant, ...props }) => (
    <div>{children}</div>
);
export const AccordionItem = ({ children, key: itemKey, title, subtitle, startContent, indicator, ...props }) => (
    <details style={{ borderBottom: '1px solid var(--gray-a4)' }}>
        <summary style={{ padding: '8px 0', cursor: 'pointer', listStyle: 'none', display: 'flex', alignItems: 'center', gap: 8 }}>
            {startContent}
            <span style={{ fontWeight: 500 }}>{title}</span>
            {subtitle && <span style={{ fontSize: '0.75rem', color: 'var(--gray-11)' }}>{subtitle}</span>}
        </summary>
        <div style={{ paddingBottom: 8 }}>{children}</div>
    </details>
);

// ─── Link ─────────────────────────────────────────────────────────────────────
export const Link = ({ children, href, isExternal, color, size, underline, onPress, onClick, ...props }) => (
    <RadixLink href={href || '#'} target={isExternal ? '_blank' : undefined}
        rel={isExternal ? 'noopener noreferrer' : undefined}
        color={mapColor(color)}
        onClick={onPress || onClick}
        {...props}
    >
        {children}
    </RadixLink>
);

// ─── Image ───────────────────────────────────────────────────────────────────
export const Image = ({ src, alt, width, height, radius, isBlurred, className, style, ...props }) => (
    <img src={src} alt={alt || ''} width={width} height={height} style={{ borderRadius: radius === 'full' ? '50%' : undefined, ...style }} />
);

// ─── Breadcrumbs ─────────────────────────────────────────────────────────────
export const Breadcrumbs = ({ children, ...props }) => (
    <nav aria-label="Breadcrumb">
        <Flex gap="1" align="center" wrap="wrap">{children}</Flex>
    </nav>
);
export const BreadcrumbItem = ({ children, href, isCurrent, ...props }) => (
    <Text size="1" color={isCurrent ? undefined : 'gray'}>
        {href && !isCurrent ? <RadixLink href={href}>{children}</RadixLink> : children}
        {!isCurrent && <span style={{ marginLeft: 4, color: 'var(--gray-8)' }}>/</span>}
    </Text>
);

// ─── Tabs ─────────────────────────────────────────────────────────────────────
export const Tabs = ({ children, selectedKey, onSelectionChange, variant, color, radius, classNames, style, ...props }) => (
    <div style={style}>{children}</div>
);
export const Tab = ({ children, key: tabKey, title, ...props }) => (
    <div>{children}</div>
);
export const TabsContent = ({ children, ...props }) => <div>{children}</div>;

// ─── Navbar (stub) ────────────────────────────────────────────────────────────
export const Navbar = ({ children, ...props }) => <nav>{children}</nav>;
export const NavbarBrand = ({ children, ...props }) => <div>{children}</div>;
export const NavbarContent = ({ children, ...props }) => <div>{children}</div>;
export const NavbarItem = ({ children, ...props }) => <div>{children}</div>;
export const NavbarMenu = ({ children, ...props }) => <div>{children}</div>;
export const NavbarMenuItem = ({ children, ...props }) => <div>{children}</div>;
export const NavbarMenuToggle = ({ icon, ...props }) => <button type="button" {...props}>{icon}</button>;

// ─── Misc passthrough stubs ───────────────────────────────────────────────────
export const Popover = ({ children, ...p }) => <div>{children}</div>;
export const PopoverTrigger = ({ children, ...p }) => <>{children}</>;
export const PopoverContent = ({ children, ...p }) => <div>{children}</div>;
export const DatePicker = (p) => <input type="date" value={p.value || ''} onChange={p.onChange} />;
export const DateRangePicker = (p) => null;
export const NumberInput = ({ value, onChange, ...p }) => <input type="number" value={value || ''} onChange={onChange} />;
export const Listbox = ({ children, ...p }) => <ul>{children}</ul>;
export const ListboxItem = ({ children, ...p }) => <li>{children}</li>;
export const DropdownMenuCompound = DropdownMenu;
export const HeroUIProvider = ({ children }) => <>{children}</>;

// ─── CheckboxGroup ────────────────────────────────────────────────────────────
export const CheckboxGroup = ({ children, label, value, onValueChange, onChange, isDisabled, disabled, orientation, ...props }) => (
    <Box>
        {label && <Text size="2" weight="medium" mb="1">{label}</Text>}
        <Flex direction={orientation === 'horizontal' ? 'row' : 'column'} gap="2">
            {React.Children.map(children, child =>
                React.isValidElement(child)
                    ? React.cloneElement(child, {
                        isSelected: value ? value.includes(child.props.value) : child.props.isSelected,
                        onValueChange: (checked) => {
                            if (onValueChange) {
                                const newVal = checked
                                    ? [...(value || []), child.props.value]
                                    : (value || []).filter(v => v !== child.props.value);
                                onValueChange(newVal);
                            }
                        },
                    })
                    : child
            )}
        </Flex>
    </Box>
);

// ─── Chip extras ─────────────────────────────────────────────────────────────
export const ChipGroup = ({ children, ...props }) => <Flex gap="1" wrap="wrap">{children}</Flex>;

// ─── Table misc ───────────────────────────────────────────────────────────────
export const TableSelectAllCheckbox = () => null;

// ─── Form ─────────────────────────────────────────────────────────────────────
export const Form = ({ children, onSubmit, ...props }) => <form onSubmit={onSubmit} {...props}>{children}</form>;

// ─── Spacer ───────────────────────────────────────────────────────────────────
export const Spacer = ({ x, y, ...props }) => <div style={{ width: x ? `${x * 4}px` : undefined, height: y ? `${y * 4}px` : undefined }} />;

// ─── Snippet ──────────────────────────────────────────────────────────────────
export const Snippet = ({ children, ...props }) => (
    <Box p="2" style={{ background: 'var(--gray-a3)', borderRadius: 'var(--radius-2)', fontFamily: 'monospace' }}>
        <Text size="1">{children}</Text>
    </Box>
);

// ─── Calendar stub ────────────────────────────────────────────────────────────
export const Calendar = (props) => null;
export const RangeCalendar = (props) => null;

// ─── Hooks ────────────────────────────────────────────────────────────────────
export const useDisclosure = (defaultOpen = false) => {
    const [isOpen, setIsOpen] = React.useState(defaultOpen);
    return {
        isOpen,
        onOpen: () => setIsOpen(true),
        onClose: () => setIsOpen(false),
        onOpenChange: (open) => setIsOpen(open),
    };
};

// ─── Extra stubs ──────────────────────────────────────────────────────────────
export const SelectSection2 = SelectSection;
export const CircularProgressBar = CircularProgress;
export const InputOtp = ({ ...p }) => <input type="text" pattern="[0-9]*" inputMode="numeric" maxLength={p.maxLength} />;
export const Autocomplete = Input;
export const AutocompleteItem = SelectItem;
