window.portalApi = async function (path, options = {}) {
  const root = document.body.dataset.root || '';
  const headers = { Accept: 'application/json', ...(options.headers || {}) };
  let body = options.body;
  if (body && !(body instanceof FormData) && typeof body !== 'string') {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(body);
  }

  let response;
  try {
    response = await fetch(`${root}api/${path}`, {
      ...options,
      headers,
      body,
      credentials: 'same-origin',
    });
  } catch {
    throw new Error('Cannot reach the portal API. Check that Apache and MySQL are running.');
  }

  let result;
  try {
    result = await response.json();
  } catch {
    throw new Error('The server returned an invalid response.');
  }
  if (response.status === 401 && path !== 'auth/login.php') {
    window.location.href = `${root}index.html`;
  }
  if (!response.ok) {
    const error = new Error(result.error || 'The request could not be completed.');
    error.status = response.status;
    error.data = result;
    throw error;
  }
  return result;
};