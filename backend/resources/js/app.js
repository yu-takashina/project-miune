bindHelpPopovers();

document.addEventListener('click', (event) => {
    const target = event.target;

    if (! (target instanceof Element)) {
        return;
    }

    const toggle = target.closest('[data-search-toggle]');

    if (toggle instanceof HTMLButtonElement) {
        updateSearchTarget(toggle);

        return;
    }

    const crawl = target.closest('[data-crawl]');

    if (crawl instanceof HTMLButtonElement) {
        crawlNow(crawl);

        return;
    }

    const sitemapCrawl = target.closest('[data-sitemap-crawl]');

    if (sitemapCrawl instanceof HTMLButtonElement) {
        crawlSitemapNow(sitemapCrawl);

        return;
    }

    const absenceEdit = target.closest('[data-absence-edit]');

    if (absenceEdit instanceof HTMLButtonElement) {
        openAbsenceLimit(absenceEdit);

        return;
    }

    const sitemapDelete = target.closest('[data-sitemap-delete]');

    if (sitemapDelete instanceof HTMLButtonElement) {
        openSitemapDelete(sitemapDelete);

        return;
    }

    const sitemapDeleteCancel = target.closest('[data-sitemap-delete-cancel]');

    if (sitemapDeleteCancel instanceof HTMLButtonElement) {
        closeSitemapDelete();

        return;
    }

    const sitemapDeleteConfirm = target.closest('[data-sitemap-delete-confirm]');

    if (sitemapDeleteConfirm instanceof HTMLButtonElement) {
        confirmSitemapDelete(sitemapDeleteConfirm);

        return;
    }

    const apiKeyCopy = target.closest('[data-api-key-copy]');

    if (apiKeyCopy instanceof HTMLButtonElement) {
        copyApiKey(apiKeyCopy);

        return;
    }

    const apiKeyDelete = target.closest('[data-api-key-delete]');

    if (apiKeyDelete instanceof HTMLButtonElement) {
        openApiKeyDelete(apiKeyDelete);

        return;
    }

    const apiKeyDeleteCancel = target.closest('[data-api-key-delete-cancel]');

    if (apiKeyDeleteCancel instanceof HTMLButtonElement) {
        closeApiKeyDelete();

        return;
    }

    const apiKeyDeleteConfirm = target.closest('[data-api-key-delete-confirm]');

    if (apiKeyDeleteConfirm instanceof HTMLButtonElement) {
        confirmApiKeyDelete(apiKeyDeleteConfirm);
    }
});

document.addEventListener('change', (event) => {
    const target = event.target;

    if (target instanceof HTMLSelectElement && target.matches('[data-absence-select]')) {
        saveAbsenceLimit(target);
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }

    const target = event.target;

    if (! (target instanceof HTMLSelectElement) || ! target.matches('[data-absence-select]')) {
        return;
    }

    const group = target.closest('[data-absence]');

    if (group instanceof HTMLElement) {
        closeAbsenceLimit(group);
    }
});

document.addEventListener('focusout', (event) => {
    const target = event.target;

    if (! (target instanceof HTMLSelectElement) || ! target.matches('[data-absence-select]') || target.disabled) {
        return;
    }

    const group = target.closest('[data-absence]');

    if (group instanceof HTMLElement) {
        closeAbsenceLimit(group);
    }
});

function updateSearchTarget(button) {
    if (button.disabled) {
        return;
    }

    const row = button.closest('tr');
    const table = button.closest('table');
    const url = button.dataset.url;
    const token = table instanceof HTMLTableElement ? table.dataset.csrf : undefined;

    if (row === null || url === undefined || token === undefined) {
        return;
    }

    const searchable = button.getAttribute('aria-checked') !== 'true';

    button.disabled = true;
    setNotice(row, '[data-search-toggle-notice]', '');

    fetch(url, {
        method: 'PATCH',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
        },
        body: JSON.stringify({ searchable }),
    })
        .then(async (response) => {
            if (! response.ok) {
                return null;
            }

            return response.json();
        })
        .then((body) => {
            if (body === null || typeof body.searchable !== 'boolean') {
                setNotice(row, '[data-search-toggle-notice]', '切り替えられませんでした。');

                return;
            }

            button.setAttribute('aria-checked', body.searchable ? 'true' : 'false');

            if (body.searchable) {
                row.removeAttribute('data-search-off');
            } else {
                row.setAttribute('data-search-off', 'true');
            }
        })
        .catch(() => {
            setNotice(row, '[data-search-toggle-notice]', '切り替えられませんでした。');
        })
        .finally(() => {
            button.disabled = false;
        });
}

