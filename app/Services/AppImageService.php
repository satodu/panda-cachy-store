<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class AppImageService
{
    /**
     * Get the default AppImage directory if not set.
     */
    public function getDefaultDirectory(): string
    {
        return getenv('HOME') . '/Applications';
    }

    /**
     * List all AppImages in the given directory and their .desktop status.
     */
    public function listAppImages(string $directory): array
    {
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $files = File::files($directory);
        $appImages = [];

        foreach ($files as $file) {
            $extension = $file->getExtension();
            if (in_array(strtolower($extension), ['appimage'])) {
                $name = $file->getFilename();
                $path = $file->getRealPath();
                
                $appImages[] = [
                    'name' => $name,
                    'path' => $path,
                    'size' => $this->formatBytes($file->getSize()),
                    'has_desktop' => $this->hasDesktopEntry($name),
                    'icon_url' => $this->getIconUrl($name),
                ];
            }
        }

        return $appImages;
    }

    /**
     * Get a browser-accessible URL or path for the icon.
     */
    public function getIconUrl(string $fileName): ?string
    {
        $id = md5($fileName);
        $path = getenv('HOME') . '/.local/share/icons/appimage-' . $id . '.png';
        
        if (File::exists($path)) {
            $data = File::get($path);
            $base64 = base64_encode($data);
            return "data:image/png;base64,{$base64}";
        }
        return null;
    }

    /**
     * Check if a .desktop entry exists for the AppImage.
     */
    public function hasDesktopEntry(string $fileName): bool
    {
        $desktopPath = getenv('HOME') . '/.local/share/applications/appimage-' . md5($fileName) . '.desktop';
        return File::exists($desktopPath);
    }

    /**
     * Register an AppImage: move to target dir, chmod +x, and create .desktop file.
     */
    public function registerAppImage(string $sourcePath, string $targetDir): bool
    {
        if (!File::exists($sourcePath)) return false;
        
        if (!File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        $fileName = basename($sourcePath);
        $targetPath = $targetDir . '/' . $fileName;

        // Move file if it's not already there
        if ($sourcePath !== $targetPath) {
            File::copy($sourcePath, $targetPath);
        }

        // Make executable
        chmod($targetPath, 0755);

        // Create .desktop entry
        return $this->createDesktopEntry($fileName, $targetPath);
    }

    /**
     * Create a .desktop entry for the AppImage.
     */
    public function createDesktopEntry(string $fileName, string $execPath): bool
    {
        $name = str_replace(['.AppImage', '.appimage'], '', $fileName);
        $id = md5($fileName);
        $desktopPath = getenv('HOME') . '/.local/share/applications/appimage-' . $id . '.desktop';

        $iconPath = $this->extractIcon($execPath, $id) ?: 'system-run';

        $content = "[Desktop Entry]\n";
        $content .= "Type=Application\n";
        $content .= "Name=" . $name . " (AppImage)\n";
        $content .= "Exec=\"" . $execPath . "\"\n";
        $content .= "Icon=" . $iconPath . "\n";
        $content .= "Categories=Utility;\n";
        $content .= "Terminal=false\n";
        $content .= "Comment=Installed via CachyOS Store\n";

        return File::put($desktopPath, $content) !== false;
    }

    /**
     * Try to extract the icon from the AppImage.
     */
    protected function extractIcon(string $execPath, string $id): ?string
    {
        $iconDir = getenv('HOME') . '/.local/share/icons';
        if (!File::exists($iconDir)) {
            File::makeDirectory($iconDir, 0755, true);
        }

        $targetIconPath = $iconDir . '/appimage-' . $id . '.png';
        
        // If already exists, return it
        if (File::exists($targetIconPath)) return $targetIconPath;

        $tempDir = storage_path('app/temp_icon_' . $id);
        if (File::exists($tempDir)) File::deleteDirectory($tempDir);
        File::makeDirectory($tempDir, 0755, true);
        
        try {
            // Ensure executable
            chmod($execPath, 0755);
            
            // Try to extract .DirIcon
            $command = "cd " . escapeshellarg($tempDir) . " && " . escapeshellarg($execPath) . " --appimage-extract .DirIcon > /dev/null 2>&1";
            shell_exec($command);

            $extracted = $tempDir . '/squashfs-root/.DirIcon';
            if (File::exists($extracted)) {
                // Use cp -L to follow symlinks if .DirIcon is a link
                shell_exec("cp -L " . escapeshellarg($extracted) . " " . escapeshellarg($targetIconPath));
                
                if (File::exists($targetIconPath)) {
                    File::deleteDirectory($tempDir);
                    return $targetIconPath;
                }
            }

            // Fallback: try to extract any PNG in the root
            $command = "cd " . escapeshellarg($tempDir) . " && " . escapeshellarg($execPath) . " --appimage-extract \"*.png\" > /dev/null 2>&1";
            shell_exec($command);
            
            $files = File::files($tempDir . '/squashfs-root');
            foreach ($files as $file) {
                if (strtolower($file->getExtension()) === 'png') {
                    File::copy($file->getRealPath(), $targetIconPath);
                    File::deleteDirectory($tempDir);
                    return $targetIconPath;
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to extract icon: " . $e->getMessage());
        }

        if (File::exists($tempDir)) File::deleteDirectory($tempDir);
        return null;
    }

    /**
     * Remove an AppImage and its .desktop entry.
     */
    public function removeAppImage(string $path): bool
    {
        if (File::exists($path)) {
            $fileName = basename($path);
            $id = md5($fileName);
            $desktopPath = getenv('HOME') . '/.local/share/applications/appimage-' . $id . '.desktop';
            $iconPath = getenv('HOME') . '/.local/share/icons/appimage-' . $id . '.png';
            
            if (File::exists($desktopPath)) {
                File::delete($desktopPath);
            }

            if (File::exists($iconPath)) {
                File::delete($iconPath);
            }

            return File::delete($path);
        }
        return false;
    }

    /**
     * Launch an AppImage in the background.
     */
    public function launch(string $path): void
    {
        if (File::exists($path)) {
            chmod($path, 0755);
            shell_exec(escapeshellarg($path) . " > /dev/null 2>&1 &");
        }
    }

    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
