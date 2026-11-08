<?php
// Basit yapılandırma + yardımcılar
session_start();

define('DB_YOL', __DIR__ . '/data/comics.db');
define('UPLOAD_YOL', __DIR__ . '/uploads');
define('ISLER_YOL', __DIR__ . '/data/isler'); // arka plan üretim işleri

// Şifre, anahtarlar ve adresler env.php dosyasından gelir.
// env.php yoksa aşağıdaki varsayılan değerler kullanılır.
if (is_file(__DIR__ . '/env.php')) require __DIR__ . '/env.php';

if (!defined('PAROLA'))          define('PAROLA', 'm1');                    // tek giriş şifresi
if (!defined('IG_HOST'))         define('IG_HOST', 'graph.instagram.com');  // Instagram Girişi yolu
if (!defined('IG_SURUM'))        define('IG_SURUM', 'v26.0');               // Graph API sürümü
if (!defined('IG_APP_ID'))       define('IG_APP_ID', '');
if (!defined('IG_APP_SECRET'))   define('IG_APP_SECRET', '');
if (!defined('IG_REDIRECT_URI')) define('IG_REDIRECT_URI', '');
if (!defined('PUBLIC_BASE_URL')) define('PUBLIC_BASE_URL', '');

if (!is_dir(dirname(DB_YOL))) { mkdir(dirname(DB_YOL), 0777, true); }
if (!is_dir(UPLOAD_YOL))      { mkdir(UPLOAD_YOL, 0777, true); }
if (!is_dir(ISLER_YOL))       { mkdir(ISLER_YOL, 0777, true); }

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_YOL);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Windows'ta yazma hızı için: WAL + daha az fsync + bekleme/busy zaman aşımı
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA temp_store = MEMORY');
    }
    return $pdo;
}

