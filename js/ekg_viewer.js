
EKGEM.setup = function() {

    var svg = d3.select("svg"),
        margin = {top: 20, right: 20, bottom: 110, left: 40},
        margin2 = {top: 430, right: 20, bottom: 30, left: 40},
        width = +svg.attr("width") - margin.left - margin.right,
        height = +svg.attr("height") - margin.top - margin.bottom,
        height2 = +svg.attr("height") - margin2.top - margin2.bottom;

    var x = d3.scaleTime().range([0, width]),
        x2 = d3.scaleTime().range([0, width]),
        y = d3.scaleLinear().range([height, 0]),
        y2 = d3.scaleLinear().range([height2, 0]);

    var yZoomLevel = 0,
        yZoomLevels = {
            "0": [-1,1],
            "1": [-0.5,0.5],
            "2": [-0.25,0.25],
            "3": [-0.1, 0.1]
        },
        // Set how often to draw the Y axis tick labels
        yZoomLabelResolution = {
            "0": 20,
            "1": 20,
            "2": 4,
            "3": 1
        }
    ;


    var xAxis = d3.axisBottom(x).tickSize(-(height)).ticks(d3.timeMillisecond.every(200));

    xAxis.tickFormat(
        function(d, i) {
            var tickerFormat = d3.timeFormat("%M:%S");
            var timeFormat = d3.timeFormat("%f");
            var milliSecondTime = timeFormat(d);
            if(milliSecondTime === '000000')
                return  tickerFormat(d);
        }
    );

    var xAxis2 = d3.axisBottom(x2).tickFormat(d3.timeFormat("%M:%S"))

    // Array where to add y axis ticks
    var yAxisTicks = [];
    for (var i = -1; i.toFixed(2) <= 1; i += 0.04) {
        yAxisTicks.push(i.toFixed(2));
    }


    var yAxis = d3.axisLeft(y).tickSize(-width).tickValues(yAxisTicks);


    // The tickFormat for y-axis
    yAxis.tickFormat(function(d, i) {
        var res = yZoomLabelResolution[yZoomLevel];

        // console.log(d, d/res, (d/res)%1, (d*res)%1, i%res);
        // if((d*10)%1 === 0) return  d;

        // Don't draw Y-axis labels that are outside of the visible range
        if( (d*100)%res === 0 && d >= yZoomLevels[yZoomLevel][0] ) return  d;
    });



    EKGEM.zoomInY = function() {
        // Change Y-Axis Scaling
        yZoomLevel = Math.min(3, yZoomLevel+1);
        EKGEM.zoomY();
    };

    EKGEM.zoomOutY = function() {
        // Change Y-Axis Scaling
        yZoomLevel = Math.max(0, yZoomLevel-1);
        EKGEM.zoomY();
    };

    EKGEM.filterYAxisTicks = function(currentValue, index, arr) {
        return currentValue >= yZoomLevels[yZoomLevel][0] && currentValue <= yZoomLevels[yZoomLevel][1];
    };

    EKGEM.zoomY = function() {

        var limitedTicks = yAxisTicks.filter(EKGEM.filterYAxisTicks);

        yAxis.tickValues(limitedTicks);

        // Set y domain
        y.domain(yZoomLevels[yZoomLevel]);

        // Update Y Axis Labeling
        focus.select(".axis--y").call(yAxis);

        yaxisGridLineOpacity();

        // Redraw the linechart
        line_chart.select(".line").attr("d", line);


        // y2.domain(y.domain());
        // context.select(".line").attr("d", line2);

    };

    EKGEM.brush = brush = d3.brushX()
        .extent([[0, 0], [width, height2]])
        .on("brush end", function() {

            // if (d3.event.sourceEvent) console.log(d3.event.sourceEvent.type);

            if (d3.event.sourceEvent && d3.event.sourceEvent.type === "zoom") return; // ignore brush-by-zoom
            //console.log('Brush End');
            var s = d3.event.selection || x2.range();

            // Prevent selection from exceeding maxWidth
            if((s[1] - s[0]) > EKGEM.maxWidth){

                // Reset width
                s[1] = s[0] + EKGEM.maxWidth;

                // Redraw the brush to be within the boundaries
                context.select(".brush").call(brush.move, s); //x.range().map(t.invertX, t));
            }

            // Set boundaries for buttons
            if(s[0] <= 0){
                $("#buttonMoveLeft").addClass('disabled'); // = true;
            } else {
                $("#buttonMoveLeft").removeClass('disabled'); // = false;
            }

            if(s[1] < EKGEM.width){
                $("#buttonMoveRight").removeClass('disabled'); // = false;
            } else {
                $("#buttonMoveRight").addClass('disabled'); // = true;
            }

            // Redraw Chart
            x.domain(s.map(x2.invert, x2));
            line_chart.select(".line").attr("d", line);
            focus.select(".axis--x").call(xAxis);

            // Fix the grid
            xaxisGridLineOpacity();

            // AM - REMOVING ZOOM
            // // Seems inefficient
            // svg.select(".zoom").call(zoom.transform,
            //     d3.zoomIdentity
            //     .scale(width / (s[1] - s[0]))
            //     .translate(-s[0], 0));

            setCookie("ekg_zoom_width", s[1]-s[0], 30);
            // console.log("Width: ", width,s[1], s[0], s[1]-s[0]);
        });


    // var zoom = d3.zoom()
    //     .scaleExtent([1, Infinity])
    //     .translateExtent([[0, 0], [width, height]])
    //     .extent([[0, 0], [width, height]])
    //     .on("zoom", function() {
    //         if (d3.event.sourceEvent && d3.event.sourceEvent.type === "brush") return; // ignore zoom-by-brush
    //         console.log("d3.zoom not by brush");
    //         // xaxisGridLineOpacity();
    //
    //         var t = d3.event.transform;
    //         // console.log("Zoom", x2);
    //
    //         x.domain(t.rescaleX(x2).domain());
    //         line_chart.select(".line").attr("d", line);
    //         focus.select(".axis--x").call(xAxis);
    //         console.log(x.range(), t.invertX, t);
    //         // context.select(".brush").call(brush.move, x.range().map(t.invertX, t));
    //     });


    var line = d3.line()
        .x(function (d) { return x(d.time); })
        .y(function (d) { return y(d.mv); });


    var line2 = d3.line()
        .x(function (d) { return x2(d.time); })
        .y(function (d) { return y2(d.mv); });



    var clip = svg.append("defs")
        .append("svg:clipPath")
        .attr("id", "clip")
        .append("svg:rect")
        .attr("width", width)
        .attr("height", height)
        .attr("x", 0)
        .attr("y", 0);

    var line_chart = svg.append("g")
        .attr("class", "focus")
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")")
        .attr("clip-path", "url(#clip)");

    var focus = svg.append("g")
        .attr("class", "focus")
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")");

    var context = svg.append("g")
        .attr("class", "context")
        .attr("transform", "translate(" + margin2.left + "," + margin2.top + ")");



    // Define function to parse date
    var parseDate = d3.timeParse("%M:%S.%f");

    // Load data from passthru
    data = EKGEM['data'];

    // Parse data
    data.forEach(function(d) {
        d.time = parseDate(d.time);
        d.mv = +d["mv"];
    });


    //var minTime = d3.min(data, function (d) { return d.time; });
    //var maxTime = d3.max(data, function (d) { return d.time; });

    x.domain(d3.extent(data, function(d) { return d.time; }));
    //y.domain([d3.min(data, function (d) { return d.mv; }), d3.max(data, function (d) { return d.mv; })]);
    y.domain([-1,1]);
    x2.domain(x.domain());
    y2.domain(y.domain());


    focus.append("g")
        .attr("class", "axis axis--x")
        .attr("transform", "translate(0," + height + ")")
        .call(xAxis);


    // text label for the x axis
    svg.append("text")
        .attr("transform", "translate(" + (width/2) + " ," +  (height + margin.top + 20) + ")")
        .attr("dy", ".7em")
        .style("text-anchor", "middle")
        .text("Time (min:sec)");


    // Add Y-Axis
    focus.append("g")
        .attr("class", "axis axis--y")
        // .attr("transform", "translate(" + 0 + " ," + height2 + ")")
        // .attr("y", -100)
        .call(yAxis);

    // Add opacity to y axis grid lines.
    yaxisGridLineOpacity();

    // text label for the y axis
    focus.append("text")
        .attr("transform", "rotate(-90)")
        .attr("y", 0 - margin.left)
        .attr("x",0 - (height / 2))
        .attr("dy", ".7em")
        .style("text-anchor", "middle")
        .text("mV (Millivolts)");


    // Do the actual data line
    line_chart.append("path")
        .datum(data)
        .attr("class", "line")
        .attr("d", line);

    // Do the data line on the brush
    context.append("path")
        .datum(data)
        .attr("class", "line")
        .attr("d", line2);

    // Do the x-axis on the brush
    context.append("g")
        .attr("class", "axis axis--x")
        .attr("transform", "translate(0," + height2 + ")")
        .call(xAxis2);


    // Set default width
    context.append("g")
        .attr("class", "brush")
        .call(brush)
        .call(brush.move, [0,EKGEM.defaultBrushWidth]);

    // svg.append("rect")
    //     .attr("class", "zoom")
    //     .attr("width", width)
    //     .attr("height", height)
    //     .attr("transform", "translate(" + margin.left + "," + margin.top + ")");



    // Now that the page is rendered, lets scroll to the top of the EKG
    $('html, body').animate({
        scrollTop: ($('svg').offset().top)
    },500);


    // Lets also show the redcap questions
    $('#questiontable').animate({
        opacity: 1
    }, 500);

    // MOVED TO HOTKEYS
    // $('body').on('keypress', function(args) {
    //     // console.log(args);
    //     if (args.keyCode === 60 || args.keyCode === 44) {
    //         $('#buttonMoveLeft').click();
    //         return false;
    //     } else if (args.keyCode === 46 || args.keyCode == 62) {
    //         $('#buttonMoveRight').click();
    //         return false;
    //     }
    // });

};

