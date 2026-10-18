<?php
require __DIR__ . '/config.php';
kurulum();
giris_kontrol();

// Sayfalama
$adetSayfa = 12;
$toplam = resim_sayisi();
$sayfaAdet = max(1, (int)ceil($toplam / $adetSayfa));
$sayfa = max(1, min($sayfaAdet, (int)($_GET['s'] ?? 1)));

$resimler = resimler($adetSayfa, ($sayfa - 1) * $adetSayfa);

ust_sayfa('eski_resimler.php');
?>

<div class="card">
  <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <h2 class="font-extrabold text-2xl md:text-3xl text-amber-300"><i class="fa-solid fa-clock-rotate-left mr-2"></i>Eski Resimler (<?= $toplam ?>)</h2>
    <a href="generate.php" class="btn-ikincil !text-base"><i class="fa-solid fa-wand-magic-sparkles"></i> Yeni Üret</a>
  </div>

  <?php if (!$resimler): ?>
    <div class="text-center py-12 text-stone-400">
      <i class="fa-solid fa-images text-7xl mb-4 text-stone-700"></i>
      <p class="text-2xl font-bold">Henüz eski resim yok.</p>
      <p class="mt-2 text-lg"><a href="generate.php" class="text-amber-300 underline font-bold">Üret</a> sayfasından ilk resmini üret.</p>
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
            <a href="generate.php?baslik_resim=<?= urlencode($dosya) ?>" class="btn-ikincil mt-auto">
              <i class="fa-solid fa-font"></i> Başlık Üret
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($sayfaAdet > 1): ?>
      <div class="flex items-center justify-center gap-4 mt-8">
        <?php if ($sayfa > 1): ?>
          <a href="?s=<?= $sayfa - 1 ?>" class="btn-ikincil"><i class="fa-solid fa-chevron-left"></i> Önceki</a>
        <?php else: ?>
          <span class="btn-ikincil opacity-40 pointer-events-none"><i class="fa-solid fa-chevron-left"></i> Önceki</span>
        <?php endif; ?>

        <span class="text-lg font-bold text-stone-300"><?= $sayfa ?> / <?= $sayfaAdet ?></span>

        <?php if ($sayfa < $sayfaAdet): ?>
          <a href="?s=<?= $sayfa + 1 ?>" class="btn-ikincil">Sonraki <i class="fa-solid fa-chevron-right"></i></a>
        <?php else: ?>
          <span class="btn-ikincil opacity-40 pointer-events-none">Sonraki <i class="fa-solid fa-chevron-right"></i></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php alt_sayfa(); ?>