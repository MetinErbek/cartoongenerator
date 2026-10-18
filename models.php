<?php
require __DIR__ . '/config.php';
kurulum();
giris_kontrol();
$pdo = db();

$duzenle = null;
if (!empty($_GET['duzenle'])) {
    $h = $pdo->prepare("SELECT * FROM modeller WHERE id=?");
    $h->execute([(int)$_GET['duzenle']]);
    $duzenle = $h->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // API anahtarını buradan kaydet
    if (($_POST['islem'] ?? '') === 'api') {
        api_anahtari_kaydet($_POST['api_anahtar'] ?? '');
        flash_ekle('OpenRouter API anahtarı kaydedildi.');
        header('Location: models.php'); exit;
    }

    $ad = trim($_POST['ad'] ?? '');
    $model_id = trim($_POST['model_id'] ?? '');
    $tip = (($_POST['tip'] ?? '') === 'metin') ? 'metin' : 'gorsel';
    $kategori = trim($_POST['kategori'] ?? '');

    if ($ad === '' || $model_id === '') {
        flash_ekle('Ad ve Model ID doldurulmalı!');
    } elseif (($_POST['islem'] ?? '') === 'kaydet' && !empty($_POST['id'])) {
        $h = $pdo->prepare("UPDATE modeller SET ad=?, model_id=?, tip=?, kategori=? WHERE id=?");
        $h->execute([$ad, $model_id, $tip, $kategori, (int)$_POST['id']]);
        flash_ekle('Model güncellendi.');
        header('Location: models.php'); exit;
    } elseif (($_POST['islem'] ?? '') === 'ekle') {
        $h = $pdo->prepare("INSERT INTO modeller (ad, model_id, tip, kategori) VALUES (?,?,?,?)");
        $h->execute([$ad, $model_id, $tip, $kategori]);
        flash_ekle('Model eklendi.');
        header('Location: models.php'); exit;
    }
    header('Location: models.php'); exit;
}

if (!empty($_GET['sil'])) {
    $h = $pdo->prepare("DELETE FROM modeller WHERE id=?");
    $h->execute([(int)$_GET['sil']]);
    flash_ekle('Model silindi.');
    header('Location: models.php'); exit;
}

$modeller = $pdo->query("SELECT * FROM modeller ORDER BY tip, ad")->fetchAll();
ust_sayfa('models.php');
?>

