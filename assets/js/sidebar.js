const sidebarToggle = document.getElementById("sidebarToggle");

if (sidebarToggle) {
    sidebarToggle.addEventListener("click", function () {
        document.body.classList.toggle("sidebar-open");
    });
}

// Sol menü kaydirma konumunu sayfalar arasinda koru: bir menü maddesine
// tiklayinca menü baslangica kaymasin, tiklama konumunda kalsin.
(function () {
    const nav = document.querySelector(".sidebar-nav");
    if (!nav) return;
    const KEY = "qms-sidebar-scroll";
    const saved = parseInt(sessionStorage.getItem(KEY) || "0", 10);
    if (saved > 0) {
        nav.scrollTop = saved;
    }
    const persist = function () {
        try { sessionStorage.setItem(KEY, String(nav.scrollTop)); } catch (e) {}
    };
    nav.addEventListener("scroll", persist, { passive: true });
    window.addEventListener("pagehide", persist);
})();

const notificationState = document.getElementById("qmsNotificationState");
const notificationIcon = document.getElementById("qmsNotificationIcon");
const topbarActions = document.querySelector(".topbar-actions");
const languageButton = document.getElementById("languageToggle");

// Son bildirimler (sunucudan JSON olarak gelen <script type=application/json>).
let recentNotifications = [];
const recentScript = document.getElementById("qmsNotificationRecent");
if (recentScript) {
    try { recentNotifications = JSON.parse(recentScript.textContent || "[]"); } catch (e) { recentNotifications = []; }
}
const markAllCsrf = (document.getElementById("qmsMarkAllReadCsrf") || {}).value || "";

// Acilir menuler icin ortak yardimci: tetik + panel.
function attachMenuDropdown(trigger, panel) {
    trigger.addEventListener("click", function (event) {
        event.stopPropagation();
        panel.hidden = !panel.hidden;
    });
    document.addEventListener("click", function (event) {
        if (!panel.hidden && !trigger.parentElement.contains(event.target)) {
            panel.hidden = true;
        }
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && !panel.hidden) {
            panel.hidden = true;
        }
    });
}

