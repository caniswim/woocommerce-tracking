jQuery(document).ready(function($) {
    function getUrlParameter(name) {
        name = name.replace(/[\[]/, '\\[').replace(/[\]]/, '\\]');
        var regex = new RegExp('[\\?&]' + name + '=([^&#]*)');
        var results = regex.exec(location.search);
        return results === null ? null : decodeURIComponent(results[1].replace(/\+/g, ' '));
    }

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

    $('#wcte-form').on('submit', function(e) {
        e.preventDefault();
        var trackingInput = $('#tracking_input').val();
        initiateTracking(trackingInput);
    });

    var trackingInputFromUrl = getUrlParameter('tracking_input');
    if (trackingInputFromUrl) {
        $('#tracking_input').val(trackingInputFromUrl);
        initiateTracking(trackingInputFromUrl);
    }

    function renderTrackingResults(data) {
        var htmlContent = '<div class="wcte-tracking-container">';

        data.tracking_results.forEach(function(result) {
            htmlContent += '<div class="wcte-tracking-info">';
            
            // Carrier info and tracking number
            htmlContent += '<div class="wcte-carrier-info">';
            htmlContent += '<h3>' + (result.carrier || 'Rastreamento') + '</h3>';
            htmlContent += '<div class="wcte-tracking-number">';
            htmlContent += '<span>Número de rastreamento: </span>';
            htmlContent += '<strong>' + result.tracking_code + '</strong>';
            htmlContent += '<button class="wcte-copy-button" data-tracking="' + result.tracking_code + '">Copiar</button>';
            htmlContent += '</div>';
            htmlContent += '</div>';

            if (result.data && result.data.length > 0) {
                htmlContent += '<div class="wcte-timeline">';
                
                // Show first 3 events initially
                result.data.slice(0, 3).forEach(function(event, index) {
                    htmlContent += renderTimelineEvent(event, index === 0);
                });

                // Add hidden events
                if (result.data.length > 3) {
                    htmlContent += '<div class="wcte-hidden-events" style="display: none;">';
                    result.data.slice(3).forEach(function(event) {
                        htmlContent += renderTimelineEvent(event, false);
                    });
                    htmlContent += '</div>';
                    
                    htmlContent += '<button class="wcte-show-more-button">Ver mais</button>';
                }

                htmlContent += '</div>'; // .wcte-timeline
            }

            htmlContent += '</div>'; // .wcte-tracking-info
        });

        htmlContent += '</div>'; // .wcte-tracking-container

        $('#wcte-tracking-results').html(htmlContent);

        // Event handlers
        $('.wcte-copy-button').on('click', function() {
            var trackingNumber = $(this).data('tracking');
            navigator.clipboard.writeText(trackingNumber).then(function() {
                alert('Código copiado!');
            });
        });

        $('.wcte-show-more-button').on('click', function() {
            var $button = $(this);
            var $hiddenEvents = $button.siblings('.wcte-hidden-events');
            
            if ($hiddenEvents.is(':visible')) {
                $hiddenEvents.slideUp();
                $button.text('Ver mais');
            } else {
                $hiddenEvents.slideDown();
                $button.text('Ver menos');
            }
        });
    }

    function renderTimelineEvent(event, isActive) {
        var html = '<div class="wcte-timeline-event">';
        html += '<div class="wcte-timeline-dot' + (isActive ? ' active' : '') + '"></div>';
        html += '<div class="wcte-timeline-content">';
        html += '<div class="wcte-event-title">' + event.description + '</div>';
        html += '<div class="wcte-event-details">';
        if (event.location) {
            html += '<div class="wcte-event-location">' + event.location + '</div>';
        }
        html += '<div class="wcte-event-date">' + event.date + '</div>';
        html += '</div>'; // .wcte-event-details
        html += '</div>'; // .wcte-timeline-content
        html += '</div>'; // .wcte-timeline-event
        return html;
    }

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
