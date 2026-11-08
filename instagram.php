<?php
require __DIR__ . '/config.php';
kurulum();
giris_kontrol();
$pdo = db();

$duzenle = null;
if (!empty($_GET['duzenle'])) {
    $h = $pdo->prepare("SELECT * FROM instagram_hesaplar WHERE id=?");
    $h->execute([(int)$_GET['duzenle']]);
    $duzenle = $h->fetch() ?: null;
}

// ---- "Instagram ile Bağlan" (OAuth) ----

// Bağlanmayı başlat: kullanıcıyı Instagram giriş sayfasına yönlendir
if (!empty($_GET['baglan'])) {
    if (IG_APP_ID === '' || IG_REDIRECT_URI === '') {
        flash_ekle('Bağlanmak için env.php içindeki IG_APP_ID ve IG_REDIRECT_URI doldurulmalı!');
        header('Location: instagram.php'); exit;
    }
    $durum = bin2hex(random_bytes(16));
    $_SESSION['ig_durum'] = $durum;
    header('Location: ' . instagram_baglanti_url($durum)); exit;
}

// Instagram'dan dönen kod: token'a çevir, hesabı kaydet
if (!empty($_GET['code'])) {
    if (($_SESSION['ig_durum'] ?? '') === '' || ($_GET['state'] ?? '') !== ($_SESSION['ig_durum'] ?? '')) {
        flash_ekle('Güvenlik doğrulaması başarısız (state uyuşmadı). Tekrar dene.');
        header('Location: instagram.php'); exit;
    }
    unset($_SESSION['ig_durum']);
    [$ok, $sonuc] = instagram_kod_degistir((string)$_GET['code']);
    if (!$ok) { flash_ekle('Bağlanılamadı: ' . $sonuc); header('Location: instagram.php'); exit; }

    [$okU, $uzun] = instagram_uzun_token($sonuc['token']);
    $token = $okU ? $uzun : $sonuc['token']; // uzun token alınamazsa kısa token ile devam

    [$pOk, $profil] = instagram_profil($token);
    $ad   = ($pOk && $profil['username'] !== '') ? '@' . $profil['username'] : 'Instagram Hesabı';
    $igId = $sonuc['user_id'] !== '' ? $sonuc['user_id'] : ($profil['user_id'] ?? '');

    $h = $pdo->prepare("INSERT INTO instagram_hesaplar (ad, ig_user_id, access_token) VALUES (?,?,?)");
    $h->execute([$ad, $igId, $token]);
    flash_ekle('Instagram hesabı bağlandı: ' . $ad);
    header('Location: instagram.php'); exit;
}

// Bağlantı iptal/hata geri dönüşü
if (!empty($_GET['error'])) {
    flash_ekle('Instagram bağlantısı iptal edildi: ' . ($_GET['error_description'] ?? $_GET['error']));
    header('Location: instagram.php'); exit;
}