function kurulum(): void {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS ayarlar (anahtar TEXT PRIMARY KEY, deger TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS modeller (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ad TEXT NOT NULL,
        model_id TEXT NOT NULL,
        tip TEXT NOT NULL DEFAULT 'gorsel',
        kategori TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS promptlar (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ad TEXT NOT NULL,
        sektor TEXT NOT NULL DEFAULT '',
        metin TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS instagram_hesaplar (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ad TEXT NOT NULL,
        ig_user_id TEXT NOT NULL,
        access_token TEXT NOT NULL
    )");
    // İlk kullanımda birkaç örnek prompt taslağı
    $var = (int)$pdo->query("SELECT COUNT(*) FROM promptlar")->fetchColumn();
    if ($var === 0) {
        $ilk = [
            ['Restoran Karikatürü', 'Restoran', 'Bir restoran masasında garson ile müşteri arasında çok komik diyalog. Konuşma balonları olsun ve balonlarda Türkçe esprili kısa cümleler yazılı olsun. Karikatür tarzı, renkli çizim.'],
            ['İnşaat Karikatürü', 'İnşaat', 'Şantiyede ustalar arasında çok komik diyalog. Konuşma balonları olsun ve balonlarda Türkçe esprili kısa cümleler yazılı olsun. Karikatür tarzı, renkli çizim.'],
            ['Kafe Karikatürü', 'Kafe', 'Kafede barista ile müşteri arasında çok komik diyalog. Konuşma balonları olsun ve balonlarda Türkçe esprili kısa cümleler yazılı olsun. Karikatür tarzı, renkli çizim.'],
            ['Market Karikatürü', 'Market', 'Market kasanında kasiyer ile müşteri arasında çok komik diyalog. Konuşma balonları olsun ve balonlarda Türkçe esprili kısa cümleler yazılı olsun. Karikatür tarzı, renkli çizim.'],
            ['Genel Absürt', 'Genel', 'Absürt ve çok komik bir karikatür. Hayvan karakterler kullan, konuşma balonlarında Türkçe esprili cümleler olsun. Karikatür tarzı, renkli çizim.'],
        ];
        $h = $pdo->prepare("INSERT INTO promptlar (ad, sektor, metin) VALUES (?,?,?)");
        foreach ($ilk as $p) { $h->execute($p); }
    }
}

function giris_kontrol(): void {
    if (empty($_SESSION['giris'])) { header('Location: index.php'); exit; }
}

function api_anahtari(): string {
    $r = db()->query("SELECT deger FROM ayarlar WHERE anahtar='openrouter_api_key'")->fetch();
    return $r['deger'] ?? '';
}

function api_anahtari_kaydet(string $k): void {
    $h = db()->prepare("INSERT INTO ayarlar (anahtar,deger) VALUES ('openrouter_api_key',?) ON CONFLICT(anahtar) DO UPDATE SET deger=excluded.deger");
    $h->execute([trim($k)]);
}

// OpenRouter'a istek gönderir. Ek resim (data URI) gönderilebilir.
// Dönüş: [true, ['tur'=>'resim','url'=>..]] | [true, ['tur'=>'metin','metin'=>..]] | [false, 'hata']
function openrouter_iste(string $model, string $icerik, string $api, ?string $gorselVeri = null): array {
    $mesaj = ['role' => 'user'];
    if ($gorselVeri !== null) {
        $mesaj['content'] = [
            ['type' => 'text', 'text' => $icerik],
            ['type' => 'image_url', 'image_url' => ['url' => $gorselVeri]],
        ];
    } else {
        $mesaj['content'] = $icerik;
    }
    $g = json_encode(['model' => $model, 'messages' => [$mesaj]]);

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $api, 'Content-Type: application/json'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $g,
        CURLOPT_TIMEOUT => 300,
    ]);
    $yanit = curl_exec($ch);
    $hata = curl_error($ch);
    curl_close($ch);

    if ($yanit === false || $yanit === '') return [false, 'Ağ hatası: ' . $hata];
    $v = json_decode($yanit, true);
    if (isset($v['error'])) return [false, $v['error']['message'] ?? 'API hatası'];

    $out = $v['output'] ?? [];
    if (isset($out[0]['type']) && $out[0]['type'] === 'image' && !empty($out[0]['url'])) {
        return [true, ['tur' => 'resim', 'url' => $out[0]['url']]];
    }
    if (isset($out[0]['type']) && $out[0]['type'] === 'text') {
        return [true, ['tur' => 'metin', 'metin' => $out[0]['text']]];
    }
    $kanal = $v['choices'][0]['message']['content'] ?? '';
    if (is_array($kanal)) { $kanal = $kanal[0]['text'] ?? json_encode($kanal); }
    $kanal = trim((string)$kanal);
    return $kanal !== '' ? [true, ['tur' => 'metin', 'metin' => $kanal]] : [false, 'Yanıt çözümlenemedi: ' . substr($yanit, 0, 300)];
}

// Görsel üretimi: OpenRouter'ın yeni Unified Image API'si (/images). URL veya base64 (b64_json + media_type) dönebilir.
function gorsel_iste_uc(string $uc, string $model, string $icerik, string $api): array {
    $g = json_encode(['model' => $model, 'prompt' => $icerik]);
    $ch = curl_init($uc);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $api, 'Content-Type: application/json'],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $g,
        CURLOPT_TIMEOUT => 300,
    ]);
    $yanit = curl_exec($ch);
    $hata = curl_error($ch);
    curl_close($ch);

    if ($yanit === false || $yanit === '') return [false, 'Ağ hatası: ' . $hata];
    $v = json_decode($yanit, true);
    if (isset($v['error'])) return [false, $v['error']['message'] ?? 'Görsel API hatası'];

    $veri = $v['data'][0] ?? null;
    if (is_array($veri)) {
        if (!empty($veri['url'])) return [true, ['tur' => 'resim', 'url' => $veri['url']]];
        if (!empty($veri['b64_json'])) {
            $mt = $veri['media_type'] ?? 'image/png';
            return [true, ['tur' => 'resim', 'url' => 'data:' . $mt . ';base64,' . $veri['b64_json']]];
        }
    }
    return [false, 'Görsel verisi bulunamadı: ' . substr($yanit, 0, 200)];
}

// Görsel üretim ana yolu: /images (yeni), sonra /images/generations (eski), en son chat ucu (flux-schnell vb.)
function gorsel_uret(string $model, string $icerik, string $api): array {
    // Mümkünse JPEG/JPG vermesini iste (uzantıyı model belirler; ama öncelik JPG olsun)
    $icerik .= ' (Görseli mümkünse JPEG/JPG formatında üret; uzantıyı sen belirle, öncelik JPG olsun.)';
    $hatalar = [];
    foreach (['https://openrouter.ai/api/v1/images', 'https://openrouter.ai/api/v1/images/generations'] as $uc) {
        [$ok, $sonuc] = gorsel_iste_uc($uc, $model, $icerik, $api);
        if ($ok) return [$ok, $sonuc];
        $hatalar[] = $sonuc;
    }
    [$ok, $sonuc] = openrouter_iste($model, $icerik, $api);
    if ($ok) return [$ok, $sonuc];
    return [false, implode(' • ', array_filter($hatalar)) . ($sonuc ? ' • Chat: ' . $sonuc : '')];
}

