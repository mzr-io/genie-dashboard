import { computed, nextTick, reactive, ref, useId } from 'vue';

export type FieldRule = {
    value: () => unknown;
    // Returns the error message, or null/undefined when valid.
    validate: (value: unknown) => string | null | undefined;
    label?: string;
};

export type SummaryHandle = { focus: () => void } | null;

// Validation runs on blur and on submit, never per keystroke (UX-DR-23, 274). Submit focuses
// the only invalid field, or an error summary of links when there are two or more.
export function useBlurValidation(rules: Record<string, FieldRule>) {
    const prefix = useId();
    const errors = reactive<Record<string, string | null>>({});
    const summary = ref<SummaryHandle>(null);
    const showSummary = ref(false);

    function fieldId(name: string): string {
        return `${prefix}-${name}`;
    }

    function check(name: string): boolean {
        const rule = rules[name];

        if (!rule) {
            return true;
        }

        errors[name] = rule.validate(rule.value()) ?? null;

        return errors[name] === null;
    }

    // The summary stays only while two or more errors remain.
    function syncSummary(): void {
        if (Object.values(errors).filter(Boolean).length < 2) {
            showSummary.value = false;
        }
    }

    function onBlur(name: string): void {
        check(name);
        syncSummary();
    }

    function focusField(name: string): void {
        document.getElementById(fieldId(name))?.focus();
    }

    const summaryItems = computed(() =>
        Object.keys(rules)
            .filter((name) => errors[name])
            .map((name) => ({
                id: fieldId(name),
                label: rules[name].label ?? name,
                message: errors[name] as string,
            })),
    );

    // Returns true when every field is valid; otherwise moves focus.
    async function validateAll(): Promise<boolean> {
        const invalid = Object.keys(rules).filter((name) => !check(name));

        showSummary.value = invalid.length >= 2;

        if (invalid.length === 0) {
            return true;
        }

        await nextTick();

        if (invalid.length === 1) {
            focusField(invalid[0]);
        } else {
            summary.value?.focus();
        }

        return false;
    }

    let submitting = false;

    async function submit(onValid: () => void | Promise<void>): Promise<void> {
        if (submitting) {
            return;
        }

        submitting = true;

        try {
            if (await validateAll()) {
                await onValid();
            }
        } finally {
            submitting = false;
        }
    }

    return {
        errors,
        fieldId,
        onBlur,
        validateAll,
        submit,
        focusField,
        summary,
        showSummary,
        summaryItems,
    };
}
