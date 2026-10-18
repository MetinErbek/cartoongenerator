# 🎭 Komik Sahne Üretici

OpenRouter tabanlı, sektörlere uygun **komik / konuşma balonlu karikatür** üreten basit PHP uygulaması.
Yapay zekâ modelleriyle karikatür üretir, metin modelleriyle de bu karikatürlere **caption (başlık + İngilizce çeviri + etiketler)** yazar.

## ✨ Özellikler

- 🔐 Tek şifreli basit giriş (varsayılan: `m1`)
- 🖼️ Birden çok görsel model — CRUD işlemleri (`models.php`)
- 💬 Birden çok metin (kategorili) modeli — resimlere caption üretir
- 📝 Prompt taslakları — CRUD, selectbox'tan seçince textarea'ya dolan
- ⚡ AJAX + SweetAlert2 ile arka plan üretimi ve ilerleme takibi
- 📜 Caption: Türkçe başlık + parantez içinde İngilizce çevirisi + bol etiket (hashtag)
- 🖱️ Resme tıklayınca popup'ta büyük görüntüleme
- ⬇️ Her resmin altında indirme butonu
- 📂 "Eski Resimler" sayfasında sayfalı liste (12/sayfa)
- 🔄 WebP gelen resimler otomatik PNG'ye çevrilir (GD)
- 🗜️ Görsel üretim prompt'unda JPG önceliği istenir

## 🛠️ Teknolojiler

- PHP 8+ (yerleşik dev sunucusu / XAMPP)
- SQLite (WAL + performance pragmaları)
- Tailwind CSS (CDN), Font Awesome, SweetAlert2 (CDN)
- OpenRouter API (görsel + metin modelleri)
- GD kütüphanesi (WebP → PNG dönüşümü)
- **NPM / Node.js / Vite YOK** — tamamı CDN üzerinden

## 🚀 Kurulum

1. Klasörü bir PHP sunucusuna koy (ör. `php -S localhost:8000`)
2. `php.ini` içinde GD uzantısını açın: `extension=gd`
3. Tarayıcıdan uygulamayı açın → şifreyle giriş yapın (`m1`)
4. **Modeller** sayfasından OpenRouter API anahtarını kaydedin
5. Görsel ve metin modelleri ekleyin, prompt seçin, **RESİMLERİ ÜRET** deyin 🎉

## 📁 Dosya Yapısı

| Dosya | Görev |
|---|---|
| `index.php` | Giriş ekranı (tek şifre) |
| `generate.php` | Karikatür üretme + caption ekleme + son 3 resim |
| `eski_resimler.php` | Eski resimler (sayfalı) |
| `models.php` | Model ekleme/düzenleme/silme + API anahtarı |
| `prompts.php` | Prompt taslak yönetimi |
| `uretim_calisan.php` | Arka plan işçisi (uzun üretimlerde arayüz takılmasın) |
| `config.php` | Yapılandırma + yardımcı fonksiyonlar |
| `data/comics.db` | SQLite veritabanı |
| `data/isler/` | Arka plan iş durum dosyaları |
| `uploads/` | Üretilen resimler (+ yanında `.txt` caption dosyaları) |

## 📝 Notlar

- Windows PHP sunucusu tek işlemli çalıştığı için uzun resim üretimleri **arka plan işçisinde** yürütülür; süreç bir JSON dosyası üzerinden izlenir.
- OpenRouter önce `/api/v1/images`, olmazsa `/api/v1/images/generations`, en son chat ucu denenir.
- Resimler `uploads/` klasöründe fiziksel saklanır; veritabanında resim kaydı tutulmaz (sadece model/prompt/ayarlar).