// WebP baytını PNG baytına çevirir; GD yoksa ya da çevrilemezse null döner
function webp_png_cevir(string $bayt): ?string {
    if (!function_exists('imagepng')) return null;
    $im = @imagecreatefromstring($bayt);
    if ($im === false && function_exists('imagecreatefromwebp')) {
        // Bazı GD derlemeleri string'den webp açmaz; geçici dosya ile dene
        $tmp = tempnam(sys_get_temp_dir(), 'w2p');
        if ($tmp !== false) {
            file_put_contents($tmp, $bayt);
            $im = @imagecreatefromwebp($tmp);
            @unlink($tmp);
        }
    }
    if ($im === false) return null;
    ob_start();
    imagepng($im, null, 9);
    $cikti = ob_get_clean();
    imagedestroy($im);
    return $cikti ?: null;
}

// URL veya data URI'den resmi uploads klasörüne kaydeder, dosya adını döner
function resim_kaydet(string $url): ?string {
    $uzanti = 'png';
    $bayt = null;
    $webp = false;

    if (strpos($url, 'data:image') === 0) {
        $mime = substr($url, 5, strpos($url, ';') - 5);
        if (strpos($mime, 'jpeg') !== false) $uzanti = 'jpg';
        if (strpos($mime, 'webp') !== false) { $uzanti = 'webp'; $webp = true; }
        if (strpos($mime, 'gif') !== false)  $uzanti = 'gif';
        $bayt = base64_decode(substr($url, strpos($url, ',') + 1));
    } else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120]);
        $bayt = curl_exec($ch);
        $tip = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if (strpos($tip, 'jpeg') !== false) $uzanti = 'jpg';
        if (strpos($tip, 'webp') !== false) { $uzanti = 'webp'; $webp = true; }
        if (strpos($tip, 'gif') !== false)  $uzanti = 'gif';
    }

    if (!$bayt || strlen($bayt) < 100) return null;

    // WebP gelenleri PNG'ye çevir (tarayıcı/kullanım uyumluluğu için)
    if ($webp) {
        $cevrilen = webp_png_cevir($bayt);
        if ($cevrilen !== null) { $bayt = $cevrilen; $uzanti = 'png'; }
    }

    $dosya = 'kc_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $uzanti;
    if (file_put_contents(UPLOAD_YOL . '/' . $dosya, $bayt) === false) return null;
    return $dosya;
}

// Uploads klasöründeki resimleri yeni → eski sıralı listeler (limit=0 ise hepsi)
function resimler(int $limit = 0, int $offset = 0): array {
    $ds = glob(UPLOAD_YOL . '/*.{png,jpg,jpeg,gif,webp}', GLOB_BRACE) ?: [];
    usort($ds, fn($a, $b) => filemtime($b) <=> filemtime($a));
    if ($limit > 0) { $ds = array_slice($ds, $offset, $limit); }
    return array_map('basename', $ds);
}

// Uploads klasöründeki toplam resim sayısı
function resim_sayisi(): int {
    return count(glob(UPLOAD_YOL . '/*.{png,jpg,jpeg,gif,webp}', GLOB_BRACE) ?: []);
}

// Resmin yanındaki .txt başlık dosyası (caption) işlemleri
function baslik_oku(string $dosya): string {
    $f = UPLOAD_YOL . '/' . basename($dosya) . '.txt';
    return is_file($f) ? trim(file_get_contents($f)) : '';
}

// Başlık metnini tırnak/parantez/süs işaretlerinden arındırır
function baslik_sus_sok(string $m): string {
    return trim($m, " \t\n\r\"'«»“”‘’()[]*•-");
}

