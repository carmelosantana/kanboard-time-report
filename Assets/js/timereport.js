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
        var existing = cell.querySelector(".tr-copy-badge");
        if (existing && existing.parentNode) { existing.parentNode.removeChild(existing); }
        var badge = document.createElement("span");
        badge.className = "tr-copy-badge";
        var label = cell.getAttribute("data-tr-copied") || "Copied";
        badge.textContent = label + " ✓";
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
        var text = null;
        // Prefer the client-side assembler so loaded per-row summaries are included;
        // fall back to the server-rendered payload when the summaries module is absent.
        if (window.TimeReportSummaries && window.TimeReportSummaries.assembleMarkdown) {
            text = window.TimeReportSummaries.assembleMarkdown();
        } else {
            var ta = document.getElementById("tr-markdown");
            if (ta) { text = ta.value; }
        }
        if (text === null) { return; }
        copyText(text, function () {
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

// TimeReport — per-row AI summaries: lazy expand, cache-backed load, stale badge,
// regenerate. CSP-safe: external file, event-delegated, no inline handlers. The
// request context (URL, CSRF token, project/range/scope) lives on #tr-summary-context.
(function () {
    "use strict";

    function context() {
        return document.getElementById("tr-summary-context");
    }

    function summaryRow(rowKey) {
        var rows = document.querySelectorAll(".tr-summary-row[data-row-key]");
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].getAttribute("data-row-key") === rowKey) { return rows[i]; }
        }
        return null;
    }

    function setIcon(btn, expanded, hasSummary) {
        var icon = btn.querySelector("i");
        if (!icon) { return; }
        icon.className = expanded
            ? "fa fa-chevron-down"
            : (hasSummary ? "fa fa-magic" : "fa fa-chevron-right");
    }

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) { node.className = cls; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    // Render the loaded summary into the panel. All model text via textContent (XSS-safe).
    function renderPanel(panel, json, rowKey) {
        var ctx = context();
        panel.innerHTML = "";

        if (json && json.error) {
            panel.appendChild(el("p", "tr-summary-error", json.error));
            panel.setAttribute("data-loaded", "1");
            return;
        }

        var summary = (json && json.summary) || "";
        var highlights = (json && json.highlights) || [];

        if (!summary && highlights.length === 0) {
            panel.appendChild(el("p", "tr-ai-note", ctx ? ctx.getAttribute("data-empty") : "No summary available."));
            panel.setAttribute("data-loaded", "1");
            return;
        }

        if (json && json.stale) {
            var badge = el("span", "tr-stale-badge", ctx ? ctx.getAttribute("data-outdated") : "may be outdated");
            panel.appendChild(badge);
        }

        if (summary) { panel.appendChild(el("p", "tr-summary-text", summary)); }

        if (highlights.length > 0) {
            var ul = el("ul", "tr-summary-highlights");
            highlights.forEach(function (h) { ul.appendChild(el("li", null, h)); });
            panel.appendChild(ul);
        }

        var actions = el("div", "tr-summary-actions");
        var regen = el("button", "btn tr-regenerate", ctx ? ctx.getAttribute("data-regenerate") : "Regenerate");
        regen.setAttribute("type", "button");
        regen.setAttribute("data-tr-regenerate", "");
        regen.setAttribute("data-row-key", rowKey);
        actions.appendChild(regen);

        var copy = el("button", "btn tr-copy-summary", "Copy");
        copy.setAttribute("type", "button");
        copy.setAttribute("data-tr-copy-summary", "");
        actions.appendChild(copy);
        panel.appendChild(actions);

        panel.setAttribute("data-loaded", "1");
    }

    // POST to the row-summary endpoint. Returns the fetch promise so callers (e.g.
    // "Generate all") can sequence. force=true triggers a regenerate.
    function loadSummary(panel, rowKey, force) {
        var ctx = context();
        if (!ctx || !panel) { return Promise.resolve(); }

        panel.innerHTML = "";
        panel.appendChild(el("p", "tr-ai-note", ctx.getAttribute("data-loading")));

        var data = new FormData(ctx);
        data.set("row_key", rowKey);
        if (force) { data.set("force", "1"); }

        return fetch(ctx.getAttribute("data-url"), {
            method: "POST",
            body: data,
            headers: { "X-Requested-With": "XMLHttpRequest" }
        })
        .then(function (resp) { return resp.json(); })
        .then(function (json) {
            renderPanel(panel, json, rowKey);
            markLoaded(rowKey);
        })
        .catch(function () {
            panel.innerHTML = "";
            panel.appendChild(el("p", "tr-summary-error", ctx.getAttribute("data-error")));
        });
    }

    // Flip the row's toggle button to the "cached" affordance once content exists.
    function markLoaded(rowKey) {
        var btn = toggleFor(rowKey);
        if (btn) {
            btn.classList.add("tr-has-summary");
            setIcon(btn, btn.getAttribute("aria-expanded") === "true", true);
        }
    }

    function toggleFor(rowKey) {
        var btns = document.querySelectorAll("[data-tr-row-toggle]");
        for (var i = 0; i < btns.length; i++) {
            if (btns[i].getAttribute("data-row-key") === rowKey) { return btns[i]; }
        }
        return null;
    }

    function toggle(btn) {
        var rowKey = btn.getAttribute("data-row-key");
        var row = summaryRow(rowKey);
        if (!row) { return; }

        var willExpand = row.hasAttribute("hidden");
        if (willExpand) { row.removeAttribute("hidden"); } else { row.setAttribute("hidden", ""); }
        btn.setAttribute("aria-expanded", willExpand ? "true" : "false");

        var panel = row.querySelector(".tr-summary-panel");
        var hasSummary = panel && panel.getAttribute("data-loaded") === "1";
        setIcon(btn, willExpand, hasSummary || btn.classList.contains("tr-has-summary"));

        if (willExpand && panel && panel.getAttribute("data-loaded") !== "1") {
            loadSummary(panel, rowKey, false);
        }
    }

    document.addEventListener("click", function (e) {
        var editBtn = e.target.closest("[data-tr-edit-filters]");
        if (editBtn) {
            e.preventDefault();
            var filters = document.getElementById("tr-filters");
            if (filters) {
                var open = filters.hasAttribute("hidden");
                if (open) { filters.removeAttribute("hidden"); } else { filters.setAttribute("hidden", ""); }
                editBtn.setAttribute("aria-expanded", open ? "true" : "false");
            }
            return;
        }

        var genAllBtn = e.target.closest("[data-tr-generate-all]");
        if (genAllBtn) { e.preventDefault(); generateAll(genAllBtn); return; }

        var toggleBtn = e.target.closest("[data-tr-row-toggle]");
        if (toggleBtn) { e.preventDefault(); toggle(toggleBtn); return; }

        var regenBtn = e.target.closest("[data-tr-regenerate]");
        if (regenBtn) {
            e.preventDefault();
            var rowKey = regenBtn.getAttribute("data-row-key");
            var row = summaryRow(rowKey);
            var panel = row ? row.querySelector(".tr-summary-panel") : null;
            if (panel) { loadSummary(panel, rowKey, true); }
            return;
        }

        var copyBtn = e.target.closest("[data-tr-copy-summary]");
        if (copyBtn) {
            e.preventDefault();
            var panelEl = copyBtn.closest(".tr-summary-panel");
            if (panelEl) { copySummaryPanel(panelEl, copyBtn); }
        }
    });

    // Copy one panel's narrative + highlights as Markdown.
    function copySummaryPanel(panel, btn) {
        var parts = [];
        var text = panel.querySelector(".tr-summary-text");
        if (text) { parts.push(text.textContent); }
        var lis = panel.querySelectorAll(".tr-summary-highlights li");
        if (lis.length > 0) {
            parts.push("");
            for (var i = 0; i < lis.length; i++) { parts.push("- " + lis[i].textContent); }
        }
        var md = parts.join("\n");
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(md).catch(function () {});
        }
        var original = btn.textContent;
        btn.textContent = "Copied ✓";
        setTimeout(function () { btn.textContent = original; }, 1200);
    }

    // "Generate all summaries": fill every summarizable row, cache-respecting, with a
    // small concurrency cap and a live progress count. Rows already loaded are skipped.
    function generateAll(btn) {
        var toggles = document.querySelectorAll("[data-tr-row-toggle]");
        var keys = [];
        for (var i = 0; i < toggles.length; i++) { keys.push(toggles[i].getAttribute("data-row-key")); }
        if (keys.length === 0) { return; }

        var total = keys.length, done = 0, idx = 0, cap = 2;
        var progress = btn.getAttribute("data-progress") || "Generating…";
        var doneLabel = btn.getAttribute("data-done") || btn.textContent;
        btn.disabled = true;

        function update() { btn.textContent = progress + " (" + done + "/" + total + ")"; }
        update();

        function next() {
            if (idx >= keys.length) { return Promise.resolve(); }
            var key = keys[idx++];
            var row = summaryRow(key);
            var panel = row ? row.querySelector(".tr-summary-panel") : null;
            if (!panel || panel.getAttribute("data-loaded") === "1") {
                done++; update(); return next();
            }
            return loadSummary(panel, key, false).then(function () { done++; update(); return next(); });
        }

        var runners = [];
        for (var r = 0; r < Math.min(cap, keys.length); r++) { runners.push(next()); }
        Promise.all(runners).then(function () {
            btn.disabled = false;
            btn.textContent = doneLabel;
        });
    }

    // Assemble the copy-as-Markdown payload from the server-rendered base plus whatever
    // per-row summaries are currently loaded in the DOM (D8).
    function assembleMarkdown() {
        var base = "";
        var ta = document.getElementById("tr-markdown");
        if (ta) { base = ta.value; }

        var rows = document.querySelectorAll(".tr-summary-row");
        var sections = [];
        for (var i = 0; i < rows.length; i++) {
            var panel = rows[i].querySelector(".tr-summary-panel");
            if (!panel || panel.getAttribute("data-loaded") !== "1") { continue; }
            var text = panel.querySelector(".tr-summary-text");
            var lis = panel.querySelectorAll(".tr-summary-highlights li");
            if (!text && lis.length === 0) { continue; }

            var main = rows[i].previousElementSibling;
            var labelCell = main ? main.querySelector("td:not(.tr-expander-col):not(.tr-num)") : null;
            var label = labelCell ? labelCell.textContent.trim() : rows[i].getAttribute("data-row-key");

            var block = ["### " + label];
            if (text) { block.push(text.textContent); }
            if (lis.length > 0) {
                block.push("");
                for (var j = 0; j < lis.length; j++) { block.push("- " + lis[j].textContent); }
            }
            sections.push(block.join("\n"));
        }

        if (sections.length > 0) {
            base = base.replace(/\n+$/, "") + "\n\n## Row summaries\n\n" + sections.join("\n\n") + "\n";
        }
        return base;
    }

    // Exposed for the copy-as-Markdown assembler and reuse by other scripts (Task 10).
    window.TimeReportSummaries = { load: loadSummary, summaryRow: summaryRow, assembleMarkdown: assembleMarkdown };
})();