// Token'ı yenile (60 günlük süreyi uzat)
if (!empty($_GET['yenile'])) {
    $h = $pdo->prepare("SELECT * FROM instagram_hesaplar WHERE id=?");
    $h->execute([(int)$_GET['yenile']]);
    if ($hesap = $h->fetch()) {
        [$ok, $yeni] = instagram_token_yenile($hesap['access_token']);
        if ($ok) {
            $u = $pdo->prepare("UPDATE instagram_hesaplar SET access_token=? WHERE id=?");
            $u->execute([$yeni, (int)$hesap['id']]);
            flash_ekle('Token yenilendi.');
        } else {
            flash_ekle('Token yenilenemedi: ' . $yeni);
        }
    }
    header('Location: instagram.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ad = trim($_POST['ad'] ?? '');
    $ig_user_id = trim($_POST['ig_user_id'] ?? '');
    $token = trim($_POST['access_token'] ?? '');

    if ($ad === '' || $ig_user_id === '' || $token === '') {
        flash_ekle('Ad, Instagram Kullanıcı ID ve Erişim Anahtarı doldurulmalı!');
    } elseif (($_POST['islem'] ?? '') === 'kaydet' && !empty($_POST['id'])) {
        $h = $pdo->prepare("UPDATE instagram_hesaplar SET ad=?, ig_user_id=?, access_token=? WHERE id=?");
        $h->execute([$ad, $ig_user_id, $token, (int)$_POST['id']]);
        flash_ekle('Hesap güncellendi.');
        header('Location: instagram.php'); exit;
    } elseif (($_POST['islem'] ?? '') === 'ekle') {
        $h = $pdo->prepare("INSERT INTO instagram_hesaplar (ad, ig_user_id, access_token) VALUES (?,?,?)");
        $h->execute([$ad, $ig_user_id, $token]);
        flash_ekle('Hesap eklendi.');
        header('Location: instagram.php'); exit;
    }
    header('Location: instagram.php'); exit;
}

if (!empty($_GET['sil'])) {
    $h = $pdo->prepare("DELETE FROM instagram_hesaplar WHERE id=?");
    $h->execute([(int)$_GET['sil']]);
    flash_ekle('Hesap silindi.');
    header('Location: instagram.php'); exit;
}

$hesaplar = instagram_hesaplar();
$publicHazir = PUBLIC_BASE_URL !== '';
$baglanHazir = IG_APP_ID !== '' && IG_APP_SECRET !== '' && IG_REDIRECT_URI !== '';
ust_sayfa('instagram.php');
?>

<div class="space-y-8">
  <div class="flex items-center gap-4">
    <i class="fa-brands fa-instagram text-4xl text-amber-400"></i>
    <h1 class="font-black text-3xl md:text-4xl tracking-tight">Instagram Paylaşım</h1>
  </div>

  <!-- Yapılandırma durumu -->
  <div class="card">
    <h2 class="font-extrabold text-2xl mb-4 text-amber-300"><i class="fa-solid fa-circle-info mr-2"></i>Yapılandırma</h2>
    <div class="mb-4 inline-flex items-center gap-2 rounded-xl bg-emerald-500/15 border border-emerald-700 text-emerald-200 px-4 py-2 text-base font-bold">
      <i class="fa-solid fa-check"></i> Instagram Girişi kullanılıyor (<code><?= IG_HOST ?>/<?= IG_SURUM ?></code>) — <b>Facebook sayfası gerekmez</b>
    </div>
    <div class="space-y-3 text-lg">
      <div class="flex items-center gap-3">
        <i class="fa-solid <?= IG_APP_ID ? 'fa-circle-check text-emerald-400' : 'fa-circle-xmark text-red-400' ?>"></i>
        <span>Uygulama ID (env.php → <code class="text-amber-300">IG_APP_ID</code>): <?= IG_APP_ID ? 'tanımlı' : '<b class="text-red-300">boş</b>' ?></span>
      </div>
      <div class="flex items-center gap-3">
        <i class="fa-solid <?= IG_APP_SECRET ? 'fa-circle-check text-emerald-400' : 'fa-circle-xmark text-red-400' ?>"></i>
        <span>App Secret (env.php → <code class="text-amber-300">IG_APP_SECRET</code>): <?= IG_APP_SECRET ? 'tanımlı' : '<b class="text-red-300">boş</b>' ?></span>
      </div>
      <div class="flex items-center gap-3">
        <i class="fa-solid <?= IG_REDIRECT_URI ? 'fa-circle-check text-emerald-400' : 'fa-circle-xmark text-red-400' ?>"></i>
        <span>Dönüş adresi (env.php → <code class="text-amber-300">IG_REDIRECT_URI</code>): <?= IG_REDIRECT_URI ? htmlspecialchars(IG_REDIRECT_URI) : '<b class="text-red-300">boş</b>' ?></span>
      </div>
      <div class="flex items-center gap-3">
        <i class="fa-solid <?= $publicHazir ? 'fa-circle-check text-emerald-400' : 'fa-triangle-exclamation text-amber-400' ?>"></i>
        <span>Genel adres (env.php → <code class="text-amber-300">PUBLIC_BASE_URL</code>): <?= $publicHazir ? htmlspecialchars(PUBLIC_BASE_URL) : 'boş (mevcut adres kullanılır)' ?></span>
      </div>
      <div class="flex items-center gap-3">
        <i class="fa-solid <?= $hesaplar ? 'fa-circle-check text-emerald-400' : 'fa-circle-xmark text-red-400' ?>"></i>
        <span>Kayıtlı hesap sayısı: <b><?= count($hesaplar) ?></b></span>
      </div>
    </div>

    <?php if (!$publicHazir): ?>
      <div class="mt-5 bg-amber-400/10 border-2 border-amber-500 text-amber-200 rounded-2xl px-5 py-4 text-base leading-relaxed">
        <b><i class="fa-solid fa-triangle-exclamation mr-1"></i> Önemli:</b> Instagram, yayınlanacak resme <b>internetten erişilebilen</b> bir adresten ulaşmak zorundadır.
        Yerel sunucu (<code>localhost</code>) adreslerine erişemez. Canlı bir alan adı ya da bir tünel adresi (ör. ngrok)
        girip <code>PUBLIC_BASE_URL</code> olarak kaydet.
      </div>
    <?php endif; ?>
  </div>

  <!-- Instagram ile Bağlan (OAuth) -->
  <div class="card">
    <h2 class="font-extrabold text-2xl mb-2 text-amber-300"><i class="fa-brands fa-instagram mr-2"></i>Instagram ile Bağlan</h2>
    <p class="text-stone-400 text-lg mb-6">Erişim anahtarını elle girmene gerek yok. Butona bas, Instagram'da giriş yap ve hesabını otomatik bağla.</p>

    <?php if ($baglanHazir): ?>
      <a href="instagram.php?baglan=1" class="btn-main">
        <i class="fa-brands fa-instagram"></i> INSTAGRAM İLE BAĞLAN
      </a>
    <?php else: ?>
      <div class="bg-amber-400/10 border-2 border-amber-500 text-amber-200 rounded-2xl px-5 py-4 text-base leading-relaxed">
        <b><i class="fa-solid fa-triangle-exclamation mr-1"></i> Bağlanmak için gerekli:</b>
        env.php içindeki <code>IG_APP_ID</code>, <code>IG_APP_SECRET</code> ve <code>IG_REDIRECT_URI</code> alanlarını doldur.
        Ayrıca <code>IG_REDIRECT_URI</code> adresi Meta uygulamasındaki "OAuth redirect URIs" listesine eklenmiş olmalı.
      </div>
    <?php endif; ?>
  </div>

  <!-- Ekle / Düzenle (elle) -->
  <div class="card">
    <h2 class="font-extrabold text-2xl mb-6 text-amber-300">
      <i class="fa-solid fa-circle-plus mr-2"></i><?= $duzenle ? 'Hesabı Düzenle' : 'Elle Hesap Ekle' ?>
    </h2>
    <form method="post" class="space-y-6">
      <input type="hidden" name="islem" value="<?= $duzenle ? 'kaydet' : 'ekle' ?>">
      <?php if ($duzenle): ?><input type="hidden" name="id" value="<?= (int)$duzenle['id'] ?>"><?php endif; ?>

      <div>
        <label class="label">Hesap Adı (ör. Kafe Sayfası)</label>
        <input class="input" type="text" name="ad" required value="<?= htmlspecialchars($duzenle['ad'] ?? '') ?>" placeholder="Seçim kutusunda görünecek ad">
      </div>

      <div>
        <label class="label">Instagram Kullanıcı ID (IG Business/Creator hesabının ID'si)</label>
        <input class="input" type="text" name="ig_user_id" required value="<?= htmlspecialchars($duzenle['ig_user_id'] ?? '') ?>" placeholder="ör. 17841400000000000">
      </div>

      <div>
        <label class="label">Erişim Anahtarı (Access Token)</label>
        <textarea class="input" name="access_token" rows="3" required placeholder="instagram_business_content_publish iznine sahip uzun ömürlü Instagram erişim anahtarı"><?= htmlspecialchars($duzenle['access_token'] ?? '') ?></textarea>
      </div>

      <button type="submit" class="btn-main">
        <i class="fa-solid <?= $duzenle ? 'fa-floppy-disk' : 'fa-circle-plus' ?>"></i>
        <?= $duzenle ? 'KAYDET' : 'HESABI EKLE' ?>
      </button>
    </form>
  </div>

  <!-- Liste -->
  <div class="card">
    <h2 class="font-extrabold text-2xl mb-6 text-amber-300"><i class="fa-solid fa-list mr-2"></i>Kayıtlı Hesaplar (<?= count($hesaplar) ?>)</h2>

    <?php if (!$hesaplar): ?>
      <div class="text-center py-10 text-stone-400">
        <i class="fa-brands fa-instagram text-6xl mb-4 text-stone-700"></i>
        <p class="text-2xl font-bold">Henüz hesap yok.</p>
        <p class="mt-2">Yukarıdan bir Instagram işletme hesabı ekle.</p>
      </div>
    <?php else: ?>
      <div class="space-y-4">
        <?php foreach ($hesaplar as $h): ?>
          <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 rounded-2xl border border-stone-800 bg-stone-800/50 p-5">
            <div class="flex items-start md:items-center gap-4">
              <span class="text-3xl"><i class="fa-brands fa-instagram text-amber-400"></i></span>
              <div>
                <div class="font-extrabold text-xl"><?= htmlspecialchars($h['ad']) ?></div>
                <code class="text-amber-300 text-lg break-all">ID: <?= htmlspecialchars($h['ig_user_id']) ?></code>
                <div class="mt-1 text-xs text-stone-500 break-all">Token: <?= htmlspecialchars(mb_substr($h['access_token'], 0, 18)) ?>••••<?= htmlspecialchars(mb_substr($h['access_token'], -6)) ?></div>
              </div>
            </div>
            <div class="flex gap-2 shrink-0">
              <a href="instagram.php?yenile=<?= (int)$h['id'] ?>" class="btn-ikincil" title="Token süresini uzat (60 gün)"><i class="fa-solid fa-rotate"></i> Yenile</a>
              <a href="instagram.php?duzenle=<?= (int)$h['id'] ?>" class="btn-ikincil"><i class="fa-solid fa-pen"></i> Düzenle</a>
              <a href="instagram.php?sil=<?= (int)$h['id'] ?>" class="btn-tehlike" onclick="return confirm('Bu hesap silinsin mi?')"><i class="fa-solid fa-trash"></i></a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="mt-6 bg-stone-800/40 border border-stone-800 rounded-2xl px-5 py-4 text-base text-stone-400 leading-relaxed">
        <b class="text-stone-300"><i class="fa-solid fa-lightbulb mr-1 text-amber-400"></i> Nasıl paylaşılır?</b><br>
        Üretilen bir resmin altındaki <b>Paylaş</b> butonuna bas → açılan pencerede hesabı seç, caption'ı düzenle ve <b>Paylaş</b>'ı onayla. Resim seçili hesapta otomatik yayınlanır.
      </div>
    <?php endif; ?>
  </div>
</div>

<?php alt_sayfa(); ?>