EKGEM.setWidth = function(setWidth){

    setWidth = Math.round(8.133333 * setWidth,0);

    var s = d3.brushSelection(d3.select(".brush").node());
    var width = s[1] - s[0];

    console.log(s[0], s[1], width, setWidth);

    // If the width is unchanged, do nothing
    if (setWidth == width) return;

    if(s[0] + setWidth < EKGEM.width){
        s[0] = s[0];
        s[1] = s[0] + setWidth;
    }else{
        s[1] = EKGEM.width;
        s[0] = s[1] - setWidth;
    }

    console.log(s[0], s[1], width, setWidth);

    d3.select(".brush").call(EKGEM.brush.move, [s[0], s[1]]);
};


// function brushed() {
//     if (d3.event.sourceEvent && d3.event.sourceEvent.type === "zoom") return; // ignore brush-by-zoom
//     //console.log("brushed");
//     xaxisGridLineOpacity();
//     var s = d3.event.selection || x2.range();
//     if((s[1] - s[0]) > 100){
//         s[1] = s[0] + 100;
//     }
//
//     x.domain(s.map(x2.invert, x2));
//     Line_chart.select(".line").attr("d", line);
//     focus.select(".axis--x").call(xAxis);
//     svg.select(".zoom").call(zoom.transform, d3.zoomIdentity
//         .scale(width / (s[1] - s[0]))
//         .translate(-s[0], 0));
// }

