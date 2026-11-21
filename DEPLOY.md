# Yayına alma (HTTPS) — Demirtaş Yapı Market

Sistem PHP 8.3 + MariaDB/MySQL'dir. Kartsız/ücretsiz seçenekler sınırlıdır; **kart bilgisi girmeden önce kararı kendiniz verin.**

## Seçenek A — Kendi VPS'iniz (Ubuntu 24.04, Apache) — önerilen
```bash
sudo apt update && sudo apt install -y apache2 mariadb-server php8.3 php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl libapache2-mod-php8.3 certbot python3-certbot-apache git
sudo a2enmod rewrite headers ssl
sudo mysql_secure_installation
```
1. Veritabanı:
   ```sql
   CREATE DATABASE demirtas CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci;
   CREATE USER 'demirtas_app'@'localhost' IDENTIFIED BY '<güçlü-parola>';
   GRANT SELECT,INSERT,UPDATE,DELETE ON demirtas.* TO 'demirtas_app'@'localhost';
   ```
   `mysql demirtas < schema.sql` (şemayı yüklerken yönetici hesabıyla).
   Not: Denetim kaydı tablosu tetikleyicilerle değiştirilemez; uygulama kullanıcısına `DROP/ALTER/TRIGGER` **verilmez**.
2. Kod: `git clone <repo> /var/www/demirtas`; `cp .env.example .env`; `.env` içini doldurun; `chmod 640 .env`; sahibi `www-data`.
3. Yapay zekâ SELECT-only kullanıcı: `scripts/ai_readonly.sql` (parolayı değiştirin) → `.env` içine `AI_DB_USER/PASS`.
4. Kullanıcılar: `php scripts/seed_users.php` (rastgele parolalar basar; kaydedin). İlk girişten sonra demo kullanıcıları gerçek kişilere göre düzenleyin.
5. Apache sanal sunucu — **DocumentRoot = /var/www/demirtas/public** (proje kökü web'den erişilmemeli):
   ```apache
   <VirtualHost *:80>
     ServerName demo.alanadiniz.com
     DocumentRoot /var/www/demirtas/public
     <Directory /var/www/demirtas/public>
       AllowOverride All
       Require all granted
     </Directory>
   </VirtualHost>
   ```
   `sudo a2ensite demirtas && sudo systemctl reload apache2 && sudo certbot --apache -d demo.alanadiniz.com` → HTTPS ve otomatik yenileme.
6. Kur: uygulamada **Yönetim → Kur güncelle** düğmesi (patron). TCMB erişimi sunucuda denenmelidir.
7. Yedek: `0 3 * * * mysqldump --single-transaction demirtas | gzip > /var/backups/demirtas-$(date +\%F).sql.gz`

## Seçenek B — Paylaşımlı hosting (cPanel)
PHP 8.3 seçin, MySQL veritabanı/kullanıcı oluşturun, `schema.sql`'i phpMyAdmin ile içe aktarın, projeyi yükleyin; alan adının kök dizinini `public/` klasörüne yönlendirin (yönlendirilemiyorsa `public` içeriğini `public_html`'e, kalanını bir üst dizine koyup `index.php` içindeki yolları düzeltin). Ücretsiz SSL (Let's Encrypt/AutoSSL) açın. `.env` dosyası `public_html` DIŞINDA kalmalı.

## Yayın sonrası kontrol listesi
- [ ] `https://…` açılıyor, `http://` HTTPS'e yönleniyor
- [ ] `APP_ENV=production` (çerezler Secure işaretlenir)
- [ ] `.env`, `schema.sql`, `tests/` adresleri tarayıcıdan 404/403 (DocumentRoot doğruysa erişilemez)
- [ ] Her rol için demo kullanıcı giriş yapabiliyor; parolalar teslim e-postasında, repoda DEĞİL
- [ ] Mobil uygulamada sunucu adresi `https://…` olarak girildi
- [ ] Testler üretim DB'sinde ÇALIŞTIRILMAZ (yalnızca `*_test`)
- [ ] Veri içe aktarma: `php import.php` (rapor: `rapor/`)

## Seçenek C — InfinityFree (ücretsiz paylaşımlı hosting) — bu projede kullanılan
Kısıtları README'de ("Canlı yayın" bölümü): tetikleyici yetkisi yok, tarayıcı dışı istemcilere bot koruması, dışa çıkış kısıtlı.
1. Hesap panelinde MySQL veritabanı oluşturun (adı `if0_xxxxxxxx_demirtas` biçiminde olur) ve phpMyAdmin'de `schema.sql` (ya da hazır dump) dosyasını içe aktarın. Tetikleyiciler hata verirse atlayın.
2. Dosya yapısı: `public/` içeriğini `htdocs/` köküne, `src/ views/ config/` klasörlerini `htdocs/app/` altına koyun; `public/index.php` içindeki `require dirname(__DIR__) . '/src/autoload.php'` satırını `require __DIR__ . '/app/src/autoload.php'` yapın; `htdocs/app/.htaccess` içine `Require all denied` yazın.
3. `htdocs/app/.env` oluşturun (`DB_HOST=sqlXXX.infinityfree.com`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_ENV=production`).
4. Kullanıcı parolaları yerelde üretilip hash olarak içe aktarılır (hostingte komut satırı yoktur).
