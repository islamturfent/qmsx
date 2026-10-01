const themeToggle = document.getElementById("themeToggle");
const themeColorMeta = document.querySelector('meta[name="theme-color"]');

const sunIconHtml = (document.getElementById("qmsIconSun") || {}).innerHTML || "☀️";
const moonIconHtml = (document.getElementById("qmsIconMoon") || {}).innerHTML || "🌙";

const serverDefaults = document.getElementById("qmsServerDefaults");
const serverTheme = serverDefaults ? serverDefaults.dataset.theme : "";
const savedTheme = localStorage.getItem("qms-theme") || serverTheme;

function updateThemeColor(darkMode) {
    if (themeColorMeta) {
        themeColorMeta.setAttribute("content", darkMode ? "#101828" : "#f9fafb");
    }
}

// Tema butonunu TailAdmin tarzi gunes/ay ikonu olarak cizer.
function renderThemeButton() {
    if (!themeToggle) return;
    const dark = document.body.classList.contains("dark-mode");
    themeToggle.innerHTML = '<span class="topbar-btn-icon">' + (dark ? sunIconHtml : moonIconHtml) + "</span>";
}

if (savedTheme === "dark") {
    document.body.classList.add("dark-mode");
}

renderThemeButton();
updateThemeColor(document.body.classList.contains("dark-mode"));

themeToggle.addEventListener("click", function () {
    document.body.classList.toggle("dark-mode");

    const darkMode = document.body.classList.contains("dark-mode");
    localStorage.setItem("qms-theme", darkMode ? "dark" : "light");

    renderThemeButton();
    updateThemeColor(darkMode);
});
