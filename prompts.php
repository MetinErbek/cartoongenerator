<?php
require __DIR__ . '/config.php';
kurulum();
giris_kontrol();
$pdo = db();

$duzenle = null;
if (!empty($_GET['duzenle'])) {
    $h = $pdo->prepare("SELECT * FROM promptlar WHERE id=?");
    $h->execute([(int)$_GET['duzenle']]);
    $duzenle = $h->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ad = trim($_POST['ad'] ?? '');
    $sektor = trim($_POST['sektor'] ?? '');
    $metin = trim($_POST['metin'] ?? '');

    if ($ad === '' || $metin === '') {
        flash_ekle('Ad ve prompt metni doldurulmalı!');
    } elseif (($_POST['islem'] ?? '') === 'kaydet' && !empty($_POST['id'])) {
        $h = $pdo->prepare("UPDATE promptlar SET ad=?, sektor=?, metin=? WHERE id=?");
        $h->execute([$ad, $sektor, $metin, (int)$_POST['id']]);
        flash_ekle('Prompt güncellendi.');
        header('Location: prompts.php'); exit;
    } elseif (($_POST['islem'] ?? '') === 'ekle') {
        $h = $pdo->prepare("INSERT INTO promptlar (ad, sektor, metin) VALUES (?,?,?)");
        $h->execute([$ad, $sektor, $metin]);
        flash_ekle('Prompt eklendi.');
        header('Location: prompts.php'); exit;
    }
    header('Location: prompts.php'); exit;
}

if (!empty($_GET['sil'])) {
    $h = $pdo->prepare("DELETE FROM promptlar WHERE id=?");
    $h->execute([(int)$_GET['sil']]);
    flash_ekle('Prompt silindi.');
    header('Location: prompts.php'); exit;
}

$promptlar = $pdo->query("SELECT * FROM promptlar ORDER BY sektor, ad")->fetchAll();
ust_sayfa('prompts.php');
?>

<div class="space-y-8">
  <div class="flex items-center gap-4">
    <i class="fa-solid fa-comment-dots text-4xl text-amber-400"></i>
    <h1 class="font-black text-3xl md:text-4xl tracking-tight">Prompt Taslakları</h1>
  </div>

  <!-- Ekle / Düzenle -->
  <div class="card">
    <h2 class="font-extrabold text-2xl mb-6 text-amber-300">
      <i class="fa-solid fa-circle-plus mr-2"></i><?= $duzenle ? 'Promptu Düzenle' : 'Yeni Prompt Ekle' ?>
    </h2>
    <form method="post" class="space-y-6">
      <input type="hidden" name="islem" value="<?= $duzenle ? 'kaydet' : 'ekle' ?>">
      <?php if ($duzenle): ?><input type="hidden" name="id" value="<?= (int)$duzenle['id'] ?>"><?php endif; ?>

      <div>
        <label class="label">Taslak Adı (ör. Restoran Karikatürü)</label>
        <input class="input" type="text" name="ad" required value="<?= htmlspecialchars($duzenle['ad'] ?? '') ?>" placeholder="Seçim kutusunda görünecek ad">
      </div>

      <div>
        <label class="label">Sektör</label>
        <input class="input" type="text" name="sektor" list="sektor_onerileri" value="<?= htmlspecialchars($duzenle['sektor'] ?? '') ?>" placeholder="Ör. Restoran">
        <datalist id="sektor_onerileri">
          <?php foreach (sektorler() as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?>
        </datalist>
      </div>

      <div>
        <label class="label">Prompt Metni</label>
        <textarea class="input" name="metin" rows="6" required placeholder="Resim için istediğin sahneyi yaz..."><?= htmlspecialchars($duzenle['metin'] ?? '') ?></textarea>
      </div>

      <button type="submit" class="btn-main">
        <i class="fa-solid <?= $duzenle ? 'fa-floppy-disk' : 'fa-circle-plus' ?>"></i>
        <?= $duzenle ? 'KAYDET' : 'PROMPTU EKLE' ?>
      </button>
    </form>
  </div>

  <!-- Liste -->
  <div class="card">
    <h2 class="font-extrabold text-2xl mb-6 text-amber-300"><i class="fa-solid fa-list mr-2"></i>Kayıtlı Promptlar (<?= count($promptlar) ?>)</h2>

    <?php if (!$promptlar): ?>
      <div class="text-center py-10 text-stone-400">
        <i class="fa-solid fa-comment-dots text-6xl mb-4 text-stone-700"></i>
        <p class="text-2xl font-bold">Henüz prompt yok.</p>
        <p class="mt-2">Yukarıdan ilk taslağını ekle.</p>
      </div>
    <?php else: ?>
      <div class="space-y-4">
        <?php foreach ($promptlar as $p): ?>
          <div class="rounded-2xl border border-stone-800 bg-stone-800/50 p-5">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 md:gap-6">
              <div>
                <div class="font-extrabold text-xl"><?= htmlspecialchars($p['ad']) ?></div>
                <?php if ($p['sektor'] !== ''): ?>
                  <span class="inline-block mt-1 text-xs font-bold uppercase px-2 py-1 rounded-lg bg-emerald-500/20 text-emerald-300"><?= htmlspecialchars($p['sektor']) ?></span>
                <?php endif; ?>
              </div>
              <div class="flex gap-2 shrink-0">
                <a href="generate.php?prompt_id=<?= (int)$p['id'] ?>" class="btn-main !py-2 !text-lg md:w-auto"><i class="fa-solid fa-wand-magic-sparkles"></i> Kullan</a>
                <a href="prompts.php?duzenle=<?= (int)$p['id'] ?>" class="btn-ikincil"><i class="fa-solid fa-pen"></i> Düzenle</a>
                <a href="prompts.php?sil=<?= (int)$p['id'] ?>" class="btn-tehlike" onclick="return confirm('Bu prompt silinsin mi?')"><i class="fa-solid fa-trash"></i></a>
              </div>
            </div>
            <div class="mt-4 text-stone-300 text-lg rounded-xl bg-stone-900/70 border border-stone-800 p-4 italic"><?= nl2br(htmlspecialchars($p['metin'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php alt_sayfa(); ?>