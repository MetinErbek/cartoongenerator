<?php
require __DIR__ . '/config.php';
kurulum();
giris_kontrol();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['tamam' => 0, 'hata' => 'Geçersiz istek']); exit;
}

$veri = json_decode(file_get_contents('php://input'), true);
if (!is_array($veri)) $veri = $_POST;

$hesapId = (int)($veri['hesap'] ?? 0);
$dosya   = basename($veri['dosya'] ?? '');
$caption = trim($veri['caption'] ?? '');

if ($dosya === '' || !in_array($dosya, resimler(), true)) {
    echo json_encode(['tamam' => 0, 'hata' => 'Resim bulunamadı']); exit;
}

$h = db()->prepare("SELECT * FROM instagram_hesaplar WHERE id=?");
$h->execute([$hesapId]);
$hesap = $h->fetch();
if (!$hesap) {
    echo json_encode(['tamam' => 0, 'hata' => 'Hesap seçilemedi']); exit;
}

session_write_close(); // uzun istek sürerken oturumu serbest bırak

$gorselUrl = resim_public_url($dosya);
[$ok, $sonuc] = instagram_paylas($hesap['ig_user_id'], $hesap['access_token'], $gorselUrl, $caption);

echo json_encode($ok ? ['tamam' => 1, 'medya' => $sonuc] : ['tamam' => 0, 'hata' => $sonuc], JSON_UNESCAPED_UNICODE);
