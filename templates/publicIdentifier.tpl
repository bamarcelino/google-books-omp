{if $googleBooksPublicRecords|@count}
<div class="item google_books">
    <div class="label">{translate key="plugins.generic.googleBooks.publicLabel"}</div>
    <div class="value">
        {foreach from=$googleBooksPublicRecords item=googleRecord name=googleBooksRecords}
            <a href="{$googleRecord.url|escape}" target="_blank" rel="noopener noreferrer">{translate key="plugins.generic.googleBooks.viewOnGoogleBooks"}</a>
            <span class="google_books_identifiers"> - ISBN {$googleRecord.isbn13|escape} - Google Volume ID {$googleRecord.volumeId|escape}</span>{if !$smarty.foreach.googleBooksRecords.last}<br>{/if}
        {/foreach}
    </div>
</div>
{/if}
