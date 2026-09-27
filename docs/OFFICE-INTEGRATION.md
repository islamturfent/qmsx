# QuAmi ofis entegrasyonu — mimari karar ve işletim rehberi

Karar tarihi: 17 Eylül 2026. Durum: QuAmi deneme entegrasyonu uygulanmıştır; gerçek ofis sunucusuyla kabul testi ayrıca gereklidir.

## Bu geliştirme makinesindeki kurulum durumu

18 Eylül 2026 itibarıyla Docker Desktop 4.91.0 resmî Winget paketiyle kurulmuştur. `Microsoft-Windows-Subsystem-Linux` ve `VirtualMachinePlatform` Windows özellikleri DISM ile etkinleştirilmiş, komut `3010` (yeniden başlatma gerekli) sonucuyla tamamlanmıştır. Bilgisayar yeniden başlatılmadan Docker Linux motoru ve Collabora CODE container'ı çalıştırılamaz. Yeniden başlatma sonrasında aşağıdaki yerel CODE adımları uygulanacak, ardından geçici DOCX ile gerçek iframe → düzenleme → PutFile → yeni revizyon kabul testi yapılacaktır. Bu not gerçek canlı kabul testinin henüz tamamlandığı anlamına gelmez.

## Kalıcı mimari karar

İlk geliştirme ve doğrulamada ücretsiz **Collabora Online CODE** kullanılacaktır. CODE, sürekli güncellenen geliştirme sürümüdür; ticari SaaS üretiminde **destekli Collabora Online veya uygun ticari ofis sağlayıcısına geçilecektir**. CODE'u denemek, üretim için destek/SLA alındığı anlamına gelmez. Collabora'nın kendi açıklaması CODE'u üretim ortamları için önermemektedir: [CODE](https://www.collaboraonline.com/code/), [resmî SSS](https://www.collaboraonline.com/faqs/).

Sağlayıcı, iş kurallarından ayrıdır. `QmsOfficeProvider` arayüzü ve ortam/ayar yapılandırması kullanılır. Bugünkü `collabora` ve `onlyoffice-wopi` seçenekleri WOPI protokolünü kullanır. Destekli Collabora'ya geçişte aynı adapter ve farklı sunucu adresleri yeterlidir. ONLYOFFICE için **WOPI etkin, uygun lisanslı dağıtım** gerekir; bu entegrasyon ONLYOFFICE'un ayrı Docs API/JWT callback protokolünü uygulamaz. O protokol seçilirse yeni adapter ve doğrulanmış callback endpoint'i gerekir; veritabanı revizyon/onay kuralları yeniden yazılmaz.

Mevcut HTML web editörü her zaman alternatif olarak kalır. PDF/Word/Excel içeriğini HTML'ye otomatik dönüştürmez; orijinal dosyayı koruyarak yeni web içeriği oluşturur.

## Kullanıcı akışı ve dosya desteği

Doküman Detayı → **Ofis Editörü** → **Ofis Editöründe Aç** veya **Salt Okunur Aç**. Dosya QuAmi sayfasındaki iframe içinde açılır. Dosyanın kullanıcı tarafından önce indirilmesi gerekmez; ofis sunucusu yetkili WOPI isteğiyle içeriği alır. Bu nedenle sağlayıcı sunucusu doküman verisini işler.

İlk kabul önceliği **DOCX Word akışıdır**: DOCX'i QuAmi içinde açma, düzenleme ve yeni revizyon olarak geri kaydetme. DOCX için discovery'de `edit` varsa düzenleme açılır. Eski `.doc` desteği de discovery yeteneğine bağlıdır; sağlayıcı `.doc` dosyasını doğrudan düzenleyebilir veya kendi içinde dönüştürme isteyebilir. QuAmi bu aşamada DOC→DOCX dönüşümü ve `PutRelativeFile` uygulamaz, dolayısıyla `.doc` desteği canlı sunucuyla ayrıca kabul edilmeden garanti edilmez.

