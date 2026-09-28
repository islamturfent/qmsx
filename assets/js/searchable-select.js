/**
 * Arama yapilabilir select bileşeni.
 *
 * Seçenek sayisi belirli bir esigin uzerinde olan <select> elemanlarini, acilinca
 * icinde "Ara..." kutusu olan ozel bir listeye donusturur. Native <select> sayfada
 * (gizli) kalir ve secimi onun value/change'ine yazar; boylece form gonderimi ve
 * mevcut is mantigi bozulmaz. Kucuk listeler (< esik) native kalir.
 */
(function () {
    var THRESHOLD = 3; // 4+ secenekli listeler arama yapilabilir olur
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('select').forEach(function (select) {
            if (select.multiple) { return; }
            if (select.disabled) { return; }
            if (select.dataset.sbEnabled) { return; }
            if (select.options.length <= THRESHOLD) { return; }
            buildSearchable(select);
        });
    });

    function buildSearchable(select) {
        select.dataset.sbEnabled = '1';

        var wrapper = document.createElement('div');
        wrapper.className = 'sb-searchable';

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'sb-trigger';
        trigger.innerHTML = '<span class="sb-value"></span><span class="sb-chevron">▾</span>';

        var list = document.createElement('div');
        list.className = 'sb-list';
        list.hidden = true;

        var search = document.createElement('input');
        search.type = 'text';
        search.className = 'sb-search';
        search.placeholder = 'Ara...';
        search.autocomplete = 'off';

        var ul = document.createElement('ul');
        ul.className = 'sb-options';

        function refreshValue() {
            var opt = select.options[select.selectedIndex];
            trigger.querySelector('.sb-value').textContent = opt ? opt.textContent : '';
        }
        function renderOptions() {
            ul.innerHTML = '';
            Array.prototype.forEach.call(select.options, function (opt) {
                var li = document.createElement('li');
                li.className = 'sb-option';
                li.textContent = opt.textContent;
                li.dataset.value = opt.value;
                if (opt.selected) { li.classList.add('is-selected'); }
                li.addEventListener('click', function () {
                    select.value = opt.value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    refreshValue();
                    closeList();
                });
                ul.appendChild(li);
            });
        }
        function closeList() {
            list.hidden = true;
            search.value = '';
            filterSearch('');
            ul.classList.remove('is-open');
        }
        function openList() {
            renderOptions();
            list.hidden = false;
            ul.classList.add('is-open');
            search.focus();
        }
        function filterSearch(q) {
            q = (q || '').trim().toLowerCase();
            Array.prototype.forEach.call(ul.children, function (li) {
                li.style.display = (q === '' || li.textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
            });
        }

        search.addEventListener('input', function () { filterSearch(this.value); });
        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            if (list.hidden) { openList(); } else { closeList(); }
        });

        list.appendChild(search);
        list.appendChild(ul);
        wrapper.appendChild(trigger);
        wrapper.appendChild(list);

        // Native select'i gorunmez yap ama formda birak.
        select.style.display = 'none';
        select.parentNode.insertBefore(wrapper, select.nextSibling || null);

        document.addEventListener('click', function (e) {
            if (!wrapper.contains(e.target)) { closeList(); }
        });
    }
})();
