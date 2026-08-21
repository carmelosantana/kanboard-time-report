// TimeReport — CSP-safe, event-delegated clipboard copy of the Markdown payload.
(function () {
    "use strict";
    document.addEventListener("click", function (e) {
        var btn = e.target.closest("[data-tr-copy]");
        if (!btn) {
            return;
        }
        e.preventDefault();
        var ta = document.getElementById("tr-markdown");
        if (!ta) {
            return;
        }
        var text = ta.value;
        var done = function () {
            var original = btn.textContent;
            btn.textContent = btn.getAttribute("data-tr-copied") || "Copied";
            setTimeout(function () { btn.textContent = original; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {
                ta.removeAttribute("aria-hidden");
                ta.select();
                try { document.execCommand("copy"); done(); } catch (err) { /* no-op */ }
            });
        } else {
            ta.removeAttribute("aria-hidden");
            ta.select();
            try { document.execCommand("copy"); done(); } catch (err) { /* no-op */ }
        }
    });
})();
