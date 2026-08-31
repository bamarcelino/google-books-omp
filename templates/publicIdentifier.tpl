{if $googleBooksPublicRecords|@count}
<div class="item google_books">
    <div class="label">{translate key="plugins.generic.googleBooks.publicLabel"}</div>
    <div class="value google_books_links">
        {foreach from=$googleBooksPublicRecords item=googleRecord name=googleBooksRecords}
            <div class="google_books_actions">
                <a class="google_books_button google_books_button--books" href="{$googleRecord.booksUrl|escape}" target="_blank" rel="noopener noreferrer">
                    <svg class="google_books_button__icon" viewBox="0 0 28 28" aria-hidden="true" focusable="false">
                        <path fill="#4285f4" d="M3 5.2c3.7-.8 7.1-.2 10 1.7v16.2c-2.9-1.9-6.3-2.5-10-1.7z"/>
                        <path fill="#34a853" d="M25 5.2c-3.7-.8-7.1-.2-10 1.7v16.2c2.9-1.9 6.3-2.5 10-1.7z"/>
                        <path fill="#fbbc04" d="M13 6.9h2v16.2h-2z"/>
                        <path fill="#ea4335" d="M4.8 8.1c2.2-.3 4.4.1 6.3 1.2v2.3a10.7 10.7 0 0 0-6.3-1.4z"/>
                    </svg>
                    <span>{translate key="plugins.generic.googleBooks.viewOnGoogleBooks"}</span>
                </a>
                {if $googleRecord.playUrl}
                    <a class="google_books_button google_books_button--play" href="{$googleRecord.playUrl|escape}" target="_blank" rel="noopener noreferrer">
                        <svg class="google_books_button__icon" viewBox="0 0 28 28" aria-hidden="true" focusable="false">
                            <path fill="#00d6c9" d="M4.2 3.4 16.9 14 4.2 24.6a3 3 0 0 1-.7-2V5.4a3 3 0 0 1 .7-2z"/>
                            <path fill="#ffcf3d" d="m16.9 14 3.4-2.8 4 2.2c1.5.8 1.5 2.4 0 3.2l-4 2.2z"/>
                            <path fill="#ff5f52" d="m4.2 24.6 12.7-10.6 3.4 4.8-13.6 7.5a2.8 2.8 0 0 1-2.5-1.7z"/>
                            <path fill="#4d7cff" d="M4.2 3.4A2.8 2.8 0 0 1 6.7 1.7l13.6 7.5-3.4 4.8z"/>
                        </svg>
                        <span>{translate key="plugins.generic.googleBooks.viewOnGooglePlay"}</span>
                    </a>
                {/if}
            </div>
        {/foreach}
    </div>
</div>
{/if}
