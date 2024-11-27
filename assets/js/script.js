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
                    if (response.data.tracking_results && 
                        response.data.tracking_results[0] && 
                        response.data.tracking_results[0].status === 'orders_found') {
                        
                        renderOrderList({
                            status: 'orders_found',
                            data: response.data.tracking_results[0].data
                        });
                    } else if (response.data.status === 'no_orders') {
                        $('#wcte-tracking-results').html('<p>' + response.data.message + '</p>');
                    } else {
                        renderTrackingResults(response.data);
                    }
                    // Update URL with tracking input for sharing
                    const newUrl = new URL(window.location);
                    newUrl.searchParams.set('tracking_input', trackingInput);
                    window.history.pushState({}, '', newUrl);
                } else {
                    $('#wcte-tracking-results').html('<p class="error">' + response.data + '</p>');
                }
            },
            error: function() {
                $('#wcte-tracking-results').html('<p class="error">Ocorreu um erro na consulta. Por favor, tente novamente.</p>');
            }
        });
    }

    function isValidInput(input) {
        // Remove espaços em branco no início e fim
        input = input.trim();
        
        // Verifica se é um email
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (emailRegex.test(input)) {
            return true;
        }

        // Verifica se é um número de pedido (com ou sem #)
        const orderNumberRegex = /^#?\d+$/;
        if (orderNumberRegex.test(input)) {
            return true;
        }

        return false;
    }

    $('#wcte-form').on('submit', function(e) {
        e.preventDefault();
        var trackingInput = $('#tracking_input').val().trim();
        
        if (!isValidInput(trackingInput)) {
            $('#wcte-tracking-results').html('<p class="error">Por favor, insira um número de pedido válido ou um endereço de email.</p>');
            return;
        }
        
        // Remove o # se existir antes de enviar
        if (trackingInput.startsWith('#')) {
            trackingInput = trackingInput.substring(1);
        }
        
        initiateTracking(trackingInput);
    });

    // Validação em tempo real no input
    $('#tracking_input').on('input', function() {
        var input = $(this).val().trim();
        if (input && !isValidInput(input)) {
            $(this).addClass('invalid');
            $('#wcte-tracking-results').html('<p class="error">Por favor, insira um número de pedido válido ou um endereço de email.</p>');
        } else {
            $(this).removeClass('invalid');
            $('#wcte-tracking-results').empty();
        }
    });

    var trackingInputFromUrl = getUrlParameter('tracking_input');
    if (trackingInputFromUrl) {
        $('#tracking_input').val(trackingInputFromUrl);
        initiateTracking(trackingInputFromUrl);
    }

    function renderTrackingResults(data) {
        var htmlContent = '<div class="wcte-tracking-container">';

        // Order Information Section
        if (data.order_number) {
            htmlContent += '<div class="wcte-order-info">';
            htmlContent += '<h3>Pedido #' + data.order_number + '</h3>';
            
            // Order Items
            if (data.order_items && data.order_items.length > 0) {
                htmlContent += '<div class="wcte-order-items">';
                data.order_items.forEach(function(item) {
                    htmlContent += '<div class="wcte-order-item">';
                    if (item.image) {
                        htmlContent += '<img src="' + item.image + '" alt="' + item.name + '" />';
                    }
                    htmlContent += '<span class="wcte-item-name">' + item.name + '</span>';
                    htmlContent += '</div>';
                });
                htmlContent += '</div>';
            }
            htmlContent += '</div>';
        }

        // Multiple Tracking Warning
        if (data.tracking_results.length > 1) {
            htmlContent += '<div class="wcte-multiple-tracking-warning">';
            htmlContent += '<h3>Encontramos múltiplos envios para o seu pedido:</h3>';
            htmlContent += '</div>';
        }

        // Sort tracking results - active shipments first, delivered ones last
        const sortedResults = [...data.tracking_results].sort((a, b) => {
            const getPriority = (result) => {
                if (result.status === 'cainiao') return 1;
                return result.status === 'delivered' ? 2 : 0;
            };

            const priorityA = getPriority(a);
            const priorityB = getPriority(b);

            return priorityA - priorityB;
        });

        // Tracking Information Section
        sortedResults.forEach(function(result) {
            htmlContent += '<div class="wcte-tracking-info">';
            
            // Tracking number
            htmlContent += '<div class="wcte-tracking-number">';
            htmlContent += '<span>Número de rastreamento: </span>';
            htmlContent += '<strong>' + result.tracking_code + '</strong>';
            htmlContent += '</div>';

            if (result.status === 'cainiao') {
                htmlContent += '<div class="wcte-cainiao-tracking">';
                htmlContent += '<p class="wcte-tracking-message">' + result.message + '</p>';
                htmlContent += '<a href="' + result.tracking_url + '" target="_blank" class="wcte-tracking-button">Rastrear no Site da Transportadora</a>';
                htmlContent += '</div>';
            } else if (result.data && result.data.length > 0) {
                htmlContent += '<div class="wcte-timeline">';
                
                // Os primeiros 3 eventos já serão os mais recentes
                result.data.slice(0, 3).forEach(function(event, index) {
                    htmlContent += renderTimelineEvent(event, index === 0);
                });

                // Eventos ocultos também mantêm a ordem cronológica
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

        // Other Orders Section
        if (data.other_orders && data.other_orders.length > 0) {
            htmlContent += '<div class="wcte-other-orders">';
            htmlContent += '<h3>Outros Pedidos</h3>';
            htmlContent += '<div class="wcte-orders-list">';
            data.other_orders.forEach(function(order) {
                htmlContent += '<div class="wcte-other-order-item" data-order="' + order.order_number + '">';
                htmlContent += '<span class="wcte-order-number">Pedido #' + order.order_number + '</span>';
                htmlContent += '<span class="wcte-order-date">' + order.date + '</span>';
                htmlContent += '</div>';
            });
            htmlContent += '</div>';
            htmlContent += '</div>';
        }

        htmlContent += '</div>'; // .wcte-tracking-container

        $('#wcte-tracking-results').html(htmlContent);

        // Event handlers
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

        // Make other orders clickable
        $('.wcte-other-order-item').on('click', function() {
            var orderNumber = $(this).data('order');
            $('#tracking_input').val(orderNumber);
            initiateTracking(orderNumber);
            
            // Scroll to top smoothly
            $('html, body').animate({
                scrollTop: $('#wcte-form').offset().top
            }, 500);
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

    function renderOrderList(data) {
        var htmlContent = '<div class="wcte-tracking-container">';
        htmlContent += '<div class="wcte-other-orders">';
        htmlContent += '<h3>Pedidos encontrados</h3>';
        htmlContent += '<div class="wcte-orders-list">';
        
        data.data.forEach(function(order) {
            // Formata a data para mostrar apenas dd/mm/yyyy
            const orderDate = order.order_date.split(' ')[0];
            
            htmlContent += '<div class="wcte-other-order-item" data-order="' + order.order_id + '">';
            htmlContent += '<span class="wcte-order-number">Pedido #' + order.order_id + '</span>';
            htmlContent += '<span class="wcte-order-date">' + orderDate + '</span>';
            htmlContent += '</div>';
        });
        
        htmlContent += '</div>'; // .wcte-orders-list
        htmlContent += '</div>'; // .wcte-other-orders
        htmlContent += '</div>'; // .wcte-tracking-container

        $('#wcte-tracking-results').html(htmlContent);

        // Faz os pedidos serem clicáveis
        $('.wcte-other-order-item').on('click', function() {
            var orderNumber = $(this).data('order');
            $('#tracking_input').val(orderNumber);
            initiateTracking(orderNumber);
            
            // Rolagem suave para o topo
            $('html, body').animate({
                scrollTop: $('#wcte-form').offset().top
            }, 500);
        });
    }
});