function xaxisGridLineOpacity(){
    var xAxidGrids = d3.selectAll(".axis--x .tick");

    xAxidGrids.each(function(d) {
        var ticktext = d3.select(this).select("text").text();
        //console.log(ticktext)
        if(ticktext === ""){
            d3.select(this).attr("stroke-opacity", 0.3);
        }
    });

}

function yaxisGridLineOpacity(){
    var yAxidGrids = d3.selectAll(".axis--y .tick");

    yAxidGrids.each(function(d) {

        // console.log(d, this);
        var ticktext = d3.select(this).select("text").text();
        //console.log(ticktext)
        if(ticktext === ""){
            d3.select(this).attr("stroke-opacity", 0.3);
        }
    });

}



function moveBrushLeft(){
    // console.log("moveBrushLeft");
    var s = d3.brushSelection(d3.select(".brush").node());
    var width = s[1] - s[0];
    if(s[0] - width <= 0){
        s[0] = 0;
        s[1] = s[0] + width;
    }else{
        s[0] = s[0] - width;
        s[1] = s[1] - width;
    }

    d3.select(".brush").call(EKGEM.brush.move, [s[0], s[1]]);
}

function moveBrushRight(){
    //console.log("moveBrushRight");
    var s = d3.brushSelection(d3.select(".brush").node());
    var width = s[1] - s[0];
    if(s[1] + width < EKGEM.width){
        s[0] = s[0] + width;
        s[1] = s[1] + width;
    }else{
        s[1] = EKGEM.width;
        s[0] = s[1] - width;
    }
    d3.select(".brush").call(EKGEM.brush.move, [s[0], s[1]]);
}
