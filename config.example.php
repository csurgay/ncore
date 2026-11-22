<?php
/**
 * Másold config.php néven, és töltsd ki. A config.php nincs a gitben.
 */
return [
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=csurgay_ncore;charset=utf8mb4',
        'user' => 'csurgay_ncore',
        'pass' => 'IDE_A_JELSZO',
    ],

    // nCore RSS linkek a profilodból (a passkey benne van – senkinek ne add oda).
    // Érdemes kategóriánként külön feedet felvenni (pl. sorozat HUN SD, sorozat HUN HD, film HUN HD).
    'feeds' => [
        'https://ncore.pro/rss.php?key=IDE_A_PASSKEY',
    ],

    'mail' => [
        'to'        => 'te@example.com',
        'from'      => 'ncore@csurgay.com',     // csurgay.com-os cím legyen, különben spambe mehet
        'from_name' => 'nCore figyelő',
        'site_url'  => 'https://csurgay.com/ncore/',
    ],

    // A webes felület jelszavának hash-e. Előállítás:
    //   php -r 'echo password_hash("jelszavad", PASSWORD_DEFAULT), PHP_EOL;'
    'ui_password_hash' => '',
];
