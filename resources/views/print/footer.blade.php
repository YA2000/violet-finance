<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
{!! $fontCss !!}
html, body { margin: 0; padding: 0; }
body { font-family: 'Carlito', sans-serif; color: #333; font-size: 8pt; }
table { width: 100%; border-collapse: collapse; border-top: 1px solid #999; }
td { padding-top: 1.5mm; vertical-align: top; }
</style>
<script>
function subst() {
    var vars = {}, q = document.location.search.substring(1).split('&');
    for (var i in q) { var z = q[i].split('=', 2); vars[z[0]] = decodeURIComponent(z[1]); }
    var map = {page: 'page', topage: 'topage'};
    for (var k in map) {
        var els = document.getElementsByClassName(k);
        for (var j = 0; j < els.length; ++j) els[j].textContent = vars[map[k]];
    }
}
</script>
</head>
<body onload="subst()">
<table><tr>
  <td style="width:33%">{!! $p->footerHtml('left') !!}</td>
  <td style="width:34%; text-align:center">{!! $p->footerHtml('center') !!}</td>
  <td style="width:33%; text-align:right">{!! $p->footerHtml('right') !!}</td>
</tr></table>
</body>
</html>
