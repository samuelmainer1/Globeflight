(function () {
  function htmlBox() {
    var area = document.getElementById("gf-blog-html");
    if (!area || area.style.display === "none") return null;
    return area;
  }

  function apply(body) {
    var area = htmlBox();
    if (!area || !(body instanceof FormData) || typeof body.has !== "function" || !body.has("content")) return body;
    body.set("content", area.value);
    return body;
  }

  var send = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function (body) {
    return send.call(this, apply(body));
  };

  if (typeof window.fetch === "function") {
    var fetch = window.fetch;
    window.fetch = function (input, init) {
      if (init && init.body) {
        init = Object.assign({}, init, { body: apply(init.body) });
      }
      return fetch.call(this, input, init);
    };
  }
})();
