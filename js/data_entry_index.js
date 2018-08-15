console.log("Here");

// Initialize an object for custom functions or data
var EKGEM = EKGEM || {}




$('document').ready( function() {
    let instructions = $("<div/>")
        .text("These are instructions")
        .insertAfter('#subheader');
    //.after(instructions);

    // Allow width to be wider
    $('#form>div').filter(":first").removeAttr('style');

    // Remove other save options
    $('#submit-btn-saverecord').text("Save and Next");

    // Bring cancel button up
    $('button[name="submit-btn-cancel"]').removeAttr('style');


    // Set the form_complete status to be 2
    $('select[name="ekg_review_complete"]').val(2);


    // Make the center class wider
    $('#center').removeClass("col-sm-8").addClass("col-sm-12").removeClass("col-md-9").addClass("col-md-12");

    if (EKGEM.progress) {
        console.log (EKGEM.progress);
        let width = EKGEM.progress.width;
        let text = EKGEM.progress.text;


        // let p = $('<div/>').addClass('progress');
        let p2 = $('<div/>');
        p2.addClass('progress-bar')
            .addClass('progress-bar-striped')
            .text(text)
            .attr('style', "width:" + width + "%")
            .wrap("<div class='progress'></div>")
            .parent()
            .insertAfter('#subheader');

    }

});