<div class="space-y-8">
  <div class="flex items-center gap-4">
    <i class="fa-solid fa-robot text-4xl text-amber-400"></i>
    <h1 class="font-black text-3xl md:text-4xl tracking-tight">Modeller</h1>
  </div>

  <!-- OpenRouter API Anahtarı -->
  <div class="card">
    <h2 class="font-extrabold text-2xl mb-6 text-amber-300"><i class="fa-solid fa-key mr-2"></i>OpenRouter API Anahtarı</h2>
    <form method="post" class="flex flex-col md:flex-row gap-4 items-end">
      <input type="hidden" name="islem" value="api">
      <div class="flex-1 w-full">
        <label class="label">API Anahtarı (sk-or-...) — openrouter.ai/settings/keys</label>
        <input class="input" type="password" name="api_anahtar" value="<?= htmlspecialchars(api_anahtari()) ?>" placeholder="sk-or-...">
      </div>
      <button type="submit" class="btn-ikincil md:mb-0 !py-4 shrink-0 w-full md:w-auto"><i class="fa-solid fa-floppy-disk"></i> KAYDET</button>
    </form>
  </div>

  <!-- Ekle / Düzenle -->
  <div class="card">
    <h2 class="font-extrabold text-2xl mb-6 text-amber-300">
      <i class="fa-solid fa-circle-plus mr-2"></i><?= $duzenle ? 'Modeli Düzenle' : 'Yeni Model Ekle' ?>
    </h2>
    <form method="post" class="space-y-6">
      <input type="hidden" name="islem" value="<?= $duzenle ? 'kaydet' : 'ekle' ?>">
      <?php if ($duzenle): ?><input type="hidden" name="id" value="<?= (int)$duzenle['id'] ?>"><?php endif; ?>

      <div>
        <label class="label">Model Adı (ör. Flux Schnell)</label>
        <input class="input" type="text" name="ad" required value="<?= htmlspecialchars($duzenle['ad'] ?? '') ?>" placeholder="Model için takma ad">
      </div>

      <div>
        <label class="label">OpenRouter Model ID</label>
        <input class="input" type="text" name="model_id" required list="model_onerileri" value="<?= htmlspecialchars($duzenle['model_id'] ?? '') ?>" placeholder="ör. black-forest-labs/flux-schnell">
        <datalist id="model_onerileri">
          <option value="black-forest-labs/flux-schnell">
          <option value="openai/gpt-image-1">
          <option value="openai/dall-e-3">
          <option value="google/gemini-2.0-flash-exp-image-generation:free">
          <option value="google/gemini-2.5-flash">
          <option value="openai/gpt-4o-mini">
          <option value="meta-llama/llama-3.3-70b-instruct">
          <option value="mistralai/mistral-small-3.1-24b-instruct:free">
        </datalist>
      </div>

      <div>
        <label class="label">Tür</label>
        <div class="grid grid-cols-2 gap-4">
          <label class="cursor-pointer">
            <input type="radio" name="tip" value="gorsel" class="peer hidden" <?= ($duzenle['tip'] ?? 'gorsel') !== 'metin' ? 'checked' : '' ?>>
            <span class="block text-center rounded-2xl border-2 border-stone-700 bg-stone-800 hover:border-amber-400 py-5 text-xl font-extrabold peer-checked:border-amber-400 peer-checked:bg-amber-400 peer-checked:text-stone-950 transition">
              <i class="fa-solid fa-image mr-2"></i>Görsel
            </span>
          </label>
          <label class="cursor-pointer">
            <input type="radio" name="tip" value="metin" class="peer hidden" <?= ($duzenle['tip'] ?? '') === 'metin' ? 'checked' : '' ?>>
            <span class="block text-center rounded-2xl border-2 border-stone-700 bg-stone-800 hover:border-amber-400 py-5 text-xl font-extrabold peer-checked:border-amber-400 peer-checked:bg-amber-400 peer-checked:text-stone-950 transition">
              <i class="fa-solid fa-font mr-2"></i>Metin / Başlık
            </span>
          </label>
        </div>
      </div>

      <div>
        <label class="label">Kategori (metin modelleri için, ör. Restoran)</label>
        <input class="input" type="text" name="kategori" list="sektor_onerileri" value="<?= htmlspecialchars($duzenle['kategori'] ?? '') ?>" placeholder="Sektör seçimi için">
        <datalist id="sektor_onerileri">
          <?php foreach (sektorler() as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?>
        </datalist>
      </div>

      <button type="submit" class="btn-main">
        <i class="fa-solid <?= $duzenle ? 'fa-floppy-disk' : 'fa-circle-plus' ?>"></i>
        <?= $duzenle ? 'KAYDET' : 'MODELİ EKLE' ?>
      </button>
    </form>
  </div>

  <!-- Liste -->
  <div class="card">
    <h2 class="font-extrabold text-2xl mb-6 text-amber-300"><i class="fa-solid fa-list mr-2"></i>Kayıtlı Modeller (<?= count($modeller) ?>)</h2>

    <?php if (!$modeller): ?>
      <div class="text-center py-10 text-stone-400">
        <i class="fa-solid fa-robot text-6xl mb-4 text-stone-700"></i>
        <p class="text-2xl font-bold">Henüz model yok.</p>
        <p class="mt-2">Yukarıdan ilk modelini ekle — OpenRouter'dan bir model ID seç.</p>
      </div>
    <?php else: ?>
      <div class="space-y-4">
        <?php foreach ($modeller as $m):
            $gorselMi = $m['tip'] === 'gorsel'; ?>
          <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 rounded-2xl border border-stone-800 bg-stone-800/50 p-5">
            <div class="flex items-start md:items-center gap-4">
              <span class="text-3xl"><?= $gorselMi ? '🖼️' : '💬' ?></span>
              <div>
                <div class="font-extrabold text-xl"><?= htmlspecialchars($m['ad']) ?></div>
                <code class="text-amber-300 text-lg break-all"><?= htmlspecialchars($m['model_id']) ?></code>
                <div class="mt-1 flex flex-wrap gap-2">
                  <span class="text-xs font-bold uppercase px-2 py-1 rounded-lg <?= $gorselMi ? 'bg-amber-400/20 text-amber-300' : 'bg-sky-500/20 text-sky-300' ?>"><?= $gorselMi ? 'Görsel' : 'Metin' ?></span>
                  <?php if (!$gorselMi && $m['kategori'] !== ''): ?>
                    <span class="text-xs font-bold uppercase px-2 py-1 rounded-lg bg-emerald-500/20 text-emerald-300"><?= htmlspecialchars($m['kategori']) ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="flex gap-2 shrink-0">
              <a href="models.php?duzenle=<?= (int)$m['id'] ?>" class="btn-ikincil"><i class="fa-solid fa-pen"></i> Düzenle</a>
              <a href="models.php?sil=<?= (int)$m['id'] ?>" class="btn-tehlike" onclick="return confirm('Bu model silinsin mi?')"><i class="fa-solid fa-trash"></i></a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php alt_sayfa(); ?>