function crawlNow(button) {
    if (button.disabled) {
        return;
    }

    const row = button.closest('tr');
    const table = button.closest('table');
    const url = button.dataset.url;
    const token = table instanceof HTMLTableElement ? table.dataset.csrf : undefined;

    if (row === null || url === undefined || token === undefined) {
        return;
    }

    button.disabled = true;
    setNotice(row, '[data-crawl-notice]', '');

    fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': token,
        },
    })
        .then(async (response) => {
            if (! response.ok) {
                return null;
            }

            return response.json();
        })
        .then((body) => {
            if (body === null || typeof body.http_status === 'undefined') {
                setNotice(row, '[data-crawl-notice]', '巡回できませんでした。');

                return;
            }

            setCrawlStatus(row, body.http_status, body.error_message);
            setCellText(row, '[data-crawl-size]', body.response_size);
            setCellText(row, '[data-crawl-time]', body.crawled_at);
        })
        .catch(() => {
            setNotice(row, '[data-crawl-notice]', '巡回できませんでした。');
        })
        .finally(() => {
            button.disabled = false;
        });
}

function crawlSitemapNow(button) {
    if (button.disabled) {
        return;
    }

    const row = button.closest('tr');
    const table = button.closest('table');
    const url = button.dataset.url;
    const token = table instanceof HTMLTableElement ? table.dataset.csrf : undefined;

    if (row === null || url === undefined || token === undefined) {
        return;
    }

    const label = button.textContent;
    button.disabled = true;
    button.textContent = '巡回しています';
    setNotice(row, '[data-sitemap-crawl-notice]', '');

    fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': token,
        },
    })
        .then(async (response) => {
            if (! response.ok) {
                return null;
            }

            return response.json();
        })
        .then((body) => {
            if (body === null || typeof body.status_label !== 'string' || typeof body.pages_count !== 'number') {
                setNotice(row, '[data-sitemap-crawl-notice]', '巡回できませんでした。');

                return;
            }

            if (body.ran === false) {
                setNotice(row, '[data-sitemap-crawl-notice]', '別の巡回が進行中です。');

                return;
            }

            setNotice(row, '[data-sitemap-crawl-notice]', '');
            setSitemapStatus(row, body.status_label, body.error_message);
            setPagesCount(row, body.pages_count);
            setCellText(row, '[data-sitemap-crawled-at]', body.crawled_at);
        })
        .catch(() => {
            setNotice(row, '[data-sitemap-crawl-notice]', '巡回できませんでした。');
        })
        .finally(() => {
            button.disabled = false;
            button.textContent = label ?? '今すぐ巡回';
        });
}

function bindHelpPopovers() {
    const popovers = document.querySelectorAll('[data-help-popover]');

    popovers.forEach((popover) => {
        if (! (popover instanceof HTMLElement) || popover.id === '') {
            return;
        }

        const button = document.querySelector(`[popovertarget="${CSS.escape(popover.id)}"]`);

        if (! (button instanceof HTMLButtonElement)) {
            return;
        }

        popover.addEventListener('toggle', (event) => {
            if (! (event instanceof ToggleEvent)) {
                return;
            }

            const open = event.newState === 'open';

            button.setAttribute('aria-expanded', open ? 'true' : 'false');

            if (open) {
                placeHelpPopover(button, popover);
            }
        });
    });

    if (popovers.length === 0) {
        return;
    }

    const reposition = () => {
        popovers.forEach((popover) => {
            if (! (popover instanceof HTMLElement) || popover.id === '' || ! popover.matches(':popover-open')) {
                return;
            }

            const button = document.querySelector(`[popovertarget="${CSS.escape(popover.id)}"]`);

            if (button instanceof HTMLButtonElement) {
                placeHelpPopover(button, popover);
            }
        });
    };

    document.addEventListener('scroll', reposition, true);
    window.addEventListener('resize', reposition);
}

