# Kendi bilgisayarında çalıştırma (Windows, adım adım)

## 1. İndirilecek programlar
| Program | Ne için | Not |
|---|---|---|
| **XAMPP** (PHP 8.2 veya 8.3 sürümü) – apachefriends.org | PHP + MySQL (MariaDB) | Kurulumda yalnızca **Apache** ve **MySQL** yeter |
| **Visual Studio Code** – code.visualstudio.com | Kodu okumak/düzenlemek | |
| **Git** – git-scm.com | GitHub'a yüklemek | |
| **Node.js LTS** – nodejs.org | Mobil uygulama (sonraki aşama) | Şimdilik gerekmez, mobil aşamasında kurarız |
| **Expo Go** (telefona, mağazadan) | Mobil uygulamayı denemek | Mobil aşamasında |
| Tarayıcı (Chrome) | Denemek | |

Hesaplar: **GitHub** (ücretsiz), bir **hosting** hesabı (canlı adres için; PHP+MySQL destekleyen, ücretsiz deneme sunan biri).

## 2. Projeyi yerleştir
1. `demirtas-proje.zip` dosyasını `C:\demirtas` klasörüne çıkar (içinde `schema.sql`, `public`, `src` görünmeli).
2. XAMPP Control Panel'i aç, **Apache** ve **MySQL** için *Start*'a bas.
3. `C:\xampp\php\php.ini` dosyasında şu satırların başında `;` olmasın: `extension=zip`, `extension=mbstring`, `extension=pdo_mysql`, `extension=intl` (varsa). Değiştirdiysen Apache'yi yeniden başlat.
4. PowerShell aç: `$env:Path += ";C:\xampp\php"` (bu pencere için `php` komutunu tanıtır).

## 3. Veritabanı
1. Tarayıcıda `http://localhost/phpmyadmin` → *Yeni* → ad: `demirtas`, karşılaştırma: `utf8mb4_turkish_ci` → Oluştur.
2. PowerShell'de:
   ```
   cd C:\demirtas
   php import.php C:\yol\demirtas_eski_kayitlar.xlsx --kur
   php scripts\seed_users.php
   ```
   İkinci komut 4 deneme kullanıcısının parolasını ekrana yazar — **not et**.

## 4. Çalıştır
```
cd C:\demirtas
php -S 127.0.0.1:8081 -t public public/index.php
```
Tarayıcı: `http://127.0.0.1:8081` → patron / muhasebe / depo / satisci ile giriş yap.

## 5. Testler (isteğe bağlı ama sunumda göstermek iyi olur)
```
$env:DB_USER="root"
sh tests/hepsi.sh      # Git ile gelen "Git Bash" içinde çalıştır
```
