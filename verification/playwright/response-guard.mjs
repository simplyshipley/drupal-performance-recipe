// Playwright routing is not repeated for redirect hops. Never forward Location.
export async function fulfillWithoutRedirects(route, { timeoutMs, inspect = () => {} }) {
  let response;
  try {
    response = await route.fetch({ maxRedirects: 0, maxRetries: 0, timeout: timeoutMs });
    const status = response.status(), headers = response.headers();
    const redirect = status >= 300 && status < 400;
    await inspect({ status, headers, redirect });
    if (redirect) await route.abort();
    else await route.fulfill({ response, headers });
    return { status, redirect };
  } finally { await response?.dispose().catch(() => {}); }
}
