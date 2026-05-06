@props(['selectedPackage'])

<div {{ $attributes->merge(['class' => 'fixed inset-0 z-[100] flex items-center justify-end']) }}>
    <div wire:click="closeDetails" class="absolute inset-0 bg-background/80 backdrop-blur-md"></div>
    <div class="relative w-full max-w-xl h-full bg-card border-l border-border shadow-2xl flex flex-col">
        <div class="p-8 border-b border-border flex items-center justify-between">
            <div class="flex items-center gap-6">
                <div class="w-16 h-16 bg-muted rounded-xl flex items-center justify-center text-4xl shadow-inner">📦</div>
                <div>
                    <h2 class="text-2xl font-black tracking-tight leading-tight mb-1">{{ $selectedPackage['Name'] ?? 'Unknown' }}</h2>
                    <p class="text-sm text-muted-foreground font-medium">{{ $selectedPackage['Version'] ?? '---' }}</p>
                </div>
            </div>
            <button wire:click="closeDetails" class="p-3 hover:bg-accent rounded-full transition-colors"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
        </div>
        
        <div class="flex-1 overflow-y-auto p-8 space-y-10">
            <div>
                <h3 class="text-[11px] font-black uppercase tracking-[0.2em] text-muted-foreground mb-4">Description</h3>
                <p class="text-[17px] leading-relaxed font-medium text-slate-200">{{ $selectedPackage['Description'] ?? 'No description provided.' }}</p>
            </div>

            <div class="grid grid-cols-2 gap-6">
                <div class="p-6 rounded-xl border border-border bg-muted/10">
                    <p class="text-[10px] font-black uppercase text-muted-foreground tracking-widest mb-2">Install Size</p>
                    <p class="text-xl font-black">{{ $selectedPackage['Installed Size'] ?? 'Unknown' }}</p>
                </div>
                <div class="p-6 rounded-xl border border-border bg-muted/10">
                    <p class="text-[10px] font-black uppercase text-muted-foreground tracking-widest mb-2">License</p>
                    <p class="text-xl font-black">{{ $selectedPackage['Licenses'] ?? 'Unknown' }}</p>
                </div>
            </div>

            <div class="bg-muted/10 p-8 rounded-xl border border-border space-y-4">
                <h3 class="text-[11px] font-black uppercase tracking-[0.2em] text-muted-foreground mb-4">Package Metadata</h3>
                <div class="space-y-4">
                    <div class="flex justify-between items-center text-sm"><span class="text-muted-foreground font-bold">Build Date</span><span class="font-black">{{ $selectedPackage['Build Date'] ?? '---' }}</span></div>
                    <div class="flex justify-between items-center text-sm"><span class="text-muted-foreground font-bold">Groups</span><span class="font-black">{{ $selectedPackage['Groups'] ?? 'None' }}</span></div>
                    <div class="flex justify-between items-center text-sm"><span class="text-muted-foreground font-bold">Architecture</span><span class="font-black">{{ $selectedPackage['Architecture'] ?? 'x86_64' }}</span></div>
                    <div class="flex justify-between items-center text-sm"><span class="text-muted-foreground font-bold">Maintainer</span><span class="font-black truncate max-w-[200px]">{{ $selectedPackage['Maintainer'] ?? 'Unknown' }}</span></div>
                </div>
            </div>
        </div>

        <div class="p-8 border-t border-border bg-muted/5 flex gap-4">
            @if($selectedPackage['is_installed'] ?? false)
                <button wire:click="remove('{{ $selectedPackage['Name'] }}')" class="flex-1 h-12 bg-destructive text-destructive-foreground text-xs font-black rounded-md hover:bg-destructive/90 transition-all uppercase tracking-widest shadow-lg shadow-destructive/20">Uninstall Package</button>
            @else
                <button wire:click="install('{{ $selectedPackage['Name'] }}', {{ $selectedPackage['is_aur'] ? 'true' : 'false' }})" class="flex-1 h-12 bg-cachy text-white text-xs font-black rounded-md hover:bg-cachy/90 transition-all uppercase tracking-widest shadow-lg shadow-cachy/20">Install Now</button>
            @endif
        </div>
    </div>
</div>
