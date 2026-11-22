<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->settingsForm }}
        <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">
            Save settings
        </x-filament::button>
    </form>
</x-filament-panels::page>
