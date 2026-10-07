<script setup lang="ts">
import { computed } from 'vue';
import ListStates from '@/components/ListStates.vue';
import { helpLabels as labels, shellPages } from '@/locales/labels';

// Help & support (Story 1.18): the links the Workspace Admin configured and the contact line. The server
// already drops anything that is not an http or https link; the page checks again, and renders every label
// as escaped text. "Contact your workspace administrator" links only to an address the Admin configured and
// is plain text otherwise, so no admin address is ever guessed at.
type HelpLink = { label: string; url: string };

const props = defineProps<{
    helpLinks: HelpLink[];
    contactHref: string | null;
}>();

function webAddress(url: string): boolean {
    try {
        return ['http:', 'https:'].includes(new URL(url).protocol);
    } catch {
        return false;
    }
}

function contactAddress(url: string | null): string | null {
    if (!url) {
        return null;
    }

    return webAddress(url) || /^mailto:[^\s<>"]+$/i.test(url) ? url : null;
}

const links = computed(() =>
    props.helpLinks.filter(
        (link) =>
            typeof link.label === 'string' &&
            link.label.trim() !== '' &&
            typeof link.url === 'string' &&
            webAddress(link.url),
    ),
);
const contact = computed(() => contactAddress(props.contactHref));
const newTab = (href: string) => /^https?:/i.test(href);
</script>

<template>
    <div class="flex flex-col gap-6" data-slot="help-content">
        <section class="flex flex-col gap-3" aria-labelledby="help-links">
            <h2 id="help-links" class="type-title-sm text-text-primary">
                {{ labels.linksHeading }}
            </h2>
            <ul
                v-if="links.length > 0"
                class="flex flex-col gap-2"
                data-test="help-links"
            >
                <li
                    v-for="(link, index) in links"
                    :key="`${index}-${link.url}`"
                >
                    <a
                        :href="link.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="type-body-md font-semibold text-accent-ink underline underline-offset-4"
                        data-test="help-link"
                        >{{ link.label
                        }}<span class="sr-only">
                            {{ labels.opensInNewTab }}</span
                        ></a
                    >
                </li>
            </ul>
            <ListStates
                v-else
                state="empty"
                :items="shellPages.help.items"
                :action="shellPages.help.action"
            />
        </section>

        <section class="flex flex-col gap-2" aria-labelledby="help-contact">
            <h2 id="help-contact" class="type-title-sm text-text-primary">
                {{ labels.contactHeading }}
            </h2>
            <a
                v-if="contact"
                :href="contact"
                :target="newTab(contact) ? '_blank' : undefined"
                :rel="newTab(contact) ? 'noopener noreferrer' : undefined"
                class="type-body-md font-semibold text-accent-ink underline underline-offset-4"
                data-test="help-contact"
                >{{ labels.contact }}</a
            >
            <p
                v-else
                class="type-body-md text-text-secondary"
                data-test="help-contact"
            >
                {{ labels.contact }}
            </p>
        </section>
    </div>
</template>
