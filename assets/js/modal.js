(function () {
    function openModal(modal) {
        modal.hidden = false;
        document.body.classList.add("modal-open");
        var focusable = modal.querySelector("input:not([type=hidden]), select, textarea, button");
        if (focusable) {
            focusable.focus();
        }
    }

    function closeModal(modal) {
        modal.hidden = true;
        document.body.classList.remove("modal-open");
    }

    document.addEventListener("click", function (event) {
        var opener = event.target.closest("[data-modal-open]");
        if (opener) {
            event.preventDefault();
            var target = document.getElementById(opener.getAttribute("data-modal-open"));
            if (target) {
                openModal(target);
            }
            return;
        }

        var closer = event.target.closest("[data-modal-close]");
        if (closer) {
            event.preventDefault();
            var owner = closer.closest(".modal-overlay");
            if (owner) {
                closeModal(owner);
            }
            return;
        }

        if (event.target.classList.contains("modal-overlay")) {
            closeModal(event.target);
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key !== "Escape") {
            return;
        }
        var open = document.querySelector(".modal-overlay:not([hidden])");
        if (open) {
            closeModal(open);
        }
    });

    // Baglantidan gelindiginde ilgili modal'i ac: profile.php#editPassword gibi.
    function openFromHash() {
        if (!window.location.hash) {
            return;
        }
        var modal = document.getElementById(window.location.hash.slice(1));
        if (modal && modal.classList.contains("modal-overlay")) {
            openModal(modal);
        }
    }

    window.addEventListener("load", openFromHash);
    window.addEventListener("hashchange", openFromHash);
})();