// Chat modelinin "İşte başlığın:", "Başlık:", açıklama vb. ekstra metinlerini temizler, sadece caption + etiketler kalır
function baslik_temizle(string $m): string {
    $m = trim($m);
    $satirlar = preg_split('/\r?\n/', $m);
    $ana = '';

    // Satırları tara: "önek: başlık" kalıplı bir satır varsa onu kullan (çok satırlı açıklamaları atlar)
    foreach ($satirlar as $satir) {
        $s = trim($satir);
        $kolon = mb_strpos($s, ':');
        if ($kolon !== false && $kolon <= 50) {
            $onEk = mb_strtolower(mb_substr($s, 0, $kolon), 'UTF-8');
            $onEk = str_replace('i̇', 'i', $onEk); // "İ" küçültmesindeki nokta uyumsuzluğunu düzelt
            foreach (['başlık', 'caption', 'isim', 'işte', 'öneri', 'tamam', 'cevap', 'sonuç', 'açıklama', 'karikatür', 'için'] as $k) {
                if (mb_strpos($onEk, $k) !== false) {
                    $ana = baslik_sus_sok(mb_substr($s, $kolon + 1));
                    break 2;
                }
            }
        }
    }
    // Hiçbir satırda "önek:" kalıbı yoksa ilk satırı ver
    if ($ana === '') $ana = baslik_sus_sok($satirlar[0]);

    // Etiket (hashtag) içeren satırlar varsa başlığa ekle; zaten başlıkta varsa tekrar etme
    foreach ($satirlar as $satir) {
        $s = trim($satir);
        if ($s === '' || mb_strpos($s, '#') === false) continue;
        $parca = baslik_sus_sok($s);
        if ($parca === '' || mb_strpos($ana, $parca) !== false) continue;
        $ana = rtrim($ana) . ' ' . $parca;
    }
    return $ana;
}

function baslik_yaz(string $dosya, string $metin): void {
    file_put_contents(UPLOAD_YOL . '/' . basename($dosya) . '.txt', baslik_temizle($metin));
}

// ---- Instagram yardımcıları ----
function instagram_hesaplar(): array {
    return db()->query("SELECT * FROM instagram_hesaplar ORDER BY ad")->fetchAll();
}

// ---- Instagram OAuth ("Instagram ile Bağlan") ----
// Basit HTTP yardımcısı: $veri verilirse POST, verilmezse GET yapar.
// Dönüş: [true, dizi] | [false, 'hata']
function ig_http(string $url, ?array $veri = null): array {
    $ch = curl_init($url);
    $ayar = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60];
    if ($veri !== null) {
        $ayar[CURLOPT_POST] = true;
        $ayar[CURLOPT_POSTFIELDS] = http_build_query($veri);
    }
    curl_setopt_array($ch, $ayar);
    $yanit = curl_exec($ch);
    $hata = curl_error($ch);
    curl_close($ch);
    if ($yanit === false || $yanit === '') return [false, 'Ağ hatası: ' . $hata];
    $v = json_decode($yanit, true);
    if (!is_array($v)) return [false, 'Geçersiz yanıt: ' . substr($yanit, 0, 200)];
    if (isset($v['error'])) {
        $mesaj = is_array($v['error']) ? ($v['error']['message'] ?? 'Bilinmeyen hata') : $v['error'];
        return [false, $mesaj];
    }
    return [true, $v];
}

// Kullanıcının yönlendirileceği yetkilendirme (login) adresi
function instagram_baglanti_url(string $durum): string {
    return 'https://www.instagram.com/oauth/authorize?' . http_build_query([
        'client_id'     => IG_APP_ID,
        'redirect_uri'  => IG_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'instagram_business_basic,instagram_business_content_publish',
        'state'         => $durum,
    ]);
}

// Dönen kodu kısa ömürlü token'a çevirir (token + kullanıcı ID)
function instagram_kod_degistir(string $kod): array {
    [$ok, $v] = ig_http('https://api.instagram.com/oauth/access_token', [
        'client_id'     => IG_APP_ID,
        'client_secret' => IG_APP_SECRET,
        'grant_type'    => 'authorization_code',
        'redirect_uri'  => IG_REDIRECT_URI,
        'code'          => $kod,
    ]);
    if (!$ok) return [false, $v];
    if (empty($v['access_token'])) return [false, 'Kod değiştirilemedi: ' . substr(json_encode($v), 0, 200)];
    return [true, ['token' => $v['access_token'], 'user_id' => (string)($v['user_id'] ?? '')]];
}

// Kısa ömürlü token'ı 60 günlük uzun ömürlü token'a çevirir
function instagram_uzun_token(string $kisaToken): array {
    [$ok, $v] = ig_http('https://' . IG_HOST . '/access_token?' . http_build_query([
        'grant_type'    => 'ig_exchange_token',
        'client_secret' => IG_APP_SECRET,
        'access_token'  => $kisaToken,
    ]));
    if (!$ok) return [false, $v];
    return [true, $v['access_token'] ?? $kisaToken];
}