XLS/XLSX ve PDF altyapıda discovery'ye göre yönlendirilir ancak Word kabulü tamamlanmadan bunlar canlı olarak doğrulanmış sayılmaz. PDF için CODE discovery tanımı `view_comment` sunar; bu denemede PDF **salt okunur** açılır. Tam PDF düzenleme ya da biçimler arası kayıpsız dönüşüm taahhüt edilmez. Yüklü sağlayıcının discovery yanıtı esas alınır. [Collabora discovery tanımı](https://github.com/CollaboraOnline/online.mirror/blob/main/discovery.xml).

Eski revizyonlar, incelemedeki ve arşivlenmiş dokümanlar salt okunurdur. Sağlayıcı/format düzenlemeyi desteklemiyorsa görüntüleme seçilir; görüntüleme de yoksa açık hata ve alternatif editör gösterilir. `PutRelativeFile`, Save As, yeniden adlandırma ve sunucuda otomatik DOC→DOCX/XLS→XLSX dönüşümü bu aşamada yoktur. ONLYOFFICE eski ikili biçimleri dönüştürmek isteyebilir; sağlayıcı değiştirme kabul testinde bunu özellikle doğrulayın.

Her **değişmiş içerikli başarılı PutFile** yeni `document_versions` satırı ve rastgele adlandırılmış yeni dosya oluşturur. Eski dosya değişmez. Otomatik kayıtlar da revizyon yaratabilir. Aynı içerikle tekrarlanan son kayıt yeni revizyon oluşturmaz. Revizyon numarası `W` + UTC zaman + rastgele ek ile otomatik üretilir. `documents.current_revision` güncellenir ve durum **draft** olur. Önceki onay geçmişi korunur fakat yeni revizyonu yayımlamaya izin vermez; yeniden talep → onay → yayın gerekir. Dosya yazma/veritabanı hatasında işlem geri alınır, o istek için oluşturulan dosya temizlenir.

## Güvenlik ve tenant izolasyonu

