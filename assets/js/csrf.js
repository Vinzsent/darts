/* ── CSRF token auto-attachment ──────────────────────────────────────────
   The session token is emitted in <meta name="csrf-token"> by header.php,
   and enforced server-side by includes/auth.php on every POST request.
   This script makes sure every outgoing POST carries it:

     1. Hidden csrf_token input is injected into every POST <form> on the
        page (including forms inside modals).
     2. HTMLFormElement.prototype.submit is patched, so forms created
        dynamically after load (and submitted programmatically) also get
        the token.
     3. Every jQuery $.ajax POST gets an X-CSRF-Token header (attached as
        soon as jQuery becomes available — it may load later in the page).
     4. Every fetch() POST gets the same header.

   Server side accepts the token from the POST field OR the header. */

(function () {
    var meta = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? meta.content : '';
    if (!token) return; // nothing to attach (defensive)

    var NAME = 'csrf_token';
    var HEADER = 'X-CSRF-Token';

    function ensureToken(form) {
        if (!form || form.querySelector('input[name="' + NAME + '"]')) return;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = NAME;
        input.value = token;
        form.appendChild(input);
    }

    // 1) Inject into every existing POST form once the DOM is ready.
    function injectAll() {
        var forms = document.querySelectorAll('form');
        for (var i = 0; i < forms.length; i++) {
            var m = (forms[i].getAttribute('method') || 'GET').toUpperCase();
            if (m === 'POST') ensureToken(forms[i]);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectAll);
    } else {
        injectAll();
    }

    // 2) Cover programmatic submits of dynamically created forms.
    if (HTMLFormElement.prototype.submit) {
        var originalSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function () {
            var m = (this.getAttribute('method') || 'GET').toUpperCase();
            if (m === 'POST') ensureToken(this);
            return originalSubmit.apply(this, arguments);
        };
    }

    // 3) jQuery: attach as soon as jQuery appears (it often loads after
    //    this script, mid-page). Poll briefly, stop once attached.
    var jqueryTries = 0;
    (function attachJquery() {
        if (window.jQuery) {
            jQuery.ajaxSetup({
                beforeSend: function (xhr, settings) {
                    if ((settings.type || 'GET').toUpperCase() === 'POST') {
                        xhr.setRequestHeader(HEADER, token);
                    }
                }
            });
            return;
        }
        if (++jqueryTries <= 200) {
            setTimeout(attachJquery, 100); // keep watching for up to ~20s
        }
    })();

    // 4) fetch(): wrap so every POST carries the header too.
    if (window.fetch) {
        var originalFetch = window.fetch;
        window.fetch = function (input, init) {
            init = init || {};
            var method = (init.method || (input && input.method) || 'GET').toUpperCase();
            if (method === 'POST') {
                init.headers = Object.assign({}, init.headers || {});
                if (!init.headers[HEADER]) {
                    init.headers[HEADER] = token;
                }
            }
            return originalFetch.call(this, input, init);
        };
    }
})();
