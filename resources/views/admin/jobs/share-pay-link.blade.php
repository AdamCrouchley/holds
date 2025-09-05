<div x-data="{ url: @js($url), copied: false }">
  <label class="block text-sm mb-1">Pay link (valid 7 days)</label>
  <input x-ref="t" class="w-full rounded border px-3 py-2" type="text" :value="url" readonly>
  <button
    type="button"
    class="mt-2 px-3 py-2 rounded bg-black text-white"
    @click="navigator.clipboard.writeText(url).then(()=>copied=true)">
    Copy to clipboard
  </button>
  <span x-show="copied" class="ml-2 text-green-600">Copied!</span>
</div>
