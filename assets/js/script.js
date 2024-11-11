jQuery(document).ready(function($) {
    $('#wcte-form').on('submit', function(e) {
        e.preventDefault();

        var trackingInput = $('#tracking_input').val();

        $.ajax({
            url: wcte_ajax_object.ajax_url,
            method: 'POST',
            data: {
                action: 'wcte_track_order',
                tracking_input: trackingInput,
            },
            beforeSend: function() {
                $('#wcte-tracking-results').html('<p>Aguarde, estamos consultando o status do seu pedido...</p>');
            },
            success: function(response) {
                if (response.success) {
                    renderTrackingResults(response.data.tracking_results);
                } else {
                    $('#wcte-tracking-results').html('<p class="error">' + response.data + '</p>');
                }
            },
            error: function() {
                $('#wcte-tracking-results').html('<p class="error">Ocorreu um erro na consulta. Por favor, tente novamente.</p>');
            }
        });
    });

    function renderTrackingResults(results) {
        var htmlContent = '<div class="tracking-info-container">';

        if (results.length > 1) {
            htmlContent += '<h3>Encontramos múltiplos envios para o seu pedido:</h3>';
        }

        results.forEach(function(result, index) {
            htmlContent += '<div class="tracking-info">';
            htmlContent += '<p class="tracking-number">Código de Rastreamento: <strong>' + result.tracking_code + '</strong></p>';

            if (result.status === 'cainiao') {
                // Exibe o iframe com o rastreamento da Cainiao
                htmlContent += '<iframe src="' + result.iframe_url + '" width="100%" height="600"></iframe>';
            } else if (result.status === 'fictitious') {
                // Exibe a mensagem fictícia
                htmlContent += '<p class="tracking-status">Status: <strong>' + formatStatus(result.status) + '</strong></p>';
                htmlContent += '<p class="tracking-message">' + result.message + '</p>';
            } else {
                // Exibe as informações reais dos Correios
                htmlContent += '<p class="tracking-status">Status: <strong>' + formatStatus(result.status) + '</strong></p>';
                htmlContent += '<p class="tracking-message">' + result.message + '</p>';
                // Se houver dados adicionais, como histórico de rastreamento
                if (result.data && result.data.length > 0) {
                    htmlContent += '<ul class="tracking-history">';
                    result.data.forEach(function(event) {
                        htmlContent += '<li>';
                        htmlContent += '<span class="event-date">' + event.date + '</span> - ';
                        htmlContent += '<span class="event-description">' + event.description + '</span>';
                        htmlContent += '</li>';
                    });
                    htmlContent += '</ul>';
                }
            }

            htmlContent += '</div>';
        });

        htmlContent += '</div>';
        $('#wcte-tracking-results').html(htmlContent);
    }

    function formatStatus(status) {
        switch (status) {
            case 'in_transit':
                return 'Em Trânsito';
            case 'delivered':
                return 'Entregue';
            case 'fictitious':
                return 'Atualizando Informações';
            default:
                return status.charAt(0).toUpperCase() + status.slice(1).replace('_', ' ');
        }
    }
});
