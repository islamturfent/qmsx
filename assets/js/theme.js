const themeToggle = document.getElementById("themeToggle");
const themeColorMeta = document.querySelector('meta[name="theme-color"]');

const savedTheme = localStorage.getItem("qms-theme");

function updateThemeColor(darkMode) {
    if (themeColorMeta) {
        themeColorMeta.setAttribute("content", darkMode ? "#101828" : "#f9fafb");
    }
}

if (savedTheme === "dark") {
    document.body.classList.add("dark-mode");
    themeToggle.textContent = "☀️";
}

updateThemeColor(document.body.classList.contains("dark-mode"));

themeToggle.addEventListener("click", function () {
    document.body.classList.toggle("dark-mode");

    const darkMode = document.body.classList.contains("dark-mode");

    if (darkMode) {
        themeToggle.textContent = "☀️";
        localStorage.setItem("qms-theme", "dark");
    } else {
        themeToggle.textContent = "🌙";
        localStorage.setItem("qms-theme", "light");
    }

    updateThemeColor(darkMode);
});
