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

// Header bildirim zili - TailAdmin tarzi acilir panel.
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

    const iconUser = (document.getElementById("qmsIconUserMenuUser") || {}).innerHTML || "";
    const iconCog = (document.getElementById("qmsIconUserMenuCog") || {}).innerHTML || "";
    const iconKey = (document.getElementById("qmsIconUserMenuKey") || {}).innerHTML || "";
    const iconChevron = (document.getElementById("qmsIconUserMenuChevron") || {}).innerHTML || "";
    const iconLogout = (document.getElementById("qmsIconUserMenuLogout") || {}).innerHTML || "";

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

    // TailAdmin: avatar + ad/rol (iki satir) + chevron SVG.
    const textBlock = document.createElement("span");
    textBlock.className = "user-menu-id";
    const nameSpan = document.createElement("span");
    nameSpan.className = "user-menu-name";
    nameSpan.textContent = userName;
    const roleSpan = document.createElement("span");
    roleSpan.className = "user-menu-role";
    roleSpan.textContent = userRole;
    textBlock.appendChild(nameSpan);
    textBlock.appendChild(roleSpan);

    const chevron = document.createElement("span");
    chevron.className = "user-menu-caret";
    chevron.setAttribute("aria-hidden", "true");
    chevron.innerHTML = iconChevron;

    toggle.appendChild(avatar);
    toggle.appendChild(textBlock);
    toggle.appendChild(chevron);

    const dropdown = document.createElement("div");
    dropdown.className = "user-menu-dropdown";
    dropdown.hidden = true;

    // TailAdmin basligi: avatar + ad + rol.
    const head = document.createElement("div");
    head.className = "user-menu-head";
    const headAvatar = document.createElement("span");
    headAvatar.className = "user-menu-avatar user-menu-head-avatar";
    headAvatar.innerHTML = hasAvatar ? '<img src="avatar.php" alt="">' : userInitials;
    const headText = document.createElement("div");
    headText.className = "user-menu-head-text";
    const headName = document.createElement("strong");
    headName.textContent = userName;
    const headRole = document.createElement("span");
    headRole.textContent = userRole;
    headText.appendChild(headName);
    headText.appendChild(headRole);
    head.appendChild(headAvatar);
    head.appendChild(headText);
    dropdown.appendChild(head);

    // TailAdmin maddeleri: ikon + etiket.
    function userMenuItem(href, icon, key, fallback) {
        const a = document.createElement("a");
        a.className = "user-menu-item";
        a.href = href;
        const ic = document.createElement("span");
        ic.className = "user-menu-item-icon";
        ic.innerHTML = icon;
        const lbl = document.createElement("span");
        lbl.setAttribute("data-i18n", key);
        lbl.textContent = fallback;
        a.appendChild(ic);
        a.appendChild(lbl);
        return a;
    }

    dropdown.appendChild(userMenuItem("profile.php", iconUser, "profileTitle", "Profil"));
    dropdown.appendChild(userMenuItem("profile.php", iconCog, "accountSettingsMenuLabel", "Hesap Ayarları"));
    dropdown.appendChild(userMenuItem("profile.php#editPassword", iconKey, "profilePasswordTitle", "Şifre Değiştir"));
    const divider = document.createElement("div");
    divider.className = "user-menu-divider";
    dropdown.appendChild(divider);
    dropdown.appendChild(userMenuItem("logout.php", iconLogout, "logoutLabel", "Çıkış"));

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

    // Enjekte edilen ogeler icin ceviriyi yeniden uygula.
    if (typeof changeLanguage === "function") {
        changeLanguage(currentLanguage);
    }
}
