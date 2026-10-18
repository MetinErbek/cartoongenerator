<?php
require __DIR__ . '/config.php';
kurulum();

if (!empty($_GET['cikis'])) { unset($_SESSION['giris']); header('Location: index.php'); exit; }
if (!empty($_SESSION['giris'])) { header('Location: generate.php'); exit; }

$hata = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['parola'] ?? '') === PAROLA) {
        $_SESSION['giris'] = true;
        header('Location: generate.php');
        exit;
    }
    $hata = 'Şifre yanlış!';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Giriş — Komik Sahne Üretici</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<script>
tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'sans-serif'] } } } };
</script>
</head>
<body class="bg-stone-950 text-stone-100 font-sans min-h-screen flex items-center justify-center px-4">
  <div class="w-full max-w-md">
    <div class="text-center mb-8">
      <div class="text-7xl mb-4">🎭</div>
      <h1 class="font-black text-4xl text-amber-400 tracking-tight">Komik Sahne Üretici</h1>
      <p class="text-stone-400 mt-2 text-xl">Giriş yap, karikatürleri üretmeye başla</p>
    </div>

    <form method="post" class="bg-stone-900 border border-stone-800 rounded-3xl p-8 shadow-2xl space-y-6">
      <div>
        <label class="block text-sm font-bold uppercase tracking-wider text-stone-400 mb-2">Şifre</label>
        <input type="password" name="parola" required autofocus autocomplete="off"
               class="w-full rounded-xl bg-stone-800 border-2 border-stone-700 px-4 py-4 text-2xl text-center text-stone-100 placeholder-stone-500 focus:outline-none focus:border-amber-400 transition"
               placeholder="••••">
      </div>
      <?php if ($hata !== ''): ?>
        <div class="bg-red-900/50 border border-red-800 text-red-200 rounded-xl px-4 py-3 text-center font-bold text-xl"><?= htmlspecialchars($hata) ?></div>
      <?php endif; ?>
      <button type="submit" class="inline-flex items-center justify-center gap-2 w-full rounded-2xl bg-amber-400 hover:bg-amber-300 text-stone-950 text-2xl font-extrabold py-5 shadow-lg shadow-amber-500/20 transition active:scale-95">
        <i class="fa-solid fa-right-to-bracket"></i> GİRİŞ
      </button>
    </form>
  </div>
</body>
</html>