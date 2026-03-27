<script setup>
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold">TradingView Module</h1>
    <p class="mt-1 text-gray-400">Full TradingView Charting Library with UDF data bridge.</p>

    <div class="mt-6 rounded-xl border border-gray-800 bg-gray-900 p-8">
      <div class="mx-auto max-w-lg text-center">
        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-900/20">
          <svg class="h-8 w-8 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z" />
          </svg>
        </div>

        <h2 class="text-xl font-semibold text-white">License Required</h2>
        <p class="mt-3 text-sm text-gray-400">
          The TradingView Charting Library requires an individual license from TradingView.
          Once obtained, the library files should be placed in
          <code class="rounded bg-gray-800 px-1.5 py-0.5 text-xs text-blue-400">frontend/public/charting_library/</code>.
        </p>

        <div class="mt-6 space-y-3 text-left">
          <h3 class="text-sm font-medium text-gray-300">Setup Steps</h3>
          <div class="space-y-2 text-sm text-gray-400">
            <div class="flex gap-2">
              <span class="text-blue-400">1.</span>
              <span>Apply for a license at tradingview.com/charting-library</span>
            </div>
            <div class="flex gap-2">
              <span class="text-blue-400">2.</span>
              <span>Clone the charting library repo as a private git submodule</span>
            </div>
            <div class="flex gap-2">
              <span class="text-blue-400">3.</span>
              <span>The UDF backend bridge is already built at <code class="rounded bg-gray-800 px-1 text-xs">/api/udf/*</code></span>
            </div>
            <div class="flex gap-2">
              <span class="text-blue-400">4.</span>
              <span>Multi-chart layouts, Pine Script, and 100+ indicators will be available natively</span>
            </div>
          </div>
        </div>

        <div class="mt-6 rounded-lg border border-amber-900/30 bg-amber-900/10 p-3">
          <p class="text-xs text-amber-400">
            The charting_library/ directory must never be committed to a public git repo (rule #9).
          </p>
        </div>
      </div>
    </div>
  </div>
</template>
