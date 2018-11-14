// Initialize an object for custom functions or data
var EKGEM = EKGEM || {};


// A helper function for redirects to remove a query string attribute
EKGEM.removeParam = function(key, sourceURL) {
    var rtn = sourceURL.split("?")[0],
        param,
        params_arr = [],
        queryString = (sourceURL.indexOf("?") !== -1) ? sourceURL.split("?")[1] : "";
    if (queryString !== "") {
        params_arr = queryString.split("&");
        for (var i = params_arr.length - 1; i >= 0; i -= 1) {
            param = params_arr[i].split("=")[0];
            if (param === key) {
                params_arr.splice(i, 1);
            }
        }
        rtn = rtn + "?" + params_arr.join("&");
    }
    return rtn;
};


$('document').ready( function() {



    // SHAZAM STUFF FOR QUESTIONS

    if (EKGEM.dag) {

        $('#center').removeClass('col-md-8').removeClass('col-lg-9');

        // Center the finalize buttons
        $('#__SUBMITBUTTONS__-tr td:first')
            .remove();

        $('#__SUBMITBUTTONS__-tr td:first')
            .removeClass('col-5')
            .addClass('text-center');

        // Cancel button
        $('button[name="submit-btn-cancel"]')
            .removeClass('btn-defaultrc')
            .removeClass('btn-sm')
            .addClass('btn-danger')
            .addClass('btn-lg')
            .css({ "float": "left", "margin-top": "6px" });

        // save button
        $('#submit-btn-saverecord')
            .addClass('btn-lg')
            .removeClass('btn-primaryrc')
            .addClass('btn-success')
            .css({"float": "right", "width": "50%"});

        setTimeout(function () {
            $('.resetLinkParent').removeAttr('style').find('a').attr('tabindex', '-1')
        }, 500);

        setTimeout(function () {
            Shazam.showDuration = 200;
            Shazam.hideDuration = 200;
        }, 500);

        // // Set focus
        // var i = $('<input/>').css({
        //     width: "2px",
        //     opacity: "50"
        // })
        //     .addClass('first-focus')
        //     .focus()
        //     .insertBefore($('input[name="q1"]'));

        setTimeout(function () {
            // $('#center').click();
            $('#buttonMoveRight').focus();
            // console.log($.each($(":focus"), function(index, value) { console.log(i, value) } ));
            // $('body').trigger('click').focus();
        }, 700);
        //

        // Add record ID to header
        var urlParams = new URLSearchParams(location.search);
        var record = urlParams.get('id');
        $('#subheaderDiv2').text('EKG Project').append(
            $('<span/>')
                .addClass('badge badge-dark')
                .addClass('ml-2')
                .text( '#' + record ) //' #' + $('#record_id-tr td:last-child').text() )
        );
    }




    // Adjust for margins and scaling factor (estimated margin + scaling)
    // console.log("Document Width", $(document).width());
    // console.log("svg width", docWidth);

    // if (docWidth < 1200) {
    //     p = { "width": 900, "brushWidth": 150, "maxWidth": 150 }
    // } else if (docWidth < 1500) {
    //     p = { "width": 1200, "brushWidth": 200, "maxWidth": 200 }
    // } else if (docWidth < 1800) {
    //     p = { "width": 1500, "brushWidth": 250, "maxWidth": 250 }
    // }
    // EKGEM.width             = 900; // 900
    // EKGEM.defaultBrushWidth = 150; // 150
    // EKGEM.maxWidth          = 150; // 150
    // Ratio of 1/6 = 30 seconds.  //sec width ratio of 3 for 1 min.

    docWidth = Math.max(320, $(document).width() - 80) * 0.83;
    unit = min(Math.floor(docWidth / 5), 380);
    // console.log("Unit: " + unit);
    p = { "width" : 6*unit, "brushWidth": unit, "maxWidth": unit};
    // console.log("After", p);

    // Get Last Width and keep it for the next EKG if set
    lastWidth = getCookie("ekg_zoom_width");
    // console.log("lastWidth", lastWidth);
    if(lastWidth.length) p.brushWidth = min(p.brushWidth,lastWidth);

    EKGEM.width             = p.width;
    EKGEM.defaultBrushWidth = p.brushWidth;
    EKGEM.maxWidth          = p.maxWidth;
    EKGEM.step              = 1;
    EKGEM.slideDelay        = 50;   // Not sure this is used anymore

    // let svg = $("<svg width='" + EKGEM.width + 60 + "' height='500'/>").insertAfter('#subheader');
    let svg = $("<svg width='" + (EKGEM.width + 60) + "' height='500'/>").insertAfter('#subheader');


    let btnL = $('<div id="buttonMoveLeft" class="btn btn-primaryrc btn-xzoom" title="Move Left\nhotkey: shift+left arrow" />')
        .bind('click', function() { moveBrushLeft() })
        .attr('tabindex', 0)
        .append('<i class="fa fa-long-arrow-alt-left"></i>');

    let btnR = $('<div id="buttonMoveRight" class="btn btn-primaryrc btn-xzoom" title="Move Right\nhotkey: shift+right arrow" />')
        .bind('click', function() { moveBrushRight() })
        .attr('tabindex', 0)
        .append('<i class="fa fa-long-arrow-alt-right"></i>');

    let zoomInY = $('<div id="buttonZoomInY" class="btn btn-success btn-yzoom" title="Zoom In on Y Axis\nhotkey: shift+up arrow" />')
        .bind('click', function() { EKGEM.zoomInY() })
        .attr('tabindex', 0)
        .append('<i class="fa fa-search-plus"></i>');

    let zoomOutY = $('<div id="buttonZoomOutY" class="btn btn-success btn-yzoom" title="Zoom Out on Y Axis\nhotkey: shift+down arrow" />')
        .bind('click', function() { EKGEM.zoomOutY() })
        .attr('tabindex', 0)
        .append('<i class="fa fa-search-minus"></i>');

    const w30 = $('<div id="x30" class="badge badge-secondary btn-xscale">30 sec</div>')
        .bind('click', function() {
            console.log('clicking!');
            EKGEM.setWidth(30)
        });
    const w10 = $('<div id="x10" class="badge badge-secondary btn-xscale">10 sec</div>')
        .bind('click', function() {
            console.log('clicking!');
            EKGEM.setWidth(10)
        });
    const w5 = $('<div id="x5" class="badge badge-secondary btn-xscale">5 sec</div>')
        .bind('click', function() {
            console.log('clicking!');
            EKGEM.setWidth(5)
        });
    const w3 = $('<div id="x3" class="badge badge-secondary btn-xscale">3 sec</div>')
        .bind('click', function() {
            console.log('clicking!');
            EKGEM.setWidth(3)
        });

    const wBox1 = $("<div/>")
        .addClass('xscale-badges')
        .append(w3)
        .append(w5);

    const wBox2 = $("<div/>")
        .addClass('xscale-badges')
        .append(w10)
        .append(w30);

    const helpIcon = $('<div id="buttonHelpInfo" class="btn text-center"><i class="fas fa-question-circle"></i></div>')
        .data('trigger', 'click')
        .data('toggle', 'popover')
        .data('title', '<i class="fas fa-hand-point-right"></i> Hotkey Shortcuts')
        .data('html', true)
        .data('content', 'Hint: you can use \'hotkeys\' to quickly navigate the EKG with your keyboard.<br>' +
            '<ul><li>Hold SHIFT + LEFT-ARROW to go back in time</li>' +
            '<li>Hold SHIFT + RIGHT-ARROW to go forward in time</li>' +
            '<li>Hold SHIFT + UP-ARROW to increase Y Gain</li>' +
            '<li>Hold SHIFT + DOWN-ARROW to decrese Y Gain</li></ul>');


    $("<div id='moveButtons'/>")
        .append(btnL)
        .append(zoomOutY)
        .append(wBox1)
        .append(helpIcon)
        .append(wBox2)
        .append(zoomInY)
        .append(btnR)
        .insertAfter(svg);




    $('#buttonHelpInfo').popover();

    // Allow width to be wider
    $('#form>div').filter(":first").removeAttr('style');

    // Remove other save options
    $('#submit-btn-saverecord').text("Finalize and Next").removeAttr('style');

    // Bring cancel button up
    $('button[name="submit-btn-cancel"]').removeAttr('style');


    // If we are in a DAG - we do some additional formatting
    if (EKGEM.dag) {
        $('body').addClass("DAG");

        // Set the form_complete status to be 2
        var status_select = $('select[name="ekg_review_complete"]');
        var status_val = status_select.val();
        if (+status_val === 2) {
            console.log("This record is already complete - you shouldn't be editing it!");
            // Form is already complete - user should not be editing it again
            // $('body').css("display","none");
            // alert ('This record has already been scored.  Press OK to return to the home page.');
            // window.location = EKGEM.removeParam("id", window.location.href);
        } else {
            // Set it to complete so on-save it is fixed
            // status_select.val(2);
        }

        // Make the center class wider
        $('#center').removeClass("col-sm-8").addClass("col-sm-12").removeClass("col-md-9").addClass("col-md-12");

        // Update the progress meter
        if (EKGEM.progress) {
            //console.log (EKGEM.progress);
            let width = EKGEM.progress.width;
            let text = EKGEM.progress.text;

            $('<div/>').addClass('progress-bar')
                .addClass('progress-bar-striped')
                .text(text)
                .attr('style', "width:" + width + "%")
                .wrap("<div class='progress'></div>")
                .parent()
                .insertAfter('#questiontable');
        }

        // Set the start time
        if (EKGEM.startTime) {
            let st = $('input[name="start_time"]');
            if (st.length) {
                if (st.val() == "") {
                    st.val(EKGEM.startTime);
                }
            }
        }

        // Set reviewer
        if (EKGEM.userid) {
            $('input[name="reviewer"]').val(EKGEM.userid);
        }

     } //DAG


    // Start the d3 viewer
    if (EKGEM.data === false) {
        // There was no data
        alert("There was a problem loading the data for this record");
    } else {
        EKGEM.setup();
    }


    // HOTKEYS
    var k = hotkeys.noConflict();
    k('shift+right,shift+left,shift+up,shift+down,\,,.,shift+\,,shift+.', function(event,handler) {
        switch(handler.key) {
            case "shift+left":
            case ",":
            case "shift+,":
                moveBrushLeft();
                event.preventDefault();
                break;
            case "shift+right":
            case ".":
            case "shift+.":
                moveBrushRight();
                event.preventDefault();
                break;
            case "shift+up":
                EKGEM.zoomInY();
                event.preventDefault();
                break;
            case "shift+down":
                EKGEM.zoomOutY();
                event.preventDefault();
                break;
            default:
                console.log(handler.key, handler);
        }
    });

    // $('body').trigger('click').focus();
    // console.log("Body has focus");

    // $(window).focus();
    // $('#ekg').focus();


});

