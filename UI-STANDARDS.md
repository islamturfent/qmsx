# QMS Arayuz Standartlari

## Tasarim Sistemi

- Renk, golge, kose yariciligi ve tipografi degerleri `assets/css/style.css` icindeki tasarim token'lari ile yonetilir. Bilesen kurallarinda sabit hex renk yazilmaz; token degiskenleri referans alinir.
- Palet TailAdmin tasarim sistemini takip eder: marka olcegi `--brand-25` ... `--brand-900` (ana renk `--brand-500` = `#465fff`), notr olcek `--gray-25` ... `--gray-950`, durum renkleri `--success-*`, `--warning-*`, `--error-*`, `--orange-*`, `--purple-*`.
- Golgeler `--shadow-theme-xs` ... `--shadow-theme-xl`, odak halkalari `--shadow-focus-ring` ve `--shadow-focus-ring-danger` ile tanimlidir. Kartlar `--shadow-theme-xs` kullanir; hover durumunda yalnizca kenarlik rengi ve golge degisir, konum degismez.
- Yazi tipi `--font-sans` ("Outfit", yuklenmezse Inter / Segoe UI). Basliklar 600 agirlik ve negatif harf araligi kullanir; govde metni 14px.
- Kartlar 16px (`--border-radius`), form kontrolleri ve dugmeler 8px (`--radius-control`) kose yariciligi kullanir.

## Renk Semantigi

- Metin icin `--text-color`, `--text-body`, `--muted-color`; zemin icin `--surface-color`, `--surface-strong-color`, `--surface-hover`, `--surface-muted`; kenarlik icin `--border-subtle`, `--border-row`, `--border-control` kullanilir.
- Durum etiketleri (`status-badge`, `status-pill`, `risk-level-*`, `review-alert`, `form-message`) dogrudan renk yazmak yerine `--*-soft` / `--*-soft-text` ciftlerini kullanir. Bu ciftler koyu temada otomatik olarak yeniden tanimlandigi icin etiketler her iki temada da okunakli kalir.
- Yeni bir token eklerken `:root` ve `body.dark-mode` bloklarinin ikisi birlikte guncellenmelidir.

## Ikonlar

- Sidebar ve ozet kart ikonlari `includes/app-sidebar.php` icindeki `appIcon()` fonksiyonundan gelir: 24x24 olcekli, `currentColor` ile renk alan cizgi ikonlar.
- Yeni ikon eklenirken fonksiyondaki tek ikon haritasi guncellenir; sayfalara elle SVG yazilmaz ve emoji ikon kullanilmaz.
- Cagrim bicimi: sidebar icin `appIcon("users")`, kart ikonlari icin `appIcon("users", "dashboard-card-icon")`. Sarmalayici istenmezse ikinci parametre `""` verilir.
- Ikon boyutlari CSS tarafinda tanimlidir (`sidebar-link-icon svg`, `dashboard-card-icon svg`, `notification-bell svg`).

## Dugmeler

- Tum dugmeler TailAdmin recetesini kullanir: `inline-flex`, `gap: 8px`, 8px kose yariciligi, `12px 16px` dolgu (44px yukseklik), 14px/500 yazi. Olcu degisikligi yalnizca `style.css` icindeki dugme kurallarinda yapilir.
- Ana islemler `primary-button` sinifini kullanir: dolu `--brand-500`, hover `--brand-600`, aktif `--brand-700`. Golge kullanmaz.
- Ikincil islemler `secondary-button` sinifini kullanir: beyaz zemin, `--border-control` kenarlik, hover `--surface-hover`.
- Geri donulemez veya olumsuz kararlar `danger-button` kullanir: dolu `--error-500`, hover `--error-600`.
- Header'daki dil/tema kontrolleri `.topbar-button` kullanir: 44x44px yuvarlak, ince kenarlikli, hover `--nav-hover`.
- Sayfa seviyesindeki olusturma ve genel komut dugmeleri header icinde yer almaz; sayfa basliginin saginda veya ilgili icerik bolumunde bulunur.
- Dil ve tema kontrolleri header icinde kalir; bunlar ana islem dugmesi degildir.
- Sayfa ici gezinme baglantilari ("Listeye Don", "Dokumanlara Don", "Denetime Don", "Kayitli Sirketler" gibi) header'da tutulmaz; sayfa basliginin saginda `secondary-button` olarak yer alir.
- Header'da yalnizca dil/tema kontrolleri, genel gezinme (Dashboard) ve cikis baglantisi kalir.
- Masaustunde dugmeler baslik veya form satiriyla hizalanir; mobilde tam genislige gecerek tasma olusturmaz.
- Yeni ekranlarda farkli bir dugme stili uretilmez; mevcut siniflar yeniden kullanilir.

## Tema

- Acik/koyu tema `body.dark-mode` sinifi ile yonetilir (`assets/js/theme.js`, `localStorage` anahtari `qms-theme`).
- Koyu temada yalnizca token degerleri degisir; bilesen kurallari tekrar yazilmaz.
- PWA renkleri (`manifest.webmanifest`, sayfa `theme-color` meta etiketi, `theme.js`) acik tema zemini `#f9fafb`, koyu tema zemini `#101828` ile uyumlu tutulur.
