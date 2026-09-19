const sidebarToggle = document.getElementById("sidebarToggle");

if (sidebarToggle) {
    sidebarToggle.addEventListener("click", function () {
        document.body.classList.toggle("sidebar-open");
    });
}

const notificationState = document.getElementById("qmsNotificationState");
const notificationIcon = document.getElementById("qmsNotificationIcon");
const topbarActions = document.querySelector(".topbar-actions");
const languageButton = document.getElementById("languageToggle");

if (notificationState && topbarActions && languageButton) {
    const unreadCount = Number.parseInt(notificationState.dataset.count || "0", 10);
    const notificationBell = document.createElement("a");

    notificationBell.className = "notification-bell " + (unreadCount > 0 ? "has-notifications" : "is-empty");
    notificationBell.href = "notifications.php";
    notificationBell.setAttribute("aria-label", unreadCount > 0 ? unreadCount + " okunmamış bildirim" : "Bildirim yok");

    // The icon path is rendered server-side by appIcon() so it stays in one place.
    notificationBell.innerHTML = (notificationIcon ? notificationIcon.innerHTML : "")
        + '<span class="notification-bell-count">' + unreadCount + "</span>";

    topbarActions.insertBefore(notificationBell, languageButton);
}

// Header kullanici menusu: TailAdmin'deki profil menusunun karsiligi.
// Sunucudan gelen durum span'inden beslenir; boylece her sayfayi elle
// degistirmek gerekmez.
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

    // Enjekte edilen ogeler icin ceviriyi yeniden uygula.
    if (typeof changeLanguage === "function") {
        changeLanguage(currentLanguage);
    }
}
