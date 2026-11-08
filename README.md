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
- 📸 Instagram'a doğrudan paylaşım — hesap seç, caption düzenle, otomatik yayınla
- 👥 Çoklu Instagram hesabı yönetimi (`instagram.php`)
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
6. Instagram paylaşımı için: `env.php` içindeki IG sabitlerini doldurun ve **Instagram** sayfasından hesap ekleyin (aşağıya bakın)

## 📸 Instagram Paylaşımı

Uygulama, **Instagram Girişi** yolunu (`graph.instagram.com`) kullanır — bu sayede **Facebook sayfası gerekmez**.
İki adımlı **içerik yayınlama** akışı uygulanır: önce bir medya konteyneri oluşturulur, sonra yayınlanır.

### Gereksinimler

- **Instagram Business veya Creator** hesabı (kişisel hesap olmaz)
- Meta uygulamasında `instagram_business_basic` + `instagram_business_content_publish` izinleri
- Yayınlanacak resme **internetten erişilebilen** bir adres

### 1) `env.php` sabitleri

> 🔐 Şifre, anahtarlar ve adresler `env.php` dosyasında tutulur (`.gitignore` ile sürüm kontrolünden hariç tutulur).
> Dosya yoksa `config.php` içindeki varsayılan değerler kullanılır.

| Sabit | Açıklama |
|---|---|
| `PAROLA` | Panel giriş şifresi (varsayılan `m1`) |
| `IG_HOST` | `graph.instagram.com` (Instagram Girişi — varsayılan) |
| `IG_SURUM` | Graph API sürümü (varsayılan `v26.0`) |
| `IG_APP_ID` | Uygulama ID (developers.facebook.com) |
| `IG_APP_SECRET` | Uygulama gizli anahtarı (App Secret) |
| `IG_REDIRECT_URI` | OAuth dönüş adresi (ör. `https://site.com/instagram.php`) |
| `PUBLIC_BASE_URL` | Resimlerin **internetten erişilebilen** adresi (ör. `https://site.com` veya ngrok adresi) |

> ⚠️ **Önemli:** Instagram yayınlanacak resme dışarıdan erişilebilen bir adresten ulaşmak zorundadır.
> `localhost` çalışmaz. Canlı bir alan adı ya da tünel adresini (ör. ngrok) `PUBLIC_BASE_URL` olarak girin.

> ℹ️ Eski **Facebook Girişi** yolunu (Facebook sayfası zorunlu, `graph.facebook.com`) kullanmak istersen
> `IG_HOST` sabitini `graph.facebook.com` yapman yeterli; kodun geri kalanı aynı kalır.

### 2) Hesap ekleme

**Instagram** sayfasında iki yol var:

**a) Instagram ile Bağlan (önerilen — token elle girilmez):**
Butona basılır → Instagram giriş ekranına yönlendirilir → kullanıcı izinleri onaylar →
sistem kodu otomatik olarak **60 günlük uzun ömürlü token'a** çevirir, kullanıcı adını çeker ve hesabı kaydeder.
Token süresi dolmadan listedeki **Yenile** butonuyla süre uzatılabilir.

**b) Elle ekleme:** Her hesap için şunlar girilir:

- **Hesap Adı** — panelde görünecek ad
- **Instagram Kullanıcı ID** — IG Business/Creator hesabının ID'si
- **Erişim Anahtarı** — `instagram_business_content_publish` iznine sahip uzun ömürlü token

### 3) Paylaşma

Üretilen bir resmin altındaki **Paylaş** butonuna basılır → açılan pencerede hesap seçilir,
caption (otomatik dolu) düzenlenir ve **Paylaş** onaylanır. Resim seçili hesapta yayınlanır.

## 📁 Dosya Yapısı

| Dosya | Görev |
|---|---|
| `index.php` | Giriş ekranı (tek şifre) |
| `generate.php` | Karikatür üretme + caption ekleme + son 3 resim |
| `eski_resimler.php` | Eski resimler (sayfalı) |
| `models.php` | Model ekleme/düzenleme/silme + API anahtarı |
| `prompts.php` | Prompt taslak yönetimi |
| `instagram.php` | Instagram hesapları (ekleme/düzenleme/silme) + yapılandırma durumu |
| `instagram_paylas.php` | Instagram yayınlama uç noktası (AJAX) |
| `uretim_calisan.php` | Arka plan işçisi (uzun üretimlerde arayüz takılmasın) |
| `config.php` | Yapılandırma + yardımcı fonksiyonlar |
| `env.php` | Şifre + anahtarlar + adresler (gizli, `.gitignore`'da) |
| `data/comics.db` | SQLite veritabanı |
| `data/isler/` | Arka plan iş durum dosyaları |
| `uploads/` | Üretilen resimler (+ yanında `.txt` caption dosyaları) |

## 📝 Notlar

- Windows PHP sunucusu tek işlemli çalıştığı için uzun resim üretimleri **arka plan işçisinde** yürütülür; süreç bir JSON dosyası üzerinden izlenir.
- OpenRouter önce `/api/v1/images`, olmazsa `/api/v1/images/generations`, en son chat ucu denenir.
- Resimler `uploads/` klasöründe fiziksel saklanır; veritabanında resim kaydı tutulmaz (sadece model/prompt/ayar ve Instagram hesapları).