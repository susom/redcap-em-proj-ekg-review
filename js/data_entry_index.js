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

    // let instructions = $("<div/>")
    //     .text("These are instructions")
    //     .insertAfter('#subheader');

    let svg = $("<svg width='960' height='500'/>").insertAfter('#subheader');


    let btnL = $('<div id="buttonMoveLeft" class="btn btn-primary" title="Move Left" />')
        .bind('click', function() { moveBrushLeft() })
        .append('<i class="fa fa-arrow-left"></i>');

    let btnR = $('<div id="buttonMoveRight" class="btn btn-primary" title="Move Right" />')
        .bind('click', function() { moveBrushRight() })
        .append('<i class="fa fa-arrow-right"></i>');

    $("<div id='moveButtons'/>").append(btnL).append(btnR).insertAfter(svg);


    // Allow width to be wider
    $('#form>div').filter(":first").removeAttr('style');

    // Remove other save options
    $('#submit-btn-saverecord').text("Save and Next").removeAttr('style');

    // Bring cancel button up
    $('button[name="submit-btn-cancel"]').removeAttr('style');


    // If we are in a DAG - we do some additional formatting
    if (EKGEM.dag) {
        $('body').addClass("DAG");

        // Set the form_complete status to be 2
        let status_select = $('select[name="ekg_review_complete"]');
        let status_val = status_select.val();
        if (+status_val === 2) {
            // Form is already complete - user should not be editing it again
            $('body').css("display","none");
            alert ('This record has already been scored.  Press OK to return to the home page.');
            window.location = EKGEM.removeParam("id", window.location.href);
        } else {
            // Set it to complete so on-save it is fixed
            status_select.val(2);
        }

        // Make the center class wider
        $('#center').removeClass("col-sm-8").addClass("col-sm-12").removeClass("col-md-9").addClass("col-md-12");

        // Update the progress meter
        if (EKGEM.progress) {
            console.log (EKGEM.progress);
            let width = EKGEM.progress.width;
            let text = EKGEM.progress.text;

            $('<div/>').addClass('progress-bar')
                .addClass('progress-bar-striped')
                .text(text)
                .attr('style', "width:" + width + "%")
                .wrap("<div class='progress'></div>")
                .parent()
                .insertAfter('#questiontable');
                // .insertAfter('#subheader');
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

});