- Tenant sınırı `company_id`'dir. Belirteç; dosya oturumu, şirket, doküman, kullanıcı, revizyon, yazma yetkisi, yapılandırma özeti ve sona erme zamanına bağlıdır. Her callback'te aktif kullanıcı/şirket ve güncel şirket ataması tekrar kontrol edilir. Başka dosya ID'siyle kullanılamaz. Süper admin mevcut uygulamadaki global yetki modelini korur.
- 256 bit rastgele erişim belirtecinin yalnız SHA-256 özeti veritabanına yazılır. Varsayılan süre 30 dakika, izin verilen aralık 5–60 dakikadır. Sayfa yenileme/yeni açılış yeni belirteç üretir; otomatik sınırsız yenileme yoktur. Ayar değişikliği yapılandırma özeti aracılığıyla eski belirteçleri geçersiz kılar.
- Tarayıcıdan ofis oturumu açma ve ayar değiştirme POST + oturum CSRF doğrulaması ister. Ofis sunucusunun callback'leri tarayıcı çerezleriyle yetkilendirilmez; belirteç, tam IP izin listesi ve varsayılan olarak WOPI RSA/SHA-256 proof doğrulaması gerektirir. İmzaya token, dış WOPI URL'si ve zaman damgası dahildir; eski zaman damgası reddedilir. Güncel/eski anahtar geçiş kombinasyonları desteklenir. [WOPI proof doğrulaması](https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/online/scenarios/proofkeys).
- `REMOTE_ADDR` kullanılır; istemcinin `X-Forwarded-For` veya `Host` başlığına güvenilmez. Proxy kullanılıyorsa yalnız ofis sunucusunun geçebildiği ayrı proxy kuralı oluşturun. Genel amaçlı reverse proxy IP'sini tek güvenlik sınırı olarak kullanmayın; proof doğrulaması açık kalmalıdır.
- Yalnız açıkça seçilmiş yerel HTTP denemesinde proof kapatılabilir. Bu istisna localhost/özel IP/host.docker.internal adresleriyle sınırlandırılmıştır. Üretimde HTTPS, proof ve IP listesi birlikte zorunlu işletim gereksinimidir. TLS sertifika doğrulaması uygulamada kapatılmaz.
- Discovery yalnız adminin yapılandırdığı adresten, zaman/boyut sınırıyla, yönlendirme izlemeden alınır. XML dış varlıkları/DOCTYPE reddedilir. Başlatma adresleri yapılandırılmış sağlayıcı origin'iyle sınırlandırılır. QuAmi doküman içeriğini kullanıcıdan gelen bir callback URL'sine göndermez.
- WOPI lock, refresh, unlock ve unlock/relock işlemleri desteklenir. Kilit doküman başınadır; ilk aşama **tek yazarlı oturumdur**, çoklu eşzamanlı ortak yazarlık değildir. Aynı dokümana başka ofis oturumu kayıt yapamaz. Web editörü, dosya yükleme ve onay/yayın işlemleri de aktif ofis kilidini kontrol eder. Çakışmalar 409 ve WOPI kilit başlığıyla dönülür. Kilit 30 dakika veya token süresi kadar yaşar; refresh uzatır, token ömrünü uzatmaz.
- Doküman satır kilidi + beklenen revizyon kontrolü, eski ekranın yeni revizyonun üzerine kayıt yapmasını engeller. Ofis açıkken dışarıdan yapılan bir revizyon değişikliği de sessizce ezilmez.
- Boyut sınırı, ZIP yapısı/sıkıştırılmamış boyut sınırı ve dosya imzası kontrol edilir. Makro temizleyici/antivirüs bu kodun parçası değildir; SaaS dosya güvenliği katmanında ayrıca uygulanmalıdır.
- `office_audit` erişim, oturum açma, kilit, kayıt, reddetme, ayar ve sağlık kontrolünü kaydeder. Token, dosya içeriği veya token içeren URL kaydedilmez. Başarılı kayıt ve audit aynı transaction içindedir. Uygulama audit kayıtlarını güncellemez/silmez; üretimde ayrı DB yetkisi, yedekleme ve saklama politikası tanımlayın.
- WOPI/host sayfaları `no-store` kullanır. Service worker PHP sayfalarını, WOPI isteklerini ve üçüncü taraf ofis isteklerini önbelleğe almaz; eski cache sürümü temizlenir. `storage/documents` ve `storage/office` doğrudan HTTP erişimine kapalıdır. Apache `AllowOverride` etkin olmalı; başka sunucuda eşdeğer erişim engelini kurun.

## Dosyalar ve veritabanı

| Parça | Konum / görev |
|---|---|
| Yapılandırma | `config/office.php`, `storage/office/settings.json` (varsa), `QMS_OFFICE_*` ortam değişkenleri |
| Yönetim ekranı | `office-settings.php` — yalnız aktif süper admin |
| Host ekranı | `document-office.php` — doküman yetkisi + CSRF ile iframe başlatır |
| Adapter | `includes/office/adapter.php` — discovery, biçim ve başlatma URL'si |
| WOPI | `wopi.php/files/{opaque-id}` ve `/contents` |
| İş kuralları | `includes/office/service.php`, `security.php`, `locks.php` |
| Migration | `migrations/20260917-office.sql`, `scripts/migrate-office.php` |
| Yeni tablolar | `office_sessions`, `office_locks`, `office_audit` |

Migration mevcut tablo/sürümleri değiştirmez. Uygulamak için proje kökünde `C:\xampp\php\php.exe scripts/migrate-office.php` çalıştırın. Komut tekrar çalıştırılabilir. Mevcut projede bu migration uygulanmıştır; başka kurulumda ayrıca çalıştırın.

## Yerel CODE kurulumu — kullanıcı tarafından çalıştırılır

