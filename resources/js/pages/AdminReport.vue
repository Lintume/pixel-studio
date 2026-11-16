<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { exactUsd, formatUsd } from '@/lib/money';
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, XCircle } from 'lucide-vue-next';

interface Row {
    date: string;
    user: string;
    generations: number;
    chargedNanos: number;
    providerCostNanos: number;
}

interface Day {
    date: string;
    ourProviderCostNanos: number;
    pixelforgeCostNanos: number;
    pixelforgeGenerations: number;
    matches: boolean;
}

defineProps<{
    from: string;
    to: string;
    rows: Row[];
    totals: { chargedNanos: number; providerCostNanos: number };
    reconciliation: Day[];
    usageError: string | null;
}>();
</script>

<template>
    <Head title="Operator report" />

    <AppLayout :breadcrumbs="[{ title: 'Operator report', href: '/admin/report' }]">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-8 p-4 md:p-6">
            <header>
                <h1 class="text-xl font-semibold">Operator report</h1>
                <p class="text-sm text-muted-foreground">
                    {{ from }} to {{ to }}, by the UTC day each image completed (the day Pixelforge bills it). Hover any amount for the exact figure.
                </p>
            </header>

            <section class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border p-4">
                    <p class="text-sm text-muted-foreground">Credit spend</p>
                    <p class="text-2xl font-semibold tabular-nums" :title="exactUsd(totals.chargedNanos)">{{ formatUsd(totals.chargedNanos) }}</p>
                </div>
                <div class="rounded-xl border p-4">
                    <p class="text-sm text-muted-foreground">Provider costs</p>
                    <p class="text-2xl font-semibold tabular-nums" :title="exactUsd(totals.providerCostNanos)">
                        {{ formatUsd(totals.providerCostNanos) }}
                    </p>
                </div>
                <div class="rounded-xl border p-4">
                    <p class="text-sm text-muted-foreground">Profit</p>
                    <p class="text-2xl font-semibold tabular-nums" :title="exactUsd(totals.chargedNanos - totals.providerCostNanos)">
                        {{ formatUsd(totals.chargedNanos - totals.providerCostNanos) }}
                    </p>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <h2 class="font-semibold">Check against Pixelforge</h2>
                <p class="text-sm text-muted-foreground">Our recorded provider costs next to what GET /v1/usage reports for this app's API key.</p>
                <p v-if="usageError" class="text-sm text-red-600 dark:text-red-400">Couldn't load Pixelforge usage: {{ usageError }}</p>
                <p v-else-if="reconciliation.length === 0" class="text-sm text-muted-foreground">No usage in this period.</p>
                <div v-else class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm tabular-nums">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">Day (UTC)</th>
                                <th class="px-4 py-2 text-right font-medium">Our records</th>
                                <th class="px-4 py-2 text-right font-medium">Pixelforge</th>
                                <th class="px-4 py-2 text-right font-medium">Images</th>
                                <th class="px-4 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="day in reconciliation" :key="day.date" class="border-t">
                                <td class="px-4 py-2">{{ day.date }}</td>
                                <td class="px-4 py-2 text-right font-mono text-xs">{{ exactUsd(day.ourProviderCostNanos) }}</td>
                                <td class="px-4 py-2 text-right font-mono text-xs">{{ exactUsd(day.pixelforgeCostNanos) }}</td>
                                <td class="px-4 py-2 text-right">{{ day.pixelforgeGenerations }}</td>
                                <td class="px-4 py-2">
                                    <span v-if="day.matches" class="flex items-center gap-1 text-green-700 dark:text-green-400"
                                        ><CheckCircle2 class="size-4" /> Match</span
                                    >
                                    <span v-else class="flex items-center gap-1 text-red-600 dark:text-red-400"
                                        ><XCircle class="size-4" /> Differs</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <h2 class="font-semibold">By day and user</h2>
                <p v-if="rows.length === 0" class="text-sm text-muted-foreground">No completed images in this period.</p>
                <div v-else class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm tabular-nums">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">Day (UTC)</th>
                                <th class="px-4 py-2 font-medium">User</th>
                                <th class="px-4 py-2 text-right font-medium">Images</th>
                                <th class="px-4 py-2 text-right font-medium">Credit spend</th>
                                <th class="px-4 py-2 text-right font-medium">Provider costs</th>
                                <th class="px-4 py-2 text-right font-medium">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.date + row.user" class="border-t">
                                <td class="px-4 py-2">{{ row.date }}</td>
                                <td class="px-4 py-2">{{ row.user }}</td>
                                <td class="px-4 py-2 text-right">{{ row.generations }}</td>
                                <td class="px-4 py-2 text-right" :title="exactUsd(row.chargedNanos)">{{ formatUsd(row.chargedNanos) }}</td>
                                <td class="px-4 py-2 text-right" :title="exactUsd(row.providerCostNanos)">{{ formatUsd(row.providerCostNanos) }}</td>
                                <td class="px-4 py-2 text-right" :title="exactUsd(row.chargedNanos - row.providerCostNanos)">
                                    {{ formatUsd(row.chargedNanos - row.providerCostNanos) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
