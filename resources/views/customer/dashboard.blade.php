<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800">
            Customer Dashboard
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
            <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    Welcome back, {{ $customer->name }} ({{ $customer->email }})
                </div>
            </div>

            <form method="POST" action="{{ route('portal.logout') }}" class="mt-6">
                @csrf
                <button class="rounded-md bg-gray-800 px-4 py-2 text-white">Log out</button>
            </form>
        </div>
    </div>
</x-app-layout>