Bu çalışma Docker veya başka yazılım kurmaz ve container başlatmaz. Windows'ta kullanıcı tarafından kurulmuş Docker Desktop/Linux container altyapısı gerekir. PHP 8.2+, PDO MySQL, cURL, OpenSSL, DOM, mbstring ve ZipArchive gerekir. Apache PHP PATH_INFO'yu (`wopi.php/files/...`) kabul etmeli; QuAmi ve ofis sunucusu birbirine erişebilmelidir.

1. `deploy/office/.env.example` dosyasını aynı dizinde `.env` olarak kopyalayın. Örnek `latest` etiketi yalnız geliştirme içindir; doğruladığınız image digest/tag'ini kaydedin. Ticari ortamda desteklenen sürüm kullanın.
2. Kullanıcı kararıyla `docker compose --env-file .env -f compose.yaml up -d` çalıştırın. Örnek yalnız `127.0.0.1:9980` portunu açar, otomatik yeniden başlatma yapmaz. QuAmi WOPI host izin listesi `aliasgroup1` ile sınırlıdır.
3. CODE discovery'de proof-key bulunmuyorsa container içinde uygun kullanıcıyla `coolconfig generate-proof-key` çalıştırıp servisi yeniden başlatın; anahtarın coolwsd tarafından okunabildiğini ve yeniden oluşturma sonrası kalıcılığını sağlayın. Anahtar yolu/sürüm ayrıntıları için [Collabora resmî entegrasyon örneği](https://github.com/CollaboraOnline/collabora-drupal/blob/main/docker-compose.yml) ve [SDK](https://sdk.collaboraonline.com/CO-SDK-manual.pdf) esas alınmalıdır. Örnek compose anahtarları otomatik üretmez.
4. QuAmi → Sistem Yönetimi → **Ofis Entegrasyonu** ekranında adresleri girin. Örnek `.env` Docker Compose içindir; **PHP bunu kendiliğinden okumaz**. QuAmi ayarlarını ekrandan kaydedin veya Apache/PHP çalışma ortamına `QMS_OFFICE_*` değişkenlerini aktarın. Ortam değişkenleri ekrandaki ayarlardan üstündür; bu alanlar ekranda kilitlenir.
5. Windows örneği: tarayıcı ofis adresi `http://localhost:9980`, discovery `http://localhost:9980/hosting/discovery`, ofisten QuAmi adresi `http://host.docker.internal/qms`, tarayıcı QuAmi adresi `http://localhost/qms`. Container içindeki `localhost`, Windows makinesi değildir. `QMS_OFFICE_ALLOW_HTTP=true` yalnız bu yerel deneme içindir; proof açık kalır.
6. Callback IP listesine PHP'nin gerçekten gördüğü Docker/host adresini girin. Örnekteki loopback adresleri her Docker kurulumunda doğru olmayabilir. Wildcard kullanmayın. Firewall'u tüm ağa açmak yerine yalnız gerekli bağlantıyı tanımlayın.
7. **Kaydedilmiş Ayarlarla Bağlantıyı Test Et** discovery erişimini ve biçimleri denetler. Başarılı sonuç, container→QuAmi geri bağlantısının, WebSocket'in veya dosya kaydının test edildiği anlamına gelmez.
8. Entegrasyonu etkinleştirin. Ayrı deneme dokümanında DOCX/XLSX açma → düzenleme → kayıt → QuAmi yeni revizyon kontrolü yapın; sonra DOC/XLS ve PDF görüntülemeyi doğrulayın. İncelemede/eskide düzenleme kapalı olmalıdır. Test kayıtlarını uygulamanın veri saklama politikasına göre yönetin.

Sunucu yoksa/erişilemiyorsa QuAmi çalışmaya devam eder: ofis ekranı devre dışı/hata durumunu gösterir, web editörüne bağlantı sunar. Discovery timeout en fazla 8 saniyedir; 5 dakikalık özel önbellek vardır. TLS doğrulama hatasında sertifikayı düzeltin; doğrulamayı kapatmayın.

