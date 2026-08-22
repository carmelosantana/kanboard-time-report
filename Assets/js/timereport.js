// TimeReport — CSP-safe, event-delegated clipboard helpers:
//   [data-tr-copy]    → copy the full Markdown payload (#tr-markdown)
//   [data-tr-copyval] → copy a single value (e.g. an hours figure) with a flash + badge
(function () {
    "use strict";

    function fallbackCopy(text) {
        var tmp = document.createElement("textarea");
        tmp.value = text;
        tmp.setAttribute("readonly", "");
        tmp.style.position = "absolute";
        tmp.style.left = "-9999px";
        document.body.appendChild(tmp);
        tmp.select();
        try { document.execCommand("copy"); } catch (err) { /* no-op */ }
        document.body.removeChild(tmp);
    }

    function copyText(text, onDone) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(onDone).catch(function () {
                fallbackCopy(text);
                onDone();
            });
        } else {
            fallbackCopy(text);
            onDone();
        }
    }

    function flashValue(cell) {
        cell.classList.add("tr-copy-flash");
        setTimeout(function () { cell.classList.remove("tr-copy-flash"); }, 600);
        var badge = document.createElement("span");
        badge.className = "tr-copy-badge";
        badge.textContent = cell.getAttribute("data-tr-copied") || "Copied ✓";
        cell.appendChild(badge);
        setTimeout(function () {
            if (badge.parentNode) { badge.parentNode.removeChild(badge); }
        }, 1200);
    }

    function copyValue(cell) {
        var val = cell.getAttribute("data-tr-copyval");
        if (val === null) { return; }
        copyText(val, function () { flashValue(cell); });
    }

    function copyMarkdown(btn) {
        var ta = document.getElementById("tr-markdown");
        if (!ta) { return; }
        copyText(ta.value, function () {
            var original = btn.textContent;
            btn.textContent = btn.getAttribute("data-tr-copied") || "Copied";
            setTimeout(function () { btn.textContent = original; }, 1500);
        });
    }

    document.addEventListener("click", function (e) {
        var valCell = e.target.closest("[data-tr-copyval]");
        if (valCell) {
            e.preventDefault();
            copyValue(valCell);
            return;
        }
        var btn = e.target.closest("[data-tr-copy]");
        if (btn) {
            e.preventDefault();
            copyMarkdown(btn);
        }
    });

    document.addEventListener("keydown", function (e) {
        if (e.key !== "Enter" && e.key !== " " && e.key !== "Spacebar") { return; }
        var valCell = e.target.closest ? e.target.closest("[data-tr-copyval]") : null;
        if (!valCell) { return; }
        e.preventDefault();
        copyValue(valCell);
    });
})();
