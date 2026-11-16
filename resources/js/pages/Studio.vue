<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { exactUsd, formatUsd } from '@/lib/money';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Coins, ImageOff, LoaderCircle, Plus, RotateCcw, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

type Status = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';

interface Generation {
    id: string;
    status: Status;
    prompt: string;
    size: string;
    quality: string;
    referenceImages: string[];
    estimatedMinNanos: number;
    estimatedMaxNanos: number;
    chargedNanos: number | null;
    errorCode: string | null;
    imageUrl: string | null;
    imageExpired: boolean;
    createdAt: string;
}

const props = defineProps<{
    sizes: string[];
    qualities: string[];
    balanceNanos: number;
    generations: Generation[];
}>();

const MAX_REFERENCES = 4;
const CREDIT_PACKS = [1, 5, 20];

const form = useForm({
    prompt: '',
    size: props.sizes[0] ?? '1024x1024',
    quality: props.qualities.includes('medium') ? 'medium' : props.qualities[0],
    reference_images: [] as string[],
});

const filledReferences = computed(() => form.reference_images.map((url) => url.trim()).filter((url) => url !== ''));

// --- Price estimate -------------------------------------------------------
// Asked from the server, so the form and the charge use one formula.

const estimate = ref<{ minNanos: number; maxNanos: number } | null>(null);
const estimating = ref(false);
let estimateTimer: ReturnType<typeof setTimeout> | undefined;
let estimateRequest = 0;

async function refreshEstimate() {
    if (form.prompt.trim() === '') {
        estimate.value = null;
        return;
    }

    const request = ++estimateRequest;
    const params = new URLSearchParams({ prompt: form.prompt, size: form.size, quality: form.quality });
    filledReferences.value.forEach((url) => params.append('reference_images[]', url));

    estimating.value = true;
    try {
        const response = await fetch(`${route('studio.estimate')}?${params}`, { headers: { Accept: 'application/json' } });
        if (request !== estimateRequest) return; // a newer keystroke won
        estimate.value = response.ok ? await response.json() : null;
    } finally {
        if (request === estimateRequest) estimating.value = false;
    }
}

watch(
    () => [form.prompt, form.size, form.quality, filledReferences.value.join('\n')],
    () => {
        clearTimeout(estimateTimer);
        estimateTimer = setTimeout(refreshEstimate, 250);
    },
);

const canAfford = computed(() => estimate.value === null || props.balanceNanos >= estimate.value.maxNanos);

// --- Actions --------------------------------------------------------------

function submit() {
    form.transform((data) => ({ ...data, reference_images: filledReferences.value })).post(route('generations.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('prompt', 'reference_images'),
    });
}

function addReference() {
    if (form.reference_images.length < MAX_REFERENCES) form.reference_images.push('');
}

