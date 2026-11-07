<?php
/**
 * Öffentliches Beispiel ohne Schlüssel und ohne Freigabe.
 * Nicht als echte Konfiguration ins Webverzeichnis legen.
 * Kopie außerhalb des Webroots, Pfad dann in MEGACLEAN_KONTAKT_CONFIG.
 * Direkt aufgerufen liefert diese Datei absichtlich nichts Verwertbares.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

return [
    'enabled' => false,
    'mailbox_confirmed' => false,
    // Beispiel für die öffentliche Website-Adresse. mailbox_confirmed bleibt false.
    'recipient' => 'info@megacleanberlin.de',
    'from' => 'website@example.invalid',
    'allowed_hosts' => ['example.invalid'],
    'storage_dir' => '/pfad/ausserhalb/des/webroots/megaclean-kontakt',
    'hmac_key' => 'HIER_MINDESTENS_32_ZEICHEN_AUS_DEM_SERVER_NICHT_AUS_DEM_REPO',
    'transport' => 'mail',
];
