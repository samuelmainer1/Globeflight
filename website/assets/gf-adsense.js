(function () {
  var CLIENT = "ca-pub-5935226106762588";
  var SLOT = "7789598244";
  var MARK = /\[\[ad(?::(\d+))?\]\]/g;

  function loadScript() {
    if (document.querySelector("script[data-gf-adsense]")) return;
    var script = document.createElement("script");
    script.async = true;
    script.src = "https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=" + CLIENT;
    script.crossOrigin = "anonymous";
    script.setAttribute("data-gf-adsense", "1");
    document.head.appendChild(script);
  }

  function push(ad) {
    if (!ad || ad.getAttribute("data-gf-pushed") === "1") return;
    ad.setAttribute("data-gf-pushed", "1");
    try {
      (window.adsbygoogle = window.adsbygoogle || []).push({});
    } catch (error) {
      ad.removeAttribute("data-gf-pushed");
    }
  }

  function makeAd(slot) {
    var ad = document.createElement("ins");
    ad.className = "adsbygoogle";
    ad.style.display = "block";
    ad.style.margin = "1.5rem 0";
    ad.setAttribute("data-ad-client", CLIENT);
    ad.setAttribute("data-ad-slot", slot || SLOT);
    ad.setAttribute("data-ad-format", "auto");
    ad.setAttribute("data-full-width-responsive", "true");
    return ad;
  }

  function replaceMarkers(root) {
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    var nodes = [];
    var node;
    while ((node = walker.nextNode())) {
      if (node.nodeValue && node.nodeValue.indexOf("[[ad") !== -1) nodes.push(node);
    }
    nodes.forEach(function (textNode) {
      var text = textNode.nodeValue;
      var parent = textNode.parentNode;
      if (!parent) return;
      MARK.lastIndex = 0;
      var fragment = document.createDocumentFragment();
      var last = 0;
      var match;
      var found = false;
      while ((match = MARK.exec(text))) {
        found = true;
        if (match.index > last) fragment.appendChild(document.createTextNode(text.slice(last, match.index)));
        fragment.appendChild(makeAd(match[1]));
        last = match.index + match[0].length;
      }
      if (!found) return;
      if (last < text.length) fragment.appendChild(document.createTextNode(text.slice(last)));
      parent.replaceChild(fragment, textNode);
    });
  }

  var busy = false;

  function scan() {
    if (busy) return;
    var root = document.getElementById("root");
    if (!root) return;
    var hasMarker = root.textContent && root.textContent.indexOf("[[ad") !== -1;
    var pending = root.querySelector("ins.adsbygoogle[data-ad-client]:not([data-gf-pushed])");
    if (!hasMarker && !pending) return;
    busy = true;
    try {
      if (hasMarker) {
        loadScript();
        replaceMarkers(root);
      }
      root.querySelectorAll("ins.adsbygoogle[data-ad-client]:not([data-gf-pushed])").forEach(push);
    } finally {
      busy = false;
    }
  }

  function start() {
    var root = document.getElementById("root");
    if (!root) return;
    scan();
    new MutationObserver(scan).observe(root, { childList: true, subtree: true, characterData: true });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start);
  else start();
})();