function reuse(generation: Generation) {
    form.prompt = generation.prompt;
    form.size = generation.size;
    form.quality = generation.quality;
    form.reference_images = [...generation.referenceImages];
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

const buying = ref<number | null>(null);

function buyCredits(usd: number) {
    buying.value = usd;
    router.post(route('credits.store'), { usd }, { preserveScroll: true, onFinish: () => (buying.value = null) });
}

// --- Live status ------------------------------------------------------------
// While anything is in flight, re-read just the history and the balance.

const inFlight = computed(() => props.generations.some((g) => g.status !== 'succeeded' && g.status !== 'failed'));
let poll: ReturnType<typeof setInterval> | undefined;

watch(
    inFlight,
    (active) => {
        clearInterval(poll);
        if (active) {
            poll = setInterval(() => router.reload({ only: ['generations', 'balanceNanos'] }), 2000);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    clearInterval(poll);
    clearTimeout(estimateTimer);
});

// --- Presentation -----------------------------------------------------------

const statusLabel: Record<Status, string> = {
    pending: 'Sending',
    queued: 'Queued',
    processing: 'Generating',
    succeeded: 'Done',
    failed: 'Failed',
};

const statusClass: Record<Status, string> = {
    pending: 'bg-muted text-muted-foreground',
    queued: 'bg-muted text-muted-foreground',
    processing: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    succeeded: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    failed: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
};

function failureReason(code: string | null): string {
    switch (code) {
        case 'content_policy_violation':
            return 'Pixelforge rejected this prompt.';
        case 'daily_limit_exceeded':
            return 'The daily generation limit is used up. It resets at midnight UTC.';
        case 'generation_failed':
            return "The model couldn't make an image for this prompt.";
        case 'internal_error':
            return 'Pixelforge had an internal error. Trying again usually works.';
        case 'not_submitted':
            return "We couldn't reach Pixelforge.";
        default:
            return 'Something went wrong.';
    }
}

function aspect(size: string): string {
    const [w, h] = size.split('x').map(Number);
    return `${w} / ${h}`;
}

function range(min: number, max: number): string {
    return `${formatUsd(min)} – ${formatUsd(max)}`;
}
</script>

<template>
    <Head title="Studio" />

    <AppLayout :breadcrumbs="[{ title: 'Studio', href: '/studio' }]">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-8 p-4 md:p-6">
            <!-- Balance -->
            <section class="flex flex-wrap items-center justify-between gap-4 rounded-xl border p-4">
                <div>
                    <p class="text-sm text-muted-foreground">Credit balance</p>
                    <p class="text-2xl font-semibold tabular-nums" :title="exactUsd(balanceNanos)">{{ formatUsd(balanceNanos) }}</p>
                    <p class="text-xs text-muted-foreground">Includes credits reserved for images still generating.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-muted-foreground">Buy credits</span>
                    <Button v-for="usd in CREDIT_PACKS" :key="usd" variant="outline" size="sm" :disabled="buying !== null" @click="buyCredits(usd)">
                        <LoaderCircle v-if="buying === usd" class="animate-spin" />
                        <Coins v-else />
                        ${{ usd }}
                    </Button>
                </div>
            </section>

            <!-- Form -->
            <form class="flex flex-col gap-5 rounded-xl border p-4 md:p-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <div class="flex items-baseline justify-between">
                        <Label for="prompt">Prompt</Label>
                        <span class="text-xs tabular-nums text-muted-foreground">{{ [...form.prompt].length }} / 4000</span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="3"
                        maxlength="4000"
                        required
                        placeholder="A lighthouse on a cliff at dawn, oil painting"
                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-base placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:text-sm"
                    />
                    <InputError :message="form.errors.prompt" />
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <fieldset class="grid gap-2">
                        <legend class="mb-2 text-sm font-medium">Size</legend>
                        <div class="flex flex-wrap gap-2">
                            <label
                                v-for="size in sizes"
                                :key="size"
                                class="cursor-pointer rounded-md border px-3 py-1.5 text-sm tabular-nums has-[:checked]:border-primary has-[:checked]:bg-primary has-[:checked]:text-primary-foreground"
                            >
                                <input v-model="form.size" type="radio" name="size" :value="size" class="sr-only" />
                                {{ size }}
                            </label>
                        </div>
                        <InputError :message="form.errors.size" />
                    </fieldset>

                    <fieldset class="grid gap-2">
                        <legend class="mb-2 text-sm font-medium">Quality</legend>
                        <div class="flex flex-wrap gap-2">
                            <label
                                v-for="quality in qualities"
                                :key="quality"
                                class="cursor-pointer rounded-md border px-3 py-1.5 text-sm capitalize has-[:checked]:border-primary has-[:checked]:bg-primary has-[:checked]:text-primary-foreground"
                            >
                                <input v-model="form.quality" type="radio" name="quality" :value="quality" class="sr-only" />
                                {{ quality }}
                            </label>
                        </div>
                        <InputError :message="form.errors.quality" />
                    </fieldset>
                </div>

                <div class="grid gap-2">
                    <Label>Reference images <span class="font-normal text-muted-foreground">(optional, up to 4 URLs)</span></Label>
                    <div v-for="(_, index) in form.reference_images" :key="index" class="flex gap-2">
                        <Input v-model="form.reference_images[index]" type="url" placeholder="https://example.com/style.png" />
                        <Button type="button" variant="ghost" size="icon" aria-label="Remove" @click="form.reference_images.splice(index, 1)">
                            <X />
                        </Button>
                    </div>
                    <InputError
                        :message="form.errors.reference_images ?? Object.entries(form.errors).find(([k]) => k.startsWith('reference_images.'))?.[1]"
                    />
                    <div>
                        <Button v-if="form.reference_images.length < MAX_REFERENCES" type="button" variant="outline" size="sm" @click="addReference">
                            <Plus /> Add reference image
                        </Button>
                    </div>
                </div>

                <!-- Cost -->
                <div class="flex flex-wrap items-end justify-between gap-4 border-t pt-4">
                    <div class="min-h-[3.25rem]">
                        <template v-if="estimate">
                            <p class="text-sm">
                                Estimated cost
                                <span class="font-semibold tabular-nums" :class="{ 'opacity-60': estimating }">
                                    {{ range(estimate.minNanos, estimate.maxNanos) }}
                                </span>
                            </p>
                            <p class="max-w-md text-xs text-muted-foreground">
                                The exact price depends on how much the model thinks, which is only known once the image is ready. You pay the actual
                                cost then, never more than the top of this range. Failed images are free.
                            </p>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">Type a prompt to see what it will cost.</p>
                    </div>

                    <div class="flex flex-col items-end gap-1">
                        <Button type="submit" :disabled="form.processing || !canAfford || form.prompt.trim() === ''">
                            <LoaderCircle v-if="form.processing" class="animate-spin" />
                            Generate
                        </Button>
                        <p v-if="!canAfford" class="text-xs text-red-600 dark:text-red-400">
                            Up to {{ formatUsd(estimate!.maxNanos) }} needed, you have {{ formatUsd(balanceNanos) }}.
                        </p>
                        <InputError :message="form.errors.credits" />
                    </div>
                </div>
            </form>

            <!-- History -->
            <section class="flex flex-col gap-4">
                <h2 class="text-lg font-semibold">Your images</h2>

                <p v-if="generations.length === 0" class="text-sm text-muted-foreground">Nothing yet. Your images will appear here.</p>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <article v-for="generation in generations" :key="generation.id" class="flex flex-col overflow-hidden rounded-xl border">
                        <div class="relative flex items-center justify-center bg-muted" :style="{ aspectRatio: aspect(generation.size) }">
                            <img
                                v-if="generation.status === 'succeeded' && generation.imageUrl && !generation.imageExpired"
                                :src="generation.imageUrl"
                                :alt="generation.prompt"
                                class="h-full w-full object-cover"
                                loading="lazy"
                            />
                            <div v-else-if="generation.status === 'succeeded'" class="flex flex-col items-center gap-1 text-xs text-muted-foreground">
                                <ImageOff class="size-6" /> Image link expired after 24 hours
                            </div>
                            <AlertTriangle v-else-if="generation.status === 'failed'" class="size-8 text-muted-foreground" />
                            <LoaderCircle v-else class="size-8 animate-spin text-muted-foreground" />

                            <span class="absolute left-2 top-2 rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass[generation.status]">
                                {{ statusLabel[generation.status] }}
                            </span>
                        </div>

                        <div class="flex flex-1 flex-col gap-2 p-3 text-sm">
                            <p class="line-clamp-2" :title="generation.prompt">{{ generation.prompt }}</p>
                            <p class="text-xs capitalize text-muted-foreground">{{ generation.size }} · {{ generation.quality }}</p>

                            <div class="mt-auto flex items-end justify-between gap-2 border-t pt-2 text-xs">
                                <div class="tabular-nums">
                                    <template v-if="generation.status === 'succeeded' && generation.chargedNanos !== null">
                                        <p>
                                            Charged
                                            <span class="font-semibold" :title="exactUsd(generation.chargedNanos)">{{
                                                formatUsd(generation.chargedNanos)
                                            }}</span>
                                        </p>
                                        <p class="text-muted-foreground">
                                            Estimated {{ range(generation.estimatedMinNanos, generation.estimatedMaxNanos) }}
                                        </p>
                                    </template>
                                    <template v-else-if="generation.status === 'failed'">
                                        <p class="font-semibold">Not charged</p>
                                        <p class="text-muted-foreground">{{ failureReason(generation.errorCode) }}</p>
                                    </template>
                                    <template v-else>
                                        <p>Estimated {{ range(generation.estimatedMinNanos, generation.estimatedMaxNanos) }}</p>
                                        <p class="text-muted-foreground">{{ formatUsd(generation.estimatedMaxNanos) }} reserved until it finishes</p>
                                    </template>
                                </div>
                                <Button variant="ghost" size="sm" title="Use these settings again" @click="reuse(generation)">
                                    <RotateCcw />
                                </Button>
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
