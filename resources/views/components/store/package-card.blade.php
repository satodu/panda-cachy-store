@props(['pkg', 'pendingInstallations'])

@php 
    $isPending = in_array($pkg['name'], $pendingInstallations);
    $isAurVal = isset($pkg['is_aur']) && $pkg['is_aur'] ? 'true' : 'false';
    $emoji = match(true) { 
        str_contains(strtolower($pkg['name']), 'steam') => '🎮', 
        str_contains(strtolower($pkg['name']), 'discord') => '💬', 
        str_contains(strtolower($pkg['name']), 'brave') => '🦁', 
        str_contains(strtolower($pkg['name']), 'code') => '💻', 
        str_contains(strtolower($pkg['name']), 'vlc') => '🎬', 
        str_contains(strtolower($pkg['name']), 'obs') => '🎥', 
        str_contains(strtolower($pkg['name']), 'spotify') => '🎧', 
        default => '📦' 
    };
@endphp

<div {{ $attributes->merge(['class' => 'bg-card border border-border rounded-lg p-6 hover:border-primary/20 transition-all flex flex-col h-full group relative']) }}>
    <div class="flex items-start justify-between mb-5">
        <div class="w-14 h-14 bg-muted rounded-lg flex items-center justify-center text-3xl group-hover:scale-105 transition-transform">
            {{ $emoji }}
        </div>
        <div class="flex flex-col items-end gap-1.5">
            @if($pkg['installed']) <span class="text-[9px] font-black text-cachy border border-cachy/30 px-2.5 py-0.5 rounded uppercase">Installed</span> @endif
            @if(isset($pkg['is_aur']) && $pkg['is_aur']) <span class="text-[9px] font-black text-blue-400 border border-blue-400/30 px-2 py-0.5 rounded uppercase tracking-widest">AUR</span>
            @else <span class="text-[9px] font-black text-purple-400 border border-purple-400/30 px-2 py-0.5 rounded uppercase tracking-widest">{{ $pkg['repo'] ?? 'Official' }}</span> @endif
        </div>
    </div>
    <h3 class="text-[17px] font-black mb-1 truncate tracking-tight">{{ $pkg['name'] }}</h3>
    <p class="text-[13px] text-muted-foreground mb-6 line-clamp-2 leading-relaxed h-10">{{ $pkg['description'] }}</p>
    
    <div class="mt-auto flex items-center gap-3 pt-6">
        @if($pkg['installed'])
            <button wire:click="remove('{{ $pkg['name'] }}')" class="flex-1 h-10 bg-destructive/10 hover:bg-destructive text-destructive hover:text-destructive-foreground text-[11px] font-black rounded transition-all uppercase tracking-widest disabled:opacity-50">
                <span wire:loading.remove wire:target="remove('{{ $pkg['name'] }}')">Uninstall</span>
                <span wire:loading wire:target="remove('{{ $pkg['name'] }}')">Wait...</span>
            </button>
        @elseif($isPending)
            <button disabled class="flex-1 h-10 bg-cachy/20 text-cachy text-[11px] font-black rounded transition-all uppercase tracking-widest animate-pulse">
                Finalizing...
            </button>
        @else
            <button wire:click="install('{{ $pkg['name'] }}', {{ $isAurVal }})" class="flex-1 h-10 bg-cachy hover:bg-cachy/90 text-white text-[11px] font-black rounded transition-all uppercase tracking-widest shadow-md disabled:opacity-50">
                <span wire:loading.remove wire:target="install('{{ $pkg['name'] }}', {{ $isAurVal }})">Install</span>
                <span wire:loading wire:target="install('{{ $pkg['name'] }}', {{ $isAurVal }})">Loading...</span>
            </button>
        @endif
        <button wire:click="showDetails('{{ $pkg['name'] }}', {{ $isAurVal }})" class="h-10 w-10 border border-input rounded flex items-center justify-center text-muted-foreground hover:bg-accent transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        </button>
    </div>
</div>
