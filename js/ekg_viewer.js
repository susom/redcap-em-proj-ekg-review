
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

    xAxis.tickFormat(function(d, i) {
        var tickerFormat = d3.timeFormat("%M:%S");
        var timeFormat = d3.timeFormat("%f");
        var milliSecondTime = timeFormat(d);

        if(milliSecondTime === '000000')
            return  tickerFormat(d);
    });


    // Array where to add y axis ticks
    var yAxisTicks = [];
    for (var i = -1; i.toFixed(2) <= 1; i += 0.04) {
        yAxisTicks.push(i.toFixed(2));
    }

    var xAxis2 = d3.axisBottom(x2).tickFormat(d3.timeFormat("%M:%S")),
        yAxis = d3.axisLeft(y).tickSize(-width).tickValues(yAxisTicks);

    yAxis.tickFormat(function(d, i) {
        if((d*10)%1 === 0)
            return  d;
    });

    var brush = d3.brushX()
        .extent([[0, 0], [width, height2]])
        .on("brush end", function() {
            if (d3.event.sourceEvent && d3.event.sourceEvent.type === "zoom") return; // ignore brush-by-zoom
            //console.log("brushed");
            xaxisGridLineOpacity();
            var s = d3.event.selection || x2.range();
            if((s[1] - s[0]) > 100){
                s[1] = s[0] + 100;
            }

            x.domain(s.map(x2.invert, x2));
            Line_chart.select(".line").attr("d", line);
            focus.select(".axis--x").call(xAxis);
            svg.select(".zoom").call(zoom.transform, d3.zoomIdentity
                .scale(width / (s[1] - s[0]))
                .translate(-s[0], 0));
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

    context.append("g")
        .attr("class", "brush")
        .call(brush)
        .call(brush.move, [0,50]);
    //.call(brush.move, x.range());

    svg.append("rect")
        .attr("class", "zoom")
        .attr("width", width)
        .attr("height", height)
        .attr("transform", "translate(" + margin.left + "," + margin.top + ")");
    //.call(zoom);

};



function brushed() {
    if (d3.event.sourceEvent && d3.event.sourceEvent.type === "zoom") return; // ignore brush-by-zoom
    //console.log("brushed");
    xaxisGridLineOpacity();
    var s = d3.event.selection || x2.range();
    if((s[1] - s[0]) > 100){
        s[1] = s[0] + 100;
    }

    x.domain(s.map(x2.invert, x2));
    Line_chart.select(".line").attr("d", line);
    focus.select(".axis--x").call(xAxis);
    svg.select(".zoom").call(zoom.transform, d3.zoomIdentity
        .scale(width / (s[1] - s[0]))
        .translate(-s[0], 0));
}

function moveBrushLeft(){
    console.log("moveBrushLeft");
    var s = d3.brushSelection(d3.select(".brush").node());
    var width = s[1] - s[0];
    if(s[0] - width <= 0){
        s[0] = 0;
        s[1] = s[0] + width;
    }else{
        s[0] = s[0] - width;
        s[1] = s[1] - width;
    }

    d3.select(".brush").call(brush.move, [s[0], s[1]]);
}

function moveBrushRight(){
    //console.log("moveBrushRight");
    var s = d3.brushSelection(d3.select(".brush").node());
    var width = s[1] - s[0];
    if(s[1] + width < 900){
        s[0] = s[0] + width;
        s[1] = s[1] + width;
    }else{
        s[1] = 900;
        s[0] = s[1] - width;

    }

    d3.select(".brush").call(brush.move, [s[0], s[1]]);
}

function zoomed() {
    if (d3.event.sourceEvent && d3.event.sourceEvent.type === "brush") return; // ignore zoom-by-brush
    //console.log("zoomed");
    // xaxisGridLineOpacity();
    var t = d3.event.transform;
    x.domain(t.rescaleX(x2).domain());
    Line_chart.select(".line").attr("d", line);
    focus.select(".axis--x").call(xAxis);
    context.select(".brush").call(brush.move, x.range().map(t.invertX, t));
}

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
