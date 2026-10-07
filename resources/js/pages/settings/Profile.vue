<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { registerUnsavedForm } from '@/lib/unsavedForms';
import { shellPages } from '@/locales/labels';
import { edit } from '@/routes/profile';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: shellPages.profile.title,
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

// Unsaved name or email edits are asked about before a Workspace switch (Story 1.17); Save submits the form.
const form = ref<{ isDirty: boolean; submit: () => void } | null>(null);
let settle: ((saved: boolean) => void) | null = null;

const stop = registerUnsavedForm({
    id: 'profile',
    isDirty: () => form.value?.isDirty ?? false,
    save: () => {
        if (!form.value) {
            return Promise.resolve(false);
        }

        // An earlier save still waiting is settled first, so no promise is left hanging.
        settled(false);

        return new Promise<boolean>((resolve) => {
            settle = resolve;
            form.value?.submit();
        });
    },
});

function settled(saved: boolean): void {
    settle?.(saved);
    settle = null;
}

onBeforeUnmount(() => {
    stop();
    settled(false);
});
</script>

<template>
    <Head :title="shellPages.profile.title" />

    <h1 class="sr-only">{{ shellPages.profile.title }}</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            description="Update your name and email address"
        />

        <Form
            ref="form"
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
            @success="settled(true)"
            @error="settled(false)"
            @finish="settled(false)"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
