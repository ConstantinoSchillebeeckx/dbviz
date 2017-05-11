

function generateDBviz(options) {

    var chart = {};
    var defaultSelector = '#chart';

    if (typeof options === 'undefined') options = {};

    // set default options
    chart.options = {
        selector: defaultSelector,
        title: null,
        margin: {top: 15, right: 60, bottom: 40, left: 50},
        chartSize: {width: getParentWidth(options), height: getParentHeight(options)},
    }

    // update options with those provided
    for (var setting in options) {
        chart.options[setting] = options[setting]
    }

    !function parseOptions() {
        chart.margin = chart.options.margin;
        chart.divWidth = chart.options.chartSize.width;
        chart.divHeight = chart.options.chartSize.height;
        chart.width = chart.divWidth - chart.margin.left - chart.margin.right;
        chart.height = chart.divHeight - chart.margin.top - chart.margin.bottom;
        chart.title = chart.options.title;
        chart.selector = chart.options.selector;
    }();

    function getParentWidth(options, defaultWidth=960) {
        var sel = ('selector' in options) ? options.selector : defaultSelector;
        var w = jQuery(sel).height();
        return w > 0 ? w : defaultWidth;
    }

    function getParentHeight(options, defaultHeight=500) {
        var sel = ('selector' in options) ? options.selector : defaultSelector;
        var h = jQuery(sel).height();
        return h > 0 ? h : defaultHeight;
    }

    


    // setup graph
    var force = d3.layout.force()
        .linkDistance(80)
        .charge(-400)
        .size([chart.width, chart.height])
        .on("tick", tick);

    var svg = d3.select(chart.selector).append("svg")
        .attr("width", chart.width)
        .attr("height", chart.height);

    var link = svg.selectAll(".link"),
        node = svg.selectAll(".node");

    var drag = force.drag()
        .on("dragstart", dragstart)
        .on("dragend", dragended);

    function dragstart(d) {
      d3.select(this).classed("fixed", d.fixed = true);
      d3.select(this).classed("active", true);
    }

    function dragended(d) {
      d3.select(this).classed("active", false);
    }

    chart.update = function update() {
      var nodes = flatten(chart.options.data),
          links = d3.layout.tree().links(nodes);

      // Restart the force layout.
      force
          .nodes(nodes)
          .links(links)
          .start();

      // Update links.
      link = link.data(links, function(d) { return d.target.id; });

      link.exit().remove();

      link.enter().insert("line", ".node")
          .attr("class", "link");

      // Update nodes.
      node = node.data(nodes, function(d) { return d.id; });

      node.exit().remove();

      var nodeEnter = node.enter().append("g")
          .attr("class", "node")
          .on("click", click)
          .call(drag);

      nodeEnter.append("circle")
          .attr("r", 12 );

      nodeEnter.append("text")
          .attr("dy", "-20px")
          .text(function(d) { return d.name; });

      node.select("circle")
          .style("fill", color);
    }

    function tick() {
      link.attr("x1", function(d) { return d.source.x; })
          .attr("y1", function(d) { return d.source.y; })
          .attr("x2", function(d) { return d.target.x; })
          .attr("y2", function(d) { return d.target.y; });

      node.attr("transform", function(d) { return "translate(" + d.x + "," + d.y + ")"; });
    }

    function color(d) {
      return d._children ? "#3182bd" // collapsed package
          : d.children ? "#c6dbef" // expanded package
          : "#fd8d3c"; // leaf node
    }

    // Toggle children on click.
    function click(d) {
      if (d3.event.defaultPrevented) return; // ignore drag
      if (d.children) {
        d._children = d.children;
        d.children = null;
      } else {
        d.children = d._children;
        d._children = null;
      }
      chart.update();
    }

    // Returns a list of all nodes under the root.
    function flatten(root) {
      var nodes = [], i = 0;

      function recurse(node) {
        if (node.children) node.children.forEach(recurse);
        if (!node.id) node.id = ++i;
        nodes.push(node);
      }

      recurse(root);
      return nodes;
    }




    return chart;

}



