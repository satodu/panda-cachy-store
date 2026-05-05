<?php

use Livewire\Volt\Component;
use App\Services\PackageService;
use Native\Laravel\Facades\Notification;
use Illuminate\Support\Facades\Storage;

new class extends Component
{
    public $search = '';
    public $packages = [];
    public $systemPackages = []; 
    public $selectedPackage = null;
    public $tab = 'explore'; 
    public $filterRepo = 'all'; 
    public $sortBy = 'relevance'; 
    public $toast = ['show' => false, 'message' => '', 'type' => 'success'];
    public $pendingInstallations = []; 
    
    // Paginação
    public $page = 1;
    public $totalResults = 0;
    
    // Configurações
    public $settings = [
        'enable_aur' => true,
        'enable_flatpak' => false,
        'search_limit' => 50
    ];

    public $sysInfo = [
        'kernel' => '',
        'hostname' => '',
        'os' => 'CachyOS'
    ];

    public function mount()
    {
        $this->sysInfo['kernel'] = php_uname('r');
        $this->sysInfo['hostname'] = gethostname();
        $this->loadSettings();
        $this->loadData();
        $this->checkPendingInstallations();
    }

    public function checkPendingInstallations()
    {
        foreach ($this->pendingInstallations as $key => $name) {
            if (!\Illuminate\Support\Facades\Cache::has("installing_{$name}")) {
                unset($this->pendingInstallations[$key]);
                $this->loadData(); // Refresh list when one finishes
            }
        }
    }

    public function loadSettings()
    {
        if (Storage::exists('settings.json')) {
            $this->settings = array_merge($this->settings, json_decode(Storage::get('settings.json'), true));
        }
    }

    public function saveSettings()
    {
        Storage::put('settings.json', json_encode($this->settings));
        $this->showNotification("Saved", "Settings updated successfully.");
        $this->loadData();
    }

    public function showDetails($name, $isAur = false)
    {
        $service = new PackageService();
        $this->selectedPackage = $service->getPackageDetails($name, $isAur);
        if ($this->selectedPackage) {
            $this->selectedPackage['is_aur'] = $isAur;
        }
    }

    public function closeDetails()
    {
        $this->selectedPackage = null;
    }

    public function setTab($tab)
    {
        $this->tab = $tab;
        $this->page = 1;
        if ($tab !== 'settings') {
            $this->search = '';
        }
        $this->loadData();
    }

    public function nextPage()
    {
        if ($this->page * $this->settings['search_limit'] < $this->totalResults) {
            $this->page++;
            $this->loadData();
        }
    }

    public function prevPage()
    {
        if ($this->page > 1) {
            $this->page--;
            $this->loadData();
        }
    }

    public function refresh()
    {
        $service = new PackageService();
        $service->clearCache();
        $this->loadData();
        $this->showNotification("Refreshed", "Store data has been updated.");
    }

    public function loadData()
    {
        if ($this->tab === 'settings') return;

        if ($this->tab === 'installed') {
            $this->loadInstalled();
            $this->systemPackages = [];
        } else {
            if (empty($this->search) || strlen($this->search) < 3) {
                $this->getFeaturedPackages();
                $this->getSystemPackages();
            } else {
                $this->searchPackages();
                $this->systemPackages = [];
            }
        }
        
        $this->applyFiltersAndSorting();
    }

    public function updatedSearch()
    {
        $this->page = 1;
        $this->loadData();
    }

    public function updatedFilterRepo()
    {
        $this->page = 1;
        $this->loadData();
    }

    public function getSystemPackages()
    {
        $service = new PackageService();
        $essentials = ['cachy-update', 'cachyos-settings', 'cachyos-hooks', 'cachyos-gaming-meta'];
        $this->systemPackages = [];
        
        foreach($essentials as $name) {
            $results = $service->search($name);
            foreach($results as $pkg) {
                if ($pkg['name'] === $name) {
                    $this->systemPackages[] = $pkg;
                    break;
                }
            }
        }
    }

    public function getFeaturedPackages()
    {
        $service = new PackageService();
        $featured = ['discord', 'steam', 'brave-bin', 'vlc', 'obs-studio', 'visual-studio-code-bin', 'spotify'];
        $this->packages = [];
        
        foreach($featured as $name) {
            $results = $service->search($name);
            foreach($results as $pkg) {
                if ($pkg['name'] === $name) {
                    $this->packages[] = $pkg;
                    break;
                }
            }
        }
    }

    public function loadInstalled()
    {
        $service = new PackageService();
        $allInstalled = $service->getInstalledPackages();
        
        $this->packages = array_map(function($pkg) {
            return [
                'repo' => $pkg['is_aur'] ? 'aur' : 'official',
                'name' => $pkg['name'],
                'version' => $pkg['version'],
                'is_aur' => $pkg['is_aur'],
                'installed' => true,
                'description' => 'Package installed on your system.',
            ];
        }, $allInstalled);
    }

    public function searchPackages()
    {
        $service = new PackageService();
        $this->packages = $service->search($this->search);
    }

    protected function applyFiltersAndSorting()
    {
        if (!empty($this->search)) {
            $this->packages = array_filter($this->packages, function($pkg) {
                return str_contains(strtolower($pkg['name']), strtolower($this->search));
            });
        }

        if ($this->filterRepo !== 'all') {
            $this->packages = array_filter($this->packages, function($pkg) {
                if ($this->filterRepo === 'aur') {
                    return isset($pkg['is_aur']) && $pkg['is_aur'];
                }
                if ($this->filterRepo === 'official') {
                    return (!isset($pkg['is_aur']) || !$pkg['is_aur']);
                }
                return true;
            });
        }

        if ($this->sortBy === 'name') {
            usort($this->packages, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        }

        // Paginação REAL
        $this->totalResults = count($this->packages);
        $limit = (int) ($this->settings['search_limit'] ?? 50);
        $offset = ($this->page - 1) * $limit;
        
        $this->packages = array_slice(array_values($this->packages), $offset, $limit);
    }

    public function install($name, $isAur = false)
    {
        $service = new PackageService();
        $success = $service->install($name, $isAur);
        
        if ($success) {
            if ($isAur || (new PackageService())->getHelper() !== 'pacman') {
                // Para AUR, entramos em modo de monitoramento
                $this->pendingInstallations[] = $name;
                $this->showNotification("Installing", "Building $name in terminal...");
            } else {
                $this->showNotification("Success", "$name has been installed.");
                Notification::new()
                    ->title('CachyOS Store')
                    ->message("$name installed successfully!")
                    ->show();
            }
        } else {
            $this->showNotification("Error", "Failed to install $name.", 'error');
        }

        $this->loadData();
    }

    public function remove($name)
    {
        $service = new PackageService();
        $success = $service->remove($name);
        
        if ($success) {
            $this->showNotification("Removed", "$name was uninstalled.");
            Notification::new()
                ->title('CachyOS Store')
                ->message("$name uninstalled.")
                ->show();
        }

        $this->loadData();
    }

    public function showNotification($title, $message, $type = 'success')
    {
        $this->toast = [
            'show' => true,
            'message' => $message,
            'type' => $type
        ];
        
        $this->dispatch('close-toast');
    }

    public function hideToast()
    {
        $this->toast['show'] = false;
    }
};
?>

<div 
    class="flex h-screen bg-background text-foreground overflow-hidden relative font-sans" 
    x-data="{ show: @entangle('toast.show') }" 
    x-init="$watch('show', value => { if(value) setTimeout(() => $wire.hideToast(), 4000) })"
    @if(count($pendingInstallations) > 0) wire:poll.3s="checkPendingInstallations" @endif
>
    
    <!-- Toast Notification -->
    <div 
        x-show="show"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 transform -translate-y-10 scale-95"
        x-transition:enter-end="opacity-100 transform translate-y-0 scale-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 transform translate-y-0 scale-100"
        x-transition:leave-end="opacity-0 transform -translate-y-10 scale-95"
        class="fixed top-8 right-8 z-[10000] pointer-events-auto"
    >
        <div class="bg-card border border-border rounded-lg p-5 shadow-2xl flex items-center gap-4 min-w-[350px]">
            <div class="w-12 h-12 rounded-full {{ $toast['type'] === 'success' ? 'bg-cachy/20 text-cachy' : 'bg-destructive/20 text-destructive' }} flex items-center justify-center">
                @if($toast['type'] === 'success')
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                @else
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                @endif
            </div>
            <div class="flex-1">
                <p class="text-sm font-bold tracking-tight">{{ $toast['type'] === 'success' ? 'Notification' : 'Error' }}</p>
                <p class="text-muted-foreground text-xs leading-relaxed">{{ $toast['message'] }}</p>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div wire:loading.delay.longer wire:target="setTab, refresh, saveSettings, nextPage, prevPage" class="fixed inset-0 z-[9999] bg-background/85 backdrop-blur-md no-drag">
        <div class="flex h-screen w-screen items-center justify-center flex-col gap-8 select-none pointer-events-none">
            <div class="pacman-container scale-110">
                <div class="pacman"></div>
                <div class="dot"></div>
                <div class="dot"></div>
                <div class="dot"></div>
            </div>
            <p class="text-xs font-black tracking-[0.4em] uppercase text-cachy animate-pulse">
                Syncing Store
            </p>
        </div>
    </div>

    <!-- Sidebar -->
    <aside class="w-72 bg-card border-r border-border flex flex-col shrink-0">
        <div class="p-8 flex-1">
            <div class="flex items-center gap-4 mb-2 px-2">
                <img src="/logo.png" class="w-12 h-12 object-contain" alt="Logo">
                <div>
                    <h1 class="text-xl font-black tracking-tighter leading-none">Cachy Store</h1>
                    <span class="text-[9px] font-black uppercase text-cachy/70 tracking-widest">Community Edition</span>
                </div>
            </div>
            <p class="px-2 mb-10 text-[10px] text-muted-foreground font-medium italic">Unofficial fan project</p>

            <nav class="space-y-1.5">
                <button 
                    wire:click="setTab('explore')"
                    class="w-full flex items-center gap-4 px-4 py-3 rounded-md text-[15px] transition-all {{ $tab === 'explore' ? 'bg-accent text-accent-foreground font-bold' : 'text-muted-foreground hover:bg-accent/40 hover:text-foreground' }}"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                    <span>Explore</span>
                </button>
                <button 
                    wire:click="setTab('installed')"
                    class="w-full flex items-center gap-4 px-4 py-3 rounded-md text-[15px] transition-all {{ $tab === 'installed' ? 'bg-accent text-accent-foreground font-bold' : 'text-muted-foreground hover:bg-accent/40 hover:text-foreground' }}"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                    <span>Installed</span>
                </button>
                <button 
                    wire:click="setTab('settings')"
                    class="w-full flex items-center gap-4 px-4 py-3 rounded-md text-[15px] transition-all {{ $tab === 'settings' ? 'bg-accent text-accent-foreground font-bold' : 'text-muted-foreground hover:bg-accent/40 hover:text-foreground' }}"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <span>Settings</span>
                </button>
            </nav>
        </div>

        <!-- Sidebar Footer -->
        <div class="p-8 bg-muted/10 space-y-5 relative">
            <div class="flex items-center gap-4">
                <div class="w-9 h-9 rounded border border-border flex items-center justify-center bg-background shadow-sm">
                    <svg class="w-4 h-4 text-cachy" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path></svg>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-muted-foreground tracking-widest leading-none mb-1">Host</p>
                    <p class="text-[13px] font-bold">{{ $sysInfo['hostname'] }}</p>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <div class="w-9 h-9 rounded border border-border flex items-center justify-center bg-background shadow-sm">
                    <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path></svg>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase text-muted-foreground tracking-widest leading-none mb-1">Kernel</p>
                    <p class="text-[13px] font-bold">{{ $sysInfo['kernel'] }}</p>
                </div>
            </div>
            
            <div class="pt-4 border-t border-border/50">
                <button wire:click="setTab('settings')" class="text-[10px] font-black uppercase text-muted-foreground hover:text-cachy transition-colors tracking-widest leading-none">
                    v1.0.0 | Made by Panda 🐼
                </button>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col min-w-0 bg-background overflow-hidden">
        @if($tab === 'explore' || $tab === 'installed')
            <!-- Header -->
            <header class="h-16 border-b border-border flex items-center px-8 gap-6 bg-background/95 backdrop-blur-xl sticky top-0 z-40">
                <div class="flex-1 relative max-w-2xl">
                    <svg wire:loading.remove wire:target="search" class="w-5 h-5 absolute left-4 top-1/2 -translate-y-1/2 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    <div wire:loading wire:target="search" class="absolute left-4 top-1/2 -translate-y-1/2">
                        <div class="spinner"></div>
                    </div>
                    <input 
                        wire:model.live.debounce.700ms="search"
                        type="text" 
                        placeholder="{{ $tab === 'installed' ? 'Search installed applications...' : 'Type to search packages...' }}" 
                        class="w-full h-10 bg-background border border-input rounded-md pl-12 pr-4 text-[15px] placeholder:text-muted-foreground focus:ring-2 focus:ring-ring focus:border-ring outline-none transition-all"
                    >
                </div>

                <div class="flex items-center gap-3">
                    <select wire:model.live="filterRepo" class="h-10 bg-background border border-input rounded-md pl-4 pr-10 text-sm font-semibold outline-none focus:ring-2 focus:ring-ring cursor-pointer appearance-none bg-[url('data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20fill%3D%22none%22%20viewBox%3D%220%200%2024%2024%22%20stroke%3D%22%23a1a1aa%22%20stroke-width%3D%222%22%3E%3Cpath%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%20d%3D%22m19%209-7%207-7-7%22%2F%3E%3C%2Fsvg%3E')] bg-[length:16px] bg-[position:right_10px_center] bg-no-repeat transition-all">
                        <option value="all">All Repositories</option>
                        <option value="official">Official Repos</option>
                        @if($settings['enable_aur']) <option value="aur">AUR Repo</option> @endif
                    </select>

                    <button wire:click="refresh" wire:loading.attr="disabled" class="h-10 w-10 border border-input rounded-md flex items-center justify-center hover:bg-accent transition-all group">
                        <svg wire:loading.class="animate-spin" wire:target="refresh" class="w-5 h-5 text-muted-foreground group-hover:text-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    </button>
                </div>
            </header>

            <!-- Content Area -->
            <div class="flex-1 overflow-y-auto">
                <div class="max-w-7xl mx-auto p-10">
                    
                    <!-- Essentials Row -->
                    @if($tab === 'explore' && (empty($search) || strlen($search) < 3))
                        <div class="mb-14">
                            <h3 class="text-xs font-black text-muted-foreground uppercase tracking-[0.2em] mb-8">Official CachyOS Tools</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
                                @foreach($systemPackages as $pkg)
                                    <div wire:key="sys-{{ $pkg['name'] }}" class="bg-card border border-border rounded-lg p-6 hover:border-cachy/40 transition-all flex flex-col gap-4 shadow-sm group">
                                        <div class="flex justify-between items-start">
                                            <div class="w-12 h-12 bg-muted rounded-lg flex items-center justify-center text-3xl group-hover:scale-110 transition-transform">⚙️</div>
                                            @if($pkg['installed']) <span class="text-[9px] font-black text-cachy border border-cachy/40 px-2 py-0.5 rounded uppercase">Installed</span> @endif
                                        </div>
                                        <div>
                                            <h4 class="text-[15px] font-black truncate mb-1">{{ $pkg['name'] }}</h4>
                                            <p class="text-[13px] text-muted-foreground line-clamp-2 leading-relaxed">{{ $pkg['description'] }}</p>
                                        </div>
                                        <div class="mt-2 flex gap-2">
                                            @if($pkg['installed'])
                                                <button wire:click="remove('{{ $pkg['name'] }}')" class="flex-1 py-2 bg-destructive/10 hover:bg-destructive text-destructive hover:text-destructive-foreground text-[11px] font-black rounded transition-all uppercase tracking-wider">Uninstall</button>
                                            @else
                                                <button wire:click="install('{{ $pkg['name'] }}', false)" class="flex-1 py-2 bg-cachy hover:bg-cachy/90 text-white text-[11px] font-black rounded transition-all uppercase tracking-wider">Install</button>
                                            @endif
                                            <button wire:click="showDetails('{{ $pkg['name'] }}', false)" class="w-9 h-9 border border-input rounded flex items-center justify-center text-muted-foreground hover:bg-accent"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="mb-10 flex items-end justify-between">
                        <div>
                            <h2 class="text-3xl font-black tracking-tight mb-2">
                                @if($tab === 'installed') Installed Applications @else {{ (empty($search) || strlen($search) < 3) ? 'Popular Software' : 'Search Results' }} @endif
                            </h2>
                            @if($tab === 'explore' && !empty($search) && strlen($search) < 3)
                                <p class="text-sm text-cachy font-bold italic tracking-wide">Enter at least 3 characters for a global search...</p>
                            @else
                                <p class="text-xs text-muted-foreground font-bold uppercase tracking-widest">Found {{ $totalResults }} packages</p>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                        @forelse($packages as $pkg)
                            <div wire:key="pkg-{{ $pkg['name'] }}-{{ $loop->index }}" class="bg-card border border-border rounded-lg p-6 hover:border-primary/20 transition-all flex flex-col h-full group relative">
                                <div class="flex items-start justify-between mb-5">
                                    <div class="w-14 h-14 bg-muted rounded-lg flex items-center justify-center text-3xl group-hover:scale-105 transition-transform">
                                        @php $emoji = match(true) { str_contains(strtolower($pkg['name']), 'steam') => '🎮', str_contains(strtolower($pkg['name']), 'discord') => '💬', str_contains(strtolower($pkg['name']), 'brave') => '🦁', str_contains(strtolower($pkg['name']), 'code') => '💻', str_contains(strtolower($pkg['name']), 'vlc') => '🎬', str_contains(strtolower($pkg['name']), 'obs') => '🎥', str_contains(strtolower($pkg['name']), 'spotify') => '🎧', default => '📦' }; @endphp {{ $emoji }}
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
                                    @else
                                        @php $isAurVal = isset($pkg['is_aur']) && $pkg['is_aur'] ? 'true' : 'false'; @endphp
                                        <button wire:click="install('{{ $pkg['name'] }}', {{ $isAurVal }})" class="flex-1 h-10 bg-cachy hover:bg-cachy/90 text-white text-[11px] font-black rounded transition-all uppercase tracking-widest shadow-md disabled:opacity-50">
                                            <span wire:loading.remove wire:target="install('{{ $pkg['name'] }}', {{ $isAurVal }})">Install</span>
                                            <span wire:loading wire:target="install('{{ $pkg['name'] }}', {{ $isAurVal }})">Loading...</span>
                                        </button>
                                    @endif
                                    <button wire:click="showDetails('{{ $pkg['name'] }}', {{ isset($pkg['is_aur']) && $pkg['is_aur'] ? 'true' : 'false' }})" class="h-10 w-10 border border-input rounded flex items-center justify-center text-muted-foreground hover:bg-accent transition-colors"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></button>
                                </div>
                            </div>
                        @empty
                            <div class="col-span-full py-32 text-center">
                                <h3 class="text-xl font-bold text-muted-foreground">No applications found.</h3>
                                <p class="text-sm text-slate-500 mt-2">Try a different search term or check your filters.</p>
                            </div>
                        @endforelse
                    </div>

                    <!-- Pagination Controls -->
                    @if($totalResults > $settings['search_limit'])
                        <div class="mt-16 flex items-center justify-between border-t border-border pt-8 pb-20">
                            <div class="text-sm text-muted-foreground font-bold">
                                Showing <span class="text-foreground">{{ min(($page - 1) * $settings['search_limit'] + 1, $totalResults) }}</span> to <span class="text-foreground">{{ min($page * $settings['search_limit'], $totalResults) }}</span> of <span class="text-foreground">{{ $totalResults }}</span> results
                            </div>
                            <div class="flex items-center gap-2">
                                <button 
                                    wire:click="prevPage" 
                                    @if($page === 1) disabled @endif
                                    class="h-10 px-4 border border-input rounded-md text-xs font-black uppercase tracking-widest transition-all hover:bg-accent disabled:opacity-30 disabled:hover:bg-transparent"
                                >
                                    Previous
                                </button>
                                <div class="h-10 px-4 bg-accent/50 rounded-md flex items-center justify-center text-xs font-black">
                                    {{ $page }} / {{ ceil($totalResults / $settings['search_limit']) }}
                                </div>
                                <button 
                                    wire:click="nextPage" 
                                    @if($page * $settings['search_limit'] >= $totalResults) disabled @endif
                                    class="h-10 px-4 border border-input rounded-md text-xs font-black uppercase tracking-widest transition-all hover:bg-accent disabled:opacity-30 disabled:hover:bg-transparent"
                                >
                                    Next
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @elseif($tab === 'settings')
            <!-- Settings & About -->
            <div class="flex-1 overflow-y-auto p-16">
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
                                <select wire:model="settings.search_limit" class="h-10 bg-background border border-input rounded-md pl-4 pr-10 text-sm font-bold outline-none appearance-none bg-[url('data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20fill%3D%22none%22%20viewBox%3D%220%200%2024%2024%22%20stroke%3D%22%23a1a1aa%22%20stroke-width%3D%222%22%3E%3Cpath%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%20d%3D%22m19%209-7%207-7-7%22%2F%3E%3C%2Fsvg%3E')] bg-[length:16px] bg-[position:right_10px_center] bg-no-repeat transition-all">
                                    <option value="25">25 Results</option>
                                    <option value="50">50 Results</option>
                                    <option value="100">100 Results</option>
                                </select>
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
        @endif
    </main>

    <!-- Details Overlay -->
    @if($selectedPackage)
        <div class="fixed inset-0 z-[100] flex items-center justify-end">
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
                        </div>
                    </div>
                </div>

                <div class="p-8 bg-muted/5">
                    @php $isInstalled = $selectedPackage['is_installed'] ?? false; $pkgName = $selectedPackage['Name'] ?? ''; @endphp
                    @if($isInstalled)
                        <button wire:click="remove('{{ $pkgName }}')" class="w-full h-14 bg-destructive text-destructive-foreground font-black rounded-lg hover:bg-destructive/90 transition-all text-xs uppercase tracking-[0.2em] shadow-lg">Uninstall Package</button>
                    @else
                        @php $detIsAur = $selectedPackage['is_aur'] ?? false; @endphp
                        <button wire:click="install('{{ $pkgName }}', {{ $detIsAur ? 'true' : 'false' }})" class="w-full h-14 bg-cachy text-white font-black rounded-lg hover:bg-cachy/90 transition-all text-xs uppercase tracking-[0.2em] shadow-lg">Install Application</button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>