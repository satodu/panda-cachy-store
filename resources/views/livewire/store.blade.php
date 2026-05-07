<?php

use Livewire\Volt\Component;
use App\Services\PackageService;
use App\Services\AppImageService;
use Native\Laravel\Facades\Notification;
use Native\Laravel\Facades\Alert;
use Native\Laravel\Dialog;
use Illuminate\Support\Facades\Storage;

new class extends Component
{
    public $search = '';
    public $packages = [];
    public $systemPackages = []; 
    public $appImages = [];
    public $selectedPackage = null;
    public $tab = 'explore'; 
    public $filterRepo = 'all'; 
    public $sortBy = 'relevance'; 
    public $toast = ['show' => false, 'message' => '', 'type' => 'success'];
    public $pendingInstallations = []; 
    public $confirmingAppImageDeletion = null; 
    public $confirmingPackageRemoval = null; 
    
    // Paginação
    public $page = 1;
    public $totalResults = 0;
    
    // Configurações
    public $settings = [
        'enable_aur' => true,
        'enable_flatpak' => false,
        'search_limit' => 50,
        'appimage_path' => ''
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
        
        if (empty($this->settings['appimage_path'])) {
            $this->settings['appimage_path'] = (new AppImageService())->getDefaultDirectory();
        }

        $this->loadData();
        $this->checkPendingInstallations();
    }

    public function runSystemUpdate()
    {
        $service = new PackageService();
        $service->runCachyUpdate();
        $this->showNotification("System Update", "Running update process in terminal...");
    }

    public function checkPendingInstallations()
    {
        foreach ($this->pendingInstallations as $key => $name) {
            if (!\Illuminate\Support\Facades\Cache::has("installing_{$name}")) {
                unset($this->pendingInstallations[$key]);
                
                // Força a limpeza do cache de busca antes de recarregar
                $service = new PackageService();
                $service->clearCache();
                
                $this->loadData();
                $this->showNotification("Concluído", "Operação com {$name} finalizada.");

                // Fecha detalhes e modais automaticamente ao finalizar
                $this->selectedPackage = null;
                $this->confirmingAppImageDeletion = null;
                $this->confirmingPackageRemoval = null;
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

        if ($this->tab === 'appimages') {
            $this->loadAppImages();
            return;
        }

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
        
        // Fast update installation status for what's on screen
        $this->updateInstallationStatus();
        $this->applyFiltersAndSorting();
    }

    protected function updateInstallationStatus()
    {
        $service = new PackageService();
        $res = \Illuminate\Support\Facades\Process::run("pacman -Qq");
        $installedNames = $res->successful() ? explode("\n", trim($res->output())) : [];

        foreach ($this->packages as &$pkg) {
            $pkg['installed'] = in_array($pkg['name'], $installedNames);
        }
        
        foreach ($this->systemPackages as &$pkg) {
            $pkg['installed'] = in_array($pkg['name'], $installedNames);
        }
    }

    public function updatedSearch()
    {
        $this->page = 1;
        $this->loadData();
    }

    public function clearSearch()
    {
        $this->search = '';
        $this->tab = 'explore';
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
        $path = storage_path('app/essentials.json');
        
        $essentials = [];
        if (file_exists($path)) {
            $essentials = json_decode(file_get_contents($path), true);
            shuffle($essentials);
            $essentials = array_slice($essentials, 0, 3);
        } else {
            $essentials = ['cachy-update', 'cachyos-settings', 'cachyos-gaming-meta'];
        }

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
        $this->packages = \Illuminate\Support\Facades\Cache::remember('featured_pkgs_data', 3600, function() {
            $service = new PackageService();
            $featured = ['discord', 'steam', 'brave-bin', 'vlc', 'obs-studio', 'visual-studio-code-bin', 'spotify'];
            $data = [];
            
            foreach($featured as $name) {
                $results = $service->search($name);
                foreach($results as $pkg) {
                    if ($pkg['name'] === $name) {
                        $data[] = $pkg;
                        break;
                    }
                }
            }
            return $data;
        });

        // Garante que o status de instalado esteja sempre certo mesmo se vier do cache
        $this->updateInstallationStatus();
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
        $this->confirmingPackageRemoval = $name;
    }

    public function cancelPackageRemoval()
    {
        $this->confirmingPackageRemoval = null;
    }

    public function deletePackageConfirmed()
    {
        $name = $this->confirmingPackageRemoval;
        if (!$name) return;

        $service = new PackageService();
        $success = $service->remove($name);
        
        if ($success) {
            $this->pendingInstallations[] = $name;
            $this->showNotification("Removing", "Uninstalling $name in terminal...");
        } else {
            $this->showNotification("Error", "Failed to remove $name.", 'error');
        }

        // Limpa o estado APÓS a execução
        $this->confirmingPackageRemoval = null;
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

    public function loadAppImages()
    {
        $service = new AppImageService();
        $this->appImages = $service->listAppImages($this->settings['appimage_path']);
    }

    public function launchAppImage($path)
    {
        (new AppImageService())->launch($path);
        $this->showNotification("Launching", "Starting application...");
    }

    public function registerAppImage($path, $targetDir = null)
    {
        $targetDir = $targetDir ?? $this->settings['appimage_path'];
        $service = new AppImageService();
        $success = $service->registerAppImage($path, $targetDir);
        
        if ($success) {
            $this->showNotification("Success", "AppImage integrated into your menu.");
            $this->loadAppImages();
        } else {
            $this->showNotification("Error", "Failed to integrate AppImage.", "error");
        }
    }

    public function selectAppImage()
    {
        $path = Dialog::new()
            ->title('Select AppImage')
            ->button('Select')
            ->filter('AppImage', ['AppImage', 'appimage'])
            ->open();

        if ($path) {
            $this->registerAppImage($path);
        }
    }

    public function selectAppImagePath()
    {
        $path = Dialog::new()
            ->title('Select Directory for AppImages')
            ->button('Select')
            ->folders()
            ->open();

        if ($path) {
            $this->settings['appimage_path'] = $path;
            $this->saveSettings();
        }
    }

    public function removeAppImage($path)
    {
        $this->confirmingAppImageDeletion = $path;
    }

    public function cancelAppImageDeletion()
    {
        $this->confirmingAppImageDeletion = null;
    }

    public function deleteAppImageConfirmed()
    {
        $path = $this->confirmingAppImageDeletion;
        $this->confirmingAppImageDeletion = null;

        $service = new AppImageService();
        if ($service->removeAppImage($path)) {
            $this->showNotification("Removed", "AppImage and menu entry removed.");
            $this->loadAppImages();
        } else {
            $this->showNotification("Error", "Failed to remove AppImage.", "error");
        }
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
    @if(count($pendingInstallations) > 0) wire:poll.2s="checkPendingInstallations" @endif
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

    <x-store.sidebar :$tab :$sysInfo :$pendingInstallations />

    <!-- Main Content -->
    <main class="flex-1 flex flex-col min-w-0 bg-background overflow-hidden">
        @if($tab === 'explore' || $tab === 'installed')
            <x-store.header :$search :$tab :$filterRepo :$settings />

            <!-- Content Area -->
            <div class="flex-1 overflow-y-auto">
                <div class="max-w-7xl mx-auto p-10">
                    
                    <!-- Essentials Row -->
                    @if($tab === 'explore' && (empty($search) || strlen($search) < 3))
                        <div class="mb-14">
                            <h3 class="text-xs font-black text-muted-foreground uppercase tracking-[0.2em] mb-8">Official CachyOS Tools</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                                @foreach($systemPackages as $pkg)
                                    <x-store.package-card :$pkg :$pendingInstallations wire:key="sys-{{ $pkg['name'] }}" />
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
                            <x-store.package-card :$pkg :$pendingInstallations wire:key="pkg-{{ $pkg['name'] }}-{{ $loop->index }}" />
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
                                <button wire:click="prevPage" @if($page === 1) disabled @endif class="h-10 px-4 border border-input rounded-md text-xs font-black uppercase tracking-widest transition-all hover:bg-accent disabled:opacity-30 disabled:hover:bg-transparent">Previous</button>
                                <div class="h-10 px-4 bg-accent/50 rounded-md flex items-center justify-center text-xs font-black">{{ $page }} / {{ ceil($totalResults / $settings['search_limit']) }}</div>
                                <button wire:click="nextPage" @if($page * $settings['search_limit'] >= $totalResults) disabled @endif class="h-10 px-4 border border-input rounded-md text-xs font-black uppercase tracking-widest transition-all hover:bg-accent disabled:opacity-30 disabled:hover:bg-transparent">Next</button>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @elseif($tab === 'appimages')
            <x-store.appimages-tab :$appImages />
        @elseif($tab === 'settings')
            <x-store.settings-tab :$settings />
        @endif
    </main>

    <!-- Details Overlay -->
    @if($selectedPackage)
        <x-store.details-overlay :$selectedPackage />
    @endif

    <!-- Custom Confirmation Modal for AppImages -->
    @if($confirmingAppImageDeletion)
        <div class="fixed inset-0 z-[10001] flex items-center justify-center p-6 bg-background/80 backdrop-blur-sm animate-in fade-in duration-200">
            <div class="bg-card border border-border w-full max-w-md rounded-3xl shadow-2xl overflow-hidden animate-in zoom-in-95 duration-200">
                <div class="p-8">
                    <div class="w-16 h-16 bg-destructive/10 text-destructive rounded-2xl flex items-center justify-center mb-6">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                    </div>
                    
                    <h3 class="text-2xl font-black tracking-tight mb-3">Remove AppImage?</h3>
                    <p class="text-muted-foreground leading-relaxed">
                        Are you sure you want to delete <span class="text-foreground font-bold">{{ basename($confirmingAppImageDeletion) }}</span>? 
                        This will remove the file from your disk and delete its menu entry.
                    </p>
                </div>
                
                <div class="p-6 bg-muted/30 flex items-center gap-3">
                    <button wire:click="cancelAppImageDeletion" class="flex-1 h-12 text-sm font-bold rounded-xl hover:bg-accent transition-colors">Keep it</button>
                    <button wire:click="deleteAppImageConfirmed" class="flex-1 h-12 bg-destructive text-destructive-foreground text-sm font-black rounded-xl hover:bg-destructive/90 shadow-lg shadow-destructive/20 transition-all">Delete Now</button>
                </div>
            </div>
        </div>
    @endif

    <!-- Custom Confirmation Modal for System Packages -->
    @if($confirmingPackageRemoval)
        <div class="fixed inset-0 z-[10001] flex items-center justify-center p-6 bg-background/80 backdrop-blur-sm animate-in fade-in duration-200">
            <div class="bg-card border border-border w-full max-w-md rounded-3xl shadow-2xl overflow-hidden animate-in zoom-in-95 duration-200">
                <div class="p-8">
                    <div class="w-16 h-16 bg-destructive/10 text-destructive rounded-2xl flex items-center justify-center mb-6">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                    </div>
                    
                    <h3 class="text-2xl font-black tracking-tight mb-3">Uninstall Package?</h3>
                    <p class="text-muted-foreground leading-relaxed">
                        Are you sure you want to uninstall <span class="text-foreground font-bold">{{ $confirmingPackageRemoval }}</span>? 
                        This will remove the package and its dependencies from your system.
                    </p>
                </div>
                
                <div class="p-6 bg-muted/30 flex items-center gap-3">
                    <button wire:click="cancelPackageRemoval" class="flex-1 h-12 text-sm font-bold rounded-xl hover:bg-accent transition-colors">Cancel</button>
                    <button wire:click="deletePackageConfirmed" class="flex-1 h-12 bg-destructive text-destructive-foreground text-sm font-black rounded-xl hover:bg-destructive/90 shadow-lg shadow-destructive/20 transition-all">Uninstall</button>
                </div>
            </div>
        </div>
    @endif
</div>