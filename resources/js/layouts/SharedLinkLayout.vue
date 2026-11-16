<script setup lang="ts">
// For pages opened through a signed link, without an account: no sidebar,
// no user menu, and a plain note on what this link is.
defineProps<{ until: string }>();
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <header class="border-b">
            <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-2 p-4 md:px-6">
                <span class="font-semibold">Pixel Studio</span>
                <span class="text-xs text-muted-foreground">Read-only link to the operator report, valid until {{ until }} (UTC)</span>
            </div>
        </header>
        <slot />
    </div>
</template>
