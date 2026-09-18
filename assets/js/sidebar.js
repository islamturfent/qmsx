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