// Uzun ömürlü token'ı süresi dolmadan yeniler
function instagram_token_yenile(string $token): array {
    [$ok, $v] = ig_http('https://' . IG_HOST . '/refresh_access_token?' . http_build_query([
        'grant_type'   => 'ig_refresh_token',
        'access_token' => $token,
    ]));
    if (!$ok) return [false, $v];
    return [true, $v['access_token'] ?? $token];
}

// Token ile profil bilgisi (kullanıcı adı, hesap tipi)
function instagram_profil(string $token): array {
    [$ok, $v] = ig_http('https://' . IG_HOST . '/' . IG_SURUM . '/me?' . http_build_query([
        'fields'       => 'user_id,username,account_type',
        'access_token' => $token,
    ]));
    if (!$ok) return [false, $v];
    return [true, [
        'user_id'      => (string)($v['user_id'] ?? $v['id'] ?? ''),
        'username'     => (string)($v['username'] ?? ''),
        'account_type' => (string)($v['account_type'] ?? ''),
    ]];
}

// Resmin internetten erişilebilir (public) tam adresini üretir
function resim_public_url(string $dosya): string {
    $taban = PUBLIC_BASE_URL !== '' ? rtrim(PUBLIC_BASE_URL, '/') : '';
    if ($taban === '') {
        $semasi = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $yol = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        if ($yol === '.' || $yol === '') $yol = '';
        $taban = $semasi . '://' . $host . $yol;
    }
    return $taban . '/uploads/' . rawurlencode(basename($dosya));
}

// Instagram'da resmi yayınlar (Instagram Girişi): 1) konteyner oluştur, 2) yayınla.
// Dönüş: [true, media_id] | [false, 'hata']
function instagram_paylas(string $igUserId, string $token, string $gorselUrl, string $caption): array {
    $temel = 'https://' . IG_HOST . '/' . IG_SURUM . '/' . rawurlencode($igUserId);

    // 1) Konteyner oluştur
    $ch = curl_init($temel . '/media');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'image_url'    => $gorselUrl,
            'caption'      => $caption,
            'access_token' => $token,
        ]),
        CURLOPT_TIMEOUT => 120,
    ]);
    $yanit = curl_exec($ch);
    $hata = curl_error($ch);
    curl_close($ch);
    if ($yanit === false || $yanit === '') return [false, 'Ağ hatası: ' . $hata];
    $v = json_decode($yanit, true);
    if (isset($v['error'])) return [false, $v['error']['message'] ?? 'Instagram hatası'];
    $olusturId = $v['id'] ?? '';
    if ($olusturId === '') return [false, 'Konteyner oluşturulamadı: ' . substr($yanit, 0, 200)];

    // 2) Yayınla
    $ch = curl_init($temel . '/media_publish');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'creation_id'  => $olusturId,
            'access_token' => $token,
        ]),
        CURLOPT_TIMEOUT => 120,
    ]);
    $yanit2 = curl_exec($ch);
    $hata2 = curl_error($ch);
    curl_close($ch);
    if ($yanit2 === false || $yanit2 === '') return [false, 'Ağ hatası: ' . $hata2];
    $v2 = json_decode($yanit2, true);
    if (isset($v2['error'])) return [false, $v2['error']['message'] ?? 'Instagram yayın hatası'];
    $medyaId = $v2['id'] ?? '';
    if ($medyaId === '') return [false, 'Yayınlanamadı: ' . substr($yanit2, 0, 200)];
    return [true, $medyaId];
}

