/**
 * Metin olarak basilmiş ISO tarihlerini (YYYY-MM-DD) Türkçe "gün ay yıl"
 * biçimine çevirir. Yalnizca gorunen metin (text node) duzeyinde calisir;
 * <input type="date"> degerine ve form gizli alanlarina (attribute) dokunmaz,
 * boylece is mantigi / backend ISO okumaya devam eder.
 */
(function () {
    var TR_MONTHS = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
        'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    function formatISO(ymd) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd);
        if (!m) { return ymd; }
        var mi = (+m[2]) - 1;
        if (mi < 0 || mi > 11) { return ymd; }
        return (+m[3]) + ' ' + TR_MONTHS[mi] + ' ' + m[1];
    }

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.body;
        if (!root) { return; }
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
        var nodes = [];
        var node;
        while ((node = walker.nextNode())) { nodes.push(node); }

        nodes.forEach(function (textNode) {
            if (!textNode.nodeValue) { return; }
            var p = textNode.parentNode;
            if (p && p.closest && p.closest('script, style, textarea, input')) { return; }
            var v = textNode.nodeValue;
            if (/^\d{4}-\d{2}-\d{2}$/.test(v)) {
                textNode.nodeValue = formatISO(v);
            } else if (/\b\d{4}-\d{2}-\d{2}\b/.test(v)) {
                textNode.nodeValue = v.replace(/\b\d{4}-\d{2}-\d{2}\b/g, formatISO);
            }
        });
    });
})();