// Header bildirim zili - TailAdmin tarzi acilir panel + "tumunu okundu isaretle".
if (notificationState && topbarActions && languageButton) {
    const unreadCount = Number.parseInt(notificationState.dataset.count || "0", 10);

    const wrapper = document.createElement("div");
    wrapper.className = "notification-menu";

    const bellBtn = document.createElement("button");
    bellBtn.type = "button";
    bellBtn.className = "notification-bell " + (unreadCount > 0 ? "has-notifications" : "is-empty");
    bellBtn.setAttribute("aria-label", unreadCount > 0 ? unreadCount + " okunmamış bildirim" : "Bildirim yok");
    bellBtn.innerHTML = (notificationIcon ? notificationIcon.innerHTML : "")
        + '<span class="notification-bell-count">' + unreadCount + "</span>";

    const panel = document.createElement("div");
    panel.className = "notification-panel";
    panel.hidden = true;

    const head = document.createElement("div");
    head.className = "notification-panel-head";
    const headTitle = document.createElement("strong");
    headTitle.setAttribute("data-i18n", "notificationPanelTitle");
    headTitle.textContent = "Bildirimler";
    head.appendChild(headTitle);

    const markAllBtn = document.createElement("button");
    markAllBtn.type = "button";
    markAllBtn.className = "notification-panel-action";
    markAllBtn.setAttribute("data-i18n", "markAllReadButton");
    markAllBtn.textContent = "Tümünü Okundu İşaretle";
    if (unreadCount === 0) markAllBtn.hidden = true;
    head.appendChild(markAllBtn);
    panel.appendChild(head);

    const list = document.createElement("div");
    list.className = "notification-panel-list";
    if (recentNotifications.length === 0) {
        const empty = document.createElement("div");
        empty.className = "notification-panel-empty";
        empty.setAttribute("data-i18n", "notificationPanelEmpty");
        empty.textContent = "Bildirim yok";
        list.appendChild(empty);
    } else {
        recentNotifications.forEach(function (item) {
            const a = document.createElement("a");
            a.className = "notification-panel-item" + (item.is_read ? " is-read" : "");
            a.href = item.link_url || "notifications.php";
            const text = document.createElement("span");
            text.className = "notification-panel-text";
            text.textContent = item.message || item.title || "";
            a.appendChild(text);
            const time = document.createElement("small");
            time.className = "notification-panel-time";
            time.textContent = item.created_at || "";
            a.appendChild(time);
            list.appendChild(a);
        });
    }
    panel.appendChild(list);

    const foot = document.createElement("a");
    foot.className = "notification-panel-footer";
    foot.href = "notifications.php";
    foot.setAttribute("data-i18n", "notificationPanelSeeAll");
    foot.textContent = "Tümünü Gör";
    panel.appendChild(foot);

    // "Tumunu okundu isaretle" -> AJAX + zil sayaci/panel canli guncellenir.
    markAllBtn.addEventListener("click", function () {
        if (!markAllCsrf) return;
        fetch("notifications-ajax.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "csrf=" + encodeURIComponent(markAllCsrf) + "&form_type=mark_all_read"
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res && res.ok) {
                bellBtn.classList.remove("has-notifications");
                bellBtn.classList.add("is-empty");
                const c = bellBtn.querySelector(".notification-bell-count");
                if (c) c.textContent = "0";
                bellBtn.setAttribute("aria-label", "Bildirim yok");
                panel.querySelectorAll(".notification-panel-item").forEach(function (i) { i.classList.add("is-read"); });
                markAllBtn.hidden = true;
            }
        }).catch(function () {});
    });

    bellBtn.addEventListener("click", function (event) {
        event.stopPropagation();
        panel.hidden = !panel.hidden;
    });
    document.addEventListener("click", function (event) {
        if (!panel.hidden && !wrapper.contains(event.target)) {
            panel.hidden = true;
        }
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && !panel.hidden) {
            panel.hidden = true;
        }
    });

    wrapper.appendChild(bellBtn);
    wrapper.appendChild(panel);
    topbarActions.insertBefore(wrapper, languageButton);
}

// Header kullanici menusu: TailAdmin'deki profil menusunun karsiligi.
const userMenuState = document.getElementById("qmsUserMenuState");

if (userMenuState && topbarActions && languageButton) {
    const userName = userMenuState.dataset.name || "";
    const userRole = userMenuState.dataset.role || "";
    const userInitials = userMenuState.dataset.initials || "";
    const hasAvatar = userMenuState.dataset.avatar === "1";

    const wrapper = document.createElement("div");
    wrapper.className = "user-menu";

    const toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "user-menu-toggle";
    toggle.setAttribute("aria-haspopup", "true");
    toggle.setAttribute("aria-expanded", "false");

    const avatar = document.createElement("span");
    avatar.className = "user-menu-avatar";
    if (hasAvatar) {
        const image = document.createElement("img");
        image.src = "avatar.php";
        image.alt = "";
        avatar.appendChild(image);
    } else {
        avatar.textContent = userInitials;
    }

    const nameSpan = document.createElement("span");
    nameSpan.className = "user-menu-name";
    nameSpan.textContent = userName;

    const caret = document.createElement("span");
    caret.setAttribute("aria-hidden", "true");
    caret.className = "user-menu-caret";
    caret.textContent = "\u25BE";

    toggle.appendChild(avatar);
    toggle.appendChild(nameSpan);
    toggle.appendChild(caret);

    const dropdown = document.createElement("div");
    dropdown.className = "user-menu-dropdown";
    dropdown.hidden = true;

    const head = document.createElement("div");
    head.className = "user-menu-head";
    const headName = document.createElement("strong");
    headName.textContent = userName;
    const headRole = document.createElement("span");
    headRole.textContent = userRole;
    head.appendChild(headName);
    head.appendChild(headRole);
    dropdown.appendChild(head);

    [
        { href: "profile.php", key: "profileTitle", fallback: "Profil" },
        { href: "profile.php#editPassword", key: "profilePasswordTitle", fallback: "Şifre Değiştir" },
        { href: "logout.php", key: "logoutLabel", fallback: "Çıkış" }
    ].forEach(function (item) {
        const link = document.createElement("a");
        link.className = "user-menu-item";
        link.href = item.href;
        link.setAttribute("data-i18n", item.key);
        link.textContent = item.fallback;
        dropdown.appendChild(link);
    });

    toggle.addEventListener("click", function (event) {
        event.stopPropagation();
        const willOpen = dropdown.hidden;
        dropdown.hidden = !willOpen;
        toggle.setAttribute("aria-expanded", willOpen ? "true" : "false");
    });

    document.addEventListener("click", function (event) {
        if (!dropdown.hidden && !wrapper.contains(event.target)) {
            dropdown.hidden = true;
            toggle.setAttribute("aria-expanded", "false");
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && !dropdown.hidden) {
            dropdown.hidden = true;
            toggle.setAttribute("aria-expanded", "false");
        }
    });

    wrapper.appendChild(toggle);
    wrapper.appendChild(dropdown);
    topbarActions.insertBefore(wrapper, languageButton);
}

