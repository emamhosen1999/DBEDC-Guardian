import React, { useCallback, memo } from 'react';
import { useTranslation } from '@/Contexts/TranslationContext';
import { Box, DropdownMenu, Flex, IconButton, Text } from '@radix-ui/themes';
import { GlobeIcon, CheckIcon } from '@radix-ui/react-icons';

const FLAG_MAP = { en:'us', bn:'bd', ar:'sa', es:'es', fr:'fr', de:'de', hi:'in', 'zh-CN':'cn', 'zh-TW':'tw' };

const FlagIcon = memo(({ code, size = 20 }) => {
    const country = FLAG_MAP[code] || 'us';
    return (
        <img
            src={`https://hatscripts.github.io/circle-flags/flags/${country}.svg`}
            alt=""
            loading="lazy"
            style={{ width: size, height: size, borderRadius: '50%', objectFit: 'cover', flexShrink: 0, display: 'block' }}
        />
    );
});

/**
 * Language configuration with native names
 */
const languageConfig = {
    en: {
        name: 'English',
        nativeName: 'English',
        dir: 'ltr',
    },
    bn: {
        name: 'Bengali',
        nativeName: 'বাংলা',
        dir: 'ltr',
    },
    ar: {
        name: 'Arabic',
        nativeName: 'العربية',
        dir: 'rtl',
    },
    es: {
        name: 'Spanish',
        nativeName: 'Español',
        dir: 'ltr',
    },
    fr: {
        name: 'French',
        nativeName: 'Français',
        dir: 'ltr',
    },
    de: {
        name: 'German',
        nativeName: 'Deutsch',
        dir: 'ltr',
    },
    hi: {
        name: 'Hindi',
        nativeName: 'हिन्दी',
        dir: 'ltr',
    },
    'zh-CN': {
        name: 'Chinese (Simplified)',
        nativeName: '简体中文',
        dir: 'ltr',
    },
    'zh-TW': {
        name: 'Chinese (Traditional)',
        nativeName: '繁體中文',
        dir: 'ltr',
    },
};

const LanguageSwitcher = memo(function LanguageSwitcher({ showFlag = true, showNativeName = true }) {
    const { locale, setLocale, supportedLocales } = useTranslation();

    const availableLocales = supportedLocales?.length > 0 ? supportedLocales : ['en', 'bn'];

    const handleLocaleChange = useCallback((newLocale) => {
        if (newLocale !== locale) setLocale(newLocale);
    }, [locale, setLocale]);

    const currentLang = languageConfig[locale] || languageConfig.en;

    return (
        <DropdownMenu.Root>
            <DropdownMenu.Trigger>
                <IconButton variant="ghost" color="gray" size="2" aria-label="Change language">
                    {showFlag ? <FlagIcon code={locale} size={18} /> : <GlobeIcon />}
                </IconButton>
            </DropdownMenu.Trigger>
            <DropdownMenu.Content align="end" style={{ minWidth: 200 }}>
                {availableLocales.map((code) => {
                    const lang = languageConfig[code];
                    if (!lang) return null;
                    const isActive = locale === code;
                    return (
                        <DropdownMenu.Item
                            key={code}
                            onSelect={() => handleLocaleChange(code)}
                            style={{ cursor: 'pointer' }}
                        >
                            <Flex align="center" gap="2" style={{ width: '100%' }}>
                                <FlagIcon code={code} size={18} />
                                <Box style={{ flex: 1, minWidth: 0 }}>
                                    <Text size="2" weight={isActive ? 'bold' : 'regular'} color={isActive ? 'accent' : undefined} style={{ display: 'block' }}>
                                        {lang.nativeName}
                                    </Text>
                                    {lang.name !== lang.nativeName && (
                                        <Text size="1" color="gray" style={{ display: 'block' }}>{lang.name}</Text>
                                    )}
                                </Box>
                                {isActive && <CheckIcon style={{ color: 'var(--accent-9)', flexShrink: 0 }} />}
                            </Flex>
                        </DropdownMenu.Item>
                    );
                })}
            </DropdownMenu.Content>
        </DropdownMenu.Root>
    );
});

export default LanguageSwitcher;
export { languageConfig };
