jQuery(document).ready(function($) {
    // Handle log viewer auto-refresh
    function refreshLogs() {
        $.post(wcte_admin.ajax_url, {
            action: 'wcte_get_logs',
            nonce: wcte_admin.nonce
        }, function(response) {
            if (response.success) {
                $('#wcte-logs').val(response.data);
            }
        });
    }

    // Clear logs button handler
    $('#wcte-clear-logs').on('click', function() {
        if (confirm('Tem certeza que deseja limpar os logs?')) {
            $.post(wcte_admin.ajax_url, {
                action: 'wcte_clear_logs',
                nonce: wcte_admin.nonce
            }, function(response) {
                if (response.success) {
                    $('#wcte-logs').val('');
                }
            });
        }
    });

    // Manual refresh button handler
    $('#wcte-refresh-logs').on('click', function() {
        refreshLogs();
    });

    // Auto refresh logs every 30 seconds
    setInterval(refreshLogs, 30000);

    // Scroll log viewer to bottom when new content is added
    function scrollLogsToBottom() {
        var textarea = document.getElementById('wcte-logs');
        textarea.scrollTop = textarea.scrollHeight;
    }

    // Watch for changes in the textarea content
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'characterData' || mutation.type === 'childList') {
                scrollLogsToBottom();
            }
        });
    });

    var logsTextarea = document.getElementById('wcte-logs');
    if (logsTextarea) {
        observer.observe(logsTextarea, {
            characterData: true,
            childList: true,
            subtree: true
        });
    }
});
