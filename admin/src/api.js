const csrfToken = () => {
  const row = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));

  return row ? decodeURIComponent(row.split('=').slice(1).join('=')) : '';
};

export async function api(url, method = 'GET', body = null) {
  const response = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': csrfToken(),
    },
    body: body === null ? null : JSON.stringify(body),
  });

  const data = await response.json().catch(() => ({}));

  if (response.status === 401) {
    const error = new Error('unauthenticated');
    error.unauthenticated = true;

    throw error;
  }

  if (!response.ok) {
    const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;

    throw new Error(firstError || data.message || data.error || `HTTP ${response.status}`);
  }

  return data;
}