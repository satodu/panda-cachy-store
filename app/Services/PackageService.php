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
     * Busca pacotes usando o melhor helper disponível
     */
    public function search(string $query): array
    {
        if (empty($query)) {
            return [];
        }

        return Cache::remember("pkg_search_" . md5($query), 300, function () use ($query) {
            $helper = $this->getHelper();
            $result = Process::run("LC_ALL=C $helper -Ss " . escapeshellarg($query));
            
            if ($result->failed()) {
                return [];
            }

            $output = $result->output();
            $lines = explode("\n", trim($output));
            $packages = [];

            $installedResult = Process::run("pacman -Qq");
            $installedNames = $installedResult->successful() ? explode("\n", trim($installedResult->output())) : [];

            for ($i = 0; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if (empty($line)) continue;

                if (preg_match('/^([^\s\/]+)\/([^\s]+)\s+([^\s]+)/', $line, $matches)) {
                    $repo = $matches[1];
                    $name = $matches[2];
                    $version = $matches[3];
                    
                    $packages[] = [
                        'repo' => $repo,
                        'name' => $name,
                        'version' => $version,
                        'is_aur' => strtolower($repo) === 'aur',
                        'installed' => in_array($name, $installedNames),
                        'description' => isset($lines[$i+1]) ? trim($lines[$i+1]) : '',
                    ];
                    $i++; 
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
                    ];
                }
            }

            return $installed;
        });
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
    public function install(string $packageName, bool $isAur = false): int|false
    {
        $helper = $this->getHelper();
        
        Cache::put("installing_{$packageName}", true, 1800);

        $artisan = base_path('artisan');
        $php = PHP_BINARY;
        $callbackCmd = "{$php} {$artisan} package:finished " . escapeshellarg($packageName);

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
    public function remove(string $packageName): int|false
    {
        $artisan = base_path('artisan');
        $php = PHP_BINARY;
        $innerCommand = "sudo pacman -Rns --noconfirm " . escapeshellarg($packageName);
        $callbackCmd = "{$php} {$artisan} package:finished " . escapeshellarg($packageName) . " uninstalled";

        Cache::put("installing_{$packageName}", true, 600);
        
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
    public function getPackageDetails(string $packageName, bool $isAur = false): ?array
    {
        return Cache::remember("pkg_details_" . $packageName, 600, function () use ($packageName, $isAur) {
            $helper = $this->getHelper();
            $command = "LC_ALL=C $helper -Si ";
            $result = Process::run($command . escapeshellarg($packageName));

            if ($result->failed()) {
                $result = Process::run("LC_ALL=C pacman -Qi " . escapeshellarg($packageName));
                if ($result->failed()) return null;
            }

            $output = $result->output();
            $lines = explode("\n", $output);
            $details = [];

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

            $checkInstalled = Process::run("pacman -Qq " . escapeshellarg($packageName));
            $details['is_installed'] = $checkInstalled->successful();

            return $details;
        });
    }
}
