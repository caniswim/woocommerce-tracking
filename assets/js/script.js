jQuery(document).ready(function($) {
    // Função para obter o valor de um parâmetro da URL
    function getUrlParameter(name) {
        name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
        var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
        var results = regex.exec(location.search);
        return results === null ? null : decodeURIComponent(results[1].replace(/\+/g, ' '));
    }

    // Função para iniciar a consulta de rastreamento
    function initiateTracking(trackingInput) {
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
                    renderTrackingResults(response.data);
                } else {
                    $('#wcte-tracking-results').html('<p class="error">' + response.data + '</p>');
                }
            },
            error: function() {
                $('#wcte-tracking-results').html('<p class="error">Ocorreu um erro na consulta. Por favor, tente novamente.</p>');
            }
        });
    }

    // Evento de submissão do formulário
    $('#wcte-form').on('submit', function(e) {
        e.preventDefault();
        var trackingInput = $('#tracking_input').val();
        initiateTracking(trackingInput);
    });

    // Verifica se o parâmetro 'tracking_input' está presente na URL
    var trackingInputFromUrl = getUrlParameter('tracking_input');
    if (trackingInputFromUrl) {
        $('#tracking_input').val(trackingInputFromUrl);
        initiateTracking(trackingInputFromUrl);
    }

    // Função para renderizar os resultados de rastreamento
    function renderTrackingResults(data) {
        var htmlContent = '<div class="tracking-info-container">';

        // Exibe informações do pedido
        if (data.order_number) {
            htmlContent += '<div class="order-info">';
            htmlContent += '<h3>Pedido Número: ' + data.order_number + '</h3>';
            htmlContent += '<div class="order-items">';
            data.order_items.forEach(function(item) {
                htmlContent += '<div class="order-item">';
                htmlContent += '<img src="' + item.image + '" alt="' + item.name + '" />';
                htmlContent += '<p>' + item.name + '</p>';
                htmlContent += '</div>';
            });
            htmlContent += '</div>';
            htmlContent += '</div>';
        }

        // Exibe resultados de rastreamento
        htmlContent += '<div class="tracking-info-container">';
        if (data.tracking_results.length > 1) {
            htmlContent += '<h3>Encontramos múltiplos envios para o seu pedido:</h3>';
        }

        data.tracking_results.forEach(function(result) {
            htmlContent += '<div class="tracking-info">';
            htmlContent += '<p class="tracking-number">Código de Rastreamento: <strong>' + result.tracking_code + '</strong></p>';

            if (result.status === 'cainiao') {
                htmlContent += '<p class="tracking-message">' + result.message + '</p>';
                htmlContent += '<a href="' + result.tracking_url + '" target="_blank" class="tracking-button">Rastrear no Site da Transportadora</a>';
            } else {
                htmlContent += '<p class="tracking-status">Status: <strong>' + formatStatus(result.status) + '</strong></p>';
                htmlContent += '<p class="tracking-message">' + result.message + '</p>';

                if (result.data && result.data.length > 0) {
                    htmlContent += '<div class="tracking-history-container">';
                    htmlContent += '<button class="toggle-history">Ver Histórico de Rastreamento</button>';
                    htmlContent += '<div class="tracking-history" style="display: none;">';
                    htmlContent += '<ul>';
                    result.data.forEach(function(event) {
                        htmlContent += '<li>';
                        htmlContent += '<span class="event-date">' + event.date + '</span> - ';
                        htmlContent += '<span class="event-description">' + event.description + '</span>';
                        if (event.location) {
                            htmlContent += ' <span class="event-location">(' + event.location + ')</span>';
                        }
                        htmlContent += '</li>';
                    });
                    htmlContent += '</ul>';
                    htmlContent += '</div>'; // .tracking-history
                    htmlContent += '</div>'; // .tracking-history-container
                }
            }

            htmlContent += '</div>'; // .tracking-info
        });

        htmlContent += '</div>'; // .tracking-info-container

        // Exibe outros pedidos do cliente, se disponíveis
        if (data.other_orders && data.other_orders.length > 0) {
            htmlContent += '<div class="other-orders">';
            htmlContent += '<h3>Outros Pedidos:</h3>';
            htmlContent += '<ul>';
            data.other_orders.forEach(function(order) {
                htmlContent += '<li>';
                htmlContent += 'Pedido #' + order.order_number + ' - ' + order.date;
                htmlContent += '</li>';
            });
            htmlContent += '</ul>';
            htmlContent += '</div>';
        }

        $('#wcte-tracking-results').html(htmlContent);

        // Evento para expandir/ocultar histórico
        $('.toggle-history').on('click', function() {
            $(this).next('.tracking-history').slideToggle();
            $(this).text($(this).text() === 'Ver Histórico de Rastreamento' ? 'Ocultar Histórico de Rastreamento' : 'Ver Histórico de Rastreamento');
        });
    }

    // Função para formatar o status de rastreamento
    function formatStatus(status) {
        switch (status) {
            case 'in_transit':
                return 'Em Trânsito';
            case 'delivered':
                return 'Entregue';
            case 'no_data':
                return 'Objeto Não Encontrado';
            case 'error':
                return 'Erro na Consulta';
            default:
                return status.charAt(0).toUpperCase() + status.slice(1).replace('_', ' ');
        }
    }
});
