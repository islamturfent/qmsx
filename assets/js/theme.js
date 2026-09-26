const themeToggle = document.getElementById("themeToggle");
const themeColorMeta = document.querySelector('meta[name="theme-color"]');

const sunIconHtml = (document.getElementById("qmsIconSun") || {}).innerHTML || "☀️";
const moonIconHtml = (document.getElementById("qmsIconMoon") || {}).innerHTML || "🌙";

// Tema modu: light / dark / system. Varsayilan light (degistirilmemis oturumlar icin). 
let themeMode = localStorage.getItem("qms-theme");
if (["light", "dark", "system"].indexOf(themeMode) < 0) {
    themeMode = "light";
}
const darkQuery = window.matchMedia ? window.matchMedia("(prefers-color-scheme: dark)") : null;

function currentDarkMode() {
    if (themeMode === "dark") return true;
    if (themeMode === "system" && darkQuery && darkQuery.matches) return true;
    return false;
}

function updateThemeColor(darkMode) {
    if (themeColorMeta) {
        themeColorMeta.setAttribute("content", darkMode ? "#101828" : "#f9fafb");
    }
}

// Tema butonunu TailAdmin tarzi gunes/ay ikonu olarak cizer.
function renderThemeButton() {
    if (!themeToggle) return;
    const dark = currentDarkMode();
    themeToggle.innerHTML = '<span class="topbar-btn-icon">' + (dark ? sunIconHtml : moonIconHtml) + "</span>";
}

function applyThemeClass() {
    document.body.classList.toggle("dark-mode", currentDarkMode());
    renderThemeButton();
    updateThemeColor(currentDarkMode());
}

// Dropdown'dan cagrilir: light / dark / system.
function qmsSetTheme(mode) {
    if (["light", "dark", "system"].indexOf(mode) < 0) mode = "light";
    themeMode = mode;
    try { localStorage.setItem("qms-theme", mode); } catch (e) {}
    applyThemeClass();
}
window.qmsSetTheme = qmsSetTheme;

if (darkQuery) {
    darkQuery.addEventListener("change", function () {
        if (themeMode === "system") applyThemeClass();
    });
}

applyThemeClass();
