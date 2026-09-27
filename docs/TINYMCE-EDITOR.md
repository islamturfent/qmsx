# Yerel TinyMCE web editörü

QuAmi web doküman editörü TinyMCE 8.9.1 ücretsiz çekirdeğini yerel olarak kullanır. Dosyalar `assets/vendor/tinymce` altında tutulur; sayfa Tiny Cloud, CDN veya API anahtarı kullanmaz. Yapılandırmada `license_key: "gpl"` seçilmiştir. TinyMCE lisansı `assets/vendor/tinymce/license.md`, Türkçe dil paketinin lisansı `assets/vendor/tinymce/I18N-LICENSE.md` dosyasındadır.

Bu kayıt teknik envanter bilgisidir; uygulamanın ticari SaaS kullanımında GPL şartlarına uygun olduğuna dair hukuki garanti değildir. Dağıtım modeli için lisans değerlendirmesi ayrıca yapılmalıdır.

Etkin ücretsiz özellikler: paragraf ve başlıklar, kalın/italik/altı çizili metin, sıralı ve sırasız listeler, bağlantılar, tablolar, geri al/yinele ve biçim temizleme. Resim yükleme aracı sunulmaz. Türkçe varsayılandır; sayfanın TR/EN seçimi editör diline de uygulanır. Açık/koyu tema ilk yüklemede seçilir ve mobilde araç çubuğu TinyMCE tarafından sarılır.

Sunucu tarafındaki HTML temizleyici yalnız izin verilen metin, liste, bağlantı ve tablo etiketlerini saklar. `script`, olay öznitelikleri, gömülü medya ve tehlikeli bağlantı şemaları çıkarılır. Yeni kayıt her zaman yeni bir HTML revizyonu oluşturur; tenant yetkisi, CSRF, ofis kilidi, bekleyen onay ve eşzamanlı sürüm kontrolleri sunucuda kalır.
