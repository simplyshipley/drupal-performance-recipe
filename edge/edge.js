// Edge njs helpers: Front Door style cache key and purge. LAB ONLY.
import fs from 'fs';

function paramList(s) {
  return (s || '').split(',').map((x) => x.trim().toLowerCase()).filter((x) => x.length);
}

// Query-string part of the cache key for the route's mode. Front Door ignores
// parameter order, so parameters are sorted.
function qsKey(r) {
  const mode = r.variables.edge_qs_mode || 'use';
  const raw = r.variables.args || '';
  if (mode === 'ignore' || raw === '') {
    return '';
  }
  let parts = raw.split('&').filter((p) => p.length);
  const name = (p) => p.split('=')[0].toLowerCase();
  if (mode === 'ignore-specified') {
    const drop = paramList(r.variables.edge_qs_ignore_params);
    parts = parts.filter((p) => drop.indexOf(name(p)) < 0);
  } else if (mode === 'include-specified') {
    const keep = paramList(r.variables.edge_qs_include_params);
    parts = parts.filter((p) => keep.indexOf(name(p)) >= 0);
  }
  parts.sort();
  return parts.join('&');
}

// Key = host[:port] + path [+ '?' + normalised query].
function cacheKey(r) {
  const path = (r.variables.request_uri || '/').split('?')[0];
  const q = qsKey(r);
  return (r.headersIn.Host || r.variables.host || '').toLowerCase() + path + (q ? '?' + q : '');
}

// Front Door purge semantics: case-insensitive, query-string agnostic;
// "/*" = everything, "/dir/*" = prefix, otherwise the exact path.
function matches(keyPath, pattern) {
  const p = pattern.toLowerCase();
  const k = keyPath.toLowerCase();
  if (p === '/*') {
    return true;
  }
  if (p.endsWith('/*')) {
    return k.startsWith(p.slice(0, -1));
  }
  return k === p;
}

function keyOf(file) {
  // The nginx cache file header holds "\nKEY: <key>\n" before the response.
  const buf = fs.readFileSync(file);
  const i = buf.indexOf('\nKEY: ');
  if (i < 0 || i > 4096) {
    return null;
  }
  const j = buf.indexOf('\n', i + 6);
  return buf.slice(i + 6, j < 0 ? buf.length : j).toString();
}

function walk(dir, out) {
  let entries = [];
  try {
    entries = fs.readdirSync(dir, { withFileTypes: true });
  } catch (e) {
    return;
  }
  for (let n = 0; n < entries.length; n++) {
    const e = entries[n];
    const p = dir + '/' + e.name;
    if (e.isDirectory()) {
      walk(p, out);
    } else {
      out.push(p);
    }
  }
}

// POST /__edge/purge {"contentPaths": ["/node/1", "/sites/*"]}
function purge(r) {
  if (r.method !== 'POST') {
    r.return(405, 'POST only\n');
    return;
  }
  let body;
  try {
    body = JSON.parse(r.requestText || '{}');
  } catch (e) {
    r.return(400, JSON.stringify({ error: 'invalid JSON' }) + '\n');
    return;
  }
  const paths = Array.isArray(body.contentPaths) ? body.contentPaths : [];
  const files = [];
  walk(r.variables.edge_cache_dir || '/var/cache/nginx/edge', files);
  const purged = [];
  let errors = 0;
  for (let n = 0; n < files.length; n++) {
    const f = files[n];
    let key;
    try {
      key = keyOf(f);
    } catch (e) {
      continue;
    }
    if (!key) {
      continue;
    }
    const slash = key.indexOf('/');
    const keyPath = (slash < 0 ? '/' : key.slice(slash)).split('?')[0];
    if (paths.some((p) => typeof p === 'string' && matches(keyPath, p))) {
      try {
        fs.unlinkSync(f);
        purged.push(key);
      } catch (e) {
        errors++;
      }
    }
  }
  r.headersOut['Content-Type'] = 'application/json';
  r.return(200, JSON.stringify({ contentPaths: paths, scanned: files.length, purged: purged, errors: errors }) + '\n');
}

export default { cacheKey, purge };
