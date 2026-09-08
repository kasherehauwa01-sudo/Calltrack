<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$html=(string)file_get_contents($root.'/analizmop/index.html');
foreach(['manifest.webmanifest','rel="manifest"','serviceWorker.register','beforeinstallprompt','appinstalled','apple-mobile-web-app-capable','mobile-web-app-capable'] as $removed){
    if(str_contains($html,$removed))throw new RuntimeException("PWA-маркер не удалён: {$removed}");
}
foreach(['manifest.webmanifest','pwa-icon.svg','service-worker.js'] as $file){
    if(file_exists($root.'/analizmop/'.$file))throw new RuntimeException("PWA-файл не удалён: {$file}");
}
foreach(["registration.scope===calltrackScope","registration.unregister()","key.startsWith('calltrack-pwa-')","caches.delete(key)","localStorage.setItem(cleanupKey,'done')"] as $required){
    if(!str_contains($html,$required))throw new RuntimeException("Нет безопасной миграции старой PWA: {$required}");
}
if(str_contains($html,"filter((registration)=>true")||str_contains($html,"map((key)=>caches.delete(key))\n  }"))throw new RuntimeException('Очистка затрагивает чужие Service Worker или кэши');
echo "web_pwa_removal_test: OK\n";
