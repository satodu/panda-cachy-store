<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Native\Laravel\Facades\Notification;
use Illuminate\Support\Facades\Cache;

class PackageFinishedCommand extends Command
{
    protected $signature = 'package:finished {name}';
    protected $description = 'Avisa o app que a instalação de um pacote terminou';

    public function handle()
    {
        $name = $this->argument('name');
        
        // Limpa o cache para garantir que a lista de instalados atualize
        Cache::flush();
        
        // Remove a flag de "instalando"
        Cache::forget("installing_{$name}");

        // Dispara a notificação oficial do sistema
        Notification::new()
            ->title('Instalação Concluída')
            ->message("O pacote {$name} foi instalado com sucesso!")
            ->show();

        $this->info("Notificação enviada para {$name}");
    }
}
