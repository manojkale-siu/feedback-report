(function (window) {
    'use strict';

    window.initReportSearch = function (options) {
        const table = document.getElementById(options.tableId);
        const input = document.getElementById(options.inputId);
        const tools = document.getElementById(options.toolsId);
        const pageSizeSelect = document.getElementById(options.pageSizeId);
        const previousButton = document.getElementById(options.previousButtonId);
        const nextButton = document.getElementById(options.nextButtonId);
        const pageInfo = document.getElementById(options.pageInfoId);

        if (!table || !table.tBodies.length || !input || !pageSizeSelect || !previousButton || !nextButton || !pageInfo) {
            if (tools) {
                tools.hidden = true;
            }
            return;
        }

        const rows = Array.from(table.tBodies[0].rows);
        const headerText = table.tHead ? table.tHead.textContent.toLowerCase() : '';
        let currentPage = 1;
        let filteredRows = rows;

        function renderPage() {
            const pageSize = Number(pageSizeSelect.value);
            const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
            currentPage = Math.min(currentPage, totalPages);
            const firstRow = (currentPage - 1) * pageSize;
            const visibleRows = new Set(filteredRows.slice(firstRow, firstRow + pageSize));

            rows.forEach(function (row) {
                row.hidden = !visibleRows.has(row);
            });

            pageInfo.textContent = filteredRows.length === 0
                ? 'No matching records'
                : 'Page ' + currentPage + ' of ' + totalPages + ' (' + filteredRows.length + ' records)';
            previousButton.disabled = currentPage === 1 || filteredRows.length === 0;
            nextButton.disabled = currentPage === totalPages || filteredRows.length === 0;
        }

        function applySearch() {
            const searchTerm = input.value.trim().toLowerCase();
            const matchesHeader = searchTerm !== '' && headerText.includes(searchTerm);

            filteredRows = rows.filter(function (row) {
                return searchTerm === '' || matchesHeader || row.textContent.toLowerCase().includes(searchTerm);
            });
            currentPage = 1;
            renderPage();
        }

        input.addEventListener('input', function () {
            applySearch();
        });

        pageSizeSelect.addEventListener('change', function () {
            currentPage = 1;
            renderPage();
        });

        previousButton.addEventListener('click', function () {
            if (currentPage > 1) {
                currentPage--;
                renderPage();
            }
        });

        nextButton.addEventListener('click', function () {
            const pageSize = Number(pageSizeSelect.value);
            if (currentPage * pageSize < filteredRows.length) {
                currentPage++;
                renderPage();
            }
        });

        applySearch();
    };
}(window));
