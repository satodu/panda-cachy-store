<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PackageService
{
    protected $helper = null;

    /**
     * Detecta qual AUR helper está disponível (paru ou yay)
     */
    public function getHelper(): string
    {
        if ($this->helper) return $this->helper;

        $checkParu = Process::run("command -v paru");
        if ($checkParu->successful()) {
            return $this->helper = 'paru';
        }

        $checkYay = Process::run("command -v yay");
        if ($checkYay->successful()) {
            return $this->helper = 'yay';
        }

        return $this->helper = 'pacman';
    }

    /**
     * Verifica se um comando existe no sistema
     */
    public function commandExists(string $command): bool
    {
        return Process::run("command -v $command")->successful();
    }

    /**
     * Roda o cachy-update (instala se não existir)
     */
    public function runCachyUpdate(): bool
    {
        $terminals = ['konsole', 'alacritty', 'kitty', 'foot', 'gnome-terminal', 'xterm'];
        $foundTerminal = null;

        foreach ($terminals as $term) {
            if ($this->commandExists($term)) {
                $foundTerminal = $term;
                break;
            }
        }

        if ($foundTerminal) {
            $helper = $this->getHelper();
            $artisan = base_path('artisan');
            $php = PHP_BINARY;
            $callback = "{$php} {$artisan} package:finished 'System Update'";

            // Script inteligente para o terminal
            $script = "if command -v cachy-update > /dev/null; then " .
                      "  echo 'Starting CachyOS Update...'; cachy-update; " .
                      "else " .
                      "  echo 'cachy-update not found. Installing via {$helper}...'; " .
                      "  SUDO=pkexec {$helper} --skipreview --noconfirm -S cachy-update && cachy-update; " .
                      "fi; " .
                      "$callback";

            $terminalCmd = match ($foundTerminal) {
                'konsole' => "konsole -e bash -c \"$script; sleep 5\"",
                'gnome-terminal' => "gnome-terminal -- bash -c \"$script; sleep 5\"",
                default => "$foundTerminal -e bash -c \"$script; sleep 5\""
            };

            shell_exec($terminalCmd . " > /dev/null 2>&1 &");
            return true;
        }

        return false;
    }

    /**
     * Busca pacotes usando o melhor helper disponível e opcionalmente flatpak
     */
    public function search(string $query, bool $includeFlatpak = false): array
    {
        if (empty($query)) {
            return [];
        }

        $packages = Cache::remember("pkg_search_" . md5($query), 300, function () use ($query) {
            $helper = $this->getHelper();
            $result = Process::run("LC_ALL=C $helper -Ss " . escapeshellarg($query));
            
            if ($result->failed()) {
                return [];
            }

            $output = $result->output();
            $lines = explode("\n", trim($output));
            $data = [];

            $installedResult = Process::run("pacman -Qq");
            $installedNames = $installedResult->successful() ? explode("\n", trim($installedResult->output())) : [];

            for ($i = 0; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if (empty($line)) continue;

                if (preg_match('/^([^\s\/]+)\/([^\s]+)\s+([^\s]+)/', $line, $matches)) {
                    $repo = $matches[1];
                    $name = $matches[2];
                    $version = $matches[3];
                    
                    $data[] = [
                        'repo' => $repo,
                        'name' => $name,
                        'version' => $version,
                        'is_aur' => strtolower($repo) === 'aur',
                        'is_flatpak' => false,
                        'installed' => in_array($name, $installedNames),
                        'description' => isset($lines[$i+1]) ? trim($lines[$i+1]) : '',
                        'icon_url' => $this->getIcon($name, false),
                    ];
                    $i++; 
                }
            }

            return $data;
        });

        if ($includeFlatpak && $this->commandExists('flatpak')) {
            $flatpaks = $this->searchFlatpak($query);
            $packages = array_merge($packages, $flatpaks);
        }

        return $packages;
    }

    /**
     * Busca pacotes no Flathub
     */
    public function searchFlatpak(string $query): array
    {
        return Cache::remember("flatpak_search_" . md5($query), 300, function () use ($query) {
            $result = Process::run("flatpak search --columns=name,application,version,description " . escapeshellarg($query));
            
            if ($result->failed()) return [];

            $lines = explode("\n", trim($result->output()));
            $packages = [];
            
            $installed = $this->getInstalledFlatpaks();
            $installedIds = array_column($installed, 'name');

            foreach ($lines as $line) {
                $parts = explode("\t", $line);
                if (count($parts) >= 4) {
                    $packages[] = [
                        'repo' => 'flathub',
                        'name' => trim($parts[1]), // Use Application ID as name for flatpaks
                        'display_name' => trim($parts[0]),
                        'version' => trim($parts[2]),
                        'is_aur' => false,
                        'is_flatpak' => true,
                        'installed' => in_array(trim($parts[1]), $installedIds),
                        'description' => trim($parts[3]),
                        'icon_url' => $this->getIcon(trim($parts[1]), true),
                    ];
                }
            }

            return $packages;
        });
    }

    /**
     * Lista todos os pacotes instalados
     */
    public function getInstalledPackages(): array
    {
        return Cache::remember("pkg_installed_list", 60, function () {
            $result = Process::run("LC_ALL=C pacman -Q");
            if ($result->failed()) return [];

            $foreignResult = Process::run("LC_ALL=C pacman -Qm");
            $foreignNames = [];
            if ($foreignResult->successful()) {
                foreach (explode("\n", trim($foreignResult->output())) as $line) {
                    $parts = explode(' ', trim($line));
                    if (!empty($parts[0])) $foreignNames[] = $parts[0];
                }
            }

            $lines = explode("\n", trim($result->output()));
            $installed = [];

            foreach ($lines as $line) {
                $parts = explode(' ', trim($line));
                if (count($parts) >= 2) {
                    $name = $parts[0];
                    $installed[] = [
                        'name' => $name,
                        'version' => $parts[1],
                        'is_aur' => in_array($name, $foreignNames),
                        'is_flatpak' => false,
                    ];
                }
            }

            // Adiciona Flatpaks instalados
            if ($this->commandExists('flatpak')) {
                $installed = array_merge($installed, $this->getInstalledFlatpaks());
            }

            return $installed;
        });
    }

    /**
     * Lista flatpaks instalados
     */
    public function getInstalledFlatpaks(): array
    {
        $result = Process::run("flatpak list --columns=name,application,version,description");
        if ($result->failed()) return [];

        $lines = explode("\n", trim($result->output()));
        $installed = [];

        foreach ($lines as $line) {
            $parts = explode("\t", $line);
            if (count($parts) >= 3) {
                $installed[] = [
                    'repo' => 'flathub',
                    'name' => trim($parts[1]),
                    'display_name' => trim($parts[0]),
                    'version' => trim($parts[2]),
                    'is_aur' => false,
                    'is_flatpak' => true,
                    'installed' => true,
                    'description' => $parts[3] ?? '',
                ];
            }
        }

        return $installed;
    }

    /**
     * Helper para abrir terminal e retornar o PID do processo
     */
    private function openTerminal(string $command, string $callbackCmd): int|false
    {
        $terminals = ['konsole', 'alacritty', 'kitty', 'foot', 'gnome-terminal', 'xterm', 'footclient'];
        $foundTerminal = null;

        foreach ($terminals as $term) {
            if ($this->commandExists($term)) {
                $foundTerminal = $term;
                break;
            }
        }

        if (!$foundTerminal) return false;

        $terminalCmd = match ($foundTerminal) {
            'konsole' => "konsole -e bash -c \"$command; $callbackCmd; sleep 5\"",
            'gnome-terminal' => "gnome-terminal -- bash -c \"$command; $callbackCmd; sleep 5\"",
            default => "$foundTerminal -e bash -c \"$command; $callbackCmd; sleep 5\""
        };

        // Executa em background e captura o PID do terminal
        $pid = shell_exec($terminalCmd . " > /dev/null 2>&1 & echo $!");
        
        return $pid ? (int)trim($pid) : false;
    }

    /**
     * Instala um pacote
     */
    public function install(string $packageName, bool $isAur = false, bool $isFlatpak = false): int|false
    {
        $helper = $this->getHelper();
        
        Cache::put("installing_{$packageName}", true, 1800);

        $artisan = base_path('artisan');
        $php = PHP_BINARY;
        $callbackCmd = "{$php} {$artisan} package:finished " . escapeshellarg($packageName);

        if ($isFlatpak) {
            $innerCommand = "flatpak install -y flathub " . escapeshellarg($packageName);
            return $this->openTerminal($innerCommand, $callbackCmd);
        }

        if ($isAur || $helper !== 'pacman') {
            $innerCommand = ($helper === 'paru') 
                ? "paru --skipreview --noconfirm -S " . escapeshellarg($packageName)
                : "yay --noedit --noconfirm -S " . escapeshellarg($packageName);
            
            return $this->openTerminal($innerCommand, $callbackCmd);
        }

        $innerCommand = "sudo pacman -S --noconfirm " . escapeshellarg($packageName);
        return $this->openTerminal($innerCommand, $callbackCmd);
    }

    /**
     * Remove um pacote
     */
    public function remove(string $packageName, bool $isFlatpak = false): int|false
    {
        $artisan = base_path('artisan');
        $php = PHP_BINARY;
        $callbackCmd = "{$php} {$artisan} package:finished " . escapeshellarg($packageName) . " uninstalled";

        Cache::put("installing_{$packageName}", true, 600);
        
        if ($isFlatpak) {
            $innerCommand = "flatpak uninstall -y " . escapeshellarg($packageName);
        } else {
            $innerCommand = "sudo pacman -Rns --noconfirm " . escapeshellarg($packageName);
        }

        $pid = $this->openTerminal($innerCommand, $callbackCmd);
        if ($pid) {
            $this->clearCache();
        }
        return $pid;
    }

    /**
     * Limpa caches
     */
    public function clearCache()
    {
        // Não limpamos tudo para não travar a Home
        \Illuminate\Support\Facades\Cache::forget("pkg_installed_list");
    }

    /**
     * Detalhes
     */
    /**
     * Retorna a URL do ícone para o pacote
     */
    public function getIcon(string $name, bool $isFlatpak = false): string
    {
        if ($isFlatpak) {
            return "https://dl.flathub.org/repo/appstream/x86_64/icons/128x128/{$name}.png";
        }

        // Mapeamento de apps populares para ícones do Flathub (melhor qualidade)
        $mapping = [
            'discord' => 'com.discordapp.Discord',
            'spotify' => 'com.spotify.Client',
            'steam' => 'com.valvesoftware.Steam',
            'visual-studio-code-bin' => 'com.visualstudio.code',
            'vscode' => 'com.visualstudio.code',
            'brave-bin' => 'com.brave.Browser',
            'brave' => 'com.brave.Browser',
            'vlc' => 'org.videolan.VLC',
            'obs-studio' => 'com.obsproject.Studio',
            'telegram-desktop' => 'org.telegram.desktop',
            'firefox' => 'org.mozilla.firefox',
            'discord-canary' => 'com.discordapp.DiscordCanary',
            'gimp' => 'org.gimp.GIMP',
            'inkscape' => 'org.inkscape.Inkscape',
            'blender' => 'org.blender.Blender',
        ];

        $cleanName = strtolower($name);
        if (isset($mapping[$cleanName])) {
            return "https://dl.flathub.org/repo/appstream/x86_64/icons/128x128/{$mapping[$cleanName]}.png";
        }

        return ''; // Fallback para emoji no Blade
    }

    /**
     * Busca screenshots do Flathub
     */
    public function getScreenshots(string $name, bool $isFlatpak = false): array
    {
        $id = $isFlatpak ? $name : null;
        
        if (!$id) {
            $mapping = [
                'discord' => 'com.discordapp.Discord',
                'spotify' => 'com.spotify.Client',
                'steam' => 'com.valvesoftware.Steam',
                'vlc' => 'org.videolan.VLC',
                'obs-studio' => 'com.obsproject.Studio',
            ];
            $id = $mapping[strtolower($name)] ?? null;
        }

        if (!$id) return [];

        return Cache::remember("pkg_screenshots_" . $id, 86400, function () use ($id) {
            try {
                $res = Process::run("curl -s https://flathub.org/api/v2/appstream/" . escapeshellarg($id));
                if ($res->failed()) return [];
                
                $data = json_decode($res->output(), true);
                if (empty($data['screenshots'])) return [];

                $screens = [];
                foreach (array_slice($data['screenshots'], 0, 3) as $s) {
                    if (isset($s['sizes'][0]['src'])) {
                        $screens[] = $s['sizes'][0]['src'];
                    }
                }
                return $screens;
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    /**
     * Executa um comando em background com sentinela no log
     */
    public function runInBackground(string $name, bool $isAur = false, bool $isFlatpak = false, string $logFile): int
    {
        $helper = $this->getHelper();

        if ($isFlatpak) {
            $cmd = "flatpak install -y flathub " . escapeshellarg($name);
        } elseif ($isAur) {
            $aurFlags = ($helper === 'paru') ? '--skipreview --noconfirm' : '--noedit --noconfirm';
            $cmd = "$helper $aurFlags -S " . escapeshellarg($name);
        } else {
            $cmd = "pacman -S --noconfirm " . escapeshellarg($name);
        }

        // Sentinela FORA do pkexec — é escrito mesmo se o usuário cancelar a autenticação
        $inner = $cmd . " >> " . escapeshellarg($logFile) . " 2>&1";
        $fullCommand = "( pkexec sh -c " . escapeshellarg($inner)
            . " || echo 'Autenticação cancelada ou falhou.' >> " . escapeshellarg($logFile)
            . " ; echo '__PROCESS_DONE__' >> " . escapeshellarg($logFile)
            . " ) &";

        shell_exec($fullCommand);
        return 1;
    }

    /**
     * Detalhes
     */
    public function getPackageDetails(string $packageName, bool $isAur = false, bool $isFlatpak = false): ?array
    {
        $cacheKey = "pkg_details_{$packageName}";

        // Não usar cache se estiver vazio (null cacheado causaria overlay sem abrir)
        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) return $cached;
            Cache::forget($cacheKey);
        }

        $details = [];

        if ($isFlatpak) {
            // Tenta flatpak info (pacotes instalados)
            $result = Process::run("LC_ALL=C flatpak info " . escapeshellarg($packageName));

            // Fallback: flatpak remote-info (pacotes não instalados, da busca)
            if ($result->failed()) {
                $result = Process::run("LC_ALL=C flatpak remote-info flathub " . escapeshellarg($packageName));
            }

            if ($result->failed()) return null;

            $output = $result->output();
            $lines = explode("\n", trim($output));
            $details = ['Name' => $packageName, 'is_flatpak' => true];

            if (isset($lines[0]) && str_contains($lines[0], ' - ')) {
                $parts = explode(' - ', $lines[0], 2);
                $details['display_name'] = trim($parts[0]);
                $details['Description'] = trim($parts[1]);
            }

            foreach ($lines as $line) {
                if (str_contains($line, ':')) {
                    $parts = explode(':', $line, 2);
                    $key = trim($parts[0]);
                    $val = trim($parts[1]);

                    if ($key === 'Version') $details['Version'] = $val;
                    if ($key === 'License') $details['Licenses'] = $val;
                    if ($key === 'Installed') $details['Installed Size'] = $val;
                    if ($key === 'Date') $details['Build Date'] = $val;
                    if ($key === 'Arch') $details['Architecture'] = $val;
                    if ($key === 'Origin') $details['Repository'] = $val;

                    $details[$key] = $val;
                }
            }
        } else {
            $helper = $this->getHelper();
            $result = Process::run("LC_ALL=C $helper -Si " . escapeshellarg($packageName));

            if ($result->failed()) {
                $result = Process::run("LC_ALL=C pacman -Qi " . escapeshellarg($packageName));
                if ($result->failed()) return null;
            }

            $output = $result->output();
            $lines = explode("\n", $output);

            foreach ($lines as $line) {
                if (str_contains($line, ' : ')) {
                    $parts = explode(' : ', $line, 2);
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    if (!empty($key)) {
                        $details[$key] = $value;
                    }
                }
            }
        }

        // Adiciona mídia (Ícone e Screenshots)
        $details['icon_url'] = $this->getIcon($packageName, $isFlatpak);
        $details['screenshots'] = $this->getScreenshots($packageName, $isFlatpak);

        if ($isFlatpak) {
            $checkInstalled = Process::run("flatpak list --columns=application | grep -q " . escapeshellarg($packageName));
            $details['is_installed'] = $checkInstalled->successful();
        } else {
            $checkInstalled = Process::run("pacman -Qq " . escapeshellarg($packageName));
            $details['is_installed'] = $checkInstalled->successful();
        }

        // Só cacheia se tiver dados válidos
        if (!empty($details)) {
            Cache::put($cacheKey, $details, 600);
        }

        return $details ?: null;
    }
}