function flash_ekle(string $m): void { $_SESSION['flash'][] = $m; }
function flash_al(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function sektorler(): array {
    return ['Genel', 'Restoran', 'Kafe', 'Market', 'İnşaat', 'Berber', 'Kuaför', 'Oto Servis', 'Fırın', 'Kasap', 'Manav', 'Giyim'];
}

function ust_sayfa(string $aktif): void {
    $linkler = [
        'generate.php'      => ['Üret', 'fa-solid fa-wand-magic-sparkles'],
        'eski_resimler.php' => ['Eski Resimler', 'fa-solid fa-clock-rotate-left'],
        'models.php'        => ['Modeller', 'fa-solid fa-robot'],
        'prompts.php'       => ['Promptlar', 'fa-solid fa-comment-dots'],
        'instagram.php'     => ['Instagram', 'fa-brands fa-instagram'],
    ];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Komik Sahne Üretici</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script>
tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } } };
</script>
<style type="text/tailwindcss">
.input { @apply w-full rounded-xl bg-stone-800 border-2 border-stone-700 px-4 py-4 text-lg text-stone-100 placeholder-stone-500 focus:outline-none focus:border-amber-400 transition; }
.label { @apply block text-sm font-bold uppercase tracking-wider text-stone-400 mb-2; }
.btn-main { @apply inline-flex items-center justify-center gap-2 w-full rounded-2xl bg-amber-400 hover:bg-amber-300 text-stone-950 text-xl font-extrabold py-5 shadow-lg shadow-amber-500/20 transition active:scale-95; }
.btn-ikincil { @apply inline-flex items-center justify-center gap-2 rounded-2xl bg-stone-800 hover:bg-stone-700 text-amber-300 text-lg font-extrabold px-5 py-3 border border-stone-700 transition active:scale-95; }
.btn-tehlike { @apply inline-flex items-center justify-center gap-2 rounded-2xl bg-red-900/60 hover:bg-red-800 text-red-200 text-lg font-extrabold px-5 py-3 border border-red-800 transition active:scale-95; }
.card { @apply bg-stone-900 border border-stone-800 rounded-3xl p-6 md:p-8 shadow-xl; }
</style>
</head>
<body class="bg-stone-950 text-stone-100 font-sans min-h-screen pb-28 md:pb-10">
<header class="bg-stone-900/80 backdrop-blur sticky top-0 z-40 border-b border-stone-800">
  <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between gap-3">
    <a href="generate.php" class="flex items-center gap-3">
      <span class="text-3xl">🎭</span>
      <span class="font-black text-2xl text-amber-400 tracking-tight">Komik Sahne Üretici</span>
    </a>
    <div class="hidden md:flex items-center gap-1.5">
      <?php foreach ($linkler as $dosya => $l):
          $aktiflik = $dosya === $aktif ? 'bg-amber-400 text-stone-950' : 'bg-stone-800 text-stone-200 hover:bg-stone-700'; ?>
          <a href="<?= $dosya ?>" class="<?= $aktiflik ?> rounded-lg px-3 py-2 text-sm font-bold transition"><i class="<?= $l[1] ?> mr-1.5"></i><?= $l[0] ?></a>
      <?php endforeach; ?>
      <a href="index.php?cikis=1" title="Çıkış" class="bg-stone-800 text-red-300 hover:bg-red-900/50 rounded-lg px-3 py-2 text-sm font-bold transition"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
  </div>
</header>

<nav class="fixed bottom-0 inset-x-0 z-40 bg-stone-900/95 backdrop-blur border-t border-stone-800 md:hidden flex justify-around py-2">
  <?php foreach ($linkler as $dosya => $l):
      $aktiflik = $dosya === $aktif ? 'text-amber-400' : 'text-stone-400'; ?>
      <a href="<?= $dosya ?>" class="<?= $aktiflik ?> flex flex-col items-center py-1 px-2 rounded-xl text-[10px] font-bold gap-0.5"><i class="<?= $l[1] ?> text-lg"></i><?= $l[0] ?></a>
  <?php endforeach; ?>
  <a href="index.php?cikis=1" class="flex flex-col items-center py-1 px-3 rounded-xl text-[10px] font-bold gap-0.5 text-red-400"><i class="fa-solid fa-right-from-bracket text-lg"></i>Çıkış</a>
</nav>

<main class="max-w-6xl mx-auto px-4 py-6 md:py-10 space-y-8">
<?php
    foreach (flash_al() as $f) { echo '<div class="bg-emerald-900/50 border border-emerald-700 text-emerald-200 rounded-2xl px-6 py-4 text-xl font-bold">' . htmlspecialchars($f, ENT_QUOTES) . '</div>'; }
}

