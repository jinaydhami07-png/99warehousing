/* ============================================================
   Buy Per Square Foot — client-side API layer  (CLIENT SIDE)
   ------------------------------------------------------------
   The single place the browser talks to the server. Every other
   client script goes through this, so swapping hosts or auth
   behaviour is a one-file change.

   Access token  → kept in memory + sessionStorage (short-lived)
   Refresh token → httpOnly cookie set by the server; JavaScript
                   cannot read it, which is the point.
   ============================================================ */
(function (global) {
  'use strict';

  /* The Express API is versioned and mounted at /api/v1 (see
     server/src/app.js). When the server serves these pages itself — the
     normal case, http://localhost:5000 — a relative base is correct and no
     CORS is involved. Override with window.BPSF_API_BASE to point at an API
     on another host. */
  var API_BASE = global.BPSF_API_BASE ||
    (location.protocol === 'file:' ? 'http://localhost:5000/api/v1' : '/api/v1');

  /* Where the API lives when the pages are being served by something else.
     Opening the site through Live Server, http-server or a bundler is common,
     and in that case the relative base above points at a port with no API on
     it. Without this the page would decide there is no backend at all and
     silently switch to demo data — so edits would appear to succeed while the
     database never changed. */
  var LOCAL_API = 'http://localhost:5000/api/v1';

  var isLocalHost = /^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname);

  /* True once a request has actually reached the real API in this session.
     After that, a later failure means the server is struggling — not that
     this is a static demo site — so writes must surface the error instead of
     being answered by the demo backend. */
  var apiConfirmed = false;

  if (location.protocol === 'file:') {
    console.warn(
      '[BPSF] Opened from the filesystem. Sign-in and uploads need cookies, ' +
      'which browsers block on file:// — open http://localhost:5000 instead.'
    );
  }

  var TOKEN_KEY = 'bpsf.access_token';
  var USER_KEY = 'bpsf.user';

  var accessToken = null;
  try { accessToken = sessionStorage.getItem(TOKEN_KEY); } catch (e) { /* private mode */ }

  function setSession(token, user) {
    accessToken = token || null;
    try {
      if (token) sessionStorage.setItem(TOKEN_KEY, token);
      else sessionStorage.removeItem(TOKEN_KEY);
      if (user) sessionStorage.setItem(USER_KEY, JSON.stringify(user));
      else sessionStorage.removeItem(USER_KEY);
    } catch (e) { /* ignore */ }
    global.dispatchEvent(new CustomEvent('bpsf:auth', { detail: { user: user || null } }));
  }

  function currentUser() {
    try { return JSON.parse(sessionStorage.getItem(USER_KEY) || 'null'); } catch (e) { return null; }
  }

  var isAuthed = function () { return !!accessToken; };
  var isAdmin = function () { var u = currentUser(); return !!u && u.role === 'admin'; };

  /* ── Response envelope ──
     The Express API wraps every response as
         { success, message, data, meta? }
     which is good server-side practice, but the pages were written against
     a flat shape ({ accessToken, user }, { items, total }, …).

     This is the adapter's job, so the unwrapping happens here once rather
     than forcing every page to reach through `.data`. Anything that is not
     our envelope (the demo backend, a third-party error body) passes
     through untouched.

       { data: { accessToken, user } }        → { accessToken, user }
       { data: [ … ], meta: { total, … } }    → { items: [ … ], total, … }
  */
  function unwrap(json) {
    if (!json || typeof json !== 'object' || Array.isArray(json)) return json;
    if (!Object.prototype.hasOwnProperty.call(json, 'success')) return json;

    var data = json.data;
    var meta = json.meta || {};

    if (Array.isArray(data)) {
      return Object.assign({ items: data }, meta);
    }
    if (data && typeof data === 'object') {
      return Object.assign({}, data, meta);
    }
    // No data payload (e.g. logout) — hand back the envelope itself.
    return json;
  }

  /* ── Error messages ──
     The Express API reports failures as
         { success: false, message, errors: [{ field, message }] }
     while the demo backend uses { error, details }. Read both, and prefer
     the specific field-level complaint ("Rate is required") over the generic
     summary ("Validation failed") — that is the part a user can act on.

     Without this the pages fell back to "Request failed (422)" for every
     rejected form, which tells the user nothing about what to change. */
  function errorMessage(data, status, fallback) {
    if (data && typeof data === 'object') {
      var list = data.errors || data.details;
      if (Array.isArray(list) && list.length) {
        var first = list[0];
        var text = typeof first === 'string' ? first : first && first.message;
        if (text) return first && first.field ? first.field + ': ' + text : text;
      }
      if (data.error) return data.error;
      if (data.message) return data.message;
    }
    return fallback || 'Request failed (' + status + ')';
  }

  /* ── Offline fallback ──
     When the API can't be reached (backend not started yet), route the
     same call to the local demo backend so every page keeps working.
     A real HTTP error (400/401/404…) is NOT a fallback case — that is a
     genuine answer from the server and must surface to the caller. */
  var demoMode = false;

  function splitPath(path) {
    var i = path.indexOf('?');
    if (i === -1) return { path: path, query: {} };
    var q = {};
    new URLSearchParams(path.slice(i + 1)).forEach(function (v, k) { q[k] = v; });
    return { path: path.slice(0, i), query: q };
  }

  function toDemo(path, options) {
    if (!global.BPSFDemo) throw new Error('Cannot reach the server.');

    /* ── Never fake a save ──
       Reading demo data when no backend exists is useful: the site still
       demonstrates itself. Answering a WRITE from the demo backend after the
       real API has already been talking to us is not — the moderator sees
       "Property approved", the counters move, and the database never hears
       about it. That is indistinguishable from success and loses their work.

       So once the real API has been confirmed this session, a failed write
       raises the failure instead of being quietly absorbed. Reads may still
       fall back, because stale data on screen is obvious and harmless. */
    var method = (options.method || 'GET').toUpperCase();
    if (apiConfirmed && method !== 'GET') {
      var e = new Error(
        'Could not reach the server, so this change was NOT saved. ' +
        'Check that the API is running, then try again.'
      );
      e.status = 0;
      e.notSaved = true;
      throw e;
    }

    if (!demoMode) {
      demoMode = true;
      console.info('[BPSF] API unreachable — running on the local demo backend.');
      if (document.body) BPSFDemo.showBanner();
      else document.addEventListener('DOMContentLoaded', BPSFDemo.showBanner);
    }
    var parts = splitPath(path);
    return BPSFDemo.handle(options.method || 'GET', parts.path, options.body, parts.query)
      .then(function (data) {
        // Keep the client session in step with the demo session.
        if (data && data.accessToken) setSession(data.accessToken, data.user);
        if (path === '/auth/logout') setSession(null, null);
        return data;
      });
  }

  /* How long to wait for the API before deciding it isn't there.
     Without this a request can hang forever (notably from file://,
     where the browser may never settle the promise) and the page
     would sit on a spinner instead of falling back to demo data. */
  var API_TIMEOUT_MS = 3500;

  /* Probe the standard dev port. If the API answers there, adopt it as the
     base for the rest of the session.

     The result is memoised as a PROMISE, not a boolean flag, because pages
     fire several requests at once (the dashboard opens with five). With a
     plain "already tried" flag the first caller starts the probe and every
     other caller sees the flag set, skips the probe, and drops to demo mode
     — which is sticky, so the whole page ends up on demo data even though
     the probe was about to succeed. Sharing one promise makes the others
     wait for the same answer. */
  var localApiProbe = null;

  function adoptLocalApi() {
    if (localApiProbe) return localApiProbe;

    localApiProbe = fetch(LOCAL_API + '/health', { credentials: 'include' })
      .then(function (r) {
        if (!r.ok) return false;
        return r.json().then(function (j) {
          if (!j || j.success !== true) return false;
          API_BASE = LOCAL_API;
          API.base = LOCAL_API;
          /* A racing request may already have flipped this on. The API is
             there after all, so undo that — otherwise every later call keeps
             short-circuiting to demo data. */
          demoMode = false;
          console.info('[BPSF] API found at ' + LOCAL_API + ' — using it instead of this origin.');
          return true;
        });
      })
      .catch(function () { return false; });

    return localApiProbe;
  }

  /* ── Core request ──
     On a 401 we try the refresh cookie exactly once, then replay the
     original request. `_retried` stops that becoming an infinite loop. */
  function request(path, options, _retried) {
    options = options || {};

    // Already known to be offline — don't re-attempt the network every call.
    if (demoMode) return toDemo(path, options);

    // Opened straight off disk: cookies and CORS can't work, so don't even try.
    if (location.protocol === 'file:') return toDemo(path, options);

    var headers = Object.assign({}, options.headers || {});
    if (options.body !== undefined && !(options.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
    }
    if (accessToken) headers.Authorization = 'Bearer ' + accessToken;

    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = setTimeout(function () { if (controller) controller.abort(); }, API_TIMEOUT_MS);

    return fetch(API_BASE + path, {
      method: options.method || 'GET',
      headers: headers,
      credentials: 'include', // send/receive the refresh cookie
      signal: controller ? controller.signal : undefined,
      body:
        options.body === undefined || options.body instanceof FormData
          ? options.body
          : JSON.stringify(options.body),
    }).then(function (res) {
      clearTimeout(timer);
      if (res.status === 204) return null;

      /* ── No backend at this origin ──
         On a static host (GitHub Pages, Netlify, S3) there is no API, so
         every /api/v1/… request is answered by the host's own 404 page.
         That is an HTTP error, not a network failure, so the offline
         fallback below never fired and the whole site appeared broken:
         sign-in, favourites and the contact form all failed with a bare
         "Request failed (404)".

         The tell is the body. A real API 404 ("no property with that id")
         comes back as our JSON envelope; a static host answers with HTML.
         So only a NON-JSON 404/405 means "there is no API here" — a
         genuine 404 from a live backend still surfaces to the caller. */
      if (res.status === 404 || res.status === 405) {
        var ctype = res.headers.get('content-type') || '';
        if (ctype.indexOf('json') === -1) {
          /* Before writing the origin off, check the usual dev port. The
             pages are often opened through Live Server or similar, where the
             relative base points at a server that only has files on it while
             the real API is running on 5000 all along. */
          if (isLocalHost && API_BASE !== LOCAL_API) {
            return adoptLocalApi().then(function (found) {
              return found ? request(path, options, _retried) : toDemo(path, options);
            });
          }
          return toDemo(path, options);
        }
      }

      // Reached the real API — from here on, writes must not be faked.
      apiConfirmed = true;

      return res
        .json()
        .catch(function () { return {}; })
        .then(function (data) {
          if (res.ok) return unwrap(data);

          if (res.status === 401 && !_retried && path.indexOf('/auth/') !== 0) {
            return refresh().then(
              function () { return request(path, options, true); },
              function () {
                setSession(null, null);
                var err = new Error(errorMessage(data, res.status, 'Session expired. Please sign in again.'));
                err.status = 401;
                throw err;
              }
            );
          }

          var err = new Error(errorMessage(data, res.status));
          err.status = res.status;
          err.details = data.errors || data.details;
          throw err;
        });
    },
    function () {
      // fetch() rejected or timed out → no server reachable
      clearTimeout(timer);
      return toDemo(path, options);
    });
  }

  function refresh() {
    if (demoMode || location.protocol === 'file:') return toDemo('/auth/refresh', { method: 'POST' });

    var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var timer = setTimeout(function () { if (controller) controller.abort(); }, API_TIMEOUT_MS);

    return fetch(API_BASE + '/auth/refresh', {
      method: 'POST',
      credentials: 'include',
      signal: controller ? controller.signal : undefined
    })
      .then(function (r) {
        clearTimeout(timer);
        if (!r.ok) throw new Error('refresh failed');
        return r.json();
      }, function () {
        clearTimeout(timer);
        return toDemo('/auth/refresh', { method: 'POST' });
      })
      .then(function (d) { if (d && d.accessToken) setSession(d.accessToken, d.user); return d; });
  }

  var qs = function (params) {
    var parts = [];
    Object.keys(params || {}).forEach(function (k) {
      var v = params[k];
      if (v !== undefined && v !== null && v !== '') {
        parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
      }
    });
    return parts.length ? '?' + parts.join('&') : '';
  };

  var API = {
    base: API_BASE,
    isAuthed: isAuthed,
    isAdmin: isAdmin,
    currentUser: currentUser,
    setSession: setSession,
    refresh: refresh,

    auth: {
      register: function (payload) {
        return request('/auth/register', { method: 'POST', body: payload })
          .then(function (d) { setSession(d.accessToken, d.user); return d; });
      },
      login: function (email, password) {
        return request('/auth/login', { method: 'POST', body: { email: email, password: password } })
          .then(function (d) { setSession(d.accessToken, d.user); return d; });
      },
      adminLogin: function (passkey) {
        return request('/auth/admin', { method: 'POST', body: { passkey: passkey } })
          .then(function (d) { setSession(d.accessToken, d.user); return d; });
      },
      me: function () { return request('/auth/me'); },
      logout: function () {
        return request('/auth/logout', { method: 'POST' })
          .catch(function () { /* log out locally even if the call fails */ })
          .then(function () { setSession(null, null); });
      },
      /* Full-page redirect — OAuth cannot run inside fetch(). */
      googleUrl: function () { return API_BASE + '/auth/google'; },
    },

    properties: {
      list: function (filters) { return request('/properties' + qs(filters)); },
      get: function (id) { return request('/properties/' + id); },
      create: function (payload) { return request('/properties', { method: 'POST', body: payload }); },
      update: function (id, patch) { return request('/properties/' + id, { method: 'PATCH', body: patch }); },
      remove: function (id) { return request('/properties/' + id, { method: 'DELETE' }); },
      mine: function () { return request('/properties/mine'); },
    },

    /* ── Image / document upload ──
       Upload first, then attach the returned objects to a property:

         var fd = new FormData();
         for (var i = 0; i < input.files.length; i++) fd.append('files', input.files[i]);
         API.upload(fd).then(function (res) {
           return API.properties.create({ name: …, rate: …, area: …, images: res.files });
         });

       Content-Type is deliberately not set — the browser must add the
       multipart boundary itself. */
    upload: function (formData) {
      return request('/upload', { method: 'POST', body: formData });
    },

    images: {
      /* The bytes live in MongoDB and are served by the API itself, so an
         image URL is just a path — usable directly in <img src>. */
      url: function (publicId) { return API_BASE + '/images/' + publicId; },
      remove: function (publicId) {
        return request('/images/' + publicId, { method: 'DELETE' });
      },
    },

    favorites: {
      list: function () { return request('/favorites'); },
      add: function (id) { return request('/favorites/' + id, { method: 'POST' }); },
      remove: function (id) { return request('/favorites/' + id, { method: 'DELETE' }); },
    },

    enquiries: {
      create: function (payload) { return request('/enquiries', { method: 'POST', body: payload }); },
      mine: function () { return request('/enquiries/mine'); },
    },

    admin: {
      stats: function () { return request('/admin/stats'); },
      properties: function (status, q) { return request('/admin/properties' + qs({ status: status, q: q })); },
      approve: function (id) { return request('/admin/properties/' + id + '/approve', { method: 'PATCH' }); },
      reject: function (id, reason) {
        return request('/admin/properties/' + id + '/reject', { method: 'PATCH', body: { reason: reason } });
      },
      create: function (payload) { return request('/admin/properties', { method: 'POST', body: payload }); },
      update: function (id, patch) { return request('/admin/properties/' + id, { method: 'PATCH', body: patch }); },
      remove: function (id) { return request('/admin/properties/' + id, { method: 'DELETE' }); },
      users: function () { return request('/admin/users'); },
      updateUser: function (id, patch) { return request('/admin/users/' + id, { method: 'PATCH', body: patch }); },
      enquiries: function () { return request('/admin/enquiries'); },
      audit: function () { return request('/admin/audit'); },
    },

    config: function () { return request('/config'); },
    health: function () { return request('/health'); },
  };

  /* Google redirects back with #token=… — consume it, then strip it from
     the URL so the token isn't left sitting in the address bar or history. */
  (function captureOAuthToken() {
    if (!location.hash || location.hash.indexOf('token=') === -1) return;
    var token = new URLSearchParams(location.hash.slice(1)).get('token');
    if (!token) return;
    accessToken = token;
    try { sessionStorage.setItem(TOKEN_KEY, token); } catch (e) {}
    history.replaceState(null, '', location.pathname + location.search);
    API.auth.me()
      .then(function (d) { setSession(token, d.user); })
      .catch(function () { setSession(null, null); });
  })();

  global.API = API;
})(window);
