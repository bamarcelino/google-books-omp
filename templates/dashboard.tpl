{extends file="layouts/backend.tpl"}

{block name="page"}
<div class="gb-dashboard">
    <header class="gb-hero">
        <div>
            <p class="gb-eyebrow">Google Books</p>
            <h1 class="app__pageHeading gb-title">{translate key="plugins.generic.googleBooks.displayName"}</h1>
            <p class="gb-intro">{translate key="plugins.generic.googleBooks.dashboardIntro"}</p>
        </div>
        <div class="gb-status-group">
            <span class="gb-status{if $googleBooksApiConfigured} is-active{/if}"><span class="gb-status-dot"></span>{if $googleBooksApiConfigured}{translate key="plugins.generic.googleBooks.apiReady"}{else}{translate key="plugins.generic.googleBooks.apiNotConfigured"}{/if}</span>
            <span class="gb-status{if $googleBooksFeedReady} is-active{/if}"><span class="gb-status-dot"></span>{if $googleBooksFeedReady}{translate key="plugins.generic.googleBooks.feedReady"}{else}{translate key="plugins.generic.googleBooks.feedNotReadyStatus"}{/if}</span>
        </div>
    </header>

    {if $googleBooksMessageText}
        <div class="pkp_notification {$googleBooksMessageClass|escape} gb-notification" role="status">
            {$googleBooksMessageText|escape}
            {if $googleBooksIncident}<br><small>{translate key="plugins.generic.googleBooks.message.incidentCode"}: <code>{$googleBooksIncident|escape}</code></small>{/if}
        </div>
    {/if}

    {* Native radio controls make the dashboard tabs work even if JavaScript is
       blocked, cached from an older release, or loaded late by the backend. *}
    <input class="gb-tab-toggle" type="radio" name="googleBooksDashboardTab" id="gb-tab-overview" data-gb-tab-radio="overview" checked>
    <input class="gb-tab-toggle" type="radio" name="googleBooksDashboardTab" id="gb-tab-authentication" data-gb-tab-radio="authentication">
    <input class="gb-tab-toggle" type="radio" name="googleBooksDashboardTab" id="gb-tab-delivery" data-gb-tab-radio="delivery">
    <input class="gb-tab-toggle" type="radio" name="googleBooksDashboardTab" id="gb-tab-catalog" data-gb-tab-radio="catalog">

    <nav class="gb-tabs" aria-label="Google Books" role="tablist">
        <label class="gb-tab" data-gb-tab="overview" for="gb-tab-overview" role="tab" tabindex="0">{translate key="plugins.generic.googleBooks.tabOverview"}</label>
        <label class="gb-tab" data-gb-tab="authentication" for="gb-tab-authentication" role="tab" tabindex="0">{translate key="plugins.generic.googleBooks.tabAuthentication"}</label>
        <label class="gb-tab" data-gb-tab="delivery" for="gb-tab-delivery" role="tab" tabindex="0">{translate key="plugins.generic.googleBooks.tabDelivery"}</label>
        <label class="gb-tab" data-gb-tab="catalog" for="gb-tab-catalog" role="tab" tabindex="0">{translate key="plugins.generic.googleBooks.tabCatalog"}</label>
    </nav>

    <div class="gb-tab-panel" data-gb-panel="overview">
        <section class="gb-section">
            <div class="gb-section__heading">
                <div><h2>{translate key="plugins.generic.googleBooks.catalogStatus"}</h2><p>{translate key="plugins.generic.googleBooks.catalogStatusDescription"}</p></div>
            </div>
            <div class="gb-stats">
                <div class="gb-stat"><span class="gb-stat__value">{$googleBooksPublishedCount|escape}</span><span class="gb-stat__label">{translate key="plugins.generic.googleBooks.publishedInOmp"}</span></div>
                <div class="gb-stat"><span class="gb-stat__value">{$googleBooksStats.records|escape}</span><span class="gb-stat__label">{translate key="plugins.generic.googleBooks.records"}</span></div>
                <div class="gb-stat is-success"><span class="gb-stat__value">{$googleBooksStats.linked|escape}</span><span class="gb-stat__label">{translate key="plugins.generic.googleBooks.linked"}</span></div>
                <div class="gb-stat"><span class="gb-stat__value">{$googleBooksStats.notFound|escape}</span><span class="gb-stat__label">{translate key="plugins.generic.googleBooks.notFound"}</span></div>
                <div class="gb-stat{if $googleBooksStats.discoveryErrors} has-error{/if}"><span class="gb-stat__value">{$googleBooksStats.discoveryErrors|escape}</span><span class="gb-stat__label">{translate key="plugins.generic.googleBooks.discoveryErrors"}</span></div>
                <div class="gb-stat"><span class="gb-stat__value">{$googleBooksStats.feedAvailable|escape}</span><span class="gb-stat__label">{translate key="plugins.generic.googleBooks.feedAvailable"}</span></div>
                <div class="gb-stat"><span class="gb-stat__value">{$googleBooksStats.feedIneligible|escape}</span><span class="gb-stat__label">{translate key="plugins.generic.googleBooks.feedIneligible"}</span></div>
                <div class="gb-stat{if $googleBooksStats.feedErrors} has-error{/if}"><span class="gb-stat__value">{$googleBooksStats.feedErrors|escape}</span><span class="gb-stat__label">{translate key="plugins.generic.googleBooks.feedErrors"}</span></div>
            </div>
        </section>

        <div class="gb-two-column">
            <section class="gb-section gb-card gb-card--accent">
                <div class="gb-section__heading"><div><p class="gb-kicker">API</p><h2>{translate key="plugins.generic.googleBooks.discoverySection"}</h2><p>{translate key="plugins.generic.googleBooks.discoverySectionDescription"}</p></div></div>
                <form method="post" action="{$googleBooksDiscoverUrl|escape}">{csrf}<button class="pkp_button_primary" type="submit"{if !$googleBooksApiConfigured} disabled{/if}>{translate key="plugins.generic.googleBooks.discoverCatalog"}</button></form>
                {if !$googleBooksApiConfigured}<p class="gb-help gb-help--warning">{translate key="plugins.generic.googleBooks.discoveryNeedsApiKey"}</p>{/if}
            </section>

            <section class="gb-section gb-card">
                <div class="gb-section__heading"><div><p class="gb-kicker">Delivery</p><h2>{translate key="plugins.generic.googleBooks.feedSyncSection"}</h2><p>{translate key="plugins.generic.googleBooks.feedSyncSectionDescription"}</p></div></div>
                <p class="gb-mode-line"><strong>{translate key="plugins.generic.googleBooks.deliveryMode"}:</strong> <code>{$googleBooksSettings.deliveryMode|escape}</code></p>
                <div class="gb-actions">
                    <form method="post" action="{$googleBooksSyncUrl|escape}">{csrf}<button class="pkp_button_primary" type="submit"{if !$googleBooksFeedReady} disabled{/if}>{translate key="plugins.generic.googleBooks.synchronizeFeed"}</button></form>
                    <form method="post" action="{$googleBooksForceRefreshUrl|escape}">{csrf}<button class="pkp_button gb-action--warning" type="submit"{if !$googleBooksFeedReady} disabled{/if}>{translate key="plugins.generic.googleBooks.forceFullRefresh"}</button></form>
                </div>
                {if !$googleBooksFeedReady}
                    <div class="gb-readiness is-error"><strong>{translate key="plugins.generic.googleBooks.deliveryNotReady"}</strong><ul>{foreach from=$googleBooksDeliveryReadiness.reasons item=reason}<li>{$reason|escape}</li>{/foreach}</ul></div>
                {else}<div class="gb-readiness is-success">{translate key="plugins.generic.googleBooks.deliveryReady"}</div>{/if}
            </section>
        </div>

        <section class="gb-section gb-card">
            <div class="gb-section__heading"><div><h2>{translate key="plugins.generic.googleBooks.behavior"}</h2><p>{translate key="plugins.generic.googleBooks.behaviorDescription"}</p></div></div>
            <form method="post" action="{$googleBooksSaveBehaviorUrl|escape}" class="pkp_form gb-form">
                {csrf}
                <div class="gb-check-grid">
                    <label class="gb-check"><input type="checkbox" name="autoSync" value="1"{if $googleBooksSettings.autoSync} checked{/if}><span>{translate key="plugins.generic.googleBooks.autoSync"}</span></label>
                    <label class="gb-check"><input type="checkbox" name="autoVerifyGoogle" value="1"{if $googleBooksSettings.autoVerifyGoogle} checked{/if}><span>{translate key="plugins.generic.googleBooks.autoVerifyGoogle"}<small>{translate key="plugins.generic.googleBooks.autoVerifyGoogleDescription"}</small></span></label>
                    <label class="gb-check"><input type="checkbox" name="defaultFreeOfCharge" value="1"{if $googleBooksSettings.defaultFreeOfCharge} checked{/if}><span>{translate key="plugins.generic.googleBooks.defaultFree"}</span></label>
                    <label class="gb-check"><input type="checkbox" name="defaultWorldwideRightsForFree" value="1"{if $googleBooksSettings.defaultWorldwideRightsForFree} checked{/if}><span>{translate key="plugins.generic.googleBooks.defaultWorldwideRightsForFree"}<small>{translate key="plugins.generic.googleBooks.defaultWorldwideRightsForFreeDescription"}</small></span></label>
                </div>
                <div class="gb-form-grid">
                    <div class="section gb-field"><label for="defaultBisacCode">{translate key="plugins.generic.googleBooks.defaultBisacCode"}</label><input id="defaultBisacCode" name="defaultBisacCode" type="text" maxlength="16" value="{$googleBooksSettings.defaultBisacCode|escape}" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="SOC000000"><p class="description">{translate key="plugins.generic.googleBooks.defaultBisacCodeDescription"}</p></div>
                </div>
                <div class="gb-form-actions"><button class="pkp_button_primary" type="submit">{translate key="common.save"}</button></div>
            </form>
        </section>

        {if $googleBooksDeliveryDiagnostic}
            <section class="gb-section gb-card">
                <div class="gb-section__heading"><div><h2>{translate key="plugins.generic.googleBooks.lastDelivery"}</h2></div></div>
                <div class="gb-run-grid">
                    <div><span>{translate key="common.status"}</span><strong>{$googleBooksDeliveryDiagnostic.status|default:'-'|escape}</strong></div>
                    <div><span>{translate key="plugins.generic.googleBooks.mode"}</span><strong>{$googleBooksDeliveryDiagnostic.mode|default:'-'|escape}</strong></div>
                    <div><span>{translate key="plugins.generic.googleBooks.deliveryUploaded"}</span><strong>{$googleBooksDeliveryDiagnostic.uploaded|default:0|escape}</strong></div>
                    <div><span>{translate key="plugins.generic.googleBooks.skipped"}</span><strong>{$googleBooksDeliveryDiagnostic.skipped|default:0|escape}</strong></div>
                    <div><span>{translate key="plugins.generic.googleBooks.failed"}</span><strong>{$googleBooksDeliveryDiagnostic.failed|default:0|escape}</strong></div>
                    <div><span>{translate key="plugins.generic.googleBooks.deliveryDeleted"}</span><strong>{$googleBooksDeliveryDiagnostic.deleted|default:0|escape}</strong></div>
                </div>
                {if $googleBooksDeliveryDiagnostic.errors}<details class="gb-details"><summary>{translate key="plugins.generic.googleBooks.runDetails"}</summary><pre>{foreach from=$googleBooksDeliveryDiagnostic.errors item=error}{$error|escape}\n{/foreach}</pre></details>{/if}
            </section>
        {/if}
    </div>

    <div class="gb-tab-panel" data-gb-panel="authentication">
        <section class="gb-section gb-card">
            <div class="gb-section__heading"><div><h2>{translate key="plugins.generic.googleBooks.googleAuthentication"}</h2><p>{translate key="plugins.generic.googleBooks.googleAuthenticationDescription"}</p></div></div>
            <form method="post" action="{$googleBooksSaveApiUrl|escape}" class="pkp_form gb-form">
                {csrf}
                <div class="gb-form-grid">
                    <div class="section gb-field gb-field--wide"><label for="googleApiKey">{translate key="plugins.generic.googleBooks.apiKey"}</label><input id="googleApiKey" name="googleApiKey" type="password" autocomplete="new-password"><p class="description">{if $googleBooksSettings.hasGoogleApiKey}{translate key="plugins.generic.googleBooks.apiKeyAlreadySet"}{else}{translate key="plugins.generic.googleBooks.apiKeyDescription"}{/if}</p></div>
                    {if $googleBooksSettings.hasGoogleApiKey}<label class="gb-check gb-check--danger gb-field--wide"><input type="checkbox" name="clearGoogleApiKey" value="1"><span>{translate key="plugins.generic.googleBooks.clearStoredSecret"}</span></label>{/if}
                    <div class="section gb-field"><label for="googlePartnerId">{translate key="plugins.generic.googleBooks.partnerId"}</label><input id="googlePartnerId" name="googlePartnerId" type="text" value="{$googleBooksSettings.googlePartnerId|escape}" autocomplete="off"><p class="description">{translate key="plugins.generic.googleBooks.partnerIdDescription"}</p></div>
                </div>
                <div class="gb-check-grid">
                    <label class="gb-check"><input type="checkbox" name="autoDiscovery" value="1"{if $googleBooksSettings.autoDiscovery} checked{/if}><span>{translate key="plugins.generic.googleBooks.autoDiscovery"}<small>{translate key="plugins.generic.googleBooks.autoDiscoveryDescription"}</small></span></label>
                    <label class="gb-check"><input type="checkbox" name="showPublicLink" value="1"{if $googleBooksSettings.showPublicLink} checked{/if}><span>{translate key="plugins.generic.googleBooks.showPublicLink"}<small>{translate key="plugins.generic.googleBooks.showPublicLinkDescription"}</small></span></label>
                </div>
                <div class="gb-form-actions"><button class="pkp_button_primary" type="submit">{translate key="common.save"}</button></div>
            </form>
        </section>

        <section class="gb-section gb-card">
            <div class="gb-section__heading"><div><h2>{translate key="plugins.generic.googleBooks.httpCrawlerAuthentication"}</h2><p>{translate key="plugins.generic.googleBooks.httpCrawlerAuthenticationDescription"}</p></div><button class="pkp_button" type="button" id="gb-generate-credentials">{translate key="plugins.generic.googleBooks.generateCredentials"}</button></div>
            <form method="post" action="{$googleBooksSaveCrawlerAuthUrl|escape}" class="pkp_form gb-form">
                {csrf}
                <div class="gb-form-grid">
                    <div class="section gb-field"><label for="feedUsername">{translate key="plugins.generic.googleBooks.feedUsername"}</label><input id="feedUsername" name="feedUsername" type="text" value="{$googleBooksSettings.feedUsername|escape}" autocomplete="off" spellcheck="false"></div>
                    <div class="section gb-field"><label for="feedPassword">{translate key="plugins.generic.googleBooks.feedPassword"}</label><input id="feedPassword" name="feedPassword" type="password" autocomplete="new-password"><p class="description">{if $googleBooksSettings.hasFeedPassword}{translate key="plugins.generic.googleBooks.secretAlreadyStored"}{else}{translate key="plugins.generic.googleBooks.feedPasswordDescription"}{/if}</p></div>
                </div>
                {if $googleBooksSettings.hasFeedPassword}<label class="gb-check gb-check--danger"><input type="checkbox" name="clearFeedPassword" value="1"><span>{translate key="plugins.generic.googleBooks.clearStoredSecret"}</span></label>{/if}
                <div class="gb-form-actions"><button class="pkp_button_primary" type="submit">{translate key="common.save"}</button></div>
            </form>

            {if $googleBooksFeedAuthDiagnostic}
                <div class="gb-auth-diagnostic{if $googleBooksFeedAuthDiagnostic.authenticated} is-success{/if}">
                    <div class="gb-auth-diagnostic__heading"><div><h4>{translate key="plugins.generic.googleBooks.authDiagnosticTitle"}</h4><p>{translate key="plugins.generic.googleBooks.authDiagnosticDescription"}</p></div><span class="gb-badge{if $googleBooksFeedAuthDiagnostic.authenticated} gb-badge--linked{else} gb-badge--error{/if}">{if $googleBooksFeedAuthDiagnostic.authenticated}{translate key="plugins.generic.googleBooks.authSucceeded"}{else}{translate key="plugins.generic.googleBooks.authFailed"}{/if}</span></div>
                    <div class="gb-auth-diagnostic__grid">
                        <div><span>{translate key="plugins.generic.googleBooks.authLastAttempt"}</span><strong><code>{$googleBooksFeedAuthDiagnostic.timestamp|default:'-'|escape}</code></strong></div>
                        <div><span>{translate key="plugins.generic.googleBooks.authCredentialSource"}</span><strong><code>{$googleBooksFeedAuthDiagnostic.credentialSource|default:'none'|escape}</code></strong></div>
                        <div><span>{translate key="plugins.generic.googleBooks.authAuthorizationPresent"}</span><strong>{if $googleBooksFeedAuthDiagnostic.authorizationPresent}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</strong></div>
                        <div><span>{translate key="plugins.generic.googleBooks.authAuthorizationSource"}</span><strong><code>{$googleBooksFeedAuthDiagnostic.authorizationSource|default:'-'|escape}</code></strong></div>
                        <div><span>{translate key="plugins.generic.googleBooks.authBasicDetected"}</span><strong>{if $googleBooksFeedAuthDiagnostic.authorizationIsBasic}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</strong></div>
                        <div><span>{translate key="plugins.generic.googleBooks.authDecoded"}</span><strong>{if $googleBooksFeedAuthDiagnostic.authorizationDecoded}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</strong></div>
                        <div><span>PHP_AUTH_USER</span><strong>{if $googleBooksFeedAuthDiagnostic.nativeUserPresent}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</strong></div>
                        <div><span>PHP_AUTH_PW</span><strong>{if $googleBooksFeedAuthDiagnostic.nativePasswordPresent}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</strong></div>
                        <div><span>{translate key="plugins.generic.googleBooks.authUsernamePresent"}</span><strong>{if $googleBooksFeedAuthDiagnostic.usernamePresent}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</strong></div>
                        <div><span>{translate key="plugins.generic.googleBooks.authUsernameMatches"}</span><strong>{if $googleBooksFeedAuthDiagnostic.usernameMatches}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</strong></div>
                        <div><span>{translate key="plugins.generic.googleBooks.authPasswordPresent"}</span><strong>{if $googleBooksFeedAuthDiagnostic.passwordPresent}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</strong></div>
                        <div><span>{translate key="plugins.generic.googleBooks.authPasswordMatches"}</span><strong>{if $googleBooksFeedAuthDiagnostic.passwordMatches}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</strong></div>
                    </div>
                </div>
            {/if}
        </section>

        <section class="gb-section gb-card">
            <div class="gb-section__heading"><div><h2>{translate key="plugins.generic.googleBooks.remoteAuthentication"}</h2><p>{translate key="plugins.generic.googleBooks.remoteAuthenticationDescription"}</p></div></div>
            <form method="post" action="{$googleBooksSaveTransportAuthUrl|escape}" class="pkp_form gb-form">
                {csrf}
                <div class="gb-secret-group">
                    <h3>{translate key="plugins.generic.googleBooks.modeGoogleSftp"}</h3>
                    <div class="gb-form-grid">
                        <div class="section gb-field"><label for="googleSftpUsername">{translate key="plugins.generic.googleBooks.remoteUsername"}</label><input id="googleSftpUsername" name="googleSftpUsername" type="text" value="{$googleBooksSettings.googleSftpUsername|escape}" autocomplete="off"></div>
                        <div class="section gb-field"><label for="googleSftpAuthMode">{translate key="plugins.generic.googleBooks.sftpAuthMode"}</label><select id="googleSftpAuthMode" name="googleSftpAuthMode"><option value="password"{if $googleBooksSettings.googleSftpAuthMode == 'password'} selected{/if}>{translate key="plugins.generic.googleBooks.sftpPasswordAuth"}</option><option value="private_key"{if $googleBooksSettings.googleSftpAuthMode == 'private_key'} selected{/if}>{translate key="plugins.generic.googleBooks.sftpKeyAuth"}</option></select></div>
                        <div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.remotePassword"}</label><input name="googleSftpPassword" type="password" autocomplete="new-password"><p class="description">{if $googleBooksSettings.hasGoogleSftpPassword}{translate key="plugins.generic.googleBooks.secretAlreadyStored"}{else}{translate key="plugins.generic.googleBooks.secretLeaveBlank"}{/if}</p></div>
                        <div class="section gb-field gb-field--wide"><label>{translate key="plugins.generic.googleBooks.sftpPrivateKey"}</label><textarea name="googleSftpPrivateKey" rows="5" autocomplete="off"></textarea><p class="description">{if $googleBooksSettings.hasGoogleSftpPrivateKey}{translate key="plugins.generic.googleBooks.secretAlreadyStored"}{else}{translate key="plugins.generic.googleBooks.sftpPrivateKeyDescription"}{/if}</p></div>
                        <div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.sftpKeyPassphrase"}</label><input name="googleSftpPrivateKeyPassphrase" type="password" autocomplete="new-password"></div>
                    </div>
                    <div class="gb-clear-grid">
                        {if $googleBooksSettings.hasGoogleSftpPassword}<label><input type="checkbox" name="clearGoogleSftpPassword" value="1"> {translate key="plugins.generic.googleBooks.clearPassword"}</label>{/if}
                        {if $googleBooksSettings.hasGoogleSftpPrivateKey}<label><input type="checkbox" name="clearGoogleSftpPrivateKey" value="1"> {translate key="plugins.generic.googleBooks.clearPrivateKey"}</label>{/if}
                        {if $googleBooksSettings.hasGoogleSftpPrivateKeyPassphrase}<label><input type="checkbox" name="clearGoogleSftpPrivateKeyPassphrase" value="1"> {translate key="plugins.generic.googleBooks.clearPassphrase"}</label>{/if}
                    </div>
                </div>

                <div class="gb-secret-group">
                    <h3>{translate key="plugins.generic.googleBooks.modePublisherSftp"}</h3>
                    <div class="gb-form-grid">
                        <div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.remoteUsername"}</label><input name="publisherSftpUsername" type="text" value="{$googleBooksSettings.publisherSftpUsername|escape}" autocomplete="off"></div>
                        <div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.sftpAuthMode"}</label><select name="publisherSftpAuthMode"><option value="password"{if $googleBooksSettings.publisherSftpAuthMode == 'password'} selected{/if}>{translate key="plugins.generic.googleBooks.sftpPasswordAuth"}</option><option value="private_key"{if $googleBooksSettings.publisherSftpAuthMode == 'private_key'} selected{/if}>{translate key="plugins.generic.googleBooks.sftpKeyAuth"}</option></select></div>
                        <div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.remotePassword"}</label><input name="publisherSftpPassword" type="password" autocomplete="new-password"><p class="description">{if $googleBooksSettings.hasPublisherSftpPassword}{translate key="plugins.generic.googleBooks.secretAlreadyStored"}{else}{translate key="plugins.generic.googleBooks.secretLeaveBlank"}{/if}</p></div>
                        <div class="section gb-field gb-field--wide"><label>{translate key="plugins.generic.googleBooks.sftpPrivateKey"}</label><textarea name="publisherSftpPrivateKey" rows="5" autocomplete="off"></textarea><p class="description">{if $googleBooksSettings.hasPublisherSftpPrivateKey}{translate key="plugins.generic.googleBooks.secretAlreadyStored"}{else}{translate key="plugins.generic.googleBooks.sftpPrivateKeyDescription"}{/if}</p></div>
                        <div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.sftpKeyPassphrase"}</label><input name="publisherSftpPrivateKeyPassphrase" type="password" autocomplete="new-password"></div>
                    </div>
                    <div class="gb-clear-grid">
                        {if $googleBooksSettings.hasPublisherSftpPassword}<label><input type="checkbox" name="clearPublisherSftpPassword" value="1"> {translate key="plugins.generic.googleBooks.clearPassword"}</label>{/if}
                        {if $googleBooksSettings.hasPublisherSftpPrivateKey}<label><input type="checkbox" name="clearPublisherSftpPrivateKey" value="1"> {translate key="plugins.generic.googleBooks.clearPrivateKey"}</label>{/if}
                        {if $googleBooksSettings.hasPublisherSftpPrivateKeyPassphrase}<label><input type="checkbox" name="clearPublisherSftpPrivateKeyPassphrase" value="1"> {translate key="plugins.generic.googleBooks.clearPassphrase"}</label>{/if}
                    </div>
                </div>

                <div class="gb-secret-group">
                    <h3>{translate key="plugins.generic.googleBooks.modePublisherFtp"}</h3>
                    <div class="gb-form-grid">
                        <div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.remoteUsername"}</label><input name="publisherFtpUsername" type="text" value="{$googleBooksSettings.publisherFtpUsername|escape}" autocomplete="off"></div>
                        <div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.remotePassword"}</label><input name="publisherFtpPassword" type="password" autocomplete="new-password"><p class="description">{if $googleBooksSettings.hasPublisherFtpPassword}{translate key="plugins.generic.googleBooks.secretAlreadyStored"}{else}{translate key="plugins.generic.googleBooks.secretLeaveBlank"}{/if}</p></div>
                    </div>
                    {if $googleBooksSettings.hasPublisherFtpPassword}<label class="gb-check gb-check--danger"><input type="checkbox" name="clearPublisherFtpPassword" value="1"><span>{translate key="plugins.generic.googleBooks.clearPassword"}</span></label>{/if}
                </div>

                <div class="gb-secret-group">
                    <h3>{translate key="plugins.generic.googleBooks.modeGcs"}</h3>
                    <div class="section gb-field gb-field--wide"><label>{translate key="plugins.generic.googleBooks.gcsWriterServiceAccount"}</label><textarea name="gcsServiceAccountJson" rows="8" autocomplete="off" spellcheck="false"></textarea><p class="description">{if $googleBooksSettings.hasGcsServiceAccountJson}{translate key="plugins.generic.googleBooks.secretAlreadyStored"}{else}{translate key="plugins.generic.googleBooks.gcsWriterServiceAccountDescription"}{/if}</p></div>
                    {if $googleBooksSettings.hasGcsServiceAccountJson}<label class="gb-check gb-check--danger"><input type="checkbox" name="clearGcsServiceAccountJson" value="1"><span>{translate key="plugins.generic.googleBooks.clearStoredSecret"}</span></label>{/if}
                </div>

                <div class="gb-security-note">{translate key="plugins.generic.googleBooks.secretSecurityDescription"}</div>
                <div class="gb-form-actions"><button class="pkp_button_primary" type="submit">{translate key="common.save"}</button></div>
            </form>
        </section>
    </div>

    <div class="gb-tab-panel" data-gb-panel="delivery">
        <section class="gb-section gb-card">
            <div class="gb-section__heading"><div><h2>{translate key="plugins.generic.googleBooks.deliverySettings"}</h2><p>{translate key="plugins.generic.googleBooks.deliverySettingsDescription"}</p></div></div>
            <form method="post" action="{$googleBooksSaveDeliveryUrl|escape}" class="pkp_form gb-form" id="gb-delivery-form">
                {csrf}
                <div class="gb-form-grid">
                    <div class="section gb-field"><label for="collectionCode">{translate key="plugins.generic.googleBooks.collectionCode"}</label><input id="collectionCode" name="collectionCode" class="gb-code-input" type="text" maxlength="32" value="{$googleBooksSettings.collectionCode|escape}" autocomplete="off" autocapitalize="characters" spellcheck="false"><p class="description">{translate key="plugins.generic.googleBooks.collectionCodeDescription"}</p>{if $googleBooksCollectionCodeAttempt}<p class="gb-field-error">{translate key="plugins.generic.googleBooks.collectionCodeDetected"}: <code>{$googleBooksCollectionCodeAttempt|escape}</code> - {$googleBooksCollectionCodeLength|escape}/7</p>{/if}</div>
                    <div class="section gb-field gb-field--wide"><label for="imprintCollectionMap">{translate key="plugins.generic.googleBooks.imprintMap"}</label><textarea id="imprintCollectionMap" name="imprintCollectionMap" rows="4">{$googleBooksSettings.imprintCollectionMap|escape}</textarea><p class="description">{translate key="plugins.generic.googleBooks.imprintMapDescription"}</p></div>
                    <div class="section gb-field gb-field--wide"><label for="deliveryMode">{translate key="plugins.generic.googleBooks.deliveryMode"}</label><select id="deliveryMode" name="deliveryMode" data-gb-delivery-mode>
                        <option value="http_pull"{if $googleBooksSettings.deliveryMode == 'http_pull'} selected{/if}>{translate key="plugins.generic.googleBooks.modeHttpPull"}</option>
                        <option value="google_sftp"{if $googleBooksSettings.deliveryMode == 'google_sftp'} selected{/if}>{translate key="plugins.generic.googleBooks.modeGoogleSftp"}</option>
                        <option value="publisher_sftp"{if $googleBooksSettings.deliveryMode == 'publisher_sftp'} selected{/if}>{translate key="plugins.generic.googleBooks.modePublisherSftp"}</option>
                        <option value="publisher_ftp"{if $googleBooksSettings.deliveryMode == 'publisher_ftp'} selected{/if}>{translate key="plugins.generic.googleBooks.modePublisherFtp"}</option>
                        <option value="gcs"{if $googleBooksSettings.deliveryMode == 'gcs'} selected{/if}>{translate key="plugins.generic.googleBooks.modeGcs"}</option>
                        <option value="local_export"{if $googleBooksSettings.deliveryMode == 'local_export'} selected{/if}>{translate key="plugins.generic.googleBooks.modeLocalExport"}</option>
                    </select><p class="description">{translate key="plugins.generic.googleBooks.deliveryModeDescription"}</p></div>
                </div>

                <div class="gb-subsection">
                    <h3>{translate key="plugins.generic.googleBooks.deliveryPayloads"}</h3>
                    <div class="gb-check-grid">
                        <label class="gb-check"><input type="checkbox" name="deliverOnixFull" value="1"{if $googleBooksSettings.deliverOnixFull} checked{/if}><span>{translate key="plugins.generic.googleBooks.payloadOnixFull"}<small>onix/&lt;collection&gt;-full/</small></span></label>
                        <label class="gb-check"><input type="checkbox" name="deliverOnixRights" value="1"{if $googleBooksSettings.deliverOnixRights} checked{/if}><span>{translate key="plugins.generic.googleBooks.payloadOnixRights"}<small>onix/&lt;collection&gt;-rights/</small></span></label>
                        <label class="gb-check"><input type="checkbox" name="deliverEbooks" value="1"{if $googleBooksSettings.deliverEbooks} checked{/if}><span>{translate key="plugins.generic.googleBooks.payloadEbooks"}<small>ebooks/&lt;collection&gt;/</small></span></label>
                        <label class="gb-check"><input type="checkbox" name="deliverValidation" value="1"{if $googleBooksSettings.deliverValidation} checked{/if}><span>{translate key="plugins.generic.googleBooks.payloadValidation"}<small>onix/validate/</small></span></label>
                    </div>
                </div>

                <div class="gb-transport-panel{{if $googleBooksSettings.deliveryMode == 'http_pull'}} is-active{{/if}}" data-gb-transport="http_pull">
                    <h3>{translate key="plugins.generic.googleBooks.modeHttpPull"}</h3>
                    <p class="gb-help">{translate key="plugins.generic.googleBooks.httpPullDescription"}</p>
                    <div class="gb-endpoint-list"><div class="gb-endpoint"><strong>ONIX</strong><code>{$googleBooksOnixUrl|escape}</code></div><div class="gb-endpoint"><strong>eBooks</strong><code>{$googleBooksEbooksUrl|escape}</code></div></div>
                </div>

                <div class="gb-transport-panel{{if $googleBooksSettings.deliveryMode == 'google_sftp'}} is-active{{/if}}" data-gb-transport="google_sftp">
                    <h3>{translate key="plugins.generic.googleBooks.modeGoogleSftp"}</h3><p class="gb-help">{translate key="plugins.generic.googleBooks.googleSftpDescription"}</p>
                    <div class="gb-form-grid"><div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.remoteHost"}</label><input name="googleSftpHost" type="text" value="{$googleBooksSettings.googleSftpHost|escape}" autocomplete="off"></div><div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.remotePort"}</label><input name="googleSftpPort" type="number" min="1" max="65535" value="{$googleBooksSettings.googleSftpPort|escape}"></div><div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.remoteRoot"}</label><input name="googleSftpRemoteRoot" type="text" value="{$googleBooksSettings.googleSftpRemoteRoot|escape}" autocomplete="off"></div><div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.hostKeyFingerprint"}</label><input name="googleSftpHostKeyFingerprint" type="text" value="{$googleBooksSettings.googleSftpHostKeyFingerprint|escape}" autocomplete="off"><p class="description">{translate key="plugins.generic.googleBooks.hostKeyFingerprintDescription"}</p></div></div>
                </div>

                <div class="gb-transport-panel{{if $googleBooksSettings.deliveryMode == 'publisher_sftp'}} is-active{{/if}}" data-gb-transport="publisher_sftp">
                    <h3>{translate key="plugins.generic.googleBooks.modePublisherSftp"}</h3><p class="gb-help">{translate key="plugins.generic.googleBooks.publisherSftpDescription"}</p>
                    <div class="gb-form-grid"><div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.remoteHost"}</label><input name="publisherSftpHost" type="text" value="{$googleBooksSettings.publisherSftpHost|escape}" autocomplete="off"></div><div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.remotePort"}</label><input name="publisherSftpPort" type="number" min="1" max="65535" value="{$googleBooksSettings.publisherSftpPort|escape}"></div><div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.remoteRoot"}</label><input name="publisherSftpRemoteRoot" type="text" value="{$googleBooksSettings.publisherSftpRemoteRoot|escape}" autocomplete="off"></div><div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.hostKeyFingerprint"}</label><input name="publisherSftpHostKeyFingerprint" type="text" value="{$googleBooksSettings.publisherSftpHostKeyFingerprint|escape}" autocomplete="off"></div></div>
                </div>

                <div class="gb-transport-panel{{if $googleBooksSettings.deliveryMode == 'publisher_ftp'}} is-active{{/if}}" data-gb-transport="publisher_ftp">
                    <h3>{translate key="plugins.generic.googleBooks.modePublisherFtp"}</h3><p class="gb-help">{translate key="plugins.generic.googleBooks.publisherFtpDescription"}</p>
                    <div class="gb-form-grid"><div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.remoteHost"}</label><input name="publisherFtpHost" type="text" value="{$googleBooksSettings.publisherFtpHost|escape}" autocomplete="off"></div><div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.remotePort"}</label><input name="publisherFtpPort" type="number" min="1" max="65535" value="{$googleBooksSettings.publisherFtpPort|escape}"></div><div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.remoteRoot"}</label><input name="publisherFtpRemoteRoot" type="text" value="{$googleBooksSettings.publisherFtpRemoteRoot|escape}" autocomplete="off"></div></div>
                    <div class="gb-check-grid"><label class="gb-check"><input type="checkbox" name="publisherFtpTls" value="1"{if $googleBooksSettings.publisherFtpTls} checked{/if}><span>{translate key="plugins.generic.googleBooks.ftpTls"}</span></label><label class="gb-check"><input type="checkbox" name="publisherFtpPassive" value="1"{if $googleBooksSettings.publisherFtpPassive} checked{/if}><span>{translate key="plugins.generic.googleBooks.ftpPassive"}</span></label></div>
                </div>

                <div class="gb-transport-panel{{if $googleBooksSettings.deliveryMode == 'gcs'}} is-active{{/if}}" data-gb-transport="gcs">
                    <h3>{translate key="plugins.generic.googleBooks.modeGcs"}</h3><p class="gb-help">{translate key="plugins.generic.googleBooks.gcsDescription"}</p>
                    <div class="gb-form-grid"><div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.gcsBucket"}</label><input name="gcsBucket" type="text" value="{$googleBooksSettings.gcsBucket|escape}" autocomplete="off"></div><div class="section gb-field"><label>{translate key="plugins.generic.googleBooks.gcsPrefix"}</label><input name="gcsPrefix" type="text" value="{$googleBooksSettings.gcsPrefix|escape}" autocomplete="off"></div><div class="section gb-field gb-field--wide"><label>{translate key="plugins.generic.googleBooks.gcsGoogleReaderServiceAccount"}</label><input name="gcsGoogleReaderServiceAccount" type="text" value="{$googleBooksSettings.gcsGoogleReaderServiceAccount|escape}" autocomplete="off"><p class="description">{translate key="plugins.generic.googleBooks.gcsGoogleReaderServiceAccountDescription"}</p></div></div>
                </div>

                <div class="gb-transport-panel{{if $googleBooksSettings.deliveryMode == 'local_export'}} is-active{{/if}}" data-gb-transport="local_export">
                    <h3>{translate key="plugins.generic.googleBooks.modeLocalExport"}</h3><p class="gb-help">{translate key="plugins.generic.googleBooks.localExportDescription"}</p>
                </div>

                <label class="gb-switch"><input type="checkbox" name="feedEnabled" value="1"{if $googleBooksSettings.feedEnabled} checked{/if}><span><strong>{translate key="plugins.generic.googleBooks.feedEnabled"}</strong><small>{translate key="plugins.generic.googleBooks.deliveryEnabledDescription"}</small></span></label>
                <div class="gb-form-actions"><button class="pkp_button_primary" type="submit">{translate key="common.save"}</button></div>
            </form>
        </section>

        <section class="gb-section gb-card">
            <div class="gb-section__heading"><div><h2>{translate key="plugins.generic.googleBooks.transportCapabilities"}</h2><p>{translate key="plugins.generic.googleBooks.transportCapabilitiesDescription"}</p></div></div>
            <div class="gb-capability-grid">
                <div><span>HTTP/HTTPS</span><strong>{translate key="common.yes"}</strong></div>
                <div><span>Google SFTP Dropbox</span><strong>{if $googleBooksDeliveryCapabilities.googleSftpDropbox}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</strong></div>
                <div><span>SFTP</span><strong>{if $googleBooksDeliveryCapabilities.publisherSftp}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</strong></div>
                <div><span>FTP</span><strong>{if $googleBooksDeliveryCapabilities.publisherFtp}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</strong></div>
                <div><span>FTPS</span><strong>{if $googleBooksDeliveryCapabilities.publisherFtps}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</strong></div>
                <div><span>Google Cloud Storage</span><strong>{if $googleBooksDeliveryCapabilities.gcs}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</strong></div>
            </div>
        </section>

        <section class="gb-section gb-card">
            <div class="gb-section__heading"><div><h2>{translate key="plugins.generic.googleBooks.deliveryOperations"}</h2><p>{translate key="plugins.generic.googleBooks.deliveryOperationsDescription"}</p></div></div>
            <div class="gb-actions">
                <form method="post" action="{$googleBooksTestDeliveryUrl|escape}">{csrf}<button class="pkp_button" type="submit">{translate key="plugins.generic.googleBooks.testConnection"}</button></form>
                <form method="post" action="{$googleBooksDeliverNowUrl|escape}">{csrf}<button class="pkp_button_primary" type="submit"{if !$googleBooksFeedReady} disabled{/if}>{translate key="plugins.generic.googleBooks.deliverNow"}</button></form>
                <form method="post" action="{$googleBooksDeliverNowUrl|escape}">{csrf}<input type="hidden" name="force" value="1"><button class="pkp_button gb-action--warning" type="submit"{if !$googleBooksFeedReady} disabled{/if}>{translate key="plugins.generic.googleBooks.forceDelivery"}</button></form>
            </div>
            {if $googleBooksDeliveryConnectionDiagnostic}<div class="gb-connection-result{if $googleBooksDeliveryConnectionDiagnostic.status == 'success'} is-success{else} is-error{/if}"><strong>{$googleBooksDeliveryConnectionDiagnostic.status|escape}</strong> - {$googleBooksDeliveryConnectionDiagnostic.message|default:''|escape} <small>{$googleBooksDeliveryConnectionDiagnostic.timestamp|default:''|escape}</small></div>{/if}
        </section>

        <section class="gb-section gb-card">
            <div class="gb-section__heading"><div><h2>{translate key="plugins.generic.googleBooks.validationSample"}</h2><p>{translate key="plugins.generic.googleBooks.validationSampleDescription"}</p></div></div>
            <form method="post" action="{$googleBooksSetValidationUrl|escape}" class="pkp_form gb-inline-form">{csrf}<div class="section gb-field gb-inline-form__field"><label for="validationSubmissionId">{translate key="plugins.generic.googleBooks.validationBook"}</label><select id="validationSubmissionId" name="validationSubmissionId" required><option value="">--</option>{foreach from=$googleBooksPublished item=book}<option value="{$book.id|escape}"{if $googleBooksSettings.validationSubmissionId == $book.id} selected{/if}>{$book.title|escape}</option>{/foreach}</select></div><button class="pkp_button_primary" type="submit">{translate key="plugins.generic.googleBooks.generateValidation"}</button></form>
            {if $googleBooksValidationUrl}<div class="gb-endpoint gb-endpoint--success"><div><strong>{translate key="plugins.generic.googleBooks.validationUrl"}</strong><code>{$googleBooksValidationUrl|escape}</code></div><a class="pkp_button" href="{$googleBooksDownloadValidationUrl|escape}">{translate key="plugins.generic.googleBooks.downloadValidation"}</a></div>{/if}
        </section>

        <section class="gb-section gb-card">
            <div class="gb-section__heading"><div><h2>{translate key="plugins.generic.googleBooks.googleDirectoryLayout"}</h2><p>{translate key="plugins.generic.googleBooks.googleDirectoryLayoutDescription"}</p></div></div>
            <pre class="gb-tree">onix/
  validate/
  &lt;collection&gt;-full/
  &lt;collection&gt;-rights/