// Dil ve tema toggleslarini TailAdmin tarzi ikonlu dropdown'a donusturur.
function wrapTopbarMenu(trigger) {
    const wrap = document.createElement("div");
    wrap.className = "topbar-menu";
    trigger.parentNode.insertBefore(wrap, trigger);
    wrap.appendChild(trigger);
    return wrap;
}

// Tema dropdown: Açık / Koyu / Sistem.
const themeToggle = document.getElementById("themeToggle");
const sunIconHtml = (document.getElementById("qmsIconSun") || {}).innerHTML || "☀️";
const moonIconHtml = (document.getElementById("qmsIconMoon") || {}).innerHTML || "🌙";
const monitorIconHtml = (document.getElementById("qmsIconMonitor") || {}).innerHTML || "🖥️";
if (themeToggle) {
    const wrap = wrapTopbarMenu(themeToggle);
    wrap.classList.add("theme-menu");
    const panel = document.createElement("div");
    panel.className = "topbar-dropdown";
    panel.hidden = true;
    [
        ["light", sunIconHtml, "themeLightLabel", "Açık"],
        ["dark", moonIconHtml, "themeDarkLabel", "Koyu"],
        ["system", monitorIconHtml, "themeSystemLabel", "Sistem"]
    ].forEach(function (o) {
        const b = document.createElement("button");
        b.type = "button";
        b.className = "topbar-menu-option";
        b.innerHTML = '<span class="topbar-btn-icon">' + o[1] + '</span><span data-i18n="' + o[2] + '">' + o[3] + '</span>';
        b.addEventListener("click", function () {
            if (typeof window.qmsSetTheme === "function") window.qmsSetTheme(o[0]);
            panel.hidden = true;
        });
        panel.appendChild(b);
    });
    wrap.appendChild(panel);
    attachMenuDropdown(themeToggle, panel);
}

// Dil dropdown: Türkçe / English.
if (languageButton) {
    const wrap = wrapTopbarMenu(languageButton);
    wrap.classList.add("language-menu");
    const panel = document.createElement("div");
    panel.className = "topbar-dropdown";
    panel.hidden = true;
    [
        ["tr", "Türkçe"],
        ["en", "English"]
    ].forEach(function (o) {
        const b = document.createElement("button");
        b.type = "button";
        b.className = "topbar-menu-option";
        b.innerHTML = '<span class="topbar-btn-label">' + o[1] + '</span>';
        b.addEventListener("click", function () {
            if (typeof changeLanguage === "function") changeLanguage(o[0]);
            panel.hidden = true;
        });
        panel.appendChild(b);
    });
    wrap.appendChild(panel);
    attachMenuDropdown(languageButton, panel);
}

// Enjekte edilen ogeler icin ceviriyi yeniden uygula.
if (typeof changeLanguage === "function") {
    changeLanguage(currentLanguage);
}
