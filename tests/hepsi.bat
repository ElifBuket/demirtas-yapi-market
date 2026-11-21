@echo off
rem Windows (XAMPP) icin test calistirici. Gercek veriye dokunmaz: ayri "demirtas_test" veritabani kurar.
rem Kullanim:  cd C:\xampp\htdocs\demirtas  &  tests\hepsi.bat
chcp 65001 >nul
setlocal
set MYSQL=C:\xampp\mysql\bin\mysql.exe
set PHP=C:\xampp\php\php.exe
cd /d "%~dp0.."
"%MYSQL%" -uroot -e "DROP DATABASE IF EXISTS demirtas_test; CREATE DATABASE demirtas_test CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci" || goto hata
"%MYSQL%" -uroot --default-character-set=utf8mb4 demirtas_test < schema.sql || goto hata
set DB_NAME=demirtas_test
for %%T in (run hesap_test stok_test satis_test rapor_test efatura_test cari_test asistan_test denetim_zincir_test) do (
  echo --- %%T
  "%PHP%" tests\%%T.php > "%TEMP%\t_%%T.txt" 2>&1
  powershell -NoProfile -Command "Get-Content -Encoding UTF8 '%TEMP%\t_%%T.txt' | Select-Object -Last 2"
)
echo.
echo Bitti. Her satirda "0 basarisiz" olmali; BASARISIZ yazan satir varsa bana yazin.
goto son
:hata
echo HATA: MySQL baglantisi kurulamadi. XAMPP'te MySQL calisiyor mu?
:son
endlocal