function alt_sayfa(): void {
?>
</main>
<footer class="max-w-6xl mx-auto px-4 pb-6 text-center text-stone-500 text-sm">uploads klasöründe biriken resimler otomatik listelenir.</footer>
<script>
// Metin kutusunu kopyala (caption'ların yanındaki Kopyala butonu için)
function kopyala(btn) {
  var kutu = btn.closest('div').querySelector('textarea');
  if (!kutu) return;
  var eski = btn.innerHTML;
  var bitti = function () {
    btn.innerHTML = '<i class="fa-solid fa-check"></i> Kopyalandı';
    setTimeout(function () { btn.innerHTML = eski; }, 1600);
  };
  var eskiYontem = function () {
    kutu.focus(); kutu.select(); kutu.setSelectionRange(0, kutu.value.length);
    try { document.execCommand('copy'); } catch (e) {}
    bitti();
  };
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(kutu.value).then(bitti).catch(eskiYontem);
  } else {
    eskiYontem();
  }
}

// Resme tıklanınca büyük haliyle popup
function resimPopup(src) {
  Swal.fire({
    html: '<img src="' + src + '" class="max-w-full max-h-[85vh] rounded-2xl" style="margin:0 auto;display:block">',
    showConfirmButton: false,
    showCloseButton: true,
    background: '#1c1917',
    padding: '1rem'
  });
}

// Instagram paylaşım modalı (hesap seç + caption düzenle + paylaş)
var IG_HESAPLAR = <?= json_encode(instagram_hesaplar(), JSON_UNESCAPED_UNICODE) ?>;
function paylasModal(dosya, btn) {
  if (!IG_HESAPLAR.length) {
    Swal.fire({
      icon: 'info', title: 'Instagram hesabı yok',
      html: 'Önce <a href="instagram.php" class="underline font-bold" style="color:#fbbf24">Instagram sayfasından</a> bir hesap ekle.',
      background: '#1c1917', color: '#f5f5f4', confirmButtonColor: '#fbbf24'
    });
    return;
  }
  // Caption'ı kartın içindeki textarea'dan al
  var baslik = '';
  var kart = btn ? btn.closest('.rounded-2xl') : null;
  if (kart) { var ta = kart.querySelector('textarea'); if (ta) baslik = ta.value; }
  var secenekler = IG_HESAPLAR.map(function (h) { return '<option value="' + h.id + '">' + h.ad + '</option>'; }).join('');

  Swal.fire({
    title: 'Instagram\'da Paylaş',
    html:
      '<img src="uploads/' + encodeURIComponent(dosya) + '" class="w-full max-h-40 object-cover rounded-xl mb-4" style="margin:0 auto;display:block">' +
      '<label class="block text-left text-sm font-bold uppercase mb-1" style="color:#a8a29e">Hesap</label>' +
      '<select id="ig_hesap" class="swal2-input" style="width:100%;max-width:100%;margin:0">' + secenekler + '</select>' +
      '<label class="block text-left text-sm font-bold uppercase mt-3 mb-1" style="color:#a8a29e">Caption</label>' +
      '<textarea id="ig_caption" rows="5" class="swal2-textarea" style="width:100%;max-width:100%;margin:0"></textarea>',
    showCancelButton: true,
    confirmButtonText: '<i class="fa-brands fa-instagram"></i> Paylaş',
    cancelButtonText: 'İptal',
    background: '#1c1917', color: '#f5f5f4',
    confirmButtonColor: '#fbbf24', cancelButtonColor: '#44403c',
    didOpen: function () { document.getElementById('ig_caption').value = baslik; },
    preConfirm: function () {
      return { hesap: document.getElementById('ig_hesap').value, caption: document.getElementById('ig_caption').value };
    }
  }).then(function (r) {
    if (!r.isConfirmed) return;
    Swal.fire({ title: 'Paylaşılıyor...', allowOutsideClick: false, showConfirmButton: false, didOpen: function () { Swal.showLoading(); }, background: '#1c1917', color: '#f5f5f4' });
    fetch('instagram_paylas.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ hesap: r.value.hesap, dosya: dosya, caption: r.value.caption })
    }).then(function (x) { return x.json(); }).then(function (d) {
      Swal.fire({
        icon: d.tamam ? 'success' : 'error',
        title: d.tamam ? 'Paylaşıldı!' : 'Paylaşılamadı',
        html: d.tamam ? 'Resim Instagram\'da yayınlandı.' : (d.hata || ''),
        background: '#1c1917', color: '#f5f5f4', confirmButtonColor: '#fbbf24'
      });
    }).catch(function (e) {
      Swal.fire({ icon: 'error', title: 'Hata', text: e.message, background: '#1c1917', color: '#f5f5f4', confirmButtonColor: '#fbbf24' });
    });
  });
}
</script>
</body>
</html>
<?php
}