ebooks/
  &lt;collection&gt;/</pre>
            {if $googleBooksCollectionCodes|@count}<p class="gb-code-list"><strong>{translate key="plugins.generic.googleBooks.activeCollectionCodes"}</strong> <code>{$googleBooksCollectionCodesString|escape}</code></p>{/if}
        </section>
    </div>

    <div class="gb-tab-panel" data-gb-panel="catalog">
        <section class="gb-section gb-card">
            <div class="gb-section__heading"><div><h2>{translate key="plugins.generic.googleBooks.books"}</h2><p>{translate key="plugins.generic.googleBooks.booksDescription"}</p></div></div>
            {if $googleBooksRecords|@count}
                <div class="gb-table-wrap"><table class="pkpTable gb-table"><thead><tr><th>{translate key="common.title"}</th><th>ISBN-13</th><th>{translate key="plugins.generic.googleBooks.discovery"}</th><th>{translate key="plugins.generic.googleBooks.syncStatus"}</th><th>Google Volume ID</th><th>{translate key="common.action"}</th></tr></thead><tbody>
                {foreach from=$googleBooksRecords item=item}<tr>
                    <td><strong>{$item.title|escape}</strong>{if $item.record->discovery_error}<small class="gb-row-error">{$item.record->discovery_error|escape}</small>{elseif $item.record->feed_error}<small class="gb-row-error">{$item.record->feed_error|escape}</small>{/if}</td>
                    <td><code>{$item.record->isbn13|escape}</code></td>
                    <td><span class="gb-badge gb-badge--{$item.record->discovery_status|escape}">{$item.record->discovery_status|escape}</span></td>
                    <td><span class="gb-badge gb-badge--{$item.record->sync_status|escape}">{$item.record->sync_status|escape}</span></td>
                    <td>{if $item.record->google_volume_id}<code>{$item.record->google_volume_id|escape}</code>{else}-{/if}</td>
                    <td><div class="gb-row-actions">
                        {if $item.canDiscover}<form method="post" action="{$googleBooksDiscoverBookUrl|escape}">{csrf}<input type="hidden" name="submissionId" value="{$item.record->submission_id|escape}"><button class="pkp_button" type="submit">{translate key="plugins.generic.googleBooks.discoverNow"}</button></form>{/if}
                        {if $item.canSync}<form method="post" action="{$googleBooksSyncBookUrl|escape}">{csrf}<input type="hidden" name="submissionId" value="{$item.record->submission_id|escape}"><button class="pkp_button" type="submit">{translate key="plugins.generic.googleBooks.syncNow"}</button></form>{/if}
                        {if $item.googleUrl}<a class="pkp_button" href="{$item.googleUrl|escape}" target="_blank" rel="noopener noreferrer">{translate key="plugins.generic.googleBooks.openGoogle"}</a>{/if}
                    </div></td>
                </tr>{/foreach}
                </tbody></table></div>
            {else}<p class="gb-empty">{translate key="plugins.generic.googleBooks.noRecords"}</p>{/if}
        </section>

        {if $googleBooksLatestDiscoveryRun}
            <section class="gb-section gb-card"><div class="gb-section__heading"><div><h2>{translate key="plugins.generic.googleBooks.lastDiscoveryRun"}</h2></div></div><div class="gb-run-grid"><div><span>{translate key="common.status"}</span><strong>{$googleBooksLatestDiscoveryRun->status|escape}</strong></div><div><span>{translate key="plugins.generic.googleBooks.scanned"}</span><strong>{$googleBooksLatestDiscoveryRun->books_scanned|escape}</strong></div><div><span>{translate key="plugins.generic.googleBooks.linked"}</span><strong>{$googleBooksLatestDiscoveryRun->books_linked|escape}</strong></div><div><span>{translate key="plugins.generic.googleBooks.notFound"}</span><strong>{$googleBooksLatestDiscoveryRun->books_not_found|escape}</strong></div><div><span>{translate key="plugins.generic.googleBooks.skipped"}</span><strong>{$googleBooksLatestDiscoveryRun->books_skipped|default:0|escape}</strong></div><div><span>{translate key="plugins.generic.googleBooks.failed"}</span><strong>{$googleBooksLatestDiscoveryRun->books_failed|escape}</strong></div></div>{if $googleBooksLatestDiscoveryRun->details}<details class="gb-details"><summary>{translate key="plugins.generic.googleBooks.runDetails"}</summary><pre>{$googleBooksLatestDiscoveryRun->details|escape}</pre></details>{/if}</section>
        {/if}

        {if $googleBooksLatestFeedRun}
            <section class="gb-section gb-card"><div class="gb-section__heading"><div><h2>{translate key="plugins.generic.googleBooks.lastFeedRun"}</h2></div></div><div class="gb-run-grid"><div><span>{translate key="common.status"}</span><strong>{$googleBooksLatestFeedRun->status|escape}</strong></div><div><span>{translate key="plugins.generic.googleBooks.mode"}</span><strong>{$googleBooksLatestFeedRun->mode|escape}</strong></div><div><span>{translate key="plugins.generic.googleBooks.scanned"}</span><strong>{$googleBooksLatestFeedRun->books_scanned|escape}</strong></div><div><span>{translate key="plugins.generic.googleBooks.feedIneligible"}</span><strong>{$googleBooksLatestFeedRun->books_feed_ineligible|default:0|escape}</strong></div><div><span>{translate key="plugins.generic.googleBooks.failed"}</span><strong>{$googleBooksLatestFeedRun->books_failed|escape}</strong></div></div>{if $googleBooksLatestFeedRun->details}<details class="gb-details"><summary>{translate key="plugins.generic.googleBooks.runDetails"}</summary><pre>{$googleBooksLatestFeedRun->details|escape}</pre></details>{/if}</section>
        {/if}
    </div>
</div>
{* OMP/PKP backends can load registered JavaScript late or retain a cached plugin
   asset across in-place upgrades. Load the versioned dashboard script directly
   as a deterministic fallback; the script itself is idempotent. *}
<script src="{$googleBooksDashboardJsUrl|escape}" type="text/javascript"></script>
{/block}
