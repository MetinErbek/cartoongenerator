<?php
// Basit yapılandırma + yardımcılar
session_start();

define('DB_YOL', __DIR__ . '/data/comics.db');
define('UPLOAD_YOL', __DIR__ . '/uploads');
define('ISLER_YOL', __DIR__ . '/data/isler'); // arka plan üretim işleri
define('PAROLA', 'm1'); // tek giriş şifresi

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

function flash_ekle(string $m): void { $_SESSION['flash'][] = $m; }
function flash_al(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function sektorler(): array {
    return ['Genel', 'Restoran', 'Kafe', 'Market', 'İnşaat', 'Berber', 'Kuaför', 'Oto Servis', 'Fırın', 'Kasap', 'Manav', 'Giyim'];
}

function ust_sayfa(string $aktif): void {
    $linkler = [
        'generate.php'      => ['Üret', 'fa-wand-magic-sparkles'],
        'eski_resimler.php' => ['Eski Resimler', 'fa-clock-rotate-left'],
        'models.php'        => ['Modeller', 'fa-robot'],
        'prompts.php'       => ['Promptlar', 'fa-comment-dots'],
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
    <div class="hidden md:flex items-center gap-2">
      <?php foreach ($linkler as $dosya => $l):
          $aktiflik = $dosya === $aktif ? 'bg-amber-400 text-stone-950' : 'bg-stone-800 text-stone-200 hover:bg-stone-700'; ?>
          <a href="<?= $dosya ?>" class="<?= $aktiflik ?> rounded-xl px-5 py-3 text-lg font-bold transition"><i class="fa-solid <?= $l[1] ?> mr-2"></i><?= $l[0] ?></a>
      <?php endforeach; ?>
      <a href="index.php?cikis=1" title="Çıkış" class="bg-stone-800 text-red-300 hover:bg-red-900/50 rounded-xl px-4 py-3 text-lg font-bold transition"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
  </div>
</header>

<nav class="fixed bottom-0 inset-x-0 z-40 bg-stone-900/95 backdrop-blur border-t border-stone-800 md:hidden flex justify-around py-2">
  <?php foreach ($linkler as $dosya => $l):
      $aktiflik = $dosya === $aktif ? 'text-amber-400' : 'text-stone-400'; ?>
      <a href="<?= $dosya ?>" class="<?= $aktiflik ?> flex flex-col items-center py-1 px-6 rounded-xl text-xs font-bold gap-1"><i class="fa-solid <?= $l[1] ?> text-2xl"></i><?= $l[0] ?></a>
  <?php endforeach; ?>
  <a href="index.php?cikis=1" class="flex flex-col items-center py-1 px-6 rounded-xl text-xs font-bold gap-1 text-red-400"><i class="fa-solid fa-right-from-bracket text-2xl"></i>Çıkış</a>
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
</script>
</body>
</html>
<?php
}