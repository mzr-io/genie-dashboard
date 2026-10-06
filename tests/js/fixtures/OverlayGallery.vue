<script setup lang="ts">
import { ref } from 'vue';
import BannerPreset from '@/components/BannerPreset.vue';
import BlockedReason from '@/components/BlockedReason.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ConnectionBanner from '@/components/ConnectionBanner.vue';
import ListOption from '@/components/ListOption.vue';
import SkipLink from '@/components/SkipLink.vue';
import ToastRegion from '@/components/ToastRegion.vue';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetTitle,
} from '@/components/ui/sheet';
import { useOfflineAction } from '@/composables/useOfflineAction';
import { overlayFixtureLabels as f } from './overlayLabels';

// Overlays, notices and landmarks in one place. Tests mount it; no route renders it (Story 1.9).
const confirmOpen = ref(false);
const unsavedOpen = ref(false);
const secondOpen = ref(false);
const sheetOpen = ref(false);
const rowRemoved = ref(false);
const events = ref<string[]>([]);
const { offline, reason } = useOfflineAction();

function log(name: string): void {
    events.value.push(name);
}

function rowEl(): HTMLElement | null {
    return document.getElementById('row-1');
}
</script>

<template>
    <div>
        <SkipLink />
        <ConnectionBanner />
        <main id="main-content" tabindex="-1">
            <div id="row-1" tabindex="-1">
                <Button
                    v-if="!rowRemoved"
                    id="invoker"
                    variant="destructive-soft"
                    @click="confirmOpen = true"
                >
                    {{ f.remove }}
                </Button>
            </div>
            <Button id="unsaved-invoker" @click="unsavedOpen = true">
                {{ f.leave }}
            </Button>
            <Button id="sheet-invoker" @click="sheetOpen = true">
                {{ f.openSheet }}
            </Button>
            <Button
                id="offline-save"
                :blocked="offline"
                :blocked-reason="reason"
                aria-describedby="offline-reason"
                @click="log('save')"
            >
                {{ f.save }}
            </Button>
            <BlockedReason v-if="offline" id="offline-reason">
                {{ reason }}
            </BlockedReason>
            <div role="listbox" :aria-label="f.listLabel">
                <ListOption id="opt-a" active>{{ f.optionA }}</ListOption>
                <ListOption id="opt-b" selected>{{ f.optionB }}</ListOption>
                <ListOption id="opt-c" selected active>
                    {{ f.optionC }}
                </ListOption>
            </div>
            <BannerPreset preset="reconnecting" />
            <BannerPreset preset="offline-editing" />
            <BannerPreset
                preset="api-changed"
                field="revenue"
                slot-name="Slot 2"
            />
            <BannerPreset preset="live-shape-mismatch" />
            <BannerPreset preset="map-small-screen" />
        </main>
        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="f.confirmTitle"
            :description="f.confirmImpact"
            :object-name="f.objectName"
            :fallback="rowEl"
            @confirm="rowRemoved = true"
            @cancel="log('cancel')"
        />
        <UnsavedChangesDialog
            v-model:open="unsavedOpen"
            @save="log('unsaved-save')"
            @discard="log('discard')"
            @keep="log('keep')"
        />
        <ConfirmDialog
            v-model:open="secondOpen"
            :title="f.secondTitle"
            :description="f.confirmImpact"
            :object-name="f.objectName"
            @refused="log('refused')"
        />
        <Button id="second-invoker" @click="secondOpen = true">
            {{ f.second }}
        </Button>
        <Sheet v-model:open="sheetOpen">
            <SheetContent>
                <SheetTitle>{{ f.sheetTitle }}</SheetTitle>
                <SheetDescription>{{ f.sheetDescription }}</SheetDescription>
                <SheetFooter>
                    <Button>{{ f.save }}</Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
        <ToastRegion />
        <p data-test="events">{{ events.join(',') }}</p>
    </div>
</template>
