@props(['settings'])

<div {{ $attributes->merge(['class' => 'flex-1 overflow-y-auto p-16']) }}>
    <div class="max-w-4xl mx-auto space-y-24">
        <!-- App Settings -->
        <div class="space-y-12">
            <div>
                <h2 class="text-4xl font-black tracking-tight mb-3">Settings</h2>
                <p class="text-[17px] text-muted-foreground font-medium">Manage your repositories and preferences.</p>
            </div>

            <div class="space-y-8">
                <div class="bg-card border border-border rounded-xl p-10 space-y-8 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div class="space-y-1">
                            <h4 class="text-[17px] font-black tracking-tight">Arch User Repository</h4>
                            <p class="text-sm text-muted-foreground leading-relaxed">Search and install thousands of community-maintained packages.</p>
                        </div>
                        <button 
                            wire:click="$set('settings.enable_aur', {{ !$settings['enable_aur'] ? 'true' : 'false' }})"
                            class="w-12 h-6 rounded-full transition-all relative {{ $settings['enable_aur'] ? 'bg-cachy shadow-lg shadow-cachy/20' : 'bg-muted' }}"
                        >
                            <div class="absolute top-1 left-1 bg-white w-4 h-4 rounded-full transition-transform {{ $settings['enable_aur'] ? 'translate-x-6' : '' }}"></div>
                        </button>
                    </div>
                    <div class="pt-8 border-t border-border/50 flex items-center justify-between opacity-40">
                        <div class="space-y-1">
                            <h4 class="text-[17px] font-black tracking-tight">Flatpak Integration</h4>
                            <p class="text-sm text-muted-foreground leading-relaxed">Access Flathub's universal application ecosystem.</p>
                        </div>
                        <div class="w-12 h-6 rounded-full bg-muted relative cursor-not-allowed">
                            <div class="absolute top-1 left-1 bg-slate-600 w-4 h-4 rounded-full"></div>
                        </div>
                    </div>
                </div>

                <div class="bg-card border border-border rounded-xl p-10 flex items-center justify-between shadow-sm">
                    <div class="space-y-1">
                        <h4 class="text-[17px] font-black tracking-tight">Search Results Limit</h4>
                        <p class="text-sm text-muted-foreground leading-relaxed">Limit the number of packages displayed for better performance.</p>
                    </div>
                    <!-- Shadcn Style Limit Select -->
                    <div x-data="{ open: false, selected: @entangle('settings.search_limit') }" class="relative">
                        <button 
                            @click="open = !open" 
                            @click.away="open = false"
                            class="h-10 w-40 flex items-center justify-between rounded-md border border-input bg-background px-4 py-2 text-sm font-medium ring-offset-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 hover:bg-accent/50 transition-colors"
                        >
                            <span x-text="selected + ' Results'"></span>
                            <svg class="h-4 w-4 opacity-50" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>

                        <div 
                            x-show="open" 
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-75"
                            x-transition:leave-start="opacity-100 scale-100"
                            x-transition:leave-end="opacity-0 scale-95"
                            class="absolute right-0 top-12 z-50 min-w-[10rem] overflow-hidden rounded-md border bg-popover p-1 text-popover-foreground shadow-md no-drag"
                        >
                            <div class="px-2 py-1.5 text-[10px] font-black uppercase tracking-widest text-muted-foreground opacity-70">Limit</div>
                            <button @click="selected = 25; open = false" class="relative flex w-full cursor-default select-none items-center rounded-sm px-2 py-1.5 text-xs outline-none hover:bg-accent hover:text-accent-foreground transition-colors">25 Results</button>
                            <button @click="selected = 50; open = false" class="relative flex w-full cursor-default select-none items-center rounded-sm px-2 py-1.5 text-xs outline-none hover:bg-accent hover:text-accent-foreground transition-colors">50 Results</button>
                            <button @click="selected = 100; open = false" class="relative flex w-full cursor-default select-none items-center rounded-sm px-2 py-1.5 text-xs outline-none hover:bg-accent hover:text-accent-foreground transition-colors">100 Results</button>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end pt-4">
                    <button wire:click="saveSettings" class="h-12 px-10 bg-primary text-primary-foreground text-xs font-black rounded-md hover:bg-primary/90 transition-all uppercase tracking-[0.2em] shadow-xl">Save Changes</button>
                </div>
            </div>
        </div>

        <!-- About Author Section -->
        <div class="pt-12 border-t border-border">
            <div class="bg-card border border-border rounded-2xl overflow-hidden shadow-xl flex flex-col md:flex-row">
                <div class="md:w-1/3 bg-muted/30 p-10 flex flex-col items-center text-center border-r border-border">
                    <div class="w-24 h-24 rounded-full bg-cachy/20 flex items-center justify-center mb-6 border-4 border-background shadow-lg overflow-hidden text-4xl">🐼</div>
                    <h3 class="text-xl font-black tracking-tight mb-1">Panda</h3>
                    <p class="text-[10px] text-muted-foreground font-bold uppercase tracking-widest mb-6">Eduardo Sato</p>
                    <div class="flex flex-col gap-2 w-full">
                        <div class="px-3 py-2 bg-background border border-border rounded-lg text-[11px] font-bold flex items-center gap-3">
                            <svg class="w-3.5 h-3.5 text-cachy" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path></svg>
                            <span>São Paulo, Brazil</span>
                        </div>
                    </div>
                </div>
                <div class="md:w-2/3 p-10 space-y-8">
                    <div>
                        <h2 class="text-2xl font-black tracking-tighter mb-3">About the Author</h2>
                        <p class="text-sm text-muted-foreground leading-relaxed">
                            Developer and CachyOS fan. Built with ❤️ to enhance the Linux experience.
                        </p>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-3">
                        <a href="https://github.com/satodu" target="_blank" class="p-4 border border-border rounded-xl hover:bg-accent transition-all group">
                            <div class="flex items-center gap-3">
                                <svg class="w-5 h-5 text-foreground" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
                                <span class="text-xs font-bold">GitHub</span>
                            </div>
                        </a>
                        <a href="https://linkedin.com/in/eduardo-sato-panda" target="_blank" class="p-4 border border-border rounded-xl hover:bg-accent transition-all group">
                            <div class="flex items-center gap-3">
                                <svg class="w-5 h-5 text-blue-500" fill="currentColor" viewBox="0 0 24 24"><path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.238 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/></svg>
                                <span class="text-xs font-bold">LinkedIn</span>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
