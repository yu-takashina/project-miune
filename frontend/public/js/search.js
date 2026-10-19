const form = document.querySelector('#search-form');
const queryInput = document.querySelector('#q');
const message = document.querySelector('#message');
const results = document.querySelector('#results');
const submit = document.querySelector('#submit');
const template = document.querySelector('#result-template');

const titleSuffix = 'リナビス';

const initialQuery = new URLSearchParams(location.search).get('q') ?? '';
queryInput.value = initialQuery;

if (initialQuery.trim() !== '') {
    search(initialQuery, false);
}

form.addEventListener('submit', (event) => {
    event.preventDefault();
    const query = queryInput.value;
    writeQuery(query);
    search(query, true);
});

window.addEventListener('popstate', () => {
    const query = new URLSearchParams(location.search).get('q') ?? '';
    queryInput.value = query;
    search(query, false);
});

function writeQuery(query) {
    const url = new URL(location.href);
    const trimmed = query.trim();

    if (trimmed === '') {
        url.searchParams.delete('q');
    } else {
        url.searchParams.set('q', trimmed);
    }

    history.pushState({}, '', url);
}

async function search(rawQuery, submitted) {
    const query = rawQuery.trim();

    results.replaceChildren();
    hideMessage();
    document.title = query === '' ? `検索｜${titleSuffix}` : `「${query}」の検索結果｜${titleSuffix}`;

    if (query === '') {
        if (submitted) {
            showMessage('検索語を入力してください。', true);
        }

        return;
    }

    submit.disabled = true;

    try {
        const response = await fetch(`/search.php?${new URLSearchParams({ q: query })}`, {
            headers: {
                Accept: 'application/json',
            },
        });
        const body = await readJson(response);

        if (! response.ok) {
            showMessage(messageOf(body), true);

            return;
        }

        if (! Array.isArray(body) || body.length === 0) {
            showMessage('お役に立つ記事が見つかりませんでした。', false);

            return;
        }

        for (const item of body) {
            results.append(renderResult(item));
        }
    } catch {
        showMessage('検索に失敗しました。', true);
    } finally {
        submit.disabled = false;
    }
}

async function readJson(response) {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

function messageOf(body) {
    if (body !== null && typeof body === 'object' && typeof body.message === 'string' && body.message !== '') {
        return body.message;
    }

    return '検索に失敗しました。';
}

function renderResult(item) {
    const view = template.content.cloneNode(true);
    const link = view.querySelector('.result-url');
    const urlText = view.querySelector('.result-url-text');
    const text = view.querySelector('.result-text');
    const url = item !== null && typeof item === 'object' && typeof item.url === 'string' ? item.url : '';
    const article = item !== null && typeof item === 'object' && typeof item.article_text === 'string' ? item.article_text : '';

    urlText.textContent = compact(url);

    if (isHttpUrl(url)) {
        link.href = url;
    } else {
        link.removeAttribute('href');
    }

    const compactArticle = compact(article);

    if (compactArticle === '') {
        text.remove();
    } else {
        text.textContent = compactArticle;
    }

    return view;
}

function compact(value) {
    return value.replace(/\s+/g, '');
}

function isHttpUrl(value) {
    try {
        const url = new URL(value);

        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
}

function showMessage(text, isError) {
    message.hidden = false;
    message.dataset.error = isError ? 'true' : 'false';
    message.textContent = text;

    if (isError) {
        message.setAttribute('role', 'alert');
    } else {
        message.removeAttribute('role');
    }
}

function hideMessage() {
    message.hidden = true;
    message.textContent = '';
    delete message.dataset.error;
    message.removeAttribute('role');
}
