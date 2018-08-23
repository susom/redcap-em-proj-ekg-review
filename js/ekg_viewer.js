
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

    var xAxis = d3.axisBottom(x).tickSize(-height).ticks(d3.timeMillisecond.every(200));

    xAxis.tickFormat(
        function(d, i) {
            var tickerFormat = d3.timeFormat("%M:%S");
            var timeFormat = d3.timeFormat("%f");
            var milliSecondTime = timeFormat(d);

            if(milliSecondTime === '000000')
                return  tickerFormat(d);
        }
    );


    // Array where to add y axis ticks
    var yAxisTicks = [];
    for (var i = -1; i.toFixed(2) <= 1; i += 0.04) {
        yAxisTicks.push(i.toFixed(2));
    }

    var xAxis2 = d3.axisBottom(x2).tickFormat(d3.timeFormat("%M:%S")),
        yAxis = d3.axisLeft(y).tickSize(-width).tickValues(yAxisTicks);

    yAxis.tickFormat(function(d, i) { if((d*10)%1 === 0) return  d; });

    EKGEM.brush = brush = d3.brushX()
        .extent([[0, 0], [width, height2]])
        .on("brush end", function() {
            if (d3.event.sourceEvent && d3.event.sourceEvent.type === "zoom") return; // ignore brush-by-zoom
            //console.log("brushed");
            xaxisGridLineOpacity();
            var s = d3.event.selection || x2.range();
            if((s[1] - s[0]) > EKGEM.maxWidth){
                s[1] = s[0] + EKGEM.maxWidth;
            }

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

            x.domain(s.map(x2.invert, x2));
            Line_chart.select(".line").attr("d", line);
            focus.select(".axis--x").call(xAxis);
            svg.select(".zoom").call(zoom.transform, d3.zoomIdentity
                .scale(width / (s[1] - s[0]))
                .translate(-s[0], 0));

            setCookie("ekg_zoom_width", s[1]-s[0], 30);
            // console.log("Width: ", width,s[1], s[0], s[1]-s[0]);
        });

    var zoom = d3.zoom()
        .scaleExtent([1, Infinity])
        .translateExtent([[0, 0], [width, height]])
        .extent([[0, 0], [width, height]])
        .on("zoom", function() {
            if (d3.event.sourceEvent && d3.event.sourceEvent.type === "brush") return; // ignore zoom-by-brush
            //console.log("zoomed");
            // xaxisGridLineOpacity();
            var t = d3.event.transform;
            x.domain(t.rescaleX(x2).domain());
            Line_chart.select(".line").attr("d", line);
            focus.select(".axis--x").call(xAxis);
            context.select(".brush").call(brush.move, x.range().map(t.invertX, t));
        });

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

    var Line_chart = svg.append("g")
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

    focus.append("g")
        .attr("class", "axis axis--y")
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


    Line_chart.append("path")
        .datum(data)
        .attr("class", "line")
        .attr("d", line);


    context.append("path")
        .datum(data)
        .attr("class", "line")
        .attr("d", line2);


    context.append("g")
        .attr("class", "axis axis--x")
        .attr("transform", "translate(0," + height2 + ")")
        .call(xAxis2);

    // Set default width
    context.append("g")
        .attr("class", "brush")
        .call(brush)
        .call(brush.move, [0,EKGEM.defaultBrushWidth]);

    svg.append("rect")
        .attr("class", "zoom")
        .attr("width", width)
        .attr("height", height)
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")");

    // Now that the page is rendered, lets scroll to the top of the EKG
    $('html, body').animate({
        scrollTop: ($('svg').offset().top)
    },500);

    $('body').on('keypress', function(args) {
        // console.log(args);
        if (args.keyCode === 60 || args.keyCode === 44) {
            $('#buttonMoveLeft').click();
            return false;
        } else if (args.keyCode === 46 || args.keyCode == 62) {
            $('#buttonMoveRight').click();
            return false;
        }
    });

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

function startSlide(direction) {
    // console.log(direction);
    EKGEM.sliding = direction;
    EKGEM.interval = setInterval(function() {slide();}, EKGEM.slideDelay);
    // slide();
}

function stopSlide(){
    // console.log("Stop Slide");
    EKGEM.sliding == false;
    clearInterval(EKGEM.interval);
}

function slide(){
    // console.log("slide", EKGEM.sliding);
    if (EKGEM.sliding == "left") {
        // console.log("moveBrushLeft");
        var s = d3.brushSelection(d3.select(".brush").node());
        var width = s[1] - s[0];
        var newStart = Math.max(0, s[0] - EKGEM.step);
        s[0] = newStart;
        s[1] = newStart + width;
        d3.select(".brush").call(EKGEM.brush.move, [s[0], s[1]]);
    } else if (EKGEM.sliding == "right") {
        var s = d3.brushSelection(d3.select(".brush").node());
        var width = s[1] - s[0];

        var newStart = s[0]+EKGEM.step;
        s[0] = newStart;
        s[1] = newStart+width;

        if(s[1] > EKGEM.width){
            s[1] = EKGEM.width;
            s[0] = EKGEM.width - width;
        }
        // console.log("About to set interval");
        d3.select(".brush").call(EKGEM.brush.move, [s[0], s[1]]);
        // if (EKGEM.interval !== false) EKGEM.interval(function() {slide();}, 100);
    }
}


function slideLeft(){
    if (EKGEM.sliding) {
        // console.log("moveBrushLeft");
        var s = d3.brushSelection(d3.select(".brush").node());
        var width = s[1] - s[0];
        var newStart = Math.max(0, s[0] - EKGEM.step);
        s[0] = newStart;
        s[1] = newStart + width;
        d3.select(".brush").call(EKGEM.brush.move, [s[0], s[1]]);
        setInterval("slideLeft", 100);
    }
}

function slideRight(){
    if (EKGEM.sliding) {
        //console.log("moveBrushRight");
        var s = d3.brushSelection(d3.select(".brush").node());
        var width = s[1] - s[0];

        var newStart = s[0]+EKGEM.step;
        s[0] = newStart;
        s[1] = newStart+width;

        if(s[1] > EKGEM.width){
            s[1] = EKGEM.width;
            s[0] = EKGEM.width - width;
        }
        d3.select(".brush").call(EKGEM.brush.move, [s[0], s[1]]);
        setInterval("slideRight", 100);
    }
}





// function zoomed() {
//     if (d3.event.sourceEvent && d3.event.sourceEvent.type === "brush") return; // ignore zoom-by-brush
//     //console.log("zoomed");
//     // xaxisGridLineOpacity();
//     var t = d3.event.transform;
//     x.domain(t.rescaleX(x2).domain());
//     Line_chart.select(".line").attr("d", line);
//     focus.select(".axis--x").call(xAxis);
//     context.select(".brush").call(EKGEM.brush.move, x.range().map(t.invertX, t));
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
        var ticktext = d3.select(this).select("text").text();
        //console.log(ticktext)
        if(ticktext === ""){
            d3.select(this).attr("stroke-opacity", 0.3);
        }
    });

}

