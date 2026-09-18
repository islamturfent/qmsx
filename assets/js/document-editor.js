const editorForm = document.getElementById("documentEditorForm");

if (editorForm && window.tinymce) {
    const darkMode = document.body.classList.contains("dark-mode");
    const language = localStorage.getItem("qms-language") || "tr";

    tinymce.init({
        selector: "#editorSource",
        license_key: "gpl",
        language: language === "tr" ? "tr" : "en",
        language_url: language === "tr" ? "assets/vendor/tinymce/langs/tr.js" : undefined,
        skin: darkMode ? "oxide-dark" : "oxide",
        content_css: darkMode ? "dark" : "default",
        menubar: false,
        branding: false,
        promotion: false,
        resize: true,
        min_height: 380,
        plugins: "lists link table",
        toolbar: "undo redo | blocks | bold italic underline | bullist numlist | link unlink | table | removeformat",
        block_formats: "Paragraf=p; Başlık 1=h1; Başlık 2=h2; Başlık 3=h3; Alıntı=blockquote",
        link_default_target: "_self",
        link_assume_external_targets: "https",
        target_list: false,
        rel_list: [],
        table_advtab: false,
        table_cell_advtab: false,
        table_row_advtab: false,
        invalid_elements: "script,style,iframe,object,embed,svg,math,form,input,button,textarea,select,img,video,audio",
        valid_elements: "p,div,br,strong/b,em/i,u,h1,h2,h3,ul,ol,li,blockquote,table,thead,tbody,tfoot,tr,th[colspan|rowspan|scope],td[colspan|rowspan],a[href|title|target|rel]",
        setup(editor) {
            editor.on("change input undo redo", () => editor.save());
        }
    });

    editorForm.addEventListener("submit", () => tinymce.triggerSave());

    const languageToggle = document.getElementById("languageToggle");
    if (languageToggle) {
        languageToggle.addEventListener("click", () => window.setTimeout(() => window.location.reload(), 0));
    }
}