function placeHelpPopover(button, popover) {
    const margin = 8;
    const rect = button.getBoundingClientRect();
    const width = Math.min(320, window.innerWidth - margin * 2);

    popover.style.width = `${width}px`;

    let left = rect.left;

    if (left + width > window.innerWidth - margin) {
        left = window.innerWidth - margin - width;
    }

    if (left < margin) {
        left = margin;
    }

    let top = rect.bottom + margin;

    popover.style.left = `${left}px`;
    popover.style.top = `${top}px`;

    const height = popover.offsetHeight;

    if (top + height > window.innerHeight - margin) {
        top = Math.max(margin, rect.top - margin - height);
        popover.style.top = `${top}px`;
    }
}

function openAbsenceLimit(button) {
    const group = button.closest('[data-absence]');

    if (! (group instanceof HTMLElement)) {
        return;
    }

    const select = group.querySelector('[data-absence-select]');
    const label = group.querySelector('[data-absence-label]');

    if (! (select instanceof HTMLSelectElement) || ! (label instanceof HTMLElement)) {
        return;
    }

    if (! select.hidden) {
        closeAbsenceLimit(group);

        return;
    }

    select.value = group.dataset.absenceLimit ?? '';
    label.hidden = true;
    select.hidden = false;
    select.focus();
}

function closeAbsenceLimit(group) {
    const select = group.querySelector('[data-absence-select]');
    const label = group.querySelector('[data-absence-label]');

    if (select instanceof HTMLSelectElement) {
        select.hidden = true;
    }

    if (label instanceof HTMLElement) {
        label.hidden = false;
    }
}

function saveAbsenceLimit(select) {
    const group = select.closest('[data-absence]');
    const table = select.closest('table');
    const url = group instanceof HTMLElement ? group.dataset.url : undefined;
    const token = table instanceof HTMLTableElement ? table.dataset.csrf : undefined;

    if (! (group instanceof HTMLElement) || url === undefined || token === undefined || select.disabled) {
        return;
    }

    const absenceLimit = select.value === '' ? null : Number(select.value);

    select.disabled = true;

    fetch(url, {
        method: 'PATCH',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
        },
        body: JSON.stringify({ absence_limit: absenceLimit }),
    })
        .then(async (response) => {
            if (! response.ok) {
                return null;
            }

            return response.json();
        })
        .then((body) => {
            if (body === null || (body.absence_limit !== null && typeof body.absence_limit !== 'number')) {
                select.value = group.dataset.absenceLimit ?? '';

                return;
            }

            group.dataset.absenceLimit = body.absence_limit === null ? '' : String(body.absence_limit);

            const label = group.querySelector('[data-absence-label]');

            if (label instanceof HTMLElement) {
                label.textContent = body.absence_limit === null ? '削除しない' : `${body.absence_limit}回`;
            }

            select.value = group.dataset.absenceLimit;
        })
        .finally(() => {
            select.disabled = false;
            closeAbsenceLimit(group);
        });
}

let sitemapDeleteForm = null;

function openSitemapDelete(button) {
    const form = button.closest('form');
    const dialog = document.querySelector('[data-sitemap-delete-dialog]');

    if (! (form instanceof HTMLFormElement) || ! (dialog instanceof HTMLDialogElement) || dialog.open) {
        return;
    }

    sitemapDeleteForm = form;

    const url = dialog.querySelector('[data-sitemap-delete-url]');

    if (url instanceof HTMLElement) {
        url.textContent = button.dataset.sitemapUrl ?? '';
    }

    const confirm = dialog.querySelector('[data-sitemap-delete-confirm]');

    if (confirm instanceof HTMLButtonElement) {
        confirm.disabled = false;
    }

    dialog.showModal();

    const cancel = dialog.querySelector('[data-sitemap-delete-cancel]');

    if (cancel instanceof HTMLButtonElement) {
        cancel.focus();
    }
}

function closeSitemapDelete() {
    const dialog = document.querySelector('[data-sitemap-delete-dialog]');

    if (dialog instanceof HTMLDialogElement && dialog.open) {
        dialog.close();
    }

    sitemapDeleteForm = null;
}

function confirmSitemapDelete(button) {
    const form = sitemapDeleteForm;

    if (! (form instanceof HTMLFormElement) || button.disabled) {
        return;
    }

    button.disabled = true;
    form.submit();
}

