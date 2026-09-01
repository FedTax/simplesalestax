/* global jQuery, ticSelectLocalizeScript */
(function($, data) {
    $(function() {
        var $row_template = wp.template( 'sst-tic-row' ),
            SelectView    = Backbone.View.extend( {
                rowTemplate: $row_template,
                input: null,
                readout: null,
                initialize: function() {
                    this.input             = this.$el.siblings( '.sst-tic-input' );
                    this.readout           = this.$el.siblings( '.sst-selected-tic' );
                    
                    // Bind all methods to the view instance context
                    this.handleInputChange = this.handleInputChange.bind( this );
                    this.openModal         = this.openModal.bind( this );
                    this.bindEvents        = this.bindEvents.bind( this );
                    this.unbindEvents      = this.unbindEvents.bind( this );
                    this.handleSearchInput = this.handleSearchInput.bind( this );
                    this.loadMoreResults   = this.loadMoreResults.bind( this );
                    this.updateSelection   = this.updateSelection.bind( this );
                    this.completeSelection = this.completeSelection.bind( this );
                    this.handleModalClose  = this.handleModalClose.bind( this );

                    this.$el.on( 'click', this.openModal );
                    this.input.on( 'change', this.handleInputChange );
                },
                render: function() {
                    this.selectTIC( this.input.val() );
                },
                bindEvents: function() {
                    $( document.body ).on( 'click', '.sst-select-done', this.updateSelection );
                    $( document.body ).on( 'wc_backbone_modal_response', this.completeSelection );
                    $( document.body ).on( 'click', '.sst-tic-load-more', this.loadMoreResults );
                    $( document.body ).on( 'keyup input', '.sst-tic-search', this.handleSearchInput );
                    $( document.body ).on( 'wc_backbone_modal_removed', this.handleModalClose );
                },
                unbindEvents: function() {
                    $( document.body ).off( 'click', '.sst-select-done', this.updateSelection );
                    $( document.body ).off( 'wc_backbone_modal_response', this.completeSelection );
                    $( document.body ).off( 'click', '.sst-tic-load-more', this.loadMoreResults );
                    $( document.body ).off( 'keyup input', '.sst-tic-search', this.handleSearchInput );
                    $( document.body ).off( 'wc_backbone_modal_removed', this.handleModalClose );
                },
                openModal: function( event ) {
                    event.preventDefault();
                    
                    this.$el.SSTBackboneModal( {
                        'template': 'sst-tic-select-modal',
                    } );

                    this.bindEvents();
                    this.initModal();
                },
                renderInitialMessage: function() {
                    var html = '<tr><td colspan="2" style="padding: 30px 15px; border: none;">' +
                        '<div class="sst-tic-info-card" style="' +
                            'background: #f8fafc;' +
                            'border: 1px solid #e2e8f0;' +
                            'border-radius: 8px;' +
                            'padding: 24px;' +
                            'text-align: center;' +
                            'box-shadow: 0 1px 3px rgba(0,0,0,0.05);' +
                            'max-width: 500px;' +
                            'margin: 20px auto;' +
                        '">' +
                            '<div class="sst-tic-info-icon" style="' +
                                'font-size: 36px;' +
                                'margin-bottom: 12px;' +
                                'color: #3182ce;' +
                            '">🔍</div>' +
                            '<h3 style="' +
                                'margin: 0 0 10px 0;' +
                                'font-size: 16px;' +
                                'font-weight: 600;' +
                                'color: #2d3748;' +
                            '">' + _.escape( data.strings.search_title ) + '</h3>' +
                            '<p style="' +
                                'margin: 0 0 16px 0;' +
                                'font-size: 13px;' +
                                'color: #4a5568;' +
                                'line-height: 1.5;' +
                            '">' + _.escape( data.strings.search_desc ) + '</p>' +
                            '<div class="sst-tic-tips" style="' +
                                'border-top: 1px dashed #e2e8f0;' +
                                'padding-top: 14px;' +
                                'font-size: 12px;' +
                                'color: #718096;' +
                                'line-height: 1.5;' +
                            '">' +
                                data.strings.search_tips +
                            '</div>' +
                        '</div>' +
                    '</td></tr>';
                    $( '.sst-tic-list' ).html( html );
                },
                initModal: function( event ) {
                    this.currentQuery = '';
                    this.nextCursor   = '';
                    this.isLoading    = false;
                    this.xhr          = null;
                    this.timer        = null;

                    var tic_id = this.input.val();
                    if ( tic_id ) {
                        var tic = data.tic_list ? data.tic_list[ parseInt( tic_id ) ] : null;
                        var initial_query = '';
                        if ( tic && tic['description'] ) {
                            initial_query = tic['description'] + ' (' + tic_id + ')';
                        } else {
                            initial_query = tic_id;
                        }
                        
                        $( '.sst-tic-search' ).val( initial_query );
                        this.currentQuery = initial_query;
                        this.performSearch( initial_query, false );
                    } else {
                        this.renderInitialMessage();
                    }
                },
                handleSearchInput: function( event ) {
                    var $target = $( event.target ),
                        query = $target.val().trim();

                    if ( query === this.currentQuery ) {
                        return;
                    }

                    if ( this.timer ) {
                        clearTimeout( this.timer );
                    }
                    if ( this.xhr ) {
                        this.xhr.abort();
                    }

                    if ( '' === query ) {
                        this.currentQuery = '';
                        this.nextCursor   = '';
                        this.renderInitialMessage();
                        return;
                    }

                    this.currentQuery = query;
                    this.nextCursor   = '';

                    var view = this;
                    this.timer = setTimeout( function() {
                        view.performSearch( query, false );
                    }, 300 );
                },
                performSearch: function( query, append ) {
                    var view  = this,
                        $list = $( '.sst-tic-list' );

                    if ( view.isLoading ) {
                        return;
                    }

                    if ( ! append ) {
                        $list.html( '<tr><td colspan="2" style="text-align: center; padding: 20px;"><span class="spinner is-active" style="float: none; margin: 0 auto; display: inline-block;"></span> Searching...</td></tr>' );
                    } else {
                        $( '.sst-tic-load-more-row' ).html( '<td colspan="2" style="text-align: center; padding: 15px;"><span class="spinner is-active" style="float: none; margin: 0 auto; display: inline-block;"></span> Loading more...</td>' );
                    }

                    view.isLoading = true;

                    view.xhr = $.ajax( {
                        url: data.ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'sst_search_tics',
                            nonce: data.search_tics_nonce,
                            query: query,
                            cursor: view.nextCursor
                        },
                        success: function( response ) {
                            view.isLoading = false;
                            $( '.sst-tic-load-more-row' ).remove();

                            if ( response.success ) {
                                if ( ! append ) {
                                    $list.empty();
                                }

                                var results = response.data.results || [];
                                view.nextCursor = response.data.next_cursor || '';

                                if ( results.length === 0 ) {
                                    if ( ! append ) {
                                        $list.append( '<tr><td colspan="2" style="text-align: center; padding: 20px;">No TICs found matching the query.</td></tr>' );
                                    }
                                } else {
                                    _.each( results, function( rowData ) {
                                        $list.append( view.rowTemplate( rowData ) );
                                    } );
                                }

                                if ( view.nextCursor ) {
                                    $list.append( '<tr class="sst-tic-load-more-row"><td colspan="2" style="text-align: center; padding: 15px;"><button type="button" class="button sst-tic-load-more">Load More</button></td></tr>' );
                                }
                            } else {
                                if ( ! append ) {
                                    $list.html( '<tr><td colspan="2" style="text-align: center; padding: 20px; color: #dc3232;">' + _.escape( response.data ) + '</td></tr>' );
                                } else {
                                    alert( _.escape( response.data ) );
                                }
                            }
                        },
                        error: function( jqXHR, textStatus, errorThrown ) {
                            view.isLoading = false;
                            if ( textStatus !== 'abort' ) {
                                $( '.sst-tic-load-more-row' ).remove();
                                if ( ! append ) {
                                    $list.html( '<tr><td colspan="2" style="text-align: center; padding: 20px; color: #dc3232;">Error searching TICs. Please try again.</td></tr>' );
                                } else {
                                    alert( 'Error loading more TICs. Please try again.' );
                                }
                            }
                        }
                    } );
                },
                loadMoreResults: function( event ) {
                    event.preventDefault();
                    this.performSearch( this.currentQuery, true );
                },
                updateSelection: function( event ) {
                    event.preventDefault();
                    var $target = $( event.target ),
                        $tr     = $target.closest( 'tr' );

                    var id          = $tr.data( 'id' );
                    var description = $tr.data( 'description' );

                    if ( id && description ) {
                        var parsed_id = parseInt( id );
                        if ( !data.tic_list[ parsed_id ] || !data.tic_list[ parsed_id ].description ) {
                            data.tic_list[ parsed_id ] = {
                                id: id,
                                description: description
                            };
                        }
                    }

                    $( 'input[name="tic"]' ).val( id );
                    $( '#btn-ok' ).trigger( 'click' );
                },
                completeSelection: function( event, target, posted ) {
                    if ( 'sst-tic-select-modal' === target ) {
                        this.selectTIC( posted['tic'] );
                        this.unbindEvents();
                    }
                },
                handleModalClose: function( event, target ) {
                    if ( 'sst-tic-select-modal' === target ) {
                        this.unbindEvents();
                    }
                },
                selectTIC: function( tic_id ) {
                    this.input.val( tic_id ).trigger( 'change' );
                },
                handleInputChange: function() {
                    var tic_id = this.input.val();

                    if ( '' == tic_id || undefined === tic_id ) {
                        this.readout.text( this.readout.data( 'default' ) );
                    } else {
                        var parsed_id = parseInt( tic_id );
                        data.tic_list = data.tic_list || {};
                        var tic = data.tic_list[ parsed_id ];
                        if ( tic ) {
                            if ( tic.description ) {
                                this.readout.text( tic['description'] + ' (' + tic['id'] + ')' );
                            } else {
                                this.readout.text( this.readout.data( 'default' ) || ( 'TIC ' + tic_id ) );
                            }
                        } else {
                            this.readout.text( this.readout.data( 'default' ) || ( 'TIC ' + tic_id ) );

                            // Mark as loading to prevent duplicate background requests
                            data.tic_list[ parsed_id ] = {
                                id: tic_id,
                                description: '',
                                loading: true
                            };

                            var view = this;
                            $.ajax( {
                                url: data.ajaxurl,
                                type: 'POST',
                                dataType: 'json',
                                data: {
                                    action: 'sst_search_tics',
                                    nonce: data.search_tics_nonce,
                                    query: tic_id
                                },
                                success: function( response ) {
                                    if ( response.success && response.data.results && response.data.results.length > 0 ) {
                                        var match = _.find( response.data.results, function( r ) {
                                            return parseInt( r.id ) === parsed_id;
                                        } );
                                        if ( match ) {
                                            data.tic_list[ parsed_id ] = {
                                                id: match.id,
                                                description: match.description
                                            };
                                            
                                            // Update all readout fields with this matching TIC ID
                                            $( '.sst-tic-input' ).each( function() {
                                                var $input = $( this );
                                                if ( parseInt( $input.val() ) === parsed_id ) {
                                                    $input.trigger( 'change' );
                                                }
                                            } );
                                        }
                                    }
                                }
                            } );
                        }
                    }
                },
                remove: function() {
                    Backbone.View.prototype.remove.call(this);
                    this.input.off( 'change' );
                },
            } );

        function initialize() {
            $( '.sst-select-tic:not(.initialized)' ).each( function() {
                var selectView = new SelectView( {
                    el: $( this ),
                } );

                selectView.render();

                $( this ).addClass( 'initialized' );
            } );
        }

        initialize();

        $( document.body ).on( data.tic_select_init_events, function() {
            initialize();
        } );
    });
})(jQuery, ticSelectLocalizeScript);