## Üretim ve lisans kapısı

Üretime geçmeden önce destekli sağlayıcı, ticari SaaS/çok kiracılı kullanım hakkı, kullanıcı/bağlantı kapsamı, marka/dağıtım yükümlülükleri, SLA, güvenlik güncellemesi ve veri işleme bölgesi sözleşmeyle doğrulanacaktır. Bu doküman ücretsiz sürümün ticari SaaS lisansını sağladığını iddia etmez. ONLYOFFICE lisansı/edition kapsamı ayrıca sağlayıcıyla doğrulanır.

HTTPS reverse proxy, WebSocket geçişi, iframe/CSP ayarları, karşılıklı host/IP izin listeleri, güvenilir CA, NTP saat eşitleme, dosya/audit yedekleme, disk kotası, erişim loglarında token maskeleme ve alarm planı gereklidir. Apache WOPI erişim logunda `%r` veya `%q` yerine sorgu parametresi içermeyen `%m %U %H` kullanın; proxy/ofis loglarında da `access_token` maskeleyin. Süresi dolmuş `office_sessions` ve kilitler için saklama/temizleme görevi planlayın; audit'i bu temizlikle silmeyin.

## Sağlayıcı değiştirme adımları

1. Yeni sağlayıcının destek/lisans ve veri işleme koşullarını tamamlayın. Destekli Collabora için `collabora`, ONLYOFFICE için `onlyoffice-wopi` seçin. ONLYOFFICE tarafında `wopi.enable=true`, proof keys ve QuAmi host/IP sınırlarını yapılandırın. [ONLYOFFICE resmî WOPI yapılandırması](https://api.onlyoffice.com/docs/docs-api/using-wopi/overview/).
2. Açık düzenlemeleri kaydedip kapatın; kilit ve oturumların sona ermesini bekleyin. Ofis entegrasyonunu geçici devre dışı bırakın. Kaydedilmemiş düzenleme varken sağlayıcı değiştirmeyin.
3. Ortam değişkenlerini veya yönetim ayarlarını güncelleyin: provider, public/discovery adresleri, callback IP'leri, QuAmi ve tarayıcı adresleri. HTTPS/proof açık olsun. Eski token'lar yapılandırma özeti değişince reddedilir.
4. Sağlık kontrolü, aşağıdaki testler ve gerçek DOC/DOCX/XLS/XLSX/PDF kabul testini yeni sağlayıcıyla tekrarlayın. Biçim dönüşümü/PutRelativeFile gerekirse ek geliştirme yapmadan o biçim için düzenleme açmayın. Eski `document_versions` dosyaları taşınmaz veya yeniden yazılmaz.
5. Etkinleştirin ve audit/hata oranını izleyin. Sorunda ofisi kapatıp web editörüne dönün; yeni sağlayıcıda kaydedilmiş revizyonları koruyun. Eski ayarları geri almak yeni ofis oturumu gerektirir.

## Doğrulama ve sınırlar

`php tests/office-integration.php` yalnız bağlantıya özel geçici tablolar ve rastgele geçici dosyalar kullanır; sonunda dosyaları temizler. Yetki/tenant, token, proof, kilit, çakışma, revizyon, taslağa dönüş, salt okunur durum, dosya biçimi ve audit kontrollerini kapsar. `tests/document-editor.php` ve `tests/document-workflow.php` mevcut HTML/onay akışının regresyon testleridir.

Gerçek Collabora/ONLYOFFICE kurulu olmadan iframe render, gerçek DOC/XLS biçim sadakati, autosave sıralaması, WebSocket, bağlantı kopması ve üretim eşzamanlılık/yük davranışı doğrulanmış sayılmaz. Sağlık kontrolü ve sahte WOPI istemci testleri bu kabul testinin yerine geçmez. İlk sürüm tek yazarlı, kısa süreli oturum modelidir.
