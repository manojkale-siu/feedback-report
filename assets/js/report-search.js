(function (window) {
    'use strict';

    window.initReportSearch = function (options) {
        const table = document.getElementById(options.tableId);
        const input = document.getElementById(options.inputId);
        const tools = document.getElementById(options.toolsId);

        if (!table || !table.tBodies.length || !input) {
            if (tools) {
                tools.hidden = true;
            }
            return;
        }

        const rows = Array.from(table.tBodies[0].rows);
        const headerText = table.tHead ? table.tHead.textContent.toLowerCase() : '';

        input.addEventListener('input', function () {
            const searchTerm = this.value.trim().toLowerCase();
            const matchesHeader = searchTerm !== '' && headerText.includes(searchTerm);

            rows.forEach(function (row) {
                const matchesRow = row.textContent.toLowerCase().includes(searchTerm);
                row.hidden = searchTerm !== '' && !matchesHeader && !matchesRow;
            });
        });
    };
}(window));
