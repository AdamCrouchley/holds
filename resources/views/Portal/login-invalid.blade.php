<x-app-layout>
    <div class="py-12">
        <div class="mx-auto max-w-md rounded border bg-white p-6 text-center">
            <p class="text-rose-600">That sign-in link is invalid or expired.</p>
            <a href="{{ route('portal.login.form') }}" class="mt-3 inline-block text-indigo-600">Request a new link</a>
        </div>
    </div>
</x-app-layout>
