import { createI18n } from 'vue-i18n';
import en from '@/locales/en';

export const DEFAULT_LOCALE = 'en';

export function createCatalogue() {
    return createI18n({
        legacy: false,
        locale: DEFAULT_LOCALE,
        fallbackLocale: DEFAULT_LOCALE,
        messages: { en },
    });
}
