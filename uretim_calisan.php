<?php
/**
 * Arka plan üretim çalışanı (CLI).
 * Çalıştırma: php uretim_calisan.php <is_id>
 * İş dosyasını okur, OpenRouter'a tek tek gider, resimleri uploads'a kaydeder,
 * ilerlemeyi ister dosyasına yazar. Sunucu (HTTP) hiç meşgul olmaz.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Sadece CLI ile çalıştırılır');
}
require __DIR__ . '/config.php';

$id = isset($argv[1]) ? basename($argv[1]) : '';
$dosya = ISLER_YOL . '/' . $id . '.json';
if ($id === '' || !is_file($dosya)) {
    fwrite(STDERR, "İş dosyası bulunamadı: $dosya\n");
    exit(1);
}

$is = json_decode(file_get_contents($dosya), true) ?: [];
$gorselId = (int)($is['model_id'] ?? 0);
$adet = max(1, min(8, (int)($is['adet'] ?? 1)));
$prompt = (string)($is['prompt'] ?? '');
$api = (string)($is['api'] ?? '');

$m = db()->prepare("SELECT * FROM modeller WHERE id=? AND tip='gorsel'");
$m->execute([$gorselId]);
$model = $m->fetch();

$tamam = 0;
$hatalar = [];
if ($model && $prompt !== '' && $api !== '') {
    for ($i = 1; $i <= $adet; $i++) {
        set_time_limit(0);
        // Görseller için OpenRouter'ın /images ucunu, olmazsa eski uçları dener
        [$ok, $sonuc] = gorsel_uret($model['model_id'], $prompt, $api);
        if ($ok && $sonuc['tur'] === 'resim') {
            $d = resim_kaydet($sonuc['url']);
            if ($d) { $tamam++; } else { $hatalar[] = "İndirme (#$i) başarısız"; }
        } else {
            $hatalar[] = "#$i: " . ($ok ? 'model metin döndürdü' : $sonuc);
        }
        // İlerlemeyi her adımda kaydet (istemci bunu okuyor)
        file_put_contents($dosya, json_encode(
            ['durum' => 'calisiyor', 'bitti' => false, 'tamam' => $tamam, 'adet' => $adet, 'hatalar' => $hatalar],
            JSON_UNESCAPED_UNICODE
        ));
    }
}

file_put_contents($dosya, json_encode(
    ['durum' => 'bitti', 'bitti' => true, 'tamam' => $tamam, 'adet' => $adet, 'hatalar' => $hatalar],
    JSON_UNESCAPED_UNICODE
));