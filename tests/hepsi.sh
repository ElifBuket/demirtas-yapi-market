#!/bin/sh
# Test veritabanını sıfırdan kurup tüm birim/iş kuralı testlerini çalıştırır (gerçek veriye dokunmaz).
# Kullanım: DB_USER=root DB_PASS= sh tests/hepsi.sh
set -e
cd "$(dirname "$0")/.."
MYSQL="mysql -u${DB_USER:-root} ${DB_PASS:+-p$DB_PASS}"
$MYSQL -e "DROP DATABASE IF EXISTS demirtas_test; CREATE DATABASE demirtas_test CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci"
$MYSQL demirtas_test < schema.sql
export DB_NAME=demirtas_test
php tests/run.php | tail -1
php tests/hesap_test.php | tail -1
php tests/stok_test.php | tail -1
php tests/satis_test.php | tail -1
php tests/rapor_test.php | tail -1
php tests/efatura_test.php | tail -1
php tests/cari_test.php | tail -1
php tests/asistan_test.php | tail -1
