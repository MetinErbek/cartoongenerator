<?php
require __DIR__ . '/config.php';
kurulum();
giris_kontrol();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $islem = $_POST['islem'] ?? '';

    // Resim üret (AJAX ile gelir; arka plan işçisi başlatır, JSON döner)
    if ($islem === 'uretim') {
        $api = api_anahtari();
        $hata = null;
        if ($api === '') { $hata = 'Önce Modeller sayfasından API anahtarını gir!'; }
        $gorselId = (int)($_POST['gorsel_model'] ?? 0);
        $adet = max(1, min(8, (int)($_POST['adet'] ?? 1)));
        $prompt = trim($_POST['prompt_metin'] ?? '');
        if ($prompt === '') { $hata = 'Prompt boş olamaz!'; }
        $m = $pdo->prepare("SELECT * FROM modeller WHERE id=? AND tip='gorsel'");
        $m->execute([$gorselId]);
        $model = $m->fetch();
        if (!$model) { $hata = 'Önce bir görsel model seç!'; }

        if ($hata) {
            if (!empty($_GET['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['tamam' => 0, 'adet' => $adet, 'hata' => $hata, 'hatalar' => [$hata]]);
                exit;
            }
            flash_ekle($hata);
            header('Location: generate.php');
            exit;
        }

        // Arka plan işçisini başlatmayı dene (sunucu meşgul olmaz, başka sekmeler takılmaz)
        $jobId = date('Ymd_His') . '_' . mt_rand(100, 999);
        $isYolu = ISLER_YOL . '/' . $jobId . '.json';
        file_put_contents($isYolu, json_encode([
            'model_id' => $gorselId, 'adet' => $adet, 'prompt' => $prompt, 'api' => $api,
            'durum' => 'basladi', 'bitti' => false, 'tamam' => 0, 'hatalar' => [],
        ], JSON_UNESCAPED_UNICODE));

        $komut = 'start /B "" "' . PHP_BINARY . '" "' . __DIR__ . '\\uretim_calisan.php" "' . $jobId . '" > NUL 2>&1';
        $acildi = false;
        if (function_exists('popen') && !in_array('popen', array_map('trim', explode(',', ini_get('disable_functions'))), true)) {
            $hd = @popen($komut, 'r');
            if ($hd !== false) { pclose($hd); $acildi = true; }
        }

        if ($acildi && !empty($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['arkaplan' => true, 'job_id' => $jobId, 'adet' => $adet]);
            exit;
        }

        // Arka plan açılamadıysa eski senkron yol (yavaş ama çalışır)
        if (!$acildi) { unlink($isYolu); } // bozuk iş dosyasını sil
        $tamam = 0; $hatalar = [];
        session_write_close();
        for ($i = 1; $i <= $adet; $i++) {
            set_time_limit(0);
            [$ok, $sonuc] = gorsel_uret($model['model_id'], $prompt, $api);
            if ($ok && $sonuc['tur'] === 'resim') {
                $dosya = resim_kaydet($sonuc['url']);
                if ($dosya) { $tamam++; continue; }
                $hatalar[] = "İndirme (#$i) başarısız";
            } else {
                $hatalar[] = "#$i: " . ($ok ? 'model metin döndürdü' : $sonuc);
            }
        }

        if (!empty($_GET['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['tamam' => $tamam, 'adet' => $adet, 'hata' => null, 'hatalar' => $hatalar]);
            exit;
        }
        flash_ekle("$tamam / $adet resim üretildi." . ($hatalar ? ' — ' . implode(' • ', $hatalar) : ''));
        header('Location: generate.php#resimler'); exit;
    }

    // Başlık (caption) üret
    if ($islem === 'baslik') {
        $api = api_anahtari();
        if ($api === '') { flash_ekle('Önce OpenRouter API anahtarını gir!'); header('Location: generate.php'); exit; }
        $metinId = (int)($_POST['metin_model'] ?? 0);
        $dosya = basename($_POST['baslik_resim'] ?? '');
        if (!in_array($dosya, resimler(), true)) { flash_ekle('Geçersiz resim seçildi!'); header('Location: generate.php'); exit; }
        $prompt = trim($_POST['baslik_prompt'] ?? '');
        if ($prompt === '') $prompt = 'Bu karikatüre kısa, kahkaha attıran bir Türkçe başlık yaz, İngilizce çevirisini parantez içinde ekle ve bolca etiket (hashtag) koy.';
        // Modelin "Başlık:", "İşte..." gibi açıklama/konuşma ekleri yazmasını engelle; Türkçe+İngilizce başlık ve bol etiket iste
        $prompt = 'Görevin: ' . $prompt . ' Cevabında SADECE başlık metnini ver; "başlık", "işte", "captıon" gibi kelimeler, açıklama, emoji veya iki nokta üst üste kullanma. Tek kısa Türkçe cümle yaz, hemen yanına parantez içinde İngilizce çevirisini ekle ve cümlenin sonuna bolca (5-10 tane) konuyla ilgili etiket (hashtag) ekle. Örnek: "Karpuzu gol sanan futbolcu! (The footballer mistook a watermelon for a goal!) #komik #futbol #gol #spor"';

        $m = $pdo->prepare("SELECT * FROM modeller WHERE id=? AND tip='metin'");
        $m->execute([$metinId]);
        $model = $m->fetch();
        if (!$model) { flash_ekle('Önce bir metin modeli seç!'); header('Location: generate.php'); exit; }

        // Resmi data URI olarak göndermek istenirse
        $gorselVeri = null;
        if (!empty($_POST['gorsel_gonder'])) {
            $uzanti = strtolower(pathinfo($dosya, PATHINFO_EXTENSION));
            $mime = $uzanti === 'jpg' ? 'image/jpeg' : 'image/' . $uzanti;
            $bayt = file_get_contents(UPLOAD_YOL . '/' . $dosya);
            if ($bayt && strlen($bayt) < 4 * 1024 * 1024) {
                $gorselVeri = 'data:' . $mime . ';base64,' . base64_encode($bayt);
            }
        }

        set_time_limit(0);
        session_write_close(); // oturum kilidini bırak; uzun istek sürerken diğer sekmeler takılmasın
        [$ok, $sonuc] = openrouter_iste($model['model_id'], $prompt, $api, $gorselVeri);
        session_start(); // flash mesajı yazabilmek için oturumu geri aç
        if ($ok && $sonuc['tur'] === 'metin') {
            baslik_yaz($dosya, $sonuc['metin']);
            flash_ekle('Başlık oluşturuldu ve resme eklendi.');
        } else {
            flash_ekle('Başlık üretilemedi: ' . ($ok ? json_encode($sonuc) : $sonuc));
        }
        header('Location: generate.php#resimler'); exit;
    }
    header('Location: generate.php'); exit;
}

// Arka plan üretim işinin durumunu döndür (AJAX ile yoklama)
if (!empty($_GET['ajax']) && ($_GET['islem'] ?? '') === 'durum') {
    $id = basename($_GET['id'] ?? '');
    $f = ISLER_YOL . '/' . $id . '.json';
    if ($id === '' || !is_file($f)) {
        $veri = ['durum' => 'yok', 'bitti' => true, 'tamam' => 0, 'adet' => 0, 'hatalar' => ['İş kaydı bulunamadı']];
    } else {
        $veri = json_decode(file_get_contents($f), true) ?: ['bitti' => true, 'tamam' => 0, 'adet' => 0, 'hatalar' => []];
    }
    header('Content-Type: application/json');
    echo json_encode($veri, JSON_UNESCAPED_UNICODE);
    exit;
}

$gorselModeller = $pdo->query("SELECT * FROM modeller WHERE tip='gorsel' ORDER BY ad")->fetchAll();
$metinModeller  = $pdo->query("SELECT * FROM modeller WHERE tip='metin' ORDER BY kategori, ad")->fetchAll();
$promptlar      = $pdo->query("SELECT * FROM promptlar ORDER BY sektor, ad")->fetchAll();
$resimler       = resimler(3);   // sayfada sadece son 3 üretilen görünür
$tumResimler    = resimler();    // başlık üretmek için eski resimler de seçilebilsin

// Prompt taslağından gelen dolgu (Kullan butonu için)
$doldurMetin = '';
if (!empty($_GET['prompt_id'])) {
    $h = $pdo->prepare("SELECT * FROM promptlar WHERE id=?");
    $h->execute([(int)$_GET['prompt_id']]);
    $tp = $h->fetch();
    if ($tp) $doldurMetin = $tp['metin'];
}

ust_sayfa('generate.php');
?>

<?php if (api_anahtari() === ''): ?>
  <div class="bg-amber-400/10 border-2 border-amber-500 text-amber-200 rounded-2xl px-6 py-4 flex items-center gap-4 text-xl font-bold">
    <i class="fa-solid fa-triangle-exclamation text-2xl"></i>
    Devam etmek için <a href="models.php" class="underline font-extrabold">Modeller sayfasından</a> OpenRouter API anahtarını kaydet.
  </div>
<?php endif; ?>

<!-- RESİM ÜRET -->
<div class="card">
  <h2 class="font-extrabold text-2xl md:text-3xl mb-6 text-amber-300"><i class="fa-solid fa-wand-magic-sparkles mr-2"></i>Karikatür Üret</h2>
  <form method="post" id="uretim_form" class="space-y-6">
    <input type="hidden" name="islem" value="uretim">

    <div class="grid md:grid-cols-2 gap-6">
      <div>
        <label class="label" for="gorsel_model">Görsel Model</label>
        <?php if ($gorselModeller): ?>
          <select id="gorsel_model" name="gorsel_model" required class="input !py-4">
            <?php foreach ($gorselModeller as $m): ?>
              <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['ad']) ?> — <?= htmlspecialchars($m['model_id']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <a href="models.php" class="block text-center rounded-xl border-2 border-dashed border-amber-500 text-amber-300 py-4 text-lg font-bold hover:bg-amber-400/10">
            <i class="fa-solid fa-plus mr-2"></i>Önce bir görsel model ekle (Modeller sayfası)
          </a>
        <?php endif; ?>
      </div>
      <div>
        <label class="label" for="adet">Kaç Resim? (her biri tek tek modele gider)</label>
        <input id="adet" name="adet" type="number" min="1" max="8" value="1" required class="input text-center text-3xl font-black !py-3">
      </div>
    </div>

    <div>
      <label class="label" for="prompt_sec">Taslak seç (seçince aşağıdaki kutuya yazılır)</label>
      <select id="prompt_sec" onchange="this.selectedIndex && (document.getElementById('prompt_metin').value = this.options[this.selectedIndex].getAttribute('data-metin') || '')" class="input">
        <option value="">— Taslak seç — (sen kendi yazını da yazabilirsin)</option>
        <?php foreach ($promptlar as $p): ?>
          <option value="<?= (int)$p['id'] ?>" data-metin="<?= htmlspecialchars($p['metin'], ENT_QUOTES) ?>">[<?= htmlspecialchars($p['sektor'] ?: 'Genel') ?>] <?= htmlspecialchars($p['ad']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="label" for="prompt_metin">Sahne / Prompt (değiştirebilirsin)</label>
      <textarea id="prompt_metin" name="prompt_metin" rows="5" required class="input text-lg" placeholder="Komik sahneyi anlat..."><?= htmlspecialchars($doldurMetin) ?></textarea>
    </div>

    <button type="submit" class="btn-main md:!text-3xl" <?= $gorselModeller ? '' : 'disabled' ?>>
      <i class="fa-solid fa-wand-magic-sparkles text-2xl"></i> 🖼️ RESİMLERİ ÜRET
    </button>
  </form>
</div>

<!-- RESİM LİSTESİ -->
<div id="resimler" class="card">
  <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <h2 class="font-extrabold text-2xl md:text-3xl text-amber-300"><i class="fa-solid fa-images mr-2"></i>Son Üretilenler (<?= count($resimler) ?>)</h2>
    <a href="eski_resimler.php" class="btn-ikincil !text-base"><i class="fa-solid fa-clock-rotate-left"></i> Eski Resimler (<?= resim_sayisi() ?>)</a>
  </div>

  <?php if (!$resimler): ?>
    <div class="text-center py-12 text-stone-400">
      <i class="fa-solid fa-images text-7xl mb-4 text-stone-700"></i>
      <p class="text-2xl font-bold">Henüz resim yok.</p>
      <p class="mt-2 text-lg">Yukarıdan üretince burada listelenecek (uploads klasörüne bakılır).</p>
    </div>
  <?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
      <?php foreach ($resimler as $dosya):
          $baslik = baslik_oku($dosya); ?>
        <div class="rounded-2xl overflow-hidden border border-stone-800 bg-stone-800/40 flex flex-col">
          <img src="uploads/<?= htmlspecialchars(rawurlencode($dosya)) ?>" alt="<?= htmlspecialchars($dosya) ?>" onclick="resimPopup(this.src)" class="w-full h-64 object-cover cursor-zoom-in hover:opacity-90 transition">
          <div class="p-4 flex-1 flex flex-col gap-2">
            <div class="flex items-center gap-2">
              <div class="text-xs text-stone-500 break-all flex-1"><?= htmlspecialchars($dosya) ?></div>
              <a href="uploads/<?= htmlspecialchars(rawurlencode($dosya)) ?>" download class="btn-ikincil !px-3 !py-2 !text-sm shrink-0"><i class="fa-solid fa-download"></i> İndir</a>
            </div>
            <?php if ($baslik !== ''): ?>
              <div class="flex flex-col gap-2">
                <textarea readonly class="w-full rounded-xl bg-stone-900 border border-stone-700 text-stone-100 text-base font-semibold px-4 py-3 resize-y min-h-[4.5rem]"><?= htmlspecialchars($baslik) ?></textarea>
                <button type="button" onclick="kopyala(this)" class="btn-ikincil !px-3 !py-2 !text-sm w-fit"><i class="fa-regular fa-copy"></i> Kopyala</button>
              </div>
            <?php endif; ?>
            <button type="button" onclick="baslikPaneleGit('<?= htmlspecialchars($dosya, ENT_QUOTES) ?>')" class="btn-ikincil mt-auto">
              <i class="fa-solid fa-font"></i> Başlık Üret
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- BAŞLIK/CAPTION ÜRET -->
<div id="baslik_karti" class="card scroll-mt-24">
  <h2 class="font-extrabold text-2xl md:text-3xl mb-6 text-amber-300"><i class="fa-solid fa-font mr-2"></i>Resime Başlık (Caption) Üret</h2>
  <form method="post" class="space-y-6">
    <input type="hidden" name="islem" value="baslik">

    <div class="grid md:grid-cols-2 gap-6">
      <div>
        <label class="label" for="baslik_resim">Resim</label>
        <?php if ($tumResimler): ?>
          <select id="baslik_resim" name="baslik_resim" required class="input">
            <?php foreach ($tumResimler as $dosya): ?>
              <option value="<?= htmlspecialchars($dosya) ?>"><?= htmlspecialchars($dosya) ?></option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <div class="rounded-xl border-2 border-dashed border-stone-600 text-stone-400 py-4 text-center font-bold">Önce resim üret</div>
        <?php endif; ?>
      </div>
      <div>
        <label class="label" for="metin_model">Metin Modeli</label>
        <?php if ($metinModeller): ?>
          <select id="metin_model" name="metin_model" required class="input">
            <?php
            $son = null;
            foreach ($metinModeller as $m) {
                if ($m['kategori'] !== $son) {
                    if ($son !== null) echo '</optgroup>';
                    $son = $m['kategori'];
                    if ($son !== '') echo '<optgroup label="' . htmlspecialchars($son) . '">';
                }
                echo '<option value="' . (int)$m['id'] . '">' . htmlspecialchars($m['ad']) . '</option>';
            }
            if ($son !== null && $son !== '') echo '</optgroup>';
            ?>
          </select>
        <?php else: ?>
          <a href="models.php" class="block text-center rounded-xl border-2 border-dashed border-amber-500 text-amber-300 py-4 text-lg font-bold hover:bg-amber-400/10">
            <i class="fa-solid fa-plus mr-2"></i>Metin modeli ekle (Modeller sayfası)
          </a>
        <?php endif; ?>
      </div>
    </div>

    <div>
      <label class="label" for="baslik_prompt_sec">Taslaktan dol (istersen)</label>
      <select id="baslik_prompt_sec" onchange="this.selectedIndex && (document.getElementById('baslik_prompt').value = this.options[this.selectedIndex].getAttribute('data-metin') || '')" class="input">
        <option value="">— Taslak seç —</option>
        <?php foreach ($promptlar as $p): ?>
          <option value="<?= (int)$p['id'] ?>" data-metin="<?= htmlspecialchars($p['metin'], ENT_QUOTES) ?>">[<?= htmlspecialchars($p['sektor'] ?: 'Genel') ?>] <?= htmlspecialchars($p['ad']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="label" for="baslik_prompt">Başlık Yönergesi</label>
      <textarea id="baslik_prompt" name="baslik_prompt" rows="3" required class="input" placeholder="Bu karikatüre kısa, komik bir Türkçe başlık yaz, İngilizce çevirisini ekle ve bol etiket koy...">Bu karikatüre kısa, kahkaha attıran bir Türkçe başlık yaz, İngilizce çevirisini parantez içinde ekle ve bolca etiket (hashtag) koy.</textarea>
    </div>

    <label class="flex items-center gap-3 text-lg font-bold text-stone-300 cursor-pointer">
      <input type="checkbox" name="gorsel_gonder" value="1" class="w-6 h-6 accent-amber-400">
      Resmi modele de gönder (model görüntü destekliyorsa gerçek resmi görür)
    </label>

    <button type="submit" class="btn-main md:!text-2xl" <?= ($metinModeller && $tumResimler) ? '' : 'disabled' ?>>
      <i class="fa-solid fa-font"></i> BAŞLIĞI ÜRET
    </button>
  </form>
</div>

<script>
function baslikPaneleGit(dosya) {
  var s = document.getElementById('baslik_resim');
  if (s) { [...s.options].forEach(o => { if (o.value === dosya) s.value = dosya; }); }
  document.getElementById('baslik_karti').scrollIntoView({ behavior: 'smooth' });
}

// Eski Resimler sayfasından "Başlık Üret" ile gelindiğinde (generate.php?baslik_resim=...) seçili yap
(function () {
  var br = new URLSearchParams(location.search).get('baslik_resim');
  if (br) baslikPaneleGit(br);
})();

// Resim üretimi AJAX ile (arka planda çalışır, ilerleme izlenir)
document.getElementById('uretim_form').addEventListener('submit', async function (e) {
  e.preventDefault();
  const kutuIcerik = (metin) => document.querySelector('#swal2-html-container').innerHTML = metin;
  Swal.fire({
    title: 'Bekleyiniz...',
    html: '<i class="fa-solid fa-wand-magic-sparkles text-4xl text-amber-400"></i><p class="mt-3 text-lg">Üretim başlatılıyor...</p>',
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    background: '#1c1917',
    color: '#f5f5f4',
    didOpen: () => Swal.showLoading()
  });

  try {
    const fd = new FormData(this);
    const yanit = await fetch('generate.php?ajax=1', { method: 'POST', body: fd });
    const veri = await yanit.json();

    // Arka plan modu: ilerlemeyi yokla, bitince sonucu göster
    if (veri.arkaplan) {
      const bekle = ms => new Promise(r => setTimeout(r, ms));
      let deneme = 0;
      while (deneme++ < 600) { // ~20dk güvenlik limiti
        await bekle(2000);
        const d = await (await fetch('generate.php?ajax=1&islem=durum&id=' + veri.job_id)).json();
        kutuIcerik('<i class="fa-solid fa-wand-magic-sparkles text-4xl text-amber-400"></i><p class="mt-3 text-lg">Üretim sürüyor... <b>' + d.tamam + ' / ' + d.adet + '</b> resim</p>');
        if (d.bitti) {
          let sonuc = d.tamam + ' / ' + d.adet + ' resim üretildi.';
          if (d.hatalar && d.hatalar.length) sonuc += '<br><small>' + d.hatalar.join(' • ') + '</small>';
          await Swal.fire({
            icon: d.tamam > 0 ? 'success' : 'error',
            title: d.tamam > 0 ? 'Tamamlandı!' : 'Başarısız!',
            html: sonuc,
            confirmButtonText: 'Tamam',
            background: '#1c1917', color: '#f5f5f4', confirmButtonColor: '#fbbf24'
          });
          if (d.tamam > 0) window.location.reload(); // listeyi güncelle
          return;
        }
      }
      await Swal.fire({ icon: 'error', title: 'Zaman aşımı', text: 'Üretim çok uzun sürdü; sayfayı yenileyip resim listesine bak.', background: '#1c1917', color: '#f5f5f4', confirmButtonColor: '#fbbf24' });
      return;
    }

    // Senkron yedek yolun sonucu
    let sonuc = veri.hata !== null ? veri.hata : (veri.tamam + ' / ' + veri.adet + ' resim üretildi.');
    if (veri.hatalar && veri.hatalar.length) sonuc += '<br><small>' + veri.hatalar.join(' • ') + '</small>';
    await Swal.fire({
      icon: (veri.tamam > 0) ? 'success' : 'error',
      title: (veri.tamam > 0) ? 'Tamamlandı!' : 'Başarısız!',
      html: sonuc,
      confirmButtonText: 'Tamam',
      background: '#1c1917', color: '#f5f5f4', confirmButtonColor: '#fbbf24'
    });
    if (veri.tamam > 0) window.location.reload();
  } catch (hata) {
    Swal.fire({
      icon: 'error',
      title: 'Hata!',
      text: 'İstek başarısız: ' + hata.message,
      confirmButtonText: 'Tamam',
      background: '#1c1917', color: '#f5f5f4', confirmButtonColor: '#fbbf24'
    });
  }
});
</script>

<?php alt_sayfa(); ?>