document.addEventListener('cancel', (event) => {
    if (event.target instanceof HTMLDialogElement && event.target.matches('[data-sitemap-delete-dialog]')) {
        sitemapDeleteForm = null;
    }
}, true);

document.querySelector('[data-sitemap-delete-dialog]')?.addEventListener('click', (event) => {
    if (event.target instanceof HTMLDialogElement && event.target.matches('[data-sitemap-delete-dialog]')) {
        closeSitemapDelete();
    }
});

function copyApiKey(button) {
    const token = button.dataset.apiKeyToken ?? '';

    if (token === '' || navigator.clipboard === undefined) {
        return;
    }

    navigator.clipboard.writeText(token).catch(() => {});
}

let apiKeyDeleteForm = null;

function openApiKeyDelete(button) {
    const form = button.closest('form');
    const dialog = document.querySelector('[data-api-key-delete-dialog]');

    if (! (form instanceof HTMLFormElement) || ! (dialog instanceof HTMLDialogElement) || dialog.open) {
        return;
    }

    apiKeyDeleteForm = form;

    const token = dialog.querySelector('[data-api-key-delete-token]');

    if (token instanceof HTMLElement) {
        token.textContent = button.dataset.apiKeyToken ?? '';
    }

    const confirm = dialog.querySelector('[data-api-key-delete-confirm]');

    if (confirm instanceof HTMLButtonElement) {
        confirm.disabled = false;
    }

    dialog.showModal();

    const cancel = dialog.querySelector('[data-api-key-delete-cancel]');

    if (cancel instanceof HTMLButtonElement) {
        cancel.focus();
    }
}

function closeApiKeyDelete() {
    const dialog = document.querySelector('[data-api-key-delete-dialog]');

    if (dialog instanceof HTMLDialogElement && dialog.open) {
        dialog.close();
    }

    apiKeyDeleteForm = null;
}

function confirmApiKeyDelete(button) {
    const form = apiKeyDeleteForm;

    if (! (form instanceof HTMLFormElement) || button.disabled) {
        return;
    }

    button.disabled = true;
    form.submit();
}

document.addEventListener('cancel', (event) => {
    if (event.target instanceof HTMLDialogElement && event.target.matches('[data-api-key-delete-dialog]')) {
        apiKeyDeleteForm = null;
    }
}, true);

document.querySelector('[data-api-key-delete-dialog]')?.addEventListener('click', (event) => {
    if (event.target instanceof HTMLDialogElement && event.target.matches('[data-api-key-delete-dialog]')) {
        closeApiKeyDelete();
    }
});

function setNotice(row, selector, message) {
    const notice = row.querySelector(selector);

    if (! (notice instanceof HTMLElement)) {
        return;
    }

    notice.textContent = message;
    notice.hidden = message === '';
    notice.classList.toggle('text-[#f53003]', message !== '');
}

function setSitemapStatus(row, statusLabel, errorMessage) {
    const cell = row.querySelector('[data-sitemap-status]');

    if (cell === null) {
        return;
    }

    const message = typeof errorMessage === 'string' ? errorMessage : '';

    cell.textContent = message !== '' ? message : statusLabel;
    cell.classList.toggle('text-[#f53003]', message !== '');
}

function setPagesCount(row, count) {
    const cell = row.querySelector('[data-sitemap-pages]');

    if (cell === null) {
        return;
    }

    cell.replaceChildren();

    if (count > 0) {
        const link = document.createElement('a');
        link.className = 'underline underline-offset-4';
        link.href = cell.dataset.pagesUrl ?? '';
        link.textContent = String(count);
        cell.append(link);

        return;
    }

    cell.textContent = '0';
}

function setCrawlStatus(row, httpStatus, errorMessage) {
    const cell = row.querySelector('[data-crawl-status]');

    if (cell === null) {
        return;
    }

    const message = typeof errorMessage === 'string' ? errorMessage : '';
    const text = message !== '' ? message : (httpStatus === null || httpStatus === undefined ? '' : String(httpStatus));

    cell.textContent = text;
    cell.classList.toggle('text-[#f53003]', text !== '' && text !== '200');
}

function setCellText(row, selector, value) {
    const cell = row.querySelector(selector);

    if (cell === null) {
        return;
    }

    cell.textContent = value === null || value === undefined ? '' : String(value);
}
