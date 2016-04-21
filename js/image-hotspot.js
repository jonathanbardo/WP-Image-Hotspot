/* global imagehotspot, jQuery */
(function( $ ){

    'use strict';

    var imageEdit        = $.extend({}, window.imageEdit);
    var pointerDismissed = false;

    var hotspot = window.hotspot = {
        open: function( attach_id ) {
            var t = window.hotspot;

            // Remove button listener that might have been set before
            $( document ).off( 'click', t.btnClass );

            t.btnClass             = '.imgedit-hotspot';
            t.hotspotClass         = '.hotspot';
            t.hotspotActiveClass   = 'hotspot-active';
            t.hotspotDisabledClass = 'hotspot-disabled';

            $.post(
                imagehotspot.ajax_url,
                {
                    action: 'get-attachment',
                    id: attach_id
                },
                function( rsp ) {
                    if ( rsp.success ) {
                        t.attachment = rsp.data;
                    } else {

                    }
                },
                'json'
            );

            // Put listeners on the save button
            return imageEdit.open.apply( this, arguments );
        },

        close: function() {
            var t = window.hotspot;

            t.hotspotRemoveListener();

            $( document ).off( 'click', t.btnClass );

            imageEdit.close.apply( this, arguments );
        },

        save: function( postid ) {
            var t = window.hotspot;

            if ( t.hotspotActive ) {

                this.toggleEditor( postid, 1 );

                $.post(
                    imagehotspot.ajax_url,
                    {
                        action: 'hotspot_save',
                        hotspot_nonce: imagehotspot.hotspot_nonce,
                        attachment_id: t.attach_id,
                        hotspots: [ t.hotspot ]
                    },
                    function( rsp ) {
                        if ( rsp.success ) {
                            $( '#imgedit-response-' + postid ).html('<div class="updated"><p>' + rsp.data.msg + '</p></div>');

                            if ( window.imageEdit._view ) {
                                // Remove listener for the embed view
                                t.hotspotRemoveListener();
                                $( document ).off( 'click', t.btnClass );
                                window.imageEdit._view.save();
                            } else {
                                window.imageEdit.close( postid );
                            }
                        } else {
                            $( '#imgedit-response-' + postid ).html( '<div class="error"><p>' + rsp.data.msg + '</p></div>' );
                            window.imageEdit.close( postid );
                            return;
                        }
                    },
                    'json'
                );

            } else {
                // There was a change in history, we should delete the hotspot informations
                if ( '' !== this.filterHistory( postid, 0 ) ) {
                    $.post(
                        imagehotspot.ajax_url,
                        {
                            action: 'hotspot_delete',
                            hotspot_nonce: imagehotspot.hotspot_nonce,
                            attachment_id: t.attach_id,
                        }
                    );
                }

                imageEdit.save.apply( this, arguments );
            }

        },

        imgLoaded: function( postid ) {
            var t = window.hotspot;

            t.$image     = $('#image-preview-' + postid);
            t.$parent    = $('#imgedit-crop-' + postid);
            t.attach_id  = postid;

            imageEdit.imgLoaded.apply( this, arguments );

            // Add new focus point button right here
            $( '.imgedit-menu button:last' ).after( '<button class="button '+t.btnClass.substr(1)+'" title="'+imagehotspot.btn_title+'"></button>' );

            // Proxy the inline onclick event
            $( '.imgedit-menu button' ).not( t.btnClass ).each( function() {
                // Cache event
                var existing_event = this.onclick;

                // Remove the event from the link
                this.onclick = null;

                // Add a check in for the class disabled
                $( this ).on( 'click', function(e){
                    if ( $( this ).hasClass( t.hotspotDisabledClass ) ) {
                        e.stopImmediatePropagation();
                        return false;
                    } else {
                        // Call original event
                        existing_event.apply( this, arguments );
                    }
                });
            });

            // Print the pointer if there is one
            if ( imagehotspot.pointer ) {
                var pointerOptions = $.extend( imagehotspot.pointer, {
                    close: function() {
                        $.post( imagehotspot.ajax_url, {
                            pointer: imagehotspot.pointer_id,
                            action: 'dismiss-wp-pointer'
                        });

                        pointerDismissed = true;
                    }
                });

                // This is because of a firefox bug on img loaded event
                setTimeout( function() {
                    if ( ! pointerDismissed ) {
                        $( t.btnClass )
                            .first()
                            .pointer( pointerOptions )
                            .pointer( 'open' );

                        // Pointer don't show on media modal otherwise
                        $( t.btnClass ).first().pointer( 'instance' ).pointer.css( {zIndex: 170000} );
                    }
                }, 50 );
            }

            $( document ).on( 'click', t.btnClass, t.hotspotToggleActivation );
        },

        refreshEditor: function() {
            var t = window.hotspot;

            // Disable completely the plugin because an image manipulation was done
            t.hotspotRemoveListener();

            // Remove clicking on button
            $( t.btnClass ).addClass(t.hotspotDisabledClass );
            $( document ).off( 'click', t.btnClass );

            imageEdit.refreshEditor.apply( this, arguments );
        },

        hotspotAddListener: function() {
            var t = window.hotspot;

            t.$image.on( 'click.hotspot', t.hotspotClick );
            t.$image.css( 'cursor', 'crosshair' );
            t.$image.imgAreaSelect({ remove: true });

            $('.imgedit-menu button').not( t.btnClass ).addClass( t.hotspotDisabledClass );

            // Prevent other listenerimgedit-submit-btn from disturbing our behavior
            window.imageEdit.setDisabled = function(){ return false; };
        },

        hotspotRemoveListener: function() {
            var t = window.hotspot;

            t.hotspotRemove();
            t.$image.off( 'click.hotspot' );
            t.$image.css( 'cursor', 'default' );

            imageEdit.initCrop( t.attach_id, t.$image, t.$image.parent() );

            $( '.imgedit-menu button' ).not( t.btnClass ).removeClass( t.hotspotDisabledClass );
            $( '.imgedit-submit-btn' ).prop( 'disabled', true );

            // Restore behavior
            window.imageEdit.setDisabled = imageEdit.setDisabled;
        },

        hotspotToggleActivation: function() {

            // First show current hotspot if there is a one
            var t = window.hotspot,
                $btn = $(this);

            if ( $( this ).hasClass( t.hotspotActiveClass ) ) {
                t.hotspotRemoveListener();
                $btn.removeClass( t.hotspotActiveClass );
                t.hotspotActive = false;
            } else {
                t.hotspotAddListener();
                $btn.addClass( t.hotspotActiveClass );

                // Here because firefox doesn't know the width before img is loaded
                t.correction = t.attachment.width / t.$image.width();

                if ( t.attachment.hotspot && t.attachment.hotspot !== '' ) {
                    $.each( t.attachment.hotspot, function( i, hotspot ) {
                        t.hotspotAdd( {
                            x: hotspot.x / t.correction,
                            y: hotspot.y / t.correction,
                        } );
                    } );
                }
            }

					return false;
        },

        hotspotAdd: function( hotspot ) {
            var t = window.hotspot,
                hotspotWidth = 40;

            hotspot = $.extend( {
                x: 0,
                y: 0,
            }, hotspot );

            t.hotspotActive = true;
            $( '.imgedit-submit-btn' ).removeAttr( 'disabled' );

            $( '<div class="'+ t.hotspotClass.substr(1)+'"></div>' )
                .css( {
                    left: hotspot.x - ( hotspotWidth / 2 ),
                    top: hotspot.y - ( hotspotWidth / 2 ),
                    width: hotspotWidth + 'px',
                    height: hotspotWidth + 'px',
                    position: 'absolute'
                } )
                .attr( 'title', 'Click to toggle on/off' )
                .appendTo( t.$parent )
                .click( function() {
                    t.hotspotRemove();
                } );

            t.hotspot = {
                x: Math.round( hotspot.x * t.correction ),
                y: Math.round( hotspot.y * t.correction )
            };
        },

        hotspotRemove: function() {
            var t = window.hotspot;

            t.hotspotActive = false;
            t.hotspot = {};
            $( t.hotspotClass ).remove();
            $( '.imgedit-submit-btn' ).prop( 'disabled', true );

        },

        hotspotClick: function(e) {
            var t = window.hotspot;

            t.hotspotRemove();

            // Some firefox versions don't do offsetX/Y so need to do something a little more complex
            t.hotspotAdd({
                x: e.offsetX || e.clientX - ( $( e.target ).offset().left - window.scrollX ),
                y: e.offsetY || e.clientY - ( $( e.target ).offset().top - window.scrollY )
            });
        }
    };

    // initialise
    window.imageEdit = $.extend( window.imageEdit, hotspot );

})( jQuery );
