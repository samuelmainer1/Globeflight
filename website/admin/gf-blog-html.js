(function () {
  function htmlBox() {
    return document.getElementById("gf-blog-html");
  }

  function remember(value) {
    if (typeof value !== "string") return;
    window.__gfBlogHtml = value;
    var box = htmlBox();
    if (box) box.setAttribute("data-gf-edited", "1");
  }

  function forget() {
    window.__gfBlogHtml = null;
    var box = htmlBox();
    if (!box) return;
    box.removeAttribute("data-gf-edited");
  }

  document.addEventListener("input", function (event) {
    var box = event.target;
    if (!box || box.id !== "gf-blog-html") return;
    remember(box.value);
  }, true);

  document.addEventListener("click", function (event) {
    var button = event.target && event.target.closest ? event.target.closest("button") : null;
    if (!button) return;
    var label = (button.textContent || "").replace(/\s+/g, " ").trim();
    if (label === "Edit") {
      forget();
      return;
    }
    if (label !== "HTML") return;
    setTimeout(function () {
      var box = htmlBox();
      if (!box || box.style.display === "none") {
        if (box) remember(box.value);
        return;
      }
    }, 0);
  }, true);

  function pick() {
    if (typeof window.__gfBlogHtml === "string") return window.__gfBlogHtml;
    var box = htmlBox();
    if (!box) return null;
    if (box.style.display !== "none" || box.getAttribute("data-gf-edited") === "1") return box.value;
    return null;
  }

  function apply(body) {
    var html = pick();
    if (html == null || !(body instanceof FormData) || typeof body.has !== "function" || !body.has("content")) return body;
    body.set("